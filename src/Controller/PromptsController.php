<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\comment\Entity\Comment;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\mantle2\Service\PromptsHelper;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Exception\UnexpectedValueException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PromptsController extends ControllerBase
{
	public static function create(ContainerInterface $container): PromptsController|static
	{
		return new static();
	}

	// GET /v2/prompts
	public function prompts(Request $request)
	{
		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];
		$sort = $pagination['sort'];

		try {
			// Handle random sorting separately using database query
			if ($sort === 'rand') {
				$connection = Drupal::database();
				$query = $connection
					->select('node_field_data', 'n')
					->fields('n', ['nid'])
					->condition('n.status', 1)
					->condition('n.type', 'prompt');

				$fv = $query->leftJoin('node__field_visibility', 'fv', 'fv.entity_id = n.nid');
				$query->condition("$fv.delta", 0);

				// Check visibility
				$user = UsersHelper::getOwnerOfRequest($request);
				if ($user) {
					if (!UsersHelper::isAdmin($user)) {
						// Non-private prompts for logged-in users OR prompts owned by the user.
						$fo = $query->leftJoin(
							'node__field_owner_id',
							'fo',
							'fo.entity_id = n.nid',
						);
						$query->condition("$fo.delta", 0);

						$group = $query
							->orConditionGroup()
							->condition(
								"$fv.field_visibility_value",
								[
									GeneralHelper::findOrdinal(
										Visibility::cases(),
										Visibility::PUBLIC,
									),
									GeneralHelper::findOrdinal(
										Visibility::cases(),
										Visibility::UNLISTED,
									),
								],
								'IN',
							)
							->condition("$fo.field_owner_id_value", $user->id());
						$query->condition($group);
					}
				} else {
					// Only public prompts for anonymous users
					$query->condition(
						"$fv.field_visibility_value",
						GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
					);
				}

				if ($search) {
					$escapedSearch = Drupal::database()->escapeLike($search);
					$fp = $query->leftJoin('node__field_prompt', 'fp', 'fp.entity_id = n.nid');
					$query->condition("$fp.field_prompt_value", "%$escapedSearch%", 'LIKE');
				}

				// Get total count for random
				$countQuery = clone $query;
				$total = (int) $countQuery->countQuery()->execute()->fetchField();

				$query->orderRandom()->range($page * $limit, $limit);
				$nids = $query->execute()->fetchCol();
			} else {
				// Use entity query for normal sorting
				$storage = Drupal::entityTypeManager()->getStorage('node');
				$query = $storage->getQuery()->accessCheck(false)->condition('type', 'prompt');

				// Check visibility
				$user = UsersHelper::getOwnerOfRequest($request);
				if ($user) {
					if (!UsersHelper::isAdmin($user)) {
						// non-private events for logged in users
						$group = $query->orConditionGroup();
						$group->condition(
							'field_visibility',
							[
								GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
								GeneralHelper::findOrdinal(
									Visibility::cases(),
									Visibility::UNLISTED,
								),
							],
							'IN',
						);

						// is owner
						$group->condition('field_owner_id', $user->id());
						$query->condition($group);
					}
				} else {
					// only public events for anonymous users
					$query->condition(
						'field_visibility',
						GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
					);
				}

				if ($search) {
					$query->condition('field_prompt', $search, 'CONTAINS');
				}

				$countQuery = clone $query;
				$total = (int) $countQuery->count()->execute();

				// Add sorting
				$sortDirection = $sort === 'desc' ? 'DESC' : 'ASC';
				$query->sort('created', $sortDirection);

				$query->range($page * $limit, $limit);
				$nids = $query->execute();
			}

			$data = [];
			foreach ($nids as $nid) {
				$node = Node::load($nid);
				if ($node) {
					$obj = PromptsHelper::nodeToPrompt($node);
					$serialized = PromptsHelper::serializePrompt($obj, $node, $user);
					$serialized['id'] = GeneralHelper::formatId($nid);
					$data[] = $serialized;
				}
			}

			return new JsonResponse([
				'page' => $page + 1,
				'total' => $total,
				'limit' => $limit,
				'items' => $data,
				'sort' => $sort,
				'search' => $search,
			]);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load prompts storage: ' . $e->getMessage(),
			);
		}
	}

	// POST /v2/prompts
	public function createPrompt(Request $request)
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$count = PromptsHelper::getPromptsCount($user);
		if ($count >= 1 && !UsersHelper::isWriter($user)) {
			return GeneralHelper::paymentRequired('Upgrade to Writer required for more prompts');
		}

		if ($count >= 10 && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('Prompt limit reached');
		}

		if (UsersHelper::getVisibility($user) === Visibility::PRIVATE) {
			return GeneralHelper::badRequest('Private accounts cannot create public content');
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		$data = $body['prompt'] ?? null;
		$visibility = $body['visibility'] ?? null;
		if (
			!is_string($data) ||
			!in_array($visibility, array_map(fn($v) => $v->value, Visibility::cases()), true)
		) {
			return GeneralHelper::badRequest('Missing or invalid fields');
		}

		if (strlen($data) < 10 || strlen($data) > 100) {
			return GeneralHelper::badRequest(
				'Prompt must be between length of 10 and 100 characters',
			);
		}

		$censor = $body['censor'] ?? false;
		if (!is_bool($censor)) {
			return GeneralHelper::badRequest('Field censor must be a boolean');
		}

		$flagResult = GeneralHelper::isFlagged($data);
		if ($flagResult['flagged']) {
			if ($censor) {
				$data = GeneralHelper::censorText($data);
			} else {
				Drupal::logger('mantle2')->warning(
					'User %uid attempted to create flagged prompt: %prompt (matched: %matched)',
					[
						'%uid' => $user->id(),
						'%prompt' => $data,
						'%matched' => $flagResult['matched_word'],
					],
				);
				return GeneralHelper::badRequest(
					'Prompt contains inappropriate content: ' . $flagResult['matched_word'],
				);
			}
		}

		// temporary id (0) for new prompt
		$obj = new Prompt(0, $data, $user->id(), Visibility::from($visibility));
		$node = PromptsHelper::createPrompt($obj, $user);
		if (!$node) {
			return GeneralHelper::internalError('Failed to create prompt');
		}

		$result = PromptsHelper::serializePrompt($obj, $node, $user);
		$result['id'] = GeneralHelper::formatId($node->id()); // set real id

		return new JsonResponse($result, Response::HTTP_CREATED);
	}

	// GET /v2/prompts/random
	public function randomPrompt(Request $request)
	{
		try {
			$count = $request->query->getInt('count', 10);
			if ($count < 1 || $count > 25) {
				return GeneralHelper::badRequest('Count must be between 1 and 25');
			}

			$connection = Drupal::database();
			$query = $connection
				->select('node_field_data', 'n')
				->fields('n', ['nid'])
				->condition('n.status', 1)
				->condition('n.type', 'prompt');

			$fv = $query->leftJoin('node__field_visibility', 'fv', 'fv.entity_id = n.nid');
			$query->condition("$fv.delta", 0);

			// Check visibility
			$user = UsersHelper::getOwnerOfRequest($request);
			if ($user) {
				if (!UsersHelper::isAdmin($user)) {
					// Non-private prompts for logged-in users OR prompts owned by the user.
					$fo = $query->leftJoin('node__field_owner_id', 'fo', 'fo.entity_id = n.nid');
					$query->condition("$fo.delta", 0);

					$group = $query
						->orConditionGroup()
						->condition(
							"$fv.field_visibility_value",
							[
								GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
								GeneralHelper::findOrdinal(
									Visibility::cases(),
									Visibility::UNLISTED,
								),
							],
							'IN',
						)
						->condition("$fo.field_owner_id_value", $user->id());
					$query->condition($group);
				}
			} else {
				// Only public prompts for anonymous users
				$query->condition(
					"$fv.field_visibility_value",
					GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
				);
			}

			$query->orderRandom()->range(0, $count);
			$nids = $query->execute()->fetchCol();

			if (empty($nids)) {
				return GeneralHelper::notFound('No prompts found');
			}

			$results = [];

			foreach ($nids as $randomNid) {
				$node = Node::load($randomNid);

				if (!$node) {
					return GeneralHelper::internalError('Failed to load random prompt');
				}

				$data = PromptsHelper::nodeToPrompt($node);
				$result = PromptsHelper::serializePrompt($data, $node, $user);
				$results[] = $result;
			}

			return new JsonResponse($results, Response::HTTP_OK);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load prompts storage: ' . $e->getMessage(),
			);
		} catch (UnexpectedValueException $e) {
			return GeneralHelper::badRequest('Invalid count parameter: ' . $e->getMessage());
		}
	}

	// GET /v2/prompts/{prompt}
	public function getPrompt(int $prompt, Request $request)
	{
		$node = Node::load($prompt);
		if (!$node || $node->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::getOwnerOfRequest($request);
		$data = PromptsHelper::nodeToPrompt($node);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$result = PromptsHelper::serializePrompt($data, $node, $user);
		return new JsonResponse($result);
	}

	// PATCH /v2/prompts/{prompt}
	public function updatePrompt(int $prompt, Request $request)
	{
		$node = Node::load($prompt);
		if (!$node || $node->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$data = PromptsHelper::nodeToPrompt($node);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if ($data->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to update this prompt');
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$updated = false;

		$newPrompt = $body['prompt'] ?? null;
		if (is_string($newPrompt) && $newPrompt !== $data->getPrompt()) {
			$data->setPrompt($newPrompt);
			$updated = true;
		}

		if (is_string($newPrompt)) {
			$censor = $body['censor'] ?? false;
			if (!is_bool($censor)) {
				return GeneralHelper::badRequest('Field censor must be a boolean');
			}

			$flagResult = GeneralHelper::isFlagged($newPrompt);
			if ($flagResult['flagged']) {
				if ($censor) {
					$data->setPrompt(GeneralHelper::censorText($newPrompt));
					$updated = true;
				} else {
					Drupal::logger('mantle2')->warning(
						'User %uid attempted to update flagged prompt: %prompt (matched: %matched)',
						[
							'%uid' => $user->id(),
							'%prompt' => $newPrompt,
							'%matched' => $flagResult['matched_word'],
						],
					);
					return GeneralHelper::badRequest(
						'Prompt contains inappropriate content: ' . $flagResult['matched_word'],
					);
				}
			}
		}

		$newVisibility = $body['visibility'] ?? null;
		if (
			is_string($newVisibility) &&
			in_array($newVisibility, array_map(fn($v) => $v->value, Visibility::cases()), true) &&
			$newVisibility !== $data->getVisibility()->value
		) {
			$data->setVisibility(Visibility::from($newVisibility));
			$updated = true;
		}

		if (!$updated) {
			return GeneralHelper::badRequest('No changes provided');
		}

		PromptsHelper::updatePrompt($node, $data);

		$result = PromptsHelper::serializePrompt($data, $node, $user);
		return new JsonResponse($result);
	}

	// DELETE /v2/prompts/{prompt}
	public function deletePrompt(int $prompt, Request $request)
	{
		$node = Node::load($prompt);
		if (!$node || $node->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$data = PromptsHelper::nodeToPrompt($node);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if ($data->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to delete this prompt');
		}

		$node->delete();

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// GET /v2/prompts/{prompt}/responses
	public function getPromptResponses(int $prompt, Request $request)
	{
		$node = Node::load($prompt);
		if (!$node || $node->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::getOwnerOfRequest($request);
		$data = PromptsHelper::nodeToPrompt($node);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$paginated = GeneralHelper::paginatedParameters($request);
		if ($paginated instanceof JsonResponse) {
			return $paginated;
		}

		$limit = $paginated['limit'];
		$page = $paginated['page'];
		$search = $paginated['search'];
		$sort = $paginated['sort'];

		$responses = PromptsHelper::getResponses($node, $page, $limit, $search, $sort);
		$total = PromptsHelper::getCommentsCount($node);

		return new JsonResponse([
			'page' => $page,
			'total' => $total,
			'limit' => $limit,
			'items' => array_values(
				array_filter(
					array_map(function ($r) use ($user) {
						$result = PromptsHelper::serializePromptResponse($r, $user);
						$result['created_at'] = GeneralHelper::dateToIso($r->getCreatedAt());
						$result['updated_at'] = GeneralHelper::dateToIso($r->getUpdatedAt());

						return $result;
					}, $responses),
				),
			),
		]);
	}

	// POST /v2/prompts/{prompt}/responses
	public function createPromptResponse(int $prompt, Request $request)
	{
		$node = Node::load($prompt);
		if (!$node || $node->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$data = PromptsHelper::nodeToPrompt($node);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		$content = $body['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			return GeneralHelper::badRequest('Missing or invalid content');
		}

		$flagResult = GeneralHelper::isFlagged($content);
		if ($flagResult['flagged']) {
			Drupal::logger('mantle2')->warning(
				'User %uid attempted to create flagged prompt response: %prompt (matched: %matched)',
				[
					'%uid' => $user->id(),
					'%prompt' => $content,
					'%matched' => $flagResult['matched_word'],
				],
			);
			return GeneralHelper::badRequest(
				'Prompt response contains inappropriate content: ' . $flagResult['matched_word'],
			);
		}

		if (!$user->hasPermission('post comments')) {
			return GeneralHelper::forbidden('You do not have permission to post responses');
		}

		$response = PromptsHelper::addComment($user, $node, $content);
		if (!$response) {
			return GeneralHelper::internalError('Failed to create response');
		}

		$result = PromptsHelper::entityToPromptResponse($response);
		if (!$result) {
			return GeneralHelper::internalError('Failed to load created response');
		}

		$res = PromptsHelper::serializePromptResponse($result, $user);
		$res['created_at'] = GeneralHelper::dateToIso($response->getCreatedTime());
		$res['updated_at'] = GeneralHelper::dateToIso($response->getChangedTime());

		return new JsonResponse($res, Response::HTTP_CREATED);
	}

	// GET /v2/prompts/{prompt}/responses/{response}
	public function getPromptResponse(int $prompt, int $response, Request $request)
	{
		$node = Node::load($prompt);
		if (!$node || $node->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::getOwnerOfRequest($request);
		$data = PromptsHelper::nodeToPrompt($node);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$comment = Comment::load($response);
		if (!$comment) {
			return GeneralHelper::notFound('Response not found');
		}

		if ($comment->getCommentedEntityId() != $node->id()) {
			return GeneralHelper::notFound('Response not found');
		}

		$result = PromptsHelper::entityToPromptResponse($comment);
		if (!$result) {
			return GeneralHelper::internalError('Failed to load response');
		}

		$res = PromptsHelper::serializePromptResponse($result, $user);
		$res['created_at'] = GeneralHelper::dateToIso($comment->getCreatedTime());
		$res['updated_at'] = GeneralHelper::dateToIso($comment->getChangedTime());

		return new JsonResponse($res, Response::HTTP_OK);
	}

	// PATCH /v2/prompts/{prompt}/responses/{response}
	public function updatePromptResponse(int $prompt, int $response, Request $request)
	{
		$node = Node::load($prompt);
		if (!$node || $node->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$data = PromptsHelper::nodeToPrompt($node);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$comment = Comment::load($response);
		if (!$comment) {
			return GeneralHelper::notFound('Response not found');
		}

		if ($comment->getCommentedEntityId() != $node->id()) {
			return GeneralHelper::notFound('Response not found');
		}

		if ($comment->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to update this response');
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		$content = $body['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			return GeneralHelper::badRequest('Missing or invalid content');
		}

		$censor = $body['censor'] ?? false;
		if (!is_bool($censor)) {
			return GeneralHelper::badRequest('Field censor must be a boolean');
		}

		$flagResult = GeneralHelper::isFlagged($content);
		if ($flagResult['flagged']) {
			if ($censor) {
				$content = GeneralHelper::censorText($content);
			} else {
				Drupal::logger('mantle2')->warning(
					'User %uid attempted to create flagged prompt response: %prompt (matched: %matched)',
					[
						'%uid' => $user->id(),
						'%prompt' => $content,
						'%matched' => $flagResult['matched_word'],
					],
				);
				return GeneralHelper::badRequest(
					'Prompt response contains inappropriate content: ' .
						$flagResult['matched_word'],
				);
			}
		}

		$comment->set('comment_body', $content);
		$comment->setChangedTime(time());
		if (!$comment->save()) {
			return GeneralHelper::internalError('Failed to update response');
		}

		$result = PromptsHelper::entityToPromptResponse($comment);
		if (!$result) {
			return GeneralHelper::internalError('Failed to load updated response');
		}

		$res = PromptsHelper::serializePromptResponse($result, $user);
		$res['created_at'] = GeneralHelper::dateToIso($comment->getCreatedTime());
		$res['updated_at'] = GeneralHelper::dateToIso($comment->getChangedTime());

		return new JsonResponse($res, Response::HTTP_OK);
	}

	// DELETE /v2/prompts/{prompt}/responses/{response}
	public function deletePromptResponse(int $prompt, int $response, Request $request)
	{
		$node = Node::load($prompt);
		if (!$node || $node->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$data = PromptsHelper::nodeToPrompt($node);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$comment = Comment::load($response);
		if (!$comment) {
			return GeneralHelper::notFound('Response not found');
		}

		if ($comment->getCommentedEntityId() != $node->id()) {
			return GeneralHelper::notFound('Response not found');
		}

		if ($comment->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to delete this response');
		}

		$comment->delete();

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// POST /v2/prompts/check_expired
	public function checkExpiredPrompts(Request $request)
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to perform this action');
		}

		PromptsHelper::checkExpiredPrompts();
		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}
}
