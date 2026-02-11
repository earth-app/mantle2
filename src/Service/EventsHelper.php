<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
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
		$eventTypeCases = EventType::cases();
		$event_type =
			isset($eventTypeCases[$event_type_value]) && is_int($event_type_value)
				? $eventTypeCases[$event_type_value]
				: EventType::HYBRID;

		// Load activities from JSON field
		$activities_raw = $node->get('field_event_activity_types')->value;
		$activities_data = $activities_raw ? json_decode($activities_raw, true) : [];
		$activities = [];

		if (is_array($activities_data)) {
			foreach ($activities_data as $activityData) {
				if (is_array($activityData)) {
					$type = $activityData['type'] ?? null;
					if ($type === 'activity') {
						try {
							$activities[] = Activity::fromArray($activityData);
						} catch (\Exception $e) {
							// Skip invalid activities
						}
					} elseif ($type === 'activity_type') {
						$value = $activityData['value'] ?? null;
						if ($value) {
							$activityType = ActivityType::tryFrom($value);
							if ($activityType) {
								$activities[] = $activityType;
							}
						}
					}
				}
			}
		}

		$latitude = (float) ($node->get('field_event_location_latitude')->value ?? 0.0);
		$longitude = (float) ($node->get('field_event_location_longitude')->value ?? 0.0);

		// Convert datetime string to Unix timestamp in MILLISECONDS
		$dateValue = $node->get('field_event_date')->value;
		if (!$dateValue) {
			throw new \Exception('Event date is required');
		}
		$dateTimestamp = strtotime($dateValue);
		if ($dateTimestamp === false) {
			throw new \Exception('Invalid event date format');
		}
		$date = $dateTimestamp * 1000;

		$endDateValue = $node->get('field_event_enddate')->value;
		$end_date = null;
		if ($endDateValue) {
			$endTimestamp = strtotime($endDateValue);
			$end_date = $endTimestamp !== false ? $endTimestamp * 1000 : null;
		}

		$visibility_value = $node->get('field_visibility')->value ?? 1;
		$visibilityCases = Visibility::cases();
		$visibility =
			isset($visibilityCases[$visibility_value]) && is_int($visibility_value)
				? $visibilityCases[$visibility_value]
				: Visibility::UNLISTED;

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
			$activities,
			$latitude,
			$longitude,
			$date,
			$end_date,
			$visibility,
			$attendees,
			$fields,
			GeneralHelper::formatId($node->id()),
		);
	}

	private static $allowedFields = [
		'moho_id',
		'link',
		'info',
		'max_in_person',
		'max_online',
		'address',
		'icon',
		'cancelled',
	];

	public static function validateFields(
		array $fields,
		?UserInterface $user = null,
	): JsonResponse|array {
		if (!is_array($fields)) {
			return GeneralHelper::badRequest('Field "fields" must be an object');
		}

		foreach ($fields as $key => $value) {
			if (!is_string($key)) {
				return GeneralHelper::badRequest('Field keys must be strings');
			}

			if (!in_array($key, self::$allowedFields, true)) {
				return GeneralHelper::badRequest("Field '$key' is not allowed");
			}

			if ($key === 'moho_id') {
				if (!UsersHelper::isAdmin($user)) {
					return GeneralHelper::forbidden('You do not have permission to set moho_id');
				}

				if (!is_string($value)) {
					return GeneralHelper::badRequest('Field moho_id must be a string');
				}
			}

			if ($key === 'link') {
				if (!is_string($value)) {
					return GeneralHelper::badRequest('Field link must be a string');
				}
				if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
					return GeneralHelper::badRequest('Field link must be a valid URL');
				}
			}

			if ($key === 'info') {
				if (!is_string($value)) {
					return GeneralHelper::badRequest('Field info must be a string');
				}
				if (strlen($value) > 1000) {
					return GeneralHelper::badRequest('Field info must be at most 1000 characters');
				}
			}

			if ($key === 'max_in_person') {
				if (!is_numeric($value) && !is_int($value)) {
					return GeneralHelper::badRequest('Field max_in_person must be a number');
				}
				$maxValue = is_string($value) ? (int) $value : $value;
				if ($maxValue <= 0) {
					return GeneralHelper::badRequest(
						'Field max_in_person must be a positive number',
					);
				}
				if ($user) {
					$capacity = UsersHelper::getMaxEventAttendees($user);
					if ($maxValue > $capacity) {
						return GeneralHelper::badRequest(
							"Field max_in_person cannot exceed your attendance capacity of $capacity",
						);
					}
				}
			}

			if ($key === 'max_online') {
				if (!is_numeric($value) && !is_int($value)) {
					return GeneralHelper::badRequest('Field max_online must be a number');
				}
				$maxValue = is_string($value) ? (int) $value : $value;
				if ($maxValue <= 0) {
					return GeneralHelper::badRequest('Field max_online must be a positive number');
				}
				if ($user) {
					$capacity = UsersHelper::getMaxEventAttendees($user);
					if ($maxValue > $capacity) {
						return GeneralHelper::badRequest(
							"Field max_online cannot exceed your attendance capacity of $capacity",
						);
					}
				}
			}

			if ($key === 'icon') {
				if (!is_string($value)) {
					return GeneralHelper::badRequest('Field icon must be a string');
				}
				if (strlen($value) > 128) {
					return GeneralHelper::badRequest('Field icon must be at most 128 characters');
				}
			}

			if ($key === 'address') {
				if (!is_string($value)) {
					return GeneralHelper::badRequest('Field address must be a string');
				}
				if (strlen($value) > 255) {
					return GeneralHelper::badRequest(
						'Field address must be at most 255 characters',
					);
				}
			}

			if ($key === 'cancelled') {
				if (!is_bool($value)) {
					return GeneralHelper::badRequest('Field cancelled must be a boolean');
				}
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

	public static function validateEventData(
		array $body,
		?UserInterface $user = null,
	): JsonResponse|Event {
		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$type = $body['type'] ?? null;
		$activityTypes = $body['activities'] ?? [];
		$date = $body['date'] ?? null;
		$endDate = $body['end_date'] ?? null;
		$visibility = $body['visibility'] ?? null;

		$latitude = null;
		$longitude = null;
		if (isset($body['location'])) {
			$latitude = $body['location']['latitude'] ?? null;
			$longitude = $body['location']['longitude'] ?? null;
		}

		if (!$name || !is_string($name) || strlen($name) > 50) {
			return GeneralHelper::badRequest(
				'Missing or invalid name; Max length is 50 characters',
			);
		}

		if ($description && (!is_string($description) || strlen($description) > 3000)) {
			return GeneralHelper::badRequest('Invalid description; Max length is 3000 characters');
		}

		$censor = $body['censor'] ?? false;
		if (!is_bool($censor)) {
			return GeneralHelper::badRequest('Field censor must be a boolean');
		}

		// Check event name for inappropriate content
		$flagResult = GeneralHelper::isFlagged($name);
		if ($flagResult['flagged']) {
			if ($censor) {
				$name = GeneralHelper::censorText($name);
			} else {
				return GeneralHelper::badRequest(
					'Event name contains inappropriate content: ' . $flagResult['matched_word'],
				);
			}
		}

		// Check event description for inappropriate content (if provided)
		if ($description) {
			$flagResult = GeneralHelper::isFlagged($description);
			if ($flagResult['flagged']) {
				if ($censor) {
					$description = GeneralHelper::censorText($description);
				} else {
					return GeneralHelper::badRequest(
						'Event description contains inappropriate content: ' .
							$flagResult['matched_word'],
					);
				}
			}
		}

		if (!$type || !is_string($type)) {
			return GeneralHelper::badRequest('Missing or invalid type');
		}

		$type0 = EventType::tryFrom($type);
		if (!$type0) {
			return GeneralHelper::badRequest('Invalid event type');
		}

		if (!$date || !is_integer($date)) {
			return GeneralHelper::badRequest('Missing or invalid date');
		}

		if (!$visibility || !is_string($visibility)) {
			return GeneralHelper::badRequest('Missing or invalid visibility');
		}

		$visibility0 = Visibility::tryFrom($visibility);
		if (!$visibility0) {
			return GeneralHelper::badRequest('Invalid visibility');
		}

		if ($visibility0 === Visibility::PUBLIC && $user) {
			if (!UsersHelper::isPro($user)) {
				return GeneralHelper::paymentRequired(
					'Public events are not available on free accounts',
				);
			}
		}

		$activities0 = [];
		if (is_array($activityTypes)) {
			if (count($activityTypes) > Event::MAX_ACTIVITIES) {
				return GeneralHelper::badRequest(
					'Too many activities, max is ' . Event::MAX_ACTIVITIES,
				);
			}

			foreach ($activityTypes as $activityValue) {
				if (!is_string($activityValue)) {
					return GeneralHelper::badRequest('Each activity must be a string');
				}

				$activityType = ActivityType::tryFrom(strtoupper($activityValue));
				if ($activityType) {
					$activities0[] = $activityType;
				} else {
					$activity = ActivityHelper::getActivity(strtolower($activityValue));
					if (!$activity) {
						return GeneralHelper::badRequest(
							'Invalid activity: "' .
								$activityValue .
								'" is not a valid ActivityType or Activity ID',
						);
					}
					$activities0[] = $activity;
				}
			}
		} else {
			return GeneralHelper::badRequest('Invalid activity types');
		}

		if ($latitude !== null) {
			if ($longitude === null) {
				return GeneralHelper::badRequest('Longitude is required when latitude is provided');
			}

			if (!is_float($latitude) && !is_int($latitude)) {
				return GeneralHelper::badRequest('Invalid latitude');
			}
			if ($latitude < -90 || $latitude > 90) {
				return GeneralHelper::badRequest('Invalid latitude');
			}
		}

		if ($longitude !== null) {
			if ($latitude === null) {
				return GeneralHelper::badRequest('Latitude is required when longitude is provided');
			}

			if (!is_float($longitude) && !is_int($longitude)) {
				return GeneralHelper::badRequest('Invalid longitude');
			}
			if ($longitude < -180 || $longitude > 180) {
				return GeneralHelper::badRequest('Invalid longitude');
			}
		}

		if ($endDate !== null) {
			if (!is_integer($endDate)) {
				return GeneralHelper::badRequest('Invalid end date');
			}

			if ($endDate < $date) {
				return GeneralHelper::badRequest('End date must be after or equal to start date');
			}
		}

		$fields = $body['fields'] ?? ['link' => ''];
		$validatedFields = self::validateFields($fields, $user);
		if ($validatedFields instanceof JsonResponse) {
			return $validatedFields;
		}

		return new Event(
			$user?->id() ?? 1,
			$name,
			$description ?? '',
			$type0,
			$activities0,
			(float) ($latitude ?? 0),
			(float) ($longitude ?? 0),
			$date,
			$endDate,
			$visibility0,
			[],
			$validatedFields,
		);
	}

	public static function applyEventUpdates(
		Event $event,
		array $body,
		?UserInterface $user = null,
	): JsonResponse|bool {
		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$type = $body['type'] ?? null;
		$activityTypes = $body['activities'] ?? null;
		$date = $body['date'] ?? null;
		$endDate = $body['end_date'] ?? null;
		$visibility = $body['visibility'] ?? null;
		$fields = $body['fields'] ?? null;

		$latitude = null;
		$longitude = null;
		if (isset($body['location'])) {
			$latitude = $body['location']['latitude'] ?? null;
			$longitude = $body['location']['longitude'] ?? null;
		}

		if ($name !== null) {
			if (!is_string($name) || strlen($name) > 50) {
				return GeneralHelper::badRequest('Invalid name; Max length is 50 characters');
			}

			$censor = $body['censor'] ?? false;
			if (!is_bool($censor)) {
				return GeneralHelper::badRequest('Field censor must be a boolean');
			}

			// Check event name for inappropriate content
			$flagResult = GeneralHelper::isFlagged($name);
			if ($flagResult['flagged']) {
				if ($censor) {
					$name = GeneralHelper::censorText($name);
				} else {
					return GeneralHelper::badRequest(
						'Event name contains inappropriate content: ' . $flagResult['matched_word'],
					);
				}
			}

			$event->setName($name);
		}

		if ($description !== null) {
			if (!is_string($description) || strlen($description) > 3000) {
				return GeneralHelper::badRequest(
					'Invalid description; Max length is 3000 characters',
				);
			}

			$censor = $body['censor'] ?? false;
			if (!is_bool($censor)) {
				return GeneralHelper::badRequest('Field censor must be a boolean');
			}

			// Check event description for inappropriate content
			$flagResult = GeneralHelper::isFlagged($description);
			if ($flagResult['flagged']) {
				if ($censor) {
					$description = GeneralHelper::censorText($description);
				} else {
					return GeneralHelper::badRequest(
						'Event description contains inappropriate content: ' .
							$flagResult['matched_word'],
					);
				}
			}

			if (!is_string($type)) {
				return GeneralHelper::badRequest('Invalid type');
			}

			$type0 = EventType::tryFrom($type);
			if (!$type0) {
				return GeneralHelper::badRequest('Invalid event type');
			}
			$event->setType($type0);
		}

		if ($activityTypes !== null) {
			$activities0 = [];
			if (is_array($activityTypes)) {
				if (count($activityTypes) > Event::MAX_ACTIVITIES) {
					return GeneralHelper::badRequest(
						'Too many activities, max is ' . Event::MAX_ACTIVITIES,
					);
				}

				foreach ($activityTypes as $activityValue) {
					if (!is_string($activityValue)) {
						return GeneralHelper::badRequest('Each activity must be a string');
					}

					$activityType = ActivityType::tryFrom(strtoupper($activityValue));
					if ($activityType) {
						$activities0[] = $activityType;
					} else {
						$activity = ActivityHelper::getActivity(strtolower($activityValue));
						if (!$activity) {
							return GeneralHelper::badRequest(
								'Invalid activity: "' .
									$activityValue .
									'" is not a valid ActivityType or Activity ID',
							);
						}
						$activities0[] = $activity;
					}
				}
				$event->setActivities($activities0);
			} else {
				return GeneralHelper::badRequest('Invalid activity types');
			}
		}

		if ($latitude !== null) {
			if ($longitude === null) {
				return GeneralHelper::badRequest('Longitude is required when latitude is provided');
			}

			if (!is_float($latitude) && !is_int($latitude)) {
				return GeneralHelper::badRequest("Invalid latitude: $latitude");
			}
			if ($latitude < -90 || $latitude > 90) {
				return GeneralHelper::badRequest("Invalid latitude: $latitude");
			}
			$event->setLatitude((float) $latitude);
		}

		if ($longitude !== null) {
			if ($latitude === null) {
				return GeneralHelper::badRequest('Latitude is required when longitude is provided');
			}

			if (!is_float($longitude) && !is_int($longitude)) {
				return GeneralHelper::badRequest("Invalid longitude: $longitude");
			}
			if ($longitude < -180 || $longitude > 180) {
				return GeneralHelper::badRequest("Invalid longitude: $longitude");
			}

			$event->setLongitude((float) $longitude);
		}

		if ($date !== null) {
			if (!is_integer($date)) {
				return GeneralHelper::badRequest(
					"Invalid date: $date; Must be in the form of a number",
				);
			}
			$event->setDate($date);
		}

		if ($endDate !== null) {
			if (!is_integer($endDate)) {
				return GeneralHelper::badRequest(
					"Invalid end date: $endDate; Must be in the form of a number",
				);
			}

			if ($endDate < $event->getRawDate()) {
				return GeneralHelper::badRequest('End date must be after or equal to start date');
			}

			$event->setEndDate($endDate);
		}

		if ($visibility !== null) {
			if (!is_string($visibility)) {
				return GeneralHelper::badRequest("Invalid visibility: $visibility");
			}

			$visibility0 = Visibility::tryFrom($visibility);
			if (!$visibility0) {
				return GeneralHelper::badRequest("Invalid visibility: $visibility");
			}

			if ($visibility0 === Visibility::PUBLIC && $user) {
				if (!UsersHelper::isPro($user)) {
					return GeneralHelper::paymentRequired(
						'Public events are not available on free accounts',
					);
				}
			}

			$event->setVisibility($visibility0);
		}

		if ($fields !== null) {
			$validatedFields = self::validateFields($fields, $user);
			if ($validatedFields instanceof JsonResponse) {
				return $validatedFields;
			}

			$event->setFields($validatedFields);
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

		self::updateEvent($node, $event);

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

		// Serialize activities to JSON
		$activitiesData = array_map(function ($activity) {
			if ($activity instanceof Activity) {
				return array_merge(['type' => 'activity'], $activity->jsonSerialize());
			} elseif ($activity instanceof ActivityType) {
				return [
					'type' => 'activity_type',
					'value' => $activity->value,
				];
			}
			return null;
		}, $event->getActivities());
		$node->set('field_event_activity_types', json_encode(array_filter($activitiesData)));

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
		$result['id'] = GeneralHelper::formatId($event->getId());
		$result['host'] = UsersHelper::serializeUser($event->getHost(), $user);
		$result['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
		$result['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());
		$result['is_attending'] = $user ? in_array($user->id(), $event->getAttendeeIds()) : false;
		$result['can_edit'] = $event->getHostId() === $user?->id() || UsersHelper::isAdmin($user);

		// filter out internal fields (those starting with _)
		if (isset($result['fields']) && is_array($result['fields'])) {
			$result['fields'] = array_filter(
				$result['fields'],
				fn($key) => !str_starts_with($key, '_'),
				ARRAY_FILTER_USE_KEY,
			);
		}

		// timing info
		$now = time() * 1000; // Convert to milliseconds for comparison
		$timing = [];
		$timing['has_passed'] = $event->getRawEndDate()
			? $now > $event->getRawEndDate()
			: $now > $event->getRawDate();
		$timing['is_ongoing'] = $event->getRawEndDate()
			? $now >= $event->getRawDate() && $now <= $event->getRawEndDate()
			: false;
		$timing['starts_in'] = (int) (($event->getRawDate() - $now) / 1000); // Convert to seconds
		$timing['ends_in'] = $event->getRawEndDate()
			? (int) (($event->getRawEndDate() - $now) / 1000)
			: null;
		$result['timing'] = $timing;

		return $result;
	}

	public static function getRandomEvent(bool $upcoming = false): ?Event
	{
		$query = Drupal::entityQuery('node')
			->condition('type', 'event')
			->condition('status', 1)
			->accessCheck(false);

		if ($upcoming) {
			// Drupal datetime fields are in ISO format, so we need current date in ISO format
			$now = date('Y-m-d\TH:i:s', time());
			$query->condition('field_event_date', $now, '>=');
		}

		$nids = $query->execute();

		if (empty($nids)) {
			return null;
		}

		$randomNid = $nids[array_rand($nids)];
		$node = Node::load($randomNid);

		return $node ? self::nodeToEvent($node) : null;
	}

	public static function getRandomEvents(int $count = 5, bool $upcoming = false): array
	{
		$query = Drupal::entityQuery('node')
			->condition('type', 'event')
			->condition('status', 1)
			->accessCheck(false)
			->range(0, $count);

		if ($upcoming) {
			// Drupal datetime fields are in ISO format, so we need current date in ISO format
			$now = date('Y-m-d\TH:i:s', time());
			$query->condition('field_event_date', $now, '>=');
		}

		$nids = $query->execute();

		if (empty($nids)) {
			return [];
		}

		return array_map(fn($nid) => self::getEventByNid($nid), $nids);
	}

	/**
	 * Check for events starting or ending soon and notify attendees.
	 * Called by cron (runs hourly).
	 */
	public static function checkEventNotifications(): void
	{
		$query = Drupal::entityQuery('node')
			->condition('type', 'event')
			->condition('status', 1)
			->accessCheck(false);

		$nids = $query->execute();

		if (empty($nids)) {
			return;
		}

		// Event dates are stored in milliseconds, convert current time to milliseconds
		$now = time() * 1000;
		$oneHourFromNow = $now + 3600 * 1000; // Next cron run in milliseconds

		foreach ($nids as $nid) {
			$node = Node::load($nid);
			if (!$node) {
				continue;
			}

			$event = self::nodeToEvent($node);
			$startTime = $event->getRawDate();
			$endTime = $event->getRawEndDate();

			if ($startTime > $now && $startTime <= $oneHourFromNow) {
				$minutesUntilStart = (int) ceil(($startTime - $now) / 60000); // milliseconds to minutes
				self::notifyEventStarting($event, $node, $minutesUntilStart);
			}

			if ($endTime && $endTime > $now && $endTime <= $oneHourFromNow) {
				$minutesUntilEnd = (int) ceil(($endTime - $now) / 60000); // milliseconds to minutes
				self::notifyEventEnding($event, $node, $minutesUntilEnd);
			}

			if ($endTime && $endTime <= $now) {
				self::notifyEventEnded($event, $node);
			}
		}
	}

	/**
	 * Notify attendees that an event is starting soon.
	 */
	private static function notifyEventStarting(Event $event, Node $node, int $minutes): void
	{
		$eventUrl = '/events/' . GeneralHelper::formatId($node->id());
		$attendeeIds = array_merge($event->getAttendeeIds(), [$event->getHostId()]);

		foreach ($attendeeIds as $userId) {
			$user = User::load($userId);
			if ($user) {
				UsersHelper::addNotification(
					$user,
					'Event Starting Soon',
					"The event \"{$event->getName()}\" starts in $minutes minutes!",
					$eventUrl,
					'info',
					'system',
				);
			}
		}

		Drupal::logger('mantle2')->notice(
			'[cron] Notified attendees that event "@event" (ID: @id) starts in @minutes minutes.',
			['@event' => $event->getName(), '@id' => $node->id(), '@minutes' => $minutes],
		);
	}

	/**
	 * Notify attendees that an event is ending soon.
	 */
	private static function notifyEventEnding(Event $event, Node $node, int $minutes): void
	{
		$eventUrl = '/events/' . GeneralHelper::formatId($node->id());
		$attendeeIds = array_merge($event->getAttendeeIds(), [$event->getHostId()]);

		foreach ($attendeeIds as $userId) {
			$user = User::load($userId);
			if ($user) {
				UsersHelper::addNotification(
					$user,
					'Event Ending Soon',
					"The event \"{$event->getName()}\" ends in $minutes minutes!",
					$eventUrl,
					'info',
					'system',
				);
			}
		}

		Drupal::logger('mantle2')->notice(
			'[cron] Notified attendees that event "@event" (ID: @id) ends in @minutes minutes.',
			['@event' => $event->getName(), '@id' => $node->id(), '@minutes' => $minutes],
		);
	}

	/**
	 * Notify attendees that an event has ended.
	 */
	private static function notifyEventEnded(Event $event, Node $node): void
	{
		$eventUrl = '/events/' . GeneralHelper::formatId($node->id());
		$attendeeIds = array_merge($event->getAttendeeIds(), [$event->getHostId()]);

		foreach ($attendeeIds as $userId) {
			$user = User::load($userId);
			if ($user) {
				UsersHelper::addNotification(
					$user,
					'Event Ended',
					"The event \"{$event->getName()}\" has ended.",
					$eventUrl,
					'info',
					'system',
				);

				// badges: events_attended, event_types_attended
				// These are tracked here (not in PostResponseSubscriber) because
				// event ending happens via cron, not during a request/response cycle
				UsersHelper::trackBadgeProgress(
					$user,
					'events_attended',
					GeneralHelper::formatId($event->getId()),
				);

				UsersHelper::trackBadgeProgress(
					$user,
					'event_types_attended',
					$event->getType()->value,
				);
			}
		}
	}

	public static function deleteThumbnail(Node $node): void
	{
		CloudHelper::sendRequest(
			'/v1/events/thumbnail/' . GeneralHelper::formatId($node->id()),
			'DELETE',
		);
	}

	public const EXPIRED_EVENTS_TTL = 30 * 24 * 3600; // 30 days after end date in seconds

	public static function checkExpiredEvents(): void
	{
		$query = Drupal::entityQuery('node')
			->accessCheck(false)
			->condition('type', 'event')
			->condition('status', 1);

		$nids = $query->execute();

		if (empty($nids)) {
			return;
		}

		$now = time() * 1000; // Convert to milliseconds
		$expirationThreshold = $now - self::EXPIRED_EVENTS_TTL * 1000; // convert TTL to milliseconds

		$nodes = Node::loadMultiple($nids);
		foreach ($nodes as $node) {
			$event = self::nodeToEvent($node);

			$relevantDate = $event->getRawEndDate() ?? $event->getRawDate();
			if ($relevantDate < $expirationThreshold) {
				$host = $event->getHost();
				if ($host instanceof User) {
					UsersHelper::addNotification(
						$host,
						Drupal::translation()->translate('Event Expired'),
						Drupal::translation()->translate(
							"Your event \"{$event->getName()}\" has expired and been deleted.",
						),
					);
				}

				$node->delete();
				self::deleteThumbnail($node);
			}
		}
	}
}
