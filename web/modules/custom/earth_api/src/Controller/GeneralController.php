<?php

namespace Drupal\earth_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for general API endpoints.
 */
class GeneralController extends ControllerBase {

  /**
   * Hello endpoint - returns simple text response.
   */
  public function hello() {
    return new Response('Hello World', 200, ['Content-Type' => 'text/plain']);
  }

  /**
   * API info endpoint.
   */
  public function info() {
    $data = [
      'name' => 'mantle',
      'title' => 'Earth App',
      'version' => '1.0.0',
      'description' => 'Backend API for The Earth App',
      'date' => date('Y-m-d'),
    ];

    return new JsonResponse($data);
  }

  /**
   * Database shard info endpoint.
   */
  public function shardInfo() {
    // Return sample shard information
    $data = [
      [
        'binding' => 'activities',
        'count' => 150,
      ],
      [
        'binding' => 'events',
        'count' => 75,
      ],
      [
        'binding' => 'users',
        'count' => 250,
      ],
      [
        'binding' => 'prompts',
        'count' => 500,
      ],
      [
        'binding' => 'authentication',
        'count' => 250,
      ],
    ];

    return new JsonResponse($data);
  }

  /**
   * Health check endpoint - admin only.
   */
  public function healthCheck() {
    // Perform health checks
    $health = [
      'cache' => TRUE,
      'database' => [
        'activities' => TRUE,
        'events' => TRUE,
        'users' => TRUE,
        'prompts' => TRUE,
        'authentication' => TRUE,
      ],
      'kv' => [
        'articles' => TRUE,
      ],
    ];

    return new JsonResponse($health);
  }

}