<?php

namespace Drupal\earth_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for activity-related API endpoints.
 */
class ActivitiesController extends ControllerBase {

  /**
   * List all activities with pagination.
   */
  public function list(Request $request) {
    $page = (int) $request->query->get('page', 1);
    $limit = (int) $request->query->get('limit', 25);
    $search = $request->query->get('search', '');

    $activities = [
      [
        'id' => 'hiking',
        'name' => 'Hiking',
        'description' => 'Outdoor hiking activities in nature',
        'types' => ['HOBBY', 'SPORT'],
        'created_at' => '2025-01-15T10:00:00Z',
      ],
      [
        'id' => 'cooking',
        'name' => 'Cooking',
        'description' => 'Culinary activities and food preparation',
        'types' => ['HOBBY', 'CREATIVE'],
        'created_at' => '2025-01-15T10:00:00Z',
      ],
      [
        'id' => 'reading',
        'name' => 'Reading',
        'description' => 'Reading books and literature',
        'types' => ['LEARNING', 'RELAXATION'],
        'created_at' => '2025-01-15T10:00:00Z',
      ],
    ];

    $data = [
      'page' => $page,
      'limit' => $limit,
      'total' => count($activities),
      'items' => array_slice($activities, ($page - 1) * $limit, $limit),
    ];

    return new JsonResponse($data);
  }

  /**
   * Create new activity.
   */
  public function create(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    
    if (!$content || !isset($content['name'])) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $activity = [
      'id' => strtolower(str_replace(' ', '_', $content['name'])),
      'name' => $content['name'],
      'description' => $content['description'] ?? '',
      'types' => $content['types'] ?? ['OTHER'],
      'created_at' => date('c'),
    ];

    return new JsonResponse($activity, 201);
  }

  /**
   * Get, update, or delete activity by ID.
   */
  public function byId(Request $request, $activityId) {
    $method = $request->getMethod();

    $activity = [
      'id' => $activityId,
      'name' => 'Sample Activity',
      'description' => 'A sample activity description',
      'types' => ['HOBBY'],
      'created_at' => '2025-01-15T10:00:00Z',
    ];

    if ($method === 'DELETE') {
      return new JsonResponse(null, 204);
    }

    if ($method === 'PATCH') {
      $content = json_decode($request->getContent(), TRUE);
      if ($content) {
        $activity = array_merge($activity, $content);
        $activity['updated_at'] = date('c');
      }
    }

    return new JsonResponse($activity);
  }

}