<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\redis\ClientFactory;
use Exception;
use Throwable;

class RedisHelper
{
	private static $redis_client = null;
	private static $use_cache_fallback = false;

	private static function getRedisClient()
	{
		if (self::$redis_client !== null) {
			return self::$redis_client;
		}

		try {
			// Use the correct Redis ClientFactory method
			self::$redis_client = ClientFactory::getClient();
			return self::$redis_client;
		} catch (Exception $e) {
			Drupal::logger('mantle2')->warning(
				'Redis client not available, using cache fallback: %message',
				[
					'%message' => $e->getMessage(),
				],
			);
			self::$use_cache_fallback = true;
			return null;
		}
	}

	/**
	 * Store data in Redis with TTL
	 */
	public static function set(string $key, array $data, int $ttl = 900): bool
	{
		try {
			$redis = self::getRedisClient();
			$serializedData = json_encode($data);
			if ($redis && !self::$use_cache_fallback) {
				return $redis->setex($key, $ttl, $serializedData);
			} else {
				// Fallback to Drupal cache
				$cache = Drupal::cache('mantle2');
				$cache->set($key, $serializedData, time() + $ttl);
				return true;
			}
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Redis SET failed: %message', [
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Get data from Redis
	 */
	public static function get(string $key): ?array
	{
		try {
			$redis = self::getRedisClient();
			if ($redis && !self::$use_cache_fallback) {
				$data = $redis->get($key);
				if ($data === false) {
					return null;
				}
				return json_decode($data, true);
			} else {
				// Fallback to Drupal cache
				$cache = Drupal::cache('mantle2');
				$cached = $cache->get($key);
				if ($cached && $cached->expire > time()) {
					return json_decode($cached->data, true);
				}
				return null;
			}
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Redis GET failed: %message', [
				'%message' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Delete key(s) from Redis
	 * Supports:
	 *   - Single key: 'cloud:points:123'
	 *   - Glob pattern: 'cloud:*'
	 *   - Array of keys: ['key1', 'key2', 'cloud:*']
	 */
	public static function delete(mixed $key): bool
	{
		try {
			$redis = self::getRedisClient();
			$keys = is_array($key) ? $key : [$key];

			if (!$redis || self::$use_cache_fallback) {
				// Fallback to Drupal cache
				$cache = \Drupal::cache('mantle2');

				foreach ($keys as $k) {
					if (is_string($k) && str_contains($k, '*')) {
						\Drupal::logger('mantle2')->warning(
							'Glob pattern %pattern not supported in cache fallback mode',
							['%pattern' => $k],
						);
						continue;
					}
					$cache->delete($k);
				}
				return true;
			}

			$totalDeleted = 0;

			$scanCount = 1000; // hint to Redis for SCAN batch size
			$delChunk = 1000; // max keys per DEL call

			foreach ($keys as $k) {
				if (!is_string($k) || $k === '') {
					continue;
				}

				if (str_contains($k, '*')) {
					$it = null;

					do {
						// phpredis: scan(&$iterator, $pattern = null, $count = 0)
						$batch = $redis->scan($it, $k, $scanCount);

						if ($batch === false || empty($batch)) {
							continue;
						}

						// Chunk deletes to avoid huge argument lists
						foreach (array_chunk($batch, $delChunk) as $chunk) {
							// DEL returns number of keys removed
							$deleted = $redis->del(...$chunk);
							if (is_int($deleted)) {
								$totalDeleted += $deleted;
							}
						}
					} while ($it !== 0);
				}
				// Direct key path
				else {
					$deleted = $redis->del($k);
					if (is_int($deleted)) {
						$totalDeleted += $deleted;
					}
				}
			}

			return true;
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Redis DELETE failed: %message', [
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Check if key exists in Redis
	 */
	public static function exists(string $key): bool
	{
		try {
			$redis = self::getRedisClient();
			if ($redis && !self::$use_cache_fallback) {
				return $redis->exists($key) > 0;
			} else {
				// Fallback to Drupal cache
				$cache = Drupal::cache('mantle2');
				$cached = $cache->get($key);
				return $cached && $cached->expire > time();
			}
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Redis EXISTS failed: %message', [
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Get TTL for a key in seconds
	 */
	public static function ttl(string $key): int
	{
		try {
			$redis = self::getRedisClient();
			if ($redis && !self::$use_cache_fallback) {
				return $redis->ttl($key);
			} else {
				// Fallback to Drupal cache
				$cache = Drupal::cache('mantle2');
				$cached = $cache->get($key);
				if ($cached && $cached->expire > time()) {
					return $cached->expire - time();
				}
				return -1;
			}
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Redis TTL failed: %message', [
				'%message' => $e->getMessage(),
			]);
			return -1;
		}
	}

	/**
	 * List keys by pattern (glob)
	 * Returns an array of keys matching the pattern, or empty array if none found or on error
	 */
	public static function list(string $pattern): array
	{
		try {
			$redis = self::getRedisClient();
			if ($redis && !self::$use_cache_fallback) {
				return $redis->keys($pattern);
			} else {
				// Fallback to Drupal cache - not supported
				Drupal::logger('mantle2')->warning(
					'Glob pattern %pattern not supported in cache fallback mode',
					['%pattern' => $pattern],
				);
				return [];
			}
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Redis LIST failed: %message', [
				'%message' => $e->getMessage(),
			]);
			return [];
		}
	}

	public static function cache(?string $key, callable $callback, int $ttl = 900): array
	{
		// null or empty key implies no caching
		if ($key === null || empty($key)) {
			return $callback();
		}

		$data = self::get($key);
		if ($data !== null) {
			return $data;
		}

		$data = $callback();
		self::set($key, $data, $ttl);
		return $data;
	}
}
