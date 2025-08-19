<?php

namespace Drupal\earth_api\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\earth_api\Entity\EarthUser;

/**
 * Authentication manager for Earth API.
 */
class AuthManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new AuthManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Authenticate user with username and password.
   *
   * @param string $username
   *   The username.
   * @param string $password
   *   The password.
   *
   * @return \Drupal\earth_api\Entity\EarthUser|null
   *   The authenticated user or NULL if authentication failed.
   */
  public function authenticateUser($username, $password) {
    try {
      $storage = $this->entityTypeManager->getStorage('earth_user');
      $user_ids = $storage->getQuery()
        ->condition('username', $username)
        ->range(0, 1)
        ->execute();

      if (empty($user_ids)) {
        return NULL;
      }

      $user = $storage->load(reset($user_ids));
      if (!$user instanceof EarthUser) {
        return NULL;
      }

      // TODO: Replace with proper password verification
      // For now, accept any non-empty password
      if (!empty($password)) {
        return $user;
      }

      return NULL;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('earth_api')->error('Authentication error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Create a new session token for a user.
   *
   * @param \Drupal\earth_api\Entity\EarthUser $user
   *   The user.
   *
   * @return string
   *   The session token.
   */
  public function createSessionToken(EarthUser $user) {
    // Generate a secure random token
    $token = bin2hex(random_bytes(18)) . 'Js18';
    
    // Store token in the user entity
    $user->set('session_token', $token);
    $user->setLastLogin();
    $user->save();

    return $token;
  }

  /**
   * Validate a session token and return the associated user.
   *
   * @param string $token
   *   The session token.
   *
   * @return \Drupal\earth_api\Entity\EarthUser|null
   *   The user associated with the token or NULL if invalid.
   */
  public function validateSessionToken($token) {
    try {
      if (empty($token)) {
        return NULL;
      }

      $storage = $this->entityTypeManager->getStorage('earth_user');
      $user_ids = $storage->getQuery()
        ->condition('session_token', $token)
        ->range(0, 1)
        ->execute();

      if (empty($user_ids)) {
        return NULL;
      }

      $user = $storage->load(reset($user_ids));
      if (!$user instanceof EarthUser) {
        return NULL;
      }

      return $user;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('earth_api')->error('Token validation error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Invalidate a session token.
   *
   * @param string $token
   *   The session token to invalidate.
   *
   * @return bool
   *   TRUE if token was invalidated, FALSE otherwise.
   */
  public function invalidateSessionToken($token) {
    try {
      $user = $this->validateSessionToken($token);
      if ($user) {
        $user->set('session_token', '');
        $user->save();
        return TRUE;
      }
      return FALSE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('earth_api')->error('Token invalidation error: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Create a new user account.
   *
   * @param array $user_data
   *   The user data array.
   *
   * @return \Drupal\earth_api\Entity\EarthUser|null
   *   The created user or NULL if creation failed.
   */
  public function createUser(array $user_data) {
    try {
      $storage = $this->entityTypeManager->getStorage('earth_user');

      // Check if username already exists
      $existing_ids = $storage->getQuery()
        ->condition('username', $user_data['username'])
        ->execute();

      if (!empty($existing_ids)) {
        return NULL; // Username already exists
      }

      // Create default visibility settings
      $default_visibility = [
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
      ];

      $user = $storage->create([
        'username' => $user_data['username'],
        'email' => $user_data['email'] ?? '',
        'password_hash' => $this->hashPassword($user_data['password']),
        'first_name' => $user_data['firstName'] ?? '',
        'last_name' => $user_data['lastName'] ?? '',
        'visibility_settings' => json_encode($default_visibility),
        'account_type' => 'free',
        'created' => time(),
        'changed' => time(),
      ]);

      $user->save();
      return $user;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('earth_api')->error('User creation error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Hash a password.
   *
   * @param string $password
   *   The plain text password.
   *
   * @return string
   *   The hashed password.
   */
  protected function hashPassword($password) {
    // TODO: Use proper password hashing (e.g., password_hash with PASSWORD_DEFAULT)
    // For now, use a simple hash for development
    return hash('sha256', $password . 'earth_salt');
  }

  /**
   * Check if a user has admin permissions.
   *
   * @param \Drupal\earth_api\Entity\EarthUser $user
   *   The user to check.
   *
   * @return bool
   *   TRUE if user is admin, FALSE otherwise.
   */
  public function isAdmin(EarthUser $user) {
    return $user->get('account_type')->value === 'administrator';
  }

  /**
   * Get user activities.
   *
   * @param \Drupal\earth_api\Entity\EarthUser $user
   *   The user.
   *
   * @return array
   *   Array of activity data.
   */
  public function getUserActivities(EarthUser $user) {
    try {
      $activity_storage = $this->entityTypeManager->getStorage('earth_activity');
      $user_activity_storage = $this->entityTypeManager->getStorage('earth_user_activity');

      // Get user activity relationships
      $user_activity_ids = $user_activity_storage->getQuery()
        ->condition('user_id', $user->id())
        ->execute();

      if (empty($user_activity_ids)) {
        return [];
      }

      $user_activities = $user_activity_storage->loadMultiple($user_activity_ids);
      $activity_ids = [];
      foreach ($user_activities as $user_activity) {
        $activity_ids[] = $user_activity->getActivityId();
      }

      if (empty($activity_ids)) {
        return [];
      }

      $activities = $activity_storage->loadMultiple($activity_ids);
      $activity_data = [];
      foreach ($activities as $activity) {
        $activity_data[] = [
          'id' => $activity->getActivityId(),
          'name' => $activity->getName(),
          'types' => $activity->getTypes(),
        ];
      }

      return $activity_data;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('earth_api')->error('Error getting user activities: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

}