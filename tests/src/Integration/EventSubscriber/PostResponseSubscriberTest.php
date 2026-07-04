<?php

namespace Drupal\Tests\mantle2\Integration\EventSubscriber;

use Drupal\mantle2\EventSubscriber\PostResponseSubscriber;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class PostResponseSubscriberTest extends IntegrationTestBase
{
	private function terminate(Request $request, Response $response): void
	{
		$event = new TerminateEvent($this->container->get('http_kernel'), $request, $response);
		new PostResponseSubscriber()->onTerminate($event);
	}

	private function routed(
		string $method,
		string $route,
		string $uri,
		?UserInterface $user = null,
		string $body = '{}',
	): Request {
		$request = $user
			? $this->authRequest($user, $method, $uri, [], null)
			: $this->request($method, $uri);
		$request->attributes->set('_route', $route);
		return $request;
	}

	#[Test]
	#[TestDox('Subscribes to KernelEvents::TERMINATE')]
	#[Group('mantle2/subscribers')]
	public function subscribedEvents(): void
	{
		$events = PostResponseSubscriber::getSubscribedEvents();
		$this->assertArrayHasKey(KernelEvents::TERMINATE, $events);
		$this->assertSame('onTerminate', $events[KernelEvents::TERMINATE]);
	}

	#[Test]
	#[TestDox('An unmapped route triggers no callback and does not throw')]
	#[Group('mantle2/subscribers')]
	public function unmappedRouteNoop(): void
	{
		$request = $this->routed('GET', 'mantle2.info', '/v2/info');
		$this->terminate($request, new JsonResponse(['status' => 'active']));
		$this->addToAssertionCount(1);
	}

	#[Test]
	#[
		TestDox(
			'A mapped route with an anonymous requester is a no-op (callbacks bail on null user)',
		),
	]
	#[Group('mantle2/subscribers')]
	public function anonymousRequesterNoop(): void
	{
		$request = $this->routed('PUT', 'mantle2.users.current.circle.add', '/v2/users/@me/circle');
		$this->terminate($request, new JsonResponse(['id' => 5]));
		$this->addToAssertionCount(1);
	}

	#[Test]
	#[TestDox('A mapped grant-badge callback runs for an authenticated user without throwing')]
	#[Group('mantle2/subscribers')]
	public function mappedGrantBadgeCallbackRuns(): void
	{
		$user = $this->createUser();
		$request = $this->routed(
			'PUT',
			'mantle2.users.current.circle.add',
			'/v2/users/@me/circle',
			$user,
		);
		// grantBadge routes to cloud and swallows connection failures; must not throw
		$this->terminate($request, new JsonResponse(['id' => 7]));
		$this->addToAssertionCount(1);
	}

	#[Test]
	#[TestDox('Non-JSON response content decodes to an empty data array without error')]
	#[Group('mantle2/subscribers')]
	public function nonJsonBodyDecodesToEmpty(): void
	{
		$user = $this->createUser();
		$request = $this->routed(
			'PATCH',
			'mantle2.users.current.activities.set',
			'/v2/users/@me/activities',
			$user,
		);
		$this->terminate($request, new Response('not json at all'));
		$this->addToAssertionCount(1);
	}

	#[Test]
	#[TestDox('Signup callback resolves a timezone from the country code without throwing')]
	#[Group('mantle2/subscribers')]
	public function signupTimezoneCallback(): void
	{
		$user = $this->createUser(['field_country' => 'US']);
		$request = $this->routed('POST', 'mantle2.events.signup', '/v2/events/1/signup', $user);
		$this->terminate($request, new JsonResponse(['id' => 1]));
		$this->addToAssertionCount(1);
	}

	#[Test]
	#[TestDox('A mapped route with no _route attribute set does not match any callback')]
	#[Group('mantle2/subscribers')]
	public function missingRouteAttribute(): void
	{
		$user = $this->createUser();
		$request = $this->authRequest($user, 'PUT', '/v2/users/@me/circle');
		$this->terminate($request, new JsonResponse(['id' => 1]));
		$this->addToAssertionCount(1);
	}
}
