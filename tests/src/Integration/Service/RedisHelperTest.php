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

	// list is still unsupported in fallback mode (real redis only, covered by e2e)

	#[Test]
	#[TestDox('delete with a glob pattern clears matching keys in fallback mode')]
	#[Group('mantle2/redis')]
	public function globDeleteExpandsInFallback(): void
	{
		RedisHelper::set('rk:glob:1', ['v' => 1], 120);
		RedisHelper::set('rk:glob:2', ['v' => 2], 120);

		// a silent no-op here left partitioned response caches stale forever
		$this->assertTrue(RedisHelper::delete('rk:glob:*'));
		$this->assertFalse(RedisHelper::exists('rk:glob:1'));
		$this->assertFalse(RedisHelper::exists('rk:glob:2'));
	}

	#[Test]
	#[TestDox('A glob delete leaves keys outside the pattern alone')]
	#[Group('mantle2/redis')]
	public function globDeleteIsScoped(): void
	{
		RedisHelper::set('rk:glob:1', ['v' => 1], 120);
		RedisHelper::set('rk:other:1', ['v' => 2], 120);

		RedisHelper::delete('rk:glob:*');

		$this->assertFalse(RedisHelper::exists('rk:glob:1'));
		// the fallback bin also holds application state, so over-clearing is not acceptable
		$this->assertTrue(RedisHelper::exists('rk:other:1'));
	}

	#[Test]
	#[TestDox('list resolves a glob from the tracked key index in fallback mode')]
	#[Group('mantle2/redis')]
	public function listResolvesGlobsInFallback(): void
	{
		// regression: this returned [] unconditionally, which silently stopped every
		// cron sweep that scans for keys (UsersHelper::checkAccountTrials)
		RedisHelper::set('rk:list:1', ['v' => 1], 120);
		RedisHelper::set('rk:list:2', ['v' => 2], 120);
		RedisHelper::set('rk:other:1', ['v' => 3], 120);

		$matches = RedisHelper::list('rk:list:*');
		sort($matches);

		$this->assertSame(['rk:list:1', 'rk:list:2'], $matches);
	}

	#[Test]
	#[TestDox('list omits deleted and expired keys still sitting in the index')]
	#[Group('mantle2/redis')]
	public function listOmitsStaleKeys(): void
	{
		RedisHelper::set('rk:stale:kept', ['v' => 1], 120);
		RedisHelper::set('rk:stale:gone', ['v' => 2], 120);
		RedisHelper::delete('rk:stale:gone');

		$this->assertSame(['rk:stale:kept'], RedisHelper::list('rk:stale:*'));
	}

	#[Test]
	#[TestDox('list returns an empty array when nothing matches')]
	#[Group('mantle2/redis')]
	public function listReturnsNothingWithoutAMatch(): void
	{
		RedisHelper::set('rk:list:1', ['v' => 1], 120);

		$this->assertSame([], RedisHelper::list('rk:nomatch:*'));
	}
}
