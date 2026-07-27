<?php

namespace Drupal\Tests\mantle2\Integration;

use Drupal\Component\FileCache\FileCacheFactory;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\StaticFileCacheBackend;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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

	#[Test]
	#[TestDox('MANTLE2_TEST_ISOLATION decides whether each test gets its own php process')]
	#[Group('mantle2/smoke')]
	public function processIsolationFollowsTheEnvironment(): void
	{
		$flag = new ReflectionProperty(TestCase::class, 'runTestInSeparateProcess');
		$shared = getenv('MANTLE2_TEST_ISOLATION') === '0';

		$this->assertSame(!$shared, $flag->getValue($this));
		$this->assertSame(
			!$shared,
			$flag->getValue(new SmokeTest('processIsolationFollowsTheEnvironment')),
		);
	}

	#[Test]
	#[TestDox('Parsed extension metadata goes through the process-wide FileCache backend')]
	#[Group('mantle2/smoke')]
	public function fileCacheBackendIsInstalled(): void
	{
		$configuration = FileCacheFactory::getConfiguration();
		$this->assertSame(
			StaticFileCacheBackend::class,
			$configuration['default']['cache_backend_class'] ?? null,
		);

		// the boot that just ran had to parse every module info/services yaml through it
		$this->assertGreaterThan(0, StaticFileCacheBackend::size());
	}
}
