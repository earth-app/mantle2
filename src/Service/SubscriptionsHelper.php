<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\mantle2\Custom\AccountType;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UnexpectedValueException;

class SubscriptionsHelper
{
	#region Constants

	public const REFUND_WINDOW_DAYS = 14;
	public const INTERVAL = 'month';
	public const CURRENCY = 'usd';

	// paid tier prices (USD cents)
	public const PRICE_PRO = 599;
	public const PRICE_WRITER = 899;
	public const PRICE_ORGANIZER = 1499;

	// secret key.repository names (mirror CloudHelper::getAdminKey)
	public const KEY_STRIPE_SECRET = 'mantle2_stripe_secret_key';
	public const KEY_STRIPE_WEBHOOK = 'mantle2_stripe_webhook_secret';
	public const KEY_APPLE_IAP = 'mantle2_apple_iap_key';
	public const KEY_APPLE_ROOT_CA = 'mantle2_apple_root_ca';
	public const KEY_GOOGLE_SA = 'mantle2_google_service_account';

	// tables
	public const TABLE_SUBS = 'mantle2_subscriptions';
	public const TABLE_CODES = 'mantle2_trial_codes';
	public const TABLE_REDEMPTIONS = 'mantle2_trial_code_redemptions';

	// webhook dedupe ttl (7 days)
	private const IDEMPOTENCY_TTL = 604800;

	#endregion

	#region Stripe Client

	private static ?StripeClient $clientOverride = null;
	private static ?StripeClient $client = null;

	public static function client(): StripeClient
	{
		if (self::$clientOverride !== null) {
			return self::$clientOverride;
		}
		if (self::$client === null) {
			self::$client = new StripeClient(['api_key' => self::stripeSecret() ?? '']);
		}
		return self::$client;
	}

	// lets tests inject a fake client; null clears both the override and the memo
	public static function setClientOverride(?StripeClient $c): void
	{
		self::$clientOverride = $c;
		if ($c === null) {
			self::$client = null;
		}
	}

	private static function db(): Connection
	{
		return Database::getConnection();
	}

	#endregion

	#region Config

	private static function keyValue(string $name): ?string
	{
		$key = Drupal::service('key.repository')->getKey($name);
		if (!$key) {
			return null;
		}
		$value = $key->getKeyValue();
		return $value === null || $value === '' ? null : $value;
	}

	// non-secret config; mirror CloudHelper::getCloudEndpoint (settings first, env fallback)
	private static function setting(string $settingKey, string $envKey): ?string
	{
		$value = Drupal::service('settings')->get($settingKey);
		if (is_string($value) && $value !== '') {
			return $value;
		}
		$env = getenv($envKey);
		return $env !== false && $env !== '' ? $env : null;
	}

	public static function stripeSecret(): ?string
	{
		return self::keyValue(self::KEY_STRIPE_SECRET);
	}

	public static function stripeWebhookSecret(): ?string
	{
		return self::keyValue(self::KEY_STRIPE_WEBHOOK);
	}

	public static function stripePublishableKey(): ?string
	{
		return self::setting('mantle2.stripe_publishable_key', 'MANTLE2_STRIPE_PUBLISHABLE_KEY');
	}

	public static function getPriceIdForTier(AccountType $t): ?string
	{
		return match ($t) {
			AccountType::PRO => self::setting(
				'mantle2.stripe_price_pro',
				'MANTLE2_STRIPE_PRICE_PRO',
			),
			AccountType::WRITER => self::setting(
				'mantle2.stripe_price_writer',
				'MANTLE2_STRIPE_PRICE_WRITER',
			),
			AccountType::ORGANIZER => self::setting(
				'mantle2.stripe_price_organizer',
				'MANTLE2_STRIPE_PRICE_ORGANIZER',
			),
			default => null,
		};
	}

	public static function getTierForPriceId(string $priceId): ?AccountType
	{
		foreach ([AccountType::PRO, AccountType::WRITER, AccountType::ORGANIZER] as $tier) {
			if (self::getPriceIdForTier($tier) === $priceId) {
				return $tier;
			}
		}
		return null;
	}

	public static function priceCents(AccountType $t): int
	{
		return match ($t) {
			AccountType::PRO => self::PRICE_PRO,
			AccountType::WRITER => self::PRICE_WRITER,
			AccountType::ORGANIZER => self::PRICE_ORGANIZER,
			default => 0,
		};
	}

	public static function tierLabel(AccountType $t): string
	{
		return ucfirst(strtolower($t->name));
	}

	public static function priceDisplay(int $cents): string
	{
		return '$' . number_format($cents / 100, 2);
	}

	// apple config
	public static function appleBundleId(): ?string
	{
		return self::setting('mantle2.apple_bundle_id', 'MANTLE2_APPLE_BUNDLE_ID');
	}

	public static function appleProductForTier(AccountType $t): ?string
	{
		return match ($t) {
			AccountType::PRO => self::setting(
				'mantle2.apple_product_pro',
				'MANTLE2_APPLE_PRODUCT_PRO',
			),
			AccountType::WRITER => self::setting(
				'mantle2.apple_product_writer',
				'MANTLE2_APPLE_PRODUCT_WRITER',
			),
			AccountType::ORGANIZER => self::setting(
				'mantle2.apple_product_organizer',
				'MANTLE2_APPLE_PRODUCT_ORGANIZER',
			),
			default => null,
		};
	}

	public static function appleTierForProduct(string $productId): ?AccountType
	{
		foreach ([AccountType::PRO, AccountType::WRITER, AccountType::ORGANIZER] as $tier) {
			if (self::appleProductForTier($tier) === $productId) {
				return $tier;
			}
		}
		return null;
	}

	// pinned apple root ca - g3 pem; trust anchor for the x5c chain, dropped in like other creds
	public static function appleRootCaPem(): ?string
	{
		$pem = self::keyValue(self::KEY_APPLE_ROOT_CA);
		return is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE') ? $pem : null;
	}

	public static function appleConfigured(): bool
	{
		// require the pinned root ca too; without it jws verification cannot be trusted
		return self::keyValue(self::KEY_APPLE_IAP) !== null &&
			self::appleBundleId() !== null &&
			self::appleRootCaPem() !== null;
	}

	// google config
	public static function googlePackageName(): ?string
	{
		return self::setting('mantle2.google_package_name', 'MANTLE2_GOOGLE_PACKAGE_NAME');
	}

	public static function googleProductForTier(AccountType $t): ?string
	{
		return match ($t) {
			AccountType::PRO => self::setting(
				'mantle2.google_product_pro',
				'MANTLE2_GOOGLE_PRODUCT_PRO',
			),
			AccountType::WRITER => self::setting(
				'mantle2.google_product_writer',
				'MANTLE2_GOOGLE_PRODUCT_WRITER',
			),
			AccountType::ORGANIZER => self::setting(
				'mantle2.google_product_organizer',
				'MANTLE2_GOOGLE_PRODUCT_ORGANIZER',
			),
			default => null,
		};
	}

	public static function googleTierForProduct(string $productId): ?AccountType
	{
		foreach ([AccountType::PRO, AccountType::WRITER, AccountType::ORGANIZER] as $tier) {
			if (self::googleProductForTier($tier) === $productId) {
				return $tier;
			}
		}
		return null;
	}

	public static function googleConfigured(): bool
	{
		return self::keyValue(self::KEY_GOOGLE_SA) !== null && self::googlePackageName() !== null;
	}

	#endregion

	#region Plans

	public static function getPlans(): array
	{
		$plans = [];
		foreach (
			[AccountType::FREE, AccountType::PRO, AccountType::WRITER, AccountType::ORGANIZER]
			as $tier
		) {
			$cents = self::priceCents($tier);
			$plans[] = [
				'tier' => $tier->value,
				'name' => self::tierLabel($tier),
				'price_cents' => $cents,
				'price_display' => self::priceDisplay($cents),
				'currency' => self::CURRENCY,
				'interval' => self::INTERVAL,
			];
		}
		return $plans;
	}

	#endregion

	#region Row Persist

	public static function getSubscriptionRow(int $uid): ?array
	{
		try {
			$row = self::db()
				->select(self::TABLE_SUBS, 's')
				->fields('s')
				->condition('user_id', $uid)
				->execute()
				->fetchAssoc();
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to read subscription row for %uid: %m', [
				'%uid' => $uid,
				'%m' => $e->getMessage(),
			]);
			return null;
		}

		if (!$row) {
			return null;
		}

		return self::normalizeRow($row);
	}

	private static function normalizeRow(array $row): array
	{
		return [
			'user_id' => (int) $row['user_id'],
			'provider' => (string) $row['provider'],
			'external_customer_id' => $row['external_customer_id'] ?? null,
			'external_subscription_id' => $row['external_subscription_id'] ?? null,
			'tier' => (string) $row['tier'],
			'status' => (string) $row['status'],
			'current_period_end' => isset($row['current_period_end'])
				? (int) $row['current_period_end']
				: null,
			'cancel_at_period_end' => (int) ($row['cancel_at_period_end'] ?? 0) === 1,
			'consent_at' => isset($row['consent_at']) ? (int) $row['consent_at'] : null,
			'price_cents' => (int) ($row['price_cents'] ?? 0),
			'started_at' => isset($row['started_at']) ? (int) $row['started_at'] : null,
			'created' => (int) ($row['created'] ?? 0),
			'updated' => (int) ($row['updated'] ?? 0),
		];
	}

	public static function upsertSubscriptionRow(int $uid, array $fields): void
	{
		$now = time();
		$fields['updated'] = $now;

		try {
			$existing = self::getSubscriptionRow($uid);
			if ($existing) {
				self::db()
					->update(self::TABLE_SUBS)
					->fields($fields)
					->condition('user_id', $uid)
					->execute();
				return;
			}

			// fill not-null-without-default columns for a fresh row
			$insert = $fields + [
				'provider' => 'stripe',
				'tier' => AccountType::FREE->value,
				'status' => 'none',
				'price_cents' => 0,
				'cancel_at_period_end' => 0,
				'created' => $now,
			];
			$insert['user_id'] = $uid;
			self::db()->insert(self::TABLE_SUBS)->fields($insert)->execute();
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to upsert subscription row for %uid: %m', [
				'%uid' => $uid,
				'%m' => $e->getMessage(),
			]);
		}
	}

	#endregion

	#region Entitlement

	// this is also where rank changes are applied

	public static function applyEntitlement(
		UserInterface $user,
		AccountType $tier,
		string $provider,
		string $status,
		?int $currentPeriodEnd,
		bool $cancelAtPeriodEnd,
		array $extra = [],
	): void {
		$uid = (int) $user->id();
		$prior = self::getSubscriptionRow($uid);
		$wasActive = $prior && in_array($prior['status'], ['active', 'trialing'], true);

		// the single rank write for billing (mirror UsersController set_account_type)
		try {
			$user->set(
				'field_account_type',
				GeneralHelper::findOrdinal(AccountType::cases(), $tier),
			);
			$user->save();
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to apply entitlement rank for %uid: %m', [
				'%uid' => $uid,
				'%m' => $e->getMessage(),
			]);
			return;
		}

		$fields = [
			'provider' => $provider,
			'tier' => $tier->value,
			'status' => $status,
			'current_period_end' => $currentPeriodEnd,
			'cancel_at_period_end' => $cancelAtPeriodEnd ? 1 : 0,
			'price_cents' => self::priceCents($tier),
		];
		foreach (
			['external_customer_id', 'external_subscription_id', 'consent_at', 'started_at']
			as $optional
		) {
			if (array_key_exists($optional, $extra)) {
				$fields[$optional] = $extra[$optional];
			}
		}
		self::upsertSubscriptionRow($uid, $fields);

		// notify: explicit hint wins, else activation only on the transition into active
		$notify = $extra['notify'] ?? ($wasActive ? null : 'activated');
		$label = self::tierLabel($tier);
		$price = self::priceDisplay(self::priceCents($tier));
		$renewsOn = $currentPeriodEnd ? GeneralHelper::dateToIso($currentPeriodEnd) : null;

		if ($notify === 'activated') {
			UsersHelper::addNotification(
				$user,
				'Subscription Activated',
				"Your $label plan is now active.",
				null,
				'info',
				'billing',
			);
			UsersHelper::sendEmail(
				$user,
				'subscription_activated',
				['tier' => $label, 'price' => $price, 'renews_on' => $renewsOn],
				false,
			);
		} elseif ($notify === 'renewed') {
			UsersHelper::addNotification(
				$user,
				'Subscription Renewed',
				"Your $label plan renewed.",
				null,
				'info',
				'billing',
			);
			UsersHelper::sendEmail(
				$user,
				'subscription_renewed',
				['tier' => $label, 'price' => $price, 'renews_on' => $renewsOn],
				false,
			);
		}
	}

	public static function revokeEntitlement(
		UserInterface $user,
		string $reason,
		string $provider,
	): void {
		$uid = (int) $user->id();
		$isRefund = str_contains(strtolower($reason), 'refund');
		$status = $isRefund ? 'refunded' : 'canceled';

		try {
			$user->set(
				'field_account_type',
				GeneralHelper::findOrdinal(AccountType::cases(), AccountType::FREE),
			);
			$user->save();
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to revoke entitlement rank for %uid: %m', [
				'%uid' => $uid,
				'%m' => $e->getMessage(),
			]);
			return;
		}

		self::upsertSubscriptionRow($uid, [
			'provider' => $provider,
			'tier' => AccountType::FREE->value,
			'status' => $status,
			'cancel_at_period_end' => 0,
		]);

		if ($isRefund) {
			UsersHelper::addNotification(
				$user,
				'Subscription Refunded',
				'Your subscription was refunded and your plan reverted to Free.',
				null,
				'info',
				'billing',
			);
			UsersHelper::sendEmail($user, 'subscription_refunded', ['reason' => $reason], false);
		} else {
			UsersHelper::addNotification(
				$user,
				'Subscription Canceled',
				'Your subscription was canceled and your plan reverted to Free.',
				null,
				'info',
				'billing',
			);
			UsersHelper::sendEmail($user, 'subscription_canceled', ['reason' => $reason], false);
		}
	}

	// paid-provider active sub only; trial-code trials are tracked separately
	public static function hasActiveSubscription(UserInterface $user): bool
	{
		$row = self::getSubscriptionRow((int) $user->id());
		if (!$row) {
			return false;
		}
		return in_array($row['provider'], ['stripe', 'apple', 'google'], true) &&
			in_array($row['status'], ['active', 'trialing', 'past_due'], true);
	}

	#endregion

	#region Billing Status

	public static function getBillingStatus(UserInterface $user): array
	{
		$uid = (int) $user->id();
		$row = self::getSubscriptionRow($uid);
		$now = time();

		// active paid/store subscription
		if ($row && in_array($row['status'], ['active', 'trialing', 'past_due'], true)) {
			$tier = AccountType::tryFrom($row['tier']) ?? AccountType::FREE;
			$isStripe = $row['provider'] === 'stripe';
			$isTrial = $row['status'] === 'trialing' || $row['provider'] === 'trial';
			$refundEligible =
				$isStripe &&
				in_array($row['status'], ['active', 'trialing'], true) &&
				self::isWithinRefundWindow($row, $now);
			$refundDeadline =
				$isStripe && $row['started_at']
					? GeneralHelper::dateToIso(
						$row['started_at'] + self::REFUND_WINDOW_DAYS * 86400,
					)
					: null;

			return [
				'tier' => $tier->value,
				'status' => $row['status'],
				'provider' => $row['provider'],
				'current_period_end' => $row['current_period_end']
					? GeneralHelper::dateToIso($row['current_period_end'])
					: null,
				'cancel_at_period_end' => $row['cancel_at_period_end'],
				'is_trial' => $isTrial,
				'trial_end' =>
					$isTrial && $row['current_period_end']
						? GeneralHelper::dateToIso($row['current_period_end'])
						: null,
				'refund_eligible' => $refundEligible,
				'refund_deadline' => $refundDeadline,
				'can_manage_billing' => $isStripe && !empty($row['external_customer_id']),
			];
		}

		// trial-code trial (UsersHelper::createTierTrial stores a redis key, not a row)
		$trial = self::activeTrialCodeTrial($user);
		if ($trial) {
			return [
				'tier' => $trial['tier'],
				'status' => 'trialing',
				'provider' => 'trial',
				'current_period_end' => $trial['trial_end'],
				'cancel_at_period_end' => false,
				'is_trial' => true,
				'trial_end' => $trial['trial_end'],
				'refund_eligible' => false,
				'refund_deadline' => null,
				'can_manage_billing' => false,
			];
		}

		return self::noSubscriptionStatus();
	}

	private static function noSubscriptionStatus(): array
	{
		return [
			'tier' => AccountType::FREE->value,
			'status' => 'none',
			'provider' => null,
			'current_period_end' => null,
			'cancel_at_period_end' => false,
			'is_trial' => false,
			'trial_end' => null,
			'refund_eligible' => false,
			'refund_deadline' => null,
			'can_manage_billing' => false,
		];
	}

	// reads the redis trial key written by UsersHelper::createTierTrial
	private static function activeTrialCodeTrial(UserInterface $user): ?array
	{
		$key = 'user:account_trial:' . $user->id();
		if (!RedisHelper::exists($key)) {
			return null;
		}
		$data = RedisHelper::get($key);
		if (!$data) {
			return null;
		}
		$ttl = RedisHelper::ttl($key);
		// key ttl is (days + 7) days; strip the 7-day grace to recover trial end
		$secondsRemaining = $ttl - 7 * 86400;
		$tierName = strtolower((string) ($data['new_type'] ?? 'pro'));
		$tier = AccountType::tryFrom($tierName) ?? AccountType::PRO;
		return [
			'tier' => $tier->value,
			'trial_end' =>
				$secondsRemaining > 0 ? GeneralHelper::dateToIso(time() + $secondsRemaining) : null,
		];
	}

	public static function isWithinRefundWindow(array $row, int $now): bool
	{
		if (empty($row['started_at'])) {
			return false;
		}
		return $now - (int) $row['started_at'] <= self::REFUND_WINDOW_DAYS * 86400;
	}

	#endregion

	#region Stripe

	public static function createCheckoutSession(
		UserInterface $user,
		AccountType $tier,
		string $successUrl,
		string $cancelUrl,
	): array {
		$uid = (int) $user->id();
		$priceId = self::getPriceIdForTier($tier);
		if (!$priceId) {
			throw new Exception('No Stripe price configured for tier ' . $tier->value);
		}

		$client = self::client();
		$customerId = self::ensureStripeCustomer($user);

		$session = $client->checkout->sessions->create([
			'mode' => 'subscription',
			'customer' => $customerId,
			'line_items' => [['price' => $priceId, 'quantity' => 1]],
			'success_url' => $successUrl,
			'cancel_url' => $cancelUrl,
			'client_reference_id' => (string) $uid,
			'metadata' => ['uid' => (string) $uid, 'tier' => $tier->value],
			'subscription_data' => [
				'metadata' => ['uid' => (string) $uid, 'tier' => $tier->value],
			],
		]);

		// record express consent for auto-renewal without touching rank
		self::upsertSubscriptionRow($uid, [
			'provider' => 'stripe',
			'tier' => $tier->value,
			'status' => 'incomplete',
			'external_customer_id' => $customerId,
			'consent_at' => time(),
			'price_cents' => self::priceCents($tier),
		]);

		return ['url' => $session->url, 'session_id' => $session->id];
	}

	private static function ensureStripeCustomer(UserInterface $user): string
	{
		$uid = (int) $user->id();
		$row = self::getSubscriptionRow($uid);
		if ($row && !empty($row['external_customer_id'])) {
			return $row['external_customer_id'];
		}

		$customer = self::client()->customers->create([
			'email' => $user->getEmail() ?: null,
			'metadata' => ['uid' => (string) $uid],
		]);
		return $customer->id;
	}

	public static function createPortalSession(UserInterface $user, string $returnUrl): string
	{
		$row = self::getSubscriptionRow((int) $user->id());
		if (!$row || empty($row['external_customer_id'])) {
			throw new Exception('No Stripe customer on file', 404);
		}

		$session = self::client()->billingPortal->sessions->create([
			'customer' => $row['external_customer_id'],
			'return_url' => $returnUrl,
		]);
		return $session->url;
	}

	public static function cancelSubscription(UserInterface $user, bool $immediate = false): array
	{
		$uid = (int) $user->id();
		$row = self::getSubscriptionRow($uid);
		$now = time();
		$provider = $row['provider'] ?? 'stripe';

		// store-managed subscriptions cannot be canceled server-side
		if ($provider === 'apple' || $provider === 'google') {
			$manageUrl =
				$provider === 'apple'
					? 'https://apps.apple.com/account/subscriptions'
					: 'https://play.google.com/store/account/subscriptions';
			return [
				'result' => 'store_managed',
				'provider' => $provider,
				'manage_url' => $manageUrl,
				'message' =>
					'Manage or cancel this subscription from your ' .
					($provider === 'apple' ? 'App Store' : 'Google Play') .
					' account.',
			];
		}

		$subId = $row['external_subscription_id'] ?? null;
		$tier = AccountType::tryFrom($row['tier'] ?? 'free') ?? AccountType::FREE;

		// within the refund window -> full refund + immediate cancel + revoke
		if (self::isWithinRefundWindow($row, $now)) {
			self::refundLatestInvoice($user);
			if ($subId) {
				try {
					self::client()->subscriptions->cancel($subId);
				} catch (Throwable $e) {
					Drupal::logger('mantle2')->error('Stripe cancel failed for %uid: %m', [
						'%uid' => $uid,
						'%m' => $e->getMessage(),
					]);
				}
			}
			self::revokeEntitlement($user, 'refund', 'stripe');
			return [
				'result' => 'refunded',
				'tier' => AccountType::FREE->value,
				'message' =>
					'Your ' .
					self::tierLabel($tier) .
					' plan was canceled and fully refunded. You are back on the Free plan.',
			];
		}

		// outside the window -> cancel at period end (or immediately if requested)
		if ($immediate) {
			if ($subId) {
				try {
					self::client()->subscriptions->cancel($subId);
				} catch (Throwable $e) {
					Drupal::logger('mantle2')->error('Stripe cancel failed for %uid: %m', [
						'%uid' => $uid,
						'%m' => $e->getMessage(),
					]);
				}
			}
			self::revokeEntitlement($user, 'canceled', 'stripe');
			return [
				'result' => 'canceled',
				'cancel_at_period_end' => true,
				'access_until' => GeneralHelper::dateToIso($now),
				'tier' => AccountType::FREE->value,
				'message' => 'Your ' . self::tierLabel($tier) . ' plan has been canceled.',
			];
		}

		if ($subId) {
			try {
				self::client()->subscriptions->update($subId, ['cancel_at_period_end' => true]);
			} catch (Throwable $e) {
				Drupal::logger('mantle2')->error(
					'Stripe cancel_at_period_end failed for %uid: %m',
					[
						'%uid' => $uid,
						'%m' => $e->getMessage(),
					],
				);
			}
		}
		self::upsertSubscriptionRow($uid, ['cancel_at_period_end' => 1]);

		$accessUntil = $row['current_period_end'] ?? $now;
		$accessIso = GeneralHelper::dateToIso($accessUntil);
		UsersHelper::addNotification(
			$user,
			'Subscription Canceled',
			'Your ' .
				self::tierLabel($tier) .
				' plan will stay active until ' .
				gmdate('F j, Y', $accessUntil) .
				'.',
			null,
			'info',
			'billing',
		);
		UsersHelper::sendEmail(
			$user,
			'subscription_canceled',
			['tier' => self::tierLabel($tier), 'access_until' => $accessIso, 'pending' => true],
			false,
		);

		return [
			'result' => 'canceled',
			'cancel_at_period_end' => true,
			'access_until' => $accessIso,
			'tier' => $tier->value,
			'message' =>
				'Your ' .
				self::tierLabel($tier) .
				' plan stays active until ' .
				gmdate('F j, Y', $accessUntil) .
				'.',
		];
	}

	public static function refundLatestInvoice(UserInterface $user): bool
	{
		$row = self::getSubscriptionRow((int) $user->id());
		if (!$row || empty($row['external_customer_id'])) {
			return false;
		}

		try {
			$params = ['customer' => $row['external_customer_id'], 'limit' => 1];
			if (!empty($row['external_subscription_id'])) {
				$params['subscription'] = $row['external_subscription_id'];
			}
			$invoices = self::client()->invoices->all($params);
			$invoice = $invoices->data[0] ?? null;
			if (!$invoice) {
				return false;
			}

			$refundParams = [];
			if (!empty($invoice->payment_intent)) {
				$refundParams['payment_intent'] = $invoice->payment_intent;
			} elseif (!empty($invoice->charge)) {
				$refundParams['charge'] = $invoice->charge;
			} else {
				return false;
			}

			self::client()->refunds->create($refundParams);
			return true;
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Refund failed for %uid: %m', [
				'%uid' => $user->id(),
				'%m' => $e->getMessage(),
			]);
			return false;
		}
	}

	// admin-forced refund: refund latest invoice (stripe), cancel, and revoke to Free
	public static function refundUser(UserInterface $user, string $reason = ''): array
	{
		$row = self::getSubscriptionRow((int) $user->id());
		if (!$row || !in_array($row['status'], ['active', 'trialing', 'past_due'], true)) {
			return ['error' => 'no_sub'];
		}

		$provider = $row['provider'];
		if ($provider === 'stripe') {
			self::refundLatestInvoice($user);
			if (!empty($row['external_subscription_id'])) {
				try {
					self::client()->subscriptions->cancel($row['external_subscription_id']);
				} catch (Throwable $e) {
					Drupal::logger('mantle2')->error('Admin refund cancel failed for %uid: %m', [
						'%uid' => $user->id(),
						'%m' => $e->getMessage(),
					]);
				}
			}
		}

		self::revokeEntitlement($user, 'refund' . ($reason !== '' ? ": $reason" : ''), $provider);
		return [
			'result' => 'refunded',
			'tier' => AccountType::FREE->value,
			'message' => 'The subscription was refunded and the account reverted to the Free plan.',
		];
	}

	#endregion

	#region Trial Codes

	public static function redeemTrialCode(UserInterface $user, string $code): array
	{
		$code = strtoupper(trim($code));
		$row = self::getTrialCode($code);
		if (!$row) {
			return ['error' => 'unknown'];
		}

		$now = time();
		if ((int) $row['active'] !== 1) {
			return ['error' => 'not_redeemable', 'reason' => 'This code is no longer active.'];
		}
		if ($row['expires_at'] !== null && (int) $row['expires_at'] < $now) {
			return ['error' => 'not_redeemable', 'reason' => 'This code has expired.'];
		}
		if (
			(int) $row['max_redemptions'] > 0 &&
			(int) $row['redemptions'] >= (int) $row['max_redemptions']
		) {
			return [
				'error' => 'not_redeemable',
				'reason' => 'This code has reached its redemption limit.',
			];
		}
		if (self::hasRedeemed($code, (int) $user->id())) {
			return [
				'error' => 'not_redeemable',
				'reason' => 'You have already redeemed this code.',
			];
		}
		if (self::hasActiveSubscription($user)) {
			return [
				'error' => 'not_redeemable',
				'reason' => 'You already have an active subscription.',
			];
		}

		$tier = AccountType::tryFrom($row['tier']) ?? AccountType::PRO;
		$current = UsersHelper::getAccountType($user);
		$currentOrdinal = GeneralHelper::findOrdinal(AccountType::cases(), $current);
		$tierOrdinal = GeneralHelper::findOrdinal(AccountType::cases(), $tier);
		if ($tierOrdinal <= $currentOrdinal) {
			return [
				'error' => 'not_redeemable',
				'reason' => 'This code does not upgrade your current plan.',
			];
		}

		$days = (int) $row['days'];
		UsersHelper::createTierTrial($user, $tier, $days, "trial code $code");
		self::recordRedemption($code, (int) $user->id());

		$label = self::tierLabel($tier);
		$trialEnd = GeneralHelper::dateToIso($now + $days * 86400);
		UsersHelper::addNotification(
			$user,
			'Trial Code Redeemed',
			"You redeemed a $days-day $label trial.",
			null,
			'info',
			'billing',
		);
		UsersHelper::sendEmail(
			$user,
			'trial_code_redeemed',
			['tier' => $label, 'days' => $days, 'trial_end' => $trialEnd, 'code' => $code],
			false,
		);

		return [
			'tier' => $tier->value,
			'days' => $days,
			'trial_end' => $trialEnd,
			'message' => "Your $days-day $label trial is now active.",
		];
	}

	public static function createTrialCode(
		AccountType $tier,
		int $days,
		int $maxRedemptions,
		?int $expiresAt,
		int $createdBy,
		?string $code = null,
	): array {
		$code =
			$code !== null && trim($code) !== '' ? strtoupper(trim($code)) : self::generateCode();

		// keep generating until unique when auto-generated
		$attempts = 0;
		while (self::getTrialCode($code) !== null && $attempts < 10) {
			$code = self::generateCode();
			$attempts++;
		}

		$now = time();
		self::db()
			->insert(self::TABLE_CODES)
			->fields([
				'code' => $code,
				'tier' => $tier->value,
				'days' => $days,
				'max_redemptions' => max(0, $maxRedemptions),
				'redemptions' => 0,
				'expires_at' => $expiresAt,
				'active' => 1,
				'created_by' => $createdBy,
				'created' => $now,
			])
			->execute();

		return self::formatTrialCode(self::getTrialCode($code) ?? []);
	}

	public static function listTrialCodes(): array
	{
		$rows = self::db()
			->select(self::TABLE_CODES, 'c')
			->fields('c')
			->orderBy('created', 'DESC')
			->execute()
			->fetchAll(\PDO::FETCH_ASSOC);

		return array_map(fn(array $r) => self::formatTrialCode($r), $rows ?: []);
	}

	public static function getTrialCode(string $code): ?array
	{
		$code = strtoupper(trim($code));
		$row = self::db()
			->select(self::TABLE_CODES, 'c')
			->fields('c')
			->condition('code', $code)
			->execute()
			->fetchAssoc();
		return $row ?: null;
	}

	public static function updateTrialCode(string $code, array $patch): ?array
	{
		$existing = self::getTrialCode($code);
		if (!$existing) {
			return null;
		}

		$fields = [];
		if (array_key_exists('active', $patch)) {
			$fields['active'] = $patch['active'] ? 1 : 0;
		}
		if (array_key_exists('max_redemptions', $patch)) {
			$fields['max_redemptions'] = max(0, (int) $patch['max_redemptions']);
		}
		if (array_key_exists('expires_at', $patch)) {
			$fields['expires_at'] =
				$patch['expires_at'] === null ? null : (int) $patch['expires_at'];
		}
		if (array_key_exists('days', $patch)) {
			$fields['days'] = max(1, (int) $patch['days']);
		}
		if (array_key_exists('tier', $patch)) {
			$tier = AccountType::tryFrom((string) $patch['tier']);
			if ($tier) {
				$fields['tier'] = $tier->value;
			}
		}

		if ($fields) {
			self::db()
				->update(self::TABLE_CODES)
				->fields($fields)
				->condition('code', strtoupper(trim($code)))
				->execute();
		}

		return self::formatTrialCode(self::getTrialCode($code) ?? []);
	}

	public static function deleteTrialCode(string $code): bool
	{
		if (!self::getTrialCode($code)) {
			return false;
		}
		self::db()
			->delete(self::TABLE_CODES)
			->condition('code', strtoupper(trim($code)))
			->execute();
		return true;
	}

	private static function formatTrialCode(array $row): array
	{
		return [
			'code' => (string) ($row['code'] ?? ''),
			'tier' => (string) ($row['tier'] ?? AccountType::FREE->value),
			'days' => (int) ($row['days'] ?? 0),
			'max_redemptions' => (int) ($row['max_redemptions'] ?? 0),
			'redemptions' => (int) ($row['redemptions'] ?? 0),
			'expires_at' => isset($row['expires_at'])
				? GeneralHelper::dateToIso((int) $row['expires_at'])
				: null,
			'active' => (int) ($row['active'] ?? 0) === 1,
			'created_by' => (int) ($row['created_by'] ?? 0),
			'created' => GeneralHelper::dateToIso((int) ($row['created'] ?? time())),
		];
	}

	private static function generateCode(): string
	{
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$block = function () use ($alphabet): string {
			$out = '';
			for ($i = 0; $i < 4; $i++) {
				$out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
			}
			return $out;
		};
		return 'EARTH-' . $block() . '-' . $block();
	}

	private static function hasRedeemed(string $code, int $uid): bool
	{
		$found = self::db()
			->select(self::TABLE_REDEMPTIONS, 'r')
			->fields('r', ['id'])
			->condition('code', strtoupper(trim($code)))
			->condition('user_id', $uid)
			->execute()
			->fetchField();
		return $found !== false;
	}

	private static function recordRedemption(string $code, int $uid): void
	{
		$code = strtoupper(trim($code));
		try {
			self::db()
				->insert(self::TABLE_REDEMPTIONS)
				->fields(['code' => $code, 'user_id' => $uid, 'redeemed_at' => time()])
				->execute();
			self::db()
				->update(self::TABLE_CODES)
				->expression('redemptions', 'redemptions + 1')
				->condition('code', $code)
				->execute();
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to record redemption of %code: %m', [
				'%code' => $code,
				'%m' => $e->getMessage(),
			]);
		}
	}

	#endregion

	#region Redis Idempotency

	public static function wasProcessed(string $key): bool
	{
		return RedisHelper::exists($key);
	}

	public static function markProcessed(string $key): void
	{
		RedisHelper::set($key, ['at' => time()], self::IDEMPOTENCY_TTL);
	}

	#endregion

	#region Stripe Webhook

	public static function handleStripeWebhook(string $rawBody, string $sigHeader): JsonResponse
	{
		$secret = self::stripeWebhookSecret();
		if (!$secret) {
			Drupal::logger('mantle2')->error('Stripe webhook secret not configured');
			return new JsonResponse(['received' => true], Response::HTTP_OK);
		}

		// verify signature on the RAW body before decoding
		try {
			$event = Webhook::constructEvent($rawBody, $sigHeader, $secret);
		} catch (SignatureVerificationException | UnexpectedValueException $e) {
			Drupal::logger('mantle2')->warning('Stripe webhook signature failed: %m', [
				'%m' => $e->getMessage(),
			]);
			return new JsonResponse(['error' => 'invalid signature'], Response::HTTP_BAD_REQUEST);
		}

		$eventId = $event->id ?? '';
		$dedupeKey = 'stripe:webhook:' . $eventId;
		if ($eventId && self::wasProcessed($dedupeKey)) {
			return new JsonResponse(['received' => true, 'duplicate' => true], Response::HTTP_OK);
		}
		if ($eventId) {
			self::markProcessed($dedupeKey);
		}

		try {
			self::dispatchStripeEvent($event);
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Stripe webhook dispatch failed for %type: %m', [
				'%type' => $event->type ?? 'unknown',
				'%m' => $e->getMessage(),
			]);
		}

		return new JsonResponse(['received' => true], Response::HTTP_OK);
	}

	private static function dispatchStripeEvent(object $event): void
	{
		$object = $event->data->object ?? null;
		if (!$object) {
			return;
		}

		switch ($event->type) {
			case 'checkout.session.completed':
				self::onCheckoutCompleted($object);
				break;
			case 'customer.subscription.updated':
				self::onSubscriptionUpdated($object);
				break;
			case 'customer.subscription.deleted':
				self::onSubscriptionDeleted($object);
				break;
			case 'invoice.paid':
			case 'invoice.payment_succeeded':
				self::onInvoicePaid($object);
				break;
			case 'invoice.payment_failed':
				self::onInvoicePaymentFailed($object);
				break;
			case 'invoice.payment_action_required':
				self::onInvoiceActionRequired($object);
				break;
			case 'charge.refunded':
				self::onChargeRefunded($object);
				break;
			default:
				// unknown event: ignore, still 200
				break;
		}
	}

	private static function onCheckoutCompleted(object $session): void
	{
		$uid = (int) ($session->client_reference_id ?? ($session->metadata->uid ?? 0));
		$user = $uid ? User::load($uid) : null;
		if (!$user) {
			return;
		}

		$tier =
			AccountType::tryFrom($session->metadata->tier ?? '') ??
			(self::subTier($session->subscription ?? null) ?? AccountType::PRO);

		$sub = self::retrieveSubscription($session->subscription ?? null);
		$periodEnd = $sub ? self::subCurrentPeriodEnd($sub) : null;

		self::applyEntitlement($user, $tier, 'stripe', 'active', $periodEnd, false, [
			'external_customer_id' => $session->customer ?? null,
			'external_subscription_id' => $session->subscription ?? null,
			'notify' => 'activated',
		]);
	}

	private static function onSubscriptionUpdated(object $sub): void
	{
		$user = self::userForSubscription($sub);
		if (!$user) {
			return;
		}

		$status = self::mapStripeStatus($sub->status ?? 'active');
		$tier = self::subTier($sub) ?? UsersHelper::getAccountType($user);
		$cancelAtEnd = (bool) ($sub->cancel_at_period_end ?? false);
		$periodEnd = self::subCurrentPeriodEnd($sub);

		if (in_array($status, ['active', 'trialing'], true)) {
			// sync entitlement without a duplicate activation email
			self::applyEntitlement($user, $tier, 'stripe', $status, $periodEnd, $cancelAtEnd, [
				'external_subscription_id' => $sub->id ?? null,
				'external_customer_id' => $sub->customer ?? null,
				'notify' => null,
			]);
			return;
		}

		if ($status === 'canceled') {
			self::revokeEntitlement($user, 'canceled', 'stripe');
			return;
		}

		// past_due / incomplete: keep current rank (grace), just sync the row
		self::upsertSubscriptionRow((int) $user->id(), [
			'provider' => 'stripe',
			'status' => $status,
			'current_period_end' => $periodEnd,
			'cancel_at_period_end' => $cancelAtEnd ? 1 : 0,
			'external_subscription_id' => $sub->id ?? null,
		]);
	}

	private static function onSubscriptionDeleted(object $sub): void
	{
		$user = self::userForSubscription($sub);
		if (!$user) {
			return;
		}
		self::revokeEntitlement($user, 'canceled', 'stripe');
	}

	private static function onInvoicePaid(object $invoice): void
	{
		$user = self::userForInvoice($invoice);
		if (!$user) {
			return;
		}

		$tier = self::subTier($invoice->subscription ?? null) ?? UsersHelper::getAccountType($user);
		$sub = self::retrieveSubscription($invoice->subscription ?? null);
		$periodEnd = $sub ? self::subCurrentPeriodEnd($sub) : null;

		// started_at resets each cycle to that invoice's period start (refund-window basis)
		$periodStart =
			(int) ($invoice->period_start ?? ($invoice->lines->data[0]->period->start ?? time()));

		// only the renewal cycle emails; the create invoice is covered by checkout.session.completed
		$isRenewal = ($invoice->billing_reason ?? '') !== 'subscription_create';

		self::applyEntitlement($user, $tier, 'stripe', 'active', $periodEnd, false, [
			'external_customer_id' => $invoice->customer ?? null,
			'external_subscription_id' => $invoice->subscription ?? null,
			'started_at' => $periodStart,
			'notify' => $isRenewal ? 'renewed' : null,
		]);
	}

	private static function onInvoicePaymentFailed(object $invoice): void
	{
		$user = self::userForInvoice($invoice);
		if (!$user) {
			return;
		}

		self::upsertSubscriptionRow((int) $user->id(), ['status' => 'past_due']);

		$tier = UsersHelper::getAccountType($user);
		UsersHelper::addNotification(
			$user,
			'Payment Failed',
			'We could not process your latest subscription payment. Please update your payment method.',
			null,
			'warning',
			'billing',
		);
		UsersHelper::sendEmail(
			$user,
			'payment_failed_warning',
			[
				'tier' => self::tierLabel($tier),
				'amount' => self::priceDisplay(self::priceCents($tier)),
			],
			false,
		);
	}

	private static function onInvoiceActionRequired(object $invoice): void
	{
		$user = self::userForInvoice($invoice);
		if (!$user) {
			return;
		}

		UsersHelper::addNotification(
			$user,
			'Action Required',
			'Your bank requires additional confirmation to complete your subscription payment.',
			null,
			'warning',
			'billing',
		);
		UsersHelper::sendEmail(
			$user,
			'payment_failed_warning',
			[
				'tier' => self::tierLabel(UsersHelper::getAccountType($user)),
				'action_required' => true,
			],
			false,
		);
	}

	private static function onChargeRefunded(object $charge): void
	{
		$customerId = $charge->customer ?? null;
		$user = $customerId ? self::userForCustomer($customerId) : null;
		if (!$user) {
			return;
		}

		// avoid a duplicate refund email when our own cancel path already revoked
		$row = self::getSubscriptionRow((int) $user->id());
		if ($row && $row['status'] === 'refunded') {
			return;
		}

		self::revokeEntitlement($user, 'refund', 'stripe');
	}

	#endregion

	#region Stripe Helpers

	private static function mapStripeStatus(string $status): string
	{
		return match ($status) {
			'active' => 'active',
			'trialing' => 'trialing',
			'past_due', 'unpaid' => 'past_due',
			'canceled' => 'canceled',
			'incomplete', 'incomplete_expired' => 'incomplete',
			default => $status,
		};
	}

	private static function retrieveSubscription(mixed $subId): ?object
	{
		if (!is_string($subId) || $subId === '') {
			return null;
		}
		try {
			return self::client()->subscriptions->retrieve($subId);
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->warning('Failed to retrieve Stripe subscription %id: %m', [
				'%id' => $subId,
				'%m' => $e->getMessage(),
			]);
			return null;
		}
	}

	// current_period_end moved onto items in newer api versions; check both
	private static function subCurrentPeriodEnd(object $sub): ?int
	{
		if (!empty($sub->current_period_end)) {
			return (int) $sub->current_period_end;
		}
		$item = $sub->items->data[0] ?? null;
		if ($item && !empty($item->current_period_end)) {
			return (int) $item->current_period_end;
		}
		return null;
	}

	private static function subTier(mixed $sub): ?AccountType
	{
		if (is_string($sub)) {
			$sub = self::retrieveSubscription($sub);
		}
		if (!is_object($sub)) {
			return null;
		}
		$priceId = $sub->items->data[0]->price->id ?? null;
		if (is_string($priceId)) {
			$tier = self::getTierForPriceId($priceId);
			if ($tier) {
				return $tier;
			}
		}
		$metaTier = $sub->metadata->tier ?? null;
		return is_string($metaTier) ? AccountType::tryFrom($metaTier) : null;
	}

	private static function userForSubscription(object $sub): ?UserInterface
	{
		$uid = (int) ($sub->metadata->uid ?? 0);
		if ($uid) {
			$user = User::load($uid);
			if ($user) {
				return $user;
			}
		}
		if (!empty($sub->id)) {
			$user = self::userByExternalSubscription($sub->id);
			if ($user) {
				return $user;
			}
		}
		return !empty($sub->customer) ? self::userForCustomer($sub->customer) : null;
	}

	private static function userForInvoice(object $invoice): ?UserInterface
	{
		if (!empty($invoice->subscription)) {
			$user = self::userByExternalSubscription($invoice->subscription);
			if ($user) {
				return $user;
			}
		}
		return !empty($invoice->customer) ? self::userForCustomer($invoice->customer) : null;
	}

	private static function userByExternalSubscription(string $subId): ?UserInterface
	{
		$uid = self::db()
			->select(self::TABLE_SUBS, 's')
			->fields('s', ['user_id'])
			->condition('external_subscription_id', $subId)
			->execute()
			->fetchField();
		return $uid !== false ? User::load((int) $uid) : null;
	}

	private static function userForCustomer(string $customerId): ?UserInterface
	{
		$uid = self::db()
			->select(self::TABLE_SUBS, 's')
			->fields('s', ['user_id'])
			->condition('external_customer_id', $customerId)
			->execute()
			->fetchField();
		return $uid !== false ? User::load((int) $uid) : null;
	}

	#endregion

	#region Apple IAP

	public static function verifyAppleTransaction(UserInterface $user, array $p): array
	{
		if (!self::appleConfigured()) {
			return ['error' => 'unconfigured'];
		}

		$jws = $p['signed_payload'] ?? null;
		if (!is_string($jws) || $jws === '') {
			return ['error' => 'bad_payload'];
		}

		$payload = self::verifyAppleJws($jws);
		if (!$payload) {
			return ['error' => 'validation'];
		}

		$bundleId = $payload['bundleId'] ?? null;
		if ($bundleId !== self::appleBundleId()) {
			return ['error' => 'validation'];
		}

		$productId = (string) ($payload['productId'] ?? ($p['product_id'] ?? ''));
		$tier = self::appleTierForProduct($productId);
		if (!$tier) {
			return ['error' => 'validation'];
		}

		// cross-provider guard
		$guard = self::crossProviderGuard($user, 'apple');
		if ($guard) {
			return $guard;
		}

		$expiresMs = $payload['expiresDate'] ?? null;
		$periodEnd = is_numeric($expiresMs) ? (int) ($expiresMs / 1000) : null;
		$original = $payload['originalTransactionId'] ?? ($p['transaction_id'] ?? null);

		// omit notify so a restore of an already-active sub does not re-email
		self::applyEntitlement($user, $tier, 'apple', 'active', $periodEnd, false, [
			'external_subscription_id' => $original,
			'started_at' => time(),
		]);

		return self::getBillingStatus($user);
	}

	// verify a JWS by validating its full x5c chain to the pinned apple root, then the leaf signature
	private static function verifyAppleJws(string $jws): ?array
	{
		try {
			$parts = explode('.', $jws);
			if (count($parts) !== 3) {
				return null;
			}
			$header = json_decode(JWT::urlsafeB64Decode($parts[0]), true);
			$chain = $header['x5c'] ?? null;
			// a real apple chain is leaf + intermediate + root; leaf-only is not trustworthy
			if (!is_array($chain) || count($chain) < 2) {
				return null;
			}

			$pems = [];
			foreach ($chain as $der) {
				if (!is_string($der) || $der === '') {
					return null;
				}
				$pems[] =
					"-----BEGIN CERTIFICATE-----\n" .
					chunk_split($der, 64, "\n") .
					"-----END CERTIFICATE-----\n";
			}

			// the chain must terminate at apple's pinned root before we trust the leaf key
			if (!self::appleChainTrusted($pems)) {
				return null;
			}

			$publicKey = openssl_pkey_get_public($pems[0]);
			if ($publicKey === false) {
				return null;
			}

			$decoded = JWT::decode($jws, new Key($publicKey, 'ES256'));
			return json_decode(json_encode($decoded), true);
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->warning('Apple JWS verification failed: %m', [
				'%m' => $e->getMessage(),
			]);
			return null;
		}
	}

	// each cert must be signed by the next up and in-date, and the presented root must
	// match the pinned apple root ca - g3; fails closed when the anchor is absent
	private static function appleChainTrusted(array $pems): bool
	{
		$rootPem = self::appleRootCaPem();
		if ($rootPem === null) {
			Drupal::logger('mantle2')->warning(
				'apple root ca not configured; rejecting iap payload (fail-closed)',
			);
			return false;
		}

		$now = time();
		for ($i = 0; $i < count($pems) - 1; $i++) {
			$info = openssl_x509_parse($pems[$i]);
			if (
				!is_array($info) ||
				$now < ($info['validFrom_time_t'] ?? PHP_INT_MAX) ||
				$now > ($info['validTo_time_t'] ?? 0)
			) {
				return false;
			}
			$issuerKey = openssl_pkey_get_public($pems[$i + 1]);
			if ($issuerKey === false || openssl_x509_verify($pems[$i], $issuerKey) !== 1) {
				return false;
			}
		}

		$presented = openssl_x509_fingerprint(end($pems), 'sha256');
		$pinned = openssl_x509_fingerprint($rootPem, 'sha256');
		return $presented !== false && $pinned !== false && hash_equals($pinned, $presented);
	}

	public static function handleAppleWebhook(string $rawBody): JsonResponse
	{
		if (!self::appleConfigured()) {
			Drupal::logger('mantle2')->warning(
				'Apple webhook received but Apple billing not configured',
			);
			return new JsonResponse(['received' => true], Response::HTTP_OK);
		}

		$body = json_decode($rawBody, true);
		$signedPayload = $body['signedPayload'] ?? null;
		if (!is_string($signedPayload)) {
			return new JsonResponse(['error' => 'invalid signature'], Response::HTTP_BAD_REQUEST);
		}

		$payload = self::verifyAppleJws($signedPayload);
		if (!$payload) {
			return new JsonResponse(['error' => 'invalid signature'], Response::HTTP_BAD_REQUEST);
		}

		$uuid = $payload['notificationUUID'] ?? '';
		$dedupeKey = 'apple:notif:' . $uuid;
		if ($uuid && self::wasProcessed($dedupeKey)) {
			return new JsonResponse(['received' => true, 'duplicate' => true], Response::HTTP_OK);
		}
		if ($uuid) {
			self::markProcessed($dedupeKey);
		}

		try {
			self::dispatchAppleNotification($payload);
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Apple webhook dispatch failed: %m', [
				'%m' => $e->getMessage(),
			]);
		}

		return new JsonResponse(['received' => true], Response::HTTP_OK);
	}

	private static function dispatchAppleNotification(array $payload): void
	{
		$type = $payload['notificationType'] ?? '';
		$subtype = $payload['subtype'] ?? '';

		$txInfo = null;
		$signedTx = $payload['data']['signedTransactionInfo'] ?? null;
		if (is_string($signedTx)) {
			$txInfo = self::verifyAppleJws($signedTx);
		}
		if (!$txInfo) {
			return;
		}

		$original = $txInfo['originalTransactionId'] ?? null;
		$user = $original ? self::userByExternalSubscription($original) : null;
		if (!$user) {
			return;
		}

		$tier =
			self::appleTierForProduct((string) ($txInfo['productId'] ?? '')) ??
			UsersHelper::getAccountType($user);
		$expiresMs = $txInfo['expiresDate'] ?? null;
		$periodEnd = is_numeric($expiresMs) ? (int) ($expiresMs / 1000) : null;

		switch ($type) {
			case 'SUBSCRIBED':
			case 'DID_RENEW':
				self::applyEntitlement($user, $tier, 'apple', 'active', $periodEnd, false, [
					'external_subscription_id' => $original,
					'notify' => $type === 'DID_RENEW' ? 'renewed' : 'activated',
				]);
				break;
			case 'DID_CHANGE_RENEWAL_STATUS':
				$cancelAtEnd = $subtype === 'AUTO_RENEW_DISABLED';
				self::upsertSubscriptionRow((int) $user->id(), [
					'cancel_at_period_end' => $cancelAtEnd ? 1 : 0,
				]);
				break;
			case 'EXPIRED':
			case 'REVOKE':
				self::revokeEntitlement($user, 'canceled', 'apple');
				break;
			case 'REFUND':
				self::revokeEntitlement($user, 'refund', 'apple');
				break;
			default:
				break;
		}
	}

	#endregion

	#region Google IAP

	public static function verifyGoogleTransaction(UserInterface $user, array $p): array
	{
		if (!self::googleConfigured()) {
			return ['error' => 'unconfigured'];
		}

		$purchaseToken = $p['purchase_token'] ?? null;
		$productId = $p['product_id'] ?? null;
		$packageName = $p['package_name'] ?? self::googlePackageName();
		if (!is_string($purchaseToken) || !is_string($productId) || $purchaseToken === '') {
			return ['error' => 'bad_payload'];
		}
		if ($packageName !== self::googlePackageName()) {
			return ['error' => 'validation'];
		}

		$tier = self::googleTierForProduct($productId);
		if (!$tier) {
			return ['error' => 'validation'];
		}

		$purchase = self::googleSubscriptionState($packageName, $purchaseToken);
		if (!$purchase) {
			return ['error' => 'validation'];
		}

		// v2 uses subscriptionState; require an active/entitled state
		$state = $purchase['subscriptionState'] ?? '';
		if (
			!in_array(
				$state,
				['SUBSCRIPTION_STATE_ACTIVE', 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD'],
				true,
			)
		) {
			return ['error' => 'validation'];
		}

		$guard = self::crossProviderGuard($user, 'google');
		if ($guard) {
			return $guard;
		}

		$expiry = $purchase['lineItems'][0]['expiryTime'] ?? null;
		$periodEnd = is_string($expiry) ? strtotime($expiry) : null;

		// omit notify so a restore of an already-active sub does not re-email
		self::applyEntitlement($user, $tier, 'google', 'active', $periodEnd ?: null, false, [
			'external_subscription_id' => $purchaseToken,
			'started_at' => time(),
		]);

		return self::getBillingStatus($user);
	}

	// google play developer api purchases.subscriptionsv2.get
	private static function googleSubscriptionState(string $packageName, string $token): ?array
	{
		$json = self::keyValue(self::KEY_GOOGLE_SA);
		if (!$json) {
			return null;
		}
		$creds = json_decode($json, true);
		if (!is_array($creds)) {
			return null;
		}

		try {
			$sa = new ServiceAccountCredentials(
				'https://www.googleapis.com/auth/androidpublisher',
				$creds,
			);
			$token0 = $sa->fetchAuthToken();
			$accessToken = $token0['access_token'] ?? null;
			if (!$accessToken) {
				return null;
			}

			$url =
				'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/' .
				rawurlencode($packageName) .
				'/purchases/subscriptionsv2/tokens/' .
				rawurlencode($token);

			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'Authorization: Bearer ' . $accessToken,
				'Accept: application/json',
			]);
			$response = curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			if ($code < 200 || $code >= 300 || !is_string($response)) {
				return null;
			}
			$data = json_decode($response, true);
			return is_array($data) ? $data : null;
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->warning('Google Play API call failed: %m', [
				'%m' => $e->getMessage(),
			]);
			return null;
		}
	}

	public static function handleGoogleWebhook(string $rawBody): JsonResponse
	{
		// Pub/Sub push: always ack with 200 to avoid a retry storm
		if (!self::googleConfigured()) {
			Drupal::logger('mantle2')->warning(
				'Google webhook received but Google billing not configured',
			);
			return new JsonResponse(['received' => true], Response::HTTP_OK);
		}

		$body = json_decode($rawBody, true);
		$message = $body['message'] ?? null;
		if (!is_array($message)) {
			return new JsonResponse(['received' => true], Response::HTTP_OK);
		}

		$messageId = $message['messageId'] ?? ($message['message_id'] ?? '');
		$dedupeKey = 'google:notif:' . $messageId;
		if ($messageId && self::wasProcessed($dedupeKey)) {
			return new JsonResponse(['received' => true, 'duplicate' => true], Response::HTTP_OK);
		}
		if ($messageId) {
			self::markProcessed($dedupeKey);
		}

		try {
			$decoded = base64_decode($message['data'] ?? '', true);
			$notification = $decoded ? json_decode($decoded, true) : null;
			if (is_array($notification)) {
				self::dispatchGoogleNotification($notification);
			}
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Google webhook dispatch failed: %m', [
				'%m' => $e->getMessage(),
			]);
		}

		return new JsonResponse(['received' => true], Response::HTTP_OK);
	}

	private static function dispatchGoogleNotification(array $notification): void
	{
		$sub = $notification['subscriptionNotification'] ?? null;
		if (!is_array($sub)) {
			return;
		}

		$token = $sub['purchaseToken'] ?? null;
		if (!is_string($token)) {
			return;
		}
		$user = self::userByExternalSubscription($token);
		if (!$user) {
			return;
		}

		// notificationType: 2=RENEWED, 3=CANCELED, 12=REVOKED, 13=EXPIRED
		$type = (int) ($sub['notificationType'] ?? 0);
		$productId = (string) ($sub['subscriptionId'] ?? '');
		$tier = self::googleTierForProduct($productId) ?? UsersHelper::getAccountType($user);

		switch ($type) {
			case 1: // recovered
			case 2: // renewed
			case 4: // purchased
				self::applyEntitlement($user, $tier, 'google', 'active', null, false, [
					'external_subscription_id' => $token,
					'notify' => $type === 2 ? 'renewed' : 'activated',
				]);
				break;
			case 3: // canceled (auto-renew off; access continues until expiry)
				self::upsertSubscriptionRow((int) $user->id(), ['cancel_at_period_end' => 1]);
				break;
			case 12: // revoked
			case 13: // expired
				self::revokeEntitlement($user, 'canceled', 'google');
				break;
			default:
				break;
		}
	}

	#endregion

	#region Cross-provider Guard

	// returns a conflict marker if the user already holds an active sub on a different provider
	private static function crossProviderGuard(UserInterface $user, string $provider): ?array
	{
		$row = self::getSubscriptionRow((int) $user->id());
		if (
			$row &&
			in_array($row['status'], ['active', 'trialing', 'past_due'], true) &&
			in_array($row['provider'], ['stripe', 'apple', 'google'], true) &&
			$row['provider'] !== $provider
		) {
			return ['error' => 'cross_provider', 'provider' => $row['provider']];
		}
		return null;
	}

	#endregion

	#region Reconcile (cron)

	public static function reconcile(): void
	{
		try {
			$rows = self::db()
				->select(self::TABLE_SUBS, 's')
				->fields('s')
				->condition('provider', ['stripe', 'apple', 'google'], 'IN')
				->condition('status', ['active', 'trialing', 'past_due'], 'IN')
				->execute()
				->fetchAll(\PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Reconcile query failed: %m', [
				'%m' => $e->getMessage(),
			]);
			return;
		}

		$now = time();
		foreach ($rows ?: [] as $raw) {
			try {
				$row = self::normalizeRow($raw);
				$user = User::load($row['user_id']);
				if (!$user) {
					continue;
				}

				// downgrade lapsed subscriptions past their period end
				if (
					$row['current_period_end'] &&
					$row['current_period_end'] < $now &&
					$row['cancel_at_period_end']
				) {
					self::revokeEntitlement($user, 'canceled', $row['provider']);
					continue;
				}

				// renewal reminder roughly 3 days out (idempotent per period end)
				if (
					$row['current_period_end'] &&
					!$row['cancel_at_period_end'] &&
					$row['current_period_end'] - $now > 0 &&
					$row['current_period_end'] - $now <= 3 * 86400
				) {
					$marker =
						'billing:renewal_reminder:' .
						$row['user_id'] .
						':' .
						$row['current_period_end'];
					if (!RedisHelper::exists($marker)) {
						$tier = AccountType::tryFrom($row['tier']) ?? AccountType::PRO;
						UsersHelper::addNotification(
							$user,
							'Subscription Renews Soon',
							'Your ' .
								self::tierLabel($tier) .
								' plan renews on ' .
								gmdate('F j, Y', $row['current_period_end']) .
								'.',
							null,
							'info',
							'billing',
						);
						UsersHelper::sendEmail(
							$user,
							'renewal_reminder',
							[
								'tier' => self::tierLabel($tier),
								'price' => self::priceDisplay($row['price_cents']),
								'renews_on' => GeneralHelper::dateToIso($row['current_period_end']),
							],
							false,
						);
						RedisHelper::set($marker, ['sent_at' => $now], 7 * 86400);
					}
				}

				self::drivePriceChangeNotices($user, $row, $now);
			} catch (Throwable $e) {
				Drupal::logger('mantle2')->error('Reconcile failed for a subscription: %m', [
					'%m' => $e->getMessage(),
				]);
			}
		}
	}

	// price_change_notice at T-30/7/1 when a pending change is flagged in redis
	private static function drivePriceChangeNotices(UserInterface $user, array $row, int $now): void
	{
		$flagKey = 'billing:price_change:' . $row['user_id'];
		$flag = RedisHelper::get($flagKey);
		if (!$flag || empty($flag['effective_at'])) {
			return;
		}

		$effectiveAt = (int) $flag['effective_at'];
		$daysOut = (int) floor(($effectiveAt - $now) / 86400);
		$milestone = match (true) {
			$daysOut <= 1 => 1,
			$daysOut <= 7 => 7,
			$daysOut <= 30 => 30,
			default => 0,
		};
		if ($milestone === 0) {
			return;
		}

		$marker = 'billing:price_change_sent:' . $row['user_id'] . ':' . $milestone;
		if (RedisHelper::exists($marker)) {
			return;
		}

		$tier = AccountType::tryFrom($row['tier']) ?? AccountType::PRO;
		UsersHelper::addNotification(
			$user,
			'Upcoming Price Change',
			'The price of your ' . self::tierLabel($tier) . ' plan will change soon.',
			null,
			'warning',
			'billing',
		);
		UsersHelper::sendEmail(
			$user,
			'price_change_notice',
			[
				'tier' => self::tierLabel($tier),
				'old_price' => self::priceDisplay(
					(int) ($flag['old_cents'] ?? $row['price_cents']),
				),
				'new_price' => self::priceDisplay(
					(int) ($flag['new_cents'] ?? $row['price_cents']),
				),
				'effective_at' => GeneralHelper::dateToIso($effectiveAt),
				'days_out' => $milestone,
			],
			false,
		);
		RedisHelper::set($marker, ['sent_at' => $now], ($daysOut + 2) * 86400);
	}

	#endregion
}
