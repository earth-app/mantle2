<?php

namespace Drupal\Tests\mantle2\Integration;

use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

class SmokeTest extends IntegrationTestBase
{
	#[Test]
	#[Group('mantle2/smoke')]
	public function moduleInstallsWithFieldsAndUsersResolve(): void
	{
		$this->assertTrue(\Drupal::moduleHandler()->moduleExists('mantle2'));

		$user = $this->createUser(['name' => 'smoke_user']);
		$this->assertNotNull($user->id());
		$this->assertTrue($user->hasField('field_account_type'));

		$request = $this->authRequest($user, 'GET', '/v2/hello');
		$resolved = UsersHelper::findByRequest($request);
		$this->assertSame((int) $user->id(), (int) $resolved->id());
	}

	#[Test]
	#[Group('mantle2/smoke')]
	public function redisFallsBackToCacheBin(): void
	{
		$this->assertTrue(RedisHelper::set('smoke_key', ['v' => 1], 60));
		$this->assertSame(['v' => 1], RedisHelper::get('smoke_key'));
		$this->assertTrue(RedisHelper::exists('smoke_key'));
	}
}
