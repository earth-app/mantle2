<?php

namespace Drupal\Tests\mantle2\Integration\EventSubscriber;

use Drupal\mantle2\EventSubscriber\XmlContentNegotiationSubscriber;
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

class XmlContentNegotiationSubscriberTest extends IntegrationTestBase
{
	private function subscriber(): XmlContentNegotiationSubscriber
	{
		return new XmlContentNegotiationSubscriber();
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

	#[Test]
	#[TestDox('Request handler runs at priority 20, response at -20')]
	#[Group('mantle2/subscribers')]
	public function subscribedEvents(): void
	{
		$events = XmlContentNegotiationSubscriber::getSubscribedEvents();
		$this->assertSame(['onRequest', 20], $events[KernelEvents::REQUEST]);
		$this->assertSame(['onResponse', -20], $events[KernelEvents::RESPONSE]);
	}

	#[Test]
	#[TestDox('Non /v2 XML requests are not rewritten')]
	#[Group('mantle2/subscribers')]
	public function ignoresNonV2Request(): void
	{
		$request = Request::create(
			'/admin/x',
			'POST',
			[],
			[],
			[],
			['CONTENT_TYPE' => 'application/xml'],
			'<request><a>1</a></request>',
		);
		$event = $this->onRequest($request);
		$this->assertFalse($event->hasResponse());
		$this->assertStringStartsWith('application/xml', $request->headers->get('Content-Type'));
	}

	#[Test]
	#[TestDox('Empty XML body is a no-op (no 400, no rewrite)')]
	#[Group('mantle2/subscribers')]
	public function emptyBodyNoop(): void
	{
		$request = Request::create(
			'/v2/events',
			'POST',
			[],
			[],
			[],
			['CONTENT_TYPE' => 'application/xml'],
			'   ',
		);
		$event = $this->onRequest($request);
		$this->assertFalse($event->hasResponse());
	}

	#[Test]
	#[TestDox('Malformed XML body short-circuits with a 400 JSON response')]
	#[Group('mantle2/subscribers')]
	public function malformedXmlIs400(): void
	{
		$request = Request::create(
			'/v2/events',
			'POST',
			[],
			[],
			[],
			['CONTENT_TYPE' => 'application/xml'],
			'<request><unclosed>',
		);
		$event = $this->onRequest($request);

		$this->assertTrue($event->hasResponse());
		$response = $event->getResponse();
		$this->assertInstanceOf(JsonResponse::class, $response);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$body = json_decode($response->getContent(), true);
		$this->assertSame('Invalid XML body', $body['message']);
		$this->assertSame(400, $body['code']);
	}

	#[Test]
	#[
		TestDox(
			'Round trip: XML request is normalized to JSON then rendered back to XML on response',
		),
	]
	#[Group('mantle2/subscribers')]
	public function roundTrip(): void
	{
		$request = Request::create(
			'/v2/events',
			'POST',
			[],
			[],
			[],
			['CONTENT_TYPE' => 'application/xml', 'HTTP_ACCEPT' => 'application/xml'],
			'<request><title>Cleanup</title><count>3</count></request>',
		);

		$this->onRequest($request);
		$this->assertStringStartsWith('application/json', $request->headers->get('Content-Type'));
		$this->assertSame('Cleanup', $request->request->get('title'));
		$this->assertSame(3, $request->request->getInt('count'));

		$event = $this->onResponse(
			$request,
			new JsonResponse(['title' => 'Cleanup', 'count' => 3]),
		);
		$result = $event->getResponse();
		$this->assertSame('application/xml; charset=UTF-8', $result->headers->get('Content-Type'));
		$this->assertStringContainsString('<title>Cleanup</title>', $result->getContent());
		$this->assertStringContainsString('<count>3</count>', $result->getContent());
	}

	#[Test]
	#[TestDox('format=json wins over an XML Accept header on the response')]
	#[Group('mantle2/subscribers')]
	public function formatJsonOverridesAccept(): void
	{
		$request = Request::create(
			'/v2/events?format=json',
			'GET',
			[],
			[],
			[],
			['HTTP_ACCEPT' => 'application/xml'],
		);
		$response = new JsonResponse(['a' => 1]);
		$event = $this->onResponse($request, $response);
		$this->assertSame($response, $event->getResponse());
		$this->assertStringStartsWith(
			'application/json',
			$event->getResponse()->headers->get('Content-Type'),
		);
	}

	#[Test]
	#[TestDox('format=xml forces XML even without an XML Accept header')]
	#[Group('mantle2/subscribers')]
	public function formatXmlForcesXml(): void
	{
		$request = Request::create('/v2/events?format=xml', 'GET');
		$event = $this->onResponse($request, new JsonResponse(['a' => 1]));
		$this->assertSame(
			'application/xml; charset=UTF-8',
			$event->getResponse()->headers->get('Content-Type'),
		);
	}

	#[Test]
	#[TestDox('Non-JsonResponse bodies are passed through untouched even when XML is requested')]
	#[Group('mantle2/subscribers')]
	public function nonJsonResponsePassthrough(): void
	{
		$request = Request::create(
			'/v2/events',
			'GET',
			[],
			[],
			[],
			['HTTP_ACCEPT' => 'application/xml'],
		);
		$response = new Response('plain', 200);
		$event = $this->onResponse($request, $response);
		$this->assertSame($response, $event->getResponse());
		$this->assertSame('plain', $event->getResponse()->getContent());
	}

	#[Test]
	#[TestDox('Response status code is preserved when converting JSON to XML')]
	#[Group('mantle2/subscribers')]
	public function statusPreserved(): void
	{
		$request = Request::create(
			'/v2/events',
			'GET',
			[],
			[],
			[],
			['HTTP_ACCEPT' => 'application/xml'],
		);
		$event = $this->onResponse($request, new JsonResponse(['error' => 'nope'], 404));
		$this->assertSame(404, $event->getResponse()->getStatusCode());
		$this->assertStringStartsWith(
			'application/xml',
			$event->getResponse()->headers->get('Content-Type'),
		);
	}
}
