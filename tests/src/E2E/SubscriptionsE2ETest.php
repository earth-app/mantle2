<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\SubscriptionsHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Stripe\Exception\ApiErrorException;

class SubscriptionsE2ETest extends IntegrationTestBase
{
	private string $stripeKey;

	protected function setUp(): void
	{
		parent::setUp();

		// e2e drives the REAL stripe client, so unlike the integration tier it uses the app's
		// real config resolution (env OR data/subscriptions.yml) instead of isolated/empty config
		SubscriptionsHelper::setDataConfigOverride(null);

		$this->stripeKey =
			getenv('MANTLE2_STRIPE_SECRET_KEY') ?:
			(getenv('STRIPE_TEST_KEY') ?:
			SubscriptionsHelper::stripeSecret() ?? '');
		if ($this->stripeKey === '') {
			self::markTestSkipped(
				'Stripe test key not set (MANTLE2_STRIPE_SECRET_KEY / STRIPE_TEST_KEY / data/subscriptions.yml); skipping Stripe E2E',
			);
		}
		if (!str_starts_with($this->stripeKey, 'sk_test_')) {
			self::markTestSkipped('Refusing to run E2E against a non-test Stripe key');
		}

		// pin the resolved secret so the live client is deterministic (also covers the env-only case)
		$this->seedKey('mantle2_stripe_secret_key', $this->stripeKey);
		if ($ws = getenv('STRIPE_WEBHOOK_SECRET') ?: null) {
			$this->seedKey('mantle2_stripe_webhook_secret', $ws);
		}

		foreach (
			[
				'pro' => 'STRIPE_PRICE_PRO',
				'writer' => 'STRIPE_PRICE_WRITER',
				'organizer' => 'STRIPE_PRICE_ORGANIZER',
			]
			as $tier => $env
		) {
			$priceId = getenv($env) ?: (getenv('MANTLE2_' . $env) ?: null);
			if ($priceId) {
				$this->setSetting('mantle2.stripe_price_' . $tier, $priceId);
			}
		}
	}

	// respect the full resolution (settings/env/data file), not just settings.php
	private function priceConfigured(): bool
	{
		return SubscriptionsHelper::getPriceIdForTier(AccountType::PRO) !== null;
	}

	private function payingUser(): UserInterface
	{
		$user = $this->createUser(['field_email_verified' => true]);
		UsersHelper::setSubscribed($user, true);
		$user->save();
		return $user;
	}

	#region Live (network) round-trips

	#[Test]
	#[TestDox('getPlans is a pure local read and returns the four tiers')]
	#[Group('mantle2/subscriptions')]
	public function livePlans(): void
	{
		$plans = SubscriptionsHelper::getPlans();
		$this->assertContains('pro', array_column($plans, 'tier'));
		$this->assertContains('organizer', array_column($plans, 'tier'));
	}

	#[Test]
	#[
		TestDox(
			'createCheckoutSession returns a live checkout.stripe.com URL against Stripe test mode',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function liveCheckoutSession(): void
	{
		if (!$this->priceConfigured()) {
			self::markTestSkipped(
				'STRIPE_PRICE_PRO not set; cannot build a real checkout line item',
			);
		}

		$user = $this->payingUser();
		try {
			$result = SubscriptionsHelper::createCheckoutSession(
				$user,
				AccountType::PRO,
				'https://app.earth-app.com/subscription/success',
				'https://app.earth-app.com/subscription/cancel',
			);
		} catch (ApiErrorException $e) {
			// the price must exist in the SAME mode as the key; skip (don't fail) when the stripe
			// account isn't set up for a test-mode round-trip (e.g. a live price id + a test key)
			self::markTestSkipped(
				'Stripe rejected the live checkout; configure test-mode products/prices: ' .
					$e->getMessage(),
			);
		}

		$this->assertStringStartsWith('https://checkout.stripe.com', $result['url']);
		$this->assertStringStartsWith('cs_', $result['session_id']);

		$row = $this->subscriptionRow((int) $user->id());
		$this->assertNotNull($row['external_customer_id']);
		$this->assertStringStartsWith('cus_', $row['external_customer_id']);
	}

	#endregion

	#region Card matrix (manual: requires Checkout completion or PaymentMethod attach)

	#[Test]
	#[
		TestDox(
			'MANUAL card 4242 4242 4242 4242 -> checkout.session.completed -> subscription_activated',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function cardSuccess(): void
	{
		self::markTestSkipped(
			'Manual: open the returned checkout URL, pay with 4242 4242 4242 4242 (any future exp, any CVC), ' .
				'then assert the checkout.session.completed webhook lifts the account to PRO.',
		);
	}

	#[Test]
	#[
		TestDox(
			'MANUAL card 4000 0000 0000 0002 -> generic_decline -> checkout stays incomplete, no entitlement',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function cardGenericDecline(): void
	{
		self::markTestSkipped(
			'Manual: pay with 4000 0000 0000 0002; Stripe returns decline code generic_decline; ' .
				'no checkout.session.completed fires and the account remains FREE.',
		);
	}

	#[Test]
	#[TestDox('MANUAL card 4000 0000 0000 9995 -> insufficient_funds decline')]
	#[Group('mantle2/subscriptions')]
	public function cardInsufficientFunds(): void
	{
		self::markTestSkipped(
			'Manual: pay with 4000 0000 0000 9995; decline code insufficient_funds; account remains FREE.',
		);
	}

	#[Test]
	#[
		TestDox(
			'MANUAL card 4000 0027 6000 3184 -> 3DS/SCA required -> invoice.payment_action_required',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function card3dsRequired(): void
	{
		self::markTestSkipped(
			'Manual: pay with 4000 0027 6000 3184 (3DS required). Completing authentication activates; ' .
				'abandoning it leaves the subscription incomplete and fires invoice.payment_action_required (SCA notice).',
		);
	}

	#[Test]
	#[
		TestDox(
			'MANUAL card 4000 0000 0000 0341 -> attaches but the first charge fails -> payment_failed dunning',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function cardAttachThenChargeFails(): void
	{
		self::markTestSkipped(
			'Manual: 4000 0000 0000 0341 attaches to the customer but the subscription charge fails, ' .
				'firing invoice.payment_failed (dunning) and leaving status past_due.',
		);
	}

	#endregion

	#region Test-clock scenarios (manual: requires Stripe test clocks)

	#[Test]
	#[
		TestDox(
			'MANUAL refund window: test clock at day 13 -> cancel refunds; day 15 -> cancel schedules period-end',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function testClockRefundWindow(): void
	{
		self::markTestSkipped(
			'Manual: create the customer on a Stripe test clock, subscribe, then advance the clock. ' .
				'At +13d cancel() must refund (result=refunded, account FREE). At +14d exactly it still refunds ' .
				'(inclusive). At +15d cancel() must schedule cancel_at_period_end (result=canceled).',
		);
	}

	#[Test]
	#[
		TestDox(
			'MANUAL dunning: advance the test clock past the renewal to drive payment_failed retries then final cancel',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function testClockDunning(): void
	{
		self::markTestSkipped(
			'Manual: attach a failing card, advance the test clock past the renewal date; Stripe fires ' .
				'invoice.payment_failed for each retry (dunning notices) then customer.subscription.deleted ' .
				'(final canceled-for-nonpayment), which must downgrade the account to FREE.',
		);
	}

	#[Test]
	#[
		TestDox(
			'MANUAL renewal: advance the test clock to a renewal -> invoice.paid -> subscription_renewed + started_at reset',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function testClockRenewal(): void
	{
		self::markTestSkipped(
			'Manual: advance the test clock one interval; invoice.paid fires, status stays active, started_at ' .
				'resets to the new period start (re-opening the 14-day refund window), and subscription_renewed sends.',
		);
	}

	#endregion

	#region Webhook delivery scenarios (manual: use Stripe CLI `stripe trigger` / `stripe events resend`)

	#[Test]
	#[TestDox('MANUAL idempotency: resending the same event id must not double-apply')]
	#[Group('mantle2/subscriptions')]
	public function webhookReplayIdempotent(): void
	{
		self::markTestSkipped(
			'Manual: `stripe events resend <evt_id>` twice; the second delivery is deduped via ' .
				'redis stripe:webhook:{event_id} and must not send a second activation or change state.',
		);
	}

	#[Test]
	#[
		TestDox(
			'MANUAL out-of-order: deliver subscription.updated after subscription.deleted -> stays revoked',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function webhookOutOfOrder(): void
	{
		self::markTestSkipped(
			'Manual: deliver a stale customer.subscription.updated after customer.subscription.deleted; ' .
				'the account must remain FREE (a deleted subscription is terminal).',
		);
	}

	#endregion
}
