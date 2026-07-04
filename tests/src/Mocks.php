<?php

namespace Drupal\Tests\mantle2;

use Drupal;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use PHPUnit\Framework\TestCase;

class Mocks extends TestCase
{
	public static ?Mocks $mocks = null;

	public static function instance(): Mocks
	{
		if (self::$mocks === null) {
			self::$mocks = new Mocks('Mocks');
		}

		return self::$mocks;
	}

	public function mockDrupalContainer(string $adminKey = 'test_admin_key'): void
	{
		$container = new ContainerBuilder();

		$key = $this->createMock(KeyInterface::class);
		$key->method('getKeyValue')->willReturn($adminKey);

		$keyRepository = $this->createMock(KeyRepositoryInterface::class);
		$keyRepository
			->method('getKey')
			->willReturnCallback(fn($id) => $id === 'mantle2_api_key' ? $key : null);

		$logger = $this->createMock(LoggerChannelInterface::class);
		$loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
		$loggerFactory->method('get')->willReturn($logger);

		$cache = $this->createMock(CacheBackendInterface::class);
		$cache->method('get')->willReturn(false);

		$container->set('key.repository', $keyRepository);
		$container->set(
			'settings',
			new Settings(['mantle2.cloud_endpoint' => 'https://httpbin.org']),
		);
		$container->set('logger.factory', $loggerFactory);
		$container->set('cache.mantle2', $cache);

		Drupal::setContainer($container);
	}
}
