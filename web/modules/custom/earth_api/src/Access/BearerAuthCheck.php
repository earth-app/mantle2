<?php

namespace Drupal\earth_api\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessCheckInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\earth_api\Service\AuthManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Checks access for Bearer token authentication.
 */
class BearerAuthCheck implements AccessCheckInterface {

  /**
   * The auth manager.
   *
   * @var \Drupal\earth_api\Service\AuthManager
   */
  protected $authManager;

  /**
   * Constructs a new BearerAuthCheck.
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
   * Checks access based on Bearer token.
   */
  public function access(Route $route, RouteMatchInterface $route_match, Request $request) {
    $authorization = $request->headers->get('Authorization');
    
    if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
      return AccessResult::forbidden('Bearer token required');
    }

    $token = substr($authorization, 7);
    
    // Validate token using AuthManager (works for both API tokens and session tokens)
    $user = $this->authManager->getUserByToken($token);
    
    if (!$user) {
      return AccessResult::forbidden('Invalid bearer token');
    }

    // Store user in request attributes for controllers to use
    // For admin tokens, store 'admin' string instead of user object
    $request->attributes->set('current_user', $user);

    return AccessResult::allowed();
  }

}