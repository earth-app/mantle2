<?php

namespace Drupal\Tests\mantle2;

use Drupal\Component\FileCache\FileCacheBackendInterface;

/**
 * Process-lifetime FileCache backend for kernel tests.
 *
 * Drupal parses every module .info.yml, *.services.yml, and plugin definition
 * on each kernel boot, and FileCache is what normally spares it that work.
 * KernelTestBase only wires a real backend when APCu is loaded, and it calls
 * FileCache::reset() in tearDown, so a test runner without APCu re-parses the
 * whole tree for every single test.
 *
 * Keeping the parsed data in a static array survives that reset and makes the
 * cost fall on the first test in a worker instead of all of them. Entries are
 * still validated against the file mtime by FileCache itself, so an edited file
 * is picked up the same way it would be with APCu.
 */
class StaticFileCacheBackend implements FileCacheBackendInterface
{
	/** @var array<string,mixed> */
	private static array $store = [];

	public function __construct(array $configuration = []) {}

	public function fetch(array $cids)
	{
		return array_intersect_key(self::$store, array_flip($cids));
	}

	public function store($cid, $data)
	{
		self::$store[$cid] = $data;
	}

	public function delete($cid)
	{
		unset(self::$store[$cid]);
	}

	/**
	 * Number of parsed files held for this process.
	 */
	public static function size(): int
	{
		return count(self::$store);
	}

	/**
	 * Drops everything; only needed by tests of this backend.
	 */
	public static function reset(): void
	{
		self::$store = [];
	}
}
