<?php

namespace Drupal\mantle2\Service;

use Drupal\mantle2\Custom\Activity;
use Drupal\node\Entity\Node;

class ActivityHelper
{
	public static function getActivity(string $id): ?Activity
	{
		return self::nodeToActivity(self::getNodeByActivityId($id));
	}

	public static function getNodeByActivityId(string $id): ?Node
	{
		$query = \Drupal::entityQuery('node')
			->condition('type', 'activity')
			->condition('field_activity_id', $id)
			->accessCheck(false)
			->range(0, 1);

		$nids = $query->execute();

		if (empty($nids)) {
			return null;
		}

		$nid = reset($nids);
		return Node::load($nid);
	}

	public static function nodeToActivity(Node $node): Activity
	{
		$activity_id = $node->get('field_activity_id')->value;
		$name = $node->get('field_activity_name')->value;
		$description = $node->get('field_activity_description')->value;

		$activity_types = [];
		foreach ($node->get('field_activity_types') as $item) {
			$activity_types[] = $item->value;
		}

		$activity_aliases_raw = $node->get('field_activity_aliases')->value;
		$activity_aliases = $activity_aliases_raw ? json_decode($activity_aliases_raw, true) : [];

		$activity_fields_raw = $node->get('field_activity_fields')->value;
		$activity_fields = $activity_fields_raw ? json_decode($activity_fields_raw, true) : [];

		return new Activity(
			$activity_id,
			$name,
			$activity_types,
			$description,
			$activity_aliases,
			$activity_fields,
		);
	}

	public static function getActivityByNid(int $nid): ?Activity
	{
		$node = Node::load($nid);

		if (!$node || $node->getType() !== 'activity') {
			return null;
		}

		return self::nodeToActivity($node);
	}

	public static function createActivity(Activity $activity): Node
	{
		$node = Node::create([
			'type' => 'activity',
			'title' => $activity->getName(),
		]);

		$node->set('field_activity_id', $activity->getId());
		$node->set('field_activity_name', $activity->getName());
		$node->set('field_activity_description', $activity->getDescription());

		$typeValues = array_map(fn($type) => $type->value, $activity->getTypes());
		$node->set('field_activity_types', $typeValues);

		$node->set('field_activity_aliases', json_encode($activity->getAliases()));
		$node->set('field_activity_fields', json_encode($activity->getAllFields()));

		$node->save();

		return $node;
	}
}
