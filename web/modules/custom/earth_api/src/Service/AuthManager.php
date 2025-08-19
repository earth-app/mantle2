<?php

namespace Drupal\earth_api\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\earth_api\Entity\EarthUser;

/**
 * Authentication manager for Earth API.
 * 
 * Implements the same token-based authentication system as the original mantle API:
 * - API Tokens: Long-term user tokens (37 chars, 30-day default expiration, max 5 per user)
 * - Session Tokens: Short-term login sessions (37 chars, 14-day expiration, max 3 per user)
 * - Admin API Key: Special admin token for elevated privileges
 * - Secure token storage with encryption and hashing
 */
class AuthManager {

  /**
   * Token length constant matching original API.
   */
  const API_KEY_LENGTH = 37;

  /**
   * Maximum API tokens per user.
   */
  const MAX_API_TOKENS = 5;

  /**
   * Maximum session tokens per user.
   */
  const MAX_SESSION_TOKENS = 3;

  /**
   * Default API token expiration (days).
   */
  const API_TOKEN_EXPIRATION = 30;

  /**
   * Default session token expiration (days).
   */
  const SESSION_TOKEN_EXPIRATION = 14;

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
    
    // Initialize tokens table
    $this->initializeTokensTable();
  }

  /**
   * Initialize the tokens table.
   */
  protected function initializeTokensTable() {
    $schema = [
      'description' => 'Stores authentication tokens (API tokens and session tokens)',
      'fields' => [
        'id' => [
          'type' => 'varchar',
          'length' => 36,
          'not null' => TRUE,
          'description' => 'Primary key: UUID for the token record',
        ],
        'owner' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'description' => 'User ID that owns this token',
        ],
        'token_hash' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'description' => 'Hashed version of the token for secure storage',
        ],
        'lookup_hash' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'description' => 'Hash for token lookup',
        ],
        'salt' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'description' => 'Salt used for token hashing',
        ],
        'is_session' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'Whether this is a session token (1) or API token (0)',
        ],
        'created_at' => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => 'Unix timestamp when token was created',
        ],
        'expires_at' => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => 'Unix timestamp when token expires',
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'lookup_hash' => ['lookup_hash'],
      ],
      'indexes' => [
        'owner' => ['owner'],
        'is_session' => ['is_session'],
        'expires_at' => ['expires_at'],
      ],
    ];

    if (!$this->database->schema()->tableExists('earth_api_tokens')) {
      $this->database->schema()->createTable('earth_api_tokens', $schema);
    }
  }

  /**
   * Hash a token with salt for secure storage.
   */
  protected function hashToken($token, $salt) {
    return hash_hmac('sha256', $token, $salt);
  }

  /**
   * Generate a lookup hash for token retrieval.
   */
  protected function generateLookupHash($token) {
    // Use a fixed key for lookup hashing (in production this should be an environment variable)
    $lookup_key = 'earth_api_lookup_key';
    return hash_hmac('sha256', $token, $lookup_key);
  }

  /**
   * Generate a secure random token.
   */
  protected function generateToken() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $token = '';
    for ($i = 0; $i < self::API_KEY_LENGTH; $i++) {
      $token .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $token;
  }

  /**
   * Add a new token (API token or session token).
   */
  public function addToken($owner, $expiration_days = null, $is_session = FALSE) {
    if (empty($owner)) {
      throw new \InvalidArgumentException('Owner is required');
    }

    // Set default expiration based on token type
    if ($expiration_days === NULL) {
      $expiration_days = $is_session ? self::SESSION_TOKEN_EXPIRATION : self::API_TOKEN_EXPIRATION;
    }

    // Check token limits
    $count = $this->getTokenCount($owner, $is_session);
    $max_tokens = $is_session ? self::MAX_SESSION_TOKENS : self::MAX_API_TOKENS;
    
    if ($count >= $max_tokens) {
      throw new \Exception(sprintf('Token limit reached for owner. Maximum %d %s tokens allowed.', 
        $max_tokens, $is_session ? 'session' : 'API'));
    }

    // Generate token and hashes
    $token = $this->generateToken();
    $salt = bin2hex(random_bytes(16));
    $token_hash = $this->hashToken($token, $salt);
    $lookup_hash = $this->generateLookupHash($token);

    // Store token
    $id = \Drupal::service('uuid')->generate();
    $now = time();
    $expires_at = $now + ($expiration_days * 24 * 60 * 60);

    $this->database->insert('earth_api_tokens')
      ->fields([
        'id' => $id,
        'owner' => $owner,
        'token_hash' => $token_hash,
        'lookup_hash' => $lookup_hash,
        'salt' => $salt,
        'is_session' => $is_session ? 1 : 0,
        'created_at' => $now,
        'expires_at' => $expires_at,
      ])
      ->execute();

    return $token;
  }

  /**
   * Validate a token and return the owner.
   */
  public function validateToken($token) {
    if (empty($token) || strlen($token) !== self::API_KEY_LENGTH) {
      return NULL;
    }

    // Check admin API key
    $admin_api_key = \Drupal::config('earth_api.settings')->get('admin_api_key');
    if (!empty($admin_api_key) && $token === $admin_api_key) {
      return 'admin';
    }

    try {
      $lookup_hash = $this->generateLookupHash($token);
      
      $query = $this->database->select('earth_api_tokens', 't')
        ->fields('t')
        ->condition('lookup_hash', $lookup_hash)
        ->condition('expires_at', time(), '>')
        ->range(0, 1);
      
      $record = $query->execute()->fetchAssoc();
      
      if (!$record) {
        return NULL;
      }

      // Verify token hash
      $expected_hash = $this->hashToken($token, $record['salt']);
      if (!hash_equals($expected_hash, $record['token_hash'])) {
        return NULL;
      }

      return $record['owner'];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('earth_api')->error('Token validation error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Remove a token.
   */
  public function removeToken($token) {
    if (empty($token)) {
      return FALSE;
    }

    try {
      $lookup_hash = $this->generateLookupHash($token);
      
      // Get token record to verify hash
      $query = $this->database->select('earth_api_tokens', 't')
        ->fields('t')
        ->condition('lookup_hash', $lookup_hash)
        ->range(0, 1);
      
      $record = $query->execute()->fetchAssoc();
      
      if (!$record) {
        return FALSE;
      }

      // Verify token hash before deletion
      $expected_hash = $this->hashToken($token, $record['salt']);
      if (!hash_equals($expected_hash, $record['token_hash'])) {
        return FALSE;
      }

      // Delete token
      $this->database->delete('earth_api_tokens')
        ->condition('id', $record['id'])
        ->execute();

      return TRUE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('earth_api')->error('Token removal error: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Get token count for a user.
   */
  public function getTokenCount($owner, $is_session = FALSE) {
    return $this->database->select('earth_api_tokens', 't')
      ->condition('owner', $owner)
      ->condition('is_session', $is_session ? 1 : 0)
      ->condition('expires_at', time(), '>')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Clean up expired tokens.
   */
  public function cleanupExpiredTokens() {
    $deleted = $this->database->delete('earth_api_tokens')
      ->condition('expires_at', time(), '<=')
      ->execute();
    
    if ($deleted > 0) {
      $this->loggerFactory->get('earth_api')->info('Cleaned up @count expired tokens', ['@count' => $deleted]);
    }
    
    return $deleted;
  }

  /**
   * Create a session token for user login.
   */
  public function createSessionToken($owner) {
    // Clean up old sessions if at limit
    $session_count = $this->getTokenCount($owner, TRUE);
    if ($session_count >= self::MAX_SESSION_TOKENS) {
      $this->removeOldestSession($owner);
    }

    return $this->addToken($owner, self::SESSION_TOKEN_EXPIRATION, TRUE);
  }

  /**
   * Remove the oldest session for a user.
   */
  protected function removeOldestSession($owner) {
    $query = $this->database->select('earth_api_tokens', 't')
      ->fields('t', ['id'])
      ->condition('owner', $owner)
      ->condition('is_session', 1)
      ->condition('expires_at', time(), '>')
      ->orderBy('created_at', 'ASC')
      ->range(0, 1);
    
    $oldest = $query->execute()->fetchField();
    
    if ($oldest) {
      $this->database->delete('earth_api_tokens')
        ->condition('id', $oldest)
        ->execute();
    }
  }
  /**
   * Authenticate user with username and password (Basic Auth).
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
      if (empty($username) || empty($password)) {
        return NULL;
      }

      // Length validation as per original API
      if (strlen($username) < 3 || strlen($username) > 32) {
        return NULL;
      }
      if (strlen($password) < 8 || strlen($password) > 64) {
        return NULL;
      }

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

      // Verify password using proper password hashing
      if ($this->verifyPassword($password, $user)) {
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
   * Verify a password against a user's stored password hash.
   */
  protected function verifyPassword($password, EarthUser $user) {
    $stored_hash = $user->get('password_hash')->value;
    $salt = $user->get('password_salt')->value;
    
    if (empty($stored_hash) || empty($salt)) {
      return FALSE;
    }

    // Use the same password hashing as creation
    $computed_hash = $this->hashPassword($password, base64_decode($salt));
    return hash_equals($stored_hash, $computed_hash);
  }

  /**
   * Hash a password with salt.
   */
  protected function hashPassword($password, $salt) {
    // Use PBKDF2 with SHA-256 (similar to original implementation)
    return base64_encode(hash_pbkdf2('sha256', $password, $salt, 10000, 32, TRUE));
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

      // Validate required fields
      if (empty($user_data['username']) || empty($user_data['password'])) {
        return NULL;
      }

      // Check if username already exists
      $existing_ids = $storage->getQuery()
        ->condition('username', $user_data['username'])
        ->execute();

      if (!empty($existing_ids)) {
        return NULL; // Username already exists
      }

      // Generate password salt and hash
      $salt = random_bytes(16);
      $password_hash = $this->hashPassword($user_data['password'], $salt);

      // Create user entity
      $user = $storage->create([
        'username' => $user_data['username'],
        'email' => $user_data['email'] ?? '',
        'password_hash' => $password_hash,
        'password_salt' => base64_encode($salt),
        'first_name' => $user_data['firstName'] ?? '',
        'last_name' => $user_data['lastName'] ?? '',
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
   * Login a user and create a session token.
   *
   * @param string $username
   *   The username.
   *
   * @return array|null
   *   Login response with user info and session token, or NULL if failed.
   */
  public function loginUser($username) {
    try {
      $user = $this->getUserByUsername($username);
      if (!$user) {
        return NULL;
      }

      // Create session token
      $session_token = $this->createSessionToken($user->id());

      // Update last login
      $user->setLastLogin();
      $user->save();

      return [
        'id' => $user->id(),
        'username' => $user->get('username')->value,
        'session_token' => $session_token,
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('earth_api')->error('Login error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Get user by username.
   */
  public function getUserByUsername($username) {
    $storage = $this->entityTypeManager->getStorage('earth_user');
    $user_ids = $storage->getQuery()
      ->condition('username', $username)
      ->range(0, 1)
      ->execute();

    if (empty($user_ids)) {
      return NULL;
    }

    return $storage->load(reset($user_ids));
  }

  /**
   * Get user by token (Bearer auth).
   */
  public function getUserByToken($token) {
    $owner = $this->validateToken($token);
    if (!$owner || $owner === 'admin') {
      return $owner;
    }

    $storage = $this->entityTypeManager->getStorage('earth_user');
    return $storage->load($owner);
  }

  /**
   * Check if user is admin (either admin API key or admin user).
   */
  public function isAdmin($token_or_user) {
    // Check admin API key
    $admin_api_key = \Drupal::config('earth_api.settings')->get('admin_api_key');
    if (!empty($admin_api_key) && $token_or_user === $admin_api_key) {
      return TRUE;
    }

    // Check if token validates to admin
    if (is_string($token_or_user)) {
      $owner = $this->validateToken($token_or_user);
      return $owner === 'admin';
    }

    // Check if user entity has admin role
    if ($token_or_user instanceof EarthUser) {
      return $token_or_user->get('account_type')->value === 'administrator';
    }

    return FALSE;
  }

}
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