<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\Article;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\mantle2\Service\CampaignHelper;
use Drupal\mantle2\Service\PromptsHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionMethod;
use ReflectionProperty;

class CampaignHelperTest extends IntegrationTestBase
{
	// content-reaching placeholders (activity/prompt/article random + weekly) need the node types
	protected bool $installContentTypes = true;

	// one byte per character, so strlen() and the summary limits are directly comparable
	private const FILLER = 'a';

	// how far back a cron account is created; clears every campaign's first-send stagger
	private const OLD_ACCOUNT_AGE = 400 * 86400;

	// explicit uids sit well above the autoincrement so a cron user never collides with one
	private int $nextProbeUid = 1000;

	protected function setUp(): void
	{
		parent::setUp();
		// dead cloud so recommend/event placeholders degrade to missing-content fallbacks
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
	}

	// #region Seeding

	private function seedActivity(string $id, array $types = ['HOBBY']): void
	{
		ActivityHelper::createActivity(
			new Activity($id, "Name $id", $types, "Description of $id", [], ['icon' => 'mdi:x']),
		);
	}

	private function seedPrompt(UserInterface $owner, string $text): void
	{
		$obj = new Prompt(0, $text, (int) $owner->id(), Visibility::PUBLIC);
		PromptsHelper::createPrompt($obj, null);
	}

	private function seedArticle(UserInterface $author, string $title): void
	{
		ArticlesHelper::createArticle(
			$title,
			'A short description',
			['ocean'],
			str_repeat('The sea shapes the weather across the whole planet. ', 3),
			$author,
			'#112233',
			null,
		);
	}

	// enough local content that every content placeholder resolves instead of falling back
	private function seedAllContent(UserInterface $owner): void
	{
		$this->seedActivity('run', ['SPORT']);
		$this->seedPrompt($owner, 'A public prompt for the campaign body');
		$this->seedArticle($owner, 'A Campaign Article');
	}

	private function verifiedActiveUser(array $extra = []): UserInterface
	{
		$user = $this->createUser(
			$extra + [
				'field_email_verified' => true,
				'field_first_name' => 'Ada',
				'field_last_name' => 'Lovelace',
			],
		);
		// mark as recently active so inactiveFilter is false
		$user->setLastLoginTime(Drupal::time()->getCurrentTime());
		$user->save();
		return $user;
	}

	/**
	 * A user built for the cron loop, with the marketing holdout decided rather than drawn.
	 *
	 * An autoincrement uid has a 1-in-HOLDOUT_DIVISOR chance of being permanently
	 * marketing-silent, which would make a send assertion fail on nothing but the uid it drew.
	 */
	private function cronUser(
		array $values = [],
		?int $lastLogin = null,
		bool $holdout = false,
	): UserInterface {
		$now = Drupal::time()->getCurrentTime();
		$suffix = bin2hex(random_bytes(4));

		$user = User::create(
			$values + [
				'uid' => $this->uidWhere($holdout),
				'name' => 'user_' . $suffix,
				'mail' => $suffix . '@example.com',
				'status' => 1,
				'field_email_verified' => true,
				'field_first_name' => 'Ada',
				'field_last_name' => 'Lovelace',
				'field_subscribed' => true,
				'created' => $now - self::OLD_ACCOUNT_AGE,
			],
		);
		$user->enforceIsNew();
		$user->save();

		$user->setLastLoginTime($lastLogin ?? $now);
		$user->save();

		return $user;
	}

	// smallest unclaimed uid whose holdout membership matches, read from the real predicate
	private function uidWhere(bool $holdout): int
	{
		$limit = $this->nextProbeUid + 500;
		for ($uid = $this->nextProbeUid; $uid < $limit; $uid++) {
			$probe = User::create(['uid' => $uid, 'name' => 'probe_' . $uid]);
			if (CampaignHelper::isMarketingHoldout($probe) === $holdout) {
				$this->nextProbeUid = $uid + 1;
				return $uid;
			}
		}

		self::fail(
			'no uid in ' . $this->nextProbeUid . '..' . $limit . ' with holdout=' . $holdout,
		);
	}

	// #endregion

	// #region Campaign Roles

	/** @return array the one campaign exempt from the marketing gates */
	private function transactionalCampaign(): array
	{
		foreach (CampaignHelper::getCampaigns() as $campaign) {
			if (($campaign['unsubscribable'] ?? true) === false) {
				return $campaign;
			}
		}

		self::fail('email_campaigns.yml has no transactional campaign');
	}

	/** @return array the campaign with the widest subject pool, so rotation has the most to cover */
	private function pooledCampaign(): array
	{
		$widest = null;
		foreach (CampaignHelper::getCampaigns() as $campaign) {
			$titles = $campaign['titles'] ?? null;
			if (!is_array($titles)) {
				continue;
			}

			if ($widest === null || count($titles) > count($widest['titles'])) {
				$widest = $campaign;
			}
		}

		if ($widest === null) {
			self::fail('email_campaigns.yml has no campaign with a titles pool');
		}

		return $widest;
	}

	// #endregion

	// #region Send Markers

	private function markerKey(string $campaignId, UserInterface $user): string
	{
		return "campaign:{$campaignId}:user:{$user->id()}";
	}

	/** @return array<string, array> campaign id => send marker, for every campaign that fired */
	private function sentCampaigns(UserInterface $user): array
	{
		$sent = [];
		foreach (CampaignHelper::getCampaigns() as $campaign) {
			$id = $campaign['id'] ?? null;
			if (!is_string($id)) {
				continue;
			}

			$marker = RedisHelper::get($this->markerKey($id, $user));
			if ($marker) {
				$sent[$id] = $marker;
			}
		}

		return $sent;
	}

	private function lastMarketingKey(UserInterface $user): string
	{
		return (string) self::invoke('lastMarketingKey', $user->id());
	}

	// #endregion

	// #region Reflection

	private static function invoke(string $method, mixed ...$args): mixed
	{
		return new ReflectionMethod(CampaignHelper::class, $method)->invoke(null, ...$args);
	}

	/** @return array{0: ?string, 1: ?int} */
	private static function selectTitle(array $campaign, UserInterface $user): array
	{
		return self::invoke('selectTitle', $campaign, $user);
	}

	/** matches an expanded subject against the template it claims to come from */
	private static function templatePattern(string $template): string
	{
		$parts = array_map(
			fn(string $part) => preg_quote($part, '/'),
			explode("\0", (string) preg_replace('/\{[^}]+\}/', "\0", $template)),
		);

		return '/^' . implode('.+', $parts) . '$/s';
	}

	// #endregion

	// Retrieval

	#[Test]
	#[TestDox('getCampaigns decodes every campaign with the keys the cron loop reads')]
	#[Group('mantle2/campaigns')]
	public function getCampaigns(): void
	{
		$campaigns = CampaignHelper::getCampaigns();
		$this->assertNotEmpty($campaigns);

		$ids = [];
		foreach ($campaigns as $index => $campaign) {
			$label = "campaign $index";

			foreach (['id', 'interval', 'body'] as $key) {
				$this->assertArrayHasKey($key, $campaign, "$label is missing $key");
			}

			// runEmailCampaigns skips anything without an id and an int interval
			$this->assertIsString($campaign['id']);
			$this->assertIsInt($campaign['interval'], "$label interval is not an int");
			$this->assertGreaterThanOrEqual(3600, $campaign['interval'], "$label sends hourly");
			$this->assertLessThanOrEqual(2592000, $campaign['interval'], "$label is unreachable");

			$this->assertTrue(
				isset($campaign['title']) || isset($campaign['titles']),
				"$label has neither a title nor a titles pool",
			);

			$ids[] = $campaign['id'];
		}

		$this->assertSame($ids, array_values(array_unique($ids)), 'duplicate campaign ids');

		// sendEmailCampaign resolves the id it was handed, so every id has to round-trip
		foreach ($ids as $id) {
			$resolved = CampaignHelper::getCampaign($id);
			$this->assertNotNull($resolved, "getCampaign lost '$id'");
			$this->assertSame($id, $resolved['id']);
		}
	}

	#[Test]
	#[TestDox('getCampaign resolves by id, by numeric index, and returns null for unknown keys')]
	#[Group('mantle2/campaigns')]
	public function getCampaign(): void
	{
		$campaigns = CampaignHelper::getCampaigns();
		$first = $campaigns[0];

		$byId = CampaignHelper::getCampaign($first['id']);
		$this->assertNotNull($byId);
		$this->assertSame($first, $byId);

		$byIndex = CampaignHelper::getCampaign('0');
		$this->assertNotNull($byIndex);
		$this->assertSame($first['id'], $byIndex['id']);

		$this->assertNull(CampaignHelper::getCampaign('does_not_exist'));
	}

	/**
	 * The transactional exemption is keyed on `unsubscribable === false` alone.
	 *
	 * That one flag skips the weekly cooldown, the holdout and the dormancy suppression at once, so
	 * a second campaign claiming it would quietly bypass all three.
	 */
	#[Test]
	#[TestDox('exactly one campaign is transactional and it is a finite, uncategorised series')]
	#[Group('mantle2/campaigns')]
	public function transactionalCampaignRole(): void
	{
		$transactional = array_values(
			array_filter(
				CampaignHelper::getCampaigns(),
				fn(array $campaign) => ($campaign['unsubscribable'] ?? true) === false,
			),
		);

		$this->assertCount(1, $transactional, 'more than one campaign skips every marketing gate');

		$campaign = $transactional[0];
		$this->assertArrayNotHasKey(
			'category',
			$campaign,
			'a transactional campaign has no stream to opt out of',
		);
		$this->assertArrayHasKey('max_sends', $campaign, 'an uncapped series nags forever');
		$this->assertTrue(method_exists(CampaignHelper::class, $campaign['filter']));
	}

	// Filters

	#[Test]
	#[TestDox('verifiedFilter and unverifiedFilter split users on their email-verified flag')]
	#[Group('mantle2/campaigns')]
	public function verifiedFilters(): void
	{
		$verified = $this->verifiedActiveUser();
		$unverified = $this->verifiedActiveUser(['field_email_verified' => false]);

		$this->assertTrue(CampaignHelper::verifiedFilter($verified));
		$this->assertFalse(CampaignHelper::verifiedFilter($unverified));

		// unverifiedFilter also excludes inactive users, so use active ones here
		$this->assertTrue(CampaignHelper::unverifiedFilter($unverified));
		$this->assertFalse(CampaignHelper::unverifiedFilter($verified));
	}

	#[Test]
	#[TestDox('inactiveFilter flags never-logged-in and stale users, activeFilter is its inverse')]
	#[Group('mantle2/campaigns')]
	public function inactiveFilter(): void
	{
		$neverLoggedIn = $this->createUser();
		$this->assertTrue(CampaignHelper::inactiveFilter($neverLoggedIn));
		$this->assertFalse(CampaignHelper::activeFilter($neverLoggedIn));

		$stale = $this->createUser();
		$stale->setLastLoginTime(strtotime('-3 weeks'));
		$stale->save();
		$this->assertTrue(CampaignHelper::inactiveFilter($stale));

		$recent = $this->createUser();
		$recent->setLastLoginTime(Drupal::time()->getCurrentTime());
		$recent->save();
		$this->assertFalse(CampaignHelper::inactiveFilter($recent));
		$this->assertTrue(CampaignHelper::activeFilter($recent));
	}

	#[Test]
	#[TestDox('activeVerifiedFilter requires both recent activity and a verified email')]
	#[Group('mantle2/campaigns')]
	public function activeVerifiedFilter(): void
	{
		$activeVerified = $this->verifiedActiveUser();
		$this->assertTrue(CampaignHelper::activeVerifiedFilter($activeVerified));

		$activeUnverified = $this->verifiedActiveUser(['field_email_verified' => false]);
		$this->assertFalse(CampaignHelper::activeVerifiedFilter($activeUnverified));

		$staleVerified = $this->verifiedActiveUser();
		$staleVerified->setLastLoginTime(strtotime('-3 weeks'));
		$staleVerified->save();
		$this->assertFalse(CampaignHelper::activeVerifiedFilter($staleVerified));
	}

	/**
	 * The onboarding window is an age bound, not just a verification check.
	 *
	 * Without it the first-send path (`created + crc32 offset`) is long past for every existing
	 * account, so an onboarding campaign fires at the whole user base on its first cron tick.
	 */
	#[Test]
	#[
		TestDox(
			'newUserVerifiedFilter accepts a fresh verified account and rejects old or unverified',
		),
	]
	#[Group('mantle2/campaigns')]
	public function newUserVerifiedFilter(): void
	{
		$now = Drupal::time()->getCurrentTime();

		$fresh = $this->createUser(['field_email_verified' => true, 'created' => $now - 86400]);
		$this->assertTrue(CampaignHelper::newUserVerifiedFilter($fresh));

		$old = $this->createUser([
			'field_email_verified' => true,
			'created' => $now - 30 * 86400,
		]);
		$this->assertFalse(CampaignHelper::newUserVerifiedFilter($old));

		$freshUnverified = $this->createUser([
			'field_email_verified' => false,
			'created' => $now - 86400,
		]);
		$this->assertFalse(CampaignHelper::newUserVerifiedFilter($freshUnverified));

		$justOutside = $this->createUser([
			'field_email_verified' => true,
			'created' => strtotime('-14 days') - 60,
		]);
		$this->assertFalse(CampaignHelper::newUserVerifiedFilter($justOutside));

		// the filter deliberately ignores recency of login, unlike activeVerifiedFilter
		$fresh->setLastLoginTime(strtotime('-3 weeks'));
		$fresh->save();
		$this->assertTrue(CampaignHelper::newUserVerifiedFilter($fresh));
	}

	// Placeholders

	#[Test]
	#[TestDox('runPlaceholders substitutes the local user placeholders in a template')]
	#[Group('mantle2/campaigns')]
	public function runPlaceholdersUserFields(): void
	{
		$user = $this->verifiedActiveUser();
		$text =
			'Hi {user.first_name} {user.last_name} (@{user.username}), id {user.id}, mail {user.email}';
		$result = CampaignHelper::runPlaceholders($text, $user);

		$this->assertStringContainsString('Ada Lovelace', $result);
		$this->assertStringContainsString('@' . $user->getAccountName(), $result);
		$this->assertStringContainsString('id ' . $user->id(), $result);
		$this->assertStringContainsString($user->getEmail(), $result);
		$this->assertStringNotContainsString('{user.', $result);
	}

	#[Test]
	#[TestDox('runPlaceholders falls back to the username when the display name is empty')]
	#[Group('mantle2/campaigns')]
	public function runPlaceholdersIdentifierFallback(): void
	{
		$user = $this->createUser(['field_email_verified' => true]);
		$user->setLastLoginTime(Drupal::time()->getCurrentTime());
		$user->save();

		$result = CampaignHelper::runPlaceholders('{user.identifier}', $user);
		$this->assertSame('@' . $user->getAccountName(), $result);
	}

	#[Test]
	#[TestDox('processCampaign expands the transactional campaign and passes other keys through')]
	#[Group('mantle2/campaigns')]
	public function processCampaignSubstitutesBody(): void
	{
		$user = $this->verifiedActiveUser();
		$campaign = $this->transactionalCampaign();

		$processed = CampaignHelper::processCampaign($campaign, $user);

		// a verification mail that does not say whose account it is about is a phishing lookalike
		$this->assertStringContainsString(
			'@' . $user->getAccountName(),
			$processed['body'],
			'the transactional body no longer addresses the recipient',
		);
		$this->assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $processed['body']);
		$this->assertDoesNotMatchRegularExpression('/\{[^}]+\}/', $processed['title']);
		// a single fixed subject has no pool, so there is no variant to attribute a click to
		$this->assertNull($processed['variant_id']);
		// unrelated keys pass through untouched
		$this->assertSame($campaign['interval'], $processed['interval']);
	}

	#[Test]
	#[TestDox('processCampaign leaves no placeholder syntax in any campaign string')]
	#[Group('mantle2/campaigns')]
	public function processCampaignExpandsEveryString(): void
	{
		$user = $this->verifiedActiveUser();
		$this->seedAllContent($user);

		foreach (CampaignHelper::getCampaigns() as $campaign) {
			$id = $campaign['id'];
			$processed = CampaignHelper::processCampaign($campaign, $user);

			foreach (['title', 'preheader', 'body'] as $key) {
				if (!isset($processed[$key])) {
					continue;
				}

				$this->assertDoesNotMatchRegularExpression(
					'/\{[^}]+\}/',
					$processed[$key],
					"campaign '$id' $key still contains placeholder syntax",
				);
			}

			foreach ($processed['cta'] ?? [] as $field => $value) {
				if (!is_string($value)) {
					continue;
				}

				$this->assertDoesNotMatchRegularExpression(
					'/\{[^}]+\}/',
					$value,
					"campaign '$id' cta.$field still contains placeholder syntax",
				);
			}
		}
	}

	#[Test]
	#[TestDox('processCampaign expands placeholders in the preheader and in both cta shapes')]
	#[Group('mantle2/campaigns')]
	public function processCampaignExpandsPreheaderAndCta(): void
	{
		$user = $this->verifiedActiveUser();

		$processed = CampaignHelper::processCampaign(
			[
				'id' => 'synthetic_cta_map',
				'title' => 'A subject for {user.first_name}',
				'preheader' => 'Waiting on you, {user.first_name}',
				'cta' => [
					'label' => 'Open {user.first_name}',
					'url' => 'https://app.earth-app.com/u/{user.id}',
				],
				'body' => 'A body',
			],
			$user,
		);

		$this->assertSame('A subject for Ada', $processed['title']);
		$this->assertSame('Waiting on you, Ada', $processed['preheader']);
		$this->assertSame('Open Ada', $processed['cta']['label']);
		$this->assertSame('https://app.earth-app.com/u/' . $user->id(), $processed['cta']['url']);

		// a bare string cta is the destination on its own
		$stringCta = CampaignHelper::processCampaign(
			[
				'id' => 'synthetic_cta_string',
				'title' => 'A subject',
				'cta' => 'https://app.earth-app.com/u/{user.id}',
				'body' => 'A body',
			],
			$user,
		);

		$this->assertSame('https://app.earth-app.com/u/' . $user->id(), $stringCta['cta']);
	}

	// Global Filters

	#[Test]
	#[TestDox('newActivitiesFilter is true only when recent activities exist')]
	#[Group('mantle2/campaigns')]
	public function newActivitiesFilter(): void
	{
		$this->assertFalse(CampaignHelper::newActivitiesFilter());
		$this->seedActivity('run');
		$this->assertTrue(CampaignHelper::newActivitiesFilter());
	}

	// Subject Rotation

	/**
	 * A fixed pool with a logged variant id is the only reason to vary a subject at all.
	 *
	 * A per-send generated subject is unfalsifiable at n=1, and an index no user can draw is a
	 * variant that never accumulates evidence.
	 */
	#[Test]
	#[TestDox('selectTitle is stable per user and week and every pool index is reachable')]
	#[Group('mantle2/campaigns')]
	public function selectTitleRotation(): void
	{
		$campaign = $this->pooledCampaign();
		$pool = $campaign['titles'];

		$user = $this->verifiedActiveUser();
		$chosen = self::selectTitle($campaign, $user);
		$this->assertSame($chosen, self::selectTitle($campaign, $user));
		$this->assertIsInt($chosen[1]);
		$this->assertSame($pool[$chosen[1]], $chosen[0]);

		// unsaved probes are enough here: the seed only reads the uid
		$seen = [];
		for ($uid = 1; $uid <= 400; $uid++) {
			$probe = User::create(['uid' => $uid, 'name' => 'probe_' . $uid]);
			[, $index] = self::selectTitle($campaign, $probe);
			$seen[$index] = true;
		}

		ksort($seen);
		$this->assertSame(
			range(0, count($pool) - 1),
			array_keys($seen),
			'a pool entry no user can draw is a variant that never gets measured',
		);

		// a single fixed title reports no variant rather than index 0
		$transactional = $this->transactionalCampaign();
		$this->assertSame(
			[$transactional['title'], null],
			self::selectTitle($transactional, $user),
		);
	}

	#[Test]
	#[TestDox('processCampaign records the pool index it used as variant_id')]
	#[Group('mantle2/campaigns')]
	public function processCampaignVariantId(): void
	{
		$user = $this->verifiedActiveUser();
		$this->seedAllContent($user);

		$campaign = $this->pooledCampaign();
		$processed = CampaignHelper::processCampaign($campaign, $user);

		$this->assertIsInt($processed['variant_id']);
		$this->assertArrayHasKey($processed['variant_id'], $campaign['titles']);
		// utm_content is only attributable if the id names the template that actually went out
		$this->assertMatchesRegularExpression(
			self::templatePattern($campaign['titles'][$processed['variant_id']]),
			$processed['title'],
		);
	}

	// Content Placeholders (local formatting; cloud-backed values fall back to missing-content text)

	#[Test]
	#[TestDox('runPlaceholders formats a seeded random activity with its markdown link')]
	#[Group('mantle2/campaigns')]
	public function runPlaceholdersActivity(): void
	{
		$this->seedActivity('run', ['SPORT']);
		$user = $this->verifiedActiveUser();

		$result = CampaignHelper::runPlaceholders('Try this: {activity.random}', $user);
		$this->assertStringContainsString('Name run', $result);
		$this->assertStringContainsString('https://app.earth-app.com/activities/run', $result);
		$this->assertStringNotContainsString('{activity.random}', $result);

		$title = CampaignHelper::runPlaceholders('{activity.random.title}', $user);
		$this->assertSame('Name run', $title);
	}

	#[Test]
	#[TestDox('runPlaceholders formats weekly and last-added activity blocks')]
	#[Group('mantle2/campaigns')]
	public function runPlaceholdersActivityBatches(): void
	{
		$this->seedActivity('run');
		$this->seedActivity('read', ['LEARNING']);
		$user = $this->verifiedActiveUser();

		$weekly = CampaignHelper::runPlaceholders('{activity.weekly}', $user);
		$this->assertStringContainsString('Name run', $weekly);
		$this->assertStringContainsString('Name read', $weekly);

		$lastAdded = CampaignHelper::runPlaceholders('{activity.last_added}', $user);
		$this->assertStringContainsString('Name run', $lastAdded);
	}

	#[Test]
	#[TestDox('runPlaceholders formats a seeded random prompt and weekly prompts')]
	#[Group('mantle2/campaigns')]
	public function runPlaceholdersPrompt(): void
	{
		$owner = $this->verifiedActiveUser();
		$this->seedPrompt($owner, 'A thoughtful public prompt body');

		$result = CampaignHelper::runPlaceholders('{prompt.random}', $owner);
		$this->assertStringContainsString('A thoughtful public prompt body', $result);
		$this->assertStringContainsString('https://app.earth-app.com/prompts/', $result);

		$title = CampaignHelper::runPlaceholders('{prompt.random.title}', $owner);
		$this->assertSame('A thoughtful public prompt body', $title);

		$weekly = CampaignHelper::runPlaceholders('{prompt.weekly}', $owner);
		$this->assertStringContainsString('A thoughtful public prompt body', $weekly);
	}

	#[Test]
	#[TestDox('runPlaceholders formats a seeded random article and weekly articles')]
	#[Group('mantle2/campaigns')]
	public function runPlaceholdersArticle(): void
	{
		$author = $this->verifiedActiveUser();
		$this->seedArticle($author, 'The Blue Planet');

		$result = CampaignHelper::runPlaceholders('{article.random}', $author);
		$this->assertStringContainsString('The Blue Planet', $result);
		$this->assertStringContainsString('https://app.earth-app.com/articles/', $result);

		$title = CampaignHelper::runPlaceholders('{article.random.title}', $author);
		$this->assertSame('The Blue Planet', $title);

		$weekly = CampaignHelper::runPlaceholders('{article.weekly}', $author);
		$this->assertStringContainsString('The Blue Planet', $weekly);
	}

	#[Test]
	#[TestDox('missing content placeholders resolve to their fallback text')]
	#[Group('mantle2/campaigns')]
	public function missingContentFallback(): void
	{
		$user = $this->verifiedActiveUser();

		// nothing seeded and a dead cloud, so every content placeholder takes its own fallback;
		// shouldSkipCampaign matches on these exact strings, so a drifting pair skips nothing
		$fallbacks = self::invoke('getMissingContentPlaceholders');
		$this->assertNotEmpty($fallbacks);

		foreach ($fallbacks as $placeholder => $missingText) {
			$this->assertSame(
				$missingText,
				CampaignHelper::runPlaceholders($placeholder, $user),
				"$placeholder did not resolve to its missing-content text",
			);
		}
	}

	#[Test]
	#[TestDox('random placeholders are recomputed per occurrence when repeat is true')]
	#[Group('mantle2/campaigns')]
	public function randomPlaceholderRepeat(): void
	{
		$owner = $this->verifiedActiveUser();
		$this->seedPrompt($owner, 'The only public prompt body');

		// two occurrences both resolve (recompute loop covered)
		$result = CampaignHelper::runPlaceholders(
			'{prompt.random} and {prompt.random}',
			$owner,
			true,
		);
		$this->assertSame(2, substr_count($result, 'The only public prompt body'));
		$this->assertStringNotContainsString('{prompt.random}', $result);
	}

	/**
	 * With `repeat: false` the subject and the body have to name the same objects.
	 *
	 * The recommended activity is cached alongside the four random ones precisely because title and
	 * body are expanded by separate calls, and a subject naming a different activity than the body
	 * is a broken email.
	 */
	#[Test]
	#[TestDox('processCampaign with repeat=false shares one recommended activity across the send')]
	#[Group('mantle2/campaigns')]
	public function processCampaignNoRepeat(): void
	{
		$author = $this->verifiedActiveUser();
		$this->seedActivity('run', ['SPORT']);
		$this->seedActivity('read', ['LEARNING']);
		$this->seedActivity('swim', ['SPORT']);
		$this->seedArticle($author, 'A Cached Article');

		$processed = CampaignHelper::processCampaign(
			[
				'id' => 'synthetic_no_repeat',
				'repeat' => false,
				'title' => '{activity.recommended.title}',
				'body' =>
					'Subject said {activity.recommended.title}, body says {activity.recommended}',
			],
			$author,
		);

		$this->assertStringContainsString('Name ', $processed['title']);
		$this->assertStringContainsString(
			'Subject said ' . $processed['title'] . ',',
			$processed['body'],
		);
		// the formatted block carries an emoji prefix, so match the tail of the bold label
		$this->assertStringContainsString($processed['title'] . '**](', $processed['body']);

		// the cached random objects are shared the same way
		$article = CampaignHelper::processCampaign(
			[
				'id' => 'synthetic_no_repeat_article',
				'repeat' => false,
				'title' => '{article.random.title}',
				'body' => '{article.random}',
			],
			$author,
		);
		$this->assertSame('A Cached Article', $article['title']);
		$this->assertStringContainsString('A Cached Article', $article['body']);
	}

	#[Test]
	#[TestDox('processCampaign coerces a string repeat flag')]
	#[Group('mantle2/campaigns')]
	public function processCampaignStringRepeat(): void
	{
		$owner = $this->verifiedActiveUser();
		$this->seedPrompt($owner, 'A prompt for repeat coercion');

		$campaign = [
			'id' => 'synthetic',
			'repeat' => 'false',
			'title' => 'x',
			'body' => '{prompt.random}',
		];
		$processed = CampaignHelper::processCampaign($campaign, $owner);
		$this->assertStringContainsString('A prompt for repeat coercion', $processed['body']);
	}

	// #region Formatting

	/**
	 * An email teases and links; it does not inline the article.
	 *
	 * Gmail clips around 102KB of raw HTML mid-tag, which can cut the unsubscribe footer off and
	 * turn a layout problem into a compliance one.
	 */
	#[Test]
	#[TestDox('the three summary limits truncate long prose and mark the cut with an ellipsis')]
	#[Group('mantle2/campaigns')]
	public function summaryTruncation(): void
	{
		$user = $this->verifiedActiveUser();
		$now = Drupal::time()->getCurrentTime();
		$long = str_repeat(self::FILLER, 600);

		$activity = (string) self::invoke(
			'formatActivity',
			new Activity('run', 'Name run', ['SPORT'], $long, [], ['icon' => 'mdi:x']),
		);
		$this->assertStringContainsString('https://app.earth-app.com/activities/run', $activity);
		$this->assertTruncatedTo(CampaignHelper::ACTIVITY_SUMMARY_LENGTH, $activity);

		$article = (string) self::invoke(
			'formatArticle',
			new Article(
				7,
				'The Blue Planet',
				'A short description',
				['ocean'],
				$long,
				(int) $user->id(),
				0x112233,
				$now,
				$now,
				[],
			),
		);
		$this->assertStringContainsString('https://app.earth-app.com/articles/7', $article);
		$this->assertTruncatedTo(CampaignHelper::ARTICLE_SUMMARY_LENGTH, $article);

		$event = (string) self::invoke(
			'formatEvent',
			new Event(
				(int) $user->id(),
				'A Beach Cleanup',
				$long,
				EventType::IN_PERSON,
				[],
				0.0,
				0.0,
				($now + 86400) * 1000,
				null,
				Visibility::PUBLIC,
				[],
				[],
				'evt1',
			),
		);
		$this->assertStringContainsString('https://app.earth-app.com/events/evt1', $event);
		$this->assertTruncatedTo(CampaignHelper::EVENT_SUMMARY_LENGTH, $event);
	}

	// finds the filler line in a formatted block and holds it to its limit
	private function assertTruncatedTo(int $limit, string $formatted): void
	{
		$needle = str_repeat(self::FILLER, 16);

		foreach (explode("\n", $formatted) as $line) {
			$summary = trim($line, '*');
			if (!str_starts_with($summary, $needle)) {
				continue;
			}

			$this->assertSame($limit, strlen($summary), "summary is not cut to $limit bytes");
			$this->assertStringEndsWith('...', $summary);
			return;
		}

		self::fail("no summary line in: $formatted");
	}

	/**
	 * Each emoji already carries a trailing space.
	 *
	 * A separator between the emoji run and the name rendered as "**  Name**" with emojis and
	 * "** Name**" with none.
	 */
	#[Test]
	#[TestDox('formatActivity has no double space with emojis and no leading space without')]
	#[Group('mantle2/campaigns')]
	public function formatActivityEmojiSpacing(): void
	{
		$emojis = new ReflectionProperty(CampaignHelper::class, 'emojiMap')->getValue();

		$withTypes = (string) self::invoke(
			'formatActivity',
			new Activity('run', 'Trail Run', ['SPORT', 'NATURE'], 'A run', [], ['icon' => 'mdi:x']),
		);
		$this->assertStringStartsWith(
			'[**' . $emojis['sport'] . ' ' . $emojis['nature'] . ' Trail Run**]',
			$withTypes,
		);
		$this->assertStringNotContainsString('  ', $withTypes);

		// an activity whose types have no emoji falls back to the bare name
		$withoutTypes = (string) self::invoke(
			'formatActivity',
			new Activity('read', 'Quiet Reading', [], 'A read', [], ['icon' => 'mdi:x']),
		);
		$this->assertStringStartsWith('[**Quiet Reading**]', $withoutTypes);
	}

	// #endregion

	// #region Cron

	#[Test]
	#[TestDox('runEmailCampaigns sends one campaign per user per run and records the marker')]
	#[Group('mantle2/campaigns')]
	public function runEmailCampaignsSends(): void
	{
		$user = $this->cronUser();
		$this->seedAllContent($user);

		CampaignHelper::runEmailCampaigns();

		$sent = $this->sentCampaigns($user);
		$this->assertCount(1, $sent, 'cron caps a user at one campaign per run');

		$id = array_key_first($sent);
		$this->assertSame($id, $sent[$id]['campaign_id']);
		$this->assertSame(1, $sent[$id]['count']);
		$this->assertGreaterThan(0, $sent[$id]['sent_at']);

		// a marketing send also stamps the shared weekly marker
		$marketing = RedisHelper::get($this->lastMarketingKey($user));
		$this->assertNotNull($marketing);
		$this->assertSame($id, $marketing['campaign_id']);
	}

	#[Test]
	#[TestDox('runEmailCampaigns is a no-op when there are no non-root users')]
	#[Group('mantle2/campaigns')]
	public function runEmailCampaignsNoUsers(): void
	{
		// only system users (uid 0,1) exist; the loop returns early
		Drupal::state()->set('system.test_mail_collector', []);
		CampaignHelper::runEmailCampaigns();
		$this->assertSame([], Drupal::state()->get('system.test_mail_collector'));
	}

	#[Test]
	#[TestDox('runEmailCampaigns skips a campaign whose placeholders resolve to missing content')]
	#[Group('mantle2/campaigns')]
	public function runEmailCampaignsSkipsMissingContent(): void
	{
		$user = $this->cronUser();

		// no content seeded: the digest resolves to fallback text and the announcement's global
		// filter is false, so there is nothing worth sending
		CampaignHelper::runEmailCampaigns();
		$this->assertSame([], $this->sentCampaigns($user), 'an empty digest is not worth a send');
		$this->assertNull(RedisHelper::get($this->lastMarketingKey($user)));

		// the same user, same run conditions, with content behind the placeholders
		$this->seedAllContent($user);
		CampaignHelper::runEmailCampaigns();
		$this->assertNotEmpty($this->sentCampaigns($user), 'content, not eligibility, was missing');
	}

	/**
	 * One shared marker is what actually caps weekly volume.
	 *
	 * Cron already capped one send per user per run, but nothing capped sends across runs, so a
	 * user eligible for four campaigns received roughly one a day.
	 */
	#[Test]
	#[TestDox('the marketing cooldown blocks a second marketing send inside the window')]
	#[Group('mantle2/campaigns')]
	public function marketingCooldownBlocksSecondSend(): void
	{
		$user = $this->cronUser();
		$this->seedAllContent($user);

		CampaignHelper::runEmailCampaigns();
		$first = $this->sentCampaigns($user);
		$this->assertCount(1, $first);

		$marketing = RedisHelper::get($this->lastMarketingKey($user));
		$this->assertNotNull($marketing);
		$this->assertLessThan(
			CampaignHelper::MARKETING_COOLDOWN,
			Drupal::time()->getCurrentTime() - (int) $marketing['sent_at'],
			'the run left the user inside the cooldown',
		);

		// another eligible marketing campaign is waiting, and the shared marker is all that
		// stands in front of it
		CampaignHelper::runEmailCampaigns();
		$this->assertSame(array_keys($first), array_keys($this->sentCampaigns($user)));

		RedisHelper::delete($this->lastMarketingKey($user));
		CampaignHelper::runEmailCampaigns();
		$this->assertCount(
			2,
			$this->sentCampaigns($user),
			'the cooldown, not eligibility, blocked the second send',
		);
	}

	#[Test]
	#[TestDox('a transactional campaign is not blocked by the marketing cooldown')]
	#[Group('mantle2/campaigns')]
	public function transactionalCampaignIgnoresCooldown(): void
	{
		$campaign = $this->transactionalCampaign();
		$user = $this->cronUser(['field_email_verified' => false]);
		$time = Drupal::time()->getCurrentTime();

		// squarely inside the window a marketing candidate would be refused in
		RedisHelper::set(
			$this->lastMarketingKey($user),
			['sent_at' => $time - 3600, 'campaign_id' => 'anything'],
			CampaignHelper::MARKETING_COOLDOWN,
		);

		CampaignHelper::runEmailCampaigns();

		$this->assertSame([$campaign['id']], array_keys($this->sentCampaigns($user)));
		// and it does not stamp the shared marker, so it never delays a later marketing send
		$this->assertSame(
			'anything',
			RedisHelper::get($this->lastMarketingKey($user))['campaign_id'],
		);
	}

	/**
	 * A capped series has to outlive its own interval.
	 *
	 * The old TTL was `interval + 2 * variation + 86400`, which expired the counter before the cap
	 * could ever bind and made max_sends a silent no-op.
	 */
	#[Test]
	#[TestDox('max_sends ends the series and the stored count outlives the campaign interval')]
	#[Group('mantle2/campaigns')]
	public function maxSendsEndsTheSeries(): void
	{
		$campaign = $this->transactionalCampaign();
		$id = $campaign['id'];
		$cap = (int) $campaign['max_sends'];

		$user = $this->cronUser(['field_email_verified' => false]);
		$key = $this->markerKey($id, $user);
		$stale = Drupal::time()->getCurrentTime() - self::OLD_ACCOUNT_AGE;

		// one short of the cap and long overdue, so the only question is the counter
		RedisHelper::set($key, ['sent_at' => $stale, 'campaign_id' => $id, 'count' => $cap - 1]);
		CampaignHelper::runEmailCampaigns();

		$marker = RedisHelper::get($key);
		$this->assertSame($cap, $marker['count'], 'the send did not advance the counter');
		$this->assertGreaterThan($stale, $marker['sent_at']);

		$expiringTtl = (int) $campaign['interval'] + CampaignHelper::$variation * 2 + 86400;
		$this->assertGreaterThan(
			$expiringTtl,
			RedisHelper::ttl($key),
			'a counter that expires inside its own interval never reaches the cap',
		);

		// at the cap and overdue again: the series is over
		RedisHelper::set($key, ['sent_at' => $stale, 'campaign_id' => $id, 'count' => $cap]);
		CampaignHelper::runEmailCampaigns();

		$this->assertSame(
			['sent_at' => $stale, 'campaign_id' => $id, 'count' => $cap],
			RedisHelper::get($key),
			'the capped campaign sent again',
		);
	}

	/**
	 * Suppression is the cheapest lever on complaint rate there is.
	 *
	 * Google ties sending reputation to engaged recipients and Yahoo asks senders to monitor
	 * inactives, so a long-dormant account stops receiving marketing entirely.
	 */
	#[Test]
	#[TestDox('an account dormant beyond SUPPRESS_AFTER receives no marketing')]
	#[Group('mantle2/campaigns')]
	public function dormantAccountsReceiveNoMarketing(): void
	{
		$now = Drupal::time()->getCurrentTime();
		$dormant = $this->cronUser([], $now - CampaignHelper::SUPPRESS_AFTER - 86400);
		$reachable = $this->cronUser([], $now - CampaignHelper::SUPPRESS_AFTER + 86400);
		$this->seedAllContent($reachable);

		CampaignHelper::runEmailCampaigns();

		$this->assertSame(
			[],
			$this->sentCampaigns($dormant),
			'marketing reached a dormant account',
		);
		$this->assertNull(RedisHelper::get($this->lastMarketingKey($dormant)));
		$this->assertNotEmpty(
			$this->sentCampaigns($reachable),
			'suppression must stop at the SUPPRESS_AFTER boundary',
		);
	}

	/**
	 * Without a holdout there is no way to answer whether any of this is incremental.
	 *
	 * The exclusion is permanent and marketing-only; a transactional campaign still has to reach
	 * the same account.
	 */
	#[Test]
	#[TestDox('a holdout receives no marketing but still receives the transactional campaign')]
	#[Group('mantle2/campaigns')]
	public function holdoutReceivesTransactionalOnly(): void
	{
		$holdout = $this->cronUser([], null, true);
		$this->assertTrue(CampaignHelper::isMarketingHoldout($holdout));

		$control = $this->cronUser();
		$this->assertFalse(CampaignHelper::isMarketingHoldout($control));
		$this->seedAllContent($control);

		CampaignHelper::runEmailCampaigns();

		$this->assertSame([], $this->sentCampaigns($holdout), 'marketing reached a holdout');
		$this->assertNull(RedisHelper::get($this->lastMarketingKey($holdout)));
		$this->assertNotEmpty(
			$this->sentCampaigns($control),
			'the holdout divisor caught everyone',
		);

		// the same account, now eligible for the transactional campaign
		$holdout->set('field_email_verified', false);
		$holdout->save();
		CampaignHelper::runEmailCampaigns();

		$this->assertSame(
			[$this->transactionalCampaign()['id']],
			array_keys($this->sentCampaigns($holdout)),
		);
	}

	// #endregion
}
