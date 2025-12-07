<?php

namespace Drupal\mantle2\Service;

use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\node\Entity\Node;
use Drupal\user\UserInterface;

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

	public static function getNodeByActivityAlias(string $alias): ?Node
	{
		$query = \Drupal::entityQuery('node')
			->condition('type', 'activity')
			->condition('field_activity_aliases', $alias, 'CONTAINS')
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

		$activity_types = array_map(
			fn(int $ordinal) => ActivityType::cases()[$ordinal]->name,
			array_column($node->get('field_activity_types')->getValue(), 'value'),
		);

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

	public static function createActivity(Activity $activity, ?UserInterface $author = null): Node
	{
		$node = Node::create([
			'type' => 'activity',
			'title' => $activity->getName(),
			'uid' => $author ? $author->id() : 1,
		]);

		$node->set('field_activity_id', $activity->getId());
		$node->set('field_activity_name', $activity->getName());
		$node->set('field_activity_description', $activity->getDescription());

		$typeValues = array_map(
			fn(string $type) => GeneralHelper::findOrdinal(
				ActivityType::cases(),
				ActivityType::from($type),
			),
			$activity->getTypes(),
		);
		$node->set('field_activity_types', $typeValues);

		$node->set('field_activity_aliases', json_encode($activity->getAliases()));
		$node->set('field_activity_fields', json_encode($activity->getAllFields()));

		$node->save();

		return $node;
	}

	public static function updateActivity(Node $node, Activity $activity): Node
	{
		if ($node->getType() !== 'activity') {
			throw new \InvalidArgumentException('Node is not of type activity');
		}

		$node->setTitle($activity->getName());
		$node->set('field_activity_id', $activity->getId());
		$node->set('field_activity_name', $activity->getName());
		$node->set('field_activity_description', $activity->getDescription());

		$typeValues = array_map(
			fn(string $type) => GeneralHelper::findOrdinal(
				ActivityType::cases(),
				ActivityType::from($type),
			),
			$activity->getTypes(),
		);
		$node->set('field_activity_types', $typeValues);

		$node->set('field_activity_aliases', json_encode($activity->getAliases()));
		$node->set('field_activity_fields', json_encode($activity->getAllFields()));

		$node->save();

		return $node;
	}

	public static function deleteActivity(Node $node): void
	{
		if ($node->getType() !== 'activity') {
			throw new \InvalidArgumentException('Node is not of type activity');
		}

		$node->delete();
	}

	public static function getRandomActivity(): ?Activity
	{
		$query = \Drupal::entityQuery('node')->condition('type', 'activity')->accessCheck(false);

		$nids = $query->execute();

		if (empty($nids)) {
			return null;
		}

		$randomNid = $nids[array_rand($nids)];
		$node = Node::load($randomNid);

		return $node ? self::nodeToActivity($node) : null;
	}

	public static function getRandomActivities(int $count = 5): array
	{
		$query = \Drupal::entityQuery('node')
			->condition('type', 'activity')
			->accessCheck(false)
			->range(0, $count);

		$nids = $query->execute();

		if (empty($nids)) {
			return [];
		}

		return array_map(fn($nid) => self::getActivityByNid($nid), $nids);
	}
}
