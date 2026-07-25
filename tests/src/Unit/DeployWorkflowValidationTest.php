<?php

namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the deploy pipeline against a return of `drush un mantle2`.
 *
 * ModuleInstaller::uninstallSchema() drops every table hook_schema() returns. Until this
 * was fixed, every production release wiped push_tokens, mantle2_api_keys,
 * mantle2_subscriptions, mantle2_trial_codes and mantle2_trial_code_redemptions. This is
 * the only automated defence against someone re-adding that step.
 */
class DeployWorkflowValidationTest extends TestCase
{
	private static string $deployScript;
	private static array $workflow;

	public static function setUpBeforeClass(): void
	{
		$path = dirname(__DIR__, 3) . '/.github/workflows/build.yml';
		if (!file_exists($path)) {
			self::fail('Workflow file not found: ' . $path);
		}

		try {
			self::$workflow = Yaml::parseFile($path);
		} catch (ParseException $e) {
			self::fail('Failed to parse build.yml: ' . $e->getMessage());
		}

		self::$deployScript = self::findDeployScript(self::$workflow);
		if (self::$deployScript === '') {
			self::fail('No deploy step with an ssh-action script was found in build.yml');
		}
	}

	private static function findDeployScript(array $workflow): string
	{
		foreach ($workflow['jobs'] ?? [] as $job) {
			foreach ($job['steps'] ?? [] as $step) {
				$script = $step['with']['script'] ?? null;
				if (is_string($script) && str_contains($script, 'drush')) {
					return $script;
				}
			}
		}

		return '';
	}

	#[Test]
	#[TestDox('The deploy script never uninstalls the module, which would drop every custom table')]
	#[Group('mantle2/install')]
	public function testNoUninstall(): void
	{
		$this->assertStringNotContainsString(
			'drush un mantle2',
			self::$deployScript,
			'`drush un mantle2` drops every table in mantle2_schema(). Use `drush mantle2:sync`.',
		);
		$this->assertStringNotContainsString('drush pmu mantle2', self::$deployScript);
		$this->assertStringNotContainsString('uninstall mantle2', self::$deployScript);
	}

	#[Test]
	#[TestDox('The deploy script runs mantle2:sync to apply programmatic install routines')]
	#[Group('mantle2/install')]
	public function testRunsSync(): void
	{
		$this->assertStringContainsString('mantle2:sync', self::$deployScript);
	}

	#[Test]
	#[TestDox('drush cr runs before mantle2:sync so the new command is discoverable')]
	#[Group('mantle2/install')]
	public function testCacheRebuildPrecedesSync(): void
	{
		$rebuild = strpos(self::$deployScript, 'drush cr');
		$sync = strpos(self::$deployScript, 'mantle2:sync');

		$this->assertNotFalse($rebuild, 'Deploy script must rebuild caches');
		$this->assertNotFalse($sync);
		$this->assertLessThan(
			$sync,
			$rebuild,
			'A stale container hides the new Drush command; rebuild caches first.',
		);
	}

	#[Test]
	#[TestDox('The deploy script still applies database updates and fails fast')]
	#[Group('mantle2/install')]
	public function testUpdatesAndFailFast(): void
	{
		$this->assertStringContainsString('drush updb', self::$deployScript);
		$this->assertStringContainsString(
			'set -e',
			self::$deployScript,
			'Without set -e a failed sync still restarts apache on half-applied schema.',
		);
	}
}
