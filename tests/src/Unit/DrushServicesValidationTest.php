<?php

namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class DrushServicesValidationTest extends TestCase
{
	private static array $services;
	private static string $servicesFilePath;

	public static function setUpBeforeClass(): void
	{
		self::$servicesFilePath = dirname(__DIR__, 3) . '/drush.services.yml';

		if (!file_exists(self::$servicesFilePath)) {
			self::fail('Drush services file not found: ' . self::$servicesFilePath);
		}

		try {
			$data = Yaml::parseFile(self::$servicesFilePath);
			self::$services = $data['services'] ?? [];
		} catch (ParseException $e) {
			self::fail('Failed to parse drush services YAML: ' . $e->getMessage());
		}
	}

	public static function serviceProvider(): array
	{
		// Load services if not already loaded
		if (!isset(self::$services)) {
			self::setUpBeforeClass();
		}

		$data = [];
		foreach (self::$services as $serviceName => $service) {
			$data[$serviceName] = [$serviceName, $service];
		}
		return $data;
	}

	#[Test]
	#[TestDox('Drush services file should exist and be valid YAML')]
	#[Group('mantle2/drush')]
	public function testDrushServicesFileIsValidYaml(): void
	{
		$this->assertFileExists(self::$servicesFilePath);
		$this->assertIsArray(self::$services);
		$this->assertNotEmpty(self::$services);
	}

	#[Test]
	#[TestDox('Drush service $serviceName should have class defined')]
	#[Group('mantle2/drush')]
	#[DataProvider('serviceProvider')]
	public function testDrushServiceHasClass(string $serviceName, array $service): void
	{
		$this->assertArrayHasKey(
			'class',
			$service,
			"Drush service '$serviceName' is missing 'class' definition",
		);
		$this->assertNotEmpty($service['class'], "Drush service '$serviceName' has empty class");
	}

	#[Test]
	#[TestDox('Drush service $serviceName class should exist')]
	#[Group('mantle2/drush')]
	#[DataProvider('serviceProvider')]
	public function testDrushServiceClassExists(string $serviceName, array $service): void
	{
		if (!isset($service['class'])) {
			$this->markTestSkipped("Drush service '$serviceName' has no class defined");
		}

		$className = $service['class'];
		$this->assertTrue(
			class_exists($className),
			"Drush service '$serviceName' class does not exist: $className",
		);
	}

	#[Test]
	#[TestDox('Drush service $serviceName should have drush.command tag')]
	#[Group('mantle2/drush')]
	#[DataProvider('serviceProvider')]
	public function testDrushServiceHasCommandTag(string $serviceName, array $service): void
	{
		$this->assertArrayHasKey(
			'tags',
			$service,
			"Drush service '$serviceName' is missing 'tags'",
		);

		$this->assertIsArray($service['tags'], "Drush service '$serviceName' tags is not an array");

		$hasDrushCommandTag = false;
		foreach ($service['tags'] as $tag) {
			if (isset($tag['name']) && $tag['name'] === 'drush.command') {
				$hasDrushCommandTag = true;
				break;
			}
		}

		$this->assertTrue(
			$hasDrushCommandTag,
			"Drush service '$serviceName' does not have 'drush.command' tag",
		);
	}

	#[Test]
	#[TestDox('Drush service $serviceName should follow naming conventions')]
	#[Group('mantle2/drush')]
	#[DataProvider('serviceProvider')]
	public function testDrushServiceNamingConventions(string $serviceName, array $service): void
	{
		$this->assertStringStartsWith(
			'mantle2.',
			$serviceName,
			"Drush service name '$serviceName' does not start with 'mantle2.'",
		);

		$this->assertMatchesRegularExpression(
			'/^[a-z0-9._]+$/',
			$serviceName,
			"Drush service name '$serviceName' contains invalid characters",
		);

		// Drush command services should have 'commands' in the name
		$this->assertStringContainsString(
			'command',
			$serviceName,
			"Drush service name '$serviceName' should contain 'command'",
		);
	}

	#[Test]
	#[TestDox('Drush service $serviceName class should end with Commands')]
	#[Group('mantle2/drush')]
	#[DataProvider('serviceProvider')]
	public function testDrushServiceClassNaming(string $serviceName, array $service): void
	{
		if (!isset($service['class'])) {
			$this->markTestSkipped("Drush service '$serviceName' has no class defined");
		}

		$className = $service['class'];
		$this->assertStringEndsWith(
			'Commands',
			$className,
			"Drush service '$serviceName' class should end with 'Commands': $className",
		);
	}

	#[Test]
	#[TestDox('Count total number of drush services')]
	#[Group('mantle2/drush')]
	public function testDrushServicesCount(): void
	{
		$count = count(self::$services);

		$this->assertGreaterThan(0, $count, 'Should have at least one drush service');
		$this->assertLessThan(20, $count, 'Should have less than 20 drush services');

		echo "\nTotal drush services defined: $count";
	}
}
