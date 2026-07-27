<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\Tests\mantle2\CloverMerger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CloverMergerUnitTest extends TestCase
{
	private static function clover(array $files): string
	{
		$xml =
			'<?xml version="1.0" encoding="UTF-8"?><coverage generated="1"><project timestamp="1">';
		foreach ($files as $name => $lines) {
			$xml .= '<file name="' . $name . '">';
			foreach ($lines as $number => [$type, $count]) {
				$xml .= sprintf('<line num="%d" type="%s" count="%d"/>', $number, $type, $count);
			}
			$xml .= '</file>';
		}
		return $xml . '</project></coverage>';
	}

	#[Test]
	#[TestDox('parse reads every file, line number, type, and hit count')]
	#[Group('mantle2/util')]
	public function testParse()
	{
		$parsed = CloverMerger::parse(
			self::clover([
				'/repo/src/B.php' => [7 => ['stmt', 2]],
				'/repo/src/A.php' => [3 => ['method', 0], 4 => ['stmt', 1]],
			]),
		);

		$this->assertSame(['/repo/src/A.php', '/repo/src/B.php'], array_keys($parsed));
		$this->assertSame(['type' => 'method', 'count' => 0], $parsed['/repo/src/A.php'][3]);
		$this->assertSame(['type' => 'stmt', 'count' => 1], $parsed['/repo/src/A.php'][4]);
		$this->assertSame(['type' => 'stmt', 'count' => 2], $parsed['/repo/src/B.php'][7]);
	}

	#[Test]
	#[TestDox('parse rejects a report that is not valid xml')]
	#[Group('mantle2/util')]
	public function testParseRejectsGarbage()
	{
		$this->expectException(RuntimeException::class);
		CloverMerger::parse('<coverage><project>');
	}

	#[Test]
	#[TestDox('parse folds a file that clover listed more than once')]
	#[Group('mantle2/util')]
	public function testParseFoldsRepeatedFiles()
	{
		$xml =
			'<?xml version="1.0"?><coverage><project>' .
			'<file name="/repo/src/A.php"><line num="1" type="stmt" count="0"/></file>' .
			'<file name="/repo/src/A.php"><line num="1" type="stmt" count="4"/></file>' .
			'</project></coverage>';

		$parsed = CloverMerger::parse($xml);
		$this->assertSame(4, $parsed['/repo/src/A.php'][1]['count']);
	}

	#[Test]
	#[TestDox('merge unions shard reports so a line covered anywhere counts as covered')]
	#[Group('mantle2/util')]
	public function testMergeUnionsCoverage()
	{
		$shardOne = CloverMerger::parse(
			self::clover(['/repo/src/A.php' => [1 => ['stmt', 3], 2 => ['stmt', 0]]]),
		);
		$shardTwo = CloverMerger::parse(
			self::clover([
				'/repo/src/A.php' => [1 => ['stmt', 0], 2 => ['stmt', 5]],
				'/repo/src/B.php' => [9 => ['stmt', 1]],
			]),
		);

		$merged = CloverMerger::merge([$shardOne, $shardTwo]);

		$this->assertSame(3, $merged['/repo/src/A.php'][1]['count']);
		$this->assertSame(5, $merged['/repo/src/A.php'][2]['count']);
		$this->assertSame(1, $merged['/repo/src/B.php'][9]['count']);
		$this->assertSame(['/repo/src/A.php', '/repo/src/B.php'], array_keys($merged));
	}

	#[Test]
	#[TestDox('merge of no reports is empty rather than an error')]
	#[Group('mantle2/util')]
	public function testMergeOfNothing()
	{
		$this->assertSame([], CloverMerger::merge([]));
		$this->assertSame(
			[
				'lines' => ['covered' => 0, 'total' => 0],
				'methods' => ['covered' => 0, 'total' => 0],
				'files' => [],
			],
			CloverMerger::summarize([]),
		);
	}

	#[Test]
	#[TestDox('summarize separates method lines from statement lines')]
	#[Group('mantle2/util')]
	public function testSummarize()
	{
		$merged = CloverMerger::merge([
			CloverMerger::parse(
				self::clover([
					'/repo/src/A.php' => [
						1 => ['method', 1],
						2 => ['stmt', 1],
						3 => ['stmt', 0],
						4 => ['method', 0],
					],
					'/repo/src/B.php' => [1 => ['stmt', 2]],
				]),
			),
		]);

		$summary = CloverMerger::summarize($merged);

		$this->assertSame(['covered' => 1, 'total' => 2], $summary['methods']);
		$this->assertSame(['covered' => 2, 'total' => 3], $summary['lines']);
		$this->assertSame(['covered' => 2, 'total' => 4], $summary['files']['/repo/src/A.php']);
		$this->assertSame(['covered' => 1, 'total' => 1], $summary['files']['/repo/src/B.php']);
	}

	#[Test]
	#[TestDox('render reports the merged totals and the least covered files first')]
	#[Group('mantle2/util')]
	public function testRender()
	{
		$summary = CloverMerger::summarize(
			CloverMerger::merge([
				CloverMerger::parse(
					self::clover([
						'/repo/src/Covered.php' => [1 => ['stmt', 1], 2 => ['stmt', 1]],
						'/repo/src/Bare.php' => [1 => ['stmt', 0], 2 => ['stmt', 0]],
					]),
				),
			]),
		);

		$rendered = CloverMerger::render($summary, 8, '/repo');

		$this->assertStringContainsString('Merged Integration Coverage (8 shards)', $rendered);
		$this->assertStringContainsString('Lines:    50.00% (2/4)', $rendered);
		$this->assertStringContainsString('Methods: 100.00% (0/0)', $rendered);
		// repo root stripped, worst file listed before the fully covered one
		$this->assertStringContainsString('src/Bare.php', $rendered);
		$this->assertLessThan(
			strpos($rendered, 'src/Covered.php'),
			strpos($rendered, 'src/Bare.php'),
		);
	}

	#[Test]
	#[TestDox('discover finds shard reports at any depth in a stable order')]
	#[Group('mantle2/util')]
	public function testDiscover()
	{
		$root = sys_get_temp_dir() . '/clover-' . bin2hex(random_bytes(4));
		mkdir($root . '/shard-2', 0777, true);
		mkdir($root . '/shard-1', 0777, true);
		file_put_contents($root . '/shard-1/integration.clover.xml', '<coverage/>');
		file_put_contents($root . '/shard-2/integration.clover.xml', '<coverage/>');
		file_put_contents($root . '/top.clover.xml', '<coverage/>');
		file_put_contents($root . '/shard-1/integration.junit.xml', '<testsuites/>');

		$found = CloverMerger::discover($root);

		$this->assertSame(
			[
				$root . '/shard-1/integration.clover.xml',
				$root . '/shard-2/integration.clover.xml',
				$root . '/top.clover.xml',
			],
			$found,
		);

		array_map('unlink', glob($root . '/*/*') ?: []);
		array_map('rmdir', glob($root . '/*', GLOB_ONLYDIR) ?: []);
		array_map('unlink', glob($root . '/*') ?: []);
		rmdir($root);
	}

	#[Test]
	#[TestDox('discover fails loudly when a shard produced no report')]
	#[Group('mantle2/util')]
	public function testDiscoverEmpty()
	{
		$root = sys_get_temp_dir() . '/clover-' . bin2hex(random_bytes(4));
		mkdir($root);

		try {
			$this->expectException(RuntimeException::class);
			CloverMerger::discover($root);
		} finally {
			rmdir($root);
		}
	}
}
