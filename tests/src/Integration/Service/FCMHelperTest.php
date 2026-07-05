<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal;
use Drupal\mantle2\Service\FCMHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionMethod;

class FCMHelperTest extends IntegrationTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		putenv('FCM_SERVICE_ACCOUNT_JSON');
	}

	protected function tearDown(): void
	{
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

	// loadCredentials precedence

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

	// send guards

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

	// removeInvalidToken

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
}
