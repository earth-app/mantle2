<?php

namespace Drupal\Tests\mantle2\Integration\Controller\Users;

use Drupal\mantle2\Controller\AdminController;
use Drupal\mantle2\Controller\UsersController;
use Drupal\mantle2\Custom\AccountType;
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

// controller-tier coverage of the billing surface; the UsersController regions cover the
// user-facing + webhook endpoints, and the AdminController region covers trial-code + refund.
// method names track the endpoint semantics (see report) since the contract pins the routes/
// shapes but not the PHP method names.
class SubscriptionsTest extends IntegrationTestBase
{
	private FakeStripeClient $fake;

	protected function setUp(): void
	{
		parent::setUp();
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
		$this->configureStripe();

		$fake = $this->newFakeStripe();
		$fake->on('customers.create', Customer::constructFrom(['id' => 'cus_ctl_1']));
		$fake->on(
			'checkout.sessions.create',
			CheckoutSession::constructFrom([
				'id' => 'cs_ctl_1',
				'url' => 'https://checkout.stripe.com/pay/cs_ctl_1',
			]),
		);
		$fake->on(
			'billingPortal.sessions.create',
			PortalSession::constructFrom(['url' => 'https://billing.stripe.com/p/session/ctl_1']),
		);
		$fake->on(
			'refunds.create',
			Refund::constructFrom(['id' => 're_ctl_1', 'status' => 'succeeded']),
		);
		$fake->on(
			'invoices.all',
			Collection::constructFrom([
				'data' => [['id' => 'in_ctl_1', 'payment_intent' => 'pi_ctl_1']],
			]),
		);
		$fake->on(
			'subscriptions.retrieve',
			Subscription::constructFrom([
				'id' => 'sub_test',
				'status' => 'active',
				'current_period_end' => time() + 30 * 86400,
				'items' => ['data' => [['price' => ['id' => 'price_pro_test']]]],
				'latest_invoice' => 'in_test',
			]),
		);
		$fake->on(
			'subscriptions.update',
			Subscription::constructFrom(['id' => 'sub_test', 'cancel_at_period_end' => true]),
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

	private function controller(): UsersController
	{
		return UsersController::create($this->container);
	}

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

	private function payingUser(): UserInterface
	{
		$user = $this->createUser(['field_email_verified' => true]);
		UsersHelper::setSubscribed($user, true);
		$user->save();
		return $user;
	}

	#region Plans (public)

	#[Test]
	#[TestDox('GET /v2/subscriptions/plans is public and returns plans + refund window')]
	#[Group('mantle2/subscriptions')]
	public function plansPublic(): void
	{
		$response = $this->controller()->subscriptionPlans(
			$this->request('GET', '/v2/subscriptions/plans'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertArrayHasKey('plans', $body);
		$this->assertSame(14, $body['refund_window_days']);
		$this->assertNotEmpty($body['plans']);
	}

	#endregion

	#region GET current subscription

	#[Test]
	#[TestDox('GET current subscription 401s anon and returns the free shape for a fresh user')]
	#[Group('mantle2/subscriptions')]
	public function getCurrentSubscription(): void
	{
		$anon = $this->controller()->currentSubscription(
			$this->request('GET', '/v2/users/current/subscription'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->payingUser();
		$response = $this->controller()->currentSubscription(
			$this->authRequest($user, 'GET', '/v2/users/current/subscription'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame('free', $body['tier']);
		$this->assertSame('none', $body['status']);
	}

	#endregion

	#region Checkout

	#[Test]
	#[TestDox('POST checkout 401 anon, 400 bad tier, 400 missing consent, 200 on success')]
	#[Group('mantle2/subscriptions')]
	public function checkout(): void
	{
		$anon = $this->controller()->subscriptionCheckout(
			$this->request(
				'POST',
				'/v2/users/current/subscription/checkout',
				[],
				'{"tier":"pro","consent":true}',
			),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->payingUser();

		$badTier = $this->controller()->subscriptionCheckout(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/checkout',
				[],
				'{"tier":"platinum","consent":true}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badTier->getStatusCode());

		$noConsent = $this->controller()->subscriptionCheckout(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/checkout',
				[],
				'{"tier":"pro"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noConsent->getStatusCode());

		$ok = $this->controller()->subscriptionCheckout(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/checkout',
				[],
				'{"tier":"pro","consent":true}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('https://checkout.stripe.com/pay/cs_ctl_1', $body['url']);
		$this->assertSame('cs_ctl_1', $body['session_id']);
	}

	#[Test]
	#[TestDox('POST checkout 409s when the user already has an active subscription (any provider)')]
	#[Group('mantle2/subscriptions')]
	public function checkoutConflictWhenActive(): void
	{
		$user = $this->payingUser();
		// active apple sub -> a new stripe checkout is a cross-provider conflict
		$this->seedSubscription((int) $user->id(), ['provider' => 'apple', 'status' => 'active']);

		$response = $this->controller()->subscriptionCheckout(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/checkout',
				[],
				'{"tier":"pro","consent":true}',
			),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
	}

	#[Test]
	#[TestDox('POST checkout maps a declined card to 402 and a generic Stripe error to 500')]
	#[Group('mantle2/subscriptions')]
	public function checkoutStripeErrors(): void
	{
		$user = $this->payingUser();

		$this->fake->on(
			'checkout.sessions.create',
			CardException::factory(
				'declined',
				402,
				null,
				null,
				null,
				'card_declined',
				'generic_decline',
			),
		);
		$declined = $this->controller()->subscriptionCheckout(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/checkout',
				[],
				'{"tier":"pro","consent":true}',
			),
		);
		$this->assertSame(Response::HTTP_PAYMENT_REQUIRED, $declined->getStatusCode());

		$other = $this->payingUser();
		$this->fake->on('checkout.sessions.create', new ApiConnectionException('timeout'));
		$err = $this->controller()->subscriptionCheckout(
			$this->authRequest(
				$other,
				'POST',
				'/v2/users/current/subscription/checkout',
				[],
				'{"tier":"pro","consent":true}',
			),
		);
		$this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $err->getStatusCode());
	}

	#endregion

	#region Portal

	#[Test]
	#[TestDox('POST portal 401 anon, 404 no customer, 409 non-stripe, 200 with a stripe customer')]
	#[Group('mantle2/subscriptions')]
	public function portal(): void
	{
		$anon = $this->controller()->subscriptionPortal(
			$this->request('POST', '/v2/users/current/subscription/portal', [], '{}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$noCustomer = $this->payingUser();
		$this->seedSubscription((int) $noCustomer->id(), ['external_customer_id' => null]);
		$noCust = $this->controller()->subscriptionPortal(
			$this->authRequest(
				$noCustomer,
				'POST',
				'/v2/users/current/subscription/portal',
				[],
				'{}',
			),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $noCust->getStatusCode());

		$appleUser = $this->payingUser();
		$this->seedSubscription((int) $appleUser->id(), [
			'provider' => 'apple',
			'external_customer_id' => null,
		]);
		$nonStripe = $this->controller()->subscriptionPortal(
			$this->authRequest(
				$appleUser,
				'POST',
				'/v2/users/current/subscription/portal',
				[],
				'{}',
			),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $nonStripe->getStatusCode());

		$stripeUser = $this->payingUser();
		$this->seedSubscription((int) $stripeUser->id(), ['external_customer_id' => 'cus_ctl_1']);
		$ok = $this->controller()->subscriptionPortal(
			$this->authRequest(
				$stripeUser,
				'POST',
				'/v2/users/current/subscription/portal',
				[],
				'{}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('https://billing.stripe.com/p/session/ctl_1', $this->decode($ok)['url']);
	}

	#endregion

	#region Cancel

	#[Test]
	#[TestDox('POST cancel 401 anon, 404 no active sub, 200 refunded within window')]
	#[Group('mantle2/subscriptions')]
	public function cancel(): void
	{
		$anon = $this->controller()->subscriptionCancel(
			$this->request('POST', '/v2/users/current/subscription/cancel', [], '{}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$noSub = $this->payingUser();
		$none = $this->controller()->subscriptionCancel(
			$this->authRequest($noSub, 'POST', '/v2/users/current/subscription/cancel', [], '{}'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $none->getStatusCode());

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
				'external_customer_id' => 'cus_ctl_1',
				'started_at' => time(),
			],
		);
		$refunded = $this->controller()->subscriptionCancel(
			$this->authRequest($user, 'POST', '/v2/users/current/subscription/cancel', [], '{}'),
		);
		$this->assertSame(Response::HTTP_OK, $refunded->getStatusCode());
		$this->assertSame('refunded', $this->decode($refunded)['result']);
	}

	#endregion

	#region Redeem code

	private function seedCode(
		string $code,
		AccountType $tier = AccountType::PRO,
		int $days = 30,
	): void {
		SubscriptionsHelper::createTrialCode(
			$tier,
			$days,
			0,
			null,
			(int) $this->admin()->id(),
			$code,
		);
	}

	#[Test]
	#[TestDox('POST redeem-code 401 anon, 400 malformed, 404 unknown, 200 on a valid code')]
	#[Group('mantle2/subscriptions')]
	public function redeemCode(): void
	{
		$anon = $this->controller()->subscriptionRedeemCode(
			$this->request(
				'POST',
				'/v2/users/current/subscription/redeem-code',
				[],
				'{"code":"EARTH-AAAA-BBBB"}',
			),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->payingUser();

		$malformed = $this->controller()->subscriptionRedeemCode(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/redeem-code',
				[],
				'{}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $malformed->getStatusCode());

		$unknown = $this->controller()->subscriptionRedeemCode(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/redeem-code',
				[],
				'{"code":"EARTH-ZZZZ-9999"}',
			),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $unknown->getStatusCode());

		$this->seedCode('EARTH-GOOD-0001');
		$ok = $this->controller()->subscriptionRedeemCode(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/redeem-code',
				[],
				'{"code":"EARTH-GOOD-0001"}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('pro', $body['tier']);
		$this->assertSame(30, $body['days']);
		$this->assertArrayHasKey('trial_end', $body);
	}

	#[Test]
	#[TestDox('POST redeem-code 409s a code the user already redeemed')]
	#[Group('mantle2/subscriptions')]
	public function redeemCodeAlreadyRedeemed(): void
	{
		$this->seedCode('EARTH-TWICE-01');
		$user = $this->payingUser();

		$first = $this->controller()->subscriptionRedeemCode(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/redeem-code',
				[],
				'{"code":"EARTH-TWICE-01"}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $first->getStatusCode());

		$again = $this->controller()->subscriptionRedeemCode(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/redeem-code',
				[],
				'{"code":"EARTH-TWICE-01"}',
			),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $again->getStatusCode());
	}

	#[Test]
	#[TestDox('POST redeem-code 409s when the user already has an active paid subscription')]
	#[Group('mantle2/subscriptions')]
	public function redeemCodeWithActivePaidSub(): void
	{
		$this->seedCode('EARTH-PAID-0001');
		$user = $this->payingUser();
		$this->seedSubscription((int) $user->id(), [
			'provider' => 'stripe',
			'status' => 'active',
			'tier' => 'pro',
		]);

		$response = $this->controller()->subscriptionRedeemCode(
			$this->authRequest(
				$user,
				'POST',
				'/v2/users/current/subscription/redeem-code',
				[],
				'{"code":"EARTH-PAID-0001"}',
			),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
	}

	#endregion

	#region IAP verify (dormant / unconfigured providers)

	#[Test]
	#[TestDox('POST apple verify returns 503 when Apple IAP is not configured')]
	#[Group('mantle2/subscriptions')]
	public function appleVerifyUnconfigured(): void
	{
		// apple key/config never seeded in this suite (only stripe) -> 503 per contract.
		// 400 bad-payload / 402 validation-failed / 409 cross-provider require configured
		// apple credentials and are exercised in E2E/manual.
		$user = $this->payingUser();
		$response = $this->controller()->iapAppleVerify(
			$this->authRequest(
				$user,
				'POST',
				'/v2/subscriptions/iap/apple/verify',
				[],
				'{"transaction_id":"t1","product_id":"com.earthapp.pro","signed_payload":"eyJ4NWMiOltdfQ.e30.sig"}',
			),
		);
		$this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
	}

	#[Test]
	#[TestDox('POST google verify returns 503 while Google IAP is dormant/unconfigured')]
	#[Group('mantle2/subscriptions')]
	public function googleVerifyUnconfigured(): void
	{
		$user = $this->payingUser();
		$response = $this->controller()->iapGoogleVerify(
			$this->authRequest(
				$user,
				'POST',
				'/v2/subscriptions/iap/google/verify',
				[],
				'{"purchase_token":"tok","product_id":"pro","package_name":"com.earthapp"}',
			),
		);
		$this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
	}

	#endregion

	#region Webhooks (controller wrappers)

	#[Test]
	#[TestDox('POST /v2/webhooks/stripe 400s a bad signature and 200s a valid event')]
	#[Group('mantle2/subscriptions')]
	public function stripeWebhookEndpoint(): void
	{
		$user = $this->payingUser();
		$uid = (string) $user->id();
		$body = json_encode([
			'id' => 'evt_ctl_hook',
			'object' => 'event',
			'type' => 'checkout.session.completed',
			'data' => [
				'object' => [
					'id' => 'cs_ctl_hook',
					'object' => 'checkout.session',
					'client_reference_id' => $uid,
					'customer' => 'cus_ctl_hook',
					'subscription' => 'sub_ctl_hook',
					'metadata' => ['uid' => $uid, 'tier' => 'pro'],
				],
			],
		]);

		$bad = $this->request('POST', '/v2/webhooks/stripe', [], $body);
		$bad->headers->set('Stripe-Signature', 't=' . time() . ',v1=deadbeef');
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			$this->controller()->webhookStripe($bad)->getStatusCode(),
		);

		$good = $this->request('POST', '/v2/webhooks/stripe', [], $body);
		$good->headers->set('Stripe-Signature', $this->signStripePayload($body));
		$response = $this->controller()->webhookStripe($good);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame(
			AccountType::PRO,
			UsersHelper::getAccountType(UsersHelper::findById((int) $user->id())),
		);
	}

	#[Test]
	#[TestDox('POST /v2/webhooks/apple acks 200 even when Apple is unconfigured')]
	#[Group('mantle2/subscriptions')]
	public function appleWebhookAcks(): void
	{
		$request = $this->request(
			'POST',
			'/v2/webhooks/apple',
			[],
			'{"signedPayload":"eyJ4NWMiOltdfQ.e30.sig"}',
		);
		$this->assertSame(
			Response::HTTP_OK,
			$this->controller()->webhookApple($request)->getStatusCode(),
		);
	}

	#[Test]
	#[TestDox('POST /v2/webhooks/google acks 200 even when Google is unconfigured')]
	#[Group('mantle2/subscriptions')]
	public function googleWebhookAcks(): void
	{
		$request = $this->request(
			'POST',
			'/v2/webhooks/google',
			[],
			'{"message":{"data":"eyJ0ZXN0IjoxfQ==","messageId":"m1"}}',
		);
		$this->assertSame(
			Response::HTTP_OK,
			$this->controller()->webhookGoogle($request)->getStatusCode(),
		);
	}

	#endregion

	#region Admin trial codes + refund (AdminController)

	private function adminController(): AdminController
	{
		return new AdminController();
	}

	#[Test]
	#[TestDox('POST /v2/admin/trial-codes 401 anon, 403 non-admin, 201 admin')]
	#[Group('mantle2/subscriptions')]
	public function adminCreateTrialCode(): void
	{
		$anon = $this->adminController()->createTrialCode(
			$this->request('POST', '/v2/admin/trial-codes', [], '{"tier":"pro","days":30}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$nonAdmin = $this->adminController()->createTrialCode(
			$this->authRequest(
				$this->payingUser(),
				'POST',
				'/v2/admin/trial-codes',
				[],
				'{"tier":"pro","days":30}',
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $nonAdmin->getStatusCode());

		$created = $this->adminController()->createTrialCode(
			$this->authRequest(
				$this->admin(),
				'POST',
				'/v2/admin/trial-codes',
				[],
				'{"tier":"pro","days":30,"max_redemptions":100}',
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $created->getStatusCode());
		$body = $this->decode($created);
		$this->assertSame('pro', $body['tier']);
		$this->assertSame(30, $body['days']);
		$this->assertMatchesRegularExpression('/^EARTH-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $body['code']);
	}

	#[Test]
	#[TestDox('GET /v2/admin/trial-codes lists codes for an admin')]
	#[Group('mantle2/subscriptions')]
	public function adminListTrialCodes(): void
	{
		SubscriptionsHelper::createTrialCode(
			AccountType::PRO,
			30,
			0,
			null,
			(int) $this->admin()->id(),
			'EARTH-LIST-0001',
		);

		$response = $this->adminController()->listTrialCodes(
			$this->authRequest($this->admin(), 'GET', '/v2/admin/trial-codes'),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertArrayHasKey('codes', $body);
		$this->assertContains('EARTH-LIST-0001', array_column($body['codes'], 'code'));
	}

	#[Test]
	#[TestDox('PATCH /v2/admin/trial-codes/{code} updates a code and 404s unknown')]
	#[Group('mantle2/subscriptions')]
	public function adminUpdateTrialCode(): void
	{
		SubscriptionsHelper::createTrialCode(
			AccountType::PRO,
			30,
			0,
			null,
			(int) $this->admin()->id(),
			'EARTH-UPDT-0001',
		);

		$updated = $this->adminController()->patchTrialCode(
			$this->authRequest(
				$this->admin(),
				'PATCH',
				'/v2/admin/trial-codes/EARTH-UPDT-0001',
				[],
				'{"active":false}',
			),
			'EARTH-UPDT-0001',
		);
		$this->assertSame(Response::HTTP_OK, $updated->getStatusCode());
		$this->assertFalse($this->decode($updated)['active']);

		$missing = $this->adminController()->patchTrialCode(
			$this->authRequest(
				$this->admin(),
				'PATCH',
				'/v2/admin/trial-codes/EARTH-NONE-0000',
				[],
				'{"active":false}',
			),
			'EARTH-NONE-0000',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE /v2/admin/trial-codes/{code} 204s then 404s')]
	#[Group('mantle2/subscriptions')]
	public function adminDeleteTrialCode(): void
	{
		SubscriptionsHelper::createTrialCode(
			AccountType::PRO,
			30,
			0,
			null,
			(int) $this->admin()->id(),
			'EARTH-DELT-0001',
		);

		$deleted = $this->adminController()->deleteTrialCode(
			$this->authRequest($this->admin(), 'DELETE', '/v2/admin/trial-codes/EARTH-DELT-0001'),
			'EARTH-DELT-0001',
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $deleted->getStatusCode());

		$again = $this->adminController()->deleteTrialCode(
			$this->authRequest($this->admin(), 'DELETE', '/v2/admin/trial-codes/EARTH-DELT-0001'),
			'EARTH-DELT-0001',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $again->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/admin/users/{id}/refund refunds an active sub and 404s when there is none')]
	#[Group('mantle2/subscriptions')]
	public function adminRefundUser(): void
	{
		$admin = $this->admin();

		$noSub = $this->payingUser();
		$none = $this->adminController()->refundUser(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/admin/users/' . $noSub->id() . '/refund',
				[],
				'{"reason":"test"}',
			),
			(string) $noSub->id(),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $none->getStatusCode());

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
				'external_customer_id' => 'cus_ctl_1',
				'started_at' => time(),
			],
		);
		$refunded = $this->adminController()->refundUser(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/admin/users/' . $user->id() . '/refund',
				[],
				'{"reason":"goodwill"}',
			),
			(string) $user->id(),
		);
		$this->assertSame(Response::HTTP_OK, $refunded->getStatusCode());
		$this->assertSame('refunded', $this->decode($refunded)['result']);
		$this->assertSame(
			AccountType::FREE,
			UsersHelper::getAccountType(UsersHelper::findById((int) $user->id())),
		);
	}

	#[Test]
	#[
		TestDox(
			'GET /v2/admin/trial-codes/{code}/redemptions 401 anon, 403 non-admin, 200 admin, 404 unknown',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function adminListRedemptions(): void
	{
		$this->seedCode('EARTH-RDMP-0001');
		$redeemer = $this->payingUser();
		SubscriptionsHelper::redeemTrialCode($redeemer, 'EARTH-RDMP-0001');

		$anon = $this->adminController()->listRedemptions(
			$this->request('GET', '/v2/admin/trial-codes/EARTH-RDMP-0001/redemptions'),
			'EARTH-RDMP-0001',
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$nonAdmin = $this->adminController()->listRedemptions(
			$this->authRequest(
				$this->payingUser(),
				'GET',
				'/v2/admin/trial-codes/EARTH-RDMP-0001/redemptions',
			),
			'EARTH-RDMP-0001',
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $nonAdmin->getStatusCode());

		$ok = $this->adminController()->listRedemptions(
			$this->authRequest(
				$this->admin(),
				'GET',
				'/v2/admin/trial-codes/EARTH-RDMP-0001/redemptions',
			),
			'EARTH-RDMP-0001',
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertArrayHasKey('redemptions', $body);
		$this->assertSame(1, $body['total_count']);
		$this->assertSame(1, $body['active_count']);
		$this->assertSame((int) $redeemer->id(), $body['redemptions'][0]['uid']);
		$this->assertTrue($body['redemptions'][0]['active']);

		$missing = $this->adminController()->listRedemptions(
			$this->authRequest(
				$this->admin(),
				'GET',
				'/v2/admin/trial-codes/EARTH-NONE-0000/redemptions',
			),
			'EARTH-NONE-0000',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/admin/trial-codes/{code}/notify 401/403, 400 bad body, 404 unknown, 200 admin',
		),
	]
	#[Group('mantle2/subscriptions')]
	public function adminNotifyRedeemers(): void
	{
		$this->seedCode('EARTH-NTFY-0001');
		$redeemer = $this->payingUser();
		SubscriptionsHelper::redeemTrialCode($redeemer, 'EARTH-NTFY-0001');

		$goodBody = '{"title":"Heads Up","message":"Your trial ends soon."}';

		$anon = $this->adminController()->notifyRedeemers(
			$this->request('POST', '/v2/admin/trial-codes/EARTH-NTFY-0001/notify', [], $goodBody),
			'EARTH-NTFY-0001',
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$nonAdmin = $this->adminController()->notifyRedeemers(
			$this->authRequest(
				$this->payingUser(),
				'POST',
				'/v2/admin/trial-codes/EARTH-NTFY-0001/notify',
				[],
				$goodBody,
			),
			'EARTH-NTFY-0001',
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $nonAdmin->getStatusCode());

		// empty title -> 400
		$badBody = $this->adminController()->notifyRedeemers(
			$this->authRequest(
				$this->admin(),
				'POST',
				'/v2/admin/trial-codes/EARTH-NTFY-0001/notify',
				[],
				'{"title":"","message":"x"}',
			),
			'EARTH-NTFY-0001',
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badBody->getStatusCode());

		// valid body, unknown code -> 404
		$unknown = $this->adminController()->notifyRedeemers(
			$this->authRequest(
				$this->admin(),
				'POST',
				'/v2/admin/trial-codes/EARTH-NONE-0000/notify',
				[],
				$goodBody,
			),
			'EARTH-NONE-0000',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $unknown->getStatusCode());

		$ok = $this->adminController()->notifyRedeemers(
			$this->authRequest(
				$this->admin(),
				'POST',
				'/v2/admin/trial-codes/EARTH-NTFY-0001/notify',
				[],
				$goodBody,
			),
			'EARTH-NTFY-0001',
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertGreaterThanOrEqual(1, $this->decode($ok)['notified']);
	}

	#[Test]
	#[TestDox('GET /v2/admin/users/lookup 401/403, 400 short q, 200 admin resolves a user')]
	#[Group('mantle2/subscriptions')]
	public function adminLookupUsers(): void
	{
		$target = $this->createUser([
			'name' => 'refundtarget',
			'mail' => 'refundtarget@example.com',
		]);

		$anon = $this->adminController()->lookupUsers(
			$this->request('GET', '/v2/admin/users/lookup?q=refundtarget'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$nonAdmin = $this->adminController()->lookupUsers(
			$this->authRequest($this->payingUser(), 'GET', '/v2/admin/users/lookup?q=refundtarget'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $nonAdmin->getStatusCode());

		// q under 2 chars -> 400
		$short = $this->adminController()->lookupUsers(
			$this->authRequest($this->admin(), 'GET', '/v2/admin/users/lookup?q=r'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $short->getStatusCode());

		$ok = $this->adminController()->lookupUsers(
			$this->authRequest($this->admin(), 'GET', '/v2/admin/users/lookup?q=refundtarget'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertArrayHasKey('matches', $body);
		$this->assertContains((int) $target->id(), array_column($body['matches'], 'id'));
	}

	#endregion
}
