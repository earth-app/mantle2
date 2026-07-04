<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Service\ReferralHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

// every method proxies CloudHelper::sendRequest; with no live worker the local
// curl connect fails and sendRequest returns [], so these assert the graceful
// degradation contract. the successful cloud round-trips are covered in e2e.
class ReferralHelperTest extends IntegrationTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		// force the offline endpoint so curl fails fast to connect (no live worker)
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:9');
	}

	#[Test]
	#[TestDox('getCode returns an empty string when the cloud is unreachable')]
	#[Group('mantle2/referral')]
	public function getCodeDegradesToEmpty(): void
	{
		$user = $this->createUser();
		$this->assertSame('', ReferralHelper::getCode($user));
	}

	#[Test]
	#[TestDox('getStats returns an empty array when the cloud is unreachable')]
	#[Group('mantle2/referral')]
	public function getStatsDegradesToEmpty(): void
	{
		$user = $this->createUser();
		$this->assertSame([], ReferralHelper::getStats($user));
	}

	#[Test]
	#[TestDox('recordClick swallows cloud failures and never throws')]
	#[Group('mantle2/referral')]
	public function recordClickSwallowsFailure(): void
	{
		ReferralHelper::recordClick('SOMECODE');
		// reaching here without a thrown exception is the contract
		$this->assertTrue(true);
	}

	#[Test]
	#[TestDox('attributeReferral returns null when the cloud does not confirm the conversion')]
	#[Group('mantle2/referral')]
	public function attributeReferralDegradesToNull(): void
	{
		$newUser = $this->createUser();
		$this->assertNull(ReferralHelper::attributeReferral($newUser, 'SOMECODE'));
	}
}
