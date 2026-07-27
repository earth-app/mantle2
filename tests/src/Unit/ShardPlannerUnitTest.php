<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\Tests\mantle2\ShardPlanner;
use DOMDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ShardPlannerUnitTest extends TestCase
{
	private string $repoRoot;

	protected function setUp(): void
	{
		parent::setUp();
		$this->repoRoot = dirname(__DIR__, 3);
	}

	private static function listing(array $tests): array
	{
		$lines = [
			'PHPUnit 11.5.55 by Sebastian Bergmann and contributors.',
			'',
			'Available test(s):',
		];
		foreach ($tests as $test) {
			$lines[] = ' - ' . $test;
		}
		return $lines;
	}

	#[Test]
	#[TestDox('countByClass groups list-tests output by class and counts data-provider rows')]
	#[Group('mantle2/util')]
	public function testCountByClass()
	{
		$counts = ShardPlanner::countByClass(
			self::listing([
				'Drupal\Tests\mantle2\Integration\Service\AlphaTest::testOne',
				'Drupal\Tests\mantle2\Integration\Service\AlphaTest::testTwo with data set "a"',
				'Drupal\Tests\mantle2\Integration\Service\AlphaTest::testTwo with data set "b"',
				'Drupal\Tests\mantle2\Integration\BetaTest::testOne',
			]),
		);

		$this->assertSame(
			[
				'Drupal\Tests\mantle2\Integration\Service\AlphaTest' => 3,
				'Drupal\Tests\mantle2\Integration\BetaTest' => 1,
			],
			$counts,
		);
	}

	#[Test]
	#[TestDox('countByClass ignores banner lines and entries without a method separator')]
	#[Group('mantle2/util')]
	public function testCountByClassIgnoresNoise()
	{
		$counts = ShardPlanner::countByClass([
			'PHPUnit 11.5.55 by Sebastian Bergmann and contributors.',
			'',
			'Available test(s):',
			' - Drupal\Tests\mantle2\Unit\AlphaTest::testOne',
			' - NotATestName',
			'random trailing output',
		]);

		$this->assertSame(['Drupal\Tests\mantle2\Unit\AlphaTest' => 1], $counts);
	}

	/**
	 * @param array<string,int> $counts
	 * @param list<list<string>> $shards
	 * @return list<int>
	 */
	private static function weigh(array $counts, array $shards): array
	{
		return array_map(
			fn(array $classes) => array_sum(array_map(fn($class) => $counts[$class], $classes)),
			$shards,
		);
	}

	#[Test]
	#[TestDox('pack holds the longest-first makespan bound even when a few classes dominate')]
	#[Group('mantle2/util')]
	public function testPackHoldsMakespanBound()
	{
		$counts = [
			'a' => 103,
			'b' => 86,
			'c' => 73,
			'd' => 61,
			'e' => 54,
			'f' => 53,
			'g' => 47,
			'h' => 47,
		];
		$shards = ShardPlanner::pack($counts, 4);
		$weights = self::weigh($counts, $shards);

		$this->assertCount(4, $shards);
		$this->assertSame(array_sum($counts), array_sum($weights));

		// longest-processing-time-first guarantees (4/3 - 1/3m) x the perfect split
		$ideal = array_sum($counts) / 4;
		$this->assertLessThanOrEqual((4 / 3 - 1 / 12) * $ideal, max($weights));
	}

	#[Test]
	#[TestDox('pack keeps every shard within 5% of the average on a real suite shape')]
	#[Group('mantle2/util')]
	public function testPackBalancesRealisticSuite()
	{
		// mirrors the integration suite: a long tail of small classes under a few big ones
		$sizes = [
			103,
			86,
			73,
			61,
			54,
			53,
			47,
			47,
			45,
			43,
			38,
			35,
			33,
			31,
			27,
			27,
			26,
			23,
			23,
			22,
			20,
			19,
			19,
			19,
			16,
		];
		for ($i = 0; $i < 30; $i++) {
			$sizes[] = 15 - ($i % 13);
		}

		$counts = [];
		foreach ($sizes as $i => $size) {
			$counts['class' . $i] = $size;
		}

		$weights = self::weigh($counts, ShardPlanner::pack($counts, 8));
		$ideal = array_sum($counts) / 8;

		$this->assertLessThanOrEqual($ideal * 1.05, max($weights));
		$this->assertGreaterThanOrEqual($ideal * 0.95, min($weights));
	}

	#[Test]
	#[TestDox('pack assigns every class exactly once and is stable across runs')]
	#[Group('mantle2/util')]
	public function testPackIsTotalAndDeterministic()
	{
		$counts = ['a' => 5, 'b' => 5, 'c' => 3, 'd' => 9, 'e' => 1];

		$first = ShardPlanner::pack($counts, 3);
		$shuffled = ['e' => 1, 'd' => 9, 'a' => 5, 'c' => 3, 'b' => 5];
		$second = ShardPlanner::pack($shuffled, 3);

		$this->assertSame($first, $second);

		$assigned = array_merge(...$first);
		sort($assigned);
		$this->assertSame(['a', 'b', 'c', 'd', 'e'], $assigned);
	}

	#[Test]
	#[TestDox('pack handles more shards than classes by leaving the surplus empty')]
	#[Group('mantle2/util')]
	public function testPackWithMoreShardsThanClasses()
	{
		$shards = ShardPlanner::pack(['a' => 2, 'b' => 1], 4);

		$this->assertCount(4, $shards);
		$this->assertSame(['a'], $shards[0]);
		$this->assertSame(['b'], $shards[1]);
		$this->assertSame([], $shards[2]);
		$this->assertSame([], $shards[3]);
	}

	#[Test]
	#[TestDox('pack rejects a shard count below one')]
	#[Group('mantle2/util')]
	public function testPackRejectsZeroShards()
	{
		$this->expectException(RuntimeException::class);
		ShardPlanner::pack(['a' => 1], 0);
	}

	#[Test]
	#[TestDox('fileFor maps a test class onto its psr-4 path and rejects unknown ones')]
	#[Group('mantle2/util')]
	public function testFileFor()
	{
		$planner = new ShardPlanner($this->repoRoot);

		$this->assertSame(
			'tests/src/Unit/ShardPlannerUnitTest.php',
			$planner->fileFor(self::class),
		);

		$this->expectException(RuntimeException::class);
		$planner->fileFor('Drupal\Tests\mantle2\Unit\NoSuchTest');
	}

	#[Test]
	#[TestDox('fileFor refuses a class outside the mantle2 test namespace')]
	#[Group('mantle2/util')]
	public function testFileForForeignNamespace()
	{
		$planner = new ShardPlanner($this->repoRoot);

		$this->expectException(RuntimeException::class);
		$planner->fileFor('Drupal\Tests\other\Unit\ThingTest');
	}

	#[Test]
	#[TestDox('writeConfig clones the dist config and swaps the suites for an explicit file list')]
	#[Group('mantle2/util')]
	public function testWriteConfig()
	{
		$planner = new ShardPlanner($this->repoRoot);
		$out = tempnam(sys_get_temp_dir(), 'shard') . '.xml';

		$planner->writeConfig($out, 'Integration', [
			'tests/src/Integration/SmokeTest.php',
			'tests/src/Integration/CommandsTest.php',
		]);

		$document = new DOMDocument();
		$document->load($out);
		unlink($out);

		$root = $document->documentElement;
		$this->assertSame('tests/bootstrap.php', $root->getAttribute('bootstrap'));

		$suites = $root->getElementsByTagName('testsuite');
		$this->assertCount(1, $suites);
		$this->assertSame('Integration', $suites->item(0)->getAttribute('name'));

		$files = [];
		foreach ($suites->item(0)->getElementsByTagName('file') as $file) {
			$files[] = $file->textContent;
		}
		$this->assertSame(
			['tests/src/Integration/SmokeTest.php', 'tests/src/Integration/CommandsTest.php'],
			$files,
		);

		// the suite list is replaced, everything else the runner needs is carried over
		$this->assertCount(
			0,
			$suites->item(0)->getElementsByTagName('directory'),
			'directory-based suite replaced by files',
		);
		$this->assertCount(1, $root->getElementsByTagName('source'));
		$this->assertCount(1, $root->getElementsByTagName('php'));
		$this->assertSame(
			'src',
			$root
				->getElementsByTagName('source')
				->item(0)
				->getElementsByTagName('directory')
				->item(0)->textContent,
		);
	}

	#[Test]
	#[TestDox('writeConfig reports an unwritable destination instead of failing silently')]
	#[Group('mantle2/util')]
	public function testWriteConfigUnwritable()
	{
		$planner = new ShardPlanner($this->repoRoot);

		$this->expectException(RuntimeException::class);
		$planner->writeConfig('/nonexistent-directory/shard.xml', 'Integration', []);
	}
}
