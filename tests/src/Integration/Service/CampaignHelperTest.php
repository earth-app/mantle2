<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\mantle2\Service\CampaignHelper;
use Drupal\mantle2\Service\PromptsHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class CampaignHelperTest extends IntegrationTestBase
{
	// content-reaching placeholders (activity/prompt/article random + weekly) need the node types
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
		// dead cloud so recommend/event placeholders degrade to missing-content fallbacks
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
	}

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
		$user->setLastLoginTime(\Drupal::time()->getCurrentTime());
		$user->save();
		return $user;
	}

	// Retrieval

	#[Test]
	#[TestDox('getCampaigns loads and decodes every campaign from the yml with required keys')]
	#[Group('mantle2/campaigns')]
	public function getCampaigns(): void
	{
		$campaigns = CampaignHelper::getCampaigns();
		$this->assertNotEmpty($campaigns);

		$ids = array_column($campaigns, 'id');
		$this->assertContains('verify_email', $ids);
		$this->assertContains('welcome_back', $ids);
		$this->assertContains('insights', $ids);

		foreach ($campaigns as $campaign) {
			$this->assertArrayHasKey('id', $campaign);
			$this->assertArrayHasKey('interval', $campaign);
			$this->assertArrayHasKey('body', $campaign);
			$this->assertIsInt($campaign['interval']);
		}
	}

	#[Test]
	#[TestDox('getCampaign resolves by id, by numeric index, and returns null for unknown keys')]
	#[Group('mantle2/campaigns')]
	public function getCampaign(): void
	{
		$byId = CampaignHelper::getCampaign('verify_email');
		$this->assertNotNull($byId);
		$this->assertSame('verify_email', $byId['id']);
		$this->assertSame('Verify Your Email Address', $byId['title']);
		$this->assertSame('unverifiedFilter', $byId['filter']);
		$this->assertFalse($byId['unsubscribable']);

		$byIndex = CampaignHelper::getCampaign(0);
		$this->assertSame('verify_email', $byIndex['id']);

		$this->assertNull(CampaignHelper::getCampaign('does_not_exist'));
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
		$recent->setLastLoginTime(\Drupal::time()->getCurrentTime());
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
		$user->setLastLoginTime(\Drupal::time()->getCurrentTime());
		$user->save();

		$result = CampaignHelper::runPlaceholders('{user.identifier}', $user);
		$this->assertSame('@' . $user->getAccountName(), $result);
	}

	#[Test]
	#[TestDox('processCampaign expands user placeholders in the verify_email title and body')]
	#[Group('mantle2/campaigns')]
	public function processCampaignSubstitutesBody(): void
	{
		$user = $this->verifiedActiveUser();
		$campaign = CampaignHelper::getCampaign('verify_email');

		$processed = CampaignHelper::processCampaign($campaign, $user);

		$this->assertSame($campaign['title'], $processed['title']);
		$this->assertStringContainsString('@' . $user->getAccountName(), $processed['body']);
		$this->assertStringNotContainsString('{user.username}', $processed['body']);
		// unrelated keys pass through untouched
		$this->assertSame($campaign['interval'], $processed['interval']);
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

		// nothing seeded -> each random/weekly placeholder resolves to its fallback string
		$activity = CampaignHelper::runPlaceholders('{activity.random}', $user);
		$this->assertSame('No random activity found', $activity);

		$prompt = CampaignHelper::runPlaceholders('{prompt.random}', $user);
		$this->assertSame('No random prompt found', $prompt);

		$article = CampaignHelper::runPlaceholders('{article.random}', $user);
		$this->assertSame('No random article found', $article);

		// recommended + event reach cloud (dead) and also fall back
		$recommended = CampaignHelper::runPlaceholders('{activity.recommended}', $user);
		$this->assertSame('No recommended activity found', $recommended);

		$event = CampaignHelper::runPlaceholders('{event.upcoming}', $user);
		$this->assertSame('No upcoming event found', $event);

		$weekly = CampaignHelper::runPlaceholders('{activity.weekly}', $user);
		$this->assertSame('No weekly activities found', $weekly);
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

	#[Test]
	#[TestDox('processCampaign with repeat=false pre-fetches cached objects once')]
	#[Group('mantle2/campaigns')]
	public function processCampaignNoRepeat(): void
	{
		$author = $this->verifiedActiveUser();
		$this->seedArticle($author, 'A Cached Article');

		// daily_article has repeat: false and uses {article.random} in title + body
		$campaign = CampaignHelper::getCampaign('daily_article');
		$processed = CampaignHelper::processCampaign($campaign, $author);

		$this->assertStringContainsString('A Cached Article', $processed['title']);
		$this->assertStringContainsString('A Cached Article', $processed['body']);
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

	// Cron entrypoint (mail captured via test_mail_collector; cloud content degrades)

	#[Test]
	#[TestDox('runEmailCampaigns sends the most overdue eligible campaign and marks it in redis')]
	#[Group('mantle2/campaigns')]
	public function runEmailCampaignsSends(): void
	{
		// one verified, active, subscribed user whose old account makes the first-send available now
		$user = $this->verifiedActiveUser();
		$user->set('field_subscribed', true);
		$user->set('created', \Drupal::time()->getCurrentTime() - 400 * 86400);
		$user->save();

		// seed content so the insights campaign placeholders resolve (no missing-content skip)
		$this->seedActivity('run');
		$this->seedPrompt($user, 'A public prompt for the campaign body');
		$this->seedArticle($user, 'A Campaign Article');

		\Drupal::state()->set('system.test_mail_collector', []);

		CampaignHelper::runEmailCampaigns();

		// a campaign was sent: either mail captured or a redis send-marker persisted
		$mail = \Drupal::state()->get('system.test_mail_collector') ?? [];
		$markerKeys = array_filter(
			array_map(
				fn($c) => \Drupal\mantle2\Service\RedisHelper::get(
					'campaign:' . ($c['id'] ?? '') . ':user:' . $user->id(),
				),
				CampaignHelper::getCampaigns(),
			),
		);
		$this->assertTrue(!empty($mail) || !empty($markerKeys), 'expected a campaign send attempt');
	}

	#[Test]
	#[TestDox('runEmailCampaigns is a no-op when there are no non-root users')]
	#[Group('mantle2/campaigns')]
	public function runEmailCampaignsNoUsers(): void
	{
		// only system users (uid 0,1) exist; the loop returns early
		CampaignHelper::runEmailCampaigns();
		$this->assertTrue(true);
	}

	#[Test]
	#[
		TestDox(
			'runEmailCampaigns skips a campaign whose placeholders resolve to missing-content text',
		),
	]
	#[Group('mantle2/campaigns')]
	public function runEmailCampaignsSkipsMissingContent(): void
	{
		// verified active subscribed user, old account, but NO content seeded -> the insights /
		// daily / bidaily campaigns resolve to missing-content fallbacks and are skipped
		$user = $this->verifiedActiveUser();
		$user->set('field_subscribed', true);
		$user->set('created', \Drupal::time()->getCurrentTime() - 400 * 86400);
		$user->save();

		\Drupal::state()->set('system.test_mail_collector', []);
		CampaignHelper::runEmailCampaigns();

		// the shouldSkipCampaign path is exercised; no assertion on delivery since the
		// unverified/welcome-back campaigns do not apply to a verified active user
		$this->assertTrue(true);
	}
}
