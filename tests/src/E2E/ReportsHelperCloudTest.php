<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal;
use Drupal\mantle2\Service\CloudHelper;
use Drupal\mantle2\Service\ReportsHelper;
use Drupal\mantle2\Service\UsersHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ReportsHelperCloudTest extends E2ETestBase
{
	private function blacklistValues(string $kind): array
	{
		$data = CloudHelper::sendRequest('/v1/admin/blacklist', 'GET', ['kind' => $kind]);
		$entries = $data['entries'] ?? [];
		return array_map(fn($e) => $e['value'] ?? '', $entries);
	}

	#[Test]
	#[TestDox('recordStrikeAndEnforce posts a strike to cloud and returns the enforcement action')]
	#[Group('mantle2/reports')]
	public function recordStrikeReturnsAction(): void
	{
		$user = $this->createUser();
		$before =
			(int) (CloudHelper::sendRequest('/v1/users/' . $user->id() . '/strikes')[
				'updated_at'
			] ?? 0);
		$action = ReportsHelper::recordStrikeAndEnforce(
			(int) $user->id(),
			'user',
			(string) $user->id(),
			'spam',
			'e2e strike',
		);

		$this->assertContains($action, ['none', 'disable_1_month', 'permanent_ban']);

		$record = CloudHelper::sendRequest('/v1/users/' . $user->id() . '/strikes');
		$this->assertNotEmpty($record['history'] ?? []);
		$this->assertGreaterThan($before, (int) ($record['updated_at'] ?? 0));
	}

	#[Test]
	#[TestDox('recordStrikeAndEnforce never strikes system/admin accounts')]
	#[Group('mantle2/reports')]
	public function systemAccountsNeverStrike(): void
	{
		$this->assertSame('none', ReportsHelper::recordStrikeAndEnforce(1, 'user', '1', 'spam'));
	}

	#[Test]
	#[TestDox('banUser disables the account and blacklists username + email in cloud')]
	#[Group('mantle2/reports')]
	public function banUserBlacklistsInCloud(): void
	{
		$suffix = bin2hex(random_bytes(5));
		$name = 'e2eban_' . $suffix;
		$email = $name . '@example.com';
		$user = $this->createUser(['name' => $name, 'mail' => $email]);

		ReportsHelper::banUser($user);

		$this->assertTrue(UsersHelper::isDisabled($user));
		$this->assertContains($name, $this->blacklistValues('username'));
		$this->assertContains($email, $this->blacklistValues('email'));
	}

	#[Test]
	#[TestDox('runDailyDigest fetches the pending report count from cloud without throwing')]
	#[Group('mantle2/reports')]
	public function runDailyDigest(): void
	{
		// clear the once-per-day guard so the digest actually runs
		Drupal::state()->delete(ReportsHelper::DIGEST_STATE_KEY);

		ReportsHelper::runDailyDigest();

		// the guard is stamped once the fetch succeeds (regardless of pending count)
		$stamped = (int) Drupal::state()->get(ReportsHelper::DIGEST_STATE_KEY, 0);
		$this->assertGreaterThan(0, $stamped);
	}
}
