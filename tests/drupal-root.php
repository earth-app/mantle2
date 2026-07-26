<?php

/**
 * @file
 * Makes vendor/drupal usable as a Drupal app root in this module-only repo, where
 * drupal/core is a Composer package instead of a scaffolded web/core.
 *
 * ExtensionDiscovery only recurses into modules/, profiles/ and themes/ from a search
 * path root, so mantle2 (repo root) and the contrib packages (vendor/drupal/<name>)
 * are invisible until they are linked into vendor/drupal/modules. Also drops the
 * autoload.php shim KernelTestBase requires next to core.
 *
 * Loaded by tests/bootstrap.php and by phpstan.neon's bootstrapFiles so PHPUnit and
 * PHPStan discover the same extensions; without it PHPStan cannot resolve
 * loadInclude('mantle2', ...) or any contrib service. Idempotent, and safe to run
 * standalone (`php tests/drupal-root.php`) after composer install wipes vendor.
 *
 * @return array<string, string> linked contrib package directories keyed by module name
 */

declare(strict_types=1);

return (static function (): array {
	$repoRoot = dirname(__DIR__);
	$drupalRoot = $repoRoot . '/vendor/drupal';

	if (!is_dir($drupalRoot . '/core')) {
		fwrite(STDERR, "drupal/core not installed at $drupalRoot/core; run composer install\n");
		exit(1);
	}

	$autoloadShim = $drupalRoot . '/autoload.php';
	if (!file_exists($autoloadShim)) {
		file_put_contents($autoloadShim, "<?php\n\nreturn require __DIR__ . '/../autoload.php';\n");
	}

	$modulesDir = $drupalRoot . '/modules';
	if (!is_dir($modulesDir)) {
		mkdir($modulesDir, 0777, true);
	}

	$link = static function (string $target, string $path): void {
		if (is_link($path) || file_exists($path)) {
			return;
		}
		@symlink($target, $path);
	};

	// mantle2 itself (module root is the repo root)
	$link($repoRoot, $modulesDir . '/mantle2');

	$contrib = [];
	foreach (glob($drupalRoot . '/*', GLOB_ONLYDIR) ?: [] as $pkgDir) {
		$name = basename($pkgDir);
		if (in_array($name, ['core', 'modules', 'profiles', 'themes'], true)) {
			continue;
		}
		// only packages that ship a module info.yml at their package root
		if (!file_exists($pkgDir . '/' . $name . '.info.yml')) {
			continue;
		}

		$link($pkgDir, $modulesDir . '/' . $name);
		$contrib[$name] = $pkgDir;
	}

	return $contrib;
})();
