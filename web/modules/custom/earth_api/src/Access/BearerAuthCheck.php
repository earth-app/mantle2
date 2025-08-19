<?php

namespace Drupal\earth_api\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessCheckInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Checks access for Bearer token authentication.
 */
class BearerAuthCheck implements AccessCheckInterface {

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
    
    // TODO: Implement proper token validation
    // For now, accept any non-empty token
    if (empty($token)) {
      return AccessResult::forbidden('Invalid bearer token');
    }

    return AccessResult::allowed();
  }

}