<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
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
use UnexpectedValueException;

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

		$requester = UsersHelper::getOwnerOfRequest($request);
		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];
		$sort = $pagination['sort'];

		$filter_after = $request->query->get('filter_after');
		$filter_before = $request->query->get('filter_before');
		$filter_ends_after = $request->query->get('filter_ends_after');
		$filter_ends_before = $request->query->get('filter_ends_before');

		try {
			// Handle random sorting separately using database query
			if ($sort === 'rand') {
				$connection = Drupal::database();
				$query = $connection
					->select('node_field_data', 'n')
					->fields('n', ['nid'])
					->condition('n.status', 1)
					->condition('n.type', 'event');

				$fv = $query->leftJoin('node__field_visibility', 'fv', 'fv.entity_id = n.nid');
				$query->condition("$fv.delta", 0);

				// Check visibility
				$user = UsersHelper::getOwnerOfRequest($request);
				if ($user) {
					if (!UsersHelper::isAdmin($user)) {
						// Non-private events for logged-in users OR events where user is host/attendee
						$fh = $query->leftJoin('node__field_host_id', 'fh', 'fh.entity_id = n.nid');
						$query->condition("$fh.delta", 0);

						$fa = $query->leftJoin(
							'node__field_event_attendees',
							'fa',
							'fa.entity_id = n.nid',
						);

						$group = $query
							->orConditionGroup()
							->condition(
								"$fv.field_visibility_value",
								[
									GeneralHelper::findOrdinal(
										Visibility::cases(),
										Visibility::PUBLIC,
									),
									GeneralHelper::findOrdinal(
										Visibility::cases(),
										Visibility::UNLISTED,
									),
								],
								'IN',
							)
							->condition("$fa.field_event_attendees_target_id", $user->id())
							->condition("$fh.field_host_id_value", $user->id());
						$query->condition($group);
					}
				} else {
					// Only public events for anonymous users
					$query->condition(
						"$fv.field_visibility_value",
						GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
					);
				}

				if ($search) {
					$escapedSearch = Drupal::database()->escapeLike($search);
					$fn = $query->leftJoin('node__field_event_name', 'fn', 'fn.entity_id = n.nid');
					$fd = $query->leftJoin(
						'node__field_event_description',
						'fd',
						'fd.entity_id = n.nid',
					);
					$ff = $query->leftJoin(
						'node__field_event_fields',
						'ff',
						'ff.entity_id = n.nid',
					);

					$searchGroup = $query
						->orConditionGroup()
						->condition("$fn.field_event_name_value", "%$escapedSearch%", 'LIKE')
						->condition("$fd.field_event_description_value", "%$escapedSearch%", 'LIKE')
						->condition(
							"$ff.field_event_fields_value",
							'%"moho_id"%:%"' . $escapedSearch . '"%',
							'LIKE',
						);
					$query->condition($searchGroup);
				}

				// Apply date filters - convert millisecond timestamps to ISO datetime strings
				if ($filter_after !== null && is_numeric($filter_after)) {
					$fd = $query->leftJoin('node__field_event_date', 'fd', 'fd.entity_id = n.nid');
					$date_str = date('Y-m-d\TH:i:s', (int) $filter_after / 1000);
					$query->condition("$fd.field_event_date_value", $date_str, '>=');
				}

				if ($filter_before !== null && is_numeric($filter_before)) {
					$fd = $query->leftJoin('node__field_event_date', 'fd', 'fd.entity_id = n.nid');
					$date_str = date('Y-m-d\TH:i:s', (int) $filter_before / 1000);
					$query->condition("$fd.field_event_date_value", $date_str, '<=');
				}

				if ($filter_ends_after !== null && is_numeric($filter_ends_after)) {
					$fed = $query->leftJoin(
						'node__field_event_enddate',
						'fed',
						'fed.entity_id = n.nid',
					);
					$date_str = date('Y-m-d\TH:i:s', (int) $filter_ends_after / 1000);
					$query->condition("$fed.field_event_enddate_value", $date_str, '>=');
				}

				if ($filter_ends_before !== null && is_numeric($filter_ends_before)) {
					$fed = $query->leftJoin(
						'node__field_event_enddate',
						'fed',
						'fed.entity_id = n.nid',
					);
					$date_str = date('Y-m-d\TH:i:s', (int) $filter_ends_before / 1000);
					$query->condition("$fed.field_event_enddate_value", $date_str, '<=');
				}

				// Get total count for random
				$countQuery = clone $query;
				$total = (int) $countQuery->countQuery()->execute()->fetchField();

				$query->orderRandom()->range($page * $limit, $limit);
				$nids = $query->execute()->fetchCol();
			} else {
				// Use entity query for normal sorting
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
							[
								GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
								GeneralHelper::findOrdinal(
									Visibility::cases(),
									Visibility::UNLISTED,
								),
							],
							'IN',
						);

						// is in attendee array or is host
						$group->condition(
							'field_event_attendees.target_id',
							$user->id(),
							'CONTAINS',
						);
						$group->condition('field_host_id', $user->id());
						$query->condition($group);
					}
				} else {
					// only public events for anonymous users
					$query->condition(
						'field_visibility',
						GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
						'=',
					);
				}

				if ($search) {
					$group = $query
						->orConditionGroup()
						->condition('field_event_name', $search, 'CONTAINS')
						->condition('field_event_description', $search, 'CONTAINS')
						->condition('field_event_fields', '"moho_id":"' . $search, 'CONTAINS');
					$query->condition($group);
				}

				// Apply date filters - convert millisecond timestamps to ISO datetime strings
				if ($filter_after !== null && is_numeric($filter_after)) {
					$date_str = date('Y-m-d\TH:i:s', (int) $filter_after / 1000);
					$query->condition('field_event_date', $date_str, '>=');
				}

				if ($filter_before !== null && is_numeric($filter_before)) {
					$date_str = date('Y-m-d\TH:i:s', (int) $filter_before / 1000);
					$query->condition('field_event_date', $date_str, '<=');
				}

				if ($filter_ends_after !== null && is_numeric($filter_ends_after)) {
					$date_str = date('Y-m-d\TH:i:s', (int) $filter_ends_after / 1000);
					$query->condition('field_event_enddate', $date_str, '>=');
				}

				if ($filter_ends_before !== null && is_numeric($filter_ends_before)) {
					$date_str = date('Y-m-d\TH:i:s', (int) $filter_ends_before / 1000);
					$query->condition('field_event_enddate', $date_str, '<=');
				}

				$countQuery = clone $query;
				$total = (int) $countQuery->count()->execute();

				// Add sorting
				$sortDirection = $sort === 'desc' ? 'DESC' : 'ASC';
				$query->sort('created', $sortDirection);

				$query->range($page * $limit, $limit);
				$nids = $query->execute();
			}

			/** @var Node[] $nodes */
			$nodes = $storage->loadMultiple($nids);
			$data = [];
			foreach ($nodes as $node) {
				$event = EventsHelper::nodeToEvent($node);
				if ($event) {
					$item = EventsHelper::serializeEvent($event, $node, $requester);
					$data[] = $item;
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
				'Failed to load events storage: ' . $e->getMessage(),
			);
		}
	}

	// GET /v2/events/random
	public function randomEvent(Request $request): JsonResponse
	{
		$requester = UsersHelper::getOwnerOfRequest($request);

		try {
			$count = $request->query->getInt('count', 5);
			if ($count < 1 || $count > 25) {
				return GeneralHelper::badRequest('Count must be between 1 and 25');
			}

			$connection = Drupal::database();
			$query = $connection
				->select('node_field_data', 'n')
				->fields('n', ['nid'])
				->condition('n.status', 1)
				->condition('n.type', 'event');

			$query->orderRandom()->range(0, $count);
			$nids = $query->execute()->fetchCol();

			if (empty($nids)) {
				return GeneralHelper::notFound('No events found');
			}

			$results = [];

			foreach ($nids as $randomNid) {
				$node = Node::load($randomNid);

				if (!$node) {
					return GeneralHelper::internalError('Failed to load random event');
				}

				$event = EventsHelper::nodeToEvent($node);
				if (!$event) {
					return GeneralHelper::internalError('Failed to load random event');
				}

				$results[] = EventsHelper::serializeEvent($event, $node, $requester);
			}

			return new JsonResponse($results, Response::HTTP_OK);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load events storage: ' . $e->getMessage(),
			);
		} catch (UnexpectedValueException $e) {
			return GeneralHelper::badRequest('Invalid count parameter: ' . $e->getMessage());
		}
	}

	// POST /v2/events
	public function createEvent(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$count = UsersHelper::getUserEventsCount($user);
		if ($count >= UsersHelper::getMaxEventsCount($user)) {
			return GeneralHelper::paymentRequired(
				'You have reached your event limit. Upgrade to create more events',
			);
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$event = EventsHelper::validateEventData($body, $user);
		if ($event instanceof JsonResponse) {
			return $event;
		}

		if ($event->getFields()['cancelled'] ?? false) {
			return GeneralHelper::badRequest('Cannot create an event as cancelled');
		}

		$node = EventsHelper::createEvent($event, $user);
		$result = EventsHelper::serializeEvent($event, $node, $user);

		return new JsonResponse($result, Response::HTTP_CREATED);
	}

	// GET /v2/events/{eventId}
	public function getEvent(int $eventId, Request $request): JsonResponse
	{
		$user = UsersHelper::getOwnerOfRequest($request);
		$node = Node::load($eventId);
		if (!$node) {
			return GeneralHelper::notFound('Event not found');
		}

		$event = EventsHelper::nodeToEvent($node);
		if (!$event) {
			return GeneralHelper::internalError('Failed to load event');
		}

		if (!EventsHelper::isVisible($event, $user)) {
			return GeneralHelper::notFound('Event not found');
		}

		$result = EventsHelper::serializeEvent($event, $node, $user);
		return new JsonResponse($result, Response::HTTP_OK);
	}

	// PATCH /v2/events/{eventId}
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
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$result = EventsHelper::applyEventUpdates($event, $body, $user);
		if ($result instanceof JsonResponse) {
			return $result;
		}

		$node = Node::load($eventId);
		if (!$node) {
			return GeneralHelper::internalError('Failed to load event node');
		}

		EventsHelper::updateEvent($node, $event);
		return new JsonResponse(
			EventsHelper::serializeEvent($event, $node, $user),
			Response::HTTP_OK,
		);
	}

	// DELETE /v2/events/{eventId}
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
		EventsHelper::deleteThumbnail($node);

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// GET /v2/events/{eventId}/attendees
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
		$sort = $pagination['sort'];

		$attendees = array_filter($event->getAttendees(), function (UserInterface $attendee) use (
			$search,
		) {
			if ($search) {
				return stripos($attendee->getAccountName(), $search) !== false;
			}
			return true;
		});

		// Apply sorting
		if ($sort === 'rand') {
			shuffle($attendees);
		} else {
			usort($attendees, function ($a, $b) use ($sort) {
				$aTime = $a->getCreatedTime();
				$bTime = $b->getCreatedTime();
				return $sort === 'desc' ? $bTime <=> $aTime : $aTime <=> $bTime;
			});
		}

		$total = count($attendees);
		$attendees = array_slice($attendees, $page * $limit, $limit);
		$attendees = array_values(
			array_filter(
				array_map(
					fn(UserInterface $attendee) => UsersHelper::serializeUser($attendee, $user),
					$attendees,
				),
			),
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

	// POST /v2/events/{eventId}/signup
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

		if ($event->getFields()['cancelled'] ?? false) {
			return GeneralHelper::badRequest('Cannot sign up for a cancelled event');
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
			[
				'user' => UsersHelper::serializeUser($user, $user),
				'event' => EventsHelper::serializeEvent($event, $node, $user),
			],
			Response::HTTP_OK,
		);
	}

	// POST /v2/events/{eventId}/leave
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
			[
				'user' => UsersHelper::serializeUser($user, $user),
				'event' => EventsHelper::serializeEvent($event, $node, $user),
			],
			Response::HTTP_OK,
		);
	}

	// GET /v2/events/current
	// GET /v2/users/current/events/attending
	// GET /v2/users/{id}/events/attending
	// GET /v2/users/{username}/events/attending
	public function getUserEvents(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$identifier = $id ?? $username;
		if ($identifier !== null) {
			$user = UsersHelper::findBy($identifier);
			if (!$user) {
				return GeneralHelper::notFound('User not found');
			}
		} else {
			$user = UsersHelper::findByRequest($request);
			if (!$user) {
				return GeneralHelper::unauthorized();
			}
		}

		if ($user instanceof JsonResponse) {
			return $user;
		}

		$visible = UsersHelper::checkVisibility($user, $request);
		if ($visible instanceof JsonResponse) {
			return $visible;
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];
		$sort = $pagination['sort'];

		$requester = UsersHelper::getOwnerOfRequest($request);
		$data = UsersHelper::getUserEvents($visible, $limit, $page, $search, $sort);
		$nodes = $data['nodes'];
		$total = $data['total'];

		// Serialize events with IDs
		$events = array_map(function ($node) use ($requester) {
			$event = EventsHelper::nodeToEvent($node);
			return EventsHelper::serializeEvent($event, $node, $requester);
		}, $nodes);

		return new JsonResponse([
			'limit' => $limit,
			'page' => $page + 1,
			'items' => $events,
			'total' => $total,
		]);
	}

	// POST /v2/events/{eventId}/cancel
	public function cancelEvent(int $eventId, Request $request): JsonResponse
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
			return GeneralHelper::forbidden('You are not allowed to cancel this event');
		}

		$fields = $event->getFields();
		$fields['cancelled'] = true;
		$event->setFields($fields);

		$node = Node::load($eventId);
		if (!$node) {
			return GeneralHelper::internalError('Failed to load event node');
		}

		EventsHelper::updateEvent($node, $event);

		// Notify all attendees
		$attendees = $event->getAttendees();
		foreach ($attendees as $attendee) {
			if ($attendee->id() !== $user->id()) {
				UsersHelper::addNotification(
					$attendee,
					'Event Cancelled',
					"The event '{$event->getName()}' has been cancelled by the host.",
					'/events/' . $eventId,
					'info',
					$event->getHost()->getAccountName(),
				);
			}
		}

		return new JsonResponse(
			EventsHelper::serializeEvent($event, $node, $user),
			Response::HTTP_OK,
		);
	}

	// POST /v2/events/{eventId}/uncancel
	public function uncancelEvent(int $eventId, Request $request): JsonResponse
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
			return GeneralHelper::forbidden('You are not allowed to uncancel this event');
		}

		// Update the cancelled field
		$fields = $event->getFields();
		$fields['cancelled'] = false;
		$event->setFields($fields);

		$node = Node::load($eventId);
		if (!$node) {
			return GeneralHelper::internalError('Failed to load event node');
		}

		EventsHelper::updateEvent($node, $event);

		// Notify all attendees
		$attendees = $event->getAttendees();
		foreach ($attendees as $attendee) {
			if ($attendee->id() !== $user->id()) {
				UsersHelper::addNotification(
					$attendee,
					'Event Reinstated',
					"The event '{$event->getName()}' has been reinstated by the host.",
					'/events/' . $eventId,
					'info',
					$event->getHost()->getAccountName(),
				);
			}
		}

		return new JsonResponse(
			EventsHelper::serializeEvent($event, $node, $user),
			Response::HTTP_OK,
		);
	}

	#region Event Image Submissions

	// GET /v2/events/current/images
	// GET /v2/users/current/events/images
	// GET /v2/users/{id}/events/images
	// GET /v2/users/{username}/events/images
	public function getUserEventImages(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$identifier = $id ?? $username;
		if ($identifier !== null) {
			$user = UsersHelper::findBy($identifier);
			if (!$user) {
				return GeneralHelper::notFound('User not found');
			}
		} else {
			$user = UsersHelper::findByRequest($request);
			if (!$user) {
				return GeneralHelper::unauthorized();
			}
		}

		if ($user instanceof JsonResponse) {
			return $user;
		}

		$visible = UsersHelper::checkVisibility($user, $request);
		if ($visible instanceof JsonResponse) {
			return $visible;
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'];
		$search = $pagination['search'];
		$sort = $pagination['sort'];

		try {
			$data = EventsHelper::retrieveImageSubmission(
				$visible->id(),
				null,
				null,
				$limit,
				$page,
				$search,
				$sort,
			);
			$items = $data ?? [];
			return new JsonResponse(
				[
					'items' => $items,
					'total' => count($items),
					'page' => $page,
					'limit' => $limit,
					'search' => $search,
				],
				Response::HTTP_OK,
			);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load events storage: ' . $e->getMessage(),
			);
		}
	}

	// DELETE /v2/events/current/images
	// DELETE /v2/users/current/events/images
	// DELETE /v2/users/{id}/events/images
	// DELETE /v2/users/{username}/events/images
	public function deleteUserEventImages(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$identifier = $id ?? $username;
		if ($identifier !== null) {
			$user = UsersHelper::findByAuthorized($identifier, $request);
			if (!$user) {
				return GeneralHelper::notFound('User not found');
			}
		} else {
			$user = UsersHelper::findByRequest($request);
			if (!$user) {
				return GeneralHelper::unauthorized();
			}
		}

		if ($user instanceof JsonResponse) {
			return $user;
		}

		try {
			$result = EventsHelper::deleteImageSubmission(null, $user->id(), null);
			if ($result instanceof JsonResponse) {
				return $result;
			}

			return new JsonResponse(null, Response::HTTP_NO_CONTENT);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load events storage: ' . $e->getMessage(),
			);
		}
	}

	// GET /v2/events/current/images/{eventId}
	// GET /v2/users/current/events/images/{eventId}
	// GET /v2/users/{id}/events/images/{eventId}
	// GET /v2/users/{username}/events/images/{eventId}
	public function getUserEventImage(
		int $eventId,
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$identifier = $id ?? $username;
		if ($identifier !== null) {
			$user = UsersHelper::findBy($identifier);
			if (!$user) {
				return GeneralHelper::notFound('User not found');
			}
		} else {
			$user = UsersHelper::findByRequest($request);
			if (!$user) {
				return GeneralHelper::unauthorized();
			}
		}

		if ($user instanceof JsonResponse) {
			return $user;
		}

		$visible = UsersHelper::checkVisibility($user, $request);
		if ($visible instanceof JsonResponse) {
			return $visible;
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'];
		$search = $pagination['search'];
		$sort = $pagination['sort'];

		try {
			$data =
				EventsHelper::retrieveImageSubmission(
					$visible->id(),
					$eventId,
					null,
					$limit,
					$page,
					$search,
					$sort,
				) ?? [];

			return new JsonResponse(
				[
					'items' => $data,
					'total' => count($data ?? []),
					'page' => $page,
					'limit' => $limit,
					'search' => $search,
				],
				Response::HTTP_OK,
			);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load events storage: ' . $e->getMessage(),
			);
		}
	}

	// DELETE /v2/events/current/images/{eventId}
	// DELETE /v2/users/current/events/images/{eventId}
	// DELETE /v2/users/{id}/events/images/{eventId}
	// DELETE /v2/users/{username}/events/images/{eventId}
	public function deleteUserEventImage(
		int $eventId,
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$identifier = $id ?? $username;
		if ($identifier !== null) {
			$user = UsersHelper::findByAuthorized($identifier, $request);
			if (!$user) {
				return GeneralHelper::notFound('User not found');
			}
		} else {
			$user = UsersHelper::findByRequest($request);
			if (!$user) {
				return GeneralHelper::unauthorized();
			}
		}

		if ($user instanceof JsonResponse) {
			return $user;
		}

		try {
			$result = EventsHelper::deleteImageSubmission(null, $user->id(), $eventId);
			if ($result instanceof JsonResponse) {
				return $result;
			}

			return new JsonResponse(null, Response::HTTP_NO_CONTENT);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load events storage: ' . $e->getMessage(),
			);
		}
	}

	// GET /v2/events/{eventId}/images
	public function getEventImages(int $eventId, Request $request): JsonResponse
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
		$page = $pagination['page'];
		$search = $pagination['search'];
		$sort = $pagination['sort'];

		try {
			/** @var array<int, Drupal\mantle2\Custom\EventImageSubmission>|null $data */
			$data = EventsHelper::retrieveImageSubmission(
				null,
				$eventId,
				null,
				$limit,
				$page,
				$search,
				$sort,
			);
			$items = $data ?? [];
			return new JsonResponse(
				[
					'items' => $items,
					'total' => count($items),
					'page' => $page,
					'limit' => $limit,
					'search' => $search,
				],
				Response::HTTP_OK,
			);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load events storage: ' . $e->getMessage(),
			);
		}
	}

	// GET /v2/events/{eventId}/images/{imageId}
	public function getEventImage(int $eventId, int $imageId, Request $request): JsonResponse
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

		try {
			/** @var Drupal\mantle2\Custom\EventImageSubmission $data */
			$data = EventsHelper::retrieveImageSubmission(
				null,
				$eventId,
				$imageId,
				null,
				null,
				null,
				null,
			);
			if (empty($data)) {
				return GeneralHelper::notFound('Image not found');
			}

			return new JsonResponse($data, Response::HTTP_OK);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load events storage: ' . $e->getMessage(),
			);
		}
	}

	// POST /v2/events/{eventId}/images
	public function submitEventImage(int $eventId, Request $request): JsonResponse
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

		if ($event->getFields()['cancelled'] ?? false) {
			return GeneralHelper::badRequest('Cannot submit image to a cancelled event');
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		$photoUrl = $body['photo_url'] ?? null; // data URL only
		if (!$photoUrl || !is_string($photoUrl) || !str_starts_with($photoUrl, 'data:image/')) {
			return GeneralHelper::badRequest('Invalid or missing photo_url field');
		}

		try {
			$submissionId = EventsHelper::submitImage($eventId, $user->id(), $photoUrl);
			if ($submissionId === null) {
				return GeneralHelper::internalError('Failed to submit image; unknown error');
			}

			return new JsonResponse(
				[
					'message' => 'Image submitted successfully',
					'event_id' => $eventId,
					'user_id' => $user->id(),
					'submission_id' => $submissionId,
					'photo_url' => $photoUrl,
				],
				Response::HTTP_CREATED,
			);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load events storage: ' . $e->getMessage(),
			);
		}
	}

	// DELETE /v2/events/{eventId}/images
	public function deleteEventImages(int $eventId, Request $request): JsonResponse
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

		// only delete if admin or host
		if ($event->getHost()->id() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You are not allowed to delete these images');
		}

		try {
			$result = EventsHelper::deleteImageSubmission($eventId, null, null);
			if ($result instanceof JsonResponse) {
				return $result;
			}

			return new JsonResponse(null, Response::HTTP_NO_CONTENT);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load events storage: ' . $e->getMessage(),
			);
		}
	}

	// DELETE /v2/events/{eventId}/images/{imageId}
	public function deleteEventImage(int $eventId, int $imageId, Request $request): JsonResponse
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

		/** @var Drupal\mantle2\Custom\EventImageSubmission $submission */
		$submission = EventsHelper::retrieveImageSubmission(null, null, $imageId);
		if (!$submission) {
			return GeneralHelper::notFound('Image not found');
		}

		if (!UsersHelper::isAdmin($user) && $submission->user_id !== $user->id()) {
			return GeneralHelper::forbidden('You are not allowed to delete this image');
		}

		try {
			$success = EventsHelper::deleteImageSubmission($eventId, $imageId, $user->id());
			if (!$success) {
				return GeneralHelper::internalError('Failed to delete image');
			}

			return new JsonResponse(null, Response::HTTP_NO_CONTENT);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load events storage: ' . $e->getMessage(),
			);
		}
	}

	#endregion
}
