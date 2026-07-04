<?php

namespace Drupal\Tests\mantle2\Integration\EventSubscriber;

use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\EventSubscriber\RateLimitSubscriber;
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

class RateLimitSubscriberTest extends IntegrationTestBase
{
	private function subscriber(): RateLimitSubscriber
	{
		return new RateLimitSubscriber($this->container->get('keyvalue.expirable'));
	}

	private function fire(Request $request): RequestEvent
	{
		$event = new RequestEvent(
			$this->container->get('http_kernel'),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
		);
		$this->subscriber()->onKernelRequest($event);
		return $event;
	}

	private function anonReport(string $ip): Request
	{
		$request = Request::create(
			'/v2/reports',
			'POST',
			[],
			[],
			[],
			['HTTP_CF_CONNECTING_IP' => $ip],
		);
		$request->attributes->set('_route', 'mantle2.reports.create');
		return $request;
	}

	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
		]);
	}

	#[Test]
	#[TestDox('Subscribes to KernelEvents::REQUEST at priority 300')]
	#[Group('mantle2/subscribers')]
	public function subscribedEvents(): void
	{
		$events = RateLimitSubscriber::getSubscribedEvents();
		$this->assertSame(['onKernelRequest', 300], $events[KernelEvents::REQUEST]);
	}

	#[Test]
	#[TestDox('Non /v2 paths are never rate limited')]
	#[Group('mantle2/subscribers')]
	public function nonV2Ignored(): void
	{
		$request = Request::create('/admin/x', 'GET');
		$event = $this->fire($request);
		$this->assertFalse($event->hasResponse());
		$this->assertNull($request->attributes->get('_mantle2_rl_headers'));
	}

	#[Test]
	#[TestDox('Admins bypass rate limiting entirely')]
	#[Group('mantle2/subscribers')]
	public function adminBypass(): void
	{
		$admin = $this->admin();
		$request = $this->authRequest($admin, 'GET', '/v2/events');
		$request->attributes->set('_route', 'mantle2.events');
		$event = $this->fire($request);
		$this->assertFalse($event->hasResponse());
		$this->assertNull($request->attributes->get('_mantle2_rl_headers'));
	}

	#[Test]
	#[TestDox('An allowed request stashes global rate-limit headers for the response subscriber')]
	#[Group('mantle2/subscribers')]
	public function allowedStashesGlobalHeaders(): void
	{
		$request = Request::create(
			'/v2/events',
			'GET',
			[],
			[],
			[],
			['HTTP_CF_CONNECTING_IP' => '10.0.0.1'],
		);
		$request->attributes->set('_route', 'mantle2.events');
		$event = $this->fire($request);

		$this->assertFalse($event->hasResponse());
		$stash = $request->attributes->get('_mantle2_rl_headers');
		$this->assertIsArray($stash);
		$this->assertArrayHasKey('global', $stash);
		$this->assertArrayNotHasKey('endpoint', $stash);
		$this->assertTrue($stash['global'][0]['allowed']);
	}

	#[Test]
	#[TestDox('A configured endpoint stashes both endpoint and global headers')]
	#[Group('mantle2/subscribers')]
	public function endpointStashesBoth(): void
	{
		$event = $this->fire($this->anonReport('10.0.0.2'));
		$this->assertFalse($event->hasResponse());
		$stash = $event->getRequest()->attributes->get('_mantle2_rl_headers');
		$this->assertArrayHasKey('endpoint', $stash);
		$this->assertArrayHasKey('global', $stash);
	}

	#[Test]
	#[TestDox('Exceeding the per-endpoint limit returns a 429 with retryAfter and rate headers')]
	#[Group('mantle2/subscribers')]
	public function endpointLimitEnforced(): void
	{
		$ip = '203.0.113.9';
		// anon reports.create limit is 3 per 10 minutes
		for ($i = 0; $i < 3; $i++) {
			$event = $this->fire($this->anonReport($ip));
			$this->assertFalse($event->hasResponse(), "request #$i should be allowed");
		}

		$blocked = $this->fire($this->anonReport($ip));
		$this->assertTrue($blocked->hasResponse());
		$response = $blocked->getResponse();
		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertSame(429, $response->getStatusCode());

		$body = json_decode($response->getContent(), true);
		$this->assertSame('Rate limit exceeded', $body['error']);
		$this->assertIsInt($body['retryAfter']);
		$this->assertGreaterThan(0, $body['retryAfter']);

		$this->assertSame('3', $response->headers->get('X-RateLimit-Limit'));
		$this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
		// anon write global budget is 120 (reads get 600)
		$this->assertSame('120', $response->headers->get('X-Global-RateLimit-Limit'));
	}

	#[Test]
	#[TestDox('Rate-limit counters are isolated per client IP')]
	#[Group('mantle2/subscribers')]
	public function perIpIsolation(): void
	{
		$ipA = '198.51.100.1';
		$ipB = '198.51.100.2';
		for ($i = 0; $i < 3; $i++) {
			$this->fire($this->anonReport($ipA));
		}
		$this->assertTrue(
			$this->fire($this->anonReport($ipA))->hasResponse(),
			'ipA should be blocked',
		);
		$this->assertFalse(
			$this->fire($this->anonReport($ipB))->hasResponse(),
			'ipB should be fresh',
		);
	}

	#[Test]
	#[TestDox('Authenticated non-admins get the higher auth endpoint budget')]
	#[Group('mantle2/subscribers')]
	public function authEndpointBudget(): void
	{
		$user = $this->createUser();
		$request = $this->authRequest($user, 'POST', '/v2/reports', [
			'HTTP_CF_CONNECTING_IP' => '198.51.100.7',
		]);
		$request->attributes->set('_route', 'mantle2.reports.create');
		$event = $this->fire($request);

		$stash = $request->attributes->get('_mantle2_rl_headers');
		// auth reports limit is 10 vs anon 3
		$this->assertSame(10, $stash['endpoint'][1]);
		$this->assertFalse($event->hasResponse());
	}
}
