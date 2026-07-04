<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\PromptResponse;
use Drupal\mantle2\Service\GeneralHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class PromptResponseTest extends TestCase
{
	#[Test]
	#[TestDox('Every getter returns its constructor argument')]
	#[Group('mantle2/custom')]
	public function testGetters(): void
	{
		$r = new PromptResponse(5, 101, 'my answer', 42, 1717000000, 1717000100);
		$this->assertSame(5, $r->getId());
		$this->assertSame(101, $r->getPromptId());
		$this->assertSame('my answer', $r->getResponse());
		$this->assertSame(42, $r->getOwnerId());
		$this->assertSame(1717000000, $r->getCreatedAt());
		$this->assertSame(1717000100, $r->getUpdatedAt());
	}

	#[Test]
	#[TestDox('Null ownerId collapses to the -1 anonymous sentinel')]
	#[Group('mantle2/custom')]
	public function testNullOwnerIdBecomesAnonymous(): void
	{
		$r = new PromptResponse(5, 101, 'a', null);
		$this->assertSame(-1, $r->getOwnerId());
	}

	#[Test]
	#[TestDox('ownerId defaults to -1 anonymous when omitted')]
	#[Group('mantle2/custom')]
	public function testOwnerIdDefault(): void
	{
		$r = new PromptResponse(5, 101, 'a');
		$this->assertSame(-1, $r->getOwnerId());
	}

	#[Test]
	#[TestDox('Null createdAt/updatedAt default to current time')]
	#[Group('mantle2/custom')]
	public function testTimestampDefaults(): void
	{
		$before = time();
		$r = new PromptResponse(5, 101, 'a', 42);
		$after = time();

		$this->assertGreaterThanOrEqual($before, $r->getCreatedAt());
		$this->assertLessThanOrEqual($after, $r->getCreatedAt());
		$this->assertGreaterThanOrEqual($before, $r->getUpdatedAt());
		$this->assertLessThanOrEqual($after, $r->getUpdatedAt());
	}

	#[Test]
	#[TestDox('setResponse mutates the response text')]
	#[Group('mantle2/custom')]
	public function testSetResponse(): void
	{
		$r = new PromptResponse(5, 101, 'a', 42);
		$r->setResponse('edited');
		$this->assertSame('edited', $r->getResponse());
	}

	#[Test]
	#[TestDox('hideOwnerId forces the owner to the -1 anonymous sentinel')]
	#[Group('mantle2/custom')]
	public function testHideOwnerId(): void
	{
		$r = new PromptResponse(5, 101, 'a', 42);
		$this->assertSame(42, $r->getOwnerId());
		$r->hideOwnerId();
		$this->assertSame(-1, $r->getOwnerId());
	}

	#[Test]
	#[TestDox('jsonSerialize omits owner entirely for anonymous (ownerId -1) responses')]
	#[Group('mantle2/custom')]
	public function testJsonSerializeAnonymous(): void
	{
		$json = new PromptResponse(5, 101, 'a', -1)->jsonSerialize();

		$this->assertSame(['id', 'prompt_id', 'response'], array_keys($json));
		$this->assertArrayNotHasKey('owner', $json);
		$this->assertSame(GeneralHelper::formatId(5), $json['id']);
		$this->assertSame(GeneralHelper::formatId(101), $json['prompt_id']);
		$this->assertSame('a', $json['response']);
	}

	#[Test]
	#[TestDox('jsonSerialize omits owner after hideOwnerId even when a real owner was set')]
	#[Group('mantle2/custom')]
	public function testJsonSerializeAnonymousAfterHide(): void
	{
		$r = new PromptResponse(5, 101, 'a', 42);
		$r->hideOwnerId();
		$json = $r->jsonSerialize();

		$this->assertSame(['id', 'prompt_id', 'response'], array_keys($json));
		$this->assertArrayNotHasKey('owner', $json);
	}
}
