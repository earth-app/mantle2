<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\comment\Entity\Comment;
use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Custom\PromptResponse;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use InvalidArgumentException;

class PromptsHelper
{
	private static function getCommentFieldName(Node $node): ?string
	{
		$definitions = Drupal::service('entity_field.manager')->getFieldDefinitions(
			'node',
			$node->bundle(),
		);
		foreach ($definitions as $name => $definition) {
			if (method_exists($definition, 'getType') && $definition->getType() === 'comment') {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Resolve the comment type (bundle) configured on the comment field instance.
	 */
	private static function getCommentTypeForField(Node $node, string $fieldName): ?string
	{
		$definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions(
			'node',
			$node->bundle(),
		);
		if (
			isset($definitions[$fieldName]) &&
			method_exists($definitions[$fieldName], 'getSetting')
		) {
			return $definitions[$fieldName]->getSetting('comment_type') ?: null;
		}
		return null;
	}

	public static function loadPromptNode(int $nid)
	{
		$node = Node::load($nid);
		if (!$node || $node->getType() !== 'prompt') {
			return null;
		}

		return self::nodeToPrompt($node);
	}

	public static function nodeToPrompt(Node $node)
	{
		$prompt = $node->get('field_prompt')->value;
		$ownerId = (int) $node->get('field_owner_id')->value;
		$visibility = Visibility::cases()[$node->get('field_visibility')->value ?? 0];

		return new Prompt($prompt, $ownerId, $visibility);
	}

	public static function isVisible(Prompt $prompt, ?UserInterface $user): bool
	{
		$visibility = $prompt->getVisibility();
		if ($visibility === Visibility::PUBLIC) {
			return true;
		}

		// UNLISTED requires login
		if (!$user) {
			return false;
		}

		// PRIVATE requires ownership
		if ($visibility === Visibility::PRIVATE) {
			return $user->id() === $prompt->getOwnerId();
		}

		return true;
	}

	public static function createPrompt(Prompt $prompt, ?UserInterface $author = null): Node
	{
		// check expired prompts
		self::checkExpiredPrompts();

		$node = Node::create([
			'type' => 'prompt',
			'title' => substr($prompt->getPrompt(), 0, 255),
			'uid' => $author ? $author->id() : 1,
		]);

		$node->set('field_prompt', $prompt->getPrompt());
		$node->set('field_owner_id', $prompt->getOwnerId());
		$node->set(
			'field_visibility',
			GeneralHelper::findOrdinal(Visibility::cases(), $prompt->getVisibility()),
		);

		$node->save();

		// Notify the user that their prompt was published
		if ($author) {
			UsersHelper::addNotification(
				$author,
				Drupal::translation()->translate('Prompt Published'),
				Drupal::translation()->translate(
					"Your prompt \"{$prompt->getPrompt()}\" has been successfully published.",
				),
				null,
				'info',
				'system',
			);
		}

		return $node;
	}

	public static function updatePrompt(Node $node, Prompt $prompt): Node
	{
		if (!$node) {
			throw new InvalidArgumentException('Node is null');
		}

		if ($node->getType() !== 'prompt') {
			throw new InvalidArgumentException('Node is not a prompt');
		}

		$node->set('field_prompt', $prompt->getPrompt());
		$node->set(
			'field_visibility',
			GeneralHelper::findOrdinal(Visibility::cases(), $prompt->getVisibility()),
		);

		$node->save();

		return $node;
	}

	public static function entityToPromptResponse(Comment $response): ?PromptResponse
	{
		$promptId = $response->getCommentedEntityId();

		// Try to get comment body, handling cases where field doesn't exist
		$body = '';
		if ($response->hasField('comment_body') && !$response->get('comment_body')->isEmpty()) {
			$body = $response->get('comment_body')->value;
		}

		return new PromptResponse(
			$response->id(),
			$promptId,
			$body,
			$response->getOwnerId(),
			$response->getCreatedTime(),
			$response->getChangedTime(),
		);
	}

	/**
	 * @return array<Comment>
	 */
	public static function getComments(
		Node $node,
		int $page = 1,
		int $limit = 25,
		string $search = '',
		string $sort = 'desc',
	): array {
		$fieldName = self::getCommentFieldName($node);
		if (!$fieldName) {
			// No comment field configured for this bundle; nothing to return.
			return [];
		}

		// Handle random sorting separately using database query
		if ($sort === 'rand') {
			$connection = Drupal::database();
			$query = $connection
				->select('comment_field_data', 'c')
				->fields('c', ['cid'])
				->condition('c.status', 1)
				->condition('c.entity_id', $node->id())
				->condition('c.entity_type', 'node')
				->condition('c.field_name', $fieldName);

			if ($search) {
				$cb = $query->leftJoin('comment__comment_body', 'cb', 'cb.entity_id = c.cid');
				$query->condition("$cb.comment_body_value", "%$search%", 'LIKE');
			}

			$query->orderRandom()->range(($page - 1) * $limit, $limit);
			$ids = $query->execute()->fetchCol();
			$storage = Drupal::entityTypeManager()->getStorage('comment');
			$comments = $storage->loadMultiple($ids);
		} else {
			$storage = Drupal::entityTypeManager()->getStorage('comment');
			$query = $storage
				->getQuery()
				->accessCheck(false)
				->condition('entity_id', $node->id())
				->condition('entity_type', 'node')
				->condition('field_name', $fieldName)
				->condition('status', 1)
				->range(($page - 1) * $limit, $limit);

			// Add sorting
			$sortDirection = $sort === 'desc' ? 'DESC' : 'ASC';
			$query->sort('created', $sortDirection);

			if ($search) {
				$query->condition(
					'comment_body.value',
					Drupal::database()->escapeLike($search),
					'CONTAINS',
				);
			}

			$ids = $query->execute();
			$comments = $storage->loadMultiple($ids);
		}

		return array_values($comments);
	}

	public static function addComment(UserInterface $owner, Node $response, string $body): Comment
	{
		$fieldName = self::getCommentFieldName($response);
		if (!$fieldName) {
			throw new InvalidArgumentException('No comment field configured for this content type');
		}

		$commentType = self::getCommentTypeForField($response, $fieldName) ?? 'comment';

		$comment = Comment::create([
			'entity_type' => 'node',
			'entity_id' => $response->id(),
			'field_name' => $fieldName,
			'comment_type' => $commentType,
			'uid' => $owner->id(),
			'name' => $owner->getDisplayName(),
			'mail' => $owner->getEmail(),
			'status' => 1,
			'comment_body' => [
				'value' => $body,
				'format' => 'plain_text',
			],
		]);

		$comment->save();

		return $comment;
	}

	public static function getCommentsCount(Node $node, string $search = ''): int
	{
		$fieldName = self::getCommentFieldName($node);
		if (!$fieldName) {
			return 0;
		}

		$storage = Drupal::entityTypeManager()->getStorage('comment');
		$query = $storage
			->getQuery()
			->accessCheck(false)
			->condition('entity_id', $node->id())
			->condition('entity_type', 'node')
			->condition('field_name', $fieldName)
			->condition('status', 1);

		if ($search) {
			$query->condition(
				'comment_body.value',
				Drupal::database()->escapeLike($search),
				'CONTAINS',
			);
		}

		return $query->count()->execute();
	}

	/**
	 * @return array<PromptResponse>
	 */
	public static function getResponses(
		Node $node,
		int $page = 1,
		int $limit = 25,
		string $search = '',
		string $sort = 'desc',
	): array {
		$comments = self::getComments($node, $page, $limit, $search, $sort);
		$responses = [];

		foreach ($comments as $comment) {
			$response = self::entityToPromptResponse($comment);
			if ($response) {
				$responses[] = $response;
			}
		}

		return $responses;
	}

	public const EXPIRED_PROMPTS_TTL = 172800; // 2 days

	public static function checkExpiredPrompts(): void
	{
		$timeNow = time();
		$storage = Drupal::entityTypeManager()->getStorage('node');

		$query = $storage
			->getQuery()
			->accessCheck(false)
			->condition('type', 'prompt')
			->condition(
				'field_visibility',
				GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
			)
			->condition('created', $timeNow - self::EXPIRED_PROMPTS_TTL, '<');

		$nids = $query->execute();
		if (empty($nids)) {
			return;
		}

		$nodes = $storage->loadMultiple($nids);

		/** @var Node $node */
		foreach ($nodes as $node) {
			$owner = User::load($node->get('field_owner_id')->value);
			$prompt = self::nodeToPrompt($node);
			if ($owner) {
				UsersHelper::addNotification(
					$owner,
					Drupal::translation()->translate('Prompt Expired'),
					Drupal::translation()->translate(
						"Your prompt \"{$prompt->getPrompt()}\" has expired and been deleted.",
					),
				);
			}

			$node->delete();
		}

		return;
	}

	public static function getPrompts(UserInterface $user, int $limit = 10): array
	{
		$storage = Drupal::entityTypeManager()->getStorage('node');

		$query = $storage
			->getQuery()
			->accessCheck(true)
			->condition('type', 'prompt')
			->condition('field_owner_id', $user->id())
			->range(0, $limit)
			->sort('created', 'DESC');

		$nids = $query->execute();
		if (empty($nids)) {
			return [];
		}

		$nodes = $storage->loadMultiple($nids);
		$prompts = [];

		/** @var Node $node */
		foreach ($nodes as $node) {
			$prompts[] = self::nodeToPrompt($node);
		}

		return $prompts;
	}

	public static function getPromptsCount(UserInterface $user): int
	{
		$storage = Drupal::entityTypeManager()->getStorage('node');

		$query = $storage
			->getQuery()
			->accessCheck(true)
			->condition('type', 'prompt')
			->condition('field_owner_id', $user->id());

		return $query->count()->execute();
	}

	public static function hasResponded(UserInterface $user, Node $promptNode): bool
	{
		$fieldName = self::getCommentFieldName($promptNode);
		if (!$fieldName) {
			return false;
		}

		$storage = Drupal::entityTypeManager()->getStorage('comment');
		$query = $storage
			->getQuery()
			->accessCheck(false)
			->condition('entity_id', $promptNode->id())
			->condition('entity_type', 'node')
			->condition('field_name', $fieldName)
			->condition('uid', $user->id())
			->condition('status', 1);

		$count = $query->count()->execute();

		return $count > 0;
	}
}
