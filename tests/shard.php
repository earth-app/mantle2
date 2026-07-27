<?php

/**
 * @file
 * CLI front end for ShardPlanner: writes the PHPUnit config for one CI shard.
 *
 * Usage:
 *   php tests/shard.php --suite=Integration --index=1 --total=8 [--out=phpunit.shard.xml]
 *   php tests/shard.php --suite=Integration --total=8 --plan
 */

declare(strict_types=1);

use Drupal\Tests\mantle2\ShardPlanner;

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/vendor/autoload.php';

$options = getopt('', ['suite:', 'index:', 'total:', 'out::', 'plan']);
$planOnly = isset($options['plan']);

foreach ($planOnly ? ['suite', 'total'] : ['suite', 'index', 'total'] as $required) {
	if (!isset($options[$required])) {
		fwrite(STDERR, "missing --$required\n");
		exit(1);
	}
}

$suite = (string) $options['suite'];
$total = (int) $options['total'];
$index = (int) ($options['index'] ?? 1);
$out = (string) ($options['out'] ?? $repoRoot . '/phpunit.shard.xml');

if ($total < 1 || $index < 1 || $index > $total) {
	fwrite(STDERR, "--index must be between 1 and --total\n");
	exit(1);
}

try {
	$planner = new ShardPlanner($repoRoot);
	$counts = $planner->inventory($suite);
	if (!$counts) {
		throw new RuntimeException("no tests found in suite $suite");
	}
	$shards = ShardPlanner::pack($counts, $total);

	if ($planOnly) {
		foreach ($shards as $shard => $classes) {
			printf(
				"shard %d/%d: %3d classes %5d tests\n",
				$shard + 1,
				$total,
				count($classes),
				array_sum(array_map(fn($class) => $counts[$class], $classes)),
			);
		}
		exit(0);
	}

	$classes = $shards[$index - 1];
	if (!$classes) {
		throw new RuntimeException(
			sprintf(
				'shard %d/%d is empty; suite %s only has %d classes',
				$index,
				$total,
				$suite,
				count($counts),
			),
		);
	}

	$planner->writeConfig($out, $suite, array_map($planner->fileFor(...), $classes));

	printf(
		"shard %d/%d -> %d classes, %d tests, %s\n",
		$index,
		$total,
		count($classes),
		array_sum(array_map(fn($class) => $counts[$class], $classes)),
		$out,
	);
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
