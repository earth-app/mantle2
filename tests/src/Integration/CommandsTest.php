<?php

namespace Drupal\Tests\mantle2\Integration;

use Drupal\mantle2\Commands\Mantle2Commands;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\StagingHelper;
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
}
