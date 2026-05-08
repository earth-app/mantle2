<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\mantle2\Controller\Schema\Mantle2Schemas;
use Drupal\mantle2\EventSubscriber\XmlContentNegotiationSubscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class XmlContentNegotiationSubscriberTest extends TestCase
{
	#[Test]
	#[TestDox('Convert complex XML request payloads into JSON-ready arrays')]
	#[Group('mantle2/xml')]
	public function testXmlToArrayConversion(): void
	{
		$xml = <<<'XML'
		<request>
		  <title>Forest cleanup</title>
		  <enabled>true</enabled>
		  <priority>5</priority>
		  <metadata>
		    <owner>Earth App</owner>
		    <tags>
		      <item>community</item>
		      <item>volunteer</item>
		    </tags>
		  </metadata>
		  <steps>
		    <step>
		      <name>Register</name>
		      <done>false</done>
		    </step>
		    <step>
		      <name>Attend</name>
		      <done>true</done>
		    </step>
		  </steps>
		</request>
		XML;

		$result = XmlContentNegotiationSubscriber::xmlToArray($xml);

		$this->assertSame('Forest cleanup', $result['title']);
		$this->assertTrue($result['enabled']);
		$this->assertSame(5, $result['priority']);
		$this->assertSame('Earth App', $result['metadata']['owner']);
		$this->assertSame(['community', 'volunteer'], $result['metadata']['tags']);
		$this->assertCount(2, $result['steps']);
		$this->assertSame('Register', $result['steps'][0]['name']);
		$this->assertFalse($result['steps'][0]['done']);
		$this->assertSame('Attend', $result['steps'][1]['name']);
		$this->assertTrue($result['steps'][1]['done']);
	}

	#[Test]
	#[TestDox('Convert nested JSON structures into XML with escaped text')]
	#[Group('mantle2/xml')]
	public function testArrayToXmlConversion(): void
	{
		$data = [
			'title' => 'Cleanup & Restore',
			'enabled' => true,
			'priority' => 5,
			'metadata' => [
				'owner' => 'Earth <Team>',
				'tags' => ['community', 'volunteer'],
			],
			'steps' => [
				['name' => 'Register', 'done' => false],
				['name' => 'Attend', 'done' => true],
			],
		];

		$xml = XmlContentNegotiationSubscriber::arrayToXml($data);

		$this->assertStringContainsString('<response>', $xml);
		$this->assertStringContainsString('<title>Cleanup &amp; Restore</title>', $xml);
		$this->assertStringContainsString('<owner>Earth &lt;Team&gt;</owner>', $xml);
		$this->assertStringContainsString('<item>community</item>', $xml);
		$this->assertStringContainsString('<item>volunteer</item>', $xml);
		$this->assertStringContainsString('<done>false</done>', $xml);
		$this->assertStringContainsString('<done>true</done>', $xml);
	}

	#[Test]
	#[TestDox('OpenAPI schema helpers use fixed request and response XML roots')]
	#[Group('mantle2/xml')]
	public function testOpenApiSchemaXmlRoots(): void
	{
		$requestBody = Mantle2Schemas::requestBody(['type' => 'object']);
		$responseBody = Mantle2Schemas::responseBody(['type' => 'object']);

		$this->assertSame(
			'request',
			$requestBody['content']['application/xml']['schema']['xml']['name'],
		);
		$this->assertSame(
			'response',
			$responseBody['content']['application/xml']['schema']['xml']['name'],
		);
		$this->assertArrayHasKey('application/json', $requestBody['content']);
		$this->assertArrayHasKey('application/json', $responseBody['content']);
	}

	#[Test]
	#[TestDox('Request bodies with XML content type are rewritten to JSON')]
	#[Group('mantle2/xml')]
	public function testOnRequestRewritesXmlToJson(): void
	{
		$xml =
			'<request><title>Marine cleanup</title><enabled>true</enabled><priority>7</priority></request>';
		$request = Request::create(
			'/v2/events',
			'POST',
			[],
			[],
			[],
			[
				'CONTENT_TYPE' => 'application/xml',
				'HTTP_ACCEPT' => 'application/json',
			],
			$xml,
		);

		$event = new RequestEvent(new DummyKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
		$subscriber = new XmlContentNegotiationSubscriber();
		$subscriber->onRequest($event);

		$this->assertSame(
			'application/json; charset=UTF-8',
			$request->headers->get('Content-Type'),
		);
		$this->assertSame(
			'{"title":"Marine cleanup","enabled":true,"priority":7}',
			$request->getContent(),
		);
		$this->assertSame('Marine cleanup', $request->request->get('title'));
		$this->assertTrue($request->request->getBoolean('enabled'));
		$this->assertSame(7, $request->request->getInt('priority'));
	}

	#[Test]
	#[TestDox('JSON responses are rewritten to XML when XML is requested')]
	#[Group('mantle2/xml')]
	public function testOnResponseRewritesJsonToXml(): void
	{
		$request = Request::create(
			'/v2/events',
			'GET',
			[],
			[],
			[],
			['HTTP_ACCEPT' => 'application/xml'],
		);
		$response = new JsonResponse([
			'title' => 'Cleanup & Restore',
			'enabled' => true,
			'priority' => 9,
			'tags' => ['community', 'volunteer'],
		]);

		$event = new ResponseEvent(
			new DummyKernel(),
			$request,
			HttpKernelInterface::MAIN_REQUEST,
			$response,
		);
		$subscriber = new XmlContentNegotiationSubscriber();
		$subscriber->onResponse($event);

		$result = $event->getResponse();
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('application/xml; charset=UTF-8', $result->headers->get('Content-Type'));
		$this->assertStringContainsString(
			'<title>Cleanup &amp; Restore</title>',
			$result->getContent(),
		);
		$this->assertStringContainsString('<enabled>true</enabled>', $result->getContent());
		$this->assertStringContainsString('<priority>9</priority>', $result->getContent());
		$this->assertStringContainsString('<item>community</item>', $result->getContent());
	}
}

class DummyKernel implements HttpKernelInterface
{
	public function handle(
		Request $request,
		int $type = HttpKernelInterface::MAIN_REQUEST,
		bool $catch = true,
	): Response {
		return new Response();
	}
}
