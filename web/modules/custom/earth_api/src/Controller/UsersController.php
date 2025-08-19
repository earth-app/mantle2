<?php

namespace Drupal\earth_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for user-related API endpoints.
 */
class UsersController extends ControllerBase {

  /**
   * List all users with pagination.
   */
  public function list(Request $request) {
    $page = (int) $request->query->get('page', 1);
    $limit = (int) $request->query->get('limit', 25);
    $search = $request->query->get('search', '');

    // Sample user data matching the OpenAPI schema
    $sampleUser = [
      'id' => 'eb9137b1272938',
      'username' => 'johndoe',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => '2025-01-15T12:00:00Z',
      'last_login' => '2025-01-15T11:30:00Z',
      'account' => [
        'type' => 'com.earthapp.account.Account',
        'id' => 'account123',
        'username' => 'johndoe',
        'email' => 'johndoe@example.com',
        'country' => 'US',
        'phoneNumber' => 1234567890,
        'visibility' => [
          'name' => 'PUBLIC',
          'bio' => 'PUBLIC',
          'phone_number' => 'PRIVATE',
          'country' => 'PUBLIC',
          'email' => 'CIRCLE',
          'address' => 'PRIVATE',
          'activities' => 'PUBLIC',
          'events' => 'PUBLIC',
          'friends' => 'MUTUAL',
          'last_login' => 'CIRCLE',
          'account_type' => 'PUBLIC',
          'circle' => 'CIRCLE',
        ],
      ],
      'activities' => [
        [
          'id' => 'hiking',
          'name' => 'Hiking',
          'types' => ['HOBBY', 'SPORT'],
        ],
      ],
    ];

    $data = [
      'page' => $page,
      'limit' => $limit,
      'total' => 1,
      'items' => [$sampleUser],
    ];

    return new JsonResponse($data);
  }

  /**
   * User login endpoint.
   */
  public function login(Request $request) {
    // TODO: Implement proper Basic Auth validation
    $authorization = $request->headers->get('Authorization');
    
    if (!$authorization || !str_starts_with($authorization, 'Basic ')) {
      return new JsonResponse([
        'code' => 401,
        'message' => 'Unauthorized'
      ], 401);
    }

    // Return login response
    $data = [
      'id' => 'eb9137b1272938',
      'username' => 'johndoe',
      'session_token' => '4bHN3nxwb21bd1sm109s1nan28xm1bab2Js18',
    ];

    return new JsonResponse($data);
  }

  /**
   * User logout endpoint.
   */
  public function logout(Request $request) {
    $sampleUser = [
      'id' => 'eb9137b1272938',
      'username' => 'johndoe',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => '2025-01-15T12:00:00Z',
      'last_login' => '2025-01-15T11:30:00Z',
      'account' => [
        'type' => 'com.earthapp.account.Account',
        'id' => 'account123',
        'username' => 'johndoe',
        'email' => 'johndoe@example.com',
        'country' => 'US',
        'phoneNumber' => 1234567890,
        'visibility' => [
          'name' => 'PUBLIC',
          'bio' => 'PUBLIC',
          'phone_number' => 'PRIVATE',
          'country' => 'PUBLIC',
          'email' => 'CIRCLE',
          'address' => 'PRIVATE',
          'activities' => 'PUBLIC',
          'events' => 'PUBLIC',
          'friends' => 'MUTUAL',
          'last_login' => 'CIRCLE',
          'account_type' => 'PUBLIC',
          'circle' => 'CIRCLE',
        ],
      ],
      'activities' => [
        [
          'id' => 'hiking',
          'name' => 'Hiking',
          'types' => ['HOBBY', 'SPORT'],
        ],
      ],
    ];

    $data = [
      'message' => 'Logout successful',
      'session_token' => '4bHN3nxwb21bd1sm109s1nan28xm1bab2Js18',
      'user' => $sampleUser,
    ];

    return new JsonResponse($data);
  }

  /**
   * Create new user endpoint.
   */
  public function create(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    
    if (!$content || !isset($content['username']) || !isset($content['password'])) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $sampleUser = [
      'id' => 'eb9137b1272938',
      'username' => $content['username'],
      'created_at' => date('c'),
      'updated_at' => date('c'),
      'last_login' => date('c'),
      'account' => [
        'type' => 'com.earthapp.account.Account',
        'id' => 'account123',
        'username' => $content['username'],
        'email' => $content['email'] ?? '',
        'country' => '',
        'phoneNumber' => 0,
        'visibility' => [
          'name' => 'PUBLIC',
          'bio' => 'PUBLIC',
          'phone_number' => 'PRIVATE',
          'country' => 'PUBLIC',
          'email' => 'CIRCLE',
          'address' => 'PRIVATE',
          'activities' => 'MUTUAL',
          'events' => 'MUTUAL',
          'friends' => 'MUTUAL',
          'last_login' => 'CIRCLE',
          'account_type' => 'PUBLIC',
          'circle' => 'CIRCLE',
        ],
      ],
      'activities' => [],
    ];

    $data = [
      'user' => $sampleUser,
      'session_token' => '4bHN3nxwb21bd1sm109s1nan28xm1bab2Js18',
    ];

    return new JsonResponse($data, 201);
  }

  /**
   * Current user endpoint - GET, PATCH, DELETE.
   */
  public function current(Request $request) {
    $method = $request->getMethod();

    $sampleUser = [
      'id' => 'eb9137b1272938',
      'username' => 'johndoe',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => '2025-01-15T12:00:00Z',
      'last_login' => '2025-01-15T11:30:00Z',
      'account' => [
        'type' => 'com.earthapp.account.Account',
        'id' => 'account123',
        'username' => 'johndoe',
        'email' => 'johndoe@example.com',
        'country' => 'US',
        'phoneNumber' => 1234567890,
        'visibility' => [
          'name' => 'PUBLIC',
          'bio' => 'PUBLIC',
          'phone_number' => 'PRIVATE',
          'country' => 'PUBLIC',
          'email' => 'CIRCLE',
          'address' => 'PRIVATE',
          'activities' => 'PUBLIC',
          'events' => 'PUBLIC',
          'friends' => 'MUTUAL',
          'last_login' => 'CIRCLE',
          'account_type' => 'PUBLIC',
          'circle' => 'CIRCLE',
        ],
      ],
      'activities' => [
        [
          'id' => 'hiking',
          'name' => 'Hiking',
          'types' => ['HOBBY', 'SPORT'],
        ],
      ],
    ];

    if ($method === 'DELETE') {
      return new JsonResponse(null, 204);
    }

    return new JsonResponse($sampleUser);
  }

  /**
   * Update field privacy settings.
   */
  public function fieldPrivacy(Request $request) {
    $sampleUser = [
      'id' => 'eb9137b1272938',
      'username' => 'johndoe',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => date('c'),
      'last_login' => '2025-01-15T11:30:00Z',
      'account' => [
        'type' => 'com.earthapp.account.Account',
        'id' => 'account123',
        'username' => 'johndoe',
        'email' => 'johndoe@example.com',
        'country' => 'US',
        'phoneNumber' => 1234567890,
        'visibility' => [
          'name' => 'PUBLIC',
          'bio' => 'PUBLIC',
          'phone_number' => 'PRIVATE',
          'country' => 'PUBLIC',
          'email' => 'CIRCLE',
          'address' => 'PRIVATE',
          'activities' => 'PUBLIC',
          'events' => 'PUBLIC',
          'friends' => 'MUTUAL',
          'last_login' => 'CIRCLE',
          'account_type' => 'PUBLIC',
          'circle' => 'CIRCLE',
        ],
      ],
      'activities' => [
        [
          'id' => 'hiking',
          'name' => 'Hiking',
          'types' => ['HOBBY', 'SPORT'],
        ],
      ],
    ];

    return new JsonResponse($sampleUser);
  }

  /**
   * Profile photo endpoint - GET/PUT.
   */
  public function profilePhoto(Request $request) {
    $method = $request->getMethod();

    if ($method === 'GET') {
      // Return a dummy image response
      return new JsonResponse(['message' => 'Profile photo endpoint - GET'], 200);
    }

    if ($method === 'PUT') {
      // Return a dummy image response for regeneration
      return new JsonResponse(['message' => 'Profile photo regenerated'], 201);
    }

    return new JsonResponse(['code' => 405, 'message' => 'Method Not Allowed'], 405);
  }

  /**
   * Set account type - admin only.
   */
  public function accountType(Request $request) {
    $accountType = $request->query->get('account_type');
    
    if (!$accountType) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $sampleUser = [
      'id' => 'eb9137b1272938',
      'username' => 'johndoe',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => date('c'),
      'last_login' => '2025-01-15T11:30:00Z',
      'account' => [
        'type' => 'com.earthapp.account.Account',
        'id' => 'account123',
        'username' => 'johndoe',
        'email' => 'johndoe@example.com',
        'country' => 'US',
        'phoneNumber' => 1234567890,
        'visibility' => [
          'name' => 'PUBLIC',
          'bio' => 'PUBLIC',
          'phone_number' => 'PRIVATE',
          'country' => 'PUBLIC',
          'email' => 'CIRCLE',
          'address' => 'PRIVATE',
          'activities' => 'PUBLIC',
          'events' => 'PUBLIC',
          'friends' => 'MUTUAL',
          'last_login' => 'CIRCLE',
          'account_type' => 'PUBLIC',
          'circle' => 'CIRCLE',
        ],
      ],
      'activities' => [
        [
          'id' => 'hiking',
          'name' => 'Hiking',
          'types' => ['HOBBY', 'SPORT'],
        ],
      ],
    ];

    return new JsonResponse($sampleUser);
  }

  /**
   * Add activity to user.
   */
  public function addActivity(Request $request) {
    $activityId = $request->query->get('activityId');
    
    if (!$activityId) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $sampleUser = [
      'id' => 'eb9137b1272938',
      'username' => 'johndoe',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => date('c'),
      'last_login' => '2025-01-15T11:30:00Z',
      'account' => [
        'type' => 'com.earthapp.account.Account',
        'id' => 'account123',
        'username' => 'johndoe',
        'email' => 'johndoe@example.com',
        'country' => 'US',
        'phoneNumber' => 1234567890,
        'visibility' => [
          'name' => 'PUBLIC',
          'bio' => 'PUBLIC',
          'phone_number' => 'PRIVATE',
          'country' => 'PUBLIC',
          'email' => 'CIRCLE',
          'address' => 'PRIVATE',
          'activities' => 'PUBLIC',
          'events' => 'PUBLIC',
          'friends' => 'MUTUAL',
          'last_login' => 'CIRCLE',
          'account_type' => 'PUBLIC',
          'circle' => 'CIRCLE',
        ],
      ],
      'activities' => [
        [
          'id' => 'hiking',
          'name' => 'Hiking',
          'types' => ['HOBBY', 'SPORT'],
        ],
        [
          'id' => $activityId,
          'name' => 'New Activity',
          'types' => ['HOBBY'],
        ],
      ],
    ];

    return new JsonResponse($sampleUser);
  }

  /**
   * Remove activity from user.
   */
  public function removeActivity(Request $request) {
    $activityId = $request->query->get('activityId');
    
    if (!$activityId) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $sampleUser = [
      'id' => 'eb9137b1272938',
      'username' => 'johndoe',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => date('c'),
      'last_login' => '2025-01-15T11:30:00Z',
      'account' => [
        'type' => 'com.earthapp.account.Account',
        'id' => 'account123',
        'username' => 'johndoe',
        'email' => 'johndoe@example.com',
        'country' => 'US',
        'phoneNumber' => 1234567890,
        'visibility' => [
          'name' => 'PUBLIC',
          'bio' => 'PUBLIC',
          'phone_number' => 'PRIVATE',
          'country' => 'PUBLIC',
          'email' => 'CIRCLE',
          'address' => 'PRIVATE',
          'activities' => 'PUBLIC',
          'events' => 'PUBLIC',
          'friends' => 'MUTUAL',
          'last_login' => 'CIRCLE',
          'account_type' => 'PUBLIC',
          'circle' => 'CIRCLE',
        ],
      ],
      'activities' => [],
    ];

    return new JsonResponse($sampleUser);
  }

  /**
   * Set user activities.
   */
  public function setActivities(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    
    if (!is_array($content)) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $activities = [];
    foreach ($content as $activityId) {
      $activities[] = [
        'id' => $activityId,
        'name' => 'Activity ' . $activityId,
        'types' => ['HOBBY'],
      ];
    }

    $sampleUser = [
      'id' => 'eb9137b1272938',
      'username' => 'johndoe',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => date('c'),
      'last_login' => '2025-01-15T11:30:00Z',
      'account' => [
        'type' => 'com.earthapp.account.Account',
        'id' => 'account123',
        'username' => 'johndoe',
        'email' => 'johndoe@example.com',
        'country' => 'US',
        'phoneNumber' => 1234567890,
        'visibility' => [
          'name' => 'PUBLIC',
          'bio' => 'PUBLIC',
          'phone_number' => 'PRIVATE',
          'country' => 'PUBLIC',
          'email' => 'CIRCLE',
          'address' => 'PRIVATE',
          'activities' => 'PUBLIC',
          'events' => 'PUBLIC',
          'friends' => 'MUTUAL',
          'last_login' => 'CIRCLE',
          'account_type' => 'PUBLIC',
          'circle' => 'CIRCLE',
        ],
      ],
      'activities' => $activities,
    ];

    return new JsonResponse($sampleUser);
  }

  /**
   * Recommend activities for user.
   */
  public function recommendActivities(Request $request) {
    $limit = (int) $request->query->get('limit', 10);

    $activities = [
      [
        'id' => 'hiking',
        'name' => 'Hiking',
        'description' => 'Outdoor hiking activities',
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
    ];

    return new JsonResponse(array_slice($activities, 0, $limit));
  }

}