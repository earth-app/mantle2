<?php

namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Structural rules for mantle2.install that no runtime test can catch.
 */
class InstallSchemaValidationTest extends TestCase
{
	private static string $source;

	public static function setUpBeforeClass(): void
	{
		$path = dirname(__DIR__, 3) . '/mantle2.install';
		if (!file_exists($path)) {
			self::fail('Install file not found: ' . $path);
		}
		self::$source = file_get_contents($path);
	}

	private static function functionBody(string $name): string
	{
		$start = strpos(self::$source, "function $name(");
		if ($start === false) {
			self::fail("Function $name() not found in mantle2.install");
		}

		$open = strpos(self::$source, '{', $start);
		$depth = 0;
		for ($i = $open; $i < strlen(self::$source); $i++) {
			if (self::$source[$i] === '{') {
				$depth++;
			} elseif (self::$source[$i] === '}') {
				$depth--;
				if ($depth === 0) {
					return substr(self::$source, $open, $i - $open + 1);
				}
			}
		}

		self::fail("Could not find the end of $name()");
	}

	#[Test]
	#[TestDox('mantle2 declares no hook_schema, so an uninstall cannot drop any table')]
	#[Group('mantle2/install')]
	public function testNoSchemaHook(): void
	{
		$this->assertStringNotContainsString(
			'function mantle2_schema(',
			self::$source,
			'Anything returned from hook_schema is dropped by `drush un`. Register tables in ' .
				'mantle2_ensure_custom_tables() instead.',
		);
	}

	private const TABLES = [
		'push_tokens',
		'mantle2_api_keys',
		'mantle2_subscriptions',
		'mantle2_trial_codes',
		'mantle2_trial_code_redemptions',
		'mantle2_staged_activities',
	];

	#[Test]
	#[TestDox('Every table in mantle2_ensure_custom_tables() has a matching *_table_schema()')]
	#[Group('mantle2/install')]
	public function testEveryTableHasSchemaFunction(): void
	{
		$body = self::functionBody('mantle2_ensure_custom_tables');

		preg_match_all("/'([a-z0-9_]+)'\s*=>\s*(mantle2_[a-z0-9_]+)\(\)/", $body, $matches);
		$this->assertNotEmpty($matches[1], 'No tables declared in mantle2_ensure_custom_tables()');

		foreach ($matches[2] as $function) {
			$this->assertStringContainsString(
				"function $function(",
				self::$source,
				"$function() is referenced but not defined",
			);
		}

		foreach (self::TABLES as $table) {
			$this->assertContains(
				$table,
				$matches[1],
				"$table is not created by mantle2_ensure_custom_tables()",
			);
		}
	}

	#[Test]
	#[TestDox('Every *_table_schema() returns fields with types and a primary key')]
	#[Group('mantle2/install')]
	public function testSchemaFunctionsAreWellFormed(): void
	{
		preg_match_all('/function (mantle2_[a-z0-9_]+_table_schema)\(/', self::$source, $matches);
		$this->assertNotEmpty($matches[1]);

		require_once dirname(__DIR__, 3) . '/mantle2.install';

		foreach ($matches[1] as $function) {
			$schema = $function();
			$this->assertArrayHasKey('fields', $schema, "$function has no fields");
			$this->assertArrayHasKey('primary key', $schema, "$function has no primary key");

			foreach ($schema['fields'] as $name => $definition) {
				$this->assertArrayHasKey('type', $definition, "$function.$name has no type");
			}

			foreach ($schema['primary key'] as $column) {
				$this->assertArrayHasKey(
					$column,
					$schema['fields'],
					"$function primary key references unknown column $column",
				);
			}
		}
	}

	#[Test]
	#[TestDox('Update hook numbers are unique and contiguous')]
	#[Group('mantle2/install')]
	public function testUpdateHookNumbering(): void
	{
		preg_match_all('/function mantle2_update_(\d+)\(/', self::$source, $matches);
		$numbers = array_map('intval', $matches[1]);

		$this->assertNotEmpty($numbers);
		$this->assertSame(
			array_values(array_unique($numbers)),
			$numbers,
			'Duplicate update hook numbers',
		);

		$sorted = $numbers;
		sort($sorted);
		$this->assertSame($sorted, $numbers, 'Update hooks must be declared in ascending order');
		$this->assertSame(
			range(min($numbers), max($numbers)),
			$sorted,
			'Update hook numbers must be contiguous; never renumber an existing hook',
		);
	}

	#[Test]
	#[TestDox('mantle2_update_9001 still reproduces the historical 255-length token column')]
	#[Group('mantle2/install')]
	public function testUpdate9001PreservesHistory(): void
	{
		$body = self::functionBody('mantle2_update_9001');

		// an update hook reproduces the DDL as of its own point in history; pointing 9001 at
		// the current schema function would silently turn 9002's widen into a no-op
		$this->assertStringContainsString("'length' => 255", $body);
		$this->assertStringNotContainsString('mantle2_push_tokens_table_schema', $body);
		$this->assertStringContainsString(
			"'length' => 512",
			self::functionBody('mantle2_update_9002'),
		);
	}
}
