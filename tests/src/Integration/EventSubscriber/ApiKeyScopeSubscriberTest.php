<?php

namespace Drupal\Tests\mantle2\Integration\EventSubscriber;

use Drupal\mantle2\Custom\ApiKeyScope;
use Drupal\mantle2\EventSubscriber\ApiKeyScopeSubscriber;
use Drupal\mantle2\Service\ApiKeysHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class ApiKeyScopeSubscriberTest extends IntegrationTestBase
{
	private function issueKey(UserInterface $user, array $scopes): string
	{
		$result = ApiKeysHelper::issue($user, 'Test Key', null, $scopes, null);
		$this->assertIsArray(
			$result,
			'API key issuance failed: ' . (is_string($result) ? $result : ''),
		);
		return $result['token'];
	}

	private function keyRequest(string $token, string $method, string $route, string $uri): Request
	{
		$request = Request::create($uri, $method);
		$request->headers->set('Authorization', 'Bearer ' . $token);
		$request->attributes->set('_route', $route);
		return $request;
	}

	private function fire(Request $request): RequestEvent
	{
		$event = new RequestEvent(
			$this->container->get('http_kernel'),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
		);
		new ApiKeyScopeSubscriber()->onKernelRequest($event);
		return $event;
	}

	#[Test]
	#[
		TestDox(
			'Subscribes to KernelEvents::REQUEST at priority 200 (below cache 400 and rate limit 300)',
		),
	]
	#[Group('mantle2/subscribers')]
	public function subscribedEvents(): void
	{
		$events = ApiKeyScopeSubscriber::getSubscribedEvents();
		$this->assertSame(['onKernelRequest', 200], $events[KernelEvents::REQUEST]);
	}

	#[Test]
	#[TestDox('Requests without an API key (session/anonymous) are a no-op')]
	#[Group('mantle2/subscribers')]
	public function noApiKeyNoop(): void
	{
		$user = $this->createUser();
		$request = $this->authRequest($user, 'GET', '/v2/users/@me');
		$request->attributes->set('_route', 'mantle2.users.current.get');
		$event = $this->fire($request);
		$this->assertFalse($event->hasResponse());
		$this->assertNull($request->attributes->get('_mantle2_anon_demoted'));
	}

	#[Test]
	#[TestDox('Non /v2 paths are ignored')]
	#[Group('mantle2/subscribers')]
	public function nonV2Ignored(): void
	{
		$user = $this->createUser();
		$token = $this->issueKey($user, [ApiKeyScope::USER_READ_PROFILE]);
		$event = $this->fire($this->keyRequest($token, 'GET', 'x', '/admin/x'));
		$this->assertFalse($event->hasResponse());
	}

	#[Test]
	#[TestDox('An API key holding the required scope passes the gate')]
	#[Group('mantle2/subscribers')]
	public function inScopePermitted(): void
	{
		$user = $this->createUser();
		$token = $this->issueKey($user, [ApiKeyScope::USER_READ_PROFILE]);
		$event = $this->fire(
			$this->keyRequest($token, 'GET', 'mantle2.users.current.get', '/v2/users/@me'),
		);
		$this->assertFalse($event->hasResponse());
		$this->assertNull($event->getRequest()->attributes->get('_mantle2_anon_demoted'));
	}

	#[Test]
	#[TestDox('Out-of-scope write is rejected with a 403')]
	#[Group('mantle2/subscribers')]
	public function outOfScopeWriteForbidden(): void
	{
		$user = $this->createUser();
		$token = $this->issueKey($user, [ApiKeyScope::USER_READ_PROFILE]);
		$event = $this->fire(
			$this->keyRequest($token, 'PATCH', 'mantle2.users.current.patch', '/v2/users/@me'),
		);

		$this->assertTrue($event->hasResponse());
		$response = $event->getResponse();
		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertSame(403, $response->getStatusCode());
		$body = json_decode($response->getContent(), true);
		$this->assertStringContainsString('missing required scope', $body['message']);
	}

	#[Test]
	#[TestDox('Out-of-scope GET is demoted to anonymous rather than rejected')]
	#[Group('mantle2/subscribers')]
	public function outOfScopeReadDemoted(): void
	{
		$user = $this->createUser();
		$token = $this->issueKey($user, [ApiKeyScope::EVENTS_READ]);
		$event = $this->fire(
			$this->keyRequest($token, 'GET', 'mantle2.users.current.get', '/v2/users/@me'),
		);

		$this->assertFalse($event->hasResponse());
		$this->assertTrue($event->getRequest()->attributes->get('_mantle2_anon_demoted'));
	}

	#[Test]
	#[TestDox('Session-only routes always 403 on an API key regardless of scope or method')]
	#[Group('mantle2/subscribers')]
	public function sessionOnlyForbidden(): void
	{
		$user = $this->createUser();
		$token = $this->issueKey($user, [ApiKeyScope::USER_READ_PROFILE]);
		$event = $this->fire(
			$this->keyRequest($token, 'GET', 'mantle2.api_keys.list', '/v2/user/api-keys'),
		);

		$this->assertTrue($event->hasResponse());
		$this->assertSame(403, $event->getResponse()->getStatusCode());
		$body = json_decode($event->getResponse()->getContent(), true);
		$this->assertSame('API keys are not accepted for this endpoint', $body['message']);
	}

	#[Test]
	#[TestDox('Unmapped route fails closed: write 403, GET demoted')]
	#[Group('mantle2/subscribers')]
	public function unmappedFailsClosed(): void
	{
		$user = $this->createUser();
		$token = $this->issueKey($user, [ApiKeyScope::USER_READ_PROFILE]);

		$write = $this->fire(
			$this->keyRequest($token, 'POST', 'mantle2.some.unknown.route', '/v2/unknown'),
		);
		$this->assertTrue($write->hasResponse());
		$this->assertSame(403, $write->getResponse()->getStatusCode());

		$read = $this->fire(
			$this->keyRequest($token, 'GET', 'mantle2.some.unknown.route', '/v2/unknown'),
		);
		$this->assertFalse($read->hasResponse());
		$this->assertTrue($read->getRequest()->attributes->get('_mantle2_anon_demoted'));
	}

	#[Test]
	#[TestDox('Explicitly public routes (empty scope) pass any API key through')]
	#[Group('mantle2/subscribers')]
	public function publicRoutePermitted(): void
	{
		$user = $this->createUser();
		$token = $this->issueKey($user, [ApiKeyScope::USER_READ_PROFILE]);
		$event = $this->fire($this->keyRequest($token, 'GET', 'mantle2.motd.get', '/v2/motd'));
		$this->assertFalse($event->hasResponse());
		$this->assertNull($event->getRequest()->attributes->get('_mantle2_anon_demoted'));
	}

	#[Test]
	#[TestDox('A missing _route attribute is a no-op')]
	#[Group('mantle2/subscribers')]
	public function missingRouteNoop(): void
	{
		$user = $this->createUser();
		$token = $this->issueKey($user, [ApiKeyScope::USER_READ_PROFILE]);
		$request = Request::create('/v2/users/@me', 'GET');
		$request->headers->set('Authorization', 'Bearer ' . $token);
		$event = $this->fire($request);
		$this->assertFalse($event->hasResponse());
	}
}
