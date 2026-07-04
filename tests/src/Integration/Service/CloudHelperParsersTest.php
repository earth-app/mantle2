<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Service\CloudHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class CloudHelperParsersTest extends IntegrationTestBase
{
	#[Test]
	#[TestDox('getCloudEndpoint falls back to the local default when unset')]
	#[Group('mantle2/cloud')]
	public function getCloudEndpointDefault(): void
	{
		$endpoint = CloudHelper::getCloudEndpoint();
		$this->assertIsString($endpoint);
		$this->assertNotSame('', $endpoint);
	}

	#[Test]
	#[TestDox('getAdminKey returns the configured mantle2 admin key')]
	#[Group('mantle2/cloud')]
	public function getAdminKey(): void
	{
		$this->assertSame('test_admin_key', CloudHelper::getAdminKey());
	}

	#[Test]
	#[TestDox('mapCloudException maps 404 to not found and everything else to internal error')]
	#[Group('mantle2/cloud')]
	public function mapCloudException(): void
	{
		$notFound = CloudHelper::mapCloudException(new Exception('x', 404), 'fallback');
		$this->assertSame(Response::HTTP_NOT_FOUND, $notFound->getStatusCode());

		$server = CloudHelper::mapCloudException(new Exception('x', 500), 'fallback message');
		$this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $server->getStatusCode());
		$this->assertStringContainsString('fallback message', $server->getContent());

		$zero = CloudHelper::mapCloudException(new Exception('x', 0), 'fb');
		$this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $zero->getStatusCode());
	}

	#[Test]
	#[TestDox('extractCloudMessage pulls message/error and folds details+reason extras')]
	#[Group('mantle2/cloud')]
	#[DataProvider('cloudMessageProvider')]
	public function extractCloudMessage(string $raw, string $expected): void
	{
		$this->assertSame($expected, CloudHelper::extractCloudMessage(new Exception($raw)));
	}

	public static function cloudMessageProvider(): array
	{
		return [
			'message field' => ['HTTP Error: 400 Response: {"message":"Bad thing"}', 'Bad thing'],
			'error field fallback' => [
				'HTTP Error: 400 Response: {"error":"WS failure"}',
				'WS failure',
			],
			'folds details and reason' => [
				'HTTP Error: 400 Response: {"message":"Bad thing","details":"detail here","reason":"because"}',
				'Bad thing (detail here; because)',
			],
			'skips extras already in message' => [
				'HTTP Error: 400 Response: {"message":"Bad thing detail here","details":"detail here"}',
				'Bad thing detail here',
			],
			'plain text body' => ['HTTP Error: 500 Response: something broke', 'something broke'],
			'no response marker' => ['cURL Error: timed out', ''],
			'empty response body' => ['HTTP Error: 500 Response: ', ''],
		];
	}
}
