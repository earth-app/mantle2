<?php

namespace Drupal\Tests\mantle2\Integration;

use Drupal\mantle2\Commands\Mantle2Commands;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\UsersHelper;
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
}
