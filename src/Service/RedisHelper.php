<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Exception;

class RedisHelper
{
	private static function getRedisClient()
	{
		$redis = Drupal::service('redis.factory')->get();
		return $redis;
	}

	/**
	 * Store data in Redis with TTL
	 */
	public static function set(string $key, array $data, int $ttl = 900): bool
	{
		try {
			$redis = self::getRedisClient();
			$serializedData = json_encode($data);
			return $redis->setex($key, $ttl, $serializedData);
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
			$data = $redis->get($key);
			if ($data === false) {
				return null;
			}
			return json_decode($data, true);
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
			return $redis->del($key) > 0;
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
			return $redis->exists($key) > 0;
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
			return $redis->ttl($key);
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Redis TTL failed: %message', [
				'%message' => $e->getMessage(),
			]);
			return -1;
		}
	}
}
