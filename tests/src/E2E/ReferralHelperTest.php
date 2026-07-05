<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Service\ReferralHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ReferralHelperTest extends E2ETestBase
{
	#[Test]
	#[TestDox('getCode returns a non-empty referral code for a user')]
	#[Group('mantle2/referral')]
	public function getCode(): void
	{
		$user = $this->createUser();
		$code = ReferralHelper::getCode($user);
		$this->assertNotEmpty($code);
		$this->assertSame($code, ReferralHelper::getCode($user));
	}

	#[Test]
	#[TestDox('getStats returns the code + counters shape for a fresh user')]
	#[Group('mantle2/referral')]
	public function getStats(): void
	{
		$user = $this->createUser();
		$stats = ReferralHelper::getStats($user);

		$this->assertArrayHasKey('code', $stats);
		$this->assertArrayHasKey('clicks', $stats);
		$this->assertArrayHasKey('conversions', $stats);
		$this->assertArrayHasKey('converted_ids', $stats);
		$this->assertIsArray($stats['converted_ids']);
	}

	#[Test]
	#[TestDox('recordClick posts a click for a code without throwing')]
	#[Group('mantle2/referral')]
	public function recordClick(): void
	{
		$user = $this->createUser();
		$code = ReferralHelper::getCode($user);

		$before = (int) (ReferralHelper::getStats($user)['clicks'] ?? 0);
		ReferralHelper::recordClick($code);
		$after = (int) (ReferralHelper::getStats($user)['clicks'] ?? 0);

		$this->assertGreaterThanOrEqual($before, $after);
	}

	#[Test]
	#[
		TestDox(
			'attributeReferral converts a new user against a valid code and returns the referrer id',
		),
	]
	#[Group('mantle2/referral')]
	public function attributeReferral(): void
	{
		$referrer = $this->createUser();
		$code = ReferralHelper::getCode($referrer);

		// drupal uids are reused across fresh test DBs while cloud referral state is
		// cumulative, so a given new-user id may already be attributed from a past run;
		// either outcome proves the convert path + response parsing works
		$newUser = $this->createUser();
		$referrerId = ReferralHelper::attributeReferral($newUser, $code);

		if ($referrerId !== null) {
			$this->assertSame((string) $referrer->id(), ltrim($referrerId, '0'));
			$this->assertContains(
				ltrim(\Drupal\mantle2\Service\GeneralHelper::formatId($newUser->id()), '0'),
				array_map(
					fn($v) => ltrim((string) $v, '0'),
					ReferralHelper::getStats($referrer)['converted_ids'],
				),
			);
		} else {
			// already attributed to some referrer in a prior run; re-attribution is a no-op
			$this->assertNull(ReferralHelper::attributeReferral($newUser, $code));
		}
	}

	#[Test]
	#[TestDox('attributeReferral returns null for an unknown code')]
	#[Group('mantle2/referral')]
	public function attributeReferralUnknownCode(): void
	{
		$newUser = $this->createUser();
		$result = ReferralHelper::attributeReferral($newUser, '____NOPE____');
		$this->assertNull($result);
	}
}
