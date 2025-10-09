<?php

use Drupal\mantle2\Service\CloudHelper;
use Drupal\Tests\mantle2\Mocks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class CloudUnitTest extends TestCase
{
	protected function setUp(): void
	{
		Mocks::instance()->mockDrupalContainer();
	}

	#[Test]
	#[TestDox('Test CloudHelper settings retrieval')]
	#[Group('mantle2/cloud')]
	public function testCloudSettings()
	{
		$this->assertEquals('test_admin_key', CloudHelper::getAdminKey());
		$this->assertEquals('https://httpbin.org', CloudHelper::getCloudEndpoint());
	}

	#[Test]
	#[TestDox('Test CloudHelper sendRequest method')]
	#[Group('mantle2/cloud')]
	public function testSendRequest()
	{
		// Test GET request
		$response = CloudHelper::sendRequest('get', 'GET', ['param1' => 'value1']);
		$this->assertArrayHasKey('args', $response);
		$this->assertEquals('value1', $response['args']['param1']);

		// Test POST request
		$response = CloudHelper::sendRequest('post', 'POST', ['param2' => 'value2']);
		$this->assertArrayHasKey('json', $response);
		$this->assertEquals('value2', $response['json']['param2']);

		// Test POST without data
		$response = CloudHelper::sendRequest('post', 'POST');
		$this->assertArrayHasKey('json', $response);
	}
}
