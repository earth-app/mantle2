<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\Component\FileCache\FileCacheBackendInterface;
use Drupal\Component\FileCache\FileCacheFactory;
use Drupal\Tests\mantle2\StaticFileCacheBackend;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class StaticFileCacheBackendUnitTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		StaticFileCacheBackend::reset();
	}

	protected function tearDown(): void
	{
		StaticFileCacheBackend::reset();
		parent::tearDown();
	}

	#[Test]
	#[TestDox('store then fetch round-trips values and skips unknown cids')]
	#[Group('mantle2/util')]
	public function testStoreAndFetch()
	{
		$backend = new StaticFileCacheBackend();
		$backend->store('a', ['parsed' => 1]);
		$backend->store('b', 'raw');

		$this->assertSame(
			['a' => ['parsed' => 1], 'b' => 'raw'],
			$backend->fetch(['a', 'b', 'missing']),
		);
		$this->assertSame([], $backend->fetch(['missing']));
		$this->assertSame([], $backend->fetch([]));
	}

	#[Test]
	#[TestDox('A value stored by one instance is visible to the next one')]
	#[Group('mantle2/util')]
	public function testStoreIsProcessWide()
	{
		// this is the whole point: FileCache::reset() in KernelTestBase::tearDown() drops the
		// in-memory layer, and a fresh backend instance still has to serve the parsed data
		new StaticFileCacheBackend()->store('shared', 'value');

		$this->assertSame(['shared' => 'value'], new StaticFileCacheBackend()->fetch(['shared']));
	}

	#[Test]
	#[TestDox('delete drops a single entry and reset drops all of them')]
	#[Group('mantle2/util')]
	public function testDeleteAndReset()
	{
		$backend = new StaticFileCacheBackend();
		$backend->store('a', 1);
		$backend->store('b', 2);
		$this->assertSame(2, StaticFileCacheBackend::size());

		$backend->delete('a');
		$this->assertSame(['b' => 2], $backend->fetch(['a', 'b']));
		$this->assertSame(1, StaticFileCacheBackend::size());

		$backend->delete('missing');
		$this->assertSame(['b' => 2], $backend->fetch(['a', 'b']));

		StaticFileCacheBackend::reset();
		$this->assertSame([], $backend->fetch(['a', 'b']));
		$this->assertSame(0, StaticFileCacheBackend::size());
	}

	#[Test]
	#[TestDox('FileCacheFactory can build it from a configuration array')]
	#[Group('mantle2/util')]
	public function testUsableAsAFileCacheBackend()
	{
		$this->assertInstanceOf(FileCacheBackendInterface::class, new StaticFileCacheBackend());

		$previous = FileCacheFactory::getConfiguration();
		$previousPrefix = FileCacheFactory::getPrefix();
		FileCacheFactory::setConfiguration([
			'default' => ['cache_backend_class' => StaticFileCacheBackend::class],
		]);
		FileCacheFactory::setPrefix('mantle2-test');

		try {
			$cache = FileCacheFactory::get('unit_collection');
			$file = tempnam(sys_get_temp_dir(), 'fc');
			file_put_contents($file, '<?php // fixture');

			$cache->set($file, ['parsed' => true]);
			$this->assertSame(['parsed' => true], $cache->get($file));

			unlink($file);
		} finally {
			FileCacheFactory::setConfiguration($previous);
			FileCacheFactory::setPrefix($previousPrefix);
		}
	}
}
