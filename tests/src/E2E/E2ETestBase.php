<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Service\RedisHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;

abstract class E2ETestBase extends IntegrationTestBase
{
	protected string $cloudEndpoint;

	protected function setUp(): void
	{
		$this->cloudEndpoint = getenv('MANTLE2_CLOUD_ENDPOINT') ?: 'http://127.0.0.1:9898';

		if (!$this->cloudReachable()) {
			if (getenv('CI')) {
				self::fail(
					'Cloud worker unreachable at ' . $this->cloudEndpoint . ' (required in CI)',
				);
			}
			self::markTestSkipped('Cloud worker not reachable at ' . $this->cloudEndpoint);
		}

		parent::setUp();

		// point CloudHelper at the live worker
		$this->setSetting('mantle2.cloud_endpoint', $this->cloudEndpoint);

		// admin key must match the worker's ADMIN_API_KEY (dev/ci default: test-admin-key)
		$this->setAdminKey(getenv('MANTLE2_ADMIN_KEY') ?: 'test-admin-key');

		// wire RedisHelper to the real redis instance
		$host = getenv('MANTLE2_REDIS_HOST') ?: '127.0.0.1';
		$port = (int) (getenv('MANTLE2_REDIS_PORT') ?: 6379);
		$this->enableRealRedis($host, $port);
	}

	protected function enableRealRedis(string $host, int $port): void
	{
		if (!extension_loaded('redis')) {
			return;
		}
		$this->setSetting('redis.connection', [
			'interface' => 'PhpRedis',
			'host' => $host,
			'port' => $port,
		]);
		RedisHelper::reset();
	}

	protected function cloudReachable(): bool
	{
		$ch = curl_init($this->cloudEndpoint . '/');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_exec($ch);
		$errno = curl_errno($ch);
		unset($ch);
		return $errno === 0;
	}
}
