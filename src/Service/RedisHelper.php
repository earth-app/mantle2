<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\redis\ClientFactory;
use Exception;

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
	 * Delete key from Redis
	 */
	public static function delete(string $key): bool
	{
		try {
			$redis = self::getRedisClient();
			if ($redis && !self::$use_cache_fallback) {
				return $redis->del($key) > 0;
			} else {
				// Fallback to Drupal cache
				$cache = Drupal::cache('mantle2');
				$cache->delete($key);
				return true;
			}
		} catch (Exception $e) {
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
	 * Get TTL for a key
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

	public static function cache(string $key, callable $callback, int $ttl = 900): array
	{
		$data = self::get($key);
		if ($data !== null) {
			return $data;
		}

		$data = $callback();
		self::set($key, $data, $ttl);
		return $data;
	}
}
