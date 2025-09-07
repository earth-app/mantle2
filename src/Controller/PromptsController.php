<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\comment\Entity\Comment;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\EventsHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use PromptsHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
						[Visibility::PUBLIC->value, Visibility::UNLISTED->value],
						'IN',
					);

					// is owner
					$group->condition('field_owner_id', $user->id());
					$query->condition($group);
				}
			} else {
				// only public events for anonymous users
				$query->condition('field_visibility', Visibility::PUBLIC->value);
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
						'id' => $nid,
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

		$accountType = UsersHelper::getAccountType($user);
		if ($accountType === 'FREE' || $accountType === 'PRO') {
			return GeneralHelper::paymentRequired('Must be at least WRITER to create prompts');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$prompt = $body['prompt'] ?? null;
		$visibility = $body['visibility'] ?? null;
		if (
			!is_string($prompt) ||
			!in_array($visibility, array_map(fn($v) => $v->value, Visibility::cases()), true)
		) {
			return GeneralHelper::badRequest('Missing or invalid fields');
		}

		$obj = new Prompt($prompt, $user->id(), Visibility::from($visibility));
		$node = PromptsHelper::createPrompt($obj);
		if (!$node) {
			return GeneralHelper::internalError('Failed to create prompt');
		}

		$result = $obj->jsonSerialize();
		$result['id'] = $node->id();
		$result['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
		$result['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

		return new JsonResponse($result, Response::HTTP_CREATED);
	}

	// GET /v2/prompts/random
	public function randomPrompt(Request $request)
	{
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
						[Visibility::PUBLIC->value, Visibility::UNLISTED->value],
						'IN',
					);

					// is owner
					$group->condition('field_owner_id', $user->id());
					$query->condition($group);
				}
			} else {
				// only public events for anonymous users
				$query->condition('field_visibility', Visibility::PUBLIC->value);
			}

			$query->sort('RAND()');
			$query->range(0, 1);
			$nids = $query->execute();
			if (empty($nids)) {
				return GeneralHelper::notFound('No prompts found');
			}

			$randomNid = reset($nids);
			$node = Node::load($randomNid);
			if (!$node) {
				return GeneralHelper::internalError('Failed to load random prompt');
			}

			$result = PromptsHelper::nodeToPrompt($node)->jsonSerialize();
			$result['id'] = $node->id();
			$result['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
			$result['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

			return new JsonResponse($result);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load prompts storage: ' . $e->getMessage(),
			);
		}
	}

	// GET /v2/prompts/{promptId}
	public function getPrompt(Request $request, Node $node)
	{
		$user = UsersHelper::getOwnerOfRequest($request);
		$prompt = PromptsHelper::nodeToPrompt($node);
		if (!$prompt) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($prompt, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$result = $prompt->jsonSerialize();
		$result['id'] = $node->id();
		$result['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
		$result['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

		return new JsonResponse($result);
	}

	// PATCH /v2/prompts/{promptId}
	public function updatePrompt(Request $request, Node $node)
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$prompt = PromptsHelper::nodeToPrompt($node);
		if (!$prompt) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if ($prompt->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to update this prompt');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$updated = false;

		$newPrompt = $body['prompt'] ?? null;
		if (is_string($newPrompt) && $newPrompt !== $prompt->getPrompt()) {
			$prompt->setPrompt($newPrompt);
			$updated = true;
		}

		$newVisibility = $body['visibility'] ?? null;
		if (
			is_string($newVisibility) &&
			in_array($newVisibility, array_map(fn($v) => $v->value, Visibility::cases()), true) &&
			$newVisibility !== $prompt->getVisibility()->value
		) {
			$prompt->setVisibility(Visibility::from($newVisibility));
			$updated = true;
		}

		if (!$updated) {
			return GeneralHelper::badRequest('No changes provided');
		}

		$node = PromptsHelper::updatePrompt($node, $prompt);
		if (!$node) {
			return GeneralHelper::internalError('Failed to update prompt');
		}

		$result = $prompt->jsonSerialize();
		$result['id'] = $node->id();
		$result['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
		$result['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

		return new JsonResponse($result);
	}

	// DELETE /v2/prompts/{promptId}
	public function deletePrompt(Request $request, Node $node)
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$prompt = PromptsHelper::nodeToPrompt($node);
		if (!$prompt) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if ($prompt->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to delete this prompt');
		}

		if (!$node->delete()) {
			return GeneralHelper::internalError('Failed to delete prompt');
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// GET /v2/prompts/{promptId}/responses
	public function getPromptResponses(Request $request, Node $node)
	{
		$user = UsersHelper::getOwnerOfRequest($request);
		$prompt = PromptsHelper::nodeToPrompt($node);
		if (!$prompt) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($prompt, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$paginated = GeneralHelper::paginatedParameters($request);
		if ($paginated instanceof JsonResponse) {
			return $paginated;
		}

		$limit = $paginated['limit'];
		$page = $paginated['page'];
		$search = $paginated['search'];

		$responses = PromptsHelper::getResponses($node, $page, $limit, $search);
		$total = PromptsHelper::getCommentsCount($node);

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

	// POST /v2/prompts/{promptId}/responses
	public function createPromptResponse(Request $request, Node $node)
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$prompt = PromptsHelper::nodeToPrompt($node);
		if (!$prompt) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($prompt, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$content = $body['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			return GeneralHelper::badRequest('Missing or invalid content');
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

		$data = $result->jsonSerialize();
		$data['id'] = $response->id();
		$data['created_at'] = GeneralHelper::dateToIso($response->getCreatedTime());
		$data['updated_at'] = GeneralHelper::dateToIso($response->getChangedTime());

		return new JsonResponse($data, Response::HTTP_CREATED);
	}

	// GET /v2/prompts/{promptId}/responses/count
	public function getPromptResponsesCount(Request $request, Node $node)
	{
		$user = UsersHelper::getOwnerOfRequest($request);
		$prompt = PromptsHelper::nodeToPrompt($node);
		if (!$prompt) {
			return GeneralHelper::internalError('Failed to load prompt');
		}
		if (!PromptsHelper::isVisible($prompt, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		$count = PromptsHelper::getCommentsCount($node);
		$result = $prompt->jsonSerialize();
		$result['id'] = $node->id();
		$result['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
		$result['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());
		return new JsonResponse(['count' => $count, 'prompt' => $result]);
	}

	// GET /v2/prompts/{promptId}/responses/{responseId}
	public function getPromptResponse(Request $request, Node $node, Comment $response)
	{
		$user = UsersHelper::getOwnerOfRequest($request);
		$prompt = PromptsHelper::nodeToPrompt($node);
		if (!$prompt) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($prompt, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		if ($response->getCommentedEntityId() != $node->id()) {
			return GeneralHelper::notFound('Response not found');
		}

		$result = PromptsHelper::entityToPromptResponse($response);
		if (!$result) {
			return GeneralHelper::internalError('Failed to load response');
		}

		if (UsersHelper::checkVisibility($response->getOwner(), $request) instanceof JsonResponse) {
			$result->hideOwnerId();
		}

		$data = $result->jsonSerialize();
		$data['id'] = $response->id();
		$data['created_at'] = GeneralHelper::dateToIso($response->getCreatedTime());
		$data['updated_at'] = GeneralHelper::dateToIso($response->getChangedTime());

		return new JsonResponse($data);
	}

	// PATCH /v2/prompts/{promptId}/responses/{responseId}
	public function updatePromptResponse(Request $request, Node $node, Comment $response)
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$prompt = PromptsHelper::nodeToPrompt($node);
		if (!$prompt) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($prompt, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		if ($response->getCommentedEntityId() != $node->id()) {
			return GeneralHelper::notFound('Response not found');
		}

		if ($response->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to update this response');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
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

		$data = $result->jsonSerialize();
		$data['id'] = $response->id();
		$data['created_at'] = GeneralHelper::dateToIso($response->getCreatedTime());
		$data['updated_at'] = GeneralHelper::dateToIso($response->getChangedTime());

		return new JsonResponse($data);
	}

	// DELETE /v2/prompts/{promptId}/responses/{responseId}
	public function deletePromptResponse(Request $request, Node $node, Comment $response)
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$prompt = PromptsHelper::nodeToPrompt($node);
		if (!$prompt) {
			return GeneralHelper::internalError('Failed to load prompt');
		}

		if (!PromptsHelper::isVisible($prompt, $user)) {
			return GeneralHelper::notFound('Prompt not found');
		}

		if ($response->getCommentedEntityId() != $node->id()) {
			return GeneralHelper::notFound('Response not found');
		}

		if ($response->getOwnerId() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to delete this response');
		}

		if (!$response->delete()) {
			return GeneralHelper::internalError('Failed to delete response');
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}
}
