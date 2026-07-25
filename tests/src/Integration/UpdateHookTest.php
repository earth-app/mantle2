<?php

namespace Drupal\Tests\mantle2\Integration;

use Drupal\Core\Database\Schema;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the hook_update_N chain actually works.
 *
 * These hooks have never executed against production: `drush en` stamps system.schema at
 * the newest update number, so `drush updb` was always a no-op. Dropping `drush un` from
 * the deploy pipeline makes them load-bearing, so they need coverage before they run for
 * real.
 */
class UpdateHookTest extends IntegrationTestBase
{
	private const TABLE_HOOKS = [
		'9001 push_tokens' => ['hook' => 'mantle2_update_9001', 'table' => 'push_tokens'],
		'9003 api_keys' => ['hook' => 'mantle2_update_9003', 'table' => 'mantle2_api_keys'],
		'9005 subscriptions' => [
			'hook' => 'mantle2_update_9005',
			'table' => 'mantle2_subscriptions',
		],
		'9006 trial_codes' => ['hook' => 'mantle2_update_9006', 'table' => 'mantle2_trial_codes'],
		'9007 redemptions' => [
			'hook' => 'mantle2_update_9007',
			'table' => 'mantle2_trial_code_redemptions',
		],
	];

	private const ALL_TABLES = [
		'push_tokens',
		'mantle2_api_keys',
		'mantle2_subscriptions',
		'mantle2_trial_codes',
		'mantle2_trial_code_redemptions',
		'mantle2_staged_activities',
	];

	private const PUBLISHER_FIELDS = [
		'field_verified_publisher',
		'field_verified_publisher_state',
		'field_publisher_application',
	];

	private function schema(): Schema
	{
		return $this->container->get('database')->schema();
	}

	private function dropAllCustomTables(): void
	{
		foreach (self::ALL_TABLES as $table) {
			if ($this->schema()->tableExists($table)) {
				$this->schema()->dropTable($table);
			}
		}
	}

	private function dropUserFields(array $names): void
	{
		foreach ($names as $name) {
			$config = FieldConfig::loadByName('user', 'user', $name);
			$config?->delete();

			$storage = FieldStorageConfig::loadByName('user', $name);
			$storage?->delete();
		}
	}

	/** every column each *_table_schema() declares, keyed by table */
	private function expectedColumns(): array
	{
		return [
			'push_tokens' => array_keys(mantle2_push_tokens_table_schema()['fields']),
			'mantle2_api_keys' => array_keys(mantle2_api_keys_table_schema()['fields']),
			'mantle2_subscriptions' => array_keys(mantle2_subscriptions_table_schema()['fields']),
			'mantle2_trial_codes' => array_keys(mantle2_trial_codes_table_schema()['fields']),
			'mantle2_trial_code_redemptions' => array_keys(
				mantle2_trial_code_redemptions_table_schema()['fields'],
			),
			'mantle2_staged_activities' => array_keys(
				mantle2_staged_activities_table_schema()['fields'],
			),
		];
	}

	private function snapshotColumns(): array
	{
		$snapshot = [];
		foreach ($this->expectedColumns() as $table => $columns) {
			$snapshot[$table] = [];
			foreach ($columns as $column) {
				$snapshot[$table][$column] = $this->schema()->fieldExists($table, $column);
			}
		}

		return $snapshot;
	}

	// #region Per-hook, from its own precondition

	public static function tableHookProvider(): array
	{
		$data = [];
		foreach (self::TABLE_HOOKS as $label => $spec) {
			$data[$label] = [$spec['hook'], $spec['table']];
		}

		return $data;
	}

	#[Test]
	#[TestDox('Each create-table hook builds its table from a missing state and is idempotent')]
	#[Group('mantle2/install')]
	#[DataProvider('tableHookProvider')]
	public function testCreateTableHook(string $hook, string $table): void
	{
		if ($this->schema()->tableExists($table)) {
			$this->schema()->dropTable($table);
		}
		$this->assertFalse($this->schema()->tableExists($table));

		$first = $hook();
		$this->assertTrue($this->schema()->tableExists($table), "$hook did not create $table");
		$this->assertStringContainsStringIgnoringCase('created', $first);

		// second run must take the guard branch, not throw on a duplicate table
		$second = $hook();
		$this->assertTrue($this->schema()->tableExists($table));
		$this->assertStringContainsStringIgnoringCase('already exists', $second);
		$this->assertNotSame($first, $second);
	}

	#[Test]
	#[TestDox('9002 widens push_tokens.token from the historical 255 to 512')]
	#[Group('mantle2/install')]
	public function testUpdate9002WidensToken(): void
	{
		if ($this->schema()->tableExists('push_tokens')) {
			$this->schema()->dropTable('push_tokens');
		}

		// 9001 deliberately reproduces the historical 255-wide column
		mantle2_update_9001();
		$this->assertTrue($this->schema()->fieldExists('push_tokens', 'token'));

		$message = mantle2_update_9002();
		$this->assertStringContainsString('512', $message);
		$this->assertTrue($this->schema()->fieldExists('push_tokens', 'token'));

		// a 512-char token must now round-trip
		$long = str_repeat('a', 512);
		$this->container
			->get('database')
			->insert('push_tokens')
			->fields(['user_id' => 42, 'platform' => 'ios', 'token' => $long, 'updated' => time()])
			->execute();

		$stored = $this->container
			->get('database')
			->select('push_tokens', 't')
			->fields('t', ['token'])
			->condition('user_id', 42)
			->execute()
			->fetchField();

		$this->assertSame(512, strlen($stored));
	}

	#[Test]
	#[TestDox('9004 creates the blocking fields and is safe to re-run')]
	#[Group('mantle2/install')]
	public function testUpdate9004(): void
	{
		$fields = ['field_blocked_users', 'field_blocked_by'];
		$this->dropUserFields($fields);

		foreach ($fields as $name) {
			$this->assertNull(FieldStorageConfig::loadByName('user', $name));
		}

		mantle2_update_9004();
		foreach ($fields as $name) {
			$this->assertNotNull(FieldStorageConfig::loadByName('user', $name), "$name missing");
			$this->assertNotNull(FieldConfig::loadByName('user', 'user', $name));
		}

		mantle2_update_9004();
		foreach ($fields as $name) {
			$this->assertNotNull(FieldStorageConfig::loadByName('user', $name));
		}
	}

	#[Test]
	#[TestDox('9008 adds the redemption detail columns once and tolerates a repeat')]
	#[Group('mantle2/install')]
	public function testUpdate9008(): void
	{
		$table = 'mantle2_trial_code_redemptions';
		if ($this->schema()->tableExists($table)) {
			$this->schema()->dropTable($table);
		}
		mantle2_update_9007();

		foreach (['tier', 'expires_at'] as $column) {
			if ($this->schema()->fieldExists($table, $column)) {
				$this->schema()->dropField($table, $column);
			}
		}

		$first = mantle2_update_9008();
		$this->assertStringContainsString('tier', $first);
		$this->assertStringContainsString('expires_at', $first);
		$this->assertTrue($this->schema()->fieldExists($table, 'tier'));
		$this->assertTrue($this->schema()->fieldExists($table, 'expires_at'));

		$second = mantle2_update_9008();
		$this->assertStringContainsStringIgnoringCase('already present', $second);
	}

	#[Test]
	#[TestDox('9009 creates the staging table and Verified Publisher fields, idempotently')]
	#[Group('mantle2/install')]
	public function testUpdate9009(): void
	{
		if ($this->schema()->tableExists('mantle2_staged_activities')) {
			$this->schema()->dropTable('mantle2_staged_activities');
		}
		$this->dropUserFields(self::PUBLISHER_FIELDS);

		mantle2_update_9009();

		$this->assertTrue($this->schema()->tableExists('mantle2_staged_activities'));
		foreach (self::PUBLISHER_FIELDS as $name) {
			$this->assertNotNull(FieldStorageConfig::loadByName('user', $name), "$name missing");
		}

		mantle2_update_9009();
		$this->assertTrue($this->schema()->tableExists('mantle2_staged_activities'));
	}

	// #endregion

	// #region Invalid and partial state

	#[Test]
	#[TestDox('9002 and 9008 return early instead of throwing when their table is absent')]
	#[Group('mantle2/install')]
	public function testHooksTolerateMissingTables(): void
	{
		if ($this->schema()->tableExists('push_tokens')) {
			$this->schema()->dropTable('push_tokens');
		}
		$this->assertStringContainsStringIgnoringCase('does not exist', mantle2_update_9002());

		if ($this->schema()->tableExists('mantle2_trial_code_redemptions')) {
			$this->schema()->dropTable('mantle2_trial_code_redemptions');
		}
		$this->assertStringContainsStringIgnoringCase('does not exist', mantle2_update_9008());
	}

	#[Test]
	#[TestDox('Every create-table hook is a no-op against a pre-existing table')]
	#[Group('mantle2/install')]
	public function testHooksAreNoOpsWhenTablesExist(): void
	{
		mantle2_ensure_custom_tables();

		foreach (self::TABLE_HOOKS as $spec) {
			$message = $spec['hook']();
			$this->assertStringContainsStringIgnoringCase('already exists', $message);
		}
	}

	// #endregion

	// #region Sequential replay

	#[Test]
	#[TestDox('Replaying 9001 through 9009 in order rebuilds the full schema from nothing')]
	#[Group('mantle2/install')]
	public function testSequentialReplay(): void
	{
		$this->dropAllCustomTables();
		$this->dropUserFields(
			array_merge(self::PUBLISHER_FIELDS, ['field_blocked_users', 'field_blocked_by']),
		);

		foreach (self::ALL_TABLES as $table) {
			$this->assertFalse($this->schema()->tableExists($table), "$table should be gone");
		}

		$messages = [];
		foreach (range(9001, 9009) as $number) {
			$hook = "mantle2_update_$number";
			$this->assertTrue(function_exists($hook), "$hook is missing");
			$messages[$number] = $hook();
		}

		$this->assertCount(9, $messages);
		foreach (self::ALL_TABLES as $table) {
			$this->assertTrue($this->schema()->tableExists($table), "$table was not recreated");
		}
		foreach (self::PUBLISHER_FIELDS as $name) {
			$this->assertNotNull(FieldStorageConfig::loadByName('user', $name));
		}
	}

	#[Test]
	#[TestDox('A replayed database is column-identical to a fresh install')]
	#[Group('mantle2/install')]
	public function testReplayMatchesFreshInstall(): void
	{
		// fresh-install baseline (setUp already ran mantle2_ensure_custom_tables)
		mantle2_ensure_custom_tables();
		$fresh = $this->snapshotColumns();

		foreach ($fresh as $table => $columns) {
			foreach ($columns as $column => $exists) {
				$this->assertTrue($exists, "fresh install is missing $table.$column");
			}
		}

		$this->dropAllCustomTables();
		foreach (range(9001, 9009) as $number) {
			"mantle2_update_$number"();
		}
		$replayed = $this->snapshotColumns();

		// any column added to a *_table_schema() without a matching addField hook shows up
		// here: the fresh install has it and the replay does not
		$this->assertSame(
			$fresh,
			$replayed,
			'Replaying the update hooks produced a different schema than a fresh install.',
		);
	}

	#[Test]
	#[TestDox('Replay is idempotent when run twice end to end')]
	#[Group('mantle2/install')]
	public function testReplayTwiceIsStable(): void
	{
		$this->dropAllCustomTables();

		foreach (range(9001, 9009) as $number) {
			"mantle2_update_$number"();
		}
		$first = $this->snapshotColumns();

		foreach (range(9001, 9009) as $number) {
			"mantle2_update_$number"();
		}

		$this->assertSame($first, $this->snapshotColumns());
	}

	// #endregion
}
