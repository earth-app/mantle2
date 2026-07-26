<?php

namespace Drupal\Tests\mantle2\Integration\Service\Users;

use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\VerifiedPublisherState;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\StagingHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class VerifiedPublisherTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	private function userOf(AccountType $type, bool $verified = false): UserInterface
	{
		$user = $this->createUser([
			'field_account_type' => (string) array_search($type, AccountType::cases(), true),
		]);
		$user->set('field_verified_publisher', $verified);
		$user->save();

		return $user;
	}

	private function application(): array
	{
		return [
			'reason' => str_repeat('We run a large local climbing chapter. ', 2),
			'organization' => 'Bay Area Climbing Collective',
			'links' => ['https://example.org'],
		];
	}

	// #region isVerifiedPublisher

	public static function truthTableProvider(): array
	{
		return [
			'free unverified' => [AccountType::FREE, false, false],
			'free flagged' => [AccountType::FREE, true, false],
			'pro flagged' => [AccountType::PRO, true, false],
			'writer flagged' => [AccountType::WRITER, true, false],
			'organizer unverified' => [AccountType::ORGANIZER, false, false],
			'organizer verified' => [AccountType::ORGANIZER, true, true],
			'administrator unverified' => [AccountType::ADMINISTRATOR, false, true],
		];
	}

	#[Test]
	#[TestDox('isVerifiedPublisher requires the flag AND an Organizer tier, admins excepted')]
	#[Group('mantle2/users')]
	#[DataProvider('truthTableProvider')]
	public function testIsVerifiedPublisher(AccountType $type, bool $flag, bool $expected): void
	{
		$this->assertSame($expected, UsersHelper::isVerifiedPublisher($this->userOf($type, $flag)));
	}

	#[Test]
	#[TestDox('The cloud service account is implicitly a verified publisher')]
	#[Group('mantle2/users')]
	public function testCloudIsImplicitlyVerified(): void
	{
		$this->assertTrue(UsersHelper::isVerifiedPublisher(UsersHelper::cloud()));
	}

	#[Test]
	#[
		TestDox(
			'Downgrading below Organizer revokes the badge implicitly and an upgrade restores it',
		),
	]
	#[Group('mantle2/users')]
	public function testDowngradeImplicitlyRevokes(): void
	{
		$user = $this->userOf(AccountType::ORGANIZER, true);
		$this->assertTrue(UsersHelper::isVerifiedPublisher($user));

		$user->set(
			'field_account_type',
			GeneralHelper::findOrdinal(AccountType::cases(), AccountType::PRO),
		);
		$user->save();
		$this->assertFalse(UsersHelper::isVerifiedPublisher($user));

		$user->set(
			'field_account_type',
			GeneralHelper::findOrdinal(AccountType::cases(), AccountType::ORGANIZER),
		);
		$user->save();
		$this->assertTrue(UsersHelper::isVerifiedPublisher($user));
	}

	#[Test]
	#[
		TestDox(
			'requireVerifiedPublisher distinguishes a non-organizer from an unapproved organizer',
		),
	]
	#[Group('mantle2/users')]
	public function testRequireVerifiedPublisher(): void
	{
		$this->assertNull(
			UsersHelper::requireVerifiedPublisher($this->userOf(AccountType::ORGANIZER, true)),
		);

		$free = UsersHelper::requireVerifiedPublisher($this->userOf(AccountType::FREE));
		$this->assertStringContainsString('Organizer account', $this->decode($free)['message']);

		$organizer = UsersHelper::requireVerifiedPublisher(
			$this->userOf(AccountType::ORGANIZER, false),
		);
		$this->assertStringContainsString(
			'Verified Publisher status is required',
			$this->decode($organizer)['message'],
		);
	}

	// #endregion

	// #region State storage

	#[Test]
	#[TestDox('The state field round-trips through its ordinal, defaulting to NONE')]
	#[Group('mantle2/users')]
	public function testStateOrdinalRoundTrip(): void
	{
		$user = $this->userOf(AccountType::ORGANIZER);
		$this->assertSame(
			VerifiedPublisherState::NONE,
			UsersHelper::getVerifiedPublisherState($user),
		);

		foreach (VerifiedPublisherState::cases() as $case) {
			$user->set(
				'field_verified_publisher_state',
				GeneralHelper::findOrdinal(VerifiedPublisherState::cases(), $case),
			);
			$user->save();
			$this->assertSame($case, UsersHelper::getVerifiedPublisherState($user));
		}
	}

	// #endregion

	// #region Apply

	#[Test]
	#[TestDox('Applying stores the application, flips to pending, and emails administrators')]
	#[Group('mantle2/users')]
	public function testApply(): void
	{
		$this->userOf(AccountType::ADMINISTRATOR);
		$user = $this->userOf(AccountType::ORGANIZER);

		$payload = UsersHelper::applyForVerifiedPublisher($user, $this->application());

		$this->assertIsArray($payload);
		$this->assertSame('pending', $payload['state']);
		$this->assertFalse($payload['verified']);
		$this->assertSame('Bay Area Climbing Collective', $payload['organization']);
		$this->assertNotNull($payload['applied_at']);
		$this->assertNull($payload['reviewed_at']);

		$this->assertNotEmpty($this->mailOf('mantle2_verified_publisher_submitted'));
	}

	#[Test]
	#[TestDox('Applying is refused for non-organizers, duplicates, and approved accounts')]
	#[Group('mantle2/users')]
	public function testApplyGuards(): void
	{
		$this->assertSame(
			'not_organizer',
			UsersHelper::applyForVerifiedPublisher(
				$this->userOf(AccountType::FREE),
				$this->application(),
			),
		);

		$user = $this->userOf(AccountType::ORGANIZER);
		UsersHelper::applyForVerifiedPublisher($user, $this->application());
		$this->assertSame(
			'already_pending',
			UsersHelper::applyForVerifiedPublisher($user, $this->application()),
		);

		UsersHelper::decideVerifiedPublisher(
			$user,
			$this->userOf(AccountType::ADMINISTRATOR),
			'approve',
		);
		$this->assertSame(
			'already_approved',
			UsersHelper::applyForVerifiedPublisher($user, $this->application()),
		);
	}

	#[Test]
	#[TestDox('A denied applicant is held off until the re-apply window passes')]
	#[Group('mantle2/users')]
	public function testReapplyCooldown(): void
	{
		$admin = $this->userOf(AccountType::ADMINISTRATOR);
		$user = $this->userOf(AccountType::ORGANIZER);

		UsersHelper::applyForVerifiedPublisher($user, $this->application());
		$denied = UsersHelper::decideVerifiedPublisher($user, $admin, 'deny', 'needs more detail');
		$this->assertSame('denied', $denied['state']);
		$this->assertNotNull($denied['can_reapply_at']);

		$this->assertSame(
			'cooldown',
			UsersHelper::applyForVerifiedPublisher($user, $this->application()),
		);

		// backdate the review past the cooldown
		$application = UsersHelper::getVerifiedPublisherApplication($user);
		$application['reviewed_at'] = time() - UsersHelper::PUBLISHER_REAPPLY_DELAY - 10;
		UsersHelper::setVerifiedPublisherApplication($user, $application);
		$user->save();

		$this->assertIsArray(UsersHelper::applyForVerifiedPublisher($user, $this->application()));
	}

	// #endregion

	// #region Decide

	#[Test]
	#[TestDox('Approving sets the flag and notifies the applicant')]
	#[Group('mantle2/users')]
	public function testApprove(): void
	{
		$admin = $this->userOf(AccountType::ADMINISTRATOR);
		$user = $this->userOf(AccountType::ORGANIZER);
		UsersHelper::applyForVerifiedPublisher($user, $this->application());

		$payload = UsersHelper::decideVerifiedPublisher(
			$user,
			$admin,
			'approve',
			'verified roster',
		);

		$this->assertSame('approved', $payload['state']);
		$this->assertTrue($payload['verified']);
		$this->assertSame('verified roster', $payload['notes']);
		$this->assertTrue(UsersHelper::isVerifiedPublisher($user));
		$this->assertNotEmpty($this->mailOf('mantle2_verified_publisher_approved'));
	}

	#[Test]
	#[TestDox('An unknown action is rejected')]
	#[Group('mantle2/users')]
	public function testInvalidAction(): void
	{
		$this->assertSame(
			'invalid_action',
			UsersHelper::decideVerifiedPublisher(
				$this->userOf(AccountType::ORGANIZER),
				$this->userOf(AccountType::ADMINISTRATOR),
				'explode',
			),
		);
	}

	#[Test]
	#[
		TestDox(
			'Revoking clears the flag, denies pending organizer rows, and sends one summary email',
		),
	]
	#[Group('mantle2/users')]
	public function testRevokeCascadesToStaging(): void
	{
		$admin = $this->userOf(AccountType::ADMINISTRATOR);
		$user = $this->userOf(AccountType::ORGANIZER, true);

		StagingHelper::stage(
			new Activity('revoke_one', 'Revoke One', ['SPORT'], 'Pending submission.'),
			$user,
		);
		StagingHelper::stage(
			new Activity('revoke_two', 'Revoke Two', ['SPORT'], 'Pending submission.'),
			$user,
		);

		$payload = UsersHelper::decideVerifiedPublisher($user, $admin, 'revoke', 'policy breach');

		$this->assertSame('revoked', $payload['state']);
		$this->assertFalse($payload['verified']);
		$this->assertSame(2, $payload['revoked_staged']);
		$this->assertFalse(UsersHelper::isVerifiedPublisher($user));
		$this->assertSame(0, StagingHelper::countPendingFor((int) $user->id()));

		// one summary mail, never one per withdrawn submission
		$this->assertCount(1, $this->mailOf('mantle2_verified_publisher_revoked'));
	}

	#[Test]
	#[TestDox('History is appended per decision and capped at ten entries')]
	#[Group('mantle2/users')]
	public function testHistoryCap(): void
	{
		$admin = $this->userOf(AccountType::ADMINISTRATOR);
		$user = $this->userOf(AccountType::ORGANIZER);

		for ($i = 0; $i < 8; $i++) {
			UsersHelper::decideVerifiedPublisher($user, $admin, 'approve');
			UsersHelper::decideVerifiedPublisher($user, $admin, 'revoke');
		}

		$history = UsersHelper::getVerifiedPublisherApplication($user)['history'];
		$this->assertCount(10, $history);
		$this->assertSame('revoked', end($history)['state']);
	}

	// #endregion

	// #region Listing

	#[Test]
	#[TestDox('The admin list defaults to pending applications and honours a state filter')]
	#[Group('mantle2/users')]
	public function testListApplications(): void
	{
		$admin = $this->userOf(AccountType::ADMINISTRATOR);
		$pending = $this->userOf(AccountType::ORGANIZER);
		$approved = $this->userOf(AccountType::ORGANIZER);

		UsersHelper::applyForVerifiedPublisher($pending, $this->application());
		UsersHelper::applyForVerifiedPublisher($approved, $this->application());
		UsersHelper::decideVerifiedPublisher($approved, $admin, 'approve');

		$default = UsersHelper::listVerifiedPublisherApplications();
		$this->assertSame(1, $default['total']);
		$this->assertSame($pending->getAccountName(), $default['items'][0]['user']['username']);

		$this->assertSame(1, UsersHelper::listVerifiedPublisherApplications('approved')['total']);
		$this->assertSame(0, UsersHelper::listVerifiedPublisherApplications('denied')['total']);
		$this->assertSame(0, UsersHelper::listVerifiedPublisherApplications('bogus')['total']);
	}

	// #endregion

	private function mailOf(string $id): array
	{
		return array_values(
			array_filter(
				\Drupal::state()->get('system.test_mail_collector') ?? [],
				fn(array $mail) => $mail['id'] === $id,
			),
		);
	}
}
