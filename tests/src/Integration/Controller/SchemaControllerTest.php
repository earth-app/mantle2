<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\Schema\OpenAPIController;
use Drupal\mantle2\Controller\Schema\SwaggerUIController;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class SchemaControllerTest extends IntegrationTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		// register mantle2.routing.yml so the route provider + Url::fromRoute resolve
		$this->container->get('router.builder')->rebuild();
	}

	#[Test]
	#[TestDox('GET /openapi builds a valid OpenAPI 3.1 document with resolvable schema refs')]
	#[Group('mantle2/schema')]
	public function openApiDocumentIsValid(): void
	{
		$controller = OpenAPIController::create($this->container);
		$response = $controller->getSchema();
		$this->assertSame(200, $response->getStatusCode());

		$doc = json_decode($response->getContent(), true);
		$this->assertIsArray($doc);
		$this->assertSame('3.1.0', $doc['openapi']);
		$this->assertArrayHasKey('info', $doc);
		$this->assertNotEmpty($doc['info']['title']);
		$this->assertArrayHasKey('security', $doc);
		$this->assertArrayHasKey('BasicAuth', $doc['components']['securitySchemes']);
		$this->assertArrayHasKey('BearerAuth', $doc['components']['securitySchemes']);

		$schemas = $doc['components']['schemas'];
		$this->assertNotEmpty($schemas);

		$paths = $doc['paths'];
		$this->assertNotEmpty($paths);
		foreach (array_keys($paths) as $path) {
			$this->assertStringStartsWith('/v2/', $path, "non-v2 path leaked into schema: $path");
		}

		foreach ($paths as $path => $item) {
			foreach ($item as $method => $op) {
				$this->assertArrayHasKey('responses', $op, "$method $path missing responses");
				$this->assertNotEmpty($op['responses'], "$method $path has empty responses");
			}
		}

		$refs = [];
		$this->collectRefs($doc, $refs);
		$this->assertNotEmpty($refs);
		$componentRefs = array_filter(
			array_unique($refs),
			fn($ref) => str_starts_with($ref, '#/components/schemas/'),
		);
		$this->assertNotEmpty($componentRefs);
		foreach ($componentRefs as $ref) {
			$name = substr($ref, strlen('#/components/schemas/'));
			$this->assertArrayHasKey($name, $schemas, "dangling schema ref: $ref");
		}
	}

	#[Test]
	#[TestDox('every declared component schema is itself internally consistent')]
	#[Group('mantle2/schema')]
	public function componentSchemasSelfConsistent(): void
	{
		$doc = json_decode(
			OpenAPIController::create($this->container)->getSchema()->getContent(),
			true,
		);
		$schemas = $doc['components']['schemas'];

		$refs = [];
		$this->collectRefs($schemas, $refs);
		$componentRefs = array_filter(
			array_unique($refs),
			fn($ref) => str_starts_with($ref, '#/components/schemas/'),
		);
		foreach ($componentRefs as $ref) {
			$name = substr($ref, strlen('#/components/schemas/'));
			$this->assertArrayHasKey(
				$name,
				$schemas,
				"component schema references missing schema: $ref",
			);
		}
	}

	#[Test]
	#[TestDox('GET /swagger-ui renders the Swagger UI shell pointed at /openapi')]
	#[Group('mantle2/schema')]
	public function swaggerUiRenders(): void
	{
		$response = new SwaggerUIController()->getSwaggerUI();
		$this->assertSame(200, $response->getStatusCode());
		$this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));

		$html = $response->getContent();
		$this->assertStringContainsString('id="swagger-ui"', $html);
		$this->assertStringContainsString('SwaggerUIBundle', $html);
		$this->assertStringContainsString("url: '/openapi'", $html);
	}

	private function collectRefs(mixed $node, array &$out): void
	{
		if (!is_array($node)) {
			return;
		}
		foreach ($node as $key => $value) {
			if ($key === '$ref' && is_string($value)) {
				$out[] = $value;
			} else {
				$this->collectRefs($value, $out);
			}
		}
	}
}
