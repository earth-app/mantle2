<?php

namespace Drupal\Tests\mantle2\Integration\EventSubscriber;

use Drupal\mantle2\EventSubscriber\CorsSubscriber;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class CorsSubscriberTest extends IntegrationTestBase
{
	private function fire(?string $origin, Response $response = new Response()): Response
	{
		$server = $origin !== null ? ['HTTP_ORIGIN' => $origin] : [];
		$request = Request::create('/v2/events', 'GET', [], [], [], $server);
		$event = new ResponseEvent(
			$this->container->get('http_kernel'),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			$response,
		);
		new CorsSubscriber()->onRespond($event);
		return $event->getResponse();
	}

	#[Test]
	#[TestDox('Subscribes to KernelEvents::RESPONSE')]
	#[Group('mantle2/subscribers')]
	public function subscribedEvents(): void
	{
		$events = CorsSubscriber::getSubscribedEvents();
		$this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
		$this->assertSame('onRespond', $events[KernelEvents::RESPONSE]);
	}

	#[Test]
	#[TestDox('Static CORS headers are always applied regardless of origin')]
	#[Group('mantle2/subscribers')]
	public function staticHeaders(): void
	{
		$headers = $this->fire('https://app.earth-app.com')->headers;

		$this->assertSame('Origin', $headers->get('Vary'));
		$this->assertSame(
			'GET, POST, PUT, PATCH, DELETE, OPTIONS',
			$headers->get('Access-Control-Allow-Methods'),
		);
		$this->assertSame('true', $headers->get('Access-Control-Allow-Credentials'));
		$this->assertSame('3600', $headers->get('Access-Control-Max-Age'));
		$this->assertStringContainsString(
			'Authorization',
			$headers->get('Access-Control-Allow-Headers'),
		);
		$this->assertStringContainsString(
			'X-Admin-Key',
			$headers->get('Access-Control-Allow-Headers'),
		);
	}

	#[Test]
	#[TestDox('Allowlisted origins are echoed back into Access-Control-Allow-Origin')]
	#[Group('mantle2/subscribers')]
	#[DataProvider('allowedOrigins')]
	public function allowedOriginEchoed(string $origin): void
	{
		$this->assertSame(
			$origin,
			$this->fire($origin)->headers->get('Access-Control-Allow-Origin'),
		);
	}

	public static function allowedOrigins(): array
	{
		return [
			['https://app.earth-app.com'],
			['https://api.earth-app.com'],
			['https://earth-app.com'],
			['https://cloud.earth-app.com'],
			['capacitor://localhost'],
			['http://localhost:3000'],
			['http://127.0.0.1:3001'],
		];
	}

	#[Test]
	#[TestDox('The staging workers subdomain pattern is honored')]
	#[Group('mantle2/subscribers')]
	public function patternedOriginAllowed(): void
	{
		$origin = 'https://pr-123.earthapp-crust.gmitch215.workers.dev';
		$this->assertSame(
			$origin,
			$this->fire($origin)->headers->get('Access-Control-Allow-Origin'),
		);
	}

	#[Test]
	#[TestDox('Disallowed and missing origins fall back to the first allowlist entry')]
	#[Group('mantle2/subscribers')]
	public function disallowedFallsBack(): void
	{
		$this->assertSame(
			'https://app.earth-app.com',
			$this->fire('https://evil.example.com')->headers->get('Access-Control-Allow-Origin'),
		);
		$this->assertSame(
			'https://app.earth-app.com',
			$this->fire(null)->headers->get('Access-Control-Allow-Origin'),
		);
	}
}
