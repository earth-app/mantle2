<?php

namespace Drupal\earth_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * OpenAPI specification controller.
 */
class OpenApiController extends ControllerBase {

  /**
   * Returns the OpenAPI specification.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with OpenAPI specification.
   */
  public function spec(): JsonResponse {
    $module_path = \Drupal::service('extension.list.module')->getPath('earth_api');
    $spec_file = DRUPAL_ROOT . '/../tests/contract/openapi.json';
    
    if (file_exists($spec_file)) {
      $spec_content = file_get_contents($spec_file);
      $spec_data = json_decode($spec_content, TRUE);
      
      if ($spec_data) {
        return new JsonResponse($spec_data, 200, [
          'Content-Type' => 'application/json',
          'Cache-Control' => 'public, max-age=3600',
        ]);
      }
    }
    
    // Fallback minimal spec
    $fallback_spec = [
      'openapi' => '3.0.0',
      'info' => [
        'title' => 'Earth API',
        'version' => '1.0.0',
        'description' => 'Earth application API',
      ],
      'paths' => [
        '/api/health' => [
          'get' => [
            'summary' => 'Health check',
            'responses' => [
              '200' => [
                'description' => 'Service is healthy',
                'content' => [
                  'application/json' => [
                    'schema' => [
                      'type' => 'object',
                      'properties' => [
                        'status' => ['type' => 'string'],
                        'timestamp' => ['type' => 'string'],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    return new JsonResponse($fallback_spec, 200, [
      'Content-Type' => 'application/json',
      'Cache-Control' => 'public, max-age=300',
    ]);
  }

}