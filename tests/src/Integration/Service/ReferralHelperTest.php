<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Service\CloudHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\ReferralHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Exception;
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

	// #region Cloud round trips

	/** @var array<int,array{path:string,method:string,data:array}> */
	private array $cloudCalls = [];

	protected function tearDown(): void
	{
		CloudHelper::setRequestOverride(null);
		parent::tearDown();
	}

	private function cloudReturning(mixed $response): void
	{
		$this->cloudCalls = [];
		CloudHelper::setRequestOverride(function (string $path, string $method, array $data) use (
			$response,
		) {
			$this->cloudCalls[] = ['path' => $path, 'method' => $method, 'data' => $data];
			if ($response instanceof Exception) {
				throw $response;
			}
			return $response;
		});
	}

	#[Test]
	#[TestDox('getCode returns the code the cloud minted for that user')]
	#[Group('mantle2/referral')]
	public function getCodeReturnsTheCloudCode(): void
	{
		$user = $this->createUser();
		$this->cloudReturning(['code' => 'EARTH123']);

		$this->assertSame('EARTH123', ReferralHelper::getCode($user));
		$this->assertSame(
			'/v1/users/referral/' . GeneralHelper::formatId($user->id()),
			$this->cloudCalls[0]['path'],
		);
	}

	#[Test]
	#[TestDox('getStats passes the cloud payload through untouched')]
	#[Group('mantle2/referral')]
	public function getStatsPassesThroughTheCloudPayload(): void
	{
		$user = $this->createUser();
		$stats = ['code' => 'EARTH123', 'clicks' => 12, 'conversions' => 3, 'converted_ids' => []];
		$this->cloudReturning($stats);

		$this->assertSame($stats, ReferralHelper::getStats($user));
		$this->assertStringEndsWith('/stats', $this->cloudCalls[0]['path']);
	}

	#[Test]
	#[TestDox('recordClick posts the code to the click endpoint')]
	#[Group('mantle2/referral')]
	public function recordClickPostsTheCode(): void
	{
		$this->cloudReturning([]);

		ReferralHelper::recordClick('EARTH123');

		$this->assertSame('/v1/users/referral/click', $this->cloudCalls[0]['path']);
		$this->assertSame('POST', $this->cloudCalls[0]['method']);
		$this->assertSame(['code' => 'EARTH123'], $this->cloudCalls[0]['data']);
	}

	#[Test]
	#[TestDox('attributeReferral returns the referrer id when the cloud confirms the conversion')]
	#[Group('mantle2/referral')]
	public function attributeReferralReturnsTheReferrer(): void
	{
		$newUser = $this->createUser();
		$this->cloudReturning(['ok' => true, 'referrer_id' => '000000000000000000000009']);

		$this->assertSame(
			'000000000000000000000009',
			ReferralHelper::attributeReferral($newUser, 'EARTH123'),
		);
		$this->assertSame('/v1/users/referral/convert', $this->cloudCalls[0]['path']);
		$this->assertSame(
			GeneralHelper::formatId($newUser->id()),
			$this->cloudCalls[0]['data']['user_id'],
		);
	}

	#[Test]
	#[TestDox('An unconfirmed conversion yields null even when the call succeeds')]
	#[Group('mantle2/referral')]
	public function attributeReferralRejectsAnUnconfirmedConversion(): void
	{
		$newUser = $this->createUser();
		$this->cloudReturning(['ok' => false, 'referrer_id' => '9']);

		$this->assertNull(ReferralHelper::attributeReferral($newUser, 'EARTH123'));
	}

	#[Test]
	#[TestDox('A cloud error during attribution never throws into signup')]
	#[Group('mantle2/referral')]
	public function attributeReferralSwallowsCloudErrors(): void
	{
		$newUser = $this->createUser();
		$this->cloudReturning(new Exception('cloud down', 503));

		$this->assertNull(ReferralHelper::attributeReferral($newUser, 'EARTH123'));
	}

	// #endregion
}
