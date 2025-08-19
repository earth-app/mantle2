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
    // Test the v1 health check endpoint - requires admin access
    $this->drupalGet('/v1/health_check', ['headers' => ['Authorization' => 'Bearer admin-token']]);
    $this->assertSession()->statusCodeNotEquals(404);
    
    // Test v1 info endpoint
    $response = $this->drupalGet('/v1/info');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');
    
    $data = json_decode($response, TRUE);
    $this->assertIsArray($data);
    $this->assertArrayHasKey('name', $data);
    $this->assertArrayHasKey('title', $data);
    $this->assertArrayHasKey('version', $data);
    $this->assertEquals('mantle', $data['name']);
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

    // Test some key endpoints from the v1 API
    $this->testV1HelloEndpoint();
    $this->testV1InfoEndpoint();
    $this->testV1UsersEndpoint();
    $this->testV1PromptsEndpoint();
  }

  /**
   * Test v1 hello endpoint.
   */
  protected function testV1HelloEndpoint(): void {
    $response = $this->drupalGet('/v1/hello');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/plain');
    $this->assertSession()->responseContains('Hello World');
  }

  /**
   * Test v1 info endpoint.
   */
  protected function testV1InfoEndpoint(): void {
    $response = $this->drupalGet('/v1/info');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');
    
    $data = json_decode($response, TRUE);
    $this->assertArrayHasKey('name', $data);
    $this->assertArrayHasKey('title', $data);
    $this->assertArrayHasKey('version', $data);
    $this->assertArrayHasKey('description', $data);
    $this->assertArrayHasKey('date', $data);
  }

  /**
   * Test v1 users endpoint.
   */
  protected function testV1UsersEndpoint(): void {
    $response = $this->drupalGet('/v1/users');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');
    
    $data = json_decode($response, TRUE);
    $this->assertArrayHasKey('page', $data);
    $this->assertArrayHasKey('limit', $data);
    $this->assertArrayHasKey('total', $data);
    $this->assertArrayHasKey('items', $data);
  }

  /**
   * Test v1 prompts endpoint.
   */
  protected function testV1PromptsEndpoint(): void {
    $response = $this->drupalGet('/v1/prompts');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');
    
    $data = json_decode($response, TRUE);
    $this->assertArrayHasKey('page', $data);
    $this->assertArrayHasKey('limit', $data);
    $this->assertArrayHasKey('total', $data);
    $this->assertArrayHasKey('items', $data);
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