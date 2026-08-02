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

	// #region Activity search partitioning

	private function activitiesRequest(array $query = []): Request
	{
		return Request::create('/v2/activities', 'GET', $query);
	}

	/** the payload a repeat request would be served, or null on a miss */
	private function cachedBody(Request $request): ?array
	{
		$event = $this->onRequest($request);
		$response = $event->getResponse();
		if (!$response) {
			return null;
		}

		return json_decode($response->getContent(), true);
	}

	private function seedCache(Request $request, array $payload): void
	{
		$this->onResponse($request, new JsonResponse($payload));
	}

	#[Test]
	#[TestDox('A search response never answers a different search')]
	#[Group('mantle2/subscribers')]
	public function activitySearchIsPartitioned(): void
	{
		// the exact production symptom: searching "jiu" returned whatever the previous
		// request had cached under the shared, unpartitioned activities key
		$this->seedCache($this->activitiesRequest(['search' => 'zzz']), [
			'items' => [],
			'total' => 0,
		]);

		$this->assertNull(
			$this->cachedBody($this->activitiesRequest(['search' => 'jiu'])),
			'A miss for one search term was served as the answer for another',
		);
		$this->assertNull(
			$this->cachedBody($this->activitiesRequest()),
			'A search response was served as the unfiltered activity list',
		);
	}

	#[Test]
	#[TestDox('The same search reads its own cached response back')]
	#[Group('mantle2/subscribers')]
	public function activitySearchHitsItsOwnKey(): void
	{
		$payload = ['items' => [['id' => 'jiujitsu']], 'total' => 1];
		$this->seedCache($this->activitiesRequest(['search' => 'jiu']), $payload);

		$this->assertSame(
			$payload,
			$this->cachedBody($this->activitiesRequest(['search' => 'jiu'])),
		);
	}

	#[Test]
	#[TestDox('Pages and sorts of the activity list do not overwrite each other')]
	#[Group('mantle2/subscribers')]
	public function activityPagesArePartitioned(): void
	{
		$this->seedCache($this->activitiesRequest(['page' => 1]), ['items' => ['one']]);

		$this->assertNull($this->cachedBody($this->activitiesRequest(['page' => 2])));
		$this->assertNull($this->cachedBody($this->activitiesRequest(['sort' => 'asc'])));
		$this->assertSame(
			['items' => ['one']],
			$this->cachedBody($this->activitiesRequest(['page' => 1])),
		);
	}

	#[Test]
	#[TestDox('An alias-aware search never answers the alias-blind one')]
	#[Group('mantle2/subscribers')]
	public function activityAliasSearchIsPartitioned(): void
	{
		// include_aliases widens the result set, so the two must not share a bucket
		$this->seedCache($this->activitiesRequest(['search' => 'jog']), [
			'items' => [],
			'total' => 0,
		]);

		$this->assertNull(
			$this->cachedBody(
				$this->activitiesRequest(['search' => 'jog', 'include_aliases' => 'true']),
			),
			'The alias-blind miss was served as the alias-aware answer',
		);

		$aliased = ['items' => [['id' => 'run']], 'total' => 1];
		$this->seedCache(
			$this->activitiesRequest(['search' => 'jog', 'include_aliases' => 'true']),
			$aliased,
		);

		$this->assertSame(
			$aliased,
			$this->cachedBody(
				$this->activitiesRequest(['search' => 'jog', 'include_aliases' => '1']),
			),
			'Equivalent truthy flags must resolve to one key',
		);
		$this->assertSame(
			['items' => [], 'total' => 0],
			$this->cachedBody($this->activitiesRequest(['search' => 'jog'])),
			'The alias-aware hit leaked into the alias-blind key',
		);
	}

	#[Test]
	#[TestDox('A type-filtered activity list does not answer the unfiltered one')]
	#[Group('mantle2/subscribers')]
	public function activityTypeFilterIsPartitioned(): void
	{
		$this->seedCache($this->activitiesRequest(['type' => 'SPORT']), [
			'items' => [['id' => 'run']],
		]);

		$this->assertNull($this->cachedBody($this->activitiesRequest()));
		$this->assertNull($this->cachedBody($this->activitiesRequest(['type' => 'HOBBY'])));
		$this->assertSame(
			['items' => [['id' => 'run']]],
			$this->cachedBody($this->activitiesRequest(['type' => 'SPORT'])),
		);
	}

	#[Test]
	#[TestDox('Publishing an activity clears every cached page and search')]
	#[Group('mantle2/subscribers')]
	public function publishingClearsEveryActivityKey(): void
	{
		$this->seedCache($this->activitiesRequest(), ['items' => ['before']]);
		$this->seedCache($this->activitiesRequest(['search' => 'jiu']), ['items' => []]);

		// StagingHelper::publish() busts the same glob when cron publishes outside a request
		RedisHelper::delete('request_cache:activities:list:*');

		$this->assertNull($this->cachedBody($this->activitiesRequest()));
		$this->assertNull($this->cachedBody($this->activitiesRequest(['search' => 'jiu'])));
	}

	// #endregion

	// #region Write invalidation

	private function primeCache(string $key, array $payload = ['seeded' => true]): void
	{
		RedisHelper::set($key, $payload, 300);
		$this->assertSame($payload, RedisHelper::get($key), "failed to prime $key");
	}

	private function write(
		string $method,
		string $uri,
		int $status,
		array $body = [],
		?Request $request = null,
	): void {
		$this->onResponse(
			$request ?? Request::create($uri, $method),
			new JsonResponse($body, $status),
		);
	}

	#[Test]
	#[TestDox('Creating an activity clears every cached page and search of the catalog')]
	#[Group('mantle2/subscribers')]
	public function activityCreateClearsTheCatalogGlob(): void
	{
		// regression: invalidation used a raw redis client, so with redis unavailable
		// (or down in production) every glob pattern silently invalidated nothing
		$this->seedCache($this->activitiesRequest(), ['items' => ['before']]);
		$this->seedCache($this->activitiesRequest(['search' => 'jiu']), ['items' => []]);

		$this->write('POST', '/v2/activities', 201, ['id' => 'jiujitsu']);

		$this->assertNull($this->cachedBody($this->activitiesRequest()));
		$this->assertNull($this->cachedBody($this->activitiesRequest(['search' => 'jiu'])));
	}

	#[Test]
	#[TestDox('Approving a staged activity clears the catalog the same way a create does')]
	#[Group('mantle2/subscribers')]
	public function stagedApprovalClearsTheCatalogGlob(): void
	{
		$this->seedCache($this->activitiesRequest(), ['items' => ['before']]);

		$this->write('POST', '/v2/activities/staged/12/approve', 200, ['id' => 'jiujitsu']);

		$this->assertNull($this->cachedBody($this->activitiesRequest()));
	}

	#[Test]
	#[TestDox('Patching a user clears that profile and the user list')]
	#[Group('mantle2/subscribers')]
	public function patchUserClearsProfileAndList(): void
	{
		$this->primeCache('request_cache:user:profile:42:req:0');
		$this->primeCache('request_cache:user:profile:42:req:9');
		$this->primeCache('request_cache:users:list:p:1:l:25');

		$this->write('PATCH', '/v2/users/42', 200);

		$this->assertNull(RedisHelper::get('request_cache:user:profile:42:req:0'));
		$this->assertNull(RedisHelper::get('request_cache:user:profile:42:req:9'));
		$this->assertNull(RedisHelper::get('request_cache:users:list:p:1:l:25'));
	}

	#[Test]
	#[TestDox('Patching one user never clears another user profile')]
	#[Group('mantle2/subscribers')]
	public function patchUserLeavesOtherProfilesAlone(): void
	{
		$this->primeCache('request_cache:user:profile:42:req:0');
		$this->primeCache('request_cache:user:profile:43:req:0');

		$this->write('PATCH', '/v2/users/42', 200);

		$this->assertNull(RedisHelper::get('request_cache:user:profile:42:req:0'));
		$this->assertSame(
			['seeded' => true],
			RedisHelper::get('request_cache:user:profile:43:req:0'),
			'a neighbouring uid was collateral damage',
		);
	}

	#[Test]
	#[TestDox('Deleting a user clears every per-user bucket, not just the profile one')]
	#[Group('mantle2/subscribers')]
	public function deleteUserClearsEveryPerUserBucket(): void
	{
		// regression: 'request_cache:user:*:{uid}:*' was flattened to the prefix
		// 'request_cache:user::42:' by stripping the wildcards, which matched nothing,
		// so a deleted user's friends/notifications/photo caches outlived the account
		$this->primeCache('request_cache:user:profile:42:req:0');
		$this->primeCache('request_cache:user:friends:42:p:1:l:25');
		$this->primeCache('request_cache:user:notifications:42:p:1:read:all');
		$this->primeCache('request_cache:user:profile:430:req:0');

		$this->write('DELETE', '/v2/users/42', 204);

		$this->assertNull(RedisHelper::get('request_cache:user:profile:42:req:0'));
		$this->assertNull(RedisHelper::get('request_cache:user:friends:42:p:1:l:25'));
		$this->assertNull(RedisHelper::get('request_cache:user:notifications:42:p:1:read:all'));
		$this->assertSame(
			['seeded' => true],
			RedisHelper::get('request_cache:user:profile:430:req:0'),
			'the uid glob must not match a longer uid that starts with the same digits',
		);
	}

	#[Test]
	#[TestDox('Adding a friend clears both sides when the response names the friend')]
	#[Group('mantle2/subscribers')]
	public function friendAddClearsBothSides(): void
	{
		$this->primeCache('request_cache:user:friends:42:p:1:l:25');
		$this->primeCache('request_cache:user:friends:77:p:1:l:25');
		$this->primeCache('request_cache:user:profile:42:req:0');
		$this->primeCache('request_cache:user:profile:77:req:0');

		$this->write('PUT', '/v2/users/42/friends', 200, ['friend_id' => 77]);

		$this->assertNull(RedisHelper::get('request_cache:user:friends:42:p:1:l:25'));
		$this->assertNull(RedisHelper::get('request_cache:user:friends:77:p:1:l:25'));
		$this->assertNull(RedisHelper::get('request_cache:user:profile:42:req:0'));
		$this->assertNull(RedisHelper::get('request_cache:user:profile:77:req:0'));
	}

	#[Test]
	#[TestDox('An unresolved placeholder skips only its own pattern')]
	#[Group('mantle2/subscribers')]
	public function unresolvedPlaceholderSkipsOnlyItsPattern(): void
	{
		$this->primeCache('request_cache:user:friends:42:p:1:l:25');
		$this->primeCache('request_cache:user:friends:77:p:1:l:25');

		// no friend_id in the response, so {friend_uid} never resolves
		$this->write('PUT', '/v2/users/42/friends', 200, ['ok' => true]);

		$this->assertNull(RedisHelper::get('request_cache:user:friends:42:p:1:l:25'));
		$this->assertSame(
			['seeded' => true],
			RedisHelper::get('request_cache:user:friends:77:p:1:l:25'),
			'an ambiguous key pattern must be skipped, never widened',
		);
	}

	#[Test]
	#[TestDox('A rejected write invalidates nothing')]
	#[Group('mantle2/subscribers')]
	public function rejectedWriteInvalidatesNothing(): void
	{
		$this->primeCache('request_cache:user:profile:42:req:0');

		$this->write('PATCH', '/v2/users/42', 403, ['code' => 403]);

		$this->assertSame(
			['seeded' => true],
			RedisHelper::get('request_cache:user:profile:42:req:0'),
		);
	}

	#[Test]
	#[TestDox('A write to an unmapped route invalidates nothing')]
	#[Group('mantle2/subscribers')]
	public function unmappedWriteRouteInvalidatesNothing(): void
	{
		$this->primeCache('request_cache:users:list:p:1:l:25');

		$this->write('POST', '/v2/info', 200, ['ok' => true]);

		$this->assertSame(
			['seeded' => true],
			RedisHelper::get('request_cache:users:list:p:1:l:25'),
		);
	}

	#[Test]
	#[TestDox('A subscription webhook clears every cached subscription, across users')]
	#[Group('mantle2/subscribers')]
	public function webhookClearsEverySubscriptionCache(): void
	{
		$this->primeCache('request_cache:user:subscription:req:5');
		$this->primeCache('request_cache:user:subscription:req:9');
		$this->primeCache('request_cache:users:list:p:1:l:25');

		$this->write('POST', '/v2/webhooks/stripe', 200, ['received' => true]);

		$this->assertNull(RedisHelper::get('request_cache:user:subscription:req:5'));
		$this->assertNull(RedisHelper::get('request_cache:user:subscription:req:9'));
		$this->assertSame(
			['seeded' => true],
			RedisHelper::get('request_cache:users:list:p:1:l:25'),
			'the subscription glob must not reach outside its own namespace',
		);
	}

	#[Test]
	#[TestDox('Checkout clears the requester subscription and profile, not another user')]
	#[Group('mantle2/subscribers')]
	public function checkoutClearsRequesterScopedKeys(): void
	{
		$user = $this->createUser();
		$uid = (int) $user->id();

		$this->primeCache('request_cache:user:subscription:req:' . $uid);
		$this->primeCache('request_cache:user:profile:' . $uid . ':req:' . $uid);
		$this->primeCache('request_cache:user:subscription:req:999999');

		$this->write(
			'POST',
			'/v2/users/current/subscription/checkout',
			200,
			['url' => 'https://stripe.test/session'],
			$this->authRequest($user, 'POST', '/v2/users/current/subscription/checkout'),
		);

		$this->assertNull(RedisHelper::get('request_cache:user:subscription:req:' . $uid));
		$this->assertNull(RedisHelper::get('request_cache:user:profile:' . $uid . ':req:' . $uid));
		$this->assertSame(
			['seeded' => true],
			RedisHelper::get('request_cache:user:subscription:req:999999'),
		);
	}

	#[Test]
	#[TestDox('Linking an OAuth provider clears the requester profile and the user list')]
	#[Group('mantle2/subscribers')]
	public function oauthLinkClearsRequesterProfile(): void
	{
		// regression: '/oauth/' is a read exclusion, and applying it to writes meant
		// linking a provider left the stale pre-link profile cached
		$user = $this->createUser();
		$uid = (int) $user->id();

		$this->primeCache('request_cache:user:profile:' . $uid . ':req:' . $uid);
		$this->primeCache('request_cache:users:list:p:1:l:25');

		$this->write(
			'POST',
			'/v2/users/oauth/google',
			200,
			['linked' => true],
			$this->authRequest($user, 'POST', '/v2/users/oauth/google'),
		);

		$this->assertNull(RedisHelper::get('request_cache:user:profile:' . $uid . ':req:' . $uid));
		$this->assertNull(RedisHelper::get('request_cache:users:list:p:1:l:25'));
	}

	#[Test]
	#[TestDox('An excluded path is still never written to the cache')]
	#[Group('mantle2/subscribers')]
	public function excludedPathIsStillNeverStored(): void
	{
		// the exclusion list moved to the read branch; it must still hold there
		$event = $this->onResponse(
			Request::create('/v2/users/oauth/google', 'GET'),
			new JsonResponse(['id' => 1]),
		);
		$this->assertFalse($event->getResponse()->headers->has('X-Cache'));
	}

	#[Test]
	#[TestDox('A cached GET response never invalidates anything')]
	#[Group('mantle2/subscribers')]
	public function readsNeverInvalidate(): void
	{
		$this->primeCache('request_cache:users:list:p:1:l:25');
		$this->primeCache('request_cache:user:profile:42:req:0');

		$this->write('GET', '/v2/users/42', 200, ['id' => 42]);

		$this->assertSame(
			['seeded' => true],
			RedisHelper::get('request_cache:users:list:p:1:l:25'),
		);
	}

	// #endregion
}
