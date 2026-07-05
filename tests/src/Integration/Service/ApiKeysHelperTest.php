<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\Core\Database\Database;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\ApiKey;
use Drupal\mantle2\Custom\ApiKeyScope;
use Drupal\mantle2\Service\ApiKeysHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ApiKeysHelperTest extends IntegrationTestBase
{
	private function member(array $values = []): UserInterface
	{
		return $this->createUser($values + ['field_email_verified' => true]);
	}

	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
			'field_email_verified' => true,
		]);
	}

	private function rowFor(string $keyId): ?array
	{
		$row = Database::getConnection()
			->select(ApiKeysHelper::TABLE, 't')
			->fields('t')
			->condition('t.key_id', $keyId)
			->execute()
			->fetchAssoc();
		return $row ?: null;
	}

	#region scopes

	#[Test]
	#[TestDox('isSessionOnly and scopeFor reflect the route maps')]
	#[Group('mantle2/api_keys')]
	public function scopeMaps(): void
	{
		$this->assertTrue(ApiKeysHelper::isSessionOnly('mantle2.users.login'));
		$this->assertTrue(ApiKeysHelper::isSessionOnly('mantle2.api_keys.create'));
		$this->assertFalse(ApiKeysHelper::isSessionOnly('mantle2.activities'));

		$this->assertSame(
			ApiKeyScope::ACTIVITIES_READ,
			ApiKeysHelper::scopeFor('mantle2.activities'),
		);
		$this->assertSame('', ApiKeysHelper::scopeFor('mantle2.hello'));
		$this->assertSame('', ApiKeysHelper::scopeFor('mantle2.api_keys.scopes'));

		// unknown route fails closed (null, not empty string)
		$this->assertNull(ApiKeysHelper::scopeFor('mantle2.route.that.is.not.real'));
	}

	#endregion

	#region token format

	#[Test]
	#[TestDox('buildToken then parseToken round-trips user id, timestamp, and random')]
	#[Group('mantle2/api_keys')]
	public function tokenRoundTrip(): void
	{
		$random = str_repeat('ab', ApiKey::RANDOM_HEX_LEN / 2);
		$ts = (int) (microtime(true) * 1000);
		$token = ApiKeysHelper::buildToken(4242, $random, $ts);

		$this->assertSame(ApiKey::TOTAL_LENGTH, strlen($token));
		$this->assertStringStartsWith(ApiKey::TOKEN_PREFIX, $token);
		$this->assertTrue(ApiKeysHelper::looksLikeApiKey($token));

		$parsed = ApiKeysHelper::parseToken($token);
		$this->assertIsArray($parsed);
		$this->assertSame(4242, $parsed['user_id']);
		$this->assertSame($ts, $parsed['timestamp_ms']);
		$this->assertSame($random, $parsed['random']);
		$this->assertSame(
			(int) gmdate('y', (int) floor($ts / 1000)) % 100,
			$parsed['year_two_digit'],
		);
	}

	public static function badTokenProvider(): array
	{
		$random = str_repeat('a', ApiKey::RANDOM_HEX_LEN);
		$ts = (int) (microtime(true) * 1000);
		$good = ApiKeysHelper::buildToken(7, $random, $ts);
		return [
			'wrong length' => [substr($good, 0, -1)],
			'wrong prefix' => ['XX' . substr($good, 2)],
			'non-digit year' => [ApiKey::TOKEN_PREFIX . 'zz' . substr($good, 4)],
			'garbage' => ['not-a-token'],
			'empty' => [''],
		];
	}

	#[Test]
	#[TestDox('parseToken rejects malformed tokens')]
	#[Group('mantle2/api_keys')]
	#[DataProvider('badTokenProvider')]
	public function parseRejectsBadTokens(string $token): void
	{
		$this->assertNull(ApiKeysHelper::parseToken($token));
	}

	#[Test]
	#[TestDox('parseToken rejects a year/timestamp mismatch')]
	#[Group('mantle2/api_keys')]
	public function parseRejectsYearMismatch(): void
	{
		$random = str_repeat('c', ApiKey::RANDOM_HEX_LEN);
		$ts = (int) (microtime(true) * 1000);
		$token = ApiKeysHelper::buildToken(7, $random, $ts);
		// flip the embedded YY to a value that cannot match the timestamp year
		$wrongYear = ((int) gmdate('y', (int) floor($ts / 1000)) + 1) % 100;
		$tampered =
			ApiKey::TOKEN_PREFIX .
			str_pad((string) $wrongYear, 2, '0', STR_PAD_LEFT) .
			substr($token, 4);
		$this->assertNull(ApiKeysHelper::parseToken($tampered));
	}

	#[Test]
	#[TestDox('looksLikeApiKey checks prefix and total length only')]
	#[Group('mantle2/api_keys')]
	public function looksLikeApiKey(): void
	{
		$good = ApiKeysHelper::buildToken(
			7,
			str_repeat('a', ApiKey::RANDOM_HEX_LEN),
			(int) (microtime(true) * 1000),
		);
		$this->assertTrue(ApiKeysHelper::looksLikeApiKey($good));

		$this->assertFalse(ApiKeysHelper::looksLikeApiKey('EAshort'));
		$this->assertFalse(
			ApiKeysHelper::looksLikeApiKey('XX' . substr($good, 2)),
			'wrong prefix but right length is not an api key',
		);
		$this->assertFalse(ApiKeysHelper::looksLikeApiKey(''));
	}

	public static function structurallyBadTokenProvider(): array
	{
		$random = str_repeat('a', ApiKey::RANDOM_HEX_LEN);
		$ts = (int) (microtime(true) * 1000);
		$good = ApiKeysHelper::buildToken(7, $random, $ts);
		$randomEnd = 4 + ApiKey::RANDOM_HEX_LEN;
		$gPos = $randomEnd + 1 + ApiKey::USER_HEX_LEN;
		return [
			// corrupt the random block with a non-hex char at a fixed offset
			'non-hex random' => [substr_replace($good, 'z', 5, 1)],
			// break the 'U' separator
			'missing U separator' => [substr_replace($good, 'X', $randomEnd, 1)],
			// non-hex in the user id block
			'non-hex user id' => [substr_replace($good, 'z', $randomEnd + 1, 1)],
			// break the 'G' separator
			'missing G separator' => [substr_replace($good, 'X', $gPos, 1)],
			// non-hex in the timestamp block
			'non-hex timestamp' => [substr_replace($good, 'z', $gPos + 1, 1)],
		];
	}

	#[Test]
	#[TestDox('parseToken rejects structural corruption at each field boundary')]
	#[Group('mantle2/api_keys')]
	#[DataProvider('structurallyBadTokenProvider')]
	public function parseRejectsStructural(string $token): void
	{
		$this->assertNull(ApiKeysHelper::parseToken($token));
	}

	#[Test]
	#[TestDox('hashToken is a stable sha256 hex digest')]
	#[Group('mantle2/api_keys')]
	public function hashing(): void
	{
		$token =
			'EA25' . str_repeat('a', 32) . 'U' . str_repeat('0', 24) . 'G' . str_repeat('0', 16);
		$hash = ApiKeysHelper::hashToken($token);
		$this->assertSame(hash('sha256', $token), $hash);
		$this->assertSame(64, strlen($hash));
		$this->assertSame($hash, ApiKeysHelper::hashToken($token));
	}

	#endregion

	#region issuance

	#[Test]
	#[TestDox('issue persists a hashed key with a one-time token and parseable payload')]
	#[Group('mantle2/api_keys')]
	public function issueSuccess(): void
	{
		$user = $this->member();
		$result = ApiKeysHelper::issue(
			$user,
			'My Key',
			'  spaced desc  ',
			[ApiKeyScope::USER_READ_PROFILE, ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		$this->assertIsArray($result);
		$this->assertInstanceOf(ApiKey::class, $result['key']);

		$key = $result['key'];
		$this->assertSame('My Key', $key->getName());
		$this->assertSame('spaced desc', $key->getDescription());
		$this->assertSame([ApiKeyScope::USER_READ_PROFILE], $key->getScopes());
		$this->assertNull($key->getExpiresAt());
		$this->assertFalse($key->isRevoked());

		// token parses back to this user, storage is hashed, prefix is public-safe
		$parsed = ApiKeysHelper::parseToken($result['token']);
		$this->assertSame((int) $user->id(), $parsed['user_id']);

		$row = $this->rowFor($key->getKeyId());
		$this->assertNotNull($row);
		$this->assertSame(hash('sha256', $result['token']), $row['token_hash']);
		$this->assertStringStartsWith($key->getTokenPrefix(), $result['token']);
		$this->assertSame(ApiKey::PUBLIC_PREFIX_LEN, strlen($row['token_prefix']));
	}

	public static function invalidIssueProvider(): array
	{
		return [
			'name too short' => [
				'ab',
				null,
				[ApiKeyScope::USER_READ_PROFILE],
				null,
				'invalid_name',
			],
			'name too long' => [
				str_repeat('a', ApiKey::NAME_MAX + 1),
				null,
				[ApiKeyScope::USER_READ_PROFILE],
				null,
				'invalid_name',
			],
			'description too long' => [
				'Valid Name',
				str_repeat('d', ApiKey::DESCRIPTION_MAX + 1),
				[ApiKeyScope::USER_READ_PROFILE],
				null,
				'invalid_description',
			],
			'no scopes' => ['Valid Name', null, [], null, 'invalid_scope'],
			'unknown scope' => ['Valid Name', null, ['bogus:scope'], null, 'invalid_scope'],
			'non-string scopes' => ['Valid Name', null, [123, true], null, 'invalid_scope'],
		];
	}

	#[Test]
	#[TestDox('issue rejects invalid name/description/scopes')]
	#[Group('mantle2/api_keys')]
	#[DataProvider('invalidIssueProvider')]
	public function issueValidation(
		string $name,
		?string $description,
		array $scopes,
		?int $expiresAt,
		string $expected,
	): void {
		$user = $this->member();
		$this->assertSame(
			$expected,
			ApiKeysHelper::issue($user, $name, $description, $scopes, $expiresAt),
		);
	}

	public static function invalidExpiryProvider(): array
	{
		return [
			'in the past' => [-100],
			'under one minute' => [30],
			'over ten years' => [11 * 365 * 86400],
		];
	}

	#[Test]
	#[TestDox('issue rejects out-of-range expirations')]
	#[Group('mantle2/api_keys')]
	#[DataProvider('invalidExpiryProvider')]
	public function issueExpiryValidation(int $offset): void
	{
		$user = $this->member();
		$this->assertSame(
			'invalid_expiry',
			ApiKeysHelper::issue(
				$user,
				'Valid Name',
				null,
				[ApiKeyScope::USER_READ_PROFILE],
				time() + $offset,
			),
		);
	}

	#[Test]
	#[TestDox('issue rejects users with no email and enforces the tier limit')]
	#[Group('mantle2/api_keys')]
	public function issueGuards(): void
	{
		$noEmail = $this->createUser(['field_email_verified' => true, 'mail' => '']);
		$this->assertSame(
			'no_email',
			ApiKeysHelper::issue(
				$noEmail,
				'Valid Name',
				null,
				[ApiKeyScope::USER_READ_PROFILE],
				null,
			),
		);

		$free = $this->member();
		$this->assertSame(2, ApiKeysHelper::maxKeysFor($free));
		ApiKeysHelper::issue($free, 'Key A', null, [ApiKeyScope::USER_READ_PROFILE], null);
		ApiKeysHelper::issue($free, 'Key B', null, [ApiKeyScope::USER_READ_PROFILE], null);
		$this->assertSame(
			'limit',
			ApiKeysHelper::issue($free, 'Key C', null, [ApiKeyScope::USER_READ_PROFILE], null),
		);
	}

	#[Test]
	#[TestDox('maxKeysFor maps tiers and admins are effectively unlimited')]
	#[Group('mantle2/api_keys')]
	public function maxKeysFor(): void
	{
		$free = $this->member();
		$this->assertSame(2, ApiKeysHelper::maxKeysFor($free));

		$pro = $this->member([
			'field_account_type' => (string) array_search(
				AccountType::PRO,
				AccountType::cases(),
				true,
			),
		]);
		$this->assertSame(5, ApiKeysHelper::maxKeysFor($pro));

		$organizer = $this->member([
			'field_account_type' => (string) array_search(
				AccountType::ORGANIZER,
				AccountType::cases(),
				true,
			),
		]);
		$this->assertSame(25, ApiKeysHelper::maxKeysFor($organizer));

		$this->assertSame(PHP_INT_MAX, ApiKeysHelper::maxKeysFor($this->admin()));
	}

	#endregion

	#region lookup / auth

	#[Test]
	#[TestDox('lookupByToken resolves a live key, bumps telemetry, and refuses bad tokens')]
	#[Group('mantle2/api_keys')]
	public function lookupByToken(): void
	{
		$user = $this->member();
		$issued = ApiKeysHelper::issue(
			$user,
			'Auth Key',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		$this->assertIsArray($issued);
		$token = $issued['token'];

		$request = $this->request('GET', '/v2', ['REMOTE_ADDR' => '203.0.113.9']);
		$resolved = ApiKeysHelper::lookupByToken($token, $request);
		$this->assertIsArray($resolved);
		$this->assertSame((int) $user->id(), (int) $resolved['user']->id());
		$this->assertInstanceOf(ApiKey::class, $resolved['key']);

		$row = $this->rowFor($issued['key']->getKeyId());
		$this->assertNotNull($row['last_used_at'], 'telemetry must record last_used_at');
		$this->assertSame('203.0.113.9', $row['last_used_ip']);

		// structurally invalid token -> null with no DB hit
		$this->assertNull(ApiKeysHelper::lookupByToken('garbage'));

		// well-formed token that was never issued -> null
		$phantom = ApiKeysHelper::buildToken(
			(int) $user->id(),
			str_repeat('f', ApiKey::RANDOM_HEX_LEN),
			(int) (microtime(true) * 1000),
		);
		$this->assertNull(ApiKeysHelper::lookupByToken($phantom));
	}

	#[Test]
	#[TestDox('lookupByToken refuses revoked, expired, and disabled-user keys')]
	#[Group('mantle2/api_keys')]
	public function lookupRejectsUnusable(): void
	{
		$user = $this->member();

		$revoked = ApiKeysHelper::issue(
			$user,
			'Revoked Key',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		ApiKeysHelper::revoke($revoked['key']->getKeyId(), (int) $user->id());
		$this->assertNull(ApiKeysHelper::lookupByToken($revoked['token']));

		$live = ApiKeysHelper::issue(
			$user,
			'Expiring Key',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		Database::getConnection()
			->update(ApiKeysHelper::TABLE)
			->fields(['expires_at' => time() - 10])
			->condition('id', $live['key']->getId())
			->execute();
		$this->assertNull(ApiKeysHelper::lookupByToken($live['token']));

		$active = ApiKeysHelper::issue(
			$user,
			'Blocked Owner Key',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		$user->block();
		$user->save();
		$this->assertNull(ApiKeysHelper::lookupByToken($active['token']));
	}

	#endregion

	#region list / mutate

	#[Test]
	#[TestDox('listForUser, getByKeyId, and countActive scope rows to the owner')]
	#[Group('mantle2/api_keys')]
	public function listAndGet(): void
	{
		$user = $this->member();
		$other = $this->member();
		$a = ApiKeysHelper::issue($user, 'Key A', null, [ApiKeyScope::USER_READ_PROFILE], null);
		ApiKeysHelper::issue($user, 'Key B', null, [ApiKeyScope::EVENTS_READ], null);
		ApiKeysHelper::issue($other, 'Foreign', null, [ApiKeyScope::EVENTS_READ], null);

		$list = ApiKeysHelper::listForUser((int) $user->id());
		$this->assertCount(2, $list);
		$this->assertContainsOnlyInstancesOf(ApiKey::class, $list);
		$this->assertSame(2, ApiKeysHelper::countActive((int) $user->id()));

		$fetched = ApiKeysHelper::getByKeyId($a['key']->getKeyId(), (int) $user->id());
		$this->assertInstanceOf(ApiKey::class, $fetched);
		$this->assertSame('Key A', $fetched->getName());

		// wrong owner cannot fetch
		$this->assertNull(ApiKeysHelper::getByKeyId($a['key']->getKeyId(), (int) $other->id()));
		$this->assertNull(ApiKeysHelper::getByKeyId('000000000000000000000000', (int) $user->id()));
	}

	#[Test]
	#[TestDox('update mutates fields, no-ops on empty, and reports not_found/revoked')]
	#[Group('mantle2/api_keys')]
	public function update(): void
	{
		$user = $this->member();
		$issued = ApiKeysHelper::issue(
			$user,
			'Original',
			'orig desc',
			[ApiKeyScope::USER_READ_PROFILE],
			null,
		);
		$keyId = $issued['key']->getKeyId();

		$this->assertSame(
			'not_found',
			ApiKeysHelper::update('deadbeef', (int) $user->id(), 'X', null, null),
		);

		// empty update returns the existing key unchanged
		$noop = ApiKeysHelper::update($keyId, (int) $user->id(), null, null, null);
		$this->assertInstanceOf(ApiKey::class, $noop);
		$this->assertSame('Original', $noop->getName());

		$updated = ApiKeysHelper::update($keyId, (int) $user->id(), 'Renamed', null, [
			ApiKeyScope::EVENTS_READ,
			ApiKeyScope::EVENTS_READ,
		]);
		$this->assertInstanceOf(ApiKey::class, $updated);
		$this->assertSame('Renamed', $updated->getName());
		$this->assertSame([ApiKeyScope::EVENTS_READ], $updated->getScopes());

		// clearing description via empty string persists null
		$cleared = ApiKeysHelper::update($keyId, (int) $user->id(), null, '   ', null);
		$this->assertNull($cleared->getDescription());

		$this->assertSame(
			'invalid_name',
			ApiKeysHelper::update($keyId, (int) $user->id(), 'ab', null, null),
		);
		$this->assertSame(
			'invalid_scope',
			ApiKeysHelper::update($keyId, (int) $user->id(), null, null, ['nope']),
		);

		$this->assertSame(
			'invalid_description',
			ApiKeysHelper::update(
				$keyId,
				(int) $user->id(),
				null,
				str_repeat('d', ApiKey::DESCRIPTION_MAX + 1),
				null,
			),
		);

		ApiKeysHelper::revoke($keyId, (int) $user->id());
		$this->assertSame(
			'revoked',
			ApiKeysHelper::update($keyId, (int) $user->id(), 'Renamed Again', null, null),
		);
	}

	#[Test]
	#[TestDox('countActive honors an explicit now and excludes expired keys')]
	#[Group('mantle2/api_keys')]
	public function countActiveWithNow(): void
	{
		$user = $this->member();
		ApiKeysHelper::issue(
			$user,
			'Short Lived',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			time() + 3600,
		);
		$this->assertSame(1, ApiKeysHelper::countActive((int) $user->id(), time()));
		// evaluated far in the future the key has expired, so it is not counted
		$this->assertSame(0, ApiKeysHelper::countActive((int) $user->id(), time() + 7200));
	}

	#[Test]
	#[TestDox('revoke and revokeAllForUser soft-delete active keys idempotently')]
	#[Group('mantle2/api_keys')]
	public function revoke(): void
	{
		$user = $this->admin();
		$a = ApiKeysHelper::issue($user, 'Key A', null, [ApiKeyScope::USER_READ_PROFILE], null);
		ApiKeysHelper::issue($user, 'Key B', null, [ApiKeyScope::EVENTS_READ], null);
		ApiKeysHelper::issue($user, 'Key C', null, [ApiKeyScope::PROMPTS_READ], null);

		$this->assertTrue(ApiKeysHelper::revoke($a['key']->getKeyId(), (int) $user->id()));
		$this->assertNotNull($this->rowFor($a['key']->getKeyId())['revoked_at']);
		$this->assertFalse(ApiKeysHelper::revoke($a['key']->getKeyId(), (int) $user->id()));
		$this->assertSame(2, ApiKeysHelper::countActive((int) $user->id()));

		$this->assertSame(2, ApiKeysHelper::revokeAllForUser((int) $user->id()));
		$this->assertSame(0, ApiKeysHelper::countActive((int) $user->id()));
		$this->assertSame(0, ApiKeysHelper::revokeAllForUser((int) $user->id()));
	}

	#[Test]
	#[TestDox('isExpired/isRevoked/isUsable reflect stored timestamps')]
	#[Group('mantle2/api_keys')]
	public function keyStateAccessors(): void
	{
		$user = $this->member();
		$live = ApiKeysHelper::issue(
			$user,
			'Live Key',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			time() + 3600,
		);
		$key = $live['key'];
		$this->assertFalse($key->isExpired());
		$this->assertFalse($key->isRevoked());
		$this->assertTrue($key->isUsable());
		$this->assertTrue($key->isExpired(time() + 7200));
	}

	#endregion

	#region cron / notify

	#[Test]
	#[TestDox('checkExpirations flips warning/notified flags per window and prunes stale rows')]
	#[Group('mantle2/api_keys')]
	public function checkExpirations(): void
	{
		// pin the cloud endpoint at a dead port so notify side effects no-op
		// (sendRequest returns [] on connection failure); the DB flag/prune
		// logic is the in-scope deterministic surface
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');

		$user = $this->admin();
		$now = time();
		$db = Database::getConnection();

		$oneWeek = ApiKeysHelper::issue(
			$user,
			'Expires In A Week',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			$now + 6 * 86400,
		);
		$oneDay = ApiKeysHelper::issue(
			$user,
			'Expires Tomorrow',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			$now + 12 * 3600,
		);
		$far = ApiKeysHelper::issue(
			$user,
			'Far Off',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			$now + 200 * 86400,
		);

		// an already-expired but recent key -> should get expired_notified
		$expired = ApiKeysHelper::issue(
			$user,
			'Recently Expired',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			$now + 120,
		);
		$db->update(ApiKeysHelper::TABLE)
			->fields(['expires_at' => $now - 10])
			->condition('id', $expired['key']->getId())
			->execute();

		// a deeply-expired key older than the 90d prune cutoff
		$stale = ApiKeysHelper::issue(
			$user,
			'Ancient',
			null,
			[ApiKeyScope::USER_READ_PROFILE],
			$now + 120,
		);
		$db->update(ApiKeysHelper::TABLE)
			->fields(['expires_at' => $now - 200 * 86400])
			->condition('id', $stale['key']->getId())
			->execute();

		ApiKeysHelper::checkExpirations($now);

		$this->assertSame(1, (int) $this->rowFor($oneWeek['key']->getKeyId())['warned_1w']);
		$this->assertSame(0, (int) $this->rowFor($oneWeek['key']->getKeyId())['warned_1d']);

		$dayRow = $this->rowFor($oneDay['key']->getKeyId());
		$this->assertSame(1, (int) $dayRow['warned_1w']);
		$this->assertSame(1, (int) $dayRow['warned_1d']);

		$farRow = $this->rowFor($far['key']->getKeyId());
		$this->assertSame(0, (int) $farRow['warned_1w']);
		$this->assertSame(0, (int) $farRow['warned_1d']);

		$this->assertSame(1, (int) $this->rowFor($expired['key']->getKeyId())['expired_notified']);

		$this->assertNull($this->rowFor($stale['key']->getKeyId()), 'stale row must be pruned');
	}

	#endregion
}
