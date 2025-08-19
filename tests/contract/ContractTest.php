<?php

namespace Drupal\Tests\earth_api\Functional;

use Drupal\Tests\BrowserTestBase;
use GuzzleHttp\Client;

/**
 * Tests API contract compliance with OpenAPI specification.
 *
 * @group earth_api
 */
class ContractTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['earth_api', 'serialization', 'hal', 'rest'];

  /**
   * The OpenAPI specification.
   *
   * @var array
   */
  protected $openApiSpec;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    
    // Load OpenAPI specification
    $spec_file = DRUPAL_ROOT . '/../tests/contract/openapi.json';
    if (file_exists($spec_file)) {
      $spec_content = file_get_contents($spec_file);
      $this->openApiSpec = json_decode($spec_content, TRUE);
    }
  }

  /**
   * Test health endpoint compliance.
   */
  public function testHealthEndpoint(): void {
    $response = $this->drupalGet('/api/health');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('Content-Type', 'application/json');
    
    $data = json_decode($response, TRUE);
    $this->assertIsArray($data);
    $this->assertArrayHasKey('status', $data);
    $this->assertArrayHasKey('timestamp', $data);
    $this->assertEquals('healthy', $data['status']);
  }

  /**
   * Test OpenAPI endpoint availability.
   */
  public function testOpenApiEndpoint(): void {
    $response = $this->drupalGet('/openapi');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderEquals('Content-Type', 'application/json');
    
    $data = json_decode($response, TRUE);
    $this->assertIsArray($data);
    $this->assertArrayHasKey('openapi', $data);
    $this->assertArrayHasKey('info', $data);
    $this->assertArrayHasKey('paths', $data);
  }

  /**
   * Test API endpoints match OpenAPI specification.
   */
  public function testApiEndpointsMatchSpec(): void {
    if (!$this->openApiSpec || !isset($this->openApiSpec['paths'])) {
      $this->markTestSkipped('OpenAPI specification not available');
      return;
    }

    foreach ($this->openApiSpec['paths'] as $path => $methods) {
      foreach ($methods as $method => $spec) {
        $this->validateEndpoint($path, strtoupper($method), $spec);
      }
    }
  }

  /**
   * Validate individual endpoint against specification.
   *
   * @param string $path
   *   The API path.
   * @param string $method
   *   The HTTP method.
   * @param array $spec
   *   The endpoint specification.
   */
  protected function validateEndpoint(string $path, string $method, array $spec): void {
    // For now, just check that endpoints are accessible
    // Full validation would require authentication setup and response schema validation
    if ($method === 'GET' && in_array($path, ['/api/health', '/openapi'])) {
      $response = $this->drupalGet($path);
      $this->assertSession()->statusCodeNotEquals(404, "Endpoint $path should be accessible");
    }
  }

}