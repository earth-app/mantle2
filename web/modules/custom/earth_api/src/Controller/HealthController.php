<?php

namespace Drupal\earth_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Health check controller for API status monitoring.
 */
class HealthController extends ControllerBase {

  /**
   * Returns health status of the API.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with health status.
   */
  public function check(): JsonResponse {
    $response_data = [
      'status' => 'healthy',
      'timestamp' => date('c'),
      'version' => '1.0.0',
      'service' => 'mantle2',
    ];

    return new JsonResponse($response_data, 200, [
      'Content-Type' => 'application/json',
      'Cache-Control' => 'no-cache',
    ]);
  }

}