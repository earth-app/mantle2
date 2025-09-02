<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\node\Entity\Node;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ActivityController extends ControllerBase
{
	public static function create(ContainerInterface $container): ActivityController|static
	{
		return new static();
	}

	// GET /v2/activities
	public function activities(Request $request): JsonResponse
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
			$query = $storage->getQuery()->accessCheck(false)->condition('type', 'activity');

			if ($search) {
				// For JSON fields, we search the raw JSON string which may contain the search term
				$group = $query
					->orConditionGroup()
					->condition('field_activity_id', $search, 'CONTAINS')
					->condition('field_activity_name', $search, 'CONTAINS')
					->condition('field_activity_description', $search, 'CONTAINS')
					->condition('field_activity_aliases', $search, 'CONTAINS');
				$query->condition($group);
			}

			$countQuery = clone $query;
			$total = $countQuery->count()->execute();

			$query->range($page * $limit, $limit);
			$nids = $query->execute();

			$data = [];
			foreach ($nids as $nid) {
				$node = Node::load($nid);
				if ($node) {
					$data[] = ActivityHelper::nodeToActivity($node)->jsonSerialize();
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
				'Failed to load activity storage: ' . $e->getMessage(),
			);
		}
	}

	// GET /v2/activities/random
	public function randomActivity(): JsonResponse
	{
		try {
			$storage = Drupal::entityTypeManager()->getStorage('node');
			$query = $storage
				->getQuery()
				->accessCheck(false)
				->condition('type', 'activity')
				->range(0, 1);
			$nids = $query->execute();

			if (empty($nids)) {
				return new JsonResponse(['error' => 'No activities found'], 404);
			}

			$node = Node::load($nids[0]);
			if (!$node) {
				return new JsonResponse(['error' => 'Activity not found'], 404);
			}

			$data = ActivityHelper::nodeToActivity($node)->jsonSerialize();
			return new JsonResponse($data);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load activity storage: ' . $e->getMessage(),
			);
		}
	}

	// POST /v1/activities
	public function createActivity(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You do not have permission to create activities.');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$id = $body['id'] ?? null;
		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$types = $body['types'] ?? [];

		if (!$id || !$name || !$description || empty($types)) {
			return GeneralHelper::badRequest('Missing required fields');
		}

		$fields = $body['fields'] ?? [];
		$aliases = $body['aliases'] ?? [];

		$activity = new Activity($id, $name, $description, $types, $aliases, $fields);
		$result = ActivityHelper::createActivity($activity);

		return new JsonResponse($result, Response::HTTP_CREATED);
	}

	// GET /v2/activities/:activityId
	public function getActivity(string $activityId): JsonResponse
	{
		$activity = ActivityHelper::getActivity($activityId);
		if (!$activity) {
			return GeneralHelper::notFound("Activity '$activityId' not found");
		}

		return new JsonResponse($activity->jsonSerialize());
	}
}
