<?php

namespace Drupal\Tests\mantle2;

use Drupal;
use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\Cache;
use PHPUnit\Framework\TestCase;

// Mock Interfaces

interface KeyRepositoryInterface
{
	public function getKey(string $key_id);
}

interface KeyInterface
{
	public function getKeyValue();
}

interface SettingsInterface
{
	public function get(string $name);
}

interface LoggerFactoryInterface
{
	public function get(string $channel): LoggerInterface;
}

interface LoggerInterface
{
	public function warning(string $message);
	public function info(string $message);
	public function error(string $message);
}

interface CacheInterface
{
	public function get($cid);
	public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []);
}

// Mocks Class

class Mocks extends TestCase
{
	public static $mocks = null;

	public static function instance()
	{
		if (self::$mocks === null) {
			self::$mocks = new Mocks('Mocks');
		}

		return self::$mocks;
	}

	public function mockDrupalContainer()
	{
		$container = $this->createMock(ContainerInterface::class);

		// Key Repository Mock
		$keyRepository = $this->createMock(KeyRepositoryInterface::class);

		$key1 = $this->createMock(KeyInterface::class);
		$key1->expects($this->any())->method('getKeyValue')->willReturn('test_admin_key');

		$keyRepository
			->expects($this->any())
			->method('getKey')
			->with('mantle2_api_key')
			->willReturn($key1);

		// Settings Mock
		$settings = [
			'mantle2.cloud_endpoint' => 'https://httpbin.org', // safe test endpoint for real requests
		];

		$settingsMock = $this->createMock(SettingsInterface::class);
		$settingsMock
			->expects($this->any())
			->method('get')
			->willReturnCallback(fn($name) => $settings[$name] ?? null);

		// Logger Mock
		$loggerMock = $this->createMock(LoggerInterface::class);
		$loggerMock
			->expects($this->any())
			->method('info')
			->willReturnCallback(fn($message) => print "LOG INFO: $message\n");

		$loggerMock
			->expects($this->any())
			->method('warning')
			->willReturnCallback(fn($message) => print "LOG WARNING: $message\n");

		$loggerMock
			->expects($this->any())
			->method('error')
			->willReturnCallback(fn($message) => print "LOG ERROR: $message\n");

		$loggerFactoryMock = $this->createMock(LoggerFactoryInterface::class);
		$loggerFactoryMock
			->expects($this->any())
			->method('get')
			->with('mantle2')
			->willReturnCallback(fn($channel) => $loggerMock);

		// Cache Mock
		$cacheMock = $this->createMock(CacheInterface::class);
		$cacheMock->expects($this->any())->method('get')->willReturn(null); // Always return cache miss for simplicity

		$cacheMock
			->expects($this->any())
			->method('set')
			->willReturnCallback(fn($cid, $data, $expire, $tags) => print "CACHE SET: $cid\n");

		$container
			->expects($this->any())
			->method('get')
			->willReturnMap([
				['key.repository', $keyRepository],
				['settings', $settingsMock],
				['logger.factory', $loggerFactoryMock],
				['cache.mantle2', $cacheMock],
			]);

		return Drupal::setContainer($container);
	}
}
