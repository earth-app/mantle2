<?php

namespace Drupal\mantle2\Service;

use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\node\Entity\Node;
use Drupal\user\UserInterface;
use InvalidArgumentException;

class EventsHelper
{
	public static function loadEventNode(int $nid): ?Event
	{
		$node = Node::load($nid);
		if (!$node || $node->getType() !== 'event') {
			return null;
		}

		return self::nodeToEvent($node);
	}

	public static function nodeToEvent(Node $node): Event
	{
		$hostId = (int) $node->get('field_host_id')->value;
		$name = $node->get('field_event_name')->value;
		$description = $node->get('field_event_description')->value;
		$event_type = EventType::cases()[$node->get('field_event_type')->value];

		$activity_types = array_map(
			fn(int $ordinal) => ActivityType::cases()[$ordinal],
			array_column($node->get('field_event_activity_types')->getValue(), 'value'),
		);

		$latitude = (float) $node->get('field_event_location_latitude')->value;
		$longitude = (float) $node->get('field_event_location_longitude')->value;

		$date = $node->get('field_event_date')->value;
		$end_date = $node->get('field_event_end_date')->value;
		$visibility = Visibility::cases()[$node->get('field_visibility')->value ?? 1];
		$attendees = array_column($node->get('field_event_attendees')->getValue(), 'target_id');

		return new Event(
			$hostId,
			$name,
			$description,
			$event_type,
			$activity_types,
			$latitude,
			$longitude,
			$date,
			$end_date,
			$visibility,
			$attendees,
		);
	}

	public static function isVisible(Event $event, ?UserInterface $user): bool
	{
		$visibility = $event->getVisibility();
		if ($visibility === Visibility::PUBLIC) {
			return true;
		}

		// UNLISTED requires login
		if (!$user) {
			return false;
		}

		// PRIVATE requires:
		// - is admin
		// - is host
		// - is attendee
		// - mutual friend with host
		if (
			UsersHelper::isAdmin($user) ||
			$user->id() === $event->getHostId() ||
			in_array($user->id(), $event->getAttendeeIds()) ||
			UsersHelper::isMutualFriend($event->getHost(), $user)
		) {
			return true;
		}

		if ($visibility === Visibility::PRIVATE) {
			return false;
		}

		return true;
	}

	public static function createEvent(Event $event, ?UserInterface $author = null): Node
	{
		$node = Node::create([
			'type' => 'event',
			'title' => $event->getName(),
			'author' => $author ? $author->id() : 1,
		]);
		$node->set('field_host_id', $event->getHostId());
		$node->set('field_event_name', $event->getName());
		$node->set('field_event_description', $event->getDescription());
		$node->set('field_event_type', $event->getType()->value);

		$node->set(
			'field_event_activity_types',
			array_map(
				fn(ActivityType $type) => GeneralHelper::findOrdinal(ActivityType::cases(), $type),
				$event->getActivityTypes(),
			),
		);

		$node->set('field_event_location_latitude', $event->getLatitude());
		$node->set('field_event_location_longitude', $event->getLongitude());
		$node->set('field_event_date', $event->getDate());
		$node->set('field_event_end_date', $event->getEndDate());
		$node->set(
			'field_visibility',
			GeneralHelper::findOrdinal(Visibility::cases(), $event->getVisibility()),
		);
		$node->save();

		return $node;
	}

	public static function updateEvent(Node $node, Event $event): void
	{
		if (!$node) {
			throw new InvalidArgumentException('Node is null');
		}

		if ($node->getType() !== 'event') {
			throw new InvalidArgumentException('Node is not an event');
		}

		$node->set('title', $event->getName());
		$node->set('field_event_name', $event->getName());
		$node->set('field_event_description', $event->getDescription());
		$node->set('field_event_type', $event->getType()->value);

		$node->set(
			'field_event_activity_types',
			array_map(
				fn(ActivityType $type) => GeneralHelper::findOrdinal(ActivityType::cases(), $type),
				$event->getActivityTypes(),
			),
		);

		$node->set('field_event_location_latitude', $event->getLatitude());
		$node->set('field_event_location_longitude', $event->getLongitude());
		$node->set('field_event_date', $event->getDate());
		$node->set('field_event_end_date', $event->getEndDate());
		$node->set(
			'field_visibility',
			GeneralHelper::findOrdinal(Visibility::cases(), $event->getVisibility()),
		);
		$node->save();
	}
}
