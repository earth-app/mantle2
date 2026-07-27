<?php

/**
 * @file
 * CLI front end for CloverMerger: turns the sharded Clover reports produced by
 * a matrix job into one coverage summary.
 *
 * Usage:
 *   php tests/coverage-summary.php --in=coverage/shards [--out=coverage/integration.coverage.txt]
 */

declare(strict_types=1);

use Drupal\Tests\mantle2\CloverMerger;

// required directly so the summary job does not need a composer install
$repoRoot = dirname(__DIR__);
require_once __DIR__ . '/src/CloverMerger.php';

$options = getopt('', ['in:', 'out::']);
if (!isset($options['in'])) {
	fwrite(STDERR, "missing --in\n");
	exit(1);
}

try {
	$reports = CloverMerger::discover((string) $options['in']);
	$parsed = array_map(
		fn(string $file) => CloverMerger::parse((string) file_get_contents($file)),
		$reports,
	);

	$summary = CloverMerger::summarize(CloverMerger::merge($parsed));
	$rendered = CloverMerger::render($summary, count($reports), $repoRoot);

	if (isset($options['out'])) {
		file_put_contents((string) $options['out'], $rendered);
	}
	echo $rendered;
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
