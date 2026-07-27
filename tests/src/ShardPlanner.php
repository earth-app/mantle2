<?php

namespace Drupal\Tests\mantle2;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Splits a PHPUnit test suite across parallel CI jobs.
 *
 * The inventory comes from `phpunit --list-tests`, so data-provider rows are
 * counted exactly and the plan self-maintains as tests are added or removed.
 * Classes are bin-packed longest-first, which keeps the slowest shard within a
 * few tests of the average. The plan is a pure function of the inventory, so
 * every shard job derives the same assignment without coordinating.
 */
class ShardPlanner
{
	private const TEST_NAMESPACE = 'Drupal\\Tests\\mantle2\\';
	private const TEST_ROOT = 'tests/src/';

	public function __construct(private readonly string $repoRoot) {}

	/**
	 * Counts the tests each class in a suite contributes.
	 *
	 * @return array<string,int> class => test count
	 */
	public function inventory(string $suite): array
	{
		$command = sprintf(
			'%s --configuration %s --testsuite %s --list-tests',
			escapeshellarg($this->repoRoot . '/vendor/bin/phpunit'),
			escapeshellarg($this->repoRoot . '/phpunit.xml.dist'),
			escapeshellarg($suite),
		);

		$lines = [];
		$status = 0;
		exec($command . ' 2>/dev/null', $lines, $status);
		if ($status !== 0) {
			throw new RuntimeException(
				"phpunit --list-tests failed for suite $suite (exit $status)",
			);
		}

		return self::countByClass($lines);
	}

	/**
	 * Parses `phpunit --list-tests` output into per-class test counts.
	 *
	 * @param list<string> $lines
	 * @return array<string,int>
	 */
	public static function countByClass(array $lines): array
	{
		$counts = [];
		foreach ($lines as $line) {
			if (!str_starts_with($line, ' - ')) {
				continue;
			}
			$separator = strpos($line, '::', 3);
			if ($separator === false) {
				continue;
			}
			$class = substr($line, 3, $separator - 3);
			$counts[$class] = ($counts[$class] ?? 0) + 1;
		}
		return $counts;
	}

	/**
	 * Assigns classes to shards, heaviest first onto the lightest shard.
	 *
	 * @param array<string,int> $counts
	 * @return list<list<string>> one class list per shard, in shard order
	 */
	public static function pack(array $counts, int $total): array
	{
		if ($total < 1) {
			throw new RuntimeException('shard count must be at least 1');
		}

		// heaviest first; class name breaks ties so every job packs identically
		uksort($counts, fn(string $a, string $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

		$shards = array_fill(0, $total, []);
		$weights = array_fill(0, $total, 0);

		foreach ($counts as $class => $count) {
			$target = (int) array_keys($weights, min($weights))[0];
			$shards[$target][] = $class;
			$weights[$target] += $count;
		}

		return $shards;
	}

	/**
	 * Resolves a test class to its repo-relative file path.
	 */
	public function fileFor(string $class): string
	{
		if (!str_starts_with($class, self::TEST_NAMESPACE)) {
			throw new RuntimeException("unexpected test namespace: $class");
		}
		$relative =
			self::TEST_ROOT .
			str_replace('\\', '/', substr($class, strlen(self::TEST_NAMESPACE))) .
			'.php';
		if (!is_file($this->repoRoot . '/' . $relative)) {
			throw new RuntimeException("cannot resolve $class to a file (tried $relative)");
		}
		return $relative;
	}

	/**
	 * Clones phpunit.xml.dist with its suites replaced by an explicit file list.
	 *
	 * Written next to the dist config so every relative path in it (bootstrap,
	 * cache directory, coverage source) keeps resolving.
	 *
	 * @param list<string> $files
	 */
	public function writeConfig(string $out, string $suite, array $files): void
	{
		$document = new DOMDocument();
		$document->preserveWhiteSpace = false;
		$document->formatOutput = true;
		if (!@$document->load($this->repoRoot . '/phpunit.xml.dist')) {
			throw new RuntimeException('could not read phpunit.xml.dist');
		}

		$root = $document->documentElement;
		foreach (iterator_to_array($root->getElementsByTagName('testsuites')) as $existing) {
			$root->removeChild($existing);
		}

		$suites = $document->createElement('testsuites');
		$element = $document->createElement('testsuite');
		$element->setAttribute('name', $suite);
		foreach ($files as $file) {
			$element->appendChild($document->createElement('file', $file));
		}
		$suites->appendChild($element);

		$source = $root->getElementsByTagName('source')->item(0);
		if ($source instanceof DOMElement) {
			$root->insertBefore($suites, $source);
		} else {
			$root->appendChild($suites);
		}

		if (@$document->save($out) === false) {
			throw new RuntimeException("could not write $out");
		}
	}
}
