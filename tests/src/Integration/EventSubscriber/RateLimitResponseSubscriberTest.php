<?php

namespace Drupal\Tests\mantle2\Integration\EventSubscriber;

use Drupal\mantle2\EventSubscriber\RateLimitResponseSubscriber;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class RateLimitResponseSubscriberTest extends IntegrationTestBase
{
	private function fire(Request $request, Response $response = new Response()): Response
	{
		$event = new ResponseEvent(
			$this->container->get('http_kernel'),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			$response,
		);
		new RateLimitResponseSubscriber()->onKernelResponse($event);
		return $event->getResponse();
	}

	#[Test]
	#[TestDox('Subscribes to KernelEvents::RESPONSE at priority -128 (runs late)')]
	#[Group('mantle2/subscribers')]
	public function subscribedEvents(): void
	{
		$events = RateLimitResponseSubscriber::getSubscribedEvents();
		$this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
		$this->assertSame(['onKernelResponse', -128], $events[KernelEvents::RESPONSE]);
	}

	#[Test]
	#[TestDox('No headers are added when the request carries no stashed rate-limit data')]
	#[Group('mantle2/subscribers')]
	public function noStashNoHeaders(): void
	{
		$response = $this->fire($this->request('GET', '/v2/events'));
		$this->assertFalse($response->headers->has('X-RateLimit-Limit'));
		$this->assertFalse($response->headers->has('X-Global-RateLimit-Limit'));
	}

	#[Test]
	#[TestDox('Global-only stash emits only the global rate-limit headers')]
	#[Group('mantle2/subscribers')]
	public function globalHeaders(): void
	{
		$reset = time() + 60;
		$request = $this->request('GET', '/v2/events');
		$request->attributes->set('_mantle2_rl_headers', [
			'global' => [['remaining' => 42, 'resetTime' => $reset], 600, null],
		]);

		$headers = $this->fire($request)->headers;
		$this->assertSame('600', $headers->get('X-Global-RateLimit-Limit'));
		$this->assertSame('42', $headers->get('X-Global-RateLimit-Remaining'));
		$this->assertSame((string) $reset, $headers->get('X-Global-RateLimit-Reset'));
		$this->assertFalse($headers->has('X-RateLimit-Limit'));
	}

	#[Test]
	#[TestDox('Endpoint stash emits both endpoint and global headers')]
	#[Group('mantle2/subscribers')]
	public function endpointAndGlobalHeaders(): void
	{
		$reset = time() + 120;
		$request = $this->request('POST', '/v2/events');
		$request->attributes->set('_mantle2_rl_headers', [
			'endpoint' => [['remaining' => 9, 'resetTime' => $reset], 10, null],
			'global' => [['remaining' => 100, 'resetTime' => $reset], 300, null],
		]);

		$headers = $this->fire($request)->headers;
		$this->assertSame('10', $headers->get('X-RateLimit-Limit'));
		$this->assertSame('9', $headers->get('X-RateLimit-Remaining'));
		$this->assertSame((string) $reset, $headers->get('X-RateLimit-Reset'));
		$this->assertSame('300', $headers->get('X-Global-RateLimit-Limit'));
		$this->assertSame('100', $headers->get('X-Global-RateLimit-Remaining'));
	}

	#[Test]
	#[TestDox('Negative remaining is clamped to zero')]
	#[Group('mantle2/subscribers')]
	public function remainingClampedToZero(): void
	{
		$request = $this->request('GET', '/v2/events');
		$request->attributes->set('_mantle2_rl_headers', [
			'global' => [['remaining' => -5, 'resetTime' => time()], 600, null],
		]);

		$this->assertSame('0', $this->fire($request)->headers->get('X-Global-RateLimit-Remaining'));
	}

	#[Test]
	#[TestDox('A non-array stash is ignored')]
	#[Group('mantle2/subscribers')]
	public function nonArrayStashIgnored(): void
	{
		$request = $this->request('GET', '/v2/events');
		$request->attributes->set('_mantle2_rl_headers', 'garbage');
		$this->assertFalse($this->fire($request)->headers->has('X-Global-RateLimit-Limit'));
	}
}
