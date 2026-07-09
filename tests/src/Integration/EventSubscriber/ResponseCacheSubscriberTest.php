<?php

namespace Drupal\Tests\mantle2\Integration\EventSubscriber;

use Drupal\mantle2\EventSubscriber\ResponseCacheSubscriber;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class ResponseCacheSubscriberTest extends IntegrationTestBase
{
	private function subscriber(): ResponseCacheSubscriber
	{
		return new ResponseCacheSubscriber();
	}

	private function onRequest(Request $request): RequestEvent
	{
		$event = new RequestEvent(
			$this->container->get('http_kernel'),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
		);
		$this->subscriber()->onRequest($event);
		return $event;
	}

	private function onResponse(Request $request, Response $response): ResponseEvent
	{
		$event = new ResponseEvent(
			$this->container->get('http_kernel'),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			$response,
		);
		$this->subscriber()->onResponse($event);
		return $event;
	}

	// anonymous events-list request; cache key resolves req_uid=0 for it
	private function listRequest(): Request
	{
		return Request::create('/v2/events', 'GET');
	}

	// events list key template with default placeholders for an anon requester
	private const LIST_KEY =
		'request_cache:events:list:req:0:p:1:l:25:s:' .
		'd41d8cd98f00b204e9800998ecf8427e:sort:desc:type:all';

	#[Test]
	#[TestDox('Request handler at priority 400 (above rate limit), response handler at -10')]
	#[Group('mantle2/subscribers')]
	public function subscribedEvents(): void
	{
		$events = ResponseCacheSubscriber::getSubscribedEvents();
		$this->assertSame(['onRequest', 400], $events[KernelEvents::REQUEST]);
		$this->assertSame(['onResponse', -10], $events[KernelEvents::RESPONSE]);
	}

	#[Test]
	#[TestDox('Caching config loads from the module root even when the module is symlinked')]
	#[Group('mantle2/subscribers')]
	public function configLoadsUnderSymlink(): void
	{
		// regression: loadConfig() previously used dirname(__DIR__, 3) which points
		// one level above the module root, silently disabling the entire cache
		$event = $this->onResponse($this->listRequest(), new JsonResponse(['events' => []]));
		$this->assertSame('MISS', $event->getResponse()->headers->get('X-Cache'));
	}

	#[Test]
	#[TestDox('A GET 200 JSON response is written to cache and tagged X-Cache: MISS')]
	#[Group('mantle2/subscribers')]
	public function responseWritesToCache(): void
	{
		$this->assertNull(RedisHelper::get(self::LIST_KEY));

		$response = new JsonResponse(['events' => [1, 2, 3]]);
		$event = $this->onResponse($this->listRequest(), $response);

		$this->assertSame('MISS', $event->getResponse()->headers->get('X-Cache'));
		$stored = RedisHelper::get(self::LIST_KEY);
		$this->assertSame(['events' => [1, 2, 3]], $stored);
	}

	#[Test]
	#[TestDox('A cache HIT short-circuits the request with the stored payload')]
	#[Group('mantle2/subscribers')]
	public function requestHitShortCircuits(): void
	{
		RedisHelper::set(self::LIST_KEY, ['events' => ['cached']], 300);

		$event = $this->onRequest($this->listRequest());
		$this->assertTrue($event->hasResponse());
		$response = $event->getResponse();
		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('HIT', $response->headers->get('X-Cache'));
		$this->assertSame(['events' => ['cached']], json_decode($response->getContent(), true));
	}

	#[Test]
	#[TestDox('A cache miss on request does not set a response')]
	#[Group('mantle2/subscribers')]
	public function requestMissNoResponse(): void
	{
		$this->assertNull(RedisHelper::get(self::LIST_KEY));
		$event = $this->onRequest($this->listRequest());
		$this->assertFalse($event->hasResponse());
	}

	#[Test]
	#[TestDox('Round trip: write on response then serve the HIT on the next request')]
	#[Group('mantle2/subscribers')]
	public function writeThenHit(): void
	{
		$this->onResponse($this->listRequest(), new JsonResponse(['events' => ['x']]));
		$event = $this->onRequest($this->listRequest());
		$this->assertTrue($event->hasResponse());
		$this->assertSame('HIT', $event->getResponse()->headers->get('X-Cache'));
		$this->assertSame(
			['events' => ['x']],
			json_decode($event->getResponse()->getContent(), true),
		);
	}

	#[Test]
	#[TestDox('Cache-Control: no-cache skips the cache lookup')]
	#[Group('mantle2/subscribers')]
	public function noCacheHeaderSkipsLookup(): void
	{
		RedisHelper::set(self::LIST_KEY, ['events' => ['cached']], 300);
		$request = Request::create(
			'/v2/events',
			'GET',
			[],
			[],
			[],
			['HTTP_CACHE_CONTROL' => 'no-cache'],
		);
		$event = $this->onRequest($request);
		$this->assertFalse($event->hasResponse());
	}

	#[Test]
	#[TestDox('Non-GET requests are not served from cache')]
	#[Group('mantle2/subscribers')]
	public function nonGetNotCached(): void
	{
		RedisHelper::set(self::LIST_KEY, ['events' => ['cached']], 300);
		$event = $this->onRequest(Request::create('/v2/events', 'POST'));
		$this->assertFalse($event->hasResponse());
	}

	#[Test]
	#[TestDox('Uncacheable routes are not written to cache')]
	#[Group('mantle2/subscribers')]
	public function uncacheableRouteNotWritten(): void
	{
		$request = Request::create('/v2/info', 'GET');
		$event = $this->onResponse($request, new JsonResponse(['name' => 'mantle2']));
		$this->assertFalse($event->getResponse()->headers->has('X-Cache'));
	}

	#[Test]
	#[TestDox('Excluded paths (e.g. /random) bypass the cache write')]
	#[Group('mantle2/subscribers')]
	public function excludedPathBypassed(): void
	{
		$request = Request::create('/v2/events/random', 'GET');
		$event = $this->onResponse($request, new JsonResponse(['id' => 1]));
		$this->assertFalse($event->getResponse()->headers->has('X-Cache'));
	}

	#[Test]
	#[TestDox('Server-error responses are never cached')]
	#[Group('mantle2/subscribers')]
	public function serverErrorNotCached(): void
	{
		$event = $this->onResponse(
			$this->listRequest(),
			new JsonResponse(['error' => 'boom'], 500),
		);
		$this->assertFalse($event->getResponse()->headers->has('X-Cache'));
		$this->assertNull(RedisHelper::get(self::LIST_KEY));
	}

	#[Test]
	#[TestDox('Non-200 GET responses are not written to cache')]
	#[Group('mantle2/subscribers')]
	public function non200NotCached(): void
	{
		$event = $this->onResponse(
			$this->listRequest(),
			new JsonResponse(['error' => 'nope'], 404),
		);
		$this->assertFalse($event->getResponse()->headers->has('X-Cache'));
		$this->assertNull(RedisHelper::get(self::LIST_KEY));
	}

	// carries the shared admin key configured by IntegrationTestBase::setAdminKey
	private function adminRequest(string $method = 'GET', string $uri = '/v2/events'): Request
	{
		return Request::create($uri, $method, [], [], [], ['HTTP_X_ADMIN_KEY' => 'test_admin_key']);
	}

	#[Test]
	#[TestDox('Regression: a /v2/users/quests response never poisons /v2/users/current')]
	#[Group('mantle2/subscribers')]
	public function questsResponseDoesNotPoisonCurrentUser(): void
	{
		$this->onResponse(
			Request::create('/v2/users/quests', 'GET', ['id' => 'runner']),
			new JsonResponse(['id' => 'runner', 'title' => 'Runner']),
		);

		$event = $this->onRequest(Request::create('/v2/users/current', 'GET'));
		$this->assertFalse(
			$event->hasResponse(),
			'quests response must not be served for a current-user request',
		);
	}

	#[Test]
	#[TestDox('Regression: a /v2/users/current response is never served for /v2/users/quests')]
	#[Group('mantle2/subscribers')]
	public function currentUserResponseIsNotServedForQuests(): void
	{
		$this->onResponse(
			Request::create('/v2/users/current', 'GET'),
			new JsonResponse(['id' => 99, 'username' => 'me']),
		);

		$event = $this->onRequest(Request::create('/v2/users/quests', 'GET', ['id' => 'runner']));
		$this->assertFalse(
			$event->hasResponse(),
			'quests is uncacheable and must miss even when a current-user entry exists',
		);
	}

	#[Test]
	#[TestDox('The current alias caches under a requester-scoped profile key')]
	#[Group('mantle2/subscribers')]
	public function currentUserCachesUnderRequesterScopedProfileKey(): void
	{
		// anonymous requester resolves req_uid=0, and "current" binds {uid} to it too
		$key = 'request_cache:user:profile:0:req:0';
		$this->assertNull(RedisHelper::get($key));

		$event = $this->onResponse(
			Request::create('/v2/users/current', 'GET'),
			new JsonResponse(['id' => 7]),
		);
		$this->assertSame('MISS', $event->getResponse()->headers->get('X-Cache'));
		$this->assertSame(['id' => 7], RedisHelper::get($key));
	}

	#[Test]
	#[TestDox('The badges collection route falls through to its dedicated list key')]
	#[Group('mantle2/subscribers')]
	public function badgesListRoutesToItsDedicatedKey(): void
	{
		$event = $this->onResponse(
			Request::create('/v2/users/badges', 'GET'),
			new JsonResponse(['badges' => []]),
		);
		$this->assertSame('MISS', $event->getResponse()->headers->get('X-Cache'));
		$this->assertSame(['badges' => []], RedisHelper::get('request_cache:badges:list'));
		// and it must NOT have leaked into a profile-style key
		$this->assertNull(RedisHelper::get('request_cache:user:profile:badges:req:0'));
	}

	#[Test]
	#[TestDox('Elevated (admin-key) requests are never served from the shared cache')]
	#[Group('mantle2/subscribers')]
	public function elevatedRequestDoesNotReadCache(): void
	{
		RedisHelper::set(self::LIST_KEY, ['events' => ['cached']], 300);
		$event = $this->onRequest($this->adminRequest());
		$this->assertFalse(
			$event->hasResponse(),
			'admin requests must always see fresh data, never a cached bucket',
		);
	}

	#[Test]
	#[TestDox('Elevated (admin-key) responses are never written to the shared cache')]
	#[Group('mantle2/subscribers')]
	public function elevatedResponseIsNotStored(): void
	{
		$event = $this->onResponse(
			$this->adminRequest(),
			new JsonResponse(['events' => ['privileged']]),
		);
		$this->assertFalse($event->getResponse()->headers->has('X-Cache'));
		$this->assertNull(RedisHelper::get(self::LIST_KEY));
	}
}
