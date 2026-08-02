<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\mantle2\Service\CampaignHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class EmailCampaignsValidationTest extends TestCase
{
	private const VALID_FILTERS = [
		'verifiedFilter',
		'unverifiedFilter',
		'inactiveFilter',
		'activeFilter',
		'activeVerifiedFilter',
		'newUserVerifiedFilter',
	];

	private const VALID_GLOBAL_FILTERS = ['newActivitiesFilter'];

	private const VALID_CATEGORIES = [
		'digest',
		'announcements',
		'reengagement',
		'lifecycle',
		'social',
		'quest',
	];

	/** Gmail's iOS app truncates around 38 characters, so the fixed part has to leave room */
	private const MAX_STATIC_TITLE_LENGTH = 25;

	/** Apple Mail on iOS shows roughly 48, Gmail roughly 38; 42 is the workable middle */
	private const MAX_SUBJECT_LENGTH = 42;

	private static array $campaigns;
	private static string $campaignsFilePath;
	private static array $mockStaticPlaceholderValues = [
		'{user.id}' => '42',
		'{user.identifier}' => '@test.user',
		'{user.first_name}' => 'Testy',
		'{user.last_name}' => 'McTest',
		'{user.username}' => 'test.user',
		'{user.email}' => 'test@example.com',
		'{activity.recommended}' => 'Recommended activity',
		'{activity.recommended.title}' => 'Recommended activity title',
		'{activity.weekly}' => 'Weekly activities',
		'{activity.last_added}' => 'Last added activities',
		'{activity.last_added.title}' => 'Last added activity title',
		'{prompt.weekly}' => 'Weekly prompts',
		'{article.weekly}' => 'Weekly articles',
		// assets resolve from a static catalog, so they never trigger the missing-content skip
		'{asset.motd}' => '![A banner](https://cdn.earth-app.com/marketing/email/motd_x.png)',
		'{asset.garden}' =>
			'![A garden](https://cdn.earth-app.com/marketing/email/circle-garden_dusk_after.jpg)',
		'{asset.app_qr}' =>
			'[![Scan](https://cdn.earth-app.com/marketing/email/qr_black.png)](https://earth-app.com/ios)',
		'{asset.app_launch}' =>
			'[![Store](https://cdn.earth-app.com/marketing/email/launch_post_landscape.jpg)](https://earth-app.com/ios)',
	];
	private static array $mockMissingContentPlaceholderValues = [
		'{activity.recommended}' => 'No recommended activity found',
		'{activity.recommended.title}' => 'No recommended activity found',
		'{activity.random}' => 'No random activity found',
		'{activity.random.title}' => 'No random activity found',
		'{activity.weekly}' => 'No weekly activities found',
		'{activity.last_added}' => 'No recently added activities found',
		'{activity.last_added.title}' => 'No recently added activities found',
		'{prompt.random}' => 'No random prompt found',
		'{prompt.random.title}' => 'No random prompt found',
		'{prompt.weekly}' => 'No weekly prompts found',
		'{article.random}' => 'No random article found',
		'{article.random.title}' => 'No article found',
		'{article.weekly}' => 'No weekly articles found',
		'{event.upcoming}' => 'No upcoming event found',
		'{event.upcoming.title}' => 'No upcoming event found',
	];
	private static array $mockRandomPools = [
		'activity' => ['1', '2', '3', '4', '5'],
		'prompt' => ['1', '2', '3', '4', '5'],
		'article' => ['1', '2', '3', '4', '5'],
		'event' => ['1', '2', '3', '4', '5'],
	];
	private static array $randomPlaceholderFamilies = [
		'{activity.random}' => 'activity',
		'{activity.random.title}' => 'activity',
		'{prompt.random}' => 'prompt',
		'{prompt.random.title}' => 'prompt',
		'{article.random}' => 'article',
		'{article.random.title}' => 'article',
		'{event.upcoming}' => 'event',
		'{event.upcoming.title}' => 'event',
	];

	private static function normalizeRepeatValue(mixed $repeatValue): bool
	{
		if (is_string($repeatValue)) {
			$lower = strtolower(trim($repeatValue));
			return !in_array($lower, ['false', '0', ''], true);
		}

		return (bool) $repeatValue;
	}

	private static function isTitlePlaceholder(string $placeholder): bool
	{
		return str_ends_with($placeholder, '.title}');
	}

	private static function nextMockRandomValue(
		string $placeholder,
		array &$randomIndices,
		bool $repeat,
		array &$cachedByFamily,
	): string {
		$family = self::$randomPlaceholderFamilies[$placeholder] ?? null;
		if ($family === null) {
			return '__UNRESOLVED_PLACEHOLDER__' . $placeholder;
		}

		if (!$repeat && isset($cachedByFamily[$family])) {
			$selected = $cachedByFamily[$family];
		} else {
			$index = $randomIndices[$family] ?? 0;
			$pool = self::$mockRandomPools[$family] ?? ['1'];
			$selected = $pool[$index % count($pool)];
			$randomIndices[$family] = $index + 1;

			if (!$repeat) {
				$cachedByFamily[$family] = $selected;
			}
		}

		$familyLabel = ucfirst($family);
		if (self::isTitlePlaceholder($placeholder)) {
			return "Random {$familyLabel} title {$selected} [{$family}:{$selected}]";
		}

		return "Random {$familyLabel} {$selected} [{$family}:{$selected}]";
	}

	private static function replacePlaceholdersWithMockData(
		string $text,
		bool $repeat,
		array &$randomIndices,
		array &$cachedByFamily,
		array $mockStaticPlaceholderValues,
	): string {
		return (string) preg_replace_callback(
			'/\{[^}]+\}/',
			function (array $matches) use (
				$repeat,
				&$randomIndices,
				&$cachedByFamily,
				$mockStaticPlaceholderValues,
			): string {
				$placeholder = $matches[0];
				if (isset($mockStaticPlaceholderValues[$placeholder])) {
					return $mockStaticPlaceholderValues[$placeholder];
				}

				if (isset(self::$randomPlaceholderFamilies[$placeholder])) {
					return self::nextMockRandomValue(
						$placeholder,
						$randomIndices,
						$repeat,
						$cachedByFamily,
					);
				}

				// Keep an obvious marker so tests can fail with actionable output.
				return '__UNRESOLVED_PLACEHOLDER__' . $placeholder;
			},
			$text,
		);
	}

	private static function processCampaignWithMockData(
		array $campaign,
		?array $mockStaticPlaceholderValues = null,
	): array {
		$repeat = self::normalizeRepeatValue($campaign['repeat'] ?? true);
		$randomIndices = [];
		$cachedByFamily = [];
		$mockStaticPlaceholderValues =
			$mockStaticPlaceholderValues ?? self::$mockStaticPlaceholderValues;

		$processed = $campaign;
		if (isset($campaign['title'])) {
			$processed['title'] = self::replacePlaceholdersWithMockData(
				(string) $campaign['title'],
				$repeat,
				$randomIndices,
				$cachedByFamily,
				$mockStaticPlaceholderValues,
			);
		}

		if (isset($campaign['body'])) {
			$processed['body'] = self::replacePlaceholdersWithMockData(
				(string) $campaign['body'],
				$repeat,
				$randomIndices,
				$cachedByFamily,
				$mockStaticPlaceholderValues,
			);
		}

		return $processed;
	}

	private static function extractFamilyTokens(string $text, string $family): array
	{
		preg_match_all('/\[' . preg_quote($family, '/') . ':(\d+)\]/', $text, $matches);
		return $matches[1] ?? [];
	}

	private static function campaignUsesFamilyInText(string $text, string $family): bool
	{
		$familyPlaceholders = [
			'activity' => ['{activity.random}', '{activity.random.title}'],
			'prompt' => ['{prompt.random}', '{prompt.random.title}'],
			'article' => ['{article.random}', '{article.random.title}'],
			'event' => ['{event.upcoming}', '{event.upcoming.title}'],
		];

		$placeholders = $familyPlaceholders[$family] ?? [];
		foreach ($placeholders as $placeholder) {
			if (str_contains($text, $placeholder)) {
				return true;
			}
		}

		return false;
	}

	private static function countFamilyOccurrencesInText(string $text, string $family): int
	{
		$familyPlaceholders = [
			'activity' => ['{activity.random}', '{activity.random.title}'],
			'prompt' => ['{prompt.random}', '{prompt.random.title}'],
			'article' => ['{article.random}', '{article.random.title}'],
			'event' => ['{event.upcoming}', '{event.upcoming.title}'],
		];

		$count = 0;
		foreach ($familyPlaceholders[$family] ?? [] as $placeholder) {
			$count += substr_count($text, $placeholder);
		}

		return $count;
	}

	private static function getMockSkipPlaceholderValues(): array
	{
		return array_merge(
			self::$mockStaticPlaceholderValues,
			self::$mockMissingContentPlaceholderValues,
		);
	}

	private static function campaignContainsAnyContentPlaceholder(array $campaign): bool
	{
		$title = (string) ($campaign['title'] ?? '');
		$body = (string) ($campaign['body'] ?? '');

		foreach (array_keys(self::$mockMissingContentPlaceholderValues) as $placeholder) {
			if (str_contains($title, $placeholder) || str_contains($body, $placeholder)) {
				return true;
			}
		}

		return false;
	}

	private static function invokeShouldSkipCampaign(
		array $campaign,
		array $processedCampaign,
	): bool {
		$method = new \ReflectionMethod(CampaignHelper::class, 'shouldSkipCampaign');

		return (bool) $method->invoke(null, $campaign, $processedCampaign);
	}

	private static function shouldSkipCampaignWithMockData(
		array $campaign,
		array $mockStaticPlaceholderValues,
	): bool {
		$processedCampaign = self::processCampaignWithMockData(
			$campaign,
			$mockStaticPlaceholderValues,
		);

		return self::invokeShouldSkipCampaign($campaign, $processedCampaign);
	}

	private static function getCampaignById(string $campaignId): ?array
	{
		foreach (self::$campaigns as $campaign) {
			if (($campaign['id'] ?? null) === $campaignId) {
				return $campaign;
			}
		}

		return null;
	}

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
		foreach (['id', 'interval', 'body'] as $field) {
			$this->assertArrayHasKey(
				$field,
				$campaign,
				"Campaign '$campaignId' is missing required field: $field",
			);
		}

		// a campaign supplies either a single title or a rotation pool, never neither
		$this->assertTrue(
			isset($campaign['title']) || isset($campaign['titles']),
			"Campaign '$campaignId' has neither a title nor a titles pool",
		);
	}

	/**
	 * Every campaign carries exactly one primary action.
	 *
	 * One destination means one unambiguous success metric per send, which is what makes any
	 * later comparison valid. The famous multi-CTA effect sizes are unreplicated case studies; the
	 * measurement argument is the real one.
	 */
	#[Test]
	#[TestDox('Campaign $campaignId has one CTA with an earth-app destination')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignHasSingleCta(string $campaignId, array $campaign): void
	{
		$this->assertArrayHasKey('cta', $campaign, "Campaign '$campaignId' has no cta");
		$cta = $campaign['cta'];
		$this->assertIsArray($cta, "Campaign '$campaignId' cta must be a map");
		$this->assertArrayHasKey('label', $cta);
		$this->assertArrayHasKey('url', $cta);
		$this->assertNotEmpty($cta['label']);

		$host = parse_url($cta['url'], PHP_URL_HOST);
		$this->assertIsString($host, "Campaign '$campaignId' cta url is unparseable");
		$this->assertTrue(
			$host === 'earth-app.com' || str_ends_with($host, '.earth-app.com'),
			"Campaign '$campaignId' cta points off-domain: {$cta['url']}",
		);

		// UTM tagging only applies to earth-app hosts, so an off-domain CTA is unmeasurable
		$this->assertLessThanOrEqual(
			30,
			strlen($cta['label']),
			"Campaign '$campaignId' cta label is too long for a button",
		);
	}

	#[Test]
	#[TestDox('Campaign $campaignId has a preheader within the inbox preview budget')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignHasPreheader(string $campaignId, array $campaign): void
	{
		$this->assertArrayHasKey(
			'preheader',
			$campaign,
			"Campaign '$campaignId' has no preheader, so clients will scrape the body or the alt text",
		);
		$this->assertIsString($campaign['preheader']);
		$this->assertNotEmpty($campaign['preheader']);

		// HTMLFactory::PREHEADER_LENGTH is 90 and clients truncate around there
		$this->assertLessThanOrEqual(
			90,
			strlen($campaign['preheader']),
			"Campaign '$campaignId' preheader exceeds the 90 character preview budget",
		);
	}

	#[Test]
	#[TestDox('Campaign $campaignId category and max_sends are well formed')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignCategoryAndMaxSends(string $campaignId, array $campaign): void
	{
		$unsubscribable = $campaign['unsubscribable'] ?? true;

		if ($unsubscribable) {
			$this->assertArrayHasKey(
				'category',
				$campaign,
				"Campaign '$campaignId' is unsubscribable so it needs a category to opt out of",
			);
			$this->assertContains($campaign['category'], self::VALID_CATEGORIES);
		}

		if (isset($campaign['max_sends'])) {
			$this->assertIsInt($campaign['max_sends']);
			$this->assertGreaterThan(0, $campaign['max_sends']);
		}
	}

	/**
	 * Marketing cadence floor.
	 *
	 * The complaint rate is the hard gate (Google: below 0.10%, never 0.30%), and daily sending
	 * multiplies monthly complaint opportunities roughly thirtyfold while lowering per-message
	 * engagement. Transactional campaigns are exempt because they are not competing for attention.
	 */
	#[Test]
	#[TestDox('Marketing campaign $campaignId sends no more often than weekly')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testMarketingCadenceFloor(string $campaignId, array $campaign): void
	{
		if (($campaign['unsubscribable'] ?? true) === false) {
			$this->markTestSkipped("Campaign '$campaignId' is transactional");
		}

		$this->assertGreaterThanOrEqual(
			604800,
			(int) $campaign['interval'],
			"Campaign '$campaignId' sends more often than weekly",
		);
	}

	#[Test]
	#[TestDox('Campaign $campaignId asset references are CDN-hosted with alt text')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignAssetsAreCdnHosted(string $campaignId, array $campaign): void
	{
		$body = $campaign['body'] ?? '';
		if (!preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $body, $matches, PREG_SET_ORDER)) {
			$this->markTestSkipped("Campaign '$campaignId' embeds no images");
		}

		foreach ($matches as $match) {
			// HTMLFactory renders an <img> only for this host; anything else silently degrades to
			// the alt text, and Outlook blocks images by default regardless
			$this->assertStringStartsWith(
				'https://cdn.earth-app.com/',
				$match[2],
				"Campaign '$campaignId' embeds a non-CDN image: {$match[2]}",
			);
			$this->assertNotEmpty(
				trim($match[1]),
				"Campaign '$campaignId' embeds an image with no alt text: {$match[2]}",
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

	/** @return string[] the single title or every entry of the rotation pool */
	private static function titlesOf(array $campaign): array
	{
		if (isset($campaign['titles']) && is_array($campaign['titles'])) {
			return $campaign['titles'];
		}

		return isset($campaign['title']) ? [$campaign['title']] : [];
	}

	#[Test]
	#[TestDox('Campaign $campaignId title should be valid')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignTitleValid(string $campaignId, array $campaign): void
	{
		$titles = self::titlesOf($campaign);
		$this->assertNotEmpty($titles, "Campaign '$campaignId' has no usable title");

		foreach ($titles as $title) {
			$this->assertIsString($title, "Campaign '$campaignId' title is not a string");
			$this->assertNotEmpty($title, "Campaign '$campaignId' has empty title");
			$this->assertLessThanOrEqual(
				100,
				strlen($title),
				"Campaign '$campaignId' title exceeds 100 characters",
			);

			// the load-bearing noun has to survive the Gmail iOS truncation point
			if (str_contains($title, '{')) {
				// a placeholder expands to unknown length, so the fixed wording around it stays
				// short enough to leave the expansion visible
				$static = trim(preg_replace('/\{[^}]+\}/', '', $title));
				$this->assertLessThanOrEqual(
					self::MAX_STATIC_TITLE_LENGTH,
					strlen($static),
					"Campaign '$campaignId' title has too much fixed wording to front-load: '$title'",
				);
				continue;
			}

			// a fully static title only has to fit the truncation point itself
			$this->assertLessThanOrEqual(
				self::MAX_SUBJECT_LENGTH,
				strlen($title),
				"Campaign '$campaignId' static title will truncate on mobile: '$title'",
			);
		}
	}

	#[Test]
	#[TestDox('Campaign $campaignId rotation pool is unique and large enough to attribute')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testTitlePoolIsUsable(string $campaignId, array $campaign): void
	{
		if (!isset($campaign['titles'])) {
			$this->markTestSkipped("Campaign '$campaignId' uses a single title");
		}

		$titles = $campaign['titles'];
		$this->assertIsArray($titles);
		$this->assertGreaterThanOrEqual(
			3,
			count($titles),
			"Campaign '$campaignId' pool is too small to learn anything from",
		);
		$this->assertSame(
			count($titles),
			count(array_unique($titles)),
			"Campaign '$campaignId' pool has duplicate templates, which skews the variant counts",
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

		$this->assertContains(
			$campaign['filter'],
			self::VALID_FILTERS,
			"Campaign '$campaignId' has invalid filter: {$campaign['filter']}",
		);
	}

	/**
	 * Every whitelisted filter must really exist on CampaignHelper.
	 *
	 * runEmailCampaigns() guards both filter lookups with method_exists, so a name that does not
	 * exist is silently skipped and the campaign matches EVERY user. This whitelist previously
	 * listed allFilter, newEventsFilter and newArticlesFilter, none of which exist.
	 */
	#[Test]
	#[TestDox('Every whitelisted filter is a real CampaignHelper method')]
	#[Group('mantle2/email-campaigns')]
	public function testWhitelistedFiltersExist(): void
	{
		foreach ([...self::VALID_FILTERS, ...self::VALID_GLOBAL_FILTERS] as $filter) {
			$this->assertTrue(
				method_exists(CampaignHelper::class, $filter),
				"Filter $filter is whitelisted but does not exist; a missing filter sends to everyone.",
			);
		}
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

		$this->assertContains(
			$campaign['global_filter'],
			self::VALID_GLOBAL_FILTERS,
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

		$validPrefixes = ['user', 'activity', 'event', 'article', 'prompt', 'asset'];

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
	#[TestDox('Campaign $campaignId placeholders should fully resolve with mock data')]
	#[Group('mantle2/email-campaigns')]
	#[DataProvider('campaignProvider')]
	public function testCampaignPlaceholdersFullyResolveWithMockData(
		string $campaignId,
		array $campaign,
	): void {
		$title = (string) ($campaign['title'] ?? '');
		$body = (string) ($campaign['body'] ?? '');
		$repeat = self::normalizeRepeatValue($campaign['repeat'] ?? true);

		$processed = self::processCampaignWithMockData($campaign);
		$resolvedTitle = (string) ($processed['title'] ?? '');
		$resolvedBody = (string) ($processed['body'] ?? '');

		$this->assertStringNotContainsString(
			'__UNRESOLVED_PLACEHOLDER__',
			$resolvedTitle,
			"Campaign '$campaignId' title has unresolved placeholders after mock replacement",
		);
		$this->assertStringNotContainsString(
			'__UNRESOLVED_PLACEHOLDER__',
			$resolvedBody,
			"Campaign '$campaignId' body has unresolved placeholders after mock replacement",
		);

		$this->assertDoesNotMatchRegularExpression(
			'/\{[^}]+\}/',
			$resolvedTitle,
			"Campaign '$campaignId' title still contains placeholder syntax after mock replacement",
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\{[^}]+\}/',
			$resolvedBody,
			"Campaign '$campaignId' body still contains placeholder syntax after mock replacement",
		);

		$families = ['activity', 'prompt', 'article', 'event'];
		foreach ($families as $family) {
			$titleUsesFamily = self::campaignUsesFamilyInText($title, $family);
			$bodyUsesFamily = self::campaignUsesFamilyInText($body, $family);
			$combinedTokens = array_unique(
				array_merge(
					self::extractFamilyTokens($resolvedTitle, $family),
					self::extractFamilyTokens($resolvedBody, $family),
				),
			);

			if (!$repeat && $titleUsesFamily && $bodyUsesFamily) {
				$this->assertCount(
					1,
					$combinedTokens,
					"Campaign '$campaignId' should reuse one {$family} token across title/body when repeat is false",
				);
			}

			if ($repeat) {
				$bodyOccurrences = self::countFamilyOccurrencesInText($body, $family);
				if ($bodyOccurrences > 1) {
					$this->assertGreaterThan(
						1,
						count($combinedTokens),
						"Campaign '$campaignId' should vary {$family} tokens when repeat is true",
					);
				}
			}
		}
	}

	#[Test]
	#[
		TestDox(
			'new_activities campaign should remain sendable with valid activity.last_added content',
		),
	]
	#[Group('mantle2/email-campaigns')]
	public function testNewActivitiesCampaignNotSkippedWithValidLastAddedValue(): void
	{
		$campaign = self::getCampaignById('new_activities');
		$this->assertIsArray($campaign, "Campaign 'new_activities' should exist");

		$this->assertFalse(
			self::shouldSkipCampaignWithMockData($campaign, self::$mockStaticPlaceholderValues),
			"Campaign 'new_activities' should not be skipped when {activity.last_added} resolves to content",
		);
	}

	#[Test]
	#[
		TestDox(
			'Campaigns with content placeholders should be skipped when placeholders resolve to missing content',
		),
	]
	#[Group('mantle2/email-campaigns')]
	public function testCampaignsWithContentPlaceholdersAreSkippedWhenContentMissing(): void
	{
		$skipPlaceholderValues = self::getMockSkipPlaceholderValues();

		foreach (self::$campaigns as $campaign) {
			$campaignId = (string) ($campaign['id'] ?? 'unknown');

			if (!self::campaignContainsAnyContentPlaceholder($campaign)) {
				continue;
			}

			$this->assertTrue(
				self::shouldSkipCampaignWithMockData($campaign, $skipPlaceholderValues),
				"Campaign '$campaignId' should be skipped when placeholders resolve to missing content values",
			);
		}
	}

	#[Test]
	#[
		TestDox(
			'Campaigns without content placeholders should not be skipped by missing content values',
		),
	]
	#[Group('mantle2/email-campaigns')]
	public function testCampaignsWithoutContentPlaceholdersAreNotSkippedWhenContentMissing(): void
	{
		$skipPlaceholderValues = self::getMockSkipPlaceholderValues();

		foreach (self::$campaigns as $campaign) {
			$campaignId = (string) ($campaign['id'] ?? 'unknown');

			if (self::campaignContainsAnyContentPlaceholder($campaign)) {
				continue;
			}

			$this->assertFalse(
				self::shouldSkipCampaignWithMockData($campaign, $skipPlaceholderValues),
				"Campaign '$campaignId' should not be skipped when it has no content placeholders",
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
