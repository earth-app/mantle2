<?php

namespace Drupal\Tests\mantle2\Integration;

use Drupal\mantle2\Commands\Mantle2Commands;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\StagingHelper;
use Drupal\mantle2\Service\SubscriptionsHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandsTest extends IntegrationTestBase
{
	private Mantle2Commands $command;
	private BufferedOutput $out;

	protected function setUp(): void
	{
		parent::setUp();
		$this->command = new Mantle2Commands();
		$this->out = new BufferedOutput();
		$this->command->setOutput($this->out);
	}

	private function flush(): string
	{
		return $this->out->fetch();
	}

	// hi

	#[Test]
	#[TestDox('hi writes the hello world greeting')]
	#[Group('mantle2/drush')]
	public function hi(): void
	{
		$this->command->hi();
		$this->assertStringContainsString('Hello, World!', $this->flush());
	}

	// sendEmailVerification

	#[Test]
	#[TestDox('send-email-verification reports a missing user without throwing')]
	#[Group('mantle2/drush')]
	public function sendEmailVerificationMissingUser(): void
	{
		$this->command->sendEmailVerification('999999');
		$this->assertStringContainsString('not found', $this->flush());
	}

	#[Test]
	#[TestDox('send-email-verification short circuits when the email is already verified')]
	#[Group('mantle2/drush')]
	public function sendEmailVerificationAlreadyVerified(): void
	{
		$user = $this->createUser();
		$user->set('field_email_verified', true);
		$user->save();

		$this->command->sendEmailVerification('@' . $user->getAccountName());
		$this->assertStringContainsString('already verified', $this->flush());
	}

	#[Test]
	#[TestDox('send-email-verification sends the code and confirms delivery')]
	#[Group('mantle2/drush')]
	public function sendEmailVerificationSends(): void
	{
		$user = $this->createUser();
		$user->set('field_email_verified', false);
		$user->save();

		$this->command->sendEmailVerification($user->id());
		$text = $this->flush();
		$this->assertStringContainsString('Sending email verification', $text);
		$this->assertStringContainsString('Email verification sent', $text);

		$mail = \Drupal::state()->get('system.test_mail_collector');
		$this->assertNotEmpty($mail);
	}

	// listEmailCampaigns

	#[Test]
	#[TestDox('list-email-campaigns prints each campaign id and title')]
	#[Group('mantle2/drush')]
	public function listEmailCampaigns(): void
	{
		$this->command->listEmailCampaigns();
		$text = $this->flush();
		$this->assertStringContainsString('Available Email Campaigns:', $text);
		$this->assertStringContainsString('- (0)', $text);
	}

	// sendEmailCampaign

	#[Test]
	#[TestDox('send-email-campaign reports a missing user')]
	#[Group('mantle2/drush')]
	public function sendEmailCampaignMissingUser(): void
	{
		$this->command->sendEmailCampaign('welcome_back', '@nobody-here');
		$this->assertStringContainsString('not found', $this->flush());
	}

	#[Test]
	#[TestDox('send-email-campaign attempts delivery and reports the outcome')]
	#[Group('mantle2/drush')]
	public function sendEmailCampaignAttempts(): void
	{
		$user = $this->createUser();
		$campaigns = \Drupal\mantle2\Service\CampaignHelper::getCampaigns();
		$id = $campaigns[0]['id'] ?? 'welcome_back';

		$this->command->sendEmailCampaign($id, $user->id());
		$text = $this->flush();
		$this->assertStringContainsString("Sending email campaign '$id'", $text);
		// either success or failure line is acceptable; both prove the branch ran
		$this->assertMatchesRegularExpression(
			'/(sent to user|Failed to send email campaign)/',
			$text,
		);
	}

	// addNotification

	#[Test]
	#[TestDox('add-notification reports a missing user')]
	#[Group('mantle2/drush')]
	public function addNotificationMissingUser(): void
	{
		$this->command->addNotification('@ghost');
		$this->assertStringContainsString('not found', $this->flush());
	}

	#[Test]
	#[TestDox('add-notification persists a notification with the given options')]
	#[Group('mantle2/drush')]
	public function addNotificationPersists(): void
	{
		$user = $this->createUser();

		$this->command->addNotification($user->id(), [
			'title' => 'Hello There',
			'type' => 'warning',
			'message' => 'A Test Message',
			'link' => null,
			'source' => 'drush',
		]);

		$text = $this->flush();
		$this->assertStringContainsString('Adding notification', $text);
		$this->assertStringContainsString('Notification added', $text);

		$fresh = UsersHelper::findById((int) $user->id());
		$notifications = UsersHelper::getNotifications($fresh);
		$this->assertNotEmpty($notifications);
		$found = false;
		foreach ($notifications as $n) {
			if ($n->getTitle() === 'Hello There') {
				$found = true;
				$this->assertSame('warning', $n->getType());
			}
		}
		$this->assertTrue($found, 'the added notification should be persisted');
	}

	// createUserTrial

	#[Test]
	#[TestDox('create-user-trial reports a missing user')]
	#[Group('mantle2/drush')]
	public function createUserTrialMissingUser(): void
	{
		$this->command->createUserTrial('@nope', 'pro', 7);
		$this->assertStringContainsString('not found', $this->flush());
	}

	#[Test]
	#[TestDox('create-user-trial rejects an invalid tier type')]
	#[Group('mantle2/drush')]
	public function createUserTrialInvalidTier(): void
	{
		$user = $this->createUser();
		$this->command->createUserTrial($user->id(), 'platinum', 7);
		$text = $this->flush();
		$this->assertStringContainsString("Invalid tier type 'platinum'", $text);
		$this->assertStringContainsString('pro', $text);
	}

	#[Test]
	#[TestDox('create-user-trial refuses to trial the tier the user already has')]
	#[Group('mantle2/drush')]
	public function createUserTrialSameTier(): void
	{
		$user = $this->createUser();
		// default account type is FREE (ordinal 0)
		$this->command->createUserTrial($user->id(), 'free', 7);
		$this->assertStringContainsString('already on tier', $this->flush());
	}

	#[Test]
	#[TestDox('create-user-trial provisions an upgrade trial and confirms it')]
	#[Group('mantle2/drush')]
	public function createUserTrialUpgrades(): void
	{
		$user = $this->createUser();
		$this->command->createUserTrial($user->id(), 'pro', 14);
		$text = $this->flush();
		$this->assertStringContainsString('Created account trial', $text);
		$this->assertStringContainsString('14 days', $text);

		$fresh = UsersHelper::findById((int) $user->id());
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType($fresh));
	}

	// #region sync

	/** every mantle2-owned table, so a wipe anywhere shows up */
	private const CUSTOM_TABLES = [
		'push_tokens',
		'mantle2_api_keys',
		'mantle2_subscriptions',
		'mantle2_trial_codes',
		'mantle2_trial_code_redemptions',
		'mantle2_staged_activities',
	];

	private function seedEveryTable(int $uid): void
	{
		$db = $this->container->get('database');
		$now = time();

		$db->insert('push_tokens')
			->fields(['user_id' => $uid, 'platform' => 'ios', 'token' => 'tok', 'updated' => $now])
			->execute();
		$db->insert('mantle2_api_keys')
			->fields([
				'key_id' => 'EA26TESTKEY',
				'user_id' => $uid,
				'token_hash' => str_repeat('a', 64),
				'token_prefix' => 'EA26TESTKEY',
				'name' => 'CI key',
				'scopes' => json_encode([]),
				'created_at' => $now,
			])
			->execute();
		$db->insert('mantle2_subscriptions')
			->fields([
				'user_id' => $uid,
				'provider' => 'stripe',
				'tier' => 'PRO',
				'status' => 'active',
				'created' => $now,
				'updated' => $now,
			])
			->execute();
		$db->insert('mantle2_trial_codes')
			->fields([
				'code' => 'TRIALCODE',
				'tier' => 'PRO',
				'days' => 7,
				'max_redemptions' => 1,
				'created_by' => $uid,
				'created' => $now,
			])
			->execute();
		$db->insert('mantle2_trial_code_redemptions')
			->fields(['code' => 'TRIALCODE', 'user_id' => $uid, 'redeemed_at' => $now])
			->execute();
	}

	private function rowCounts(): array
	{
		$db = $this->container->get('database');
		$counts = [];
		foreach (self::CUSTOM_TABLES as $table) {
			$counts[$table] = (int) $db->select($table, 't')->countQuery()->execute()->fetchField();
		}

		return $counts;
	}

	#[Test]
	#[TestDox('sync creates every custom table and reports what it created')]
	#[Group('mantle2/drush')]
	public function syncCreatesTables(): void
	{
		$schema = $this->container->get('database')->schema();
		foreach (self::CUSTOM_TABLES as $table) {
			if ($schema->tableExists($table)) {
				$schema->dropTable($table);
			}
		}

		$this->command->sync(['skip-content' => true]);
		$output = $this->flush();

		foreach (self::CUSTOM_TABLES as $table) {
			$this->assertTrue($schema->tableExists($table), "$table was not created");
			$this->assertStringContainsString($table, $output);
		}
		$this->assertStringContainsString('User fields synced.', $output);
		$this->assertStringContainsString('mantle2:sync complete.', $output);
	}

	#[Test]
	#[TestDox('sync is a no-op on a healthy install and never drops a row')]
	#[Group('mantle2/drush')]
	public function syncPreservesEveryRow(): void
	{
		$user = $this->createUser();
		$this->seedEveryTable((int) $user->id());
		StagingHelper::stage(
			new Activity('sync_probe', 'Sync Probe', ['SPORT'], 'Seeded before the sync runs.'),
			$user,
		);

		$before = $this->rowCounts();
		foreach ($before as $table => $count) {
			$this->assertSame(1, $count, "$table was not seeded");
		}

		$this->command->sync(['skip-content' => true]);

		// this is the whole point of replacing `drush un && drush en`
		$this->assertSame($before, $this->rowCounts());
		$this->assertStringContainsString('already present', $this->flush());
	}

	#[Test]
	#[TestDox('sync preserves nodes, content types, and user field values')]
	#[Group('mantle2/drush')]
	public function syncPreservesContent(): void
	{
		$this->container->get('module_handler')->loadInclude('mantle2', 'install');
		mantle2_install_content();

		$user = $this->createUser();
		$user->set('field_verified_publisher', true);
		$user->save();

		ActivityHelper::createActivity(
			new Activity('persisted_one', 'Persisted One', ['SPORT'], 'Survives the sync.'),
			$user,
		);

		$this->command->sync();

		$this->assertNotNull(NodeType::load('activity'));
		$this->assertNotNull(ActivityHelper::getNodeByActivityId('persisted_one'));
		$this->assertSame(
			'Persisted One',
			ActivityHelper::getActivity('persisted_one')?->getName(),
		);

		$fresh = UsersHelper::findById((int) $user->id());
		$this->assertTrue((bool) $fresh->get('field_verified_publisher')->value);
	}

	#[Test]
	#[TestDox('sync is idempotent across repeated runs')]
	#[Group('mantle2/drush')]
	public function syncIsIdempotent(): void
	{
		$user = $this->createUser();
		$this->seedEveryTable((int) $user->id());
		$before = $this->rowCounts();

		$this->command->sync(['skip-content' => true]);
		$this->command->sync(['skip-content' => true]);
		$this->command->sync(['skip-content' => true]);

		$this->assertSame($before, $this->rowCounts());
	}

	#[Test]
	#[TestDox('sync recreates only the table that went missing')]
	#[Group('mantle2/drush')]
	public function syncRecreatesOnlyTheMissingTable(): void
	{
		$user = $this->createUser();
		$this->seedEveryTable((int) $user->id());

		$schema = $this->container->get('database')->schema();
		$schema->dropTable('mantle2_staged_activities');

		$this->command->sync(['skip-content' => true]);
		$output = $this->flush();

		$this->assertTrue($schema->tableExists('mantle2_staged_activities'));
		$this->assertStringContainsString('mantle2_staged_activities', $output);
		// the healthy tables keep their rows rather than being rebuilt
		$this->assertSame(1, $this->rowCounts()['push_tokens']);
		$this->assertSame(1, $this->rowCounts()['mantle2_api_keys']);
	}

	#[Test]
	#[TestDox('skip-content leaves node content types untouched')]
	#[Group('mantle2/drush')]
	public function syncSkipContent(): void
	{
		$this->command->sync(['skip-content' => true]);
		$output = $this->flush();

		$this->assertStringNotContainsString('Content types', $output);
		$this->assertStringContainsString('User fields synced.', $output);
	}

	// #endregion

	// #region Email Suppression

	#[Test]
	#[TestDox('suppress-email suppresses every address in the list with the given reason')]
	#[Group('mantle2/drush')]
	public function suppressEmailList(): void
	{
		$this->command->suppressEmail('a@example.com, b@example.com', [
			'reason' => 'mailbox_user_not_found',
		]);
		$output = $this->flush();

		$this->assertStringContainsString(
			'Suppressed a@example.com (mailbox_user_not_found)',
			$output,
		);
		$this->assertStringContainsString(
			'Suppressed b@example.com (mailbox_user_not_found)',
			$output,
		);
		$this->assertStringContainsString('Suppressed 2 address(es).', $output);
		$this->assertTrue(UsersHelper::isEmailUndeliverable('a@example.com'));
		$this->assertTrue(UsersHelper::isEmailUndeliverable('b@example.com'));
	}

	#[Test]
	#[TestDox('suppress-email skips blank entries and defaults the reason to manual')]
	#[Group('mantle2/drush')]
	public function suppressEmailSkipsBlanks(): void
	{
		$this->command->suppressEmail('only@example.com,, ,');
		$output = $this->flush();

		$this->assertStringContainsString('Suppressed only@example.com (manual)', $output);
		$this->assertStringContainsString('Suppressed 1 address(es).', $output);
	}

	#[Test]
	#[TestDox('unsuppress-email releases an address so sending resumes')]
	#[Group('mantle2/drush')]
	public function unsuppressEmailReleases(): void
	{
		UsersHelper::markEmailUndeliverable('back@example.com', 'bounce');
		$this->assertTrue(UsersHelper::isEmailUndeliverable('back@example.com'));

		$this->command->unsuppressEmail('back@example.com');

		$this->assertStringContainsString('Released back@example.com', $this->flush());
		$this->assertFalse(UsersHelper::isEmailUndeliverable('back@example.com'));
	}

	// #endregion

	// #region Trial Codes

	#[Test]
	#[TestDox('create-trial-code mints a redeemable code and echoes its terms')]
	#[Group('mantle2/drush')]
	public function createTrialCode(): void
	{
		$this->command->createTrialCode(['tier' => 'writer', 'days' => 14, 'max' => 25]);
		$output = $this->flush();

		$this->assertStringContainsString('Created trial code:', $output);
		$this->assertStringContainsString('Tier: writer, Days: 14, Max: 25', $output);

		$codes = SubscriptionsHelper::listTrialCodes();
		$this->assertCount(1, $codes);
		$this->assertSame('writer', $codes[0]['tier']);
		$this->assertSame(14, $codes[0]['days']);
	}

	#[Test]
	#[TestDox('create-trial-code rejects a tier that cannot be sold')]
	#[Group('mantle2/drush')]
	public function createTrialCodeRejectsBadTier(): void
	{
		foreach (['free', 'administrator', 'nonsense'] as $tier) {
			$this->command->createTrialCode(['tier' => $tier, 'days' => 30, 'max' => 0]);
			$this->assertStringContainsString("Invalid tier '$tier'", $this->flush());
		}

		$this->assertSame([], SubscriptionsHelper::listTrialCodes());
	}

	#[Test]
	#[TestDox('create-trial-code rejects an unparseable expiry date')]
	#[Group('mantle2/drush')]
	public function createTrialCodeRejectsBadExpiry(): void
	{
		$this->command->createTrialCode([
			'tier' => 'pro',
			'days' => 30,
			'max' => 0,
			'expires' => 'not-a-date',
		]);

		$this->assertStringContainsString("Invalid expires date 'not-a-date'", $this->flush());
		$this->assertSame([], SubscriptionsHelper::listTrialCodes());
	}

	#[Test]
	#[TestDox('create-trial-code accepts an ISO expiry date')]
	#[Group('mantle2/drush')]
	public function createTrialCodeAcceptsExpiry(): void
	{
		$this->command->createTrialCode([
			'tier' => 'pro',
			'days' => 30,
			'max' => 0,
			'expires' => '2030-12-31',
		]);

		$this->assertStringContainsString('Created trial code:', $this->flush());
		$codes = SubscriptionsHelper::listTrialCodes();
		$this->assertCount(1, $codes);
		$this->assertNotNull($codes[0]['expires_at']);
	}

	#[Test]
	#[TestDox('list-trial-codes reports an empty list and then every minted code')]
	#[Group('mantle2/drush')]
	public function listTrialCodes(): void
	{
		$this->command->listTrialCodes();
		$this->assertStringContainsString('No trial codes found.', $this->flush());

		$this->command->createTrialCode(['tier' => 'pro', 'days' => 30, 'max' => 0]);
		$code = SubscriptionsHelper::listTrialCodes()[0]['code'];
		$this->flush();

		$this->command->listTrialCodes();
		$output = $this->flush();

		$this->assertStringContainsString('Trial Codes:', $output);
		$this->assertStringContainsString("- $code [active] tier=pro days=30", $output);
		$this->assertStringContainsString('redemptions=0/unlimited', $output);
	}

	#[Test]
	#[TestDox('list-trial-codes shows a capped code with its redemption limit')]
	#[Group('mantle2/drush')]
	public function listTrialCodesShowsCap(): void
	{
		$this->command->createTrialCode(['tier' => 'organizer', 'days' => 7, 'max' => 3]);
		$this->flush();

		$this->command->listTrialCodes();

		$this->assertStringContainsString('redemptions=0/3', $this->flush());
	}

	// #endregion

	// #region Refunds

	#[Test]
	#[TestDox('refund-user reports a missing user')]
	#[Group('mantle2/drush')]
	public function refundUserMissing(): void
	{
		$this->command->refundUser('999999');
		$this->assertStringContainsString('not found', $this->flush());
	}

	#[Test]
	#[TestDox('refund-user reports a user with nothing to refund')]
	#[Group('mantle2/drush')]
	public function refundUserWithoutSubscription(): void
	{
		$user = $this->createUser();

		$this->command->refundUser('@' . $user->getAccountName());

		$this->assertStringContainsString('has no active subscription to refund', $this->flush());
	}

	#[Test]
	#[TestDox('refund-user refunds an active subscription and reverts the account to Free')]
	#[Group('mantle2/drush')]
	public function refundUserRefundsActiveSubscription(): void
	{
		$this->configureStripe();
		SubscriptionsHelper::setClientOverride($this->newFakeStripe());

		$user = $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::PRO,
				AccountType::cases(),
				true,
			),
		]);
		$this->seedSubscription((int) $user->id());

		$this->command->refundUser('@' . $user->getAccountName(), ['reason' => 'Support request']);

		$this->assertStringContainsString('Refunded and canceled subscription', $this->flush());
		$this->assertSame('refunded', $this->subscriptionRow((int) $user->id())['status']);
		$this->assertSame(
			AccountType::FREE,
			UsersHelper::getAccountType(UsersHelper::findById((int) $user->id())),
		);

		SubscriptionsHelper::setClientOverride(null);
	}

	// #endregion
}
