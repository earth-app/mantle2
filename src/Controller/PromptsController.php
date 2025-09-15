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

		try {
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
							GeneralHelper::findOrdinal(Visibility::cases(), Visibility::UNLISTED),
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
			$total = $countQuery->count()->execute();

			$query->range($page * $limit, $limit);
			$nids = $query->execute();

			$data = [];
			foreach ($nids as $nid) {
				$node = Node::load($nid);
				if ($node) {
					$data[] = [
						'id' => (int) $nid,
						...PromptsHelper::nodeToPrompt($node)->jsonSerialize(),
					];
				}
			}

			return new JsonResponse([
				'page' => $page + 1,
				'total' => $total,
				'limit' => $limit,
				'items' => $data,
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

		if (!UsersHelper::isWriter($user)) {
			return GeneralHelper::paymentRequired('Upgrade to Writer required');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$data = $body['prompt'] ?? null;
		$visibility = $body['visibility'] ?? null;
		if (
			!is_string($data) ||
			!in_array($visibility, array_map(fn($v) => $v->value, Visibility::cases()), true)
		) {
			return GeneralHelper::badRequest('Missing or invalid fields');
		}

		$obj = new Prompt($data, $user->id(), Visibility::from($visibility));
		$node = PromptsHelper::createPrompt($obj, $user);
		if (!$node) {
			return GeneralHelper::internalError('Failed to create prompt');
		}

		$result = $obj->jsonSerialize();
		$result['id'] = GeneralHelper::formatId($node->id());
		$result['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
		$result['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

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

				$result = PromptsHelper::nodeToPrompt($node)->jsonSerialize();
				$result['id'] = GeneralHelper::formatId($randomNid);
				$result['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
				$result['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

				$results[] = $result;
			}

			return new JsonResponse($results);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load prompts storage: ' . $e->getMessage(),
			);
		} catch (UnexpectedValueException $e) {
			return GeneralHelper::badRequest('Invalid count parameter: ' . $e->getMessage());
		}
	}

	// GET /v2/prompts/{prompt}
	public function getPrompt(Request $request, Node $prompt)
	{
		if (!$prompt || $prompt->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::getOwnerOfRequest($request);
		$data = PromptsHelper::nodeToPrompt($prompt);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$result = $data->jsonSerialize();
		$result['id'] = GeneralHelper::formatId($prompt->id());
		$result['created_at'] = GeneralHelper::dateToIso($prompt->getCreatedTime());
		$result['updated_at'] = GeneralHelper::dateToIso($prompt->getChangedTime());

		return new JsonResponse($result);
	}

	// PATCH /v2/prompts/{prompt}
	public function updatePrompt(Request $request, Node $prompt)
	{
		if (!$prompt || $prompt->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$data = PromptsHelper::nodeToPrompt($prompt);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if ($data->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to update this prompt');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$updated = false;

		$newPrompt = $body['prompt'] ?? null;
		if (is_string($newPrompt) && $newPrompt !== $data->getPrompt()) {
			$data->setPrompt($newPrompt);
			$updated = true;
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

		PromptsHelper::updatePrompt($prompt, $data);

		$result = $data->jsonSerialize();
		$result['id'] = GeneralHelper::formatId($prompt->id());
		$result['created_at'] = GeneralHelper::dateToIso($prompt->getCreatedTime());
		$result['updated_at'] = GeneralHelper::dateToIso($prompt->getChangedTime());

		return new JsonResponse($result);
	}

	// DELETE /v2/prompts/{prompt}
	public function deletePrompt(Request $request, Node $prompt)
	{
		if (!$prompt || $prompt->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$data = PromptsHelper::nodeToPrompt($prompt);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if ($data->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to delete this prompt');
		}

		$prompt->delete();

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// GET /v2/prompts/{prompt}/responses
	public function getPromptResponses(Request $request, Node $prompt)
	{
		if (!$prompt || $prompt->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::getOwnerOfRequest($request);
		$data = PromptsHelper::nodeToPrompt($prompt);
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

		$responses = PromptsHelper::getResponses($prompt, $page, $limit, $search);
		$total = PromptsHelper::getCommentsCount($prompt);

		return new JsonResponse([
			'page' => $page,
			'total' => $total,
			'limit' => $limit,
			'items' => array_map(function ($r) use ($request) {
				$owner = $r->getOwner();
				if (UsersHelper::checkVisibility($owner, $request) instanceof JsonResponse) {
					$r->hideOwnerId();
				}

				$result = $r->jsonSerialize();
				$result['created_at'] = GeneralHelper::dateToIso($r->getCreatedAt());
				$result['updated_at'] = GeneralHelper::dateToIso($r->getUpdatedAt());

				return $result;
			}, $responses),
		]);
	}

	// POST /v2/prompts/{prompt}/responses
	public function createPromptResponse(Request $request, Node $prompt)
	{
		if (!$prompt || $prompt->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$data = PromptsHelper::nodeToPrompt($prompt);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$content = $body['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			return GeneralHelper::badRequest('Missing or invalid content');
		}

		if (!$user->hasPermission('post comments')) {
			return GeneralHelper::forbidden('You do not have permission to post responses');
		}

		$response = PromptsHelper::addComment($user, $prompt, $content);
		if (!$response) {
			return GeneralHelper::internalError('Failed to create response');
		}

		$result = PromptsHelper::entityToPromptResponse($response);
		if (!$result) {
			return GeneralHelper::internalError('Failed to load created response');
		}

		$res = $result->jsonSerialize();
		$res['created_at'] = GeneralHelper::dateToIso($response->getCreatedTime());
		$res['updated_at'] = GeneralHelper::dateToIso($response->getChangedTime());

		return new JsonResponse($res, Response::HTTP_CREATED);
	}

	// GET /v2/prompts/{prompt}/responses/count
	public function getPromptResponsesCount(Request $request, Node $prompt)
	{
		if (!$prompt || $prompt->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::getOwnerOfRequest($request);
		$data = PromptsHelper::nodeToPrompt($prompt);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}
		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$count = PromptsHelper::getCommentsCount($prompt);
		$result = $data->jsonSerialize();
		$result['created_at'] = GeneralHelper::dateToIso($prompt->getCreatedTime());
		$result['updated_at'] = GeneralHelper::dateToIso($prompt->getChangedTime());

		return new JsonResponse(['count' => $count, 'prompt' => $result], Response::HTTP_OK);
	}

	// GET /v2/prompts/{prompt}/responses/{response}
	public function getPromptResponse(Request $request, Node $prompt, Comment $response)
	{
		if (!$prompt || $prompt->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::getOwnerOfRequest($request);
		$data = PromptsHelper::nodeToPrompt($prompt);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		if ($response->getCommentedEntityId() != $prompt->id()) {
			return GeneralHelper::notFound('Response not found');
		}

		$result = PromptsHelper::entityToPromptResponse($response);
		if (!$result) {
			return GeneralHelper::internalError('Failed to load response');
		}

		if (UsersHelper::checkVisibility($response->getOwner(), $request) instanceof JsonResponse) {
			$result->hideOwnerId();
		}

		$res = $result->jsonSerialize();
		$res['created_at'] = GeneralHelper::dateToIso($response->getCreatedTime());
		$res['updated_at'] = GeneralHelper::dateToIso($response->getChangedTime());

		return new JsonResponse($res, Response::HTTP_OK);
	}

	// PATCH /v2/prompts/{prompt}/responses/{response}
	public function updatePromptResponse(Request $request, Node $prompt, Comment $response)
	{
		if (!$prompt || $prompt->getType() !== 'prompt') {
			return GeneralHelper::notFound('Prompt not found');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$data = PromptsHelper::nodeToPrompt($prompt);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		if ($response->getCommentedEntityId() != $prompt->id()) {
			return GeneralHelper::notFound('Response not found');
		}

		if ($response->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to update this response');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$content = $body['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			return GeneralHelper::badRequest('Missing or invalid content');
		}

		$response->set('comment_body', $content);
		$response->setChangedTime(time());
		if (!$response->save()) {
			return GeneralHelper::internalError('Failed to update response');
		}

		$result = PromptsHelper::entityToPromptResponse($response);
		if (!$result) {
			return GeneralHelper::internalError('Failed to load updated response');
		}

		if (UsersHelper::checkVisibility($response->getOwner(), $request) instanceof JsonResponse) {
			$result->hideOwnerId();
		}

		$res = $result->jsonSerialize();
		$res['created_at'] = GeneralHelper::dateToIso($response->getCreatedTime());
		$res['updated_at'] = GeneralHelper::dateToIso($response->getChangedTime());

		return new JsonResponse($res, Response::HTTP_OK);
	}

	// DELETE /v2/prompts/{prompt}/responses/{response}
	public function deletePromptResponse(Request $request, Node $prompt, Comment $response)
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$data = PromptsHelper::nodeToPrompt($prompt);
		if (!$data) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($data, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		if ($response->getCommentedEntityId() != $prompt->id()) {
			return GeneralHelper::notFound('Response not found');
		}

		if ($response->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to delete this response');
		}

		$response->delete();

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}
}
