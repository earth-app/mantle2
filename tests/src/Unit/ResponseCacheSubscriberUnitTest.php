<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\mantle2\EventSubscriber\ResponseCacheSubscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ResponseCacheSubscriberUnitTest extends TestCase
{
	private function invokePrivate(string $method, array $args): mixed
	{
		$ref = new ReflectionMethod(ResponseCacheSubscriber::class, $method);
		return $ref->invokeArgs(null, $args);
	}

	#[Test]
	#[TestDox('buildCacheKey refuses a key with an unresolved placeholder (no silent collapse)')]
	#[Group('mantle2/caching')]
	public function testBuildCacheKeyRefusesUnresolvedPlaceholder(): void
	{
		$key = $this->invokePrivate('buildCacheKey', [
			'request_cache:user:profile:{uid}:req:{req_uid}',
			['req_uid' => 0],
		]);
		$this->assertNull($key, 'an unresolved {uid} must not build a collapsed, colliding key');
	}

	#[Test]
	#[TestDox('buildCacheKey substitutes every placeholder when all params are present')]
	#[Group('mantle2/caching')]
	public function testBuildCacheKeyResolvesFully(): void
	{
		$key = $this->invokePrivate('buildCacheKey', [
			'request_cache:user:profile:{uid}:req:{req_uid}',
			['uid' => 42, 'req_uid' => 7],
		]);
		$this->assertSame('request_cache:user:profile:42:req:7', $key);
	}

	#[Test]
	#[TestDox('Distinct uids yield distinct cache keys')]
	#[Group('mantle2/caching')]
	public function testDistinctUidsProduceDistinctKeys(): void
	{
		$tpl = 'request_cache:user:profile:{uid}:req:{req_uid}';
		$a = $this->invokePrivate('buildCacheKey', [$tpl, ['uid' => 1, 'req_uid' => 0]]);
		$b = $this->invokePrivate('buildCacheKey', [$tpl, ['uid' => 2, 'req_uid' => 0]]);
		$this->assertNotSame($a, $b);
	}

	#[Test]
	#[TestDox('findRetrievalConfig does not cache /v2/users/quests')]
	#[Group('mantle2/caching')]
	public function testQuestsPathMatchesNoRetrievalRule(): void
	{
		$this->assertNull($this->invokePrivate('findRetrievalConfig', ['/v2/users/quests', 'GET']));
	}

	#[Test]
	#[TestDox('findRetrievalConfig still routes /v2/users/current to the profile rule')]
	#[Group('mantle2/caching')]
	public function testCurrentPathMatchesProfileRule(): void
	{
		$rule = $this->invokePrivate('findRetrievalConfig', ['/v2/users/current', 'GET']);
		$this->assertNotNull($rule);
		$this->assertSame('request_cache:user:profile:{uid}:req:{req_uid}', $rule['key_template']);
	}
}
