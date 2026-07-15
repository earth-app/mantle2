<?php

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\SubscriptionsHelper;
use Drupal\Tests\mantle2\Mocks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

// pure/mocked-container logic; anything touching the DB (trial matrix, dedupe, entitlement
// persistence, cross-provider enforcement) lives in the integration tier where redis/db exist
class SubscriptionsUnitTest extends TestCase
{
	private const WEBHOOK_SECRET = 'whsec_test';

	protected function setUp(): void
	{
		Mocks::instance()->mockDrupalContainer();
	}

	protected function tearDown(): void
	{
		SubscriptionsHelper::setClientOverride(null);
		foreach (['PRO', 'WRITER', 'ORGANIZER'] as $t) {
			putenv('MANTLE2_STRIPE_PRICE_' . $t);
		}
	}

	// mirrors Stripe's own signing so Webhook::constructEvent verifies our test payloads
	private function sign(
		string $body,
		?int $timestamp = null,
		string $secret = self::WEBHOOK_SECRET,
	): string {
		$timestamp ??= time();
		return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
	}

	private function eventBody(
		string $id = 'evt_test',
		string $type = 'checkout.session.completed',
	): string {
		return json_encode([
			'id' => $id,
			'object' => 'event',
			'type' => $type,
			'data' => ['object' => ['id' => 'cs_test', 'object' => 'checkout.session']],
		]);
	}

	#region Signature verification

	#[Test]
	#[TestDox('a correctly signed payload passes Webhook::constructEvent')]
	#[Group('mantle2/subscriptions')]
	public function validSignaturePasses(): void
	{
		$body = $this->eventBody('evt_valid');
		$event = Webhook::constructEvent($body, $this->sign($body), self::WEBHOOK_SECRET);
		$this->assertSame('evt_valid', $event->id);
		$this->assertSame('checkout.session.completed', $event->type);
	}

	#[Test]
	#[TestDox('a tampered body fails signature verification')]
	#[Group('mantle2/subscriptions')]
	public function tamperedBodyFails(): void
	{
		$body = $this->eventBody();
		$sig = $this->sign($body);
		$this->expectException(SignatureVerificationException::class);
		Webhook::constructEvent($body . ' ', $sig, self::WEBHOOK_SECRET);
	}

	#[Test]
	#[TestDox('a signature computed with the wrong secret fails')]
	#[Group('mantle2/subscriptions')]
	public function wrongSecretFails(): void
	{
		$body = $this->eventBody();
		$sig = $this->sign($body, null, 'whsec_attacker');
		$this->expectException(SignatureVerificationException::class);
		Webhook::constructEvent($body, $sig, self::WEBHOOK_SECRET);
	}

	#[Test]
	#[TestDox('a timestamp outside the tolerance window fails even with a valid HMAC')]
	#[Group('mantle2/subscriptions')]
	public function expiredTimestampFails(): void
	{
		$body = $this->eventBody();
		// 10 minutes old, well past the 300s default tolerance
		$sig = $this->sign($body, time() - 600);
		$this->expectException(SignatureVerificationException::class);
		Webhook::constructEvent($body, $sig, self::WEBHOOK_SECRET, 300);
	}

	#[Test]
	#[TestDox('a malformed signature header (no scheme, no timestamp) fails')]
	#[Group('mantle2/subscriptions')]
	public function malformedHeaderFails(): void
	{
		$body = $this->eventBody();
		$this->expectException(SignatureVerificationException::class);
		Webhook::constructEvent($body, 'garbage-header', self::WEBHOOK_SECRET);
	}

	#[Test]
	#[TestDox('a header missing the v1 scheme fails')]
	#[Group('mantle2/subscriptions')]
	public function missingSchemeFails(): void
	{
		$body = $this->eventBody();
		$sig =
			't=' .
			time() .
			',v0=' .
			hash_hmac('sha256', time() . '.' . $body, self::WEBHOOK_SECRET);
		$this->expectException(SignatureVerificationException::class);
		Webhook::constructEvent($body, $sig, self::WEBHOOK_SECRET);
	}

	#[Test]
	#[
		TestDox(
			'a replayed payload keeps a valid signature (replay defense must be idempotency, not signature)',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function replayedPayloadStillVerifies(): void
	{
		// same signed bytes verify twice; only event-id dedupe can stop a replay (integration tier)
		$body = $this->eventBody('evt_replay');
		$sig = $this->sign($body);
		$first = Webhook::constructEvent($body, $sig, self::WEBHOOK_SECRET);
		$second = Webhook::constructEvent($body, $sig, self::WEBHOOK_SECRET);
		$this->assertSame($first->id, $second->id);
	}

	#endregion

	#region Corrupted payload parsing

	#[Test]
	#[TestDox('a validly signed but non-JSON body throws UnexpectedValueException')]
	#[Group('mantle2/subscriptions')]
	public function nonJsonBodyThrows(): void
	{
		$body = 'not json at all';
		$this->expectException(UnexpectedValueException::class);
		Webhook::constructEvent($body, $this->sign($body), self::WEBHOOK_SECRET);
	}

	#[Test]
	#[TestDox('a validly signed but truncated JSON body throws UnexpectedValueException')]
	#[Group('mantle2/subscriptions')]
	public function truncatedJsonThrows(): void
	{
		$body = '{"id":"evt_x","type":"checkout.session.completed","data":{';
		$this->expectException(UnexpectedValueException::class);
		Webhook::constructEvent($body, $this->sign($body), self::WEBHOOK_SECRET);
	}

	public static function corruptIapPayloadProvider(): array
	{
		return [
			'empty string' => [''],
			'garbage' => ['%%%not-json%%%'],
			'truncated object' => ['{"transaction_id":"t1"'],
			'array not object' => ['[1,2,3]'],
			'bare null' => ['null'],
		];
	}

	#[Test]
	#[TestDox('corrupt IAP JSON bodies decode to non-usable structures (impl must 400 these)')]
	#[Group('mantle2/subscriptions')]
	#[DataProvider('corruptIapPayloadProvider')]
	public function corruptIapPayloadsAreNotUsableObjects(string $raw): void
	{
		$decoded = json_decode($raw, true);
		$this->assertFalse(
			is_array($decoded) && array_key_exists('transaction_id', $decoded),
			'corrupt payload must not yield a usable transaction object',
		);
	}

	#endregion

	#region Refund window boundaries

	public static function refundWindowProvider(): array
	{
		$day = 86400;
		return [
			// [secondsSinceStart, withinWindow]
			'just started' => [0, true],
			'day 1' => [1 * $day, true],
			'day 13' => [13 * $day, true],
			'day 14 exactly (boundary, inclusive)' => [14 * $day, true],
			'day 14 plus one second' => [14 * $day + 1, false],
			'day 15' => [15 * $day, false],
			'day 30' => [30 * $day, false],
		];
	}

	#[Test]
	#[TestDox('isWithinRefundWindow is inclusive at exactly 14 days and false after')]
	#[Group('mantle2/subscriptions')]
	#[DataProvider('refundWindowProvider')]
	public function isWithinRefundWindowBoundaries(int $secondsSinceStart, bool $expected): void
	{
		$startedAt = 1_700_000_000;
		$now = $startedAt + $secondsSinceStart;
		$row = ['started_at' => $startedAt];
		$this->assertSame($expected, SubscriptionsHelper::isWithinRefundWindow($row, $now));
	}

	#[Test]
	#[TestDox('a row without started_at is never within the refund window')]
	#[Group('mantle2/subscriptions')]
	public function noStartedAtIsNotRefundable(): void
	{
		$this->assertFalse(
			SubscriptionsHelper::isWithinRefundWindow(['started_at' => null], time()),
		);
		$this->assertFalse(SubscriptionsHelper::isWithinRefundWindow([], time()));
	}

	#[Test]
	#[
		TestDox(
			'REFUND_WINDOW_DAYS is 14 and the deadline ISO is UTC regardless of the local timezone',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function refundDeadlineIsTimezoneInvariant(): void
	{
		$this->assertSame(14, SubscriptionsHelper::REFUND_WINDOW_DAYS);

		$startedAt = 1_700_000_000;
		$deadlineTs = $startedAt + SubscriptionsHelper::REFUND_WINDOW_DAYS * 86400;

		$original = date_default_timezone_get();
		try {
			date_default_timezone_set('America/New_York');
			$eastern = GeneralHelper::dateToIso($deadlineTs);
			date_default_timezone_set('Asia/Tokyo');
			$tokyo = GeneralHelper::dateToIso($deadlineTs);
		} finally {
			date_default_timezone_set($original);
		}

		// dateToIso uses gmdate('c'), so the rendered instant is identical + UTC everywhere
		$this->assertSame($eastern, $tokyo);
		$this->assertStringEndsWith('+00:00', $eastern);
		$this->assertSame(gmdate('c', $deadlineTs), $eastern);
	}

	#endregion

	#region Price + tier constants

	public static function priceCentsProvider(): array
	{
		return [
			'free' => [AccountType::FREE, 0],
			'pro' => [AccountType::PRO, 599],
			'writer' => [AccountType::WRITER, 899],
			'organizer' => [AccountType::ORGANIZER, 1499],
		];
	}

	#[Test]
	#[TestDox('priceCents matches the frozen pricing table')]
	#[Group('mantle2/subscriptions')]
	#[DataProvider('priceCentsProvider')]
	public function priceCents(AccountType $tier, int $expected): void
	{
		$this->assertSame($expected, SubscriptionsHelper::priceCents($tier));
	}

	#[Test]
	#[TestDox('getPlans returns free plus three paid plans with the required shape')]
	#[Group('mantle2/subscriptions')]
	public function getPlansShape(): void
	{
		$plans = SubscriptionsHelper::getPlans();
		$byTier = [];
		foreach ($plans as $plan) {
			foreach (
				['tier', 'name', 'price_cents', 'price_display', 'currency', 'interval']
				as $key
			) {
				$this->assertArrayHasKey($key, $plan, "plan missing key $key");
			}
			$byTier[$plan['tier']] = $plan;
		}

		$this->assertArrayHasKey('free', $byTier);
		$this->assertArrayHasKey('pro', $byTier);
		$this->assertArrayHasKey('writer', $byTier);
		$this->assertArrayHasKey('organizer', $byTier);

		$this->assertSame(0, $byTier['free']['price_cents']);
		$this->assertSame(599, $byTier['pro']['price_cents']);
		$this->assertSame(899, $byTier['writer']['price_cents']);
		$this->assertSame(1499, $byTier['organizer']['price_cents']);

		$this->assertSame('$5.99', $byTier['pro']['price_display']);
		$this->assertSame('$8.99', $byTier['writer']['price_display']);
		$this->assertSame('$14.99', $byTier['organizer']['price_display']);

		foreach (['pro', 'writer', 'organizer'] as $tier) {
			$this->assertSame('usd', $byTier[$tier]['currency']);
			$this->assertSame('month', $byTier[$tier]['interval']);
		}
	}

	// builds a container whose settings + env expose the three stripe price ids
	private function configurePriceIds(): void
	{
		putenv('MANTLE2_STRIPE_PRICE_PRO=price_pro_test');
		putenv('MANTLE2_STRIPE_PRICE_WRITER=price_writer_test');
		putenv('MANTLE2_STRIPE_PRICE_ORGANIZER=price_organizer_test');

		$container = new ContainerBuilder();
		$container->set(
			'settings',
			new Settings([
				'mantle2.cloud_endpoint' => 'https://httpbin.org',
				'mantle2.stripe_price_pro' => 'price_pro_test',
				'mantle2.stripe_price_writer' => 'price_writer_test',
				'mantle2.stripe_price_organizer' => 'price_organizer_test',
			]),
		);
		$logger = $this->createMock(\Drupal\Core\Logger\LoggerChannelInterface::class);
		$loggerFactory = $this->createMock(
			\Drupal\Core\Logger\LoggerChannelFactoryInterface::class,
		);
		$loggerFactory->method('get')->willReturn($logger);
		$container->set('logger.factory', $loggerFactory);
		\Drupal::setContainer($container);
	}

	#[Test]
	#[TestDox('getPriceIdForTier and getTierForPriceId round-trip for each paid tier')]
	#[Group('mantle2/subscriptions')]
	public function priceIdRoundTrip(): void
	{
		$this->configurePriceIds();

		$this->assertSame(
			'price_pro_test',
			SubscriptionsHelper::getPriceIdForTier(AccountType::PRO),
		);
		$this->assertSame(
			'price_writer_test',
			SubscriptionsHelper::getPriceIdForTier(AccountType::WRITER),
		);
		$this->assertSame(
			'price_organizer_test',
			SubscriptionsHelper::getPriceIdForTier(AccountType::ORGANIZER),
		);

		foreach ([AccountType::PRO, AccountType::WRITER, AccountType::ORGANIZER] as $tier) {
			$priceId = SubscriptionsHelper::getPriceIdForTier($tier);
			$this->assertSame($tier, SubscriptionsHelper::getTierForPriceId($priceId));
		}
	}

	#[Test]
	#[TestDox('free has no price id and an unknown price id maps to no tier')]
	#[Group('mantle2/subscriptions')]
	public function priceIdEdgeCases(): void
	{
		$this->configurePriceIds();
		$this->assertNull(SubscriptionsHelper::getPriceIdForTier(AccountType::FREE));
		$this->assertNull(SubscriptionsHelper::getTierForPriceId('price_does_not_exist'));
		$this->assertNull(SubscriptionsHelper::getTierForPriceId(''));
	}

	#endregion

	#region Admin trials pure logic

	public static function activeFlagProvider(): array
	{
		$now = 1_700_000_000;
		return [
			// [expiresAt, now, expectedActive]
			'null detail (pruned) is never active' => [null, $now, false],
			'one second in the future is active' => [$now + 1, $now, true],
			'far future is active' => [$now + 30 * 86400, $now, true],
			'exactly now is not active (strictly greater)' => [$now, $now, false],
			'one second in the past is not active' => [$now - 1, $now, false],
		];
	}

	#[Test]
	#[TestDox('redemptionIsActive requires a non-null expiry strictly in the future')]
	#[Group('mantle2/subscriptions')]
	#[DataProvider('activeFlagProvider')]
	public function redemptionIsActive(?int $expiresAt, int $now, bool $expected): void
	{
		$this->assertSame($expected, SubscriptionsHelper::redemptionIsActive($expiresAt, $now));
	}

	public static function lookupKindProvider(): array
	{
		return [
			'empty string' => ['', 'empty'],
			'whitespace only' => ['   ', 'empty'],
			'plain digits' => ['42', 'id'],
			'zero-padded digits' => ['007', 'id'],
			'email' => ['user@example.com', 'email'],
			'email with dots' => ['first.last@sub.example.co', 'email'],
			'username' => ['earthfan', 'name'],
			'full name with space' => ['Ada Lovelace', 'name'],
			'digits with letters is a name not an id' => ['42abc', 'name'],
			'trims before classifying' => ['  99  ', 'id'],
		];
	}

	#[Test]
	#[TestDox('lookupQueryKind classifies id vs email vs name vs empty')]
	#[Group('mantle2/subscriptions')]
	#[DataProvider('lookupKindProvider')]
	public function lookupQueryKind(string $q, string $expected): void
	{
		$this->assertSame($expected, SubscriptionsHelper::lookupQueryKind($q));
	}

	#[Test]
	#[TestDox('normalizeCertPem repairs a root ca whose newlines were collapsed to whitespace')]
	#[Group('mantle2/subscriptions')]
	public function normalizeCertPemRepairsCollapsedNewlines(): void
	{
		// self-signed throwaway cert; only its whitespace shape matters for this test
		$pkey = openssl_pkey_new([
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		]);
		$csr = openssl_csr_new(['commonName' => 'Earth Test Root'], $pkey);
		$x509 = openssl_csr_sign($csr, null, $pkey, 2);
		openssl_x509_export($x509, $proper);

		$expected = openssl_x509_fingerprint($proper, 'sha256');
		$this->assertNotFalse($expected);

		// every whitespace run collapsed to a single space (env var / single-line paste / folded yaml)
		$collapsed = preg_replace('/\s+/', ' ', trim($proper));
		$this->assertSame(
			$expected,
			openssl_x509_fingerprint(SubscriptionsHelper::normalizeCertPem($collapsed), 'sha256'),
			'space-collapsed pem should normalize back to a parseable cert',
		);

		// no newlines at all between markers and body
		$this->assertSame(
			$expected,
			openssl_x509_fingerprint(
				SubscriptionsHelper::normalizeCertPem(str_replace("\n", '', $proper)),
				'sha256',
			),
		);

		// idempotent on an already-canonical pem
		$this->assertSame(
			$expected,
			openssl_x509_fingerprint(SubscriptionsHelper::normalizeCertPem($proper), 'sha256'),
		);
	}

	#endregion
}
