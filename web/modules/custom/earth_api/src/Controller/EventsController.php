<?php

namespace Drupal\earth_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for event-related API endpoints.
 */
class EventsController extends ControllerBase {

  /**
   * List all events with pagination.
   */
  public function list(Request $request) {
    $page = (int) $request->query->get('page', 1);
    $limit = (int) $request->query->get('limit', 25);
    $search = $request->query->get('search', '');

    $sampleEvent = [
      'id' => 'event123',
      'hostId' => 'eb9137b1272938',
      'name' => 'Community Cleanup',
      'description' => 'Join us for a community cleanup event in the park.',
      'type' => 'IN_PERSON',
      'activities' => ['HOBBY', 'SPORT'],
      'location' => [
        'latitude' => 37.7749,
        'longitude' => -122.4194,
      ],
      'date' => '2025-05-11T10:00:00Z',
      'endDate' => '2025-05-11T12:00:00Z',
      'visibility' => 'PUBLIC',
    ];

    $data = [
      'page' => $page,
      'limit' => $limit,
      'total' => 1,
      'items' => [$sampleEvent],
    ];

    return new JsonResponse($data);
  }

  /**
   * Create new event.
   */
  public function create(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    
    if (!$content || !isset($content['name'])) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $event = [
      'id' => 'event_' . uniqid(),
      'hostId' => 'eb9137b1272938',
      'name' => $content['name'],
      'description' => $content['description'] ?? '',
      'type' => $content['type'] ?? 'IN_PERSON',
      'activities' => $content['activities'] ?? [],
      'location' => $content['location'] ?? ['latitude' => 0, 'longitude' => 0],
      'date' => $content['date'] ?? date('c'),
      'endDate' => $content['endDate'] ?? date('c', strtotime('+2 hours')),
      'visibility' => $content['visibility'] ?? 'PUBLIC',
    ];

    return new JsonResponse($event, 201);
  }

  /**
   * Get, update, or delete event by ID.
   */
  public function byId(Request $request, $eventId) {
    $method = $request->getMethod();

    $event = [
      'id' => $eventId,
      'hostId' => 'eb9137b1272938',
      'name' => 'Sample Event',
      'description' => 'A sample event description',
      'type' => 'IN_PERSON',
      'activities' => ['HOBBY'],
      'location' => [
        'latitude' => 37.7749,
        'longitude' => -122.4194,
      ],
      'date' => '2025-05-11T10:00:00Z',
      'endDate' => '2025-05-11T12:00:00Z',
      'visibility' => 'PUBLIC',
    ];

    if ($method === 'DELETE') {
      return new JsonResponse(null, 204);
    }

    if ($method === 'PATCH') {
      $content = json_decode($request->getContent(), TRUE);
      if ($content) {
        $event = array_merge($event, $content);
      }
    }

    return new JsonResponse($event);
  }

  /**
   * Get current user's events.
   */
  public function current(Request $request) {
    $page = (int) $request->query->get('page', 1);
    $limit = (int) $request->query->get('limit', 25);

    $sampleEvent = [
      'id' => 'event123',
      'hostId' => 'eb9137b1272938',
      'name' => 'My Event',
      'description' => 'An event I am attending',
      'type' => 'IN_PERSON',
      'activities' => ['HOBBY', 'SPORT'],
      'location' => [
        'latitude' => 37.7749,
        'longitude' => -122.4194,
      ],
      'date' => '2025-05-11T10:00:00Z',
      'endDate' => '2025-05-11T12:00:00Z',
      'visibility' => 'PUBLIC',
    ];

    $data = [
      'page' => $page,
      'limit' => $limit,
      'total' => 1,
      'items' => [$sampleEvent],
    ];

    return new JsonResponse($data);
  }

  /**
   * Sign up for an event.
   */
  public function signup(Request $request) {
    $eventId = $request->query->get('eventId');
    
    if (!$eventId) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $event = [
      'id' => $eventId,
      'hostId' => 'eb9137b1272938',
      'name' => 'Event Signup',
      'description' => 'Successfully signed up for this event',
      'type' => 'IN_PERSON',
      'activities' => ['HOBBY'],
      'location' => [
        'latitude' => 37.7749,
        'longitude' => -122.4194,
      ],
      'date' => '2025-05-11T10:00:00Z',
      'endDate' => '2025-05-11T12:00:00Z',
      'visibility' => 'PUBLIC',
    ];

    return new JsonResponse($event);
  }

  /**
   * Cancel event signup.
   */
  public function cancel(Request $request) {
    $eventId = $request->query->get('eventId');
    
    if (!$eventId) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $event = [
      'id' => $eventId,
      'hostId' => 'eb9137b1272938',
      'name' => 'Event Cancellation',
      'description' => 'Successfully cancelled signup for this event',
      'type' => 'IN_PERSON',
      'activities' => ['HOBBY'],
      'location' => [
        'latitude' => 37.7749,
        'longitude' => -122.4194,
      ],
      'date' => '2025-05-11T10:00:00Z',
      'endDate' => '2025-05-11T12:00:00Z',
      'visibility' => 'PUBLIC',
    ];

    return new JsonResponse($event);
  }

}