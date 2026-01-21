<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\node\Entity\Node;
use Drupal\user\UserInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;

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

	public static function getEventByNid(int $nid): ?Event
	{
		return self::loadEventNode($nid);
	}

	public static function nodeToEvent(Node $node): Event
	{
		$hostId = (int) $node->get('field_host_id')->value;
		$name = $node->get('field_event_name')->value;
		$description = $node->get('field_event_description')->value ?? '';

		$event_type_value = $node->get('field_event_type')->value;
		$event_type = EventType::cases()[$event_type_value] ?? EventType::HYBRID;

		$activity_types = array_map(
			fn(int $ordinal) => ActivityType::cases()[$ordinal] ?? ActivityType::OTHER,
			array_column($node->get('field_event_activity_types')->getValue(), 'value'),
		);

		$latitude = (float) ($node->get('field_event_location_latitude')->value ?? 0.0);
		$longitude = (float) ($node->get('field_event_location_longitude')->value ?? 0.0);

		$date = $node->get('field_event_date')->value;
		$end_date = $node->get('field_event_enddate')->value;

		$visibility_value = $node->get('field_visibility')->value ?? 1;
		$visibility = Visibility::cases()[$visibility_value] ?? Visibility::UNLISTED;

		$attendees = array_column($node->get('field_event_attendees')->getValue(), 'target_id');

		$fields_raw = $node->get('field_event_fields')->value;
		$fields = $fields_raw ? json_decode($fields_raw, true) : [];
		if (!is_array($fields)) {
			$fields = [];
		}

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
			$fields,
		);
	}

	public static function validateFields(array $fields): JsonResponse|array
	{
		if (!is_array($fields)) {
			return GeneralHelper::badRequest('Field "fields" must be an object');
		}

		foreach ($fields as $key => $value) {
			if (!is_string($key)) {
				return GeneralHelper::badRequest('Field keys must be strings');
			}

			if (strlen($key) > 50) {
				return GeneralHelper::badRequest('Field keys must be at most 50 characters');
			}

			if (!is_string($value)) {
				return GeneralHelper::badRequest('Field values must be strings');
			}

			if (strlen($value) > 10000) {
				return GeneralHelper::badRequest('Field values must be at most 10,000 characters');
			}
		}

		return $fields;
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
			'uid' => $author ? $author->id() : 1,
			'status' => 1,
		]);

		$node->set('field_host_id', $event->getHostId());
		$node->set('field_event_name', $event->getName());
		$node->set('field_event_description', $event->getDescription() ?? '');
		$node->set(
			'field_event_type',
			GeneralHelper::findOrdinal(EventType::cases(), $event->getType()),
		);

		$typeValues = array_map(
			fn(ActivityType $type) => GeneralHelper::findOrdinal(ActivityType::cases(), $type),
			$event->getActivityTypes(),
		);
		$node->set('field_event_activity_types', $typeValues);

		$node->set('field_event_location_latitude', $event->getLatitude() ?? 0.0);
		$node->set('field_event_location_longitude', $event->getLongitude() ?? 0.0);
		$node->set('field_event_date', $event->getDate());
		$node->set('field_event_enddate', $event->getEndDate());
		$node->set(
			'field_visibility',
			GeneralHelper::findOrdinal(Visibility::cases(), $event->getVisibility()),
		);
		$node->set('field_event_attendees', array_map('intval', $event->getAttendeeIds()));
		$node->set('field_event_fields', json_encode($event->getFields()));

		$node->save();

		// Notify the host that their event was created
		if ($author) {
			UsersHelper::addNotification(
				$author,
				Drupal::translation()->translate('Event Created'),
				Drupal::translation()->translate(
					"Your event \"{$event->getName()}\" has been successfully created.",
				),
				null,
				'info',
				'system',
			);
		}

		return $node;
	}

	public static function updateEvent(Node $node, Event $event): Node
	{
		if (!$node) {
			throw new InvalidArgumentException('Node is null');
		}

		if ($node->getType() !== 'event') {
			throw new InvalidArgumentException('Node is not an event');
		}

		$node->setTitle($event->getName());
		$node->set('field_host_id', $event->getHostId());
		$node->set('field_event_name', $event->getName());
		$node->set('field_event_description', $event->getDescription() ?? '');
		$node->set(
			'field_event_type',
			GeneralHelper::findOrdinal(EventType::cases(), $event->getType()),
		);

		$typeValues = array_map(
			fn(ActivityType $type) => GeneralHelper::findOrdinal(ActivityType::cases(), $type),
			$event->getActivityTypes(),
		);
		$node->set('field_event_activity_types', $typeValues);

		$node->set('field_event_location_latitude', $event->getLatitude() ?? 0.0);
		$node->set('field_event_location_longitude', $event->getLongitude() ?? 0.0);
		$node->set('field_event_date', $event->getDate());
		$node->set('field_event_enddate', $event->getEndDate());
		$node->set(
			'field_visibility',
			GeneralHelper::findOrdinal(Visibility::cases(), $event->getVisibility()),
		);
		$node->set('field_event_attendees', array_map('intval', $event->getAttendeeIds()));
		$node->set('field_event_fields', json_encode($event->getFields()));

		$node->save();

		return $node;
	}

	public static function deleteEvent(Node $node): void
	{
		if ($node->getType() !== 'event') {
			throw new InvalidArgumentException('Node is not of type event');
		}

		$node->delete();
	}

	public static function serializeEvent(Event $event, Node $node, ?UserInterface $user): array
	{
		$result = $event->jsonSerialize();
		$result['id'] = GeneralHelper::formatId($node->id());
		$result['host'] = UsersHelper::serializeUser($event->getHost(), $user);
		$result['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
		$result['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());
		$result['is_attending'] = $user ? in_array($user->id(), $event->getAttendeeIds()) : false;
		$result['can_edit'] = $event->getHostId() === $user?->id() || UsersHelper::isAdmin($user);

		return $result;
	}

	public static function getRandomEvent(): ?Event
	{
		$query = Drupal::entityQuery('node')
			->condition('type', 'event')
			->condition('status', 1)
			->accessCheck(false);

		$nids = $query->execute();

		if (empty($nids)) {
			return null;
		}

		$randomNid = $nids[array_rand($nids)];
		$node = Node::load($randomNid);

		return $node ? self::nodeToEvent($node) : null;
	}

	public static function getRandomEvents(int $count = 5): array
	{
		$query = Drupal::entityQuery('node')
			->condition('type', 'event')
			->condition('status', 1)
			->accessCheck(false)
			->range(0, $count);

		$nids = $query->execute();

		if (empty($nids)) {
			return [];
		}

		return array_map(fn($nid) => self::getEventByNid($nid), $nids);
	}
}
