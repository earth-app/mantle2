<?php

/**
 * @file
 * Custom PHPUnit bootstrap for running Drupal UnitTestCase / KernelTestBase in a
 * module-only repo where drupal/core is a Composer vendor package
 * (vendor/drupal/core) rather than scaffolded into web/core.
 *
 * Drupal's own core/tests/bootstrap.php and KernelTestBase assume the app root is
 * two levels above core (i.e. web/), where an autoload.php and a modules/ tree
 * live. Here the effective root is vendor/drupal, so we:
 *   1. build that root via tests/drupal-root.php (shared with phpstan.neon),
 *   2. register core's test-suite PSR-4 namespaces (Drupal\Tests, Drupal\KernelTests, ...),
 *   3. register each contrib module's own PSR-4 namespace.
 * All steps are idempotent so the setup self-heals after composer install wipes vendor.
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$coreTestsDir = $repoRoot . '/vendor/drupal/core/tests';

if (!defined('PHPUNIT_COMPOSER_INSTALL')) {
	define('PHPUNIT_COMPOSER_INSTALL', $repoRoot . '/vendor/autoload.php');
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $repoRoot . '/vendor/autoload.php';

// core's test-suite namespaces are not in any package autoload; add them here
$loader->add('Drupal\\BuildTests', $coreTestsDir);
$loader->add('Drupal\\Tests', $coreTestsDir);
$loader->add('Drupal\\TestSite', $coreTestsDir);
$loader->add('Drupal\\KernelTests', $coreTestsDir);
$loader->add('Drupal\\FunctionalTests', $coreTestsDir);
$loader->add('Drupal\\FunctionalJavascriptTests', $coreTestsDir);
$loader->add('Drupal\\TestTools', $coreTestsDir);

// contrib module Drupal\<name>\ namespaces are registered by Drupal at kernel boot,
// not by composer; register them here so non-kernel unit tests can mock them
foreach (require __DIR__ . '/drupal-root.php' as $name => $pkgDir) {
	if (is_dir($pkgDir . '/src')) {
		$loader->addPsr4('Drupal\\' . $name . '\\', $pkgDir . '/src');
	}
	if (is_dir($pkgDir . '/tests/src')) {
		$loader->addPsr4('Drupal\\Tests\\' . $name . '\\', $pkgDir . '/tests/src');
	}
}

// paratest workers share the configured database; a file-backed one has to be split per
// worker, an in-memory one is already private to the process
$testToken = getenv('TEST_TOKEN');
$simpletestDb = (string) getenv('SIMPLETEST_DB');
if ($testToken !== false && $testToken !== '' && !str_contains($simpletestDb, ':memory:')) {
	putenv('SIMPLETEST_DB=sqlite://localhost//tmp/mantle2-test-' . $testToken . '.sqlite');
}

setlocale(LC_ALL, 'C.UTF-8', 'C');
mb_internal_encoding('utf-8');
date_default_timezone_set('America/Chicago');
