<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\SubscriptionsHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\FakeStripeClient;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Collection;
use Stripe\Customer;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\CardException;
use Stripe\Refund;
use Stripe\Subscription;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SubscriptionsHelperTest extends IntegrationTestBase
{
	private FakeStripeClient $fake;

	protected function setUp(): void
	{
		parent::setUp();
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
		$this->configureStripe();

		$fake = $this->newFakeStripe();
		$fake->on('customers.create', Customer::constructFrom(['id' => 'cus_new_1']));
		$fake->on('customers.retrieve', Customer::constructFrom(['id' => 'cus_new_1']));
		$fake->on(
			'checkout.sessions.create',
			CheckoutSession::constructFrom([
				'id' => 'cs_test_1',
				'url' => 'https://checkout.stripe.com/pay/cs_test_1',
			]),
		);
		$fake->on(
			'billingPortal.sessions.create',
			PortalSession::constructFrom(['url' => 'https://billing.stripe.com/p/session/test_1']),
		);
		$fake->on(
			'refunds.create',
			Refund::constructFrom(['id' => 're_1', 'status' => 'succeeded']),
		);
		// refundLatestInvoice needs a latest invoice with a payment_intent before refunds.create
		$fake->on(
			'invoices.all',
			Collection::constructFrom(['data' => [['id' => 'in_1', 'payment_intent' => 'pi_1']]]),
		);
		$fake->on(
			'subscriptions.retrieve',
			Subscription::constructFrom([
				'id' => 'sub_test',
				'status' => 'active',
				'cancel_at_period_end' => false,
				'current_period_end' => time() + 30 * 86400,
				'items' => ['data' => [['price' => ['id' => 'price_pro_test']]]],
				'latest_invoice' => 'in_test',
			]),
		);
		$fake->on(
			'subscriptions.update',
			Subscription::constructFrom([
				'id' => 'sub_test',
				'status' => 'active',
				'cancel_at_period_end' => true,
				'current_period_end' => time() + 30 * 86400,
			]),
		);
		$fake->on(
			'subscriptions.cancel',
			Subscription::constructFrom(['id' => 'sub_test', 'status' => 'canceled']),
		);
		SubscriptionsHelper::setClientOverride($fake);
		$this->fake = $fake;
	}

	protected function tearDown(): void
	{
		SubscriptionsHelper::setClientOverride(null);
		parent::tearDown();
	}

	private function payingUser(): UserInterface
	{
		// verified + subscribed so transactional mail is not skipped
		$user = $this->createUser(['field_email_verified' => true]);
		UsersHelper::setSubscribed($user, true);
		$user->save();
		return $user;
	}

	private function reload(UserInterface $user): UserInterface
	{
		return UsersHelper::findById((int) $user->id());
	}

	private function billingNotifications(UserInterface $user): array
	{
		return array_filter(
			UsersHelper::getNotifications($this->reload($user)),
			fn($n) => $n->getSource() === 'billing',
		);
	}

	// sends a stripe webhook end-to-end with a valid signature
	private function sendStripe(string $type, array $object, string $id, ?int $ts = null): Response
	{
		$body = json_encode([
			'id' => $id,
			'object' => 'event',
			'api_version' => '2024-06-20',
			'type' => $type,
			'data' => ['object' => $object],
		]);
		return SubscriptionsHelper::handleStripeWebhook(
			$body,
			$this->signStripePayload($body, $ts),
		);
	}

	#region Entitlement

	#[Test]
	#[
		TestDox(
			'applyEntitlement lifts the account type to the tier ordinal, upserts the row, and notifies',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function applyEntitlementActivates(): void
	{
		$user = $this->payingUser();
		$periodEnd = time() + 30 * 86400;

		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			$periodEnd,
			false,
			['external_subscription_id' => 'sub_apply_1', 'external_customer_id' => 'cus_apply_1'],
		);

		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType($this->reload($user)));

		$row = $this->subscriptionRow((int) $user->id());
		$this->assertNotNull($row);
		$this->assertSame('stripe', $row['provider']);
		$this->assertSame('pro', $row['tier']);
		$this->assertSame('active', $row['status']);
		$this->assertSame($periodEnd, (int) $row['current_period_end']);

		$this->assertNotEmpty(
			$this->billingNotifications($user),
			'expected a billing notification',
		);
	}

	#[Test]
	#[
		TestDox(
			'applyEntitlement notifies once on activation, stays silent on a plain re-apply, and notifies again with a renewal hint',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function applyEntitlementRenews(): void
	{
		$user = $this->payingUser();

		// first transition into active -> one activation notice
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time() + 86400,
			false,
		);
		$afterActivation = count($this->billingNotifications($user));
		$this->assertSame(1, $afterActivation);

		// a plain re-apply on an already-active row must NOT re-notify
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time() + 60 * 86400,
			false,
		);
		$this->assertSame($afterActivation, count($this->billingNotifications($user)));

		// the renewal path passes an explicit notify hint (as invoice.paid does)
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time() + 90 * 86400,
			false,
			['notify' => 'renewed'],
		);

		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType($this->reload($user)));
		$this->assertGreaterThan($afterActivation, count($this->billingNotifications($user)));
	}

	#[Test]
	#[TestDox('revokeEntitlement drops the account back to FREE and marks the row canceled')]
	#[Group('mantle2/subscriptions')]
	public function revokeEntitlementDowngrades(): void
	{
		$user = $this->payingUser();
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time() + 86400,
			false,
		);
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType($this->reload($user)));

		SubscriptionsHelper::revokeEntitlement($user, 'canceled by user', 'stripe');

		$this->assertSame(AccountType::FREE, UsersHelper::getAccountType($this->reload($user)));
		$row = $this->subscriptionRow((int) $user->id());
		$this->assertContains($row['status'], ['canceled', 'refunded', 'none']);
	}

	#endregion

	#region Checkout + Portal

	#[Test]
	#[
		TestDox(
			'createCheckoutSession returns url + session_id, stores the customer id, and records consent',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function createCheckoutSession(): void
	{
		$user = $this->payingUser();

		$result = SubscriptionsHelper::createCheckoutSession(
			$user,
			AccountType::PRO,
			'https://app.earth-app.com/subscription/success',
			'https://app.earth-app.com/subscription/cancel',
		);

		$this->assertSame('https://checkout.stripe.com/pay/cs_test_1', $result['url']);
		$this->assertSame('cs_test_1', $result['session_id']);
		$this->assertContains('checkout.sessions.create', $this->fake->calledPaths());

		$row = $this->subscriptionRow((int) $user->id());
		$this->assertNotNull($row);
		$this->assertSame('cus_new_1', $row['external_customer_id']);
		$this->assertNotNull($row['consent_at']);
	}

	#[Test]
	#[TestDox('createCheckoutSession surfaces a Stripe connection error (caller maps it to 500)')]
	#[Group('mantle2/subscriptions')]
	public function createCheckoutSessionTimeoutThrows(): void
	{
		$user = $this->payingUser();
		$this->fake->on(
			'checkout.sessions.create',
			new ApiConnectionException('Connection timed out'),
		);

		$this->expectException(Throwable::class);
		SubscriptionsHelper::createCheckoutSession(
			$user,
			AccountType::PRO,
			'https://app.earth-app.com/s',
			'https://app.earth-app.com/c',
		);
	}

	#[Test]
	#[TestDox('createCheckoutSession surfaces a declined-card error')]
	#[Group('mantle2/subscriptions')]
	public function createCheckoutSessionCardDeclinedThrows(): void
	{
		$user = $this->payingUser();
		$this->fake->on(
			'checkout.sessions.create',
			CardException::factory(
				'Your card was declined.',
				402,
				null,
				null,
				null,
				'card_declined',
				'generic_decline',
			),
		);

		$this->expectException(CardException::class);
		SubscriptionsHelper::createCheckoutSession(
			$user,
			AccountType::PRO,
			'https://app.earth-app.com/s',
			'https://app.earth-app.com/c',
		);
	}

	#[Test]
	#[TestDox('createPortalSession returns the billing portal url')]
	#[Group('mantle2/subscriptions')]
	public function createPortalSession(): void
	{
		$user = $this->payingUser();
		$this->seedSubscription((int) $user->id(), ['external_customer_id' => 'cus_new_1']);

		$url = SubscriptionsHelper::createPortalSession($user, 'https://app.earth-app.com/account');
		$this->assertSame('https://billing.stripe.com/p/session/test_1', $url);
	}

	#endregion

	#region Cancel + Refund

	#[Test]
	#[TestDox('cancelSubscription within the refund window refunds and downgrades to FREE')]
	#[Group('mantle2/subscriptions')]
	public function cancelWithinWindowRefunds(): void
	{
		$user = $this->payingUser();
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time() + 86400,
			false,
			[
				'external_subscription_id' => 'sub_test',
				'external_customer_id' => 'cus_new_1',
				'started_at' => time(),
			],
		);

		$result = SubscriptionsHelper::cancelSubscription($user);

		$this->assertSame('refunded', $result['result']);
		$this->assertSame('free', $result['tier']);
		$this->assertSame(AccountType::FREE, UsersHelper::getAccountType($this->reload($user)));
		$this->assertSame('refunded', $this->subscriptionRow((int) $user->id())['status']);
		$this->assertContains('refunds.create', $this->fake->calledPaths());
	}

	#[Test]
	#[TestDox('cancelSubscription outside the refund window schedules cancel-at-period-end')]
	#[Group('mantle2/subscriptions')]
	public function cancelOutsideWindowSchedules(): void
	{
		$user = $this->payingUser();
		$periodEnd = time() + 10 * 86400;
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			$periodEnd,
			false,
			[
				'external_subscription_id' => 'sub_test',
				'external_customer_id' => 'cus_new_1',
				'started_at' => time() - 20 * 86400,
			],
		);

		$result = SubscriptionsHelper::cancelSubscription($user);

		$this->assertSame('canceled', $result['result']);
		$this->assertTrue($result['cancel_at_period_end']);
		$this->assertSame('pro', $result['tier']);
		$this->assertArrayHasKey('access_until', $result);
		// access stays until period end
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType($this->reload($user)));
		$this->assertSame(
			1,
			(int) $this->subscriptionRow((int) $user->id())['cancel_at_period_end'],
		);
	}

	#[Test]
	#[TestDox('cancelSubscription on an Apple sub returns store_managed with the Apple manage url')]
	#[Group('mantle2/subscriptions')]
	public function cancelAppleReturnsStoreManaged(): void
	{
		$user = $this->payingUser();
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'apple',
			'active',
			time() + 86400,
			false,
			[
				'external_subscription_id' => 'apple_txn_1',
			],
		);

		$result = SubscriptionsHelper::cancelSubscription($user);
		$this->assertSame('store_managed', $result['result']);
		$this->assertSame('apple', $result['provider']);
		$this->assertStringContainsString('apps.apple.com', $result['manage_url']);
		// store-managed cancels do not touch rank locally
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType($this->reload($user)));
	}

	#[Test]
	#[TestDox('cancelSubscription on a Google sub returns store_managed for the Play store')]
	#[Group('mantle2/subscriptions')]
	public function cancelGoogleReturnsStoreManaged(): void
	{
		$user = $this->payingUser();
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'google',
			'active',
			time() + 86400,
			false,
			[
				'external_subscription_id' => 'google_token_1',
			],
		);

		$result = SubscriptionsHelper::cancelSubscription($user);
		$this->assertSame('store_managed', $result['result']);
		$this->assertSame('google', $result['provider']);
		$this->assertArrayHasKey('manage_url', $result);
	}

	#[Test]
	#[TestDox('refundLatestInvoice returns true on success and false when the refund call fails')]
	#[Group('mantle2/subscriptions')]
	public function refundLatestInvoice(): void
	{
		$user = $this->payingUser();
		$this->seedSubscription((int) $user->id(), [
			'external_subscription_id' => 'sub_test',
			'external_customer_id' => 'cus_new_1',
			'started_at' => time(),
		]);
		$this->assertTrue(SubscriptionsHelper::refundLatestInvoice($user));

		$this->fake->on('refunds.create', new ApiConnectionException('down'));
		$this->assertFalse(SubscriptionsHelper::refundLatestInvoice($user));
	}

	#endregion

	#region Trial codes (CRUD + redeem)

	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
		]);
	}

	#[Test]
	#[TestDox('createTrialCode auto-generates an EARTH-XXXX-XXXX code and persists the row')]
	#[Group('mantle2/subscriptions')]
	public function createTrialCodeAutoGen(): void
	{
		$admin = $this->admin();
		$code = SubscriptionsHelper::createTrialCode(
			AccountType::PRO,
			30,
			100,
			null,
			(int) $admin->id(),
		);

		$this->assertMatchesRegularExpression('/^EARTH-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code['code']);
		$this->assertSame('pro', $code['tier']);
		$this->assertSame(30, $code['days']);
		$this->assertSame(100, $code['max_redemptions']);
		$this->assertSame(0, $code['redemptions']);
		$this->assertTrue($code['active']);
		$this->assertNotNull(SubscriptionsHelper::getTrialCode($code['code']));
	}

	#[Test]
	#[TestDox('createTrialCode honours an explicit code and list/get/update/delete round-trip')]
	#[Group('mantle2/subscriptions')]
	public function trialCodeCrud(): void
	{
		$admin = $this->admin();
		$created = SubscriptionsHelper::createTrialCode(
			AccountType::WRITER,
			14,
			0,
			null,
			(int) $admin->id(),
			'EARTH-TEST-CODE',
		);
		$this->assertSame('EARTH-TEST-CODE', $created['code']);

		$codes = SubscriptionsHelper::listTrialCodes();
		$this->assertContains('EARTH-TEST-CODE', array_column($codes, 'code'));

		$this->assertNotNull(SubscriptionsHelper::getTrialCode('EARTH-TEST-CODE'));
		$this->assertNull(SubscriptionsHelper::getTrialCode('EARTH-NOPE-NOPE'));

		$updated = SubscriptionsHelper::updateTrialCode('EARTH-TEST-CODE', [
			'active' => false,
			'days' => 21,
		]);
		$this->assertNotNull($updated);
		$this->assertFalse($updated['active']);
		$this->assertSame(21, $updated['days']);
		$this->assertNull(
			SubscriptionsHelper::updateTrialCode('EARTH-NOPE-NOPE', ['active' => false]),
		);

		$this->assertTrue(SubscriptionsHelper::deleteTrialCode('EARTH-TEST-CODE'));
		$this->assertFalse(SubscriptionsHelper::deleteTrialCode('EARTH-TEST-CODE'));
	}

	#[Test]
	#[
		TestDox(
			'redeemTrialCode applies the trial tier, records the redemption, and returns the trial shape',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function redeemTrialCodeApplies(): void
	{
		$admin = $this->admin();
		$created = SubscriptionsHelper::createTrialCode(
			AccountType::PRO,
			30,
			0,
			null,
			(int) $admin->id(),
			'EARTH-REDE-EM01',
		);

		$user = $this->payingUser();
		$result = SubscriptionsHelper::redeemTrialCode($user, $created['code']);

		$this->assertSame('pro', $result['tier']);
		$this->assertSame(30, $result['days']);
		$this->assertArrayHasKey('trial_end', $result);
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType($this->reload($user)));

		$redemptions = (int) Drupal::database()
			->select('mantle2_trial_code_redemptions', 'r')
			->condition('code', $created['code'])
			->condition('user_id', (int) $user->id())
			->countQuery()
			->execute()
			->fetchField();
		$this->assertSame(1, $redemptions);
	}

	#[Test]
	#[TestDox('redeemTrialCode a second time for the same user does not double-apply')]
	#[Group('mantle2/subscriptions')]
	public function redeemTrialCodeIsSingleUsePerUser(): void
	{
		$admin = $this->admin();
		SubscriptionsHelper::createTrialCode(
			AccountType::PRO,
			30,
			0,
			null,
			(int) $admin->id(),
			'EARTH-ONCE-0001',
		);

		$user = $this->payingUser();
		SubscriptionsHelper::redeemTrialCode($user, 'EARTH-ONCE-0001');

		try {
			SubscriptionsHelper::redeemTrialCode($this->reload($user), 'EARTH-ONCE-0001');
		} catch (Throwable) {
			// helper may signal not-redeemable via exception; controller maps to 409
		}

		$redemptions = (int) Drupal::database()
			->select('mantle2_trial_code_redemptions', 'r')
			->condition('code', 'EARTH-ONCE-0001')
			->condition('user_id', (int) $user->id())
			->countQuery()
			->execute()
			->fetchField();
		$this->assertSame(1, $redemptions, 'a user may redeem a given code at most once');
	}

	#endregion

	#region Billing status

	#[Test]
	#[TestDox('getBillingStatus returns the free/none shape when there is no row')]
	#[Group('mantle2/subscriptions')]
	public function billingStatusNoSub(): void
	{
		$user = $this->payingUser();
		$status = SubscriptionsHelper::getBillingStatus($user);

		$this->assertSame('free', $status['tier']);
		$this->assertSame('none', $status['status']);
		$this->assertNull($status['provider']);
		$this->assertFalse($status['cancel_at_period_end']);
		$this->assertFalse($status['is_trial']);
		$this->assertFalse($status['refund_eligible']);
		$this->assertNull($status['refund_deadline']);
		$this->assertFalse($status['can_manage_billing']);
	}

	#[Test]
	#[
		TestDox(
			'getBillingStatus reports refund eligibility + ISO dates for an active in-window Stripe sub',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function billingStatusActive(): void
	{
		$user = $this->payingUser();
		$periodEnd = time() + 30 * 86400;
		$this->seedSubscription((int) $user->id(), [
			'status' => 'active',
			'tier' => 'pro',
			'current_period_end' => $periodEnd,
			'started_at' => time(),
		]);

		$status = SubscriptionsHelper::getBillingStatus($user);
		$this->assertSame('pro', $status['tier']);
		$this->assertSame('active', $status['status']);
		$this->assertSame('stripe', $status['provider']);
		$this->assertTrue($status['refund_eligible']);
		$this->assertNotNull($status['refund_deadline']);
		$this->assertStringEndsWith('+00:00', $status['current_period_end']);
		$this->assertTrue($status['can_manage_billing']);
	}

	#[Test]
	#[TestDox('getBillingStatus flags a trial-code trial as is_trial with a trial_end')]
	#[Group('mantle2/subscriptions')]
	public function billingStatusTrial(): void
	{
		$user = $this->payingUser();
		$trialEnd = time() + 20 * 86400;
		$this->seedSubscription((int) $user->id(), [
			'provider' => 'trial',
			'status' => 'trialing',
			'tier' => 'pro',
			'current_period_end' => $trialEnd,
			'started_at' => time(),
			'price_cents' => 0,
		]);

		$status = SubscriptionsHelper::getBillingStatus($user);
		$this->assertTrue($status['is_trial']);
		$this->assertSame('trial', $status['provider']);
		$this->assertNotNull($status['trial_end']);
	}

	#[Test]
	#[TestDox('hasActiveSubscription reflects the row status')]
	#[Group('mantle2/subscriptions')]
	public function hasActiveSubscription(): void
	{
		$user = $this->payingUser();
		$this->assertFalse(SubscriptionsHelper::hasActiveSubscription($user));

		$this->seedSubscription((int) $user->id(), ['status' => 'active']);
		$this->assertTrue(SubscriptionsHelper::hasActiveSubscription($this->reload($user)));
	}

	#endregion

	#region Stripe webhooks (end-to-end)

	#[Test]
	#[
		TestDox(
			'checkout.session.completed activates the referenced user and is idempotent on replay',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function webhookCheckoutCompleted(): void
	{
		$user = $this->payingUser();
		$uid = (string) $user->id();

		$object = [
			'id' => 'cs_hook_1',
			'object' => 'checkout.session',
			'mode' => 'subscription',
			'client_reference_id' => $uid,
			'customer' => 'cus_hook_1',
			'subscription' => 'sub_hook_1',
			'metadata' => ['uid' => $uid, 'tier' => 'pro'],
		];

		$first = $this->sendStripe('checkout.session.completed', $object, 'evt_checkout_1');
		$this->assertSame(Response::HTTP_OK, $first->getStatusCode());
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType($this->reload($user)));
		$countAfterFirst = count($this->billingNotifications($user));

		// replay of the same event id must be deduped: still 200, no second activation notice
		$replay = $this->sendStripe('checkout.session.completed', $object, 'evt_checkout_1');
		$this->assertSame(Response::HTTP_OK, $replay->getStatusCode());
		$this->assertSame($countAfterFirst, count($this->billingNotifications($user)));
	}

	#[Test]
	#[TestDox('customer.subscription.updated syncs tier + cancel_at_period_end onto the row')]
	#[Group('mantle2/subscriptions')]
	public function webhookSubscriptionUpdated(): void
	{
		$user = $this->payingUser();
		$this->seedSubscription((int) $user->id(), [
			'external_subscription_id' => 'sub_upd_1',
			'external_customer_id' => 'cus_upd_1',
			'tier' => 'pro',
		]);

		$object = [
			'id' => 'sub_upd_1',
			'object' => 'subscription',
			'customer' => 'cus_upd_1',
			'status' => 'active',
			'cancel_at_period_end' => true,
			'current_period_end' => time() + 45 * 86400,
			'items' => ['data' => [['price' => ['id' => 'price_writer_test']]]],
		];

		$response = $this->sendStripe('customer.subscription.updated', $object, 'evt_upd_1');
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());

		$row = $this->subscriptionRow((int) $user->id());
		$this->assertSame(1, (int) $row['cancel_at_period_end']);
		$this->assertSame('writer', $row['tier']);
	}

	#[Test]
	#[TestDox('customer.subscription.deleted revokes entitlement back to FREE')]
	#[Group('mantle2/subscriptions')]
	public function webhookSubscriptionDeleted(): void
	{
		$user = $this->payingUser();
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time() + 86400,
			false,
			[
				'external_subscription_id' => 'sub_del_1',
				'external_customer_id' => 'cus_del_1',
			],
		);

		$object = [
			'id' => 'sub_del_1',
			'object' => 'subscription',
			'customer' => 'cus_del_1',
			'status' => 'canceled',
		];
		$response = $this->sendStripe('customer.subscription.deleted', $object, 'evt_del_1');

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame(AccountType::FREE, UsersHelper::getAccountType($this->reload($user)));
		$this->assertContains($this->subscriptionRow((int) $user->id())['status'], [
			'canceled',
			'none',
		]);
	}

	#[Test]
	#[TestDox('invoice.paid renews: status active, started_at reset to the new period start')]
	#[Group('mantle2/subscriptions')]
	public function webhookInvoicePaidRenews(): void
	{
		$user = $this->payingUser();
		$oldStart = time() - 30 * 86400;
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time(),
			false,
			[
				'external_subscription_id' => 'sub_inv_1',
				'external_customer_id' => 'cus_inv_1',
				'started_at' => $oldStart,
			],
		);

		$newStart = time();
		$object = [
			'id' => 'in_paid_1',
			'object' => 'invoice',
			'subscription' => 'sub_inv_1',
			'customer' => 'cus_inv_1',
			'status' => 'paid',
			'period_start' => $newStart,
			'period_end' => $newStart + 30 * 86400,
			'lines' => [
				'data' => [['period' => ['start' => $newStart, 'end' => $newStart + 30 * 86400]]],
			],
		];

		$response = $this->sendStripe('invoice.paid', $object, 'evt_inv_1');
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());

		$row = $this->subscriptionRow((int) $user->id());
		$this->assertSame('active', $row['status']);
		$this->assertGreaterThan(
			$oldStart,
			(int) $row['started_at'],
			'started_at should reset forward on renewal',
		);
	}

	#[Test]
	#[TestDox('invoice.payment_failed moves the row to past_due and warns (dunning)')]
	#[Group('mantle2/subscriptions')]
	public function webhookInvoicePaymentFailed(): void
	{
		$user = $this->payingUser();
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time() + 86400,
			false,
			[
				'external_subscription_id' => 'sub_pf_1',
				'external_customer_id' => 'cus_pf_1',
			],
		);
		$before = count($this->billingNotifications($user));

		$object = [
			'id' => 'in_pf_1',
			'object' => 'invoice',
			'subscription' => 'sub_pf_1',
			'customer' => 'cus_pf_1',
			'status' => 'open',
			'attempt_count' => 1,
		];
		$response = $this->sendStripe('invoice.payment_failed', $object, 'evt_pf_1');

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame('past_due', $this->subscriptionRow((int) $user->id())['status']);
		$this->assertGreaterThan(
			$before,
			count($this->billingNotifications($user)),
			'dunning notice expected',
		);
	}

	#[Test]
	#[TestDox('invoice.payment_action_required warns about SCA and acks 200')]
	#[Group('mantle2/subscriptions')]
	public function webhookPaymentActionRequired(): void
	{
		$user = $this->payingUser();
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time() + 86400,
			false,
			[
				'external_subscription_id' => 'sub_sca_1',
				'external_customer_id' => 'cus_sca_1',
			],
		);
		$before = count($this->billingNotifications($user));

		$object = [
			'id' => 'in_sca_1',
			'object' => 'invoice',
			'subscription' => 'sub_sca_1',
			'customer' => 'cus_sca_1',
			'status' => 'open',
		];
		$response = $this->sendStripe('invoice.payment_action_required', $object, 'evt_sca_1');

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertGreaterThan(
			$before,
			count($this->billingNotifications($user)),
			'SCA notice expected',
		);
	}

	#[Test]
	#[TestDox('charge.refunded revokes entitlement and marks the row refunded')]
	#[Group('mantle2/subscriptions')]
	public function webhookChargeRefunded(): void
	{
		$user = $this->payingUser();
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time() + 86400,
			false,
			[
				'external_subscription_id' => 'sub_ref_1',
				'external_customer_id' => 'cus_ref_1',
			],
		);

		$object = [
			'id' => 'ch_ref_1',
			'object' => 'charge',
			'customer' => 'cus_ref_1',
			'refunded' => true,
			'amount_refunded' => 599,
		];
		$response = $this->sendStripe('charge.refunded', $object, 'evt_ref_1');

		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame(AccountType::FREE, UsersHelper::getAccountType($this->reload($user)));
		$this->assertSame('refunded', $this->subscriptionRow((int) $user->id())['status']);
	}

	#[Test]
	#[TestDox('an unknown event type is acknowledged with 200 and changes nothing')]
	#[Group('mantle2/subscriptions')]
	public function webhookUnknownEventIgnored(): void
	{
		$user = $this->payingUser();
		$this->seedSubscription((int) $user->id(), ['status' => 'active', 'tier' => 'pro']);

		$response = $this->sendStripe(
			'customer.discount.created',
			['id' => 'di_1', 'object' => 'discount'],
			'evt_unknown_1',
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame('active', $this->subscriptionRow((int) $user->id())['status']);
	}

	#[Test]
	#[TestDox('a bad signature is rejected with 400 and never mutates state')]
	#[Group('mantle2/subscriptions')]
	public function webhookBadSignatureRejected(): void
	{
		$user = $this->payingUser();
		$uid = (string) $user->id();
		$body = json_encode([
			'id' => 'evt_bad_sig',
			'object' => 'event',
			'type' => 'checkout.session.completed',
			'data' => [
				'object' => [
					'client_reference_id' => $uid,
					'metadata' => ['uid' => $uid, 'tier' => 'pro'],
				],
			],
		]);

		$response = SubscriptionsHelper::handleStripeWebhook($body, 't=' . time() . ',v1=deadbeef');
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$this->assertSame(AccountType::FREE, UsersHelper::getAccountType($this->reload($user)));
	}

	#[Test]
	#[TestDox('out-of-order recovery: payment_failed then invoice.paid converges to active')]
	#[Group('mantle2/subscriptions')]
	public function webhookOutOfOrderRecovery(): void
	{
		$user = $this->payingUser();
		SubscriptionsHelper::applyEntitlement(
			$user,
			AccountType::PRO,
			'stripe',
			'active',
			time() + 86400,
			false,
			[
				'external_subscription_id' => 'sub_ooo_1',
				'external_customer_id' => 'cus_ooo_1',
				'started_at' => time(),
			],
		);

		$failObj = [
			'id' => 'in_ooo_f',
			'object' => 'invoice',
			'subscription' => 'sub_ooo_1',
			'customer' => 'cus_ooo_1',
			'status' => 'open',
		];
		$this->sendStripe('invoice.payment_failed', $failObj, 'evt_ooo_fail');
		$this->assertSame('past_due', $this->subscriptionRow((int) $user->id())['status']);

		$paidObj = [
			'id' => 'in_ooo_p',
			'object' => 'invoice',
			'subscription' => 'sub_ooo_1',
			'customer' => 'cus_ooo_1',
			'status' => 'paid',
			'period_start' => time(),
			'period_end' => time() + 30 * 86400,
		];
		$this->sendStripe('invoice.paid', $paidObj, 'evt_ooo_paid');

		$this->assertSame('active', $this->subscriptionRow((int) $user->id())['status']);
		$this->assertSame(AccountType::PRO, UsersHelper::getAccountType($this->reload($user)));
	}

	#[Test]
	#[TestDox('the redis dedupe marker is set after a webhook is processed')]
	#[Group('mantle2/subscriptions')]
	public function webhookMarksProcessed(): void
	{
		$user = $this->payingUser();
		$uid = (string) $user->id();
		$object = [
			'id' => 'cs_dedupe',
			'object' => 'checkout.session',
			'client_reference_id' => $uid,
			'customer' => 'cus_dedupe',
			'subscription' => 'sub_dedupe',
			'metadata' => ['uid' => $uid, 'tier' => 'pro'],
		];
		$this->sendStripe('checkout.session.completed', $object, 'evt_dedupe_1');

		// per contract the idempotency key is stripe:webhook:{event_id}
		$this->assertNotNull(RedisHelper::get('stripe:webhook:evt_dedupe_1'));
	}

	#endregion

	#region Admin trials (redemptions detail, notify, prune, lookup)

	private function mails(): array
	{
		return Drupal::state()->get('system.test_mail_collector') ?? [];
	}

	private function insertRedemption(
		string $code,
		int $uid,
		int $redeemedAt,
		?string $tier,
		?int $expiresAt,
	): void {
		Drupal::database()
			->insert('mantle2_trial_code_redemptions')
			->fields([
				'code' => strtoupper($code),
				'user_id' => $uid,
				'redeemed_at' => $redeemedAt,
				'tier' => $tier,
				'expires_at' => $expiresAt,
			])
			->execute();
	}

	private function redemptionRow(string $code, int $uid): ?array
	{
		$row = Drupal::database()
			->select('mantle2_trial_code_redemptions', 'r')
			->fields('r')
			->condition('code', strtoupper($code))
			->condition('user_id', $uid)
			->execute()
			->fetchAssoc();
		return $row ?: null;
	}

	#[Test]
	#[TestDox('redeemTrialCode stores the tier and a future expires_at on the redemption row')]
	#[Group('mantle2/subscriptions')]
	public function redeemStoresTierAndExpiry(): void
	{
		$admin = $this->admin();
		SubscriptionsHelper::createTrialCode(
			AccountType::WRITER,
			30,
			0,
			null,
			(int) $admin->id(),
			'EARTH-DETL-0001',
		);

		$user = $this->payingUser();
		$before = time();
		SubscriptionsHelper::redeemTrialCode($user, 'EARTH-DETL-0001');

		$row = $this->redemptionRow('EARTH-DETL-0001', (int) $user->id());
		$this->assertNotNull($row);
		$this->assertSame('writer', $row['tier']);
		$this->assertNotNull($row['expires_at']);
		// 30 days out, give or take the second the redeem ran
		$this->assertGreaterThanOrEqual($before + 30 * 86400, (int) $row['expires_at']);
		$this->assertLessThanOrEqual(time() + 30 * 86400, (int) $row['expires_at']);
	}

	#[Test]
	#[TestDox('listRedemptions marks active vs expired redeemers and orders active first')]
	#[Group('mantle2/subscriptions')]
	public function listRedemptionsActiveVsExpired(): void
	{
		$admin = $this->admin();
		SubscriptionsHelper::createTrialCode(
			AccountType::PRO,
			30,
			0,
			null,
			(int) $admin->id(),
			'EARTH-REDS-0001',
		);

		$now = time();
		$activeUser = $this->payingUser();
		$expiredUser = $this->payingUser();
		$goneUid = 987654; // no such user

		// active: expiry in the future, most recently redeemed
		$this->insertRedemption(
			'EARTH-REDS-0001',
			(int) $activeUser->id(),
			$now,
			'pro',
			$now + 20 * 86400,
		);
		// expired: expiry in the past, redeemed earlier
		$this->insertRedemption(
			'EARTH-REDS-0001',
			(int) $expiredUser->id(),
			$now - 40 * 86400,
			'pro',
			$now - 5 * 86400,
		);
		// pruned/gone user: detail already nulled
		$this->insertRedemption('EARTH-REDS-0001', $goneUid, $now - 10 * 86400, null, null);

		$result = SubscriptionsHelper::listRedemptions('EARTH-REDS-0001');
		$this->assertSame(3, $result['total_count']);
		$this->assertSame(1, $result['active_count']);

		// active first
		$this->assertTrue($result['redemptions'][0]['active']);
		$this->assertSame((int) $activeUser->id(), $result['redemptions'][0]['uid']);
		$this->assertSame($activeUser->getAccountName(), $result['redemptions'][0]['username']);
		$this->assertNotNull($result['redemptions'][0]['expires_at']);

		// gone user keeps its uid as the username fallback
		$byUid = [];
		foreach ($result['redemptions'] as $r) {
			$byUid[$r['uid']] = $r;
		}
		$this->assertFalse($byUid[(int) $expiredUser->id()]['active']);
		$this->assertFalse($byUid[$goneUid]['active']);
		$this->assertSame((string) $goneUid, $byUid[$goneUid]['username']);
		$this->assertNull($byUid[$goneUid]['tier']);
	}

	#[Test]
	#[
		TestDox(
			'notifyRedeemers notifies only active redeemers via notification + trial_broadcast email',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function notifyRedeemersHitsActiveOnly(): void
	{
		$admin = $this->admin();
		SubscriptionsHelper::createTrialCode(
			AccountType::PRO,
			30,
			0,
			null,
			(int) $admin->id(),
			'EARTH-NOTI-0001',
		);

		$now = time();
		$active = $this->payingUser();
		$expired = $this->payingUser();
		$this->insertRedemption(
			'EARTH-NOTI-0001',
			(int) $active->id(),
			$now,
			'pro',
			$now + 10 * 86400,
		);
		$this->insertRedemption(
			'EARTH-NOTI-0001',
			(int) $expired->id(),
			$now - 20 * 86400,
			'pro',
			$now - 1,
		);

		$activeBefore = count($this->billingNotifications($active));
		$expiredBefore = count($this->billingNotifications($expired));
		Drupal::state()->set('system.test_mail_collector', []);

		$notified = SubscriptionsHelper::notifyRedeemers(
			'EARTH-NOTI-0001',
			'Heads Up',
			'Your trial ends soon.',
		);
		$this->assertSame(1, $notified);

		// active user gets a new billing notification; expired user does not
		$this->assertSame($activeBefore + 1, count($this->billingNotifications($active)));
		$this->assertSame($expiredBefore, count($this->billingNotifications($expired)));

		// exactly one trial_broadcast email, addressed to the active user
		$broadcasts = array_values(
			array_filter($this->mails(), fn($m) => ($m['key'] ?? '') === 'trial_broadcast'),
		);
		$this->assertCount(1, $broadcasts);
		$this->assertSame($active->getEmail(), $broadcasts[0]['to']);
	}

	#[Test]
	#[TestDox('pruneExpiredRedemptionDetail nulls detail on expired rows but keeps the guard row')]
	#[Group('mantle2/subscriptions')]
	public function prunePreservesGuardRow(): void
	{
		$admin = $this->admin();
		SubscriptionsHelper::createTrialCode(
			AccountType::PRO,
			30,
			0,
			null,
			(int) $admin->id(),
			'EARTH-PRUN-0001',
		);

		$user = $this->payingUser();
		SubscriptionsHelper::redeemTrialCode($user, 'EARTH-PRUN-0001');
		$uid = (int) $user->id();

		// force the trial to have ended, then prune
		Drupal::database()
			->update('mantle2_trial_code_redemptions')
			->fields(['expires_at' => time() - 3600])
			->condition('code', 'EARTH-PRUN-0001')
			->condition('user_id', $uid)
			->execute();

		$touched = SubscriptionsHelper::pruneExpiredRedemptionDetail();
		$this->assertGreaterThanOrEqual(1, $touched);

		// guard row survives with detail nulled
		$row = $this->redemptionRow('EARTH-PRUN-0001', $uid);
		$this->assertNotNull($row, 'the guard row must never be deleted');
		$this->assertNull($row['tier']);
		$this->assertNull($row['expires_at']);

		// and the user still cannot re-redeem the same code
		$again = SubscriptionsHelper::redeemTrialCode($this->reload($user), 'EARTH-PRUN-0001');
		$this->assertSame('not_redeemable', $again['error']);

		$count = (int) Drupal::database()
			->select('mantle2_trial_code_redemptions', 'r')
			->condition('code', 'EARTH-PRUN-0001')
			->condition('user_id', $uid)
			->countQuery()
			->execute()
			->fetchField();
		$this->assertSame(1, $count, 're-redeem must not create a second guard row');
	}

	#[Test]
	#[TestDox('pruneExpiredRedemptionDetail leaves active (future) redemptions untouched')]
	#[Group('mantle2/subscriptions')]
	public function pruneLeavesActiveRows(): void
	{
		$admin = $this->admin();
		SubscriptionsHelper::createTrialCode(
			AccountType::PRO,
			30,
			0,
			null,
			(int) $admin->id(),
			'EARTH-KEEP-0001',
		);

		$user = $this->payingUser();
		$now = time();
		$this->insertRedemption(
			'EARTH-KEEP-0001',
			(int) $user->id(),
			$now,
			'pro',
			$now + 10 * 86400,
		);

		SubscriptionsHelper::pruneExpiredRedemptionDetail();

		$row = $this->redemptionRow('EARTH-KEEP-0001', (int) $user->id());
		$this->assertSame('pro', $row['tier']);
		$this->assertNotNull($row['expires_at']);
	}

	#[Test]
	#[TestDox('lookupUsersForAdmin resolves by id, exact username, email, and name fragment')]
	#[Group('mantle2/subscriptions')]
	public function lookupResolvesEveryKind(): void
	{
		$user = $this->createUser([
			'name' => 'lookupfan',
			'mail' => 'lookupfan@example.com',
			'field_first_name' => 'Zephyrina',
			'field_last_name' => 'Quibblewick',
		]);
		$id = (int) $user->id();

		$ids = fn(array $res) => array_column($res['matches'], 'id');

		$this->assertContains(
			$id,
			$ids(SubscriptionsHelper::lookupUsersForAdmin((string) $id)),
			'by numeric id',
		);
		$this->assertContains(
			$id,
			$ids(SubscriptionsHelper::lookupUsersForAdmin('lookupfan')),
			'by exact username',
		);
		$this->assertContains(
			$id,
			$ids(SubscriptionsHelper::lookupUsersForAdmin('lookupfan@example.com')),
			'by email',
		);

		$byName = SubscriptionsHelper::lookupUsersForAdmin('Zephyr');
		$this->assertContains($id, $ids($byName), 'by first-name fragment');

		$match = null;
		foreach ($byName['matches'] as $m) {
			if ($m['id'] === $id) {
				$match = $m;
			}
		}
		$this->assertNotNull($match);
		$this->assertSame('lookupfan', $match['username']);
		$this->assertSame('lookupfan@example.com', $match['email']);
		$this->assertSame('Zephyrina Quibblewick', $match['full_name']);
		$this->assertArrayHasKey('subscription', $match);
		$this->assertArrayHasKey('tier', $match['subscription']);
	}

	#[Test]
	#[TestDox('lookupUsersForAdmin de-dupes a user matched by both username and name')]
	#[Group('mantle2/subscriptions')]
	public function lookupDeDupesById(): void
	{
		// username equals a name fragment so both resolution paths hit the same user
		$user = $this->createUser([
			'name' => 'novalene',
			'mail' => 'novalene@example.com',
			'field_first_name' => 'Novalene',
			'field_last_name' => 'Starbright',
		]);
		$id = (int) $user->id();

		$res = SubscriptionsHelper::lookupUsersForAdmin('novalene');
		$hits = array_filter($res['matches'], fn($m) => $m['id'] === $id);
		$this->assertCount(1, $hits, 'a user must appear at most once');
	}

	#endregion
}
