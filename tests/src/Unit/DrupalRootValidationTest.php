<?php

namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Guards the vendor/drupal pseudo-root that both PHPUnit and PHPStan depend on.
 *
 * ExtensionDiscovery only recurses into modules/, profiles/ and themes/ from a search
 * path root, so nothing in this module-only repo is discoverable until
 * tests/drupal-root.php links it into vendor/drupal/modules. That setup used to live
 * inside tests/bootstrap.php, so a fresh CI checkout ran PHPStan against zero
 * discovered extensions and failed with loadIncludes.moduleNotFound plus a
 * function.notFound for every mantle2_*() the include defines.
 */
class DrupalRootValidationTest extends TestCase
{
	private static string $repoRoot;
	private static string $drupalRoot;

	public static function setUpBeforeClass(): void
	{
		self::$repoRoot = dirname(__DIR__, 3);
		self::$drupalRoot = self::$repoRoot . '/vendor/drupal';
	}

	private static function contents(string $relative): string
	{
		$path = self::$repoRoot . '/' . $relative;
		if (!file_exists($path)) {
			self::fail("File not found: $relative");
		}

		return file_get_contents($path);
	}

	/**
	 * @return string[] the list entries directly under $key in an indentation-based config
	 */
	private static function listUnder(string $source, string $key): array
	{
		$lines = explode("\n", $source);
		$items = [];
		$indent = null;

		foreach ($lines as $line) {
			if ($indent === null) {
				if (preg_match('/^(\s*)' . preg_quote($key, '/') . ':\s*$/', $line, $m) === 1) {
					$indent = strlen($m[1]);
				}
				continue;
			}

			if (trim($line) === '' || str_starts_with(trim($line), '#')) {
				continue;
			}
			if (strlen($line) - strlen(ltrim($line)) <= $indent) {
				break;
			}
			if (str_starts_with(trim($line), '- ')) {
				$items[] = trim(substr(trim($line), 2));
			}
		}

		return $items;
	}

	#[Test]
	#[TestDox('mantle2 is linked into vendor/drupal/modules and resolves to the repo root')]
	#[Group('mantle2/tooling')]
	public function testModuleIsLinked(): void
	{
		$link = self::$drupalRoot . '/modules/mantle2';

		$this->assertTrue(
			is_link($link) || is_dir($link),
			'tests/drupal-root.php must link the module; without it ExtensionDiscovery finds ' .
				'no mantle2 and loadInclude("mantle2", "install") cannot resolve.',
		);
		$this->assertSame(realpath(self::$repoRoot), realpath($link));
		$this->assertFileExists($link . '/mantle2.info.yml');
	}

	#[Test]
	#[TestDox('Every contrib package with a root info.yml is linked into vendor/drupal/modules')]
	#[Group('mantle2/tooling')]
	public function testContribPackagesAreLinked(): void
	{
		$linked = 0;

		foreach (glob(self::$drupalRoot . '/*', GLOB_ONLYDIR) ?: [] as $pkgDir) {
			$name = basename($pkgDir);
			if (in_array($name, ['core', 'modules', 'profiles', 'themes'], true)) {
				continue;
			}
			if (!file_exists("$pkgDir/$name.info.yml")) {
				continue;
			}

			$link = self::$drupalRoot . '/modules/' . $name;
			$this->assertTrue(is_link($link) || is_dir($link), "$name is not linked");
			$this->assertSame(realpath($pkgDir), realpath($link));
			$linked++;
		}

		$this->assertGreaterThan(0, $linked, 'No contrib modules were discovered under vendor');
	}

	#[Test]
	#[TestDox('The autoload.php shim KernelTestBase requires sits next to core')]
	#[Group('mantle2/tooling')]
	public function testAutoloadShimExists(): void
	{
		$this->assertFileExists(self::$drupalRoot . '/autoload.php');
	}

	#[Test]
	#[TestDox('phpstan.neon bootstraps tests/drupal-root.php before analysis')]
	#[Group('mantle2/tooling')]
	public function testPhpstanBootstrapsTheDrupalRoot(): void
	{
		$this->assertContains(
			'tests/drupal-root.php',
			self::listUnder(self::contents('phpstan.neon'), 'bootstrapFiles'),
			'PHPStan discovers no Drupal extensions on a fresh checkout without this bootstrap.',
		);
	}

	#[Test]
	#[TestDox('The analyze script links the Drupal root before running phpstan')]
	#[Group('mantle2/tooling')]
	public function testAnalyzeScriptLinksFirst(): void
	{
		$scripts = json_decode(self::contents('package.json'), true)['scripts'] ?? [];
		$this->assertArrayHasKey('analyze', $scripts);

		$link = strpos($scripts['analyze'], 'tests/drupal-root.php');
		$analyze = strpos($scripts['analyze'], 'phpstan');

		$this->assertNotFalse($link, 'CI runs `bun run analyze` on a vendor tree with no links');
		$this->assertNotFalse($analyze);
		$this->assertLessThan($analyze, $link);
	}

	#[Test]
	#[TestDox('The PHPUnit bootstrap delegates linking instead of duplicating it')]
	#[Group('mantle2/tooling')]
	public function testBootstrapDelegates(): void
	{
		$bootstrap = self::contents('tests/bootstrap.php');

		$this->assertStringContainsString("require __DIR__ . '/drupal-root.php'", $bootstrap);
		$this->assertStringNotContainsString(
			'symlink(',
			$bootstrap,
			'Linking lives in tests/drupal-root.php so PHPUnit and PHPStan cannot drift apart.',
		);
	}
}
