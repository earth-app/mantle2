<?php

namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class ServicesValidationTest extends TestCase
{
	private static array $services;
	private static string $servicesFilePath;

	public static function setUpBeforeClass(): void
	{
		self::$servicesFilePath = dirname(__DIR__, 3) . '/mantle2.services.yml';

		if (!file_exists(self::$servicesFilePath)) {
			self::fail('Services file not found: ' . self::$servicesFilePath);
		}

		try {
			$data = Yaml::parseFile(self::$servicesFilePath);
			self::$services = $data['services'] ?? [];
		} catch (ParseException $e) {
			self::fail('Failed to parse services YAML: ' . $e->getMessage());
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
	#[TestDox('Services file should exist and be valid YAML')]
	#[Group('mantle2/services')]
	public function testServicesFileIsValidYaml(): void
	{
		$this->assertFileExists(self::$servicesFilePath);
		$this->assertIsArray(self::$services);
		$this->assertNotEmpty(self::$services);
	}

	#[Test]
	#[TestDox('Service $serviceName should have class defined')]
	#[Group('mantle2/services')]
	#[DataProvider('serviceProvider')]
	public function testServiceHasClass(string $serviceName, array $service): void
	{
		$this->assertArrayHasKey(
			'class',
			$service,
			"Service '$serviceName' is missing 'class' definition",
		);
		$this->assertNotEmpty($service['class'], "Service '$serviceName' has empty class");
	}

	#[Test]
	#[TestDox('Service $serviceName class should exist')]
	#[Group('mantle2/services')]
	#[DataProvider('serviceProvider')]
	public function testServiceClassExists(string $serviceName, array $service): void
	{
		if (!isset($service['class'])) {
			$this->markTestSkipped("Service '$serviceName' has no class defined");
		}

		$className = $service['class'];

		// ignore classes that start with Drupal\
		if (str_starts_with($className, 'Drupal\\')) {
			$this->markTestSkipped(
				"Service '$serviceName' class is a Drupal core class: $className",
			);
		}

		$this->assertTrue(
			class_exists($className),
			"Service '$serviceName' class does not exist: $className",
		);
	}

	#[Test]
	#[TestDox('Service $serviceName should follow naming conventions')]
	#[Group('mantle2/services')]
	#[DataProvider('serviceProvider')]
	public function testServiceNamingConventions(string $serviceName, array $service): void
	{
		// ignore cache bin service
		if ($serviceName === 'cache.mantle2') {
			$this->markTestSkipped("Skipping cache bin service '$serviceName'");
		}

		$this->assertStringStartsWith(
			'mantle2.',
			$serviceName,
			"Service name '$serviceName' does not start with 'mantle2.'",
		);

		$this->assertMatchesRegularExpression(
			'/^[a-z0-9._]+$/',
			$serviceName,
			"Service name '$serviceName' contains invalid characters",
		);

		$this->assertNotEmpty($service, "Service '$serviceName' definition is empty");
		$this->assertArrayHasKey(
			'class',
			$service,
			"Service '$serviceName' is missing 'class' key",
		);
		$this->assertNotEmpty($service['class'], "Service '$serviceName' has empty 'class' value");
	}

	#[Test]
	#[TestDox('Service $serviceName tags should be valid')]
	#[Group('mantle2/services')]
	#[DataProvider('serviceProvider')]
	public function testServiceTagsValid(string $serviceName, array $service): void
	{
		if (!isset($service['tags'])) {
			$this->markTestSkipped("Service '$serviceName' has no tags");
		}

		$this->assertIsArray($service['tags'], "Service '$serviceName' tags is not an array");

		foreach ($service['tags'] as $tag) {
			$this->assertIsArray($tag, "Service '$serviceName' tag is not an array");
			$this->assertArrayHasKey('name', $tag, "Service '$serviceName' tag missing 'name' key");
		}
	}

	#[Test]
	#[TestDox('Service $serviceName arguments should be valid')]
	#[Group('mantle2/services')]
	#[DataProvider('serviceProvider')]
	public function testServiceArgumentsValid(string $serviceName, array $service): void
	{
		if (!isset($service['arguments'])) {
			$this->markTestSkipped("Service '$serviceName' has no arguments");
		}

		$this->assertIsArray(
			$service['arguments'],
			"Service '$serviceName' arguments is not an array",
		);

		foreach ($service['arguments'] as $argument) {
			$this->assertIsString($argument, "Service '$serviceName' has non-string argument");

			// Service references should start with @
			if (str_starts_with($argument, '@')) {
				$this->assertMatchesRegularExpression(
					'/^@[a-z0-9._]+$/',
					$argument,
					"Service '$serviceName' has invalid service reference: $argument",
				);
			}
		}
	}

	#[Test]
	#[TestDox('Event subscriber services should have correct tag')]
	#[Group('mantle2/services')]
	public function testEventSubscriberServices(): void
	{
		$subscriberCount = 0;

		foreach (self::$services as $serviceName => $service) {
			if (isset($service['tags'])) {
				foreach ($service['tags'] as $tag) {
					if (isset($tag['name']) && $tag['name'] === 'event_subscriber') {
						$subscriberCount++;

						// Verify class name ends with Subscriber
						$this->assertStringEndsWith(
							'Subscriber',
							$service['class'],
							"Event subscriber service '$serviceName' class should end with 'Subscriber'",
						);
					}
				}
			}
		}

		$this->assertGreaterThan(0, $subscriberCount, 'Should have at least one event subscriber');
	}

	#[Test]
	#[TestDox('Count total number of services')]
	#[Group('mantle2/services')]
	public function testServicesCount(): void
	{
		$count = count(self::$services);

		$this->assertGreaterThan(5, $count, 'Should have more than 5 services');
		$this->assertLessThan(100, $count, 'Should have less than 100 services');

		echo "\nTotal services defined: $count";
	}

	#[Test]
	#[TestDox('Validate service structure by category')]
	#[Group('mantle2/services')]
	public function testServiceCategorization(): void
	{
		$categories = [
			'subscribers' => 0,
			'helpers' => 0,
			'tempstore' => 0,
			'other' => 0,
		];

		foreach (self::$services as $serviceName => $service) {
			if (str_contains($serviceName, 'subscriber')) {
				$categories['subscribers']++;
			} elseif (str_contains($serviceName, 'helper')) {
				$categories['helpers']++;
			} elseif (str_contains($serviceName, 'tempstore')) {
				$categories['tempstore']++;
			} else {
				$categories['other']++;
			}
		}

		echo "\nService distribution:\n";
		foreach ($categories as $category => $count) {
			echo "  - $category: $count\n";
		}

		$this->assertGreaterThan(0, $categories['subscribers'], 'Should have event subscribers');
		$this->assertGreaterThan(0, $categories['helpers'], 'Should have helper services');
	}
}
