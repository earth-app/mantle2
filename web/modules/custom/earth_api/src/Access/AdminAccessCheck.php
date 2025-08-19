<?php

namespace Drupal\earth_api\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessCheckInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\earth_api\Service\AuthManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Checks access for admin-only endpoints.
 */
class AdminAccessCheck implements AccessCheckInterface {

  /**
   * The auth manager.
   *
   * @var \Drupal\earth_api\Service\AuthManager
   */
  protected $authManager;

  /**
   * Constructs a new AdminAccessCheck.
   *
   * @param \Drupal\earth_api\Service\AuthManager $auth_manager
   *   The auth manager.
   */
  public function __construct(AuthManager $auth_manager) {
    $this->authManager = $auth_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Route $route) {
    return TRUE;
  }

  /**
   * Checks access for admin-only operations.
   */
  public function access(Route $route, RouteMatchInterface $route_match, Request $request) {
    $authorization = $request->headers->get('Authorization');
    
    if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
      return AccessResult::forbidden('Bearer token required');
    }

    $token = substr($authorization, 7);
    
    // Validate token and check admin status
    $user = $this->authManager->validateSessionToken($token);
    if (!$user) {
      return AccessResult::forbidden('Invalid bearer token');
    }

    if (!$this->authManager->isAdmin($user)) {
      return AccessResult::forbidden('Admin access required');
    }

    // Store user in request attributes for controllers to use
    $request->attributes->set('current_user', $user);

    return AccessResult::allowed();
  }

}