<?php

namespace Drupal\earth_api\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessCheckInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Checks access for admin-only endpoints.
 */
class AdminAccessCheck implements AccessCheckInterface {

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
    
    // TODO: Implement proper admin token validation
    // For now, accept any non-empty token as admin
    if (empty($token)) {
      return AccessResult::forbidden('Invalid bearer token');
    }

    return AccessResult::allowed();
  }

}