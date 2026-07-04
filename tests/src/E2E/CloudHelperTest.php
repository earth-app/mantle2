<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Service\CloudHelper;
use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class CloudHelperTest extends E2ETestBase
{
	#[Test]
	#[TestDox('sendRequest rejects an empty path before touching the network')]
	#[Group('mantle2/cloud')]
	public function sendRequestRejectsEmptyPath(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Path is required');
		CloudHelper::sendRequest('');
	}

	#[Test]
	#[TestDox('getCloudEndpoint reflects the live worker endpoint under test')]
	#[Group('mantle2/cloud')]
	public function endpointPointsAtLiveWorker(): void
	{
		$this->assertSame($this->cloudEndpoint, rtrim(CloudHelper::getCloudEndpoint(), '/'));
	}

	#[Test]
	#[TestDox('unknown cloud paths 404 into an empty array rather than throwing')]
	#[Group('mantle2/cloud')]
	public function unknownPathReturnsEmptyArray(): void
	{
		$result = CloudHelper::sendRequest(
			'/v1/____definitely_not_a_route____/' . bin2hex(random_bytes(6)),
		);
		$this->assertSame([], $result);
	}

	#[Test]
	#[TestDox('a leading slash is optional and normalized against the endpoint')]
	#[Group('mantle2/cloud')]
	public function leadingSlashNormalized(): void
	{
		$withSlash = CloudHelper::sendRequest('/v1/____nope____/' . bin2hex(random_bytes(6)));
		$withoutSlash = CloudHelper::sendRequest('v1/____nope____/' . bin2hex(random_bytes(6)));
		$this->assertSame([], $withSlash);
		$this->assertSame([], $withoutSlash);
	}
}
