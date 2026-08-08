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
		'email_suppressions',
	];

	/**
	 * Every update hook that exists, in order.
	 *
	 * Derived rather than a hardcoded range so adding a hook cannot silently escape the replay
	 * tests, which is what a literal range(9001, 9009) allowed.
	 *
	 * @return int[]
	 */
	private static function updateHookNumbers(): array
	{
		$numbers = [];
		for ($number = 9001; $number <= 9999; $number++) {
			if (function_exists("mantle2_update_$number")) {
				$numbers[] = $number;
			}
		}

		return $numbers;
	}

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
			'email_suppressions' => array_keys(mantle2_email_suppressions_table_schema()['fields']),
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

	#[Test]
	#[TestDox('9010 creates email_suppressions and field_message_prefs, idempotently')]
	#[Group('mantle2/install')]
	public function testUpdate9010(): void
	{
		if ($this->schema()->tableExists('email_suppressions')) {
			$this->schema()->dropTable('email_suppressions');
		}
		$this->dropUserFields(['field_message_prefs']);

		mantle2_update_9010();

		$this->assertTrue($this->schema()->tableExists('email_suppressions'));
		$this->assertNotNull(FieldStorageConfig::loadByName('user', 'field_message_prefs'));

		// the suppression list is a table, not a cache entry, precisely so a flush cannot resume
		// sending to an address that permanently bounced
		foreach (['email', 'reason', 'created'] as $column) {
			$this->assertTrue(
				$this->schema()->fieldExists('email_suppressions', $column),
				"email_suppressions.$column missing",
			);
		}

		mantle2_update_9010();
		$this->assertTrue($this->schema()->tableExists('email_suppressions'));
	}

	#[Test]
	#[TestDox('9011 renames warned_12h to warned_urgent and keeps the warned rows warned')]
	#[Group('mantle2/install')]
	public function testUpdate9011RenamesWarnedColumn(): void
	{
		$table = 'mantle2_staged_activities';
		$schema = $this->schema();

		// rebuild production's shape: the table as 9009 created it, before the rename
		if ($schema->tableExists($table)) {
			$schema->dropTable($table);
		}
		$definition = mantle2_staged_activities_table_schema();
		$spec = $definition['fields']['warned_urgent'];
		unset($definition['fields']['warned_urgent']);
		$definition['fields']['warned_12h'] = $spec;
		$schema->createTable($table, $definition);

		$database = $this->container->get('database');
		$database
			->insert($table)
			->fields([
				'activity_id' => 'legacy_row',
				'dedup_hash' => str_repeat('a', 64),
				'payload' => '{}',
				'submitter_id' => 1,
				'submitter_kind' => 'cloud',
				'source' => 'cloud_discovery',
				'state' => 'pending',
				'submitted_at' => time(),
				'expires_at' => time() + 3600,
				'warned_12h' => 1,
				'notified_pending' => 1,
			])
			->execute();

		$message = mantle2_update_9011();

		$this->assertStringContainsString('warned_urgent', $message);
		$this->assertTrue($schema->fieldExists($table, 'warned_urgent'));
		$this->assertFalse($schema->fieldExists($table, 'warned_12h'));

		// a rename, not a drop-and-add; a re-add would unwarn every row and re-mail everyone
		$this->assertSame(
			'1',
			(string) $database
				->select($table, 't')
				->fields('t', ['warned_urgent'])
				->condition('activity_id', 'legacy_row')
				->execute()
				->fetchField(),
		);

		// the insert StagingHelper::stage() actually performs must now succeed
		$database
			->insert($table)
			->fields([
				'activity_id' => 'new_row',
				'dedup_hash' => str_repeat('b', 64),
				'payload' => '{}',
				'submitter_id' => 1,
				'submitter_kind' => 'cloud',
				'source' => 'cloud_discovery',
				'state' => 'pending',
				'submitted_at' => time(),
				'expires_at' => time() + 3600,
				'warned_urgent' => 0,
				'notified_pending' => 0,
			])
			->execute();

		$this->assertStringContainsStringIgnoringCase('nothing to repair', mantle2_update_9011());
	}

	#[Test]
	#[TestDox('Reconciling columns restores a column a schema function gained after create')]
	#[Group('mantle2/install')]
	public function testReconcileCustomColumns(): void
	{
		mantle2_ensure_custom_tables();
		$this->schema()->dropField('mantle2_staged_activities', 'warned_urgent');
		$this->assertFalse(
			$this->schema()->fieldExists('mantle2_staged_activities', 'warned_urgent'),
		);

		$added = mantle2_reconcile_custom_columns();

		$this->assertContains('mantle2_staged_activities.warned_urgent', $added);
		$this->assertTrue(
			$this->schema()->fieldExists('mantle2_staged_activities', 'warned_urgent'),
		);
		// second pass has nothing left to do
		$this->assertSame([], mantle2_reconcile_custom_columns());
	}

	#[Test]
	#[TestDox('Reconciling columns leaves a fresh install untouched and skips missing tables')]
	#[Group('mantle2/install')]
	public function testReconcileIsANoOpOnAFreshInstall(): void
	{
		mantle2_ensure_custom_tables();
		$this->assertSame([], mantle2_reconcile_custom_columns());

		$this->dropAllCustomTables();
		$this->assertSame([], mantle2_reconcile_custom_columns());
	}

	// #endregion

	// #region Column round-trip

	/**
	 * A writable sample for one column, chosen from its declared type.
	 *
	 * Deliberately fails on a type it has not been taught: a new column type silently
	 * skipped here would put that column back outside the round-trip below.
	 */
	private function sampleValue(array $spec, int $seed): string|int|float
	{
		$length = (int) ($spec['length'] ?? 8);

		return match ($spec['type']) {
			'int', 'serial' => $seed,
			'float', 'numeric' => $seed + 0.5,
			'varchar', 'varchar_ascii', 'char', 'text', 'blob' => substr(
				str_repeat("v$seed", 16),
				0,
				max(1, min(8, $length)),
			),
			default => self::fail(
				"sampleValue() has no case for column type '{$spec['type']}'; add one",
			),
		};
	}

	public static function customTableProvider(): array
	{
		return array_combine(
			self::ALL_TABLES,
			array_map(fn(string $table) => [$table], self::ALL_TABLES),
		);
	}

	#[Test]
	#[TestDox('Every declared column survives an insert, read, update and delete')]
	#[Group('mantle2/install')]
	#[DataProvider('customTableProvider')]
	public function testEveryDeclaredColumnRoundTrips(string $table): void
	{
		// this is the check that was missing when warned_urgent shipped: the column existed
		// in the schema function, so snapshot tests passed, but no write ever named it
		mantle2_ensure_custom_tables();
		$definition = mantle2_custom_table_schemas()[$table];
		$database = $this->container->get('database');

		$serial = [];
		$insert = [];
		foreach ($definition['fields'] as $column => $spec) {
			if ($spec['type'] === 'serial') {
				$serial[] = $column;
				continue;
			}
			$insert[$column] = $this->sampleValue($spec, 1);
		}

		$this->assertNotEmpty($insert, "$table declares no writable column");
		$database->insert($table)->fields($insert)->execute();

		$stored = $database->select($table, 't')->fields('t')->execute()->fetchAssoc();

		$this->assertIsArray($stored, "$table returned no row after insert");
		foreach (array_keys($definition['fields']) as $column) {
			$this->assertArrayHasKey($column, $stored, "$table.$column is missing from the row");
		}
		foreach ($insert as $column => $value) {
			$this->assertEquals($value, $stored[$column], "$table.$column did not round-trip");
		}

		// primary key columns identify the row, so update everything else
		$keys = array_merge($definition['primary key'], $serial);
		$update = array_diff_key($insert, array_flip($keys));

		if ($update) {
			$updated = [];
			foreach ($update as $column => $ignored) {
				$updated[$column] = $this->sampleValue($definition['fields'][$column], 2);
			}

			$this->assertSame(
				1,
				(int) $database->update($table)->fields($updated)->execute(),
				"$table did not update exactly one row",
			);

			$fresh = $database->select($table, 't')->fields('t')->execute()->fetchAssoc();
			foreach ($updated as $column => $value) {
				$this->assertEquals($value, $fresh[$column], "$table.$column did not update");
			}
		}

		$this->assertSame(1, (int) $database->delete($table)->execute());
		$this->assertSame(
			0,
			(int) $database->select($table, 't')->countQuery()->execute()->fetchField(),
		);
	}

	#[Test]
	#[TestDox('Every declared index and unique key points at a column that exists')]
	#[Group('mantle2/install')]
	#[DataProvider('customTableProvider')]
	public function testDeclaredKeysReferenceRealColumns(string $table): void
	{
		$definition = mantle2_custom_table_schemas()[$table];
		$columns = array_keys($definition['fields']);

		foreach (['indexes', 'unique keys'] as $section) {
			foreach ($definition[$section] ?? [] as $name => $members) {
				foreach ($members as $member) {
					// a prefix-length index is declared as [column, length]
					$column = is_array($member) ? $member[0] : $member;
					$this->assertContains(
						$column,
						$columns,
						"$table.$section.$name references unknown column $column",
					);
				}
			}
		}

		foreach ($definition['primary key'] as $column) {
			$this->assertContains($column, $columns, "$table primary key references $column");
		}
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
	#[TestDox('Replaying every update hook in order rebuilds the full schema from nothing')]
	#[Group('mantle2/install')]
	public function testSequentialReplay(): void
	{
		$this->dropAllCustomTables();
		$this->dropUserFields(
			array_merge(self::PUBLISHER_FIELDS, [
				'field_blocked_users',
				'field_blocked_by',
				'field_message_prefs',
			]),
		);

		foreach (self::ALL_TABLES as $table) {
			$this->assertFalse($this->schema()->tableExists($table), "$table should be gone");
		}

		$messages = [];
		foreach (self::updateHookNumbers() as $number) {
			$hook = "mantle2_update_$number";
			$this->assertTrue(function_exists($hook), "$hook is missing");
			$messages[$number] = $hook();
		}

		$this->assertCount(count(self::updateHookNumbers()), $messages);
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
		foreach (self::updateHookNumbers() as $number) {
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

		foreach (self::updateHookNumbers() as $number) {
			"mantle2_update_$number"();
		}
		$first = $this->snapshotColumns();

		foreach (self::updateHookNumbers() as $number) {
			"mantle2_update_$number"();
		}

		$this->assertSame($first, $this->snapshotColumns());
	}

	// #endregion
}
