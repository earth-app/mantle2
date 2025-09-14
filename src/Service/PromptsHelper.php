<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\comment\Entity\Comment;
use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Custom\PromptResponse;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\node\Entity\Node;
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
		$body = $response->get('comment_body')->value;

		return new PromptResponse(
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
	): array {
		$fieldName = self::getCommentFieldName($node);
		if (!$fieldName) {
			// No comment field configured for this bundle; nothing to return.
			return [];
		}

		$storage = Drupal::entityTypeManager()->getStorage('comment');
		$query = $storage
			->getQuery()
			->accessCheck(false)
			->condition('entity_id', $node->id())
			->condition('entity_type', 'node')
			->condition('field_name', $fieldName)
			->condition('status', 1)
			->range(($page - 1) * $limit, $limit)
			->sort('created', 'DESC');

		if ($search) {
			$query->condition(
				'comment_body.value',
				Drupal::database()->escapeLike($search),
				'CONTAINS',
			);
		}

		$ids = $query->execute();
		$comments = $storage->loadMultiple($ids);

		return array_values($comments);
	}

	public static function addComment(UserInterface $owner, Node $response, string $body): Comment
	{
		$fieldName = self::getCommentFieldName($response);
		if (!$fieldName) {
			throw new InvalidArgumentException('No comment field configured for this content type');
		}

		$comment = Comment::create([
			'entity_type' => 'node',
			'entity_id' => $response->id(),
			'field_name' => $fieldName,
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
	): array {
		$comments = self::getComments($node, $page, $limit, $search);
		$responses = [];

		foreach ($comments as $comment) {
			$response = self::entityToPromptResponse($comment);
			if ($response) {
				$responses[] = $response;
			}
		}

		return $responses;
	}
}
