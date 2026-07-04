<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Service\RedisHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class RedisHelperTest extends IntegrationTestBase
{
	#[Test]
	#[TestDox('set then get round-trips a structured value through the cache fallback')]
	#[Group('mantle2/redis')]
	public function setAndGet(): void
	{
		$payload = ['name' => 'earth', 'nested' => ['a' => 1, 'b' => [2, 3]], 'flag' => true];
		$this->assertTrue(RedisHelper::set('rk:round', $payload, 120));
		$this->assertSame($payload, RedisHelper::get('rk:round'));
	}

	#[Test]
	#[TestDox('get returns null for a missing key')]
	#[Group('mantle2/redis')]
	public function getMissing(): void
	{
		$this->assertNull(RedisHelper::get('rk:absent'));
	}

	#[Test]
	#[TestDox('exists reflects presence and absence of a key')]
	#[Group('mantle2/redis')]
	public function exists(): void
	{
		$this->assertFalse(RedisHelper::exists('rk:exists'));
		RedisHelper::set('rk:exists', ['v' => 1], 120);
		$this->assertTrue(RedisHelper::exists('rk:exists'));
	}

	#[Test]
	#[TestDox('delete removes a single key and reports success')]
	#[Group('mantle2/redis')]
	public function deleteSingle(): void
	{
		RedisHelper::set('rk:del', ['v' => 1], 120);
		$this->assertTrue(RedisHelper::exists('rk:del'));
		$this->assertTrue(RedisHelper::delete('rk:del'));
		$this->assertFalse(RedisHelper::exists('rk:del'));
		$this->assertNull(RedisHelper::get('rk:del'));
	}

	#[Test]
	#[TestDox('delete accepts an array of keys and deleting a missing key is a no-op success')]
	#[Group('mantle2/redis')]
	public function deleteArrayAndMissing(): void
	{
		RedisHelper::set('rk:a', ['v' => 1], 120);
		RedisHelper::set('rk:b', ['v' => 2], 120);
		$this->assertTrue(RedisHelper::delete(['rk:a', 'rk:b']));
		$this->assertFalse(RedisHelper::exists('rk:a'));
		$this->assertFalse(RedisHelper::exists('rk:b'));

		$this->assertTrue(RedisHelper::delete('rk:never-existed'));
	}

	#[Test]
	#[TestDox('ttl returns the remaining lifetime for a live key and -1 for a missing one')]
	#[Group('mantle2/redis')]
	public function ttl(): void
	{
		$this->assertSame(-1, RedisHelper::ttl('rk:ttl-missing'));

		RedisHelper::set('rk:ttl', ['v' => 1], 300);
		$ttl = RedisHelper::ttl('rk:ttl');
		$this->assertGreaterThan(0, $ttl);
		$this->assertLessThanOrEqual(300, $ttl);
	}

	#[Test]
	#[TestDox('cache stores the callback result on a miss and serves it on the next hit')]
	#[Group('mantle2/redis')]
	public function cacheMemoizes(): void
	{
		$calls = 0;
		$producer = function () use (&$calls) {
			$calls++;
			return ['value' => 'computed'];
		};

		$first = RedisHelper::cache('rk:cache', $producer, 120);
		$second = RedisHelper::cache('rk:cache', $producer, 120);

		$this->assertSame(['value' => 'computed'], $first);
		$this->assertSame(['value' => 'computed'], $second);
		$this->assertSame(1, $calls);
	}

	#[Test]
	#[TestDox('cache bypasses storage for a null or empty key and never memoizes')]
	#[Group('mantle2/redis')]
	public function cacheBypassesEmptyKey(): void
	{
		$calls = 0;
		$producer = function () use (&$calls) {
			$calls++;
			return ['value' => $calls];
		};

		$this->assertSame(['value' => 1], RedisHelper::cache(null, $producer));
		$this->assertSame(['value' => 2], RedisHelper::cache('', $producer));
		$this->assertSame(2, $calls);
	}

	#[Test]
	#[TestDox('cache treats a cached empty array as a miss and recomputes')]
	#[Group('mantle2/redis')]
	public function cacheEmptyArrayIsMiss(): void
	{
		$calls = 0;
		$producer = function () use (&$calls) {
			$calls++;
			return [];
		};

		$this->assertSame([], RedisHelper::cache('rk:empty', $producer, 120));
		$this->assertSame([], RedisHelper::cache('rk:empty', $producer, 120));
		$this->assertSame(2, $calls);
	}

	// glob delete / list are unsupported in fallback mode (real redis only, covered by e2e)

	#[Test]
	#[TestDox('delete with a glob pattern is a graceful no-op in fallback mode')]
	#[Group('mantle2/redis')]
	public function globDeleteIsGracefulNoop(): void
	{
		RedisHelper::set('rk:glob:1', ['v' => 1], 120);
		RedisHelper::set('rk:glob:2', ['v' => 2], 120);

		$this->assertTrue(RedisHelper::delete('rk:glob:*'));
		// fallback cannot expand globs, so the keys survive
		$this->assertTrue(RedisHelper::exists('rk:glob:1'));
		$this->assertTrue(RedisHelper::exists('rk:glob:2'));
	}

	#[Test]
	#[TestDox('list returns an empty array in fallback mode regardless of matches')]
	#[Group('mantle2/redis')]
	public function listIsEmptyInFallback(): void
	{
		RedisHelper::set('rk:list:1', ['v' => 1], 120);
		RedisHelper::set('rk:list:2', ['v' => 2], 120);

		$this->assertSame([], RedisHelper::list('rk:list:*'));
	}
}
