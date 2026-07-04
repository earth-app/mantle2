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
}
