<?php

namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class EmailCampaignsValidationTest extends TestCase
{
	private static array $campaigns;
	private static string $campaignsFilePath;

	public static function setUpBeforeClass(): void
	{
		self::$campaignsFilePath = dirname(__DIR__, 3) . '/data/email_campaigns.yml';

		if (!file_exists(self::$campaignsFilePath)) {
			self::fail('Email campaigns file not found: ' . self::$campaignsFilePath);
		}

		try {
			self::$campaigns = Yaml::parseFile(self::$campaignsFilePath);
		} catch (ParseException $e) {
			self::fail('Failed to parse email campaigns YAML: ' . $e->getMessage());
		}
	}

	public static function campaignProvider(): array
	{
		if (!isset(self::$campaigns)) {
			self::setUpBeforeClass();
		}

		$data = [];
		foreach (self::$campaigns as $index => $campaign) {
			$campaignId = $campaign['id'] ?? "campaign_$index";
			$data[$campaignId] = [$campaignId, $campaign];
		}
		return $data;
	}

	#[Test]
	#[TestDox('Email campaigns file should exist and be valid YAML')]
	#[Group('mantle2/email-campaigns')]
	public function testEmailCampaignsFileIsValidYaml(): void
	{
		$this->assertFileExists(self::$campaignsFilePath);
		$this->assertIsArray(self::$campaigns);
		$this->assertNotEmpty(self::$campaigns);
	}

	#[Test]
	#[TestDox('Campaign $campaignId should have required fields')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignHasRequiredFields(string $campaignId, array $campaign): void
	{
		$requiredFields = ['id', 'title', 'interval', 'body'];

		foreach ($requiredFields as $field) {
			$this->assertArrayHasKey(
				$field,
				$campaign,
				"Campaign '$campaignId' is missing required field: $field",
			);
		}
	}

	#[Test]
	#[TestDox('Campaign $campaignId ID should match')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignIdMatches(string $campaignId, array $campaign): void
	{
		$this->assertEquals(
			$campaignId,
			$campaign['id'],
			"Campaign ID mismatch: expected '$campaignId', got '{$campaign['id']}'",
		);
	}

	#[Test]
	#[TestDox('Campaign $campaignId title should be valid')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignTitleValid(string $campaignId, array $campaign): void
	{
		if (!isset($campaign['title'])) {
			$this->markTestSkipped("Campaign '$campaignId' has no title");
		}

		$this->assertNotEmpty($campaign['title'], "Campaign '$campaignId' has empty title");
		$this->assertIsString($campaign['title'], "Campaign '$campaignId' title is not a string");
		$this->assertLessThanOrEqual(
			100,
			strlen($campaign['title']),
			"Campaign '$campaignId' title exceeds 100 characters",
		);
	}

	#[Test]
	#[TestDox('Campaign $campaignId interval should be valid')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignIntervalValid(string $campaignId, array $campaign): void
	{
		if (!isset($campaign['interval'])) {
			$this->markTestSkipped("Campaign '$campaignId' has no interval");
		}

		$this->assertIsInt(
			$campaign['interval'],
			"Campaign '$campaignId' interval is not an integer",
		);
		$this->assertGreaterThan(
			0,
			$campaign['interval'],
			"Campaign '$campaignId' interval must be positive",
		);

		// Intervals should be reasonable (1 hour to 30 days)
		$this->assertGreaterThanOrEqual(
			3600,
			$campaign['interval'],
			"Campaign '$campaignId' interval too short (minimum 1 hour = 3600 seconds)",
		);
		$this->assertLessThanOrEqual(
			2592000,
			$campaign['interval'],
			"Campaign '$campaignId' interval too long (maximum 30 days = 2592000 seconds)",
		);
	}

	#[Test]
	#[TestDox('Campaign $campaignId body should be valid')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignBodyValid(string $campaignId, array $campaign): void
	{
		if (!isset($campaign['body'])) {
			$this->markTestSkipped("Campaign '$campaignId' has no body");
		}

		$this->assertIsString($campaign['body'], "Campaign '$campaignId' body is not a string");
		$this->assertNotEmpty($campaign['body'], "Campaign '$campaignId' has empty body");
		$this->assertGreaterThan(
			10,
			strlen($campaign['body']),
			"Campaign '$campaignId' body too short",
		);
	}

	#[Test]
	#[TestDox('Campaign $campaignId filter should be valid')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignFilterValid(string $campaignId, array $campaign): void
	{
		// Filter is optional
		if (!isset($campaign['filter'])) {
			$this->markTestSkipped("Campaign '$campaignId' has no filter");
		}

		$validFilters = [
			'verifiedFilter',
			'unverifiedFilter',
			'inactiveFilter',
			'activeFilter',
			'allFilter',
		];

		$this->assertContains(
			$campaign['filter'],
			$validFilters,
			"Campaign '$campaignId' has invalid filter: {$campaign['filter']}",
		);
	}

	#[Test]
	#[TestDox('Campaign $campaignId global_filter should be valid')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignGlobalFilterValid(string $campaignId, array $campaign): void
	{
		// Global filter is optional
		if (!isset($campaign['global_filter'])) {
			$this->markTestSkipped("Campaign '$campaignId' has no global_filter");
		}

		$validGlobalFilters = ['newActivitiesFilter', 'newEventsFilter', 'newArticlesFilter'];

		$this->assertContains(
			$campaign['global_filter'],
			$validGlobalFilters,
			"Campaign '$campaignId' has invalid global_filter: {$campaign['global_filter']}",
		);
	}

	#[Test]
	#[TestDox('Campaign $campaignId unsubscribable should be valid boolean')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignUnsubscribableValid(string $campaignId, array $campaign): void
	{
		// Unsubscribable is optional, defaults to true
		if (!isset($campaign['unsubscribable'])) {
			$this->markTestSkipped("Campaign '$campaignId' has no unsubscribable field");
		}

		$this->assertIsBool(
			$campaign['unsubscribable'],
			"Campaign '$campaignId' unsubscribable is not a boolean",
		);
	}

	#[Test]
	#[TestDox('Campaign $campaignId placeholders should be valid')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignPlaceholdersValid(string $campaignId, array $campaign): void
	{
		if (!isset($campaign['body'])) {
			$this->markTestSkipped("Campaign '$campaignId' has no body");
		}

		// Extract placeholders like {user.username}, {activity.random}
		preg_match_all('/\{([^}]+)\}/', $campaign['body'], $matches);
		$placeholders = $matches[1] ?? [];

		if (empty($placeholders)) {
			$this->markTestSkipped("Campaign '$campaignId' has no placeholders");
		}

		$validPrefixes = ['user', 'activity', 'event', 'article', 'prompt'];

		foreach ($placeholders as $placeholder) {
			// Check if placeholder has valid prefix
			$parts = explode('.', $placeholder);
			$this->assertGreaterThan(
				0,
				count($parts),
				"Campaign '$campaignId' has invalid placeholder format: {$placeholder}",
			);

			$prefix = $parts[0];
			$this->assertContains(
				$prefix,
				$validPrefixes,
				"Campaign '$campaignId' has invalid placeholder prefix: {$prefix} in {$placeholder}",
			);
		}
	}

	#[Test]
	#[TestDox('Count total number of email campaigns')]
	#[Group('mantle2/email-campaigns')]
	public function testEmailCampaignsCount(): void
	{
		$count = count(self::$campaigns);

		$this->assertGreaterThan(0, $count, 'Should have at least one email campaign');
		$this->assertLessThan(50, $count, 'Should have less than 50 email campaigns');

		echo "\nTotal email campaigns defined: $count";
	}

	#[Test]
	#[TestDox('Campaign IDs should be unique')]
	#[Group('mantle2/email-campaigns')]
	public function testCampaignIdsUnique(): void
	{
		$ids = [];

		foreach (self::$campaigns as $campaign) {
			if (isset($campaign['id'])) {
				$ids[] = $campaign['id'];
			}
		}

		$uniqueIds = array_unique($ids);

		$this->assertCount(
			count($ids),
			$uniqueIds,
			'Email campaign IDs are not unique. Duplicates found.',
		);
	}

	#[Test]
	#[TestDox('Validate campaign intervals')]
	#[Group('mantle2/email-campaigns')]
	public function testCampaignIntervalDistribution(): void
	{
		$intervals = [];

		foreach (self::$campaigns as $campaign) {
			if (isset($campaign['interval']) && isset($campaign['id'])) {
				$intervals[$campaign['id']] = $campaign['interval'];
			}
		}

		echo "\nCampaign intervals (in seconds):\n";
		foreach ($intervals as $id => $interval) {
			$days = round($interval / 86400, 2);
			$hours = round($interval / 3600, 2);
			echo "  - $id: $interval seconds ($days days / $hours hours)\n";
		}

		$this->assertNotEmpty($intervals, 'Should have campaigns with intervals');
	}
}
