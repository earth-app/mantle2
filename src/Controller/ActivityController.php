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
	public function randomActivity(): JsonResponse
	{
		try {
			$connection = Drupal::database();
			$query = $connection
				->select('node_field_data', 'n')
				->fields('n', ['nid'])
				->condition('status', 1)
				->condition('type', 'activity')
				->orderRandom()
				->range(0, 1);

			$nids = $query->execute()->fetchCol();

			if (empty($nids)) {
				return new JsonResponse(['error' => 'No activities found'], 404);
			}

			$node = Node::load(reset($nids));
			if (!$node) {
				return new JsonResponse(['error' => 'Activity not found'], 404);
			}

			$data = ActivityHelper::nodeToActivity($node)->jsonSerialize();
			$data['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
			$data['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

			return new JsonResponse($data);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load activity storage: ' . $e->getMessage(),
			);
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
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$id = $body['id'] ?? null;
		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$types = $body['types'] ?? [];

		if (!$id || !$name || !$description || empty($types)) {
			return GeneralHelper::badRequest('Missing required fields');
		}

		if (!is_string($id) || !is_string($name) || !is_string($description) || !is_array($types)) {
			return GeneralHelper::badRequest('Invalid required field types');
		}

		foreach ($types as $type) {
			if (!is_string($type) || !ActivityType::tryFrom($type)) {
				return GeneralHelper::badRequest('Invalid activity type: ' . (string) $type);
			}
		}

		$fields = $body['fields'] ?? [];
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
}
