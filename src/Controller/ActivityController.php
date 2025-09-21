<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

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
					$res = ActivityHelper::nodeToActivity($node)->jsonSerialize();
					$res['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
					$res['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

					$data[] = $res;
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
	public function randomActivity(Request $request): JsonResponse
	{
		try {
			$count = $request->query->getInt('count', 3);

			$connection = Drupal::database();
			$query = $connection
				->select('node_field_data', 'n')
				->fields('n', ['nid'])
				->condition('status', 1)
				->condition('type', 'activity')
				->orderRandom()
				->range(0, $count);

			$nids = $query->execute()->fetchCol();

			if (empty($nids)) {
				return GeneralHelper::notFound('No activities found');
			}

			$activities = [];
			foreach ($nids as $nid) {
				$node = Node::load($nid);

				if (!$node) {
					return GeneralHelper::internalError("Failed to load activity node $nid");
				}

				$data = ActivityHelper::nodeToActivity($node)->jsonSerialize();
				$data['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
				$data['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());
				$activities[] = $data;
			}

			return new JsonResponse($activities);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load activity storage: ' . $e->getMessage(),
			);
		} catch (UnexpectedValueException $e) {
			return GeneralHelper::badRequest('Invalid count parameter: ' . $e->getMessage());
		}
	}

	// POST /v2/activities
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
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$id = $body['id'] ?? null;
		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$types = $body['types'] ?? [];

		if (!$id || !$name || !$description || empty($types)) {
			return GeneralHelper::badRequest('Missing required fields');
		}

		if (ActivityHelper::getNodeByActivityId($id)) {
			return GeneralHelper::conflict("Activity with ID '$id' already exists");
		}

		if (!is_string($id) || !is_string($name) || !is_string($description) || !is_array($types)) {
			return GeneralHelper::badRequest('Invalid required field types');
		}

		foreach ($types as $type) {
			if (!is_string($type) || !ActivityType::tryFrom($type)) {
				return GeneralHelper::badRequest('Invalid activity type: ' . (string) $type);
			}
		}

		$fields = $body['fields'] ?? ['icon' => ''];
		$aliases = $body['aliases'] ?? [];

		if (!is_array($fields) || !is_array($aliases)) {
			return GeneralHelper::badRequest('Invalid optional field types');
		}

		foreach ($fields as $key => $value) {
			if (!is_string($key) || !is_string($value)) {
				return GeneralHelper::badRequest('Invalid field entry types');
			}
		}

		foreach ($aliases as $alias) {
			if (!is_string($alias)) {
				return GeneralHelper::badRequest('Invalid alias entry type');
			}
		}

		$activity = new Activity($id, $name, $types, $description, $aliases, $fields);
		ActivityHelper::createActivity($activity, $user);

		return new JsonResponse($activity, Response::HTTP_CREATED);
	}

	// GET /v2/activities/:activityId
	public function getActivity(Request $request, string $activityId): JsonResponse
	{
		$node = ActivityHelper::getNodeByActivityId($activityId);
		$includeAliases = $request->query->getBoolean('include_aliases', false);
		if (!$node) {
			if ($includeAliases) {
				$node = ActivityHelper::getNodeByActivityAlias($activityId);
			}

			if (!$node) {
				return GeneralHelper::notFound("Activity '$activityId' not found");
			}
		}

		$activity = ActivityHelper::nodeToActivity($node)->jsonSerialize();
		$activity['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
		$activity['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

		return new JsonResponse($activity, Response::HTTP_OK);
	}

	// PATCH /v2/activities/:activityId
	public function updateActivity(Request $request, string $activityId): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You do not have permission to update activities.');
		}

		$node = ActivityHelper::getNodeByActivityId($activityId);
		if (!$node) {
			return GeneralHelper::notFound("Activity '$activityId' not found");
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$types = $body['types'] ?? null;
		$fields = $body['fields'] ?? null;
		$aliases = $body['aliases'] ?? null;

		if ($name !== null && !is_string($name)) {
			return GeneralHelper::badRequest('Invalid name type');
		}

		if ($description !== null && !is_string($description)) {
			return GeneralHelper::badRequest('Invalid description type');
		}

		if ($types !== null) {
			if (!is_array($types) || empty($types)) {
				return GeneralHelper::badRequest('Invalid types');
			}

			foreach ($types as $type) {
				if (!is_string($type) || !ActivityType::tryFrom($type)) {
					return GeneralHelper::badRequest('Invalid activity type: ' . (string) $type);
				}
			}
		}

		if ($fields !== null) {
			if (!is_array($fields)) {
				return GeneralHelper::badRequest('Invalid fields type');
			}

			foreach ($fields as $key => $value) {
				if (!is_string($key) || !is_string($value)) {
					return GeneralHelper::badRequest('Invalid field entry types');
				}
			}
		}

		if ($aliases !== null) {
			if (!is_array($aliases)) {
				return GeneralHelper::badRequest('Invalid aliases type');
			}

			foreach ($aliases as $alias) {
				if (!is_string($alias)) {
					return GeneralHelper::badRequest('Invalid alias entry type');
				}
			}
		}

		$activity = ActivityHelper::nodeToActivity($node);
		if ($name !== null) {
			$activity->setName($name);
		}

		if ($description !== null) {
			$activity->setDescription($description);
		}

		if ($types !== null) {
			$activity->setTypes($types);
		}

		if ($fields !== null) {
			$activity->setFields($fields);
		}

		if ($aliases !== null) {
			$activity->setAliases($aliases);
		}

		ActivityHelper::updateActivity($node, $activity);
		return new JsonResponse($activity, Response::HTTP_OK);
	}

	// DELETE /v2/activities/:activityId
	public function deleteActivity(Request $request, string $activityId): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You do not have permission to delete activities.');
		}

		$node = ActivityHelper::getNodeByActivityId($activityId);
		if (!$node) {
			return GeneralHelper::notFound("Activity '$activityId' not found");
		}

		ActivityHelper::deleteActivity($node);
		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// GET /v2/activities/list
	public function listActivities(Request $request): JsonResponse
	{
		$pagination = GeneralHelper::paginatedParameters($request, 1000);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		try {
			$connection = Drupal::database();
			$query = $connection->select('node_field_data', 'n');

			$f = $query->join('node__field_activity_id', 'f', 'n.nid = f.entity_id');
			$query
				->fields($f, ['field_activity_id_value'])
				->condition('n.type', 'activity')
				->condition('n.status', 1);

			if ($search) {
				$group = $query
					->orConditionGroup()
					->condition('f.field_activity_id_value', $search, 'CONTAINS');
				$query->condition($group);
			}

			$countQuery = clone $query;
			$total = (int) $countQuery->countQuery()->execute()->fetchField();
			$ids = $query
				->range($page * $limit, $limit)
				->execute()
				->fetchCol();
			if (empty($ids)) {
				return GeneralHelper::notFound('No activities found');
			}

			return new JsonResponse(
				[
					'page' => $page + 1,
					'total' => $total,
					'limit' => $limit,
					'items' => $ids,
				],
				Response::HTTP_OK,
			);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load activity storage: ' . $e->getMessage(),
			);
		}
	}
}
