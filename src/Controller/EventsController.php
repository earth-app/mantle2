<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\EventsHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EventsController extends ControllerBase
{
	public static function create(ContainerInterface $container): EventsController|static
	{
		return new static();
	}

	// GET /v2/events
	public function events(Request $request): JsonResponse
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
			$query = $storage->getQuery()->accessCheck(false)->condition('type', 'event');

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

					// is in attendee array or is host
					$group->condition('field_event_attendees.target_id', $user->id(), 'CONTAINS');
					$group->condition('field_host_id', $user->id());
					$query->condition($group);
				}
			} else {
				// only public events for anonymous users
				$query->condition('field_visibility', Visibility::PUBLIC->value);
			}

			if ($search) {
				$group = $query
					->orConditionGroup()
					->condition('field_event_name', $search, 'CONTAINS');
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
					$data[] = EventsHelper::nodeToEvent($node)->jsonSerialize();
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

	// POST /v2/events
	public function createEvent(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$type = $body['type'] ?? null;
		$activityTypes = $body['activities'] ?? [];
		$latitude = $body['location']['latitude'] ?? null;
		$longitude = $body['location']['longitude'] ?? null;
		$date = $body['date'] ?? null;
		$endDate = $body['end_date'] ?? null;
		$visibility = $body['visibility'] ?? null;

		if (!$name || !is_string($name) || strlen($name) > 50) {
			return GeneralHelper::badRequest(
				'Missing or invalid name; Max length is 50 characters',
			);
		}

		if ($description && (!is_string($description) || strlen($description) > 3000)) {
			return GeneralHelper::badRequest('Invalid description; Max length is 3000 characters');
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

		if ($visibility0 === Visibility::PUBLIC) {
			$type = UsersHelper::getAccountType($user);
			if ($type === AccountType::FREE->value) {
				return GeneralHelper::paymentRequired(
					'Public events are not available on free accounts',
				);
			}
		}

		$activityTypes0 = [];
		if (is_array($activityTypes)) {
			foreach ($activityTypes as $activityType) {
				if (!is_string($activityType)) {
					return GeneralHelper::badRequest('Invalid activity type');
				}
				$activityType0 = ActivityType::tryFrom($activityType);
				if (!$activityType0) {
					return GeneralHelper::badRequest('Invalid activity type');
				}

				$activityTypes0[] = $activityType0;
			}
		} else {
			return GeneralHelper::badRequest('Invalid activity types');
		}

		if ($latitude !== null) {
			if (!$longitude) {
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
			if (!$latitude) {
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

		$event = new Event(
			$user->id(),
			$name,
			$description ?? '',
			$type0,
			$activityTypes0,
			(float) ($latitude ?? 0),
			(float) ($longitude ?? 0),
			$date,
			$endDate,
			$visibility0,
		);

		$node = EventsHelper::createEvent($event);

		$result = $event->jsonSerialize();
		$result['id'] = GeneralHelper::formatId($node->id());

		return new JsonResponse($result, Response::HTTP_CREATED);
	}

	// GET /v2/events/:eventId
	public function getEvent(int $eventId, Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$event = EventsHelper::loadEventNode($eventId);
		if (!$event) {
			return GeneralHelper::notFound('Event not found');
		}

		if (!EventsHelper::isVisible($event, $user)) {
			return GeneralHelper::notFound('Event not found');
		}

		$result = $event->jsonSerialize();
		$result['id'] = GeneralHelper::formatId($eventId);

		return new JsonResponse($result, Response::HTTP_OK);
	}

	// PATCH /v2/events/:eventId
	public function updateEvent(int $eventId, Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$event = EventsHelper::loadEventNode($eventId);
		if (!$event) {
			return GeneralHelper::notFound('Event not found');
		}

		if ($event->getHost()->id() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to edit this event');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$type = $body['type'] ?? null;
		$activityTypes = $body['activities'] ?? null;
		$latitude = $body['location']['latitude'] ?? null;
		$longitude = $body['location']['longitude'] ?? null;
		$date = $body['date'] ?? null;
		$endDate = $body['end_date'] ?? null;
		$visibility = $body['visibility'] ?? null;

		if ($name !== null) {
			if (!is_string($name) || strlen($name) > 50) {
				return GeneralHelper::badRequest('Invalid name; Max length is 50 characters');
			}
			$event->setName($name);
		}

		if ($description !== null) {
			if (!is_string($description) || strlen($description) > 3000) {
				return GeneralHelper::badRequest(
					'Invalid description; Max length is 3000 characters',
				);
			}
			$event->setDescription($description);
		}

		if ($type !== null) {
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
			$activityTypes0 = [];
			if (is_array($activityTypes)) {
				foreach ($activityTypes as $activityType) {
					if (!is_string($activityType)) {
						return GeneralHelper::badRequest("Invalid activity type: $activityType");
					}
					$activityType0 = ActivityType::tryFrom($activityType);
					if (!$activityType0) {
						return GeneralHelper::badRequest("Invalid activity type: $activityType");
					}

					$activityTypes0[] = $activityType0;
				}
				$event->setActivityTypes($activityTypes0);
			} else {
				return GeneralHelper::badRequest('Invalid activity types');
			}
		}

		if ($latitude !== null) {
			if (!$longitude) {
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
			if (!$latitude) {
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

			if ($visibility0 === Visibility::PUBLIC) {
				$type = UsersHelper::getAccountType($user);
				if ($type === AccountType::FREE->value) {
					return GeneralHelper::paymentRequired(
						'Public events are not available on free accounts',
					);
				}
			}

			$event->setVisibility($visibility0);
		}

		$node = Node::load($eventId);
		if (!$node) {
			return GeneralHelper::internalError('Failed to load event node');
		}

		EventsHelper::updateEvent($node, $event);
		$result = $event->jsonSerialize();
		$result['id'] = GeneralHelper::formatId($eventId);

		return new JsonResponse($result, Response::HTTP_OK);
	}

	// DELETE /v2/events/:eventId
	public function deleteEvent(int $eventId, Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$event = EventsHelper::loadEventNode($eventId);
		if (!$event) {
			return GeneralHelper::notFound('Event not found');
		}

		if ($event->getHost()->id() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to delete this event');
		}

		$node = Node::load($eventId);
		if (!$node) {
			return GeneralHelper::internalError('Failed to load event node');
		}

		$node->delete();

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// GET /v2/events/:eventId/attendees
	public function getEventAttendees(int $eventId, Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$event = EventsHelper::loadEventNode($eventId);
		if (!$event) {
			return GeneralHelper::notFound('Event not found');
		}

		if (!EventsHelper::isVisible($event, $user)) {
			return GeneralHelper::notFound('Event not found');
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		$attendees = array_filter($event->getAttendees(), function (UserInterface $attendee) use (
			$search,
		) {
			if ($search) {
				return stripos($attendee->getDisplayName(), $search) !== false;
			}
			return true;
		});
		$total = count($attendees);
		$attendees = array_slice($attendees, $page * $limit, $limit);
		$attendees = array_map(
			fn(UserInterface $attendee) => UsersHelper::serializeUser($attendee),
			$attendees,
		);

		return new JsonResponse(
			[
				'limit' => $limit,
				'page' => $page + 1,
				'items' => $attendees,
				'total' => $total,
			],
			Response::HTTP_OK,
		);
	}

	// POST /v2/events/:eventId/signup
	public function signUpForEvent(int $eventId, Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$event = EventsHelper::loadEventNode($eventId);
		if (!$event) {
			return GeneralHelper::notFound('Event not found');
		}

		if (!EventsHelper::isVisible($event, $user)) {
			return GeneralHelper::notFound('Event not found');
		}

		if ($event->isAttendee($user->id())) {
			return GeneralHelper::conflict('You are already signed up for this event');
		}

		$count = $event->getAttendeesCount();
		$max = UsersHelper::getMaxEventAttendees($user);
		if ($count >= $max) {
			return GeneralHelper::badRequest("Event has reached max attendee limit of $max");
		}

		$event->addAttendee($user->id());
		$node = Node::load($eventId);
		if (!$node) {
			return GeneralHelper::internalError('Failed to load event node');
		}

		EventsHelper::updateEvent($node, $event);

		return new JsonResponse(
			['user' => UsersHelper::serializeUser($user), 'event' => $event],
			Response::HTTP_OK,
		);
	}

	// POST /v2/events/:eventId/leave
	public function leaveEvent(int $eventId, Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$event = EventsHelper::loadEventNode($eventId);
		if (!$event) {
			return GeneralHelper::notFound('Event not found');
		}

		if (!EventsHelper::isVisible($event, $user)) {
			return GeneralHelper::notFound('Event not found');
		}

		if (!$event->isAttendee($user->id())) {
			return GeneralHelper::badRequest('You are not signed up for this event');
		}

		if ($event->getHost()->id() === $user->id()) {
			return GeneralHelper::badRequest('You are the host of this event and cannot leave it');
		}

		$event->removeAttendee($user->id());
		$node = Node::load($eventId);
		if (!$node) {
			return GeneralHelper::internalError('Failed to load event node');
		}

		EventsHelper::updateEvent($node, $event);

		return new JsonResponse(
			['user' => UsersHelper::serializeUser($user), 'event' => $event],
			Response::HTTP_OK,
		);
	}
}
