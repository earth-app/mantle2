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
 *   1. drop an autoload.php shim into vendor/drupal (KernelTestBase requires it),
 *   2. symlink the mantle2 module and every contrib Drupal module package into
 *      vendor/drupal/modules so ExtensionDiscovery can find them,
 *   3. register core's test-suite PSR-4 namespaces (Drupal\Tests, Drupal\KernelTests, ...).
 * All steps are idempotent so the setup self-heals after composer install wipes vendor.
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$drupalRoot = $repoRoot . '/vendor/drupal';
$coreTestsDir = $drupalRoot . '/core/tests';

if (!is_dir($drupalRoot . '/core')) {
	fwrite(STDERR, "drupal/core not installed at $drupalRoot/core; run composer install\n");
	exit(1);
}

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

$autoloadShim = $drupalRoot . '/autoload.php';
if (!file_exists($autoloadShim)) {
	file_put_contents($autoloadShim, "<?php\n\nreturn require __DIR__ . '/../autoload.php';\n");
}

$modulesDir = $drupalRoot . '/modules';
if (!is_dir($modulesDir)) {
	mkdir($modulesDir, 0777, true);
}

function mantle2_link_module(string $target, string $link): void
{
	if (is_link($link) || file_exists($link)) {
		return;
	}
	@symlink($target, $link);
}

// mantle2 itself (module root is the repo root)
mantle2_link_module($repoRoot, $modulesDir . '/mantle2');

// every contrib Drupal package that ships a module info.yml at its package root
foreach (glob($drupalRoot . '/*', GLOB_ONLYDIR) as $pkgDir) {
	$name = basename($pkgDir);
	if (in_array($name, ['core', 'modules', 'profiles', 'themes'], true)) {
		continue;
	}
	$info = $pkgDir . '/' . $name . '.info.yml';
	if (file_exists($info)) {
		mantle2_link_module($pkgDir, $modulesDir . '/' . $name);
		// contrib module Drupal\<name>\ namespaces are registered by Drupal at kernel
		// boot, not by composer; register them here so non-kernel unit tests can mock them
		if (is_dir($pkgDir . '/src')) {
			$loader->addPsr4('Drupal\\' . $name . '\\', $pkgDir . '/src');
		}
		if (is_dir($pkgDir . '/tests/src')) {
			$loader->addPsr4('Drupal\\Tests\\' . $name . '\\', $pkgDir . '/tests/src');
		}
	}
}

setlocale(LC_ALL, 'C.UTF-8', 'C');
mb_internal_encoding('utf-8');
date_default_timezone_set('America/Chicago');
