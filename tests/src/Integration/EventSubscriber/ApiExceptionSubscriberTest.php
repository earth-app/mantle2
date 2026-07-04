<?php

namespace Drupal\Tests\mantle2\Integration\EventSubscriber;

use Drupal\mantle2\EventSubscriber\ApiExceptionSubscriber;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

class ApiExceptionSubscriberTest extends IntegrationTestBase
{
	private function subscriber(): ApiExceptionSubscriber
	{
		return new ApiExceptionSubscriber();
	}

	private function fire(Request $request, Throwable $throwable): ExceptionEvent
	{
		$event = new ExceptionEvent(
			$this->container->get('http_kernel'),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			$throwable,
		);
		$this->subscriber()->onException($event);
		return $event;
	}

	#[Test]
	#[TestDox('Subscribes to KernelEvents::EXCEPTION at priority 100')]
	#[Group('mantle2/subscribers')]
	public function subscribedEvents(): void
	{
		$events = ApiExceptionSubscriber::getSubscribedEvents();
		$this->assertArrayHasKey(KernelEvents::EXCEPTION, $events);
		$this->assertSame(['onException', 100], $events[KernelEvents::EXCEPTION]);
	}

	#[Test]
	#[TestDox('Non /v2 paths are left untouched (no response set)')]
	#[Group('mantle2/subscribers')]
	public function ignoresNonV2Paths(): void
	{
		$event = $this->fire(
			$this->request('GET', '/admin/content'),
			new NotFoundHttpException('nope'),
		);
		$this->assertFalse($event->hasResponse());
	}

	#[Test]
	#[TestDox('HttpException maps to its own status with a JSON error body')]
	#[Group('mantle2/subscribers')]
	public function httpExceptionShape(): void
	{
		$event = $this->fire(
			$this->request('GET', '/v2/events'),
			new HttpException(422, 'Unprocessable payload'),
		);

		$this->assertTrue($event->hasResponse());
		$response = $event->getResponse();
		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertSame(422, $response->getStatusCode());
		$this->assertSame(
			'application/json; charset=UTF-8',
			$response->headers->get('Content-Type'),
		);

		$body = json_decode($response->getContent(), true);
		$this->assertSame('Unprocessable payload', $body['message']);
		$this->assertSame(422, $body['code']);
	}

	#[Test]
	#[TestDox('4xx HttpException with empty message falls back to the default reason phrase')]
	#[Group('mantle2/subscribers')]
	public function httpExceptionDefaultMessage(): void
	{
		$event = $this->fire($this->request('POST', '/v2/events'), new NotFoundHttpException(''));

		$body = json_decode($event->getResponse()->getContent(), true);
		$this->assertSame(404, $event->getResponse()->getStatusCode());
		$this->assertSame('Not Found', $body['message']);
		$this->assertSame(404, $body['code']);
	}

	#[Test]
	#[TestDox('5xx HttpException hides its message behind Internal Server Error')]
	#[Group('mantle2/subscribers')]
	public function serverErrorHttpExceptionMasksMessage(): void
	{
		$event = $this->fire(
			$this->request('GET', '/v2/events'),
			new HttpException(503, 'db is down with secrets in the message'),
		);

		$body = json_decode($event->getResponse()->getContent(), true);
		$this->assertSame(503, $event->getResponse()->getStatusCode());
		$this->assertSame('Internal Server Error', $body['message']);
		$this->assertSame(503, $body['code']);
	}

	#[Test]
	#[TestDox('Plain exception with an HTTP-range code adopts that status')]
	#[Group('mantle2/subscribers')]
	public function plainExceptionWithHttpCode(): void
	{
		$event = $this->fire(
			$this->request('GET', '/v2/events'),
			new RuntimeException('Forbidden thing', 403),
		);

		$body = json_decode($event->getResponse()->getContent(), true);
		$this->assertSame(403, $event->getResponse()->getStatusCode());
		$this->assertSame('Forbidden thing', $body['message']);
		$this->assertSame(403, $body['code']);
	}

	#[Test]
	#[TestDox('Plain exception with out-of-range code defaults to 500 masked')]
	#[Group('mantle2/subscribers')]
	public function plainExceptionOutOfRangeCode(): void
	{
		$event = $this->fire(
			$this->request('GET', '/v2/events'),
			new RuntimeException('kaboom', 0),
		);

		$body = json_decode($event->getResponse()->getContent(), true);
		$this->assertSame(500, $event->getResponse()->getStatusCode());
		$this->assertSame('Internal Server Error', $body['message']);
		$this->assertSame(500, $body['code']);
	}

	#[Test]
	#[TestDox('Default reason phrases match the exception status code')]
	#[Group('mantle2/subscribers')]
	#[DataProvider('reasonPhrases')]
	public function defaultReasonPhrases(int $status, string $expected): void
	{
		$event = $this->fire($this->request('GET', '/v2/events'), new HttpException($status, ''));

		$body = json_decode($event->getResponse()->getContent(), true);
		$this->assertSame($status, $event->getResponse()->getStatusCode());
		$this->assertSame($expected, $body['message']);
	}

	public static function reasonPhrases(): array
	{
		return [
			'400' => [400, 'Bad Request'],
			'401' => [401, 'Unauthorized'],
			'403' => [403, 'Forbidden'],
			'404' => [404, 'Not Found'],
			'405' => [405, 'Method Not Allowed'],
			'410' => [410, 'Gone'],
			'415' => [415, 'Unsupported Media Type'],
			'422' => [422, 'Unprocessable Entity'],
			'429' => [429, 'Too Many Requests'],
		];
	}
}
