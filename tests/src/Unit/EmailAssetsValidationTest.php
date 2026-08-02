<?php

namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Guards scripts/build-email-assets.sh against clobbering the marketing originals.
 *
 * The R2 bucket holds the only copy of the launch and offer artwork, and the script runs with a
 * token that can overwrite or delete any key. Derivatives must therefore land under a separate
 * marketing/email/ prefix. Everything here is parsed out of the script text, so the gate lane
 * stays hermetic and never downloads an asset.
 */
class EmailAssetsValidationTest extends TestCase
{
	private const SCRIPT = 'scripts/build-email-assets.sh';
	private const SOURCE_PREFIX = 'marketing/';
	private const UPLOAD_PREFIX = 'marketing/email/';
	private const MOTD_COUNT = 14;

	private static string $repoRoot;
	private static string $scriptPath;
	private static string $script;

	public static function setUpBeforeClass(): void
	{
		self::$repoRoot = dirname(__DIR__, 3);
		self::$scriptPath = self::$repoRoot . '/' . self::SCRIPT;

		if (!file_exists(self::$scriptPath)) {
			self::fail('Script not found: ' . self::$scriptPath);
		}

		self::$script = file_get_contents(self::$scriptPath);
	}

	private static function script(): string
	{
		if (!isset(self::$script)) {
			self::setUpBeforeClass();
		}

		return self::$script;
	}

	/**
	 * @return string[] the entries of a bash array literal, e.g. `NAME=(\n\ta\n\tb\n)`
	 */
	private static function bashArray(string $name): array
	{
		$pattern = '/^' . preg_quote($name, '/') . '=\((.*?)\)/ms';
		if (preg_match($pattern, self::script(), $m) !== 1) {
			self::fail("Array $name=() not found in " . self::SCRIPT);
		}

		return preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
	}

	private static function bashScalar(string $name): string
	{
		$pattern = '/^' . preg_quote($name, '/') . "='([^']*)'/m";
		if (preg_match($pattern, self::script(), $m) !== 1) {
			self::fail("Scalar $name= not found in " . self::SCRIPT);
		}

		return $m[1];
	}

	/**
	 * @return string[] every line that invokes `wrangler r2 object put`, printed or executed
	 */
	private static function uploadLines(): array
	{
		$lines = [];
		foreach (explode("\n", self::script()) as $line) {
			if (str_contains($line, 'r2 object put')) {
				$lines[] = trim($line);
			}
		}

		return $lines;
	}

	public static function assetProvider(): array
	{
		$assets = array_merge(
			[self::bashScalar('BANNER_ASSET'), self::bashScalar('QR_ASSET')],
			self::bashArray('POST_ASSETS'),
			self::bashArray('MOTD_ASSETS'),
		);

		$data = [];
		foreach ($assets as $asset) {
			$data[$asset] = [$asset];
		}

		return $data;
	}

	public static function uploadLineProvider(): array
	{
		$data = [];
		foreach (self::uploadLines() as $index => $line) {
			$data["put_$index"] = [$line];
		}

		return $data;
	}

	public static function bannedTokenProvider(): array
	{
		return [
			'recursive delete' => [
				'rm -rf',
				'A stray rm -rf in a script that cds around is unrecoverable',
			],
			'r2 delete' => ['r2 object delete', 'This script only ever adds keys'],
			'force flag' => ['--force', '--force skips the R2 overwrite prompt'],
			'force short flag' => ['-y ', 'Same as --force for wrangler r2 object put'],
		];
	}

	#[Test]
	#[TestDox('The build script exists and is executable')]
	#[Group('mantle2/util')]
	public function testScriptIsExecutable(): void
	{
		$this->assertFileExists(self::$scriptPath);
		$this->assertTrue(
			is_executable(self::$scriptPath),
			self::SCRIPT .
				' must be executable; a chmod-less checkout breaks the documented usage.',
		);
		$this->assertStringStartsWith('#!/usr/bin/env bash', self::$script);
	}

	#[Test]
	#[TestDox('Asset $asset is a real image under the marketing prefix')]
	#[Group('mantle2/util')]
	#[DataProvider('assetProvider')]
	public function testAssetIsAnImageKey(string $asset): void
	{
		$this->assertMatchesRegularExpression(
			'/^[a-z0-9][a-z0-9_-]*\.(?:png|jpe?g|webp|gif)$/',
			$asset,
			"$asset is not a plain image filename, so it cannot be a bucket key under " .
				self::SOURCE_PREFIX,
		);
		$this->assertStringNotContainsString('/', $asset, "$asset must not escape the prefix");
		$this->assertStringContainsString(
			'"$CDN/' . self::SOURCE_PREFIX . '$name"',
			self::$script,
			'Sources must be fetched from the ' . self::SOURCE_PREFIX . ' prefix on the CDN.',
		);
	}

	#[Test]
	#[TestDox('Every bucket and CDN path the script builds stays under the marketing prefix')]
	#[Group('mantle2/util')]
	public function testEveryReferencedPathIsUnderMarketing(): void
	{
		preg_match_all(
			'#(?:cdn\.earth-app\.com|earth-app)/([^\s"\'|)]+)#',
			self::$script,
			$matches,
		);

		$this->assertNotEmpty($matches[1], 'No bucket or CDN paths were found in ' . self::SCRIPT);
		foreach ($matches[1] as $path) {
			$this->assertStringStartsWith(
				self::SOURCE_PREFIX,
				$path,
				"$path is outside " . self::SOURCE_PREFIX . ', where the email artwork lives.',
			);
		}
	}

	#[Test]
	#[TestDox('Every wrangler PUT targets the marketing/email prefix')]
	#[Group('mantle2/util')]
	#[DataProvider('uploadLineProvider')]
	public function testUploadTargetsTheEmailPrefix(string $line): void
	{
		$this->assertMatchesRegularExpression(
			'#r2 object put\s+"?earth-app/' . preg_quote(self::UPLOAD_PREFIX, '#') . '#',
			$line,
			'Every PUT must target ' .
				self::UPLOAD_PREFIX .
				' so an original can never be replaced.',
		);
	}

	#[Test]
	#[TestDox('Both the printed and the executed upload command are checked')]
	#[Group('mantle2/util')]
	public function testUploadCommandsArePresent(): void
	{
		$this->assertGreaterThanOrEqual(
			2,
			count(self::uploadLines()),
			'The script must both print the upload command and run it under --upload.',
		);
		$this->assertStringContainsString(
			'key_exists',
			self::$script,
			'An upload must probe for the key first; wrangler PUT overwrites silently.',
		);
	}

	#[Test]
	#[TestDox('The script contains no $token')]
	#[Group('mantle2/util')]
	#[DataProvider('bannedTokenProvider')]
	public function testNoDestructiveTokens(string $token, string $why): void
	{
		$this->assertStringNotContainsString($token, self::$script, $why);
	}

	#[Test]
	#[TestDox('All 14 MOTD banners are listed exactly once')]
	#[Group('mantle2/util')]
	public function testMotdListIsCompleteAndUnique(): void
	{
		$motd = self::bashArray('MOTD_ASSETS');

		$this->assertCount(
			self::MOTD_COUNT,
			$motd,
			'The bucket holds ' .
				self::MOTD_COUNT .
				' motd_* strips; a missing one ships a broken img.',
		);
		$this->assertSame(array_values(array_unique($motd)), $motd, 'Duplicate MOTD entry');

		foreach ($motd as $name) {
			$this->assertStringStartsWith('motd_', $name);
		}
	}

	#[Test]
	#[TestDox('Derivatives target a 1200px retina width and a 150 KB ceiling')]
	#[Group('mantle2/util')]
	public function testEmailBudgets(): void
	{
		$this->assertMatchesRegularExpression('/^MAX_WIDTH=1200$/m', self::$script);
		$this->assertMatchesRegularExpression(
			'/^BUDGET_BYTES=(\d+)$/m',
			self::$script,
			'The script must declare a byte budget so an oversized derivative fails the run.',
		);

		preg_match('/^BUDGET_BYTES=(\d+)$/m', self::$script, $m);
		$this->assertLessThanOrEqual(
			153600,
			(int) $m[1],
			'A mail body cannot carry more than ~150 KB per image.',
		);
	}

	#[Test]
	#[TestDox('.gitignore keeps the marketing originals and the built derivatives out of git')]
	#[Group('mantle2/util')]
	public function testGitignoreExcludesBinaryAssets(): void
	{
		$path = self::$repoRoot . '/.gitignore';
		$this->assertFileExists($path);

		$gitignore = file_get_contents($path);
		$this->assertStringContainsString(
			'data/marketing/',
			$gitignore,
			'data/marketing/ is ~4 MB of PNGs; the deliverable is a CDN URL, not a committed binary.',
		);
		$this->assertStringContainsString('dist/', $gitignore, 'dist/ is build output');
	}
}
