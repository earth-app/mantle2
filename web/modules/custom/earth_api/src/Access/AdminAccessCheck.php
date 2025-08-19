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
    
    // Check if token is admin (either admin API key or admin user)
    if (!$this->authManager->isAdmin($token)) {
      return AccessResult::forbidden('Admin access required');
    }

    // Get user (or admin) for context
    $user = $this->authManager->getUserByToken($token);
    $request->attributes->set('current_user', $user);

    return AccessResult::allowed();
  }

}