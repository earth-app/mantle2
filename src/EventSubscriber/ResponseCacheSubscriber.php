<?php

namespace Drupal\mantle2\EventSubscriber;

use Drupal;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Yaml\Yaml;

class ResponseCacheSubscriber implements EventSubscriberInterface
{
	private static $config = null;

	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::REQUEST => ['onRequest', 10],
			KernelEvents::RESPONSE => ['onResponse', -10],
		];
	}

	private static function loadConfig(): array
	{
		if (self::$config !== null) {
			return self::$config;
		}

		$configPath = dirname(__DIR__, 3) . '/mantle2.caching.yml';
		if (!file_exists($configPath)) {
			self::$config = ['cache' => []];
			return self::$config;
		}

		self::$config = Yaml::parseFile($configPath);
		return self::$config;
	}

	private static function shouldExclude(string $path): bool
	{
		$config = self::loadConfig();
		$exclusions = $config['cache']['exclusions'] ?? [];

		foreach ($exclusions as $pattern) {
			if (str_contains($path, $pattern)) {
				return true;
			}
		}

		return false;
	}

	private static function findRetrievalConfig(string $path, string $method): ?array
	{
		$config = self::loadConfig();
		$retrievals = $config['cache']['retrievals'] ?? [];

		foreach ($retrievals as $retrieval) {
			if (
				in_array($method, $retrieval['methods']) &&
				preg_match('#' . $retrieval['route'] . '#', $path)
			) {
				return $retrieval;
			}
		}

		return null;
	}

	private static function findUpdateConfig(string $path, string $method): ?array
	{
		$config = self::loadConfig();
		$updates = $config['cache']['updates'] ?? [];

		foreach ($updates as $update) {
			if (
				in_array($method, $update['methods']) &&
				preg_match('#' . $update['route'] . '#', $path)
			) {
				return $update;
			}
		}

		return null;
	}

	private static function findDeleteConfig(string $path, string $method): ?array
	{
		$config = self::loadConfig();
		$deletes = $config['cache']['deletes'] ?? [];

		foreach ($deletes as $delete) {
			if (
				in_array($method, $delete['methods']) &&
				preg_match('#' . $delete['route'] . '#', $path)
			) {
				return $delete;
			}
		}

		return null;
	}

	private static function buildCacheKey(string $template, array $params): string
	{
		$key = $template;

		foreach ($params as $param => $value) {
			$key = str_replace('{' . $param . '}', (string) $value, $key);
		}

		return preg_replace('/\{[^}]+\}/', '', $key);
	}

	private static function extractPathParams(string $route, string $path): array
	{
		$params = [];

		if (preg_match('#' . $route . '#', $path, $matches)) {
			// Support multiple capture groups (uid, eid, etc.) and normalize numeric ids to ints
			foreach ($matches as $i => $match) {
				if ($i === 0) {
					continue; // skip full match
				}
				if (is_numeric($match)) {
					$val = (int) $match;
					// assign common placeholders to numeric captures where appropriate
					$params['uid'] = $params['uid'] ?? $val;
					$params['pid'] = $params['pid'] ?? $val;
					$params['aid'] = $params['aid'] ?? $val;
					$params['eid'] = $params['eid'] ?? $val;
				} else {
					// non-numeric capture is treated as username
					$user = UsersHelper::findByUsername($match);
					if ($user) {
						$params['uid'] = $user->id();
					}
				}
			}
		}

		return $params;
	}

	private static function invalidatePatterns(array $patterns, array $params): void
	{
		foreach ($patterns as $pattern) {
			$keyPattern = self::buildCacheKey($pattern, $params);

			if (str_contains($keyPattern, '*')) {
				$prefix = str_replace('*', '', $keyPattern);
				self::deleteByPrefix($prefix);
			} else {
				RedisHelper::delete($keyPattern);
			}
		}
	}

	private static function deleteByPrefix(string $prefix): void
	{
		try {
			$redis = \Drupal\redis\ClientFactory::getClient();
			if ($redis) {
				$keys = $redis->keys($prefix . '*');
				if (!empty($keys)) {
					$redis->del($keys);
				}
			}
		} catch (\Exception $e) {
			Drupal::logger('mantle2')->warning('Cache prefix deletion failed: @message', [
				'@message' => $e->getMessage(),
			]);
		}
	}

	private static function applyPlaceholders(array $params, Request $request): array
	{
		$params['page'] = $request->query->get('page', 1);
		$params['limit'] = $request->query->get('limit', 25);
		$params['search'] = md5($request->query->get('search', ''));
		$params['sort'] = $request->query->get('sort', 'desc');
		$params['size'] = $request->query->get('size', 1024);
		$params['read'] = $request->query->get('read', 'all');
		$params['activities'] = md5($request->query->get('activities', ''));
		$params['type'] = $request->query->get('type', 'all');

		$requester = UsersHelper::findByRequest($request);
		$params['req_uid'] = $requester ? $requester->id() : 0;

		return $params;
	}

	public function onRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();
		$method = $request->getMethod();
		$path = $request->getPathInfo();

		if ($method !== 'GET' || self::shouldExclude($path)) {
			return;
		}

		$config = self::findRetrievalConfig($path, $method);
		if (!$config) {
			return;
		}

		$params = self::extractPathParams($config['route'], $path);
		$params = self::applyPlaceholders($params, $request);

		$cacheKey = self::buildCacheKey($config['key_template'], $params);
		$cached = RedisHelper::get($cacheKey);

		if ($cached !== null) {
			$response = new JsonResponse($cached, 200);
			$response->headers->set('X-Cache', 'HIT');
			$event->setResponse($response);
		}
	}

	public function onResponse(ResponseEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();
		$response = $event->getResponse();
		$method = $request->getMethod();
		$path = $request->getPathInfo();
		$status = $response->getStatusCode();

		if (self::shouldExclude($path)) {
			return;
		}

		if ($method === 'GET' && $response instanceof JsonResponse && $status === 200) {
			$config = self::findRetrievalConfig($path, $method);
			if (!$config) {
				return;
			}

			// placeholder values for cache key generation
			$params = self::extractPathParams($config['route'], $path);
			$params = self::applyPlaceholders($params, $request);

			$cacheKey = self::buildCacheKey($config['key_template'], $params);
			$data = json_decode($response->getContent(), true);

			if ($data !== null) {
				RedisHelper::set($cacheKey, $data, $config['ttl'] ?? 300);
				$response->headers->set('X-Cache', 'MISS');
			}
		} elseif (in_array($method, ['POST', 'PATCH', 'PUT']) && in_array($status, [200, 201])) {
			$config = self::findUpdateConfig($path, $method);
			if ($config) {
				$params = self::extractPathParams($config['route'], $path);
				$params = self::applyPlaceholders($params, $request);

				// additional params from response if needed
				if ($response instanceof JsonResponse) {
					$data = json_decode($response->getContent(), true);
					if (isset($data['friend_id'])) {
						$params['friend_uid'] = $data['friend_id'];
					}
				}

				self::invalidatePatterns($config['invalidate_patterns'], $params);
			}
		} elseif (in_array($method, ['DELETE']) && in_array($status, [200, 204])) {
			$config = self::findDeleteConfig($path, $method);
			if ($config) {
				$params = self::extractPathParams($config['route'], $path);
				$params = self::applyPlaceholders($params, $request);

				self::invalidatePatterns($config['invalidate_patterns'], $params);
			}
		}
	}
}
