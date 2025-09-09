<?php

namespace Drupal\mantle2\EventSubscriber;

use DateInterval;
use DateTimeImmutable;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\mantle2\Service\UsersHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RateLimitSubscriber implements EventSubscriberInterface
{
	private KeyValueStoreExpirableInterface $store;

	public function __construct(KeyValueExpirableFactoryInterface $kvFactory)
	{
		// Use a dedicated expirable keyvalue collection to persist counters per window
		$this->store = $kvFactory->get('mantle2_ratelimit');
	}

	public static function getSubscribedEvents(): array
	{
		// Run early to short-circuit expensive work when rate limited.
		return [
			KernelEvents::REQUEST => ['onKernelRequest', 300],
		];
	}

	public function onKernelRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();

		// Only rate limit API routes under /v2 and /openapi or /swagger-ui are excluded.
		$path = $request->getPathInfo() ?? '';
		if (!str_starts_with($path, '/v2/')) {
			return;
		}

		$ip =
			$request->headers->get('CF-Connecting-IP') ??
			(($request->headers->get('X-Forwarded-For')
				? trim(explode(',', $request->headers->get('X-Forwarded-For'))[0])
				: null) ??
				($request->getClientIp() ?? 'anonymous'));

		$isAuthenticated = UsersHelper::getOwnerOfRequest($request) !== null;
		$globalConfig = $this->getGlobalConfig($isAuthenticated);
		$globalCheck = $this->checkLimit(
			$isAuthenticated ? 'global:auth' : 'global:anon',
			$globalConfig['limit'],
			$this->intervalToSeconds($globalConfig['interval']),
			$ip,
		);
		if (!$globalCheck['allowed']) {
			$response = $this->build429Array($globalCheck, 'Global rate limit exceeded');
			$this->setRateHeadersArray($response, $globalCheck, prefix: 'X-Global-RateLimit-');
			$event->setResponse($response);
			return;
		}

		// Per-endpoint limiter (if configured for this route)
		$routeName = (string) $request->attributes->get('_route');
		$endpointConfig = $this->getEndpointConfig($routeName);
		if ($endpointConfig) {
			$endpointCheck = $this->checkLimit(
				'route:' . $routeName,
				$endpointConfig['limit'],
				$this->intervalToSeconds($endpointConfig['interval']),
				$ip,
			);
			$request->attributes->set('_mantle2_rl_endpoint_result', $endpointCheck);
			$request->attributes->set('_mantle2_rl_endpoint_limit', $endpointConfig['limit']);
			$request->attributes->set('_mantle2_rl_endpoint_interval', $endpointConfig['interval']);

			if (!$endpointCheck['allowed']) {
				$response = $this->build429Array($endpointCheck, 'Rate limit exceeded');
				$this->setRateHeadersArray($response, $endpointCheck);
				$this->setRateHeadersArray($response, $globalCheck, prefix: 'X-Global-RateLimit-');
				$event->setResponse($response);
				return;
			}
			$endpointResult = $endpointCheck; // for headers below
		}

		// If accepted, add headers on kernel.request (they'll be included in final response)
		if (isset($endpointResult)) {
			$request->attributes->set('_mantle2_rl_headers', [
				'endpoint' => [
					$endpointResult,
					$endpointConfig['limit'],
					$endpointConfig['interval'],
				],
				'global' => [$globalCheck, $globalConfig['limit'], $globalConfig['interval']],
			]);
		} else {
			$request->attributes->set('_mantle2_rl_headers', [
				'global' => [$globalCheck, $globalConfig['limit'], $globalConfig['interval']],
			]);
		}
	}

	/**
	 * Check and increment a rate limit counter stored in expirable keyvalue.
	 * Returns structured result: [allowed, remaining, resetTime, total]
	 */
	private function checkLimit(
		string $keyPrefix,
		int $requests,
		int $windowSeconds,
		string $identifier,
	): array {
		$now = time();
		$windowStart = (int) (floor($now / $windowSeconds) * $windowSeconds);
		$resetTime = $windowStart + $windowSeconds;
		$key = sprintf('%s:%s:%d', $keyPrefix, $identifier, $windowStart);

		$current = (int) $this->store->get($key, 0);
		if ($current >= $requests) {
			return [
				'allowed' => false,
				'remaining' => 0,
				'resetTime' => $resetTime,
				'total' => $requests,
			];
		}

		$new = $current + 1;
		$ttl = max(1, $resetTime - $now);
		// Always set with expire to move the counter forward.
		$this->store->setWithExpire($key, $new, $ttl);

		return [
			'allowed' => true,
			'remaining' => max(0, $requests - $new),
			'resetTime' => $resetTime,
			'total' => $requests,
		];
	}

	/**
	 * Global rate limit configuration (authenticated vs anonymous).
	 * Defaults can be overridden via environment variables.
	 */
	private function getGlobalConfig(bool $authenticated): array
	{
		if ($authenticated) {
			$requests = (int) getenv('MANTLE2_GLOBAL_AUTH_LIMIT_REQUESTS') ?: 120;
			$window = (int) getenv('MANTLE2_GLOBAL_AUTH_LIMIT_WINDOW_SECONDS') ?: 60;
		} else {
			$requests = (int) getenv('MANTLE2_GLOBAL_ANON_LIMIT_REQUESTS') ?: 60;
			$window = (int) getenv('MANTLE2_GLOBAL_ANON_LIMIT_WINDOW_SECONDS') ?: 60;
		}
		return [
			'limit' => $requests,
			'interval' => new DateInterval('PT' . max(1, $window) . 'S'),
		];
	}

	/**
	 * Map route names to per-endpoint rate limit configurations.
	 */
	private function getEndpointConfig(string $routeName): ?array
	{
		$sec = fn(int $s) => new DateInterval('PT' . $s . 'S');

		$map = [
			// Users
			'mantle2.users.create' => ['limit' => 5, 'interval' => $sec(5 * 60)],
			'mantle2.users.login' => ['limit' => 3, 'interval' => $sec(60)],

			// Any user updates
			'mantle2.users.id.patch' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.username.patch' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.current.patch' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.id.set_profile_photo' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.username.set_profile_photo' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.current.set_profile_photo' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.id.set_account_type' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.username.set_account_type' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.current.set_account_type' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.id.patch_field_privacy' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.username.patch_field_privacy' => ['limit' => 10, 'interval' => $sec(60)],
			'mantle2.users.current.patch_field_privacy' => ['limit' => 10, 'interval' => $sec(60)],

			// Events
			'mantle2.events.create' => ['limit' => 3, 'interval' => $sec(2 * 60)],
			'mantle2.events.update' => ['limit' => 5, 'interval' => $sec(2 * 60)],

			// Activities
			'mantle2.activities.random' => ['limit' => 25, 'interval' => $sec(5 * 60)],

			// Prompts
			'mantle2.prompts.random' => ['limit' => 10, 'interval' => $sec(3 * 60)],
			'mantle2.prompts.create' => ['limit' => 7, 'interval' => $sec(2 * 60)],
			'mantle2.prompts.update' => ['limit' => 15, 'interval' => $sec(2 * 60)],
			'mantle2.prompts.responses.create' => ['limit' => 2, 'interval' => $sec(30)],
			'mantle2.prompts.responses.update' => ['limit' => 1, 'interval' => $sec(60)],

			// Articles
			'mantle2.articles.create' => ['limit' => 1, 'interval' => $sec(3 * 60)],
			'mantle2.articles.update' => ['limit' => 2, 'interval' => $sec(3 * 60)],
		];

		return $map[$routeName] ?? null;
	}

	private function build429Array(array $result, string $prefixMessage): JsonResponse
	{
		$limit = (int) $result['total'];
		$windowSeconds = max(1, $result['resetTime'] - time());
		$response = new JsonResponse(
			[
				'error' => 'Rate limit exceeded',
				'message' => sprintf(
					'%s: Too many requests. Limit: %d requests per %d seconds.',
					$prefixMessage,
					$limit,
					$windowSeconds,
				),
				'retryAfter' => $windowSeconds,
			],
			Response::HTTP_TOO_MANY_REQUESTS,
		);
		$this->setRateHeadersArray($response, $result);
		return $response;
	}

	private function setRateHeadersArray(
		Response $response,
		array $result,
		string $prefix = 'X-RateLimit-',
	): void {
		$response->headers->set($prefix . 'Limit', (string) $result['total']);
		$response->headers->set($prefix . 'Remaining', (string) max(0, (int) $result['remaining']));
		$response->headers->set($prefix . 'Reset', (string) (int) $result['resetTime']);
	}

	private function intervalToSeconds(DateInterval $interval): int
	{
		$now = new DateTimeImmutable();
		$end = $now->add($interval);
		return (int) ($end->getTimestamp() - $now->getTimestamp());
	}
}
