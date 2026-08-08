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
		'email_suppressions',
	];

	#[Test]
	#[TestDox('Every table in mantle2_custom_table_schemas() has a matching *_table_schema()')]
	#[Group('mantle2/install')]
	public function testEveryTableHasSchemaFunction(): void
	{
		$body = self::functionBody('mantle2_custom_table_schemas');

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
				"$table is not created by mantle2_custom_table_schemas()",
			);
		}
	}

	#[Test]
	#[TestDox('Create and column-reconcile both read the single table-schema registry')]
	#[Group('mantle2/install')]
	public function testCreateAndReconcileShareOneRegistry(): void
	{
		// mantle2_ensure_custom_tables() only ever creates, so a column added to a schema
		// function later never reaches an existing table. a second hardcoded table list is
		// how the two halves drift apart again.
		foreach (['mantle2_ensure_custom_tables', 'mantle2_reconcile_custom_columns'] as $name) {
			$body = self::functionBody($name);
			$this->assertStringContainsString(
				'mantle2_custom_table_schemas()',
				$body,
				"$name must iterate mantle2_custom_table_schemas(), not its own table list",
			);
			$this->assertDoesNotMatchRegularExpression(
				"/'[a-z0-9_]+'\s*=>\s*mantle2_[a-z0-9_]+_table_schema\(\)/",
				$body,
				"$name declares its own table list instead of using the shared registry",
			);
		}

		// the reconcile pass is worthless if nothing runs it
		foreach (['mantle2_install', 'mantle2_update_9011'] as $caller) {
			$this->assertStringContainsString(
				'mantle2_reconcile_custom_columns()',
				self::functionBody($caller),
				"$caller never reconciles columns",
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

	/**
	 * Every column each table declares, as of the newest update hook.
	 *
	 * A tripwire, not documentation. mantle2_ensure_custom_tables() only ever creates, so
	 * editing a *_table_schema() does nothing to a table production already has; that is how
	 * warned_12h -> warned_urgent shipped and silently killed every staging insert for a
	 * release. Changing this list means writing the matching hook_update_N first.
	 */
	private const COLUMN_BASELINE = [
		'push_tokens' => ['user_id', 'platform', 'token', 'updated'],
		'mantle2_api_keys' => [
			'id',
			'key_id',
			'user_id',
			'token_hash',
			'token_prefix',
			'name',
			'description',
			'scopes',
			'created_at',
			'expires_at',
			'last_used_at',
			'last_used_ip',
			'revoked_at',
			'warned_1w',
			'warned_1d',
			'expired_notified',
		],
		'mantle2_subscriptions' => [
			'user_id',
			'provider',
			'external_customer_id',
			'external_subscription_id',
			'tier',
			'status',
			'current_period_end',
			'cancel_at_period_end',
			'consent_at',
			'price_cents',
			'started_at',
			'created',
			'updated',
		],
		'mantle2_trial_codes' => [
			'id',
			'code',
			'tier',
			'days',
			'max_redemptions',
			'redemptions',
			'expires_at',
			'active',
			'created_by',
			'created',
		],
		'mantle2_trial_code_redemptions' => [
			'id',
			'code',
			'user_id',
			'redeemed_at',
			'tier',
			'expires_at',
		],
		'mantle2_staged_activities' => [
			'id',
			'activity_id',
			'dedup_hash',
			'payload',
			'note',
			'submitter_id',
			'submitter_kind',
			'source',
			'state',
			'submitted_at',
			'expires_at',
			'decided_at',
			'reviewer_id',
			'review_notes',
			'published_nid',
			'warned_urgent',
			'notified_pending',
		],
		'email_suppressions' => ['email', 'reason', 'created'],
	];

	#[Test]
	#[TestDox('No table gains or loses a column without an update hook to carry it')]
	#[Group('mantle2/install')]
	public function testColumnBaselineIsUnchanged(): void
	{
		require_once dirname(__DIR__, 3) . '/mantle2.install';

		foreach (mantle2_custom_table_schemas() as $table => $definition) {
			$this->assertArrayHasKey(
				$table,
				self::COLUMN_BASELINE,
				"$table is new; add it to COLUMN_BASELINE once its create hook exists",
			);

			$this->assertSame(
				self::COLUMN_BASELINE[$table],
				array_keys($definition['fields']),
				"$table columns changed. mantle2_ensure_custom_tables() skips a table that " .
					'already exists, so write the hook_update_N that alters production first, ' .
					'then update COLUMN_BASELINE.',
			);
		}

		$this->assertSame(
			array_keys(self::COLUMN_BASELINE),
			array_keys(mantle2_custom_table_schemas()),
			'COLUMN_BASELINE and the table registry disagree on which tables exist',
		);
	}

	#[Test]
	#[TestDox('Table, column, and key names follow the lower_snake_case convention')]
	#[Group('mantle2/install')]
	public function testSchemaNamingConventions(): void
	{
		require_once dirname(__DIR__, 3) . '/mantle2.install';

		$identifier = '/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/';

		foreach (mantle2_custom_table_schemas() as $table => $definition) {
			$this->assertMatchesRegularExpression($identifier, $table, "table $table");
			// mysql caps identifiers at 64, and an index name is prefixed with the table name
			$this->assertLessThanOrEqual(48, strlen($table), "table $table is too long");
			$this->assertArrayHasKey('description', $definition, "$table has no description");

			foreach ($definition['fields'] as $column => $spec) {
				$this->assertMatchesRegularExpression($identifier, $column, "$table.$column");
				$this->assertLessThanOrEqual(64, strlen($column), "$table.$column is too long");
				$this->assertArrayHasKey('type', $spec, "$table.$column has no type");
				// mysql reserves these; drupal quotes identifiers but the raw sql in update
				// hooks does not always
				$this->assertNotContains(
					$column,
					['order', 'group', 'key', 'index', 'default', 'condition'],
					"$table.$column is a reserved sql word",
				);
			}

			foreach (['indexes', 'unique keys'] as $section) {
				foreach (array_keys($definition[$section] ?? []) as $name) {
					$this->assertMatchesRegularExpression(
						$identifier,
						$name,
						"$table.$section.$name",
					);
				}
			}
		}
	}

	#[Test]
	#[TestDox('Every *_table_schema() is registered, and every registered one is defined')]
	#[Group('mantle2/install')]
	public function testEverySchemaFunctionIsRegistered(): void
	{
		preg_match_all('/function (mantle2_[a-z0-9_]+_table_schema)\(/', self::$source, $matches);
		$registry = self::functionBody('mantle2_custom_table_schemas');

		foreach ($matches[1] as $function) {
			$this->assertStringContainsString(
				"$function()",
				$registry,
				"$function() exists but mantle2_custom_table_schemas() never calls it, so the " .
					'table it describes is never created',
			);
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
