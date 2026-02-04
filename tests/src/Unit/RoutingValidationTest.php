<?php

namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class RoutingValidationTest extends TestCase
{
	private static array $routes;
	private static string $routingFilePath;

	public static function setUpBeforeClass(): void
	{
		self::$routingFilePath = dirname(__DIR__, 3) . '/mantle2.routing.yml';

		if (!file_exists(self::$routingFilePath)) {
			self::fail('Routing file not found: ' . self::$routingFilePath);
		}

		try {
			self::$routes = Yaml::parseFile(self::$routingFilePath);
		} catch (ParseException $e) {
			self::fail('Failed to parse routing YAML: ' . $e->getMessage());
		}
	}

	public static function routeProvider(): array
	{
		if (!isset(self::$routes)) {
			self::setUpBeforeClass();
		}

		$data = [];
		foreach (self::$routes as $routeName => $route) {
			$data[$routeName] = [$routeName, $route];
		}
		return $data;
	}

	#[Test]
	#[TestDox('Routing file should exist and be valid YAML')]
	#[Group('mantle2/routing')]
	public function testRoutingFileIsValidYaml(): void
	{
		$this->assertFileExists(self::$routingFilePath);
		$this->assertIsArray(self::$routes);
		$this->assertNotEmpty(self::$routes);
	}

	#[Test]
	#[TestDox('Route $routeName should have required fields')]
	#[Group('mantle2/routing')]
	#[DataProvider('routeProvider')]
	public function testRouteHasRequiredFields(string $routeName, array $route): void
	{
		$requiredFields = ['path', 'defaults', 'requirements'];

		$this->assertIsArray($route, "Route '$routeName' is not an array");

		foreach ($requiredFields as $field) {
			$this->assertArrayHasKey(
				$field,
				$route,
				"Route '$routeName' is missing required field: $field",
			);
		}
	}

	#[Test]
	#[TestDox('Route $routeName should have valid path format')]
	#[Group('mantle2/routing')]
	#[DataProvider('routeProvider')]
	public function testRouteHasValidPath(string $routeName, array $route): void
	{
		if (!isset($route['path'])) {
			$this->markTestSkipped("Route '$routeName' has no path");
		}

		$path = $route['path'];

		$this->assertStringStartsWith('/', $path, "Route '$routeName' path does not start with /");

		if ($path !== '/') {
			$this->assertStringEndsNotWith(
				'/',
				$path,
				"Route '$routeName' path should not end with /",
			);
		}
	}

	#[Test]
	#[TestDox('Route $routeName should have valid title and controller')]
	#[Group('mantle2/routing')]
	#[DataProvider('routeProvider')]
	public function testRouteHasValidController(string $routeName, array $route): void
	{
		if (!isset($route['defaults'])) {
			$this->markTestSkipped("Route '$routeName' has no defaults");
		}

		$this->assertTrue(
			isset($route['defaults']['_title']) || isset($route['defaults']['_title_callback']),
			"Route '$routeName' defaults missing both _title and _title_callback",
		);

		$this->assertTrue(
			isset($route['defaults']['_controller']) || isset($route['defaults']['_form']),
			"Route '$routeName' defaults missing both _controller and _form",
		);

		if (isset($route['defaults']['_controller'])) {
			$controller = $route['defaults']['_controller'];

			$this->assertStringContainsString(
				'::',
				$controller,
				"Route '$routeName' controller invalid format (missing ::)",
			);

			[$className, $methodName] = explode('::', $controller, 2);
			$className = ltrim($className, '\\');

			$this->assertTrue(
				class_exists($className),
				"Route '$routeName' controller class does not exist: $className",
			);

			$this->assertTrue(
				method_exists($className, $methodName),
				"Route '$routeName' controller method does not exist: $className::$methodName",
			);
		}
	}

	#[Test]
	#[TestDox('Route $routeName should have valid HTTP methods')]
	#[Group('mantle2/routing')]
	#[DataProvider('routeProvider')]
	public function testRouteHasValidMethods(string $routeName, array $route): void
	{
		if (!isset($route['methods'])) {
			$this->markTestSkipped("Route '$routeName' has no methods");
		}

		$validMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
		$methods = $route['methods'];

		$this->assertIsArray($methods, "Route '$routeName' methods is not an array");

		foreach ($methods as $method) {
			$this->assertContains(
				$method,
				$validMethods,
				"Route '$routeName' has invalid HTTP method: $method",
			);
		}
	}

	#[Test]
	#[TestDox('Route $routeName requirements')]
	#[Group('mantle2/routing')]
	#[DataProvider('routeProvider')]
	public function testRouteRequirements(string $routeName, array $route): void
	{
		if (!isset($route['requirements'])) {
			$this->markTestSkipped("Route '$routeName' has no requirements");
		}

		$this->assertArrayHasKey(
			'_access',
			$route['requirements'],
			"Route '$routeName' missing _access requirement",
		);

		if (isset($route['requirements']['id'])) {
			$this->assertEquals(
				'\d+',
				$route['requirements']['id'],
				"Route '$routeName' has invalid id requirement",
			);

			$this->assertStringContainsString(
				'{id}',
				$route['path'],
				"Route '$routeName' has id requirement but no {id} in path",
			);

			$this->assertNotEmpty(
				$route['options']['parameters'],
				"Route '$routeName' missing options.parameters for id",
			);
			$this->assertNotEmpty(
				$route['options']['parameters']['id'],
				"Route '$routeName' missing options.parameters.id",
			);
			$this->assertEquals(
				'string',
				$route['options']['parameters']['id']['type'],
				"Route '$routeName' options.parameters.id should be of type string",
			);
		}

		if (isset($route['requirements']['username'])) {
			$this->assertEquals(
				'@[\w.]+',
				$route['requirements']['username'],
				"Route '$routeName' has invalid username requirement",
			);

			$this->assertStringContainsString(
				'{username}',
				$route['path'],
				"Route '$routeName' has username requirement but no {username} in path",
			);

			$this->assertNotEmpty(
				$route['options']['parameters'],
				"Route '$routeName' missing options.parameters for username",
			);
			$this->assertNotEmpty(
				$route['options']['parameters']['username'],
				"Route '$routeName' missing options.parameters.username",
			);
			$this->assertEquals(
				'string',
				$route['options']['parameters']['username']['type'],
				"Route '$routeName' options.parameters.username should be of type string",
			);
		}
	}

	#[Test]
	#[TestDox('Route $routeName path parameters should have consistent casing')]
	#[Group('mantle2/routing')]
	#[DataProvider('routeProvider')]
	public function testRoutePathParametersConsistent(string $routeName, array $route): void
	{
		if (!isset($route['path'])) {
			$this->markTestSkipped("Route '$routeName' has no path");
		}

		$path = $route['path'];

		preg_match_all('/\{([^}]+)\}/', $path, $matches);
		$pathParams = $matches[1] ?? [];

		if (empty($pathParams)) {
			$this->markTestSkipped("Route '$routeName' has no path parameters");
		}

		foreach ($pathParams as $param) {
			// Parameter names should be lowercase or camelCase
			$hasUpperCase = preg_match('/[A-Z]/', $param);
			$isCamelCase = preg_match('/^[a-z]+[A-Z]/', $param);

			$this->assertFalse(
				$hasUpperCase && !$isCamelCase,
				"Route '$routeName' has parameter with unusual casing: {$param}",
			);
		}
	}

	#[Test]
	#[TestDox('Route $routeName should use correct path prefix')]
	#[Group('mantle2/routing')]
	#[DataProvider('routeProvider')]
	public function testRouteV2PrefixConsistency(string $routeName, array $route): void
	{
		// Skip non-API routes
		$excludedRoutes = ['mantle2.openapi', 'mantle2.swaggerui', 'mantle2.hello', 'mantle2.info'];
		if (in_array($routeName, $excludedRoutes, true)) {
			$this->markTestSkipped("Route '$routeName' is excluded from /v2/ prefix check");
		}

		if (!isset($route['path'])) {
			$this->markTestSkipped("Route '$routeName' has no path");
		}

		$path = $route['path'];
		$this->assertStringStartsWith('/v2/', $path, "Route '$routeName' does not use /v2/ prefix");
	}

	#[Test]
	#[TestDox('Route name $routeName should follow naming conventions')]
	#[Group('mantle2/routing')]
	#[DataProvider('routeProvider')]
	public function testRouteNamingConventions(string $routeName, array $route): void
	{
		$this->assertStringStartsWith(
			'mantle2.',
			$routeName,
			"Route name '$routeName' does not start with 'mantle2.'",
		);

		$this->assertMatchesRegularExpression(
			'/^[a-z0-9._]+$/',
			$routeName,
			"Route name '$routeName' contains invalid characters",
		);
	}

	#[Test]
	#[TestDox('Route $routeName should have tags in options')]
	#[Group('mantle2/routing')]
	#[DataProvider('routeProvider')]
	public function testRouteHasTags(string $routeName, array $route): void
	{
		$excludedRoutes = ['mantle2.openapi', 'mantle2.swaggerui'];
		if (in_array($routeName, $excludedRoutes, true)) {
			$this->markTestSkipped("Route '$routeName' is excluded from tags requirement");
		}

		if (!isset($route['options']['tags'])) {
			$this->markTestIncomplete("Route '$routeName' should have tags in options");
		}

		$this->assertNotEmpty($route['options']['tags'], "Route '$routeName' has empty tags array");
	}

	#[Test]
	#[TestDox('Route $routeName schema references should be valid')]
	#[Group('mantle2/routing')]
	#[DataProvider('routeProvider')]
	public function testRouteSchemaReferencesValid(string $routeName, array $route): void
	{
		if (!isset($route['options'])) {
			$this->markTestSkipped("Route '$routeName' has no options");
		}

		$schemasClass = 'Drupal\\mantle2\\Controller\\Schema\\Mantle2Schemas';
		$this->assertTrue(class_exists($schemasClass), 'Mantle2Schemas class not found');

		// Check schema/200 reference
		if (isset($route['options']['schema/200'])) {
			$schemaRef = $route['options']['schema/200'];

			// Skip if it's just a type specification like "text/plain"
			if (!is_string($schemaRef) || str_contains($schemaRef, '/')) {
				return;
			}

			$error = $this->validateSchemaReference(
				$schemasClass,
				$schemaRef,
				$routeName,
				'schema/200',
			);
			$this->assertNull($error, $error ?? '');
		}

		// Check body/schema reference
		if (isset($route['options']['body/schema'])) {
			$schemaRef = $route['options']['body/schema'];

			if (is_string($schemaRef)) {
				$error = $this->validateSchemaReference(
					$schemasClass,
					$schemaRef,
					$routeName,
					'body/schema',
				);
				$this->assertNull($error, $error ?? '');
			}
		}
	}

	/**
	 * Validate a schema reference points to a valid method or property in Mantle2Schemas
	 */
	private function validateSchemaReference(
		string $schemasClass,
		string $schemaRef,
		string $routeName,
		string $fieldName,
	): ?string {
		// Check if it's a method call (ends with ())
		if (str_ends_with($schemaRef, '()')) {
			$methodName = substr($schemaRef, 0, -2);

			// Check if method exists
			if (!method_exists($schemasClass, $methodName)) {
				return "Route '$routeName' $fieldName references non-existent method: $schemaRef";
			}

			// Verify it's a public static method
			try {
				$reflection = new \ReflectionMethod($schemasClass, $methodName);
				if (!$reflection->isPublic()) {
					return "Route '$routeName' $fieldName method is not public: $schemaRef";
				}
				if (!$reflection->isStatic()) {
					return "Route '$routeName' $fieldName method is not static: $schemaRef";
				}
			} catch (\ReflectionException $e) {
				return "Route '$routeName' $fieldName reflection error: " . $e->getMessage();
			}
		} else {
			// It's a property reference (should start with $)
			if (!str_starts_with($schemaRef, '$')) {
				// Not a recognized format
				return "Route '$routeName' $fieldName has unrecognized format: $schemaRef (expected method() or \$property)";
			}

			$propertyName = substr($schemaRef, 1);

			// Check if property exists
			if (!property_exists($schemasClass, $propertyName)) {
				return "Route '$routeName' $fieldName references non-existent property: $schemaRef";
			}

			// Verify it's a public static property
			try {
				$reflection = new \ReflectionProperty($schemasClass, $propertyName);
				if (!$reflection->isPublic()) {
					return "Route '$routeName' $fieldName property is not public: $schemaRef";
				}
				if (!$reflection->isStatic()) {
					return "Route '$routeName' $fieldName property is not static: $schemaRef";
				}
			} catch (\ReflectionException $e) {
				return "Route '$routeName' $fieldName reflection error: " . $e->getMessage();
			}
		}

		return null;
	}

	#[Test]
	#[TestDox('No duplicate paths should exist')]
	#[Group('mantle2/routing')]
	public function testNoDuplicatePaths(): void
	{
		$pathsToRoutes = [];

		foreach (self::$routes as $routeName => $route) {
			if (!isset($route['path']) || !isset($route['methods'])) {
				continue;
			}

			$path = $route['path'];
			$methods = is_array($route['methods']) ? $route['methods'] : [$route['methods']];

			foreach ($methods as $method) {
				$key = "$method $path";

				if (!isset($pathsToRoutes[$key])) {
					$pathsToRoutes[$key] = [];
				}

				$pathsToRoutes[$key][] = $routeName;
			}
		}

		$duplicates = array_filter($pathsToRoutes, fn($routes) => count($routes) > 1);

		$this->assertEmpty(
			$duplicates,
			"Duplicate path/method combinations found:\n" . print_r($duplicates, true),
		);
	}

	#[Test]
	#[TestDox('Count total number of routes')]
	#[Group('mantle2/routing')]
	public function testRoutesCount(): void
	{
		$count = count(self::$routes);

		// Should have a reasonable number of routes
		$this->assertGreaterThan(50, $count, 'Should have more than 50 routes');
		$this->assertLessThan(500, $count, 'Should have less than 500 routes');

		echo "\nTotal routes defined: $count";
	}

	#[Test]
	#[TestDox('Validate route structure by category')]
	#[Group('mantle2/routing')]
	public function testRouteCategorization(): void
	{
		$categories = [
			'users' => 0,
			'activities' => 0,
			'events' => 0,
			'prompts' => 0,
			'articles' => 0,
			'oauth' => 0,
			'other' => 0,
		];

		foreach (self::$routes as $routeName => $route) {
			if (str_contains($routeName, 'users')) {
				$categories['users']++;
			} elseif (str_contains($routeName, 'activities')) {
				$categories['activities']++;
			} elseif (str_contains($routeName, 'events')) {
				$categories['events']++;
			} elseif (str_contains($routeName, 'prompts')) {
				$categories['prompts']++;
			} elseif (str_contains($routeName, 'articles')) {
				$categories['articles']++;
			} elseif (str_contains($routeName, 'oauth')) {
				$categories['oauth']++;
			} else {
				$categories['other']++;
			}
		}

		echo "\nRoute distribution:\n";
		foreach ($categories as $category => $count) {
			echo "  - $category: $count\n";
		}

		$this->assertGreaterThan(0, $categories['users'], 'Should have user routes');
		$this->assertGreaterThan(0, $categories['activities'], 'Should have activity routes');
		$this->assertGreaterThan(0, $categories['events'], 'Should have event routes');
	}
}
