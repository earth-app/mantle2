<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal;
use Drupal\mantle2\Service\FCMHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionMethod;
use RuntimeException;

class FCMHelperTest extends IntegrationTestBase
{
	// must match FCMHelper::ACCESS_TOKEN_KEY
	private const ACCESS_TOKEN_KEY = 'fcm:access_token';

	/** @var array<int, array> decoded service accounts handed to the fake oauth exchange */
	private array $exchanges = [];

	/** @var array<int, array> every fcm post the fake transport saw */
	private array $requests = [];

	protected function setUp(): void
	{
		parent::setUp();
		putenv('FCM_SERVICE_ACCOUNT_JSON');
		FCMHelper::setTokenFetchOverride(null);
		FCMHelper::setRequestOverride(null);
		FCMHelper::resetCaches();
	}

	protected function tearDown(): void
	{
		FCMHelper::setTokenFetchOverride(null);
		FCMHelper::setRequestOverride(null);
		FCMHelper::resetCaches();
		putenv('FCM_SERVICE_ACCOUNT_JSON');
		parent::tearDown();
	}

	private function loadCredentials(): ?string
	{
		$m = new ReflectionMethod(FCMHelper::class, 'loadCredentials');
		return $m->invoke(null);
	}

	private function removeInvalidToken(string $token, int $httpCode): void
	{
		$m = new ReflectionMethod(FCMHelper::class, 'removeInvalidToken');
		$m->invoke(null, $token, $httpCode);
	}

	// swaps extension.list.module for a stub whose getPath() points at $dir, so the
	// file branch of loadCredentials resolves to $dir/data/service-account.json
	private function withModulePath(string $dir, callable $fn): mixed
	{
		$original = $this->container->get('extension.list.module');
		$stub = new class ($dir) {
			public function __construct(private string $dir) {}

			public function getPath(string $module): string
			{
				return $this->dir;
			}
		};
		$this->container->set('extension.list.module', $stub);
		try {
			return $fn();
		} finally {
			$this->container->set('extension.list.module', $original);
		}
	}

	// swaps key.repository for a stub that counts getKey() calls, so a fan-out can prove
	// it loaded the service account once rather than once per device token
	private function countingKeyRepositoryCalls(string $value, callable $fn): int
	{
		$original = $this->container->get('key.repository');
		$stub = new class ($value) {
			public int $calls = 0;

			public function __construct(private string $value) {}

			public function getKey(string $id): object
			{
				$this->calls++;
				return new class ($this->value) {
					public function __construct(private string $value) {}

					public function getKeyValue(): string
					{
						return $this->value;
					}
				};
			}
		};
		$this->container->set('key.repository', $stub);
		try {
			$fn();
		} finally {
			$this->container->set('key.repository', $original);
		}
		return $stub->calls;
	}

	private function seedFcmKey(string $value): void
	{
		$storage = $this->container->get('entity_type.manager')->getStorage('key');
		$existing = $storage->load(FCMHelper::KEY_NAME);
		if ($existing) {
			$existing->delete();
		}
		$storage
			->create([
				'id' => FCMHelper::KEY_NAME,
				'label' => 'FCM Service Account',
				'key_type' => 'authentication',
				'key_provider' => 'config',
				'key_provider_settings' => ['key_value' => $value],
			])
			->save();
	}

	private const SERVICE_ACCOUNT = '{"type":"service_account","project_id":"demo","client_email":"push@demo.iam.gserviceaccount.com"}';

	// credentials that pass every guard; the oauth exchange itself is always faked below
	private function seedServiceAccount(): void
	{
		$this->seedFcmKey(self::SERVICE_ACCOUNT);
	}

	// records each oauth exchange and mints a distinct token per call
	private function fakeTokenExchange(int $expiresIn = 3600): void
	{
		$this->exchanges = [];
		FCMHelper::setTokenFetchOverride(function (array $credentials) use ($expiresIn): array {
			$this->exchanges[] = $credentials;
			return [
				'access_token' => 'access-' . count($this->exchanges),
				'expires_in' => $expiresIn,
			];
		});
	}

	// records each fcm post; the last queued response repeats, so one entry covers a fan-out
	private function fakeFcm(array ...$responses): void
	{
		if ($responses === []) {
			$responses = [
				['code' => 200, 'body' => '{"name":"projects/demo/messages/1"}', 'error' => ''],
			];
		}

		$this->requests = [];
		FCMHelper::setRequestOverride(function (
			string $url,
			string $accessToken,
			array $message,
		) use ($responses): array {
			$this->requests[] = [
				'url' => $url,
				'access_token' => $accessToken,
				'message' => $message,
			];
			$index = count($this->requests) - 1;
			return $responses[$index] ?? $responses[count($responses) - 1];
		});
	}

	/** @return string[] */
	private function accessTokensUsed(): array
	{
		return array_column($this->requests, 'access_token');
	}

	/** @return string[] */
	private function deviceTokensSent(): array
	{
		return array_map(fn(array $r) => $r['message']['message']['token'], $this->requests);
	}

	private function seedToken(string $token, int $userId = 7): void
	{
		Drupal::database()
			->insert('push_tokens')
			->fields([
				'user_id' => $userId,
				'platform' => 'ios',
				'token' => $token,
				'updated' => time(),
			])
			->execute();
	}

	private function tokenCount(): int
	{
		return (int) Drupal::database()
			->select('push_tokens', 't')
			->countQuery()
			->execute()
			->fetchField();
	}

	/** @return string[] */
	private function remainingTokens(): array
	{
		return Drupal::database()
			->select('push_tokens', 't')
			->fields('t', ['token'])
			->execute()
			->fetchCol();
	}

	// #region loadCredentials precedence

	#[Test]
	#[TestDox('loadCredentials prefers the key.repository value over env and file')]
	#[Group('mantle2/fcm')]
	public function loadCredentialsFromKey(): void
	{
		$this->seedFcmKey('{"from":"key"}');
		putenv('FCM_SERVICE_ACCOUNT_JSON={"from":"env"}');
		$this->assertSame('{"from":"key"}', $this->loadCredentials());
	}

	#[Test]
	#[TestDox('loadCredentials falls back to the env var when no key value is set')]
	#[Group('mantle2/fcm')]
	public function loadCredentialsFromEnv(): void
	{
		putenv('FCM_SERVICE_ACCOUNT_JSON={"from":"env"}');
		$this->assertSame('{"from":"env"}', $this->loadCredentials());
	}

	#[Test]
	#[TestDox('loadCredentials reads the bundled service-account.json when key and env are absent')]
	#[Group('mantle2/fcm')]
	public function loadCredentialsFromFile(): void
	{
		$dir = sys_get_temp_dir() . '/fcm-test-' . bin2hex(random_bytes(4));
		mkdir($dir . '/data', 0777, true);
		file_put_contents($dir . '/data/service-account.json', '{"from":"file"}');

		$result = $this->withModulePath($dir, fn() => $this->loadCredentials());
		$this->assertSame('{"from":"file"}', $result);

		unlink($dir . '/data/service-account.json');
		rmdir($dir . '/data');
		rmdir($dir);
	}

	#[Test]
	#[TestDox('loadCredentials returns null when no key, env, or bundled file is present')]
	#[Group('mantle2/fcm')]
	public function loadCredentialsNullWhenFileMissing(): void
	{
		$dir = sys_get_temp_dir() . '/fcm-test-' . bin2hex(random_bytes(4));
		mkdir($dir, 0777, true);

		$result = $this->withModulePath($dir, fn() => $this->loadCredentials());
		$this->assertNull($result);

		rmdir($dir);
	}

	// #endregion

	// #region send guards

	#[Test]
	#[TestDox('send returns quietly when no credentials are available')]
	#[Group('mantle2/fcm')]
	public function sendWithoutCredentials(): void
	{
		$dir = sys_get_temp_dir() . '/fcm-test-' . bin2hex(random_bytes(4));
		mkdir($dir, 0777, true);

		$this->seedToken('device-token');
		$this->withModulePath(
			$dir,
			fn() => FCMHelper::send('device-token', 'Title', 'Body', ['k' => 'v']),
		);

		// no-credentials branch returns before any network or token cleanup
		$this->assertSame(1, $this->tokenCount());

		rmdir($dir);
	}

	#[Test]
	#[TestDox('send returns on malformed credential JSON without throwing')]
	#[Group('mantle2/fcm')]
	public function sendWithMalformedJson(): void
	{
		$this->seedFcmKey('not-json{');
		$this->seedToken('device-token');

		FCMHelper::send('device-token', 'Title', 'Body');

		// the decode-error branch returns early, never reaching removeInvalidToken
		$this->assertSame(1, $this->tokenCount());
	}

	#[Test]
	#[TestDox('send returns when credentials are valid JSON but not a usable service account')]
	#[Group('mantle2/fcm')]
	public function sendWithIncompleteCreds(): void
	{
		// valid JSON, decodes fine, but ServiceAccountCredentials will reject it — the
		// try/catch (or missing project_id) branch must swallow it, never reach the network
		$this->seedFcmKey('{"project_id":"demo"}');
		$this->seedToken('device-token');

		FCMHelper::send('device-token', 'Title', 'Body');

		$this->assertSame(1, $this->tokenCount());
	}

	#[Test]
	#[TestDox('send skips the token exchange entirely when the service account has no project id')]
	#[Group('mantle2/fcm')]
	public function sendWithoutProjectId(): void
	{
		$this->seedFcmKey(
			'{"type":"service_account","client_email":"push@demo.iam.gserviceaccount.com"}',
		);
		$this->fakeTokenExchange();
		$this->fakeFcm();
		$this->seedToken('device-token');

		FCMHelper::send('device-token', 'Title', 'Body');

		$this->assertSame([], $this->exchanges);
		$this->assertSame([], $this->requests);
		$this->assertSame(1, $this->tokenCount());
	}

	#[Test]
	#[TestDox('send swallows a transport failure instead of throwing into the caller')]
	#[Group('mantle2/fcm')]
	public function sendSwallowsTransportException(): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->seedToken('device-token');
		FCMHelper::setRequestOverride(function (): array {
			throw new RuntimeException('socket exploded');
		});

		FCMHelper::send('device-token', 'Title', 'Body');

		$this->assertSame(1, $this->tokenCount());
	}

	#[Test]
	#[TestDox('send reports an unencodable payload without posting it')]
	#[Group('mantle2/fcm')]
	public function sendWithUnencodablePayload(): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->fakeFcm();
		$this->seedToken('device-token');

		// invalid utf-8 makes json_encode fail; the guard must stop before the post
		FCMHelper::send('device-token', "Title \xB1\x31", 'Body');

		$this->assertCount(1, $this->exchanges);
		$this->assertSame([], $this->requests);
		$this->assertSame(1, $this->tokenCount());
	}

	// #endregion

	// #region access token caching

	#[Test]
	#[TestDox('the access token is exchanged once and reused across two sends')]
	#[Group('mantle2/fcm')]
	public function accessTokenIsExchangedOncePerProcess(): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->fakeFcm();

		FCMHelper::send('tok-a', 'Title', 'Body');
		FCMHelper::send('tok-b', 'Title', 'Body');

		$this->assertCount(1, $this->exchanges);
		$this->assertCount(2, $this->requests);
		$this->assertSame(['access-1', 'access-1'], $this->accessTokensUsed());

		$cached = RedisHelper::get(self::ACCESS_TOKEN_KEY);
		$this->assertIsArray($cached);
		$this->assertSame('access-1', $cached['token']);
		$this->assertGreaterThan(time(), $cached['expires']);
		// 3600s lifetime minus the 60s safety margin
		$this->assertLessThanOrEqual(time() + 3540, $cached['expires']);
	}

	public static function tokenLifetimes(): array
	{
		return [
			'one hour' => [3600, 3540],
			'form-encoded numeric string' => ['120', 60],
			'shorter than the safety margin' => [30, 1],
			'absent from the response' => [null, 3540],
		];
	}

	#[Test]
	#[TestDox('the cached token retires one safety margin before FCM expires it')]
	#[Group('mantle2/fcm')]
	#[DataProvider('tokenLifetimes')]
	public function cachedTokenLifetimeFollowsExpiresIn(mixed $expiresIn, int $expectedTtl): void
	{
		$this->seedServiceAccount();
		$this->fakeFcm();
		FCMHelper::setTokenFetchOverride(function (array $credentials) use ($expiresIn): array {
			$auth = ['access_token' => 'access-1'];
			if ($expiresIn !== null) {
				$auth['expires_in'] = $expiresIn;
			}
			return $auth;
		});

		$before = time();
		FCMHelper::send('tok-a', 'Title', 'Body');
		$after = time();

		$this->assertSame(['access-1'], $this->accessTokensUsed());

		$cached = RedisHelper::get(self::ACCESS_TOKEN_KEY);
		$this->assertIsArray($cached);
		$this->assertGreaterThanOrEqual($before + $expectedTtl, $cached['expires']);
		$this->assertLessThanOrEqual($after + $expectedTtl, $cached['expires']);
	}

	#[Test]
	#[TestDox('a cached access token inside its lifetime is reused without an exchange')]
	#[Group('mantle2/fcm')]
	public function cachedAccessTokenIsReusedWithinLifetime(): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->fakeFcm();

		RedisHelper::set(
			self::ACCESS_TOKEN_KEY,
			['token' => 'redis-token', 'expires' => time() + 300],
			300,
		);

		FCMHelper::send('tok-a', 'Title', 'Body');

		$this->assertSame([], $this->exchanges);
		$this->assertSame(['redis-token'], $this->accessTokensUsed());
	}

	#[Test]
	#[TestDox('a cached access token past its lifetime is exchanged again')]
	#[Group('mantle2/fcm')]
	public function expiredAccessTokenIsExchangedAgain(): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->fakeFcm();

		// the cache entry itself is still alive; only the embedded lifetime has passed
		RedisHelper::set(
			self::ACCESS_TOKEN_KEY,
			['token' => 'stale-token', 'expires' => time() - 5],
			300,
		);

		FCMHelper::send('tok-a', 'Title', 'Body');

		$this->assertCount(1, $this->exchanges);
		$this->assertSame(['access-1'], $this->accessTokensUsed());

		$cached = RedisHelper::get(self::ACCESS_TOKEN_KEY);
		$this->assertIsArray($cached);
		$this->assertSame('access-1', $cached['token']);
	}

	#[Test]
	#[TestDox('a 401 invalidates the cached token and replays the message exactly once')]
	#[Group('mantle2/fcm')]
	public function unauthorizedRefreshesTokenAndRetriesOnce(): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->fakeFcm(
			['code' => 401, 'body' => '{"error":{"status":"UNAUTHENTICATED"}}', 'error' => ''],
			['code' => 200, 'body' => '{"name":"projects/demo/messages/1"}', 'error' => ''],
		);
		$this->seedToken('tok-a');

		FCMHelper::send('tok-a', 'Title', 'Body');

		$this->assertCount(2, $this->exchanges);
		$this->assertSame(['access-1', 'access-2'], $this->accessTokensUsed());
		$this->assertSame(['tok-a', 'tok-a'], $this->deviceTokensSent());
		// a 401 is an auth failure, never a dead device
		$this->assertSame(1, $this->tokenCount());

		$cached = RedisHelper::get(self::ACCESS_TOKEN_KEY);
		$this->assertIsArray($cached);
		$this->assertSame('access-2', $cached['token']);
	}

	#[Test]
	#[TestDox('a permanent 401 refreshes the token once per fan-out, not once per token')]
	#[Group('mantle2/fcm')]
	public function permanentUnauthorizedRetriesOnlyOncePerFanOut(): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->fakeFcm(['code' => 401, 'body' => '', 'error' => '']);
		// push_tokens is unique on (user_id, platform), so one row per user
		$this->seedToken('tok-a', 7);
		$this->seedToken('tok-b', 8);
		$this->seedToken('tok-c', 9);

		FCMHelper::sendMulticast(['tok-a', 'tok-b', 'tok-c'], 'Title', 'Body');

		// tok-a posts twice (the single replay), tok-b and tok-c once each
		$this->assertCount(4, $this->requests);
		$this->assertCount(2, $this->exchanges);
		$this->assertSame(3, $this->tokenCount());
	}

	// #endregion

	// #region batch send

	#[Test]
	#[TestDox('sendMulticast delivers three tokens on one token exchange')]
	#[Group('mantle2/fcm')]
	public function sendMulticastUsesOneTokenExchange(): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->fakeFcm();

		FCMHelper::sendMulticast(['tok-a', 'tok-b', 'tok-c'], 'Title', 'Body', [
			'type' => 'FRIEND_REQUEST',
		]);

		$this->assertCount(1, $this->exchanges);
		$this->assertCount(3, $this->requests);
		$this->assertSame(['access-1', 'access-1', 'access-1'], $this->accessTokensUsed());
		$this->assertSame(['tok-a', 'tok-b', 'tok-c'], $this->deviceTokensSent());

		$first = $this->requests[0];
		$this->assertSame(
			'https://fcm.googleapis.com/v1/projects/demo/messages:send',
			$first['url'],
		);
		$this->assertSame(
			['title' => 'Title', 'body' => 'Body'],
			$first['message']['message']['notification'],
		);
		$this->assertSame(['type' => 'FRIEND_REQUEST'], $first['message']['message']['data']);
	}

	#[Test]
	#[TestDox('sendMulticast skips empty lists, blank tokens, and duplicates')]
	#[Group('mantle2/fcm')]
	public function sendMulticastSkipsBlankAndDuplicateTokens(): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->fakeFcm();

		FCMHelper::sendMulticast([], 'Title', 'Body');
		$this->assertSame([], $this->exchanges);
		$this->assertSame([], $this->requests);

		FCMHelper::sendMulticast(['tok-a', '', 'tok-a', 'tok-b'], 'Title', 'Body');
		$this->assertCount(1, $this->exchanges);
		$this->assertSame(['tok-a', 'tok-b'], $this->deviceTokensSent());
	}

	#[Test]
	#[TestDox('the service account is loaded and decoded once per fan-out, not per token')]
	#[Group('mantle2/fcm')]
	public function credentialsAreDecodedOncePerFanOut(): void
	{
		$this->fakeTokenExchange();
		$this->fakeFcm();

		$calls = $this->countingKeyRepositoryCalls(self::SERVICE_ACCOUNT, function (): void {
			FCMHelper::sendMulticast(['tok-a', 'tok-b', 'tok-c'], 'Title', 'Body');
			FCMHelper::send('tok-d', 'Title', 'Body');
		});

		$this->assertSame(1, $calls);
		$this->assertCount(4, $this->requests);
		$this->assertCount(1, $this->exchanges);
	}

	// #endregion

	// #region invalid token pruning

	public static function fcmFailureCodes(): array
	{
		return [
			'404 unregistered' => [404, '{"error":{"status":"UNREGISTERED"}}', true],
			'403 sender id mismatch' => [403, '{"error":{"status":"SENDER_ID_MISMATCH"}}', true],
			'400 invalid registration token' => [
				400,
				'{"error":{"status":"INVALID_ARGUMENT","details":[{"fieldViolations":[{"field":"message.token","description":"Invalid registration token"}]}]}}',
				true,
			],
			'400 invalid payload' => [
				400,
				'{"error":{"status":"INVALID_ARGUMENT","details":[{"fieldViolations":[{"field":"message.data"}]}]}}',
				false,
			],
			'429 quota exceeded' => [429, '{"error":{"status":"QUOTA_EXCEEDED"}}', false],
			'500 internal' => [500, '{"error":{"status":"INTERNAL"}}', false],
			'503 unavailable' => [503, '{"error":{"status":"UNAVAILABLE"}}', false],
		];
	}

	#[Test]
	#[TestDox('send prunes the push token only on the codes FCM uses to report a dead device')]
	#[Group('mantle2/fcm')]
	#[DataProvider('fcmFailureCodes')]
	public function sendPrunesOnlyDeadTokens(int $code, string $body, bool $pruned): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->fakeFcm(['code' => $code, 'body' => $body, 'error' => '']);
		$this->seedToken('dead-token', 7);
		$this->seedToken('other-token', 9);

		FCMHelper::send('dead-token', 'Title', 'Body');

		$remaining = $this->remainingTokens();
		if ($pruned) {
			$this->assertNotContains('dead-token', $remaining);
		} else {
			$this->assertContains('dead-token', $remaining);
		}
		$this->assertContains('other-token', $remaining);
	}

	#[Test]
	#[TestDox('a cURL-level failure never prunes the push token')]
	#[Group('mantle2/fcm')]
	public function transportErrorDoesNotPrune(): void
	{
		$this->seedServiceAccount();
		$this->fakeTokenExchange();
		$this->fakeFcm([
			'code' => 0,
			'body' => '',
			'error' => 'Failed to connect to fcm.googleapis.com',
		]);
		$this->seedToken('tok-a');

		FCMHelper::send('tok-a', 'Title', 'Body');

		$this->assertSame(1, $this->tokenCount());
	}

	#[Test]
	#[TestDox('removeInvalidToken deletes the matching push_tokens row')]
	#[Group('mantle2/fcm')]
	public function removeInvalidTokenDeletesRow(): void
	{
		$db = Drupal::database();
		$db->insert('push_tokens')
			->fields([
				'user_id' => 42,
				'platform' => 'ios',
				'token' => 'stale-token',
				'updated' => time(),
			])
			->execute();
		$db->insert('push_tokens')
			->fields([
				'user_id' => 43,
				'platform' => 'android',
				'token' => 'good-token',
				'updated' => time(),
			])
			->execute();

		$this->removeInvalidToken('stale-token', 404);

		$remaining = $db
			->select('push_tokens', 't')
			->fields('t', ['token'])
			->execute()
			->fetchCol();
		$this->assertNotContains('stale-token', $remaining);
		$this->assertContains('good-token', $remaining);
	}

	#[Test]
	#[TestDox('removeInvalidToken is a no-op for an unknown token')]
	#[Group('mantle2/fcm')]
	public function removeInvalidTokenUnknown(): void
	{
		$db = Drupal::database();
		$db->insert('push_tokens')
			->fields([
				'user_id' => 44,
				'platform' => 'ios',
				'token' => 'keep-me',
				'updated' => time(),
			])
			->execute();

		$this->removeInvalidToken('never-existed', 403);

		$count = (int) $db->select('push_tokens', 't')->countQuery()->execute()->fetchField();
		$this->assertSame(1, $count);
	}

	// #endregion
}
