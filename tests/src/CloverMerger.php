<?php

namespace Drupal\Tests\mantle2;

use RuntimeException;
use SimpleXMLElement;

/**
 * Folds the Clover reports produced by sharded CI jobs back into one summary.
 *
 * Each shard only executes part of the suite, so its report understates
 * coverage for every file the other shards touched. Coverage is a union: a line
 * is covered if any shard hit it, which is a max over the per-shard hit counts.
 *
 * Only line and method figures are derived. Clover does not record which lines
 * belong to which class, so class-level percentages cannot be recomputed from a
 * merge and are deliberately not reported.
 */
class CloverMerger
{
	/**
	 * Reads one Clover report into file => line number => [type, count].
	 *
	 * @return array<string,array<int,array{type:string,count:int}>>
	 */
	public static function parse(string $xml): array
	{
		$document = @simplexml_load_string($xml);
		if (!($document instanceof SimpleXMLElement)) {
			throw new RuntimeException('could not parse clover report');
		}

		$files = [];
		foreach ($document->xpath('//file') ?: [] as $file) {
			$name = (string) $file['name'];
			if ($name === '') {
				continue;
			}
			$lines = $files[$name] ?? [];
			foreach ($file->line as $line) {
				$number = (int) $line['num'];
				$type = (string) $line['type'];
				$count = (int) $line['count'];
				$lines[$number] = [
					'type' => $type,
					'count' => max($count, $lines[$number]['count'] ?? 0),
				];
			}
			ksort($lines);
			$files[$name] = $lines;
		}

		ksort($files);
		return $files;
	}

	/**
	 * Unions parsed reports; a line is covered when any shard executed it.
	 *
	 * @param list<array<string,array<int,array{type:string,count:int}>>> $reports
	 * @return array<string,array<int,array{type:string,count:int}>>
	 */
	public static function merge(array $reports): array
	{
		$merged = [];
		foreach ($reports as $report) {
			foreach ($report as $file => $lines) {
				$merged[$file] ??= [];
				foreach ($lines as $number => $line) {
					$existing = $merged[$file][$number] ?? null;
					$merged[$file][$number] = [
						'type' => $line['type'],
						'count' => max($line['count'], $existing['count'] ?? 0),
					];
				}
				ksort($merged[$file]);
			}
		}

		ksort($merged);
		return $merged;
	}

	/**
	 * Counts covered/total lines and methods overall and per file.
	 *
	 * @param array<string,array<int,array{type:string,count:int}>> $merged
	 * @return array{lines:array{covered:int,total:int},methods:array{covered:int,total:int},files:array<string,array{covered:int,total:int}>}
	 */
	public static function summarize(array $merged): array
	{
		$summary = [
			'lines' => ['covered' => 0, 'total' => 0],
			'methods' => ['covered' => 0, 'total' => 0],
			'files' => [],
		];

		foreach ($merged as $file => $lines) {
			$covered = 0;
			foreach ($lines as $line) {
				$bucket = $line['type'] === 'method' ? 'methods' : 'lines';
				$summary[$bucket]['total']++;
				if ($line['count'] > 0) {
					$summary[$bucket]['covered']++;
					$covered++;
				}
			}
			$summary['files'][$file] = ['covered' => $covered, 'total' => count($lines)];
		}

		return $summary;
	}

	/**
	 * Renders the summary as the plain-text block CI drops into the job summary.
	 *
	 * @param array{lines:array{covered:int,total:int},methods:array{covered:int,total:int},files:array<string,array{covered:int,total:int}>} $summary
	 */
	public static function render(array $summary, int $shards, string $root = ''): string
	{
		$lines = [
			sprintf('Merged Integration Coverage (%d shards)', $shards),
			'',
			sprintf(
				'  Methods: %6.2f%% (%d/%d)',
				self::percent($summary['methods']),
				$summary['methods']['covered'],
				$summary['methods']['total'],
			),
			sprintf(
				'  Lines:   %6.2f%% (%d/%d)',
				self::percent($summary['lines']),
				$summary['lines']['covered'],
				$summary['lines']['total'],
			),
			'',
		];

		$worst = $summary['files'];
		uasort(
			$worst,
			fn(array $a, array $b) => [self::percent($a), -$a['total']] <=> [
				self::percent($b),
				-$b['total'],
			],
		);

		$lines[] = 'Least covered files:';
		foreach (array_slice($worst, 0, 15, true) as $file => $counts) {
			$lines[] = sprintf(
				'  %6.2f%% (%4d/%4d)  %s',
				self::percent($counts),
				$counts['covered'],
				$counts['total'],
				$root !== '' && str_starts_with($file, $root)
					? ltrim(substr($file, strlen($root)), '/')
					: $file,
			);
		}

		return implode("\n", $lines) . "\n";
	}

	/**
	 * @param array{covered:int,total:int} $counts
	 */
	private static function percent(array $counts): float
	{
		return $counts['total'] === 0 ? 100.0 : ($counts['covered'] / $counts['total']) * 100;
	}

	/**
	 * Lists the clover reports under a directory in a stable order.
	 *
	 * @return list<string>
	 */
	public static function discover(string $directory): array
	{
		$found = glob(rtrim($directory, '/') . '/**/*.clover.xml') ?: [];
		$found = array_merge($found, glob(rtrim($directory, '/') . '/*.clover.xml') ?: []);
		$found = array_values(array_unique($found));
		sort($found);

		if (!$found) {
			throw new RuntimeException("no *.clover.xml reports under $directory");
		}

		return $found;
	}
}
