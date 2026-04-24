<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\mantle2\Controller\Schema\Mantle2Schemas;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Notification;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\CampaignHelper;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Exception;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UsersHelper
{
	#region User Retrieval

	public static function cloud(): UserInterface
	{
		// Load the administrator user
		return User::load(1);
	}

	public static function findBy(string $identifier): ?UserInterface
	{
		if (str_starts_with($identifier, '@')) {
			return self::findByUsername(substr($identifier, 1));
		} else {
			return self::findById((int) $identifier);
		}
	}

	public static function findByAuthorized(
		string $identifier,
		Request $request,
	): UserInterface|JsonResponse {
		// Owner of the request
		$user = self::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		// User we want to retrieve
		$user2 = self::findBy($identifier);

		if (!$user2) {
			return GeneralHelper::notFound();
		}

		// Authorization Workflow
		// Allow if requester is the target user or requester has administer users permission
		if ($user->id() === $user2->id() || UsersHelper::isAdmin($user)) {
			return $user2;
		}

		// Return forbidden (failed check)
		return GeneralHelper::forbidden();
	}

	public static function findById(int $id): ?UserInterface
	{
		return User::load($id);
	}

	public static function findByUsername(string $username): ?UserInterface
	{
		try {
			$users = Drupal::entityTypeManager()
				->getStorage('user')
				->loadByProperties(['name' => $username]);

			return $users ? reset($users) : null;
		} catch (Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException | Drupal\Component\Plugin\Exception\PluginNotFoundException $e) {
			Drupal::logger('mantle2')->error('Failed to load user by username: %message', [
				'%message' => $e->getMessage(),
			]);
		}

		return null;
	}

	public static function findByEmail(string $email)
	{
		try {
			$users = Drupal::entityTypeManager()
				->getStorage('user')
				->loadByProperties(['mail' => $email]);

			return $users ? reset($users) : null;
		} catch (Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException | Drupal\Component\Plugin\Exception\PluginNotFoundException $e) {
			Drupal::logger('mantle2')->error('Failed to load user by email: %message', [
				'%message' => $e->getMessage(),
			]);
		}

		return null;
	}

	#endregion

	#region User Endpoint Validation

	public static function getVisibility(UserInterface $user): Visibility
	{
		$visibility = $user->get('field_visibility')->value ?? 1;
		$resolved = Visibility::cases()[(int) $visibility] ?? Visibility::UNLISTED;

		// disabled accounts are never public unless they were explicitly private
		if (self::isDisabled($user) && $resolved !== Visibility::PRIVATE) {
			return Visibility::UNLISTED;
		}

		return $resolved;
	}

	public static function checkVisibility(
		UserInterface $user,
		Request $request,
	): UserInterface|JsonResponse {
		$visibility = self::getVisibility($user);
		// PUBLIC is visible to everyone
		if ($visibility === Visibility::PUBLIC) {
			return $user;
		}

		// UNLISTED (and PRIVATE, see below) requires login
		$user2 = self::getOwnerOfRequest($request);
		if (!$user2) {
			return GeneralHelper::notFound('User not found');
		}

		// (user can see themselves)
		if ($user->id() === $user2->id()) {
			return $user;
		}

		// PRIVATE requires admin or as an added friend
		if (
			$visibility === Visibility::PRIVATE &&
			!UsersHelper::isAdmin($user2) &&
			!self::isAddedFriend($user2, $user)
		) {
			return GeneralHelper::notFound('User not found');
		}

		return $user;
	}

	public static function withSessionId(string $sid, callable $fn)
	{
		$session = Drupal::service('session');
		$currentSessionId = null;

		// Get current session ID if session is started
		if (method_exists($session, 'isStarted') && $session->isStarted()) {
			$currentSessionId = method_exists($session, 'getId') ? $session->getId() : null;
			// Save current session if it's different
			if ($currentSessionId !== $sid && method_exists($session, 'save')) {
				$session->save();
			}
		}

		// Set the session ID we want to work with
		if (method_exists($session, 'setId')) {
			$session->setId($sid);
		}

		// Start session if not started
		if (
			method_exists($session, 'start') &&
			(!method_exists($session, 'isStarted') || !$session->isStarted())
		) {
			try {
				$session->start();
			} catch (\Exception $e) {
				// If session start fails, return null
				return null;
			}
		}

		$result = $fn($session);

		// Restore original session ID if it was different
		if ($currentSessionId && $currentSessionId !== $sid) {
			if (method_exists($session, 'setId')) {
				$session->setId($currentSessionId);
			}
			if (method_exists($session, 'start')) {
				try {
					$session->start();
				} catch (\Exception $e) {
					// Ignore restore errors
				}
			}
		}

		return $result;
	}

	public static function findByRequest(Request $request)
	{
		if ($request->headers->has('X-Admin-Key')) {
			$adminKey = $request->headers->get('X-Admin-Key');
			$expectedKey = CloudHelper::getAdminKey();
			if ($adminKey && $expectedKey && hash_equals($expectedKey, $adminKey)) {
				return self::cloud();
			}
		}

		$sessionId = GeneralHelper::getBearerToken($request);
		if ($sessionId) {
			// First, try persistent API token lookup.
			$user = self::getUserByToken($sessionId);
			if ($user instanceof UserInterface) {
				if (self::isDisabled($user)) {
					return GeneralHelper::forbidden('Account disabled by administrator');
				}

				return $user;
			}

			// Back-compat: attempt to treat bearer as a PHP session id.
			$user = self::withSessionId($sessionId, function ($session) {
				$uid = $session->get('uid');
				return $uid ? User::load($uid) : null;
			});
			if ($user instanceof UserInterface) {
				if (self::isDisabled($user)) {
					return GeneralHelper::forbidden('Account disabled by administrator');
				}

				return $user;
			}

			return GeneralHelper::unauthorized('Invalid or expired session token');
		}

		return GeneralHelper::unauthorized('Authentication required');
	}

	public static function getOwnerOfRequest(Request $request)
	{
		$user = self::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return null;
		}

		return $user;
	}

	#endregion

	#region User Privacy Settings

	public static function isVisible(
		UserInterface $user,
		?UserInterface $user2,
		string $required,
	): bool {
		if ($required === 'PUBLIC') {
			return true;
		}

		if (!$user2) {
			return false;
		}

		if ($user->id() === $user2->id() || UsersHelper::isAdmin($user2)) {
			return true;
		}

		if ($required === 'PRIVATE') {
			return false;
		}

		if ($required === 'CIRCLE' && self::isInCircle($user, $user2)) {
			return true;
		}

		if ($required === 'MUTUAL' && self::isMutualFriend($user, $user2)) {
			return true;
		}

		return false;
	}

	public static function tryVisible(
		$value,
		UserInterface $user,
		?UserInterface $user2,
		string $required,
	) {
		if (self::isVisible($user, $user2, $required)) {
			return $value;
		}
		return null;
	}

	public static array $defaultPrivacy = [
		'name' => 'PUBLIC',
		'bio' => 'PUBLIC',
		'phone_number' => 'CIRCLE',
		'country' => 'PRIVATE',
		'email' => 'MUTUAL',
		'address' => 'PRIVATE',
		'activities' => 'PUBLIC',
		'events' => 'MUTUAL',
		'friends' => 'MUTUAL',
		'last_login' => 'PUBLIC',
		'account_type' => 'PUBLIC',
		'impact_points' => 'PUBLIC',
		'badges' => 'PUBLIC',
	];

	public static function getFieldPrivacy(UserInterface $user)
	{
		$privacy = $user->get('field_privacy')->value ?? '{}';
		$privacy0 = [];
		if ($privacy) {
			$decoded = json_decode($privacy, true);
			$privacy0 = is_array($decoded) ? $decoded : [];
		}

		foreach (self::$defaultPrivacy as $key => $value) {
			if (!isset($privacy0[$key])) {
				$privacy0[$key] = $value;
			}
		}

		// disabled accounts expose only private field visibility regardless of saved preferences
		if (self::isDisabled($user)) {
			foreach ($privacy0 as $key => $value) {
				$privacy0[$key] = 'PRIVATE';
			}
		}

		return $privacy0;
	}

	public static function setFieldPrivacy(UserInterface $user, array $privacy): void
	{
		$user->set('field_privacy', json_encode($privacy));
	}

	#endregion

	#region User Account Tiers

	public static function getAccountType(UserInterface $user): AccountType
	{
		$accountType = $user->get('field_account_type')->value ?? '0';
		$type = AccountType::cases()[(int) $accountType] ?? AccountType::FREE;
		return $type;
	}

	public static function isPro(UserInterface $user): bool
	{
		$accountType = self::getAccountType($user);
		return $accountType !== AccountType::FREE;
	}

	public static function isWriter(UserInterface $user): bool
	{
		$accountType = self::getAccountType($user);
		return in_array(
			$accountType,
			[AccountType::WRITER, AccountType::ORGANIZER, AccountType::ADMINISTRATOR],
			true,
		);
	}

	public static function isOrganizer(UserInterface $user): bool
	{
		$accountType = self::getAccountType($user);
		return in_array($accountType, [AccountType::ORGANIZER, AccountType::ADMINISTRATOR], true);
	}

	public static function isAdmin(?UserInterface $user): bool
	{
		if (!$user) {
			return false;
		}

		$accountType = self::getAccountType($user);

		return $user->hasRole('administrator') ||
			$user->hasPermission('administer users') ||
			$accountType === AccountType::ADMINISTRATOR;
	}

	public static function createTierTrial(
		UserInterface $user,
		AccountType $newType,
		int $days,
		string $reason = '',
	): void {
		if ($days <= 0 || $days > 90) {
			$days = 7; // default to 7 days if invalid
			Drupal::logger('mantle2')->warning(
				'Invalid trial duration %days for user %uid, defaulting to 7 days',
				[
					'%days' => $days,
					'%uid' => $user->id(),
				],
			);
		}

		if (self::isAdmin($user)) {
			Drupal::logger('mantle2')->warning(
				'Attempted to create trial for administrator user %uid',
				['%uid' => $user->id()],
			);
			return;
		}

		if ($newType === AccountType::ADMINISTRATOR) {
			Drupal::logger('mantle2')->error(
				'Attempted to create trial for administrator account (user %uid)',
				['%uid' => $user->id()],
			);
			return;
		}

		// ensure not downgrading or giving trial of same type
		$oldType = self::getAccountType($user);
		$oldOrdinal = GeneralHelper::findOrdinal(AccountType::cases(), $oldType);
		$newOrdinal = GeneralHelper::findOrdinal(AccountType::cases(), $newType);
		if ($newOrdinal <= $oldOrdinal) {
			Drupal::logger('mantle2')->warning(
				'Attempted to create trial for user %uid with invalid type change from %old to %new',
				[
					'%uid' => $user->id(),
					'%old' => $oldType->name,
					'%new' => $newType->name,
				],
			);
			return;
		}

		$trialKey = 'user:account_trial:' . $user->id();
		$trialData = [
			'old_type' => $oldType->name,
			'old_type_ordinal' => $oldOrdinal,
			'new_type' => $newType->name,
			'new_type_ordinal' => $newOrdinal,
		];

		// keys are kept a week longer than trial duration to allow for expiration checks and notifications
		try {
			RedisHelper::set($trialKey, $trialData, ($days + 7) * 86400);
			$user->set('field_account_type', $newOrdinal);
			$user->save();
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error(
				'Failed to save user %uid during trial creation: %message',
				[
					'%uid' => $user->id(),
					'%message' => $e->getMessage(),
				],
			);
			return;
		}

		// send notifications
		self::addNotification(
			$user,
			'Account Trial Activated',
			"Your account trial for $newType->name has been activated and will expire in $days days." .
				($reason ? " Reason: $reason" : ''),
			'info',
		);

		self::sendEmail(
			$user,
			'plan_trial',
			[
				'type' => ucfirst(strtolower($newType->name)),
				'days' => $days,
			],
			false,
		);

		Drupal::logger('mantle2')->notice(
			'Created account trial for user %uid: %old to %new for %days days',
			[
				'%uid' => $user->id(),
				'%old' => $oldType->name,
				'%new' => $newType->name,
				'%days' => $days,
			],
		);
	}

	public static function checkAccountTrials()
	{
		$pattern = 'user:account_trial:*';
		$keys = RedisHelper::list($pattern);

		foreach ($keys as $trialKey) {
			$ttl = RedisHelper::ttl($trialKey);
			if ($ttl < 0) {
				continue;
			}

			$trialSecondsRemaining = $ttl - 7 * 86400;
			$trialData = RedisHelper::get($trialKey);
			if (!$trialData) {
				continue;
			}

			$userId = str_replace('user:account_trial:', '', $trialKey);
			$user = User::load($userId);
			if (!$user) {
				continue;
			}

			$newTypeName = ucfirst(strtolower($trialData['new_type'] ?? 'PRO'));
			$oldTypeName = ucfirst(strtolower($trialData['old_type'] ?? 'FREE'));
			$oldTypeOrdinal = $trialData['old_type_ordinal'] ?? 0;

			if ($trialSecondsRemaining > 0 && $trialSecondsRemaining <= 86400) {
				$warningKey =
					'user:account_trial_warning:' .
					$user->id() .
					':' .
					strtolower((string) ($trialData['new_type'] ?? 'pro'));
				if (!RedisHelper::exists($warningKey)) {
					self::addNotification(
						$user,
						'Account Trial Ending Soon',
						"Your account trial for $newTypeName will expire in 1 day.",
						'warning',
					);

					self::sendEmail(
						$user,
						'plan_trial_warning',
						[
							'type' => $newTypeName,
							'days' => 1,
						],
						false,
					);

					RedisHelper::set(
						$warningKey,
						['sent_at' => time(), 'trial_key' => $trialKey],
						max(3600, $trialSecondsRemaining + 86400),
					);
				}
			}

			if ($trialSecondsRemaining > 0) {
				continue;
			}

			// downgrade account back to old type and delete key
			try {
				$user->set('field_account_type', $oldTypeOrdinal);
				$user->save();
				RedisHelper::delete($trialKey);
			} catch (EntityStorageException $e) {
				Drupal::logger('mantle2')->error(
					'Failed to save user %uid during trial expiration: %message',
					[
						'%uid' => $user->id(),
						'%message' => $e->getMessage(),
					],
				);
				continue;
			}

			self::addNotification(
				$user,
				'Account Trial Expired',
				"Your account trial for $newTypeName has expired and your account has been downgraded to $oldTypeName.",
				'warning',
			);

			self::sendEmail(
				$user,
				'plan_trial_expired',
				[
					'type' => $newTypeName,
					'old_type' => $oldTypeName,
				],
				false,
			);
		}
	}

	public static function enforceDisabledAccountRestrictions(): void
	{
		try {
			$storage = Drupal::entityTypeManager()->getStorage('user');
			$uids = $storage
				->getQuery()
				->accessCheck(false)
				->condition('uid', 0, '>')
				->condition('status', 0)
				->execute();

			if (empty($uids)) {
				return;
			}

			$users = $storage->loadMultiple($uids);
			$processedUsers = 0;
			$revokedTokens = 0;

			foreach ($users as $user) {
				if (!$user instanceof UserInterface) {
					continue;
				}

				// Hard guard: root/admin accounts must never remain disabled.
				if ($user->id() === 1 || self::isAdmin($user)) {
					if ($user->isBlocked()) {
						$user->activate();
						try {
							$user->save();
						} catch (EntityStorageException $e) {
							Drupal::logger('mantle2')->error(
								'Failed to re-enable protected account %uid during cron: %message',
								[
									'%uid' => $user->id(),
									'%message' => $e->getMessage(),
								],
							);
						}
					}

					continue;
				}

				$processedUsers++;
				$revokedTokens += self::revokeAllTokensForUser($user);
				self::clearCachedUserResponses((int) $user->id());
			}

			if ($processedUsers > 0 || $revokedTokens > 0) {
				Drupal::logger('mantle2')->notice(
					'[cron] Enforced disabled-account restrictions for %users user(s), revoked %tokens token(s).',
					[
						'%users' => $processedUsers,
						'%tokens' => $revokedTokens,
					],
				);
			}
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			Drupal::logger('mantle2')->error(
				'Failed to enforce disabled-account restrictions: %message',
				['%message' => $e->getMessage()],
			);
		}
	}

	#endregion

	#region User Fields

	public static function getFirstName(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?string {
		$privacy = self::getFieldPrivacy($user);
		$firstName = $user->get('field_first_name')->value ?? null;
		return self::tryVisible($firstName, $user, $requester, $privacy['name'] ?? 'PUBLIC');
	}

	public static function getLastName(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?string {
		$privacy = self::getFieldPrivacy($user);
		$lastName = $user->get('field_last_name')->value ?? null;
		return self::tryVisible($lastName, $user, $requester, $privacy['name'] ?? 'PUBLIC');
	}

	public static function getName(UserInterface $user, ?UserInterface $requester = null): ?string
	{
		$firstName = self::getFirstName($user, $requester);
		$lastName = self::getLastName($user, $requester);

		$parts = array_filter([$firstName, $lastName], fn($v) => !empty($v));
		return !empty($parts) ? implode(' ', $parts) : null;
	}

	public static function getEmail(UserInterface $user, ?UserInterface $requester = null): ?string
	{
		$privacy = self::getFieldPrivacy($user);
		return self::tryVisible(
			$user->getEmail(),
			$user,
			$requester,
			$privacy['email'] ?? 'MUTUAL',
		);
	}

	public static function getBiography(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?string {
		$privacy = self::getFieldPrivacy($user);
		$bio = $user->get('field_bio')->value ?? '';
		return self::tryVisible($bio, $user, $requester, $privacy['bio'] ?? 'PUBLIC');
	}

	public static function getPhoneNumber(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?int {
		$privacy = self::getFieldPrivacy($user);
		$phoneNumber = $user->get('field_phone')->value ?? 0;
		return self::tryVisible(
			$phoneNumber,
			$user,
			$requester,
			$privacy['phone_number'] ?? 'CIRCLE',
		);
	}

	public static function getAddress(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?string {
		$privacy = self::getFieldPrivacy($user);
		$address = $user->get('field_address')->value ?? '';
		return self::tryVisible($address, $user, $requester, $privacy['address'] ?? 'PRIVATE');
	}

	public static function getCountry(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?string {
		$privacy = self::getFieldPrivacy($user);
		$country = $user->get('field_country')->value ?? '';
		return self::tryVisible($country, $user, $requester, $privacy['country'] ?? 'PUBLIC');
	}

	public static function isEmailVerified(UserInterface $user): bool
	{
		return (bool) $user->get('field_email_verified')->value;
	}

	public static function isSubscribed(UserInterface $user): bool
	{
		return (bool) ($user->get('field_subscribed')->value ?? true);
	}

	public static function setSubscribed(UserInterface $user, bool $subscribed): void
	{
		$user->set('field_subscribed', $subscribed);
	}

	public static function isDisabled(UserInterface $user): bool
	{
		// root user and admins cannot be disabled
		if ($user->id() === 1 || self::isAdmin($user)) {
			return false;
		}

		return $user->isBlocked();
	}

	public static function setDisabled(UserInterface $user, bool $disabled): void
	{
		if ($user->id() === 1 || self::isAdmin($user)) {
			Drupal::logger('mantle2')->warning(
				'Attempted to disable root user or admin account, which is not allowed: user %uid',
				['%uid' => $user->id()],
			);

			if ($user->isBlocked()) {
				$user->activate();
			}

			return;
		}

		if ($disabled) {
			$user->block();
		} else {
			$user->activate();
		}
	}

	#endregion

	#region User CRUD Operations

	public static function serializeUser(
		UserInterface $user,
		?UserInterface $requester = null,
	): array {
		$privacy = self::getFieldPrivacy($user);

		$visibility = self::getVisibility($user);

		// PRIVATE requires admin or as an added friend
		if (
			$visibility === Visibility::PRIVATE &&
			!UsersHelper::isAdmin($requester) &&
			!self::isAddedFriend($requester, $user)
		) {
			return [];
		}

		// UNLISTED requires logged in user
		if ($visibility === Visibility::UNLISTED && !$requester) {
			return [];
		}

		// apply caching based on relationship to requester
		$cacheKey = '';

		// anonymous (PUBLIC)
		if (!$requester) {
			$cacheKey = 'user:' . $user->id() . ':public';
		}
		// self or admin (PRIVATE)
		elseif ($requester->id() === $user->id() || UsersHelper::isAdmin($requester)) {
			$cacheKey = 'user:' . $user->id() . ':private';
		}
		// circle (CIRCLE)
		elseif (self::isInCircle($user, $requester)) {
			$cacheKey = 'user:' . $user->id() . ':circle:' . $requester->id();
		}
		// friend (MUTUAL)
		elseif (self::isMutualFriend($user, $requester)) {
			$cacheKey = 'user:' . $user->id() . ':mutual:' . $requester->id();
		} else {
			// no caching for other relationships
			$cacheKey = null;
		}

		return RedisHelper::cache(
			$cacheKey,
			function () use ($user, $requester, $privacy) {
				return [
					'id' => GeneralHelper::formatId($user->id()),
					'username' => $user->getAccountName(),
					'full_name' => self::getName($user, $requester),
					'created_at' => date('c', $user->getCreatedTime()),
					'updated_at' => date('c', $user->getChangedTime()),
					'last_login' => date('c', $user->getLastLoginTime()),
					'is_admin' => self::isAdmin($user),
					'account' => [
						'id' => GeneralHelper::formatId($user->id()),
						'avatar_url' =>
							'https://api.earth-app.com/v2/users/' .
							GeneralHelper::formatId($user->id()) .
							'/profile_photo',
						'avatar_cosmetic' => PointsHelper::getAvatarCosmetic($user),
						'username' => $user->getAccountName(),
						'first_name' => self::getFirstName($user, $requester),
						'last_name' => self::getLastName($user, $requester),
						'email' => self::getEmail($user, $requester),
						'bio' => self::getBiography($user, $requester),
						'phone_number' => self::getPhoneNumber($user, $requester),
						'address' => self::getAddress($user, $requester),
						'country' => self::getCountry($user, $requester),
						'account_type' => self::tryVisible(
							self::getAccountType($user)->name,
							$user,
							$requester,
							$privacy['account_type'] ?? 'PUBLIC',
						),
						'email_verified' => self::tryVisible(
							self::isEmailVerified($user),
							$user,
							$requester,
							'PRIVATE',
						),
						'has_password' => self::tryVisible(
							self::hasPassword($user),
							$user,
							$requester,
							'PRIVATE',
						),
						'linked_providers' => self::tryVisible(
							OAuthHelper::getLinkedProviders($user),
							$user,
							$requester,
							'PRIVATE',
						),
						'subscribed' => self::tryVisible(
							self::isSubscribed($user),
							$user,
							$requester,
							'PRIVATE',
						),
						'visibility' => self::getVisibility($user)->name,
						'field_privacy' => $privacy,
					],
					'activities' => self::getActivities($user),
					'is_friend' => $requester ? self::isAddedFriend($requester, $user) : false,
					'is_my_friend' => $requester ? self::isAddedFriend($user, $requester) : false,
					'is_mutual' => $requester ? self::isMutualFriend($user, $requester) : false,
					'friends' => self::tryVisible(
						json_decode($user->get('field_friends')->value ?? '[]', true),
						$user,
						$requester,
						$privacy['friends'] ?? 'MUTUAL',
					),
					'added_count' => self::tryVisible(
						self::getAddedFriendsCount($user),
						$user,
						$requester,
						$privacy['friends'] ?? 'MUTUAL',
					),
					'mutual_count' => self::getMutualFriendsCount($user, $requester),
					'non_mutual_count' => self::tryVisible(
						self::getNonMutualFriendsCount($user),
						$user,
						$requester,
						'PRIVATE',
					),
					'is_in_circle' => $requester && self::isInCircle($user, $requester),
					'is_in_my_circle' => $requester && self::isInCircle($requester, $user),
					'circle' => self::tryVisible(
						json_decode($user->get('field_circle')->value ?? '[]', true),
						$user,
						$requester,
						'PRIVATE',
					),
					'circle_count' => self::tryVisible(
						self::getCircleCount($user),
						$user,
						$requester,
						'PRIVATE',
					),
					'max_circle_count' => self::tryVisible(
						self::getMaxCircleCount($user),
						$user,
						$requester,
						'PRIVATE',
					),
					'disabled' => self::isDisabled($user), // disabled state is public
				];
			},
			90, // short TTL
		);
	}

	public static function patchUser(
		UserInterface $user,
		array $data,
		?UserInterface $requester = null,
	): JsonResponse {
		if (!$data) {
			return GeneralHelper::badRequest('Invalid user or data');
		}

		$previouslyDisabled = self::isDisabled($user);
		$newDisabled = $previouslyDisabled;
		$disabledChanged = false;

		if (array_key_exists('disabled', $data)) {
			if (!$requester || !self::isAdmin($requester)) {
				return GeneralHelper::forbidden(
					'Only administrators can update the disabled state of an account.',
				);
			}

			if (!is_bool($data['disabled'])) {
				return GeneralHelper::badRequest('Field disabled must be a boolean');
			}

			$newDisabled = $data['disabled'];

			if ($newDisabled && ($user->id() === 1 || self::isAdmin($user))) {
				Drupal::logger('mantle2')->warning(
					'User %actor attempted to disable protected account %target, which is not allowed.',
					[
						'%actor' => $requester->id(),
						'%target' => $user->id(),
					],
				);

				return GeneralHelper::forbidden(
					'Root and administrator accounts cannot be disabled.',
				);
			}

			self::setDisabled($user, $newDisabled);
			$disabledChanged = $previouslyDisabled !== $newDisabled;
		}

		if (isset($data['username'])) {
			$username = trim(strtolower((string) $data['username']));
			$len = strlen($username);
			if ($len < 3 || $len > 30) {
				return GeneralHelper::badRequest('Invalid username length');
			}

			$existing = self::findByUsername($username);
			if ($existing && $existing->id() !== $user->id()) {
				return GeneralHelper::badRequest('Username already exists');
			}

			$user->setUsername($username);
		}

		$emailChangeInitiated = false;
		if (isset($data['email'])) {
			$email = trim((string) $data['email']);
			$currentEmail = $user->getEmail();
			if ($currentEmail === $email) {
				// No change needed, skip email processing
			} else {
				// Use the email change verification flow for different emails
				$emailChangeResult = self::sendEmailChangeVerification($user, $email);
				if ($emailChangeResult->getStatusCode() !== Response::HTTP_OK) {
					return $emailChangeResult;
				}

				// Email change verification was sent successfully
				$emailChangeInitiated = true;
			}
		}

		if (isset($data['first_name'])) {
			$firstName = trim((string) $data['first_name']);
			$len = strlen($firstName);
			if ($len < 2 || $len > 50) {
				return GeneralHelper::badRequest('Invalid first name length');
			}

			$flagResult = GeneralHelper::isFlagged($firstName);
			if ($flagResult['flagged']) {
				Drupal::logger('mantle2')->warning(
					'User %uid attempted to create flagged first name: %first_name (matched: %matched)',
					[
						'%uid' => $user->id(),
						'%first_name' => $firstName,
						'%matched' => $flagResult['matched_word'],
					],
				);
				return GeneralHelper::badRequest(
					'First name contains inappropriate content: ' . $flagResult['matched_word'],
				);
			}

			$user->set('field_first_name', $firstName);
		}

		if (isset($data['last_name'])) {
			$lastName = trim((string) $data['last_name']);
			$len = strlen($lastName);
			if ($len < 2 || $len > 50) {
				return GeneralHelper::badRequest('Invalid last name length');
			}

			$flagResult = GeneralHelper::isFlagged($lastName);
			if ($flagResult['flagged']) {
				Drupal::logger('mantle2')->warning(
					'User %uid attempted to create flagged last name: %last_name (matched: %matched)',
					[
						'%uid' => $user->id(),
						'%last_name' => $lastName,
						'%matched' => $flagResult['matched_word'],
					],
				);
				return GeneralHelper::badRequest(
					'Last name contains inappropriate content: ' . $flagResult['matched_word'],
				);
			}

			$user->set('field_last_name', $lastName);
		}

		if (isset($data['bio'])) {
			$bio = trim((string) $data['bio']);
			$len = strlen($bio);
			if ($len > 500) {
				return GeneralHelper::badRequest(
					'Invalid biography length: Maximum 500 characters',
				);
			}

			$censor = $data['censor'] ?? false;
			if (!is_bool($censor)) {
				return GeneralHelper::badRequest('Field censor must be a boolean');
			}

			$flagResult = GeneralHelper::isFlagged($bio);
			if ($flagResult['flagged']) {
				if ($censor) {
					$bio = GeneralHelper::censorText($bio);
				} else {
					Drupal::logger('mantle2')->warning(
						'User %uid attempted to create flagged biography: %bio (matched: %matched)',
						[
							'%uid' => $user->id(),
							'%bio' => $bio,
							'%matched' => $flagResult['matched_word'],
						],
					);
					return GeneralHelper::badRequest(
						'Biography contains inappropriate content: ' . $flagResult['matched_word'],
					);
				}
			}

			$user->set('field_bio', $bio);
		}

		if (isset($data['country'])) {
			$country = trim((string) $data['country']);
			$len = strlen($country);
			if ($len != 2) {
				return GeneralHelper::badRequest(
					'Invalid country code length: Must be 2 characters',
				);
			}

			$user->set('field_country', $country);
		}

		if (isset($data['visibility'])) {
			$visibility = trim((string) $data['visibility']);
			$visibility0 = Visibility::tryFrom($visibility);
			if ($visibility0 === null) {
				return GeneralHelper::badRequest('Invalid visibility value');
			}

			$user->set(
				'field_visibility',
				GeneralHelper::findOrdinal(Visibility::cases(), $visibility0),
			);
		}

		if (isset($data['subscribed'])) {
			$subscribed = (bool) $data['subscribed'];
			$user->set('field_subscribed', $subscribed);
		}

		try {
			$user->save();
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error('Failed to save user: %message', [
				'%message' => $e->getMessage(),
			]);
			return GeneralHelper::internalError('Failed to save user');
		}

		$responseData = self::serializeUser($user, $requester);

		if ($disabledChanged) {
			self::clearCachedUserResponses((int) $user->id());

			$actorName = $requester ? $requester->getAccountName() : 'system';
			$action = $newDisabled ? 'disabled' : 're-enabled';
			$timestamp = date(DATE_ATOM);

			Drupal::logger('mantle2')->notice('User %uid was %action by %actor at %time.', [
				'%uid' => $user->id(),
				'%action' => $action,
				'%actor' => $actorName,
				'%time' => $timestamp,
			]);

			if ($newDisabled) {
				self::addNotification(
					$user,
					'Account Disabled',
					'Your account was disabled by an administrator. Please contact support if you believe this is an error.',
					'warning',
				);

				self::sendEmail(
					$user,
					'account_disabled',
					[
						'time' => $timestamp,
						'actor' => $actorName,
					],
					false,
				);
			} else {
				self::addNotification(
					$user,
					'Account Re-enabled',
					'Your account was re-enabled by an administrator. You can now sign back in.',
					'info',
				);

				self::sendEmail(
					$user,
					'account_enabled',
					[
						'time' => $timestamp,
						'actor' => $actorName,
					],
					false,
				);
			}
		}

		// Add email change information to response if email change was initiated
		if ($emailChangeInitiated) {
			$responseData['email_change_pending'] = true;
			$responseData['message'] =
				'User updated successfully. Email change verification sent to new address.';
		}

		return new JsonResponse($responseData, Response::HTTP_OK);
	}

	private static function validKeys(): array
	{
		return array_keys(Mantle2Schemas::userFieldPrivacy()['properties']);
	}

	private static array $neverPublic = ['address', 'phone_number', 'circle'];

	public static function patchFieldPrivacy(
		UserInterface $user,
		array $data,
		?UserInterface $requester = null,
	): JsonResponse {
		if (empty($data)) {
			return GeneralHelper::badRequest('No data provided');
		}

		$fieldPrivacy = self::getFieldPrivacy($user);
		foreach ($data as $key => $value) {
			if (!in_array($key, self::validKeys(), true)) {
				return GeneralHelper::badRequest("Invalid field: $key");
			}

			if ($value === 'PUBLIC' && in_array($key, self::$neverPublic, true)) {
				return GeneralHelper::badRequest("Field $key cannot be made public");
			}

			$fieldPrivacy[$key] = $value;
		}

		self::setFieldPrivacy($user, $fieldPrivacy);
		try {
			$user->save();
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error('Failed to save field privacy: %message', [
				'%message' => $e->getMessage(),
			]);
			return GeneralHelper::internalError('Failed to save field privacy');
		}
		return new JsonResponse(self::serializeUser($user, $requester), Response::HTTP_OK);
	}

	#endregion

	#region User Profile Photos

	public static function getProfilePhoto(UserInterface $user, int $size = 1024): string
	{
		$selectedCosmetic = PointsHelper::getAvatarCosmetic($user);
		$cacheKey = 'cloud:user:photo:' . $user->id() . ':' . $size;
		if ($selectedCosmetic) {
			$cacheKey .= ':' . $selectedCosmetic;
		}

		$cached = RedisHelper::get($cacheKey);
		if ($cached !== null) {
			return $cached['photo'] ?? '';
		}

		try {
			$res = CloudHelper::sendRequest('/v1/users/profile_photo/' . $user->id(), 'GET', [
				'size' => $size,
			]);

			$data = $res['data'] ?? '';

			if ($data && $selectedCosmetic) {
				$data = PointsHelper::applyCosmetic($data, $selectedCosmetic) ?: $data;
			}

			RedisHelper::set($cacheKey, ['photo' => $data], 1800);
			return $data ?: '';
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to retrieve profile photo: %message', [
				'%message' => $e->getMessage(),
			]);
		}

		return '';
	}

	public static function regenerateProfilePhoto(UserInterface $user): string
	{
		try {
			$res = CloudHelper::sendRequest('/v1/users/profile_photo/' . $user->id(), 'PUT', [
				'username' => $user->getAccountName(),
				'bio' => self::getBiography($user, $user),
				'created_at' => date('c', $user->getCreatedTime()),
				'visibility' => self::getVisibility($user)->name,
				'country' => self::getCountry($user, $user),
				'full_name' => self::getName($user, $user),
				'activities' => self::getActivities($user, $user),
			]);

			$data = $res['data'] ?? null;
			return $data ?: '';
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to regenerate profile photo: %message', [
				'%message' => $e->getMessage(),
			]);
		}

		return '';
	}

	#endregion

	#region User Friends

	/**
	 * @return UserInterface[]
	 */
	public static function getAddedFriends(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
		string $sort = 'desc',
	): array {
		if ($user === null) {
			return [];
		}

		if ($sort === 'rand') {
			$friendsValue = $user->get('field_friends')->value ?? '[]';
			$friends = $friendsValue ? json_decode($friendsValue, true) : [];
			$friendUsers = array_filter(array_map(fn($id) => self::findById($id), $friends));
			shuffle($friendUsers);
			return array_slice($friendUsers, ($page - 1) * $limit, $limit);
		}

		$cacheKey =
			'helper:friends:' .
			$user->id() .
			':' .
			$page .
			':' .
			$limit .
			':' .
			md5($search) .
			':' .
			$sort;

		$friendIds = RedisHelper::cache(
			$cacheKey,
			function () use ($user, $limit, $page, $search, $sort) {
				$friendsValue = $user->get('field_friends')->value ?? '[]';
				$friends = $friendsValue ? json_decode($friendsValue, true) : [];
				$friendUsers = array_filter(array_map(fn($id) => self::findById($id), $friends));

				if (!empty($search)) {
					$friendUsers = array_filter($friendUsers, function ($u) use ($search) {
						return str_contains($u->getAccountName(), $search);
					});
				}

				usort($friendUsers, function ($a, $b) use ($sort) {
					$aTime = $a->getCreatedTime();
					$bTime = $b->getCreatedTime();
					return $sort === 'desc' ? $bTime <=> $aTime : $aTime <=> $bTime;
				});

				$sliced = array_slice($friendUsers, ($page - 1) * $limit, $limit);
				// Return only IDs for caching
				return array_map(fn($user) => $user->id(), $sliced);
			},
			120,
		);

		// Re-fetch UserInterface objects from cached IDs
		return array_filter(array_map(fn($id) => self::findById($id), $friendIds));
	}

	public static function getAddedFriendsCount(UserInterface $user, string $search = ''): int
	{
		$friendsValue = $user->get('field_friends')->value ?? '[]';

		/** @var int[] $friends*/
		$friends = $friendsValue ? json_decode($friendsValue, true) : [];
		if (!empty($search)) {
			$friends = array_filter(
				$friends,
				fn($id) => ($u = self::findById($id)) &&
					str_contains($u->getAccountName(), $search),
			);
		}

		return count($friends);
	}

	public static function isAddedFriend(?UserInterface $user, ?UserInterface $friend): bool
	{
		if (!$user || !$friend) {
			return false;
		}

		$friends = $user->get('field_friends')->value ?? '[]';
		$friends = $friends ? json_decode($friends, true) : [];
		return in_array($friend->id(), $friends, true);
	}

	public static function isMutualFriend(?UserInterface $user1, ?UserInterface $user2): bool
	{
		if (!$user1 || !$user2) {
			return false;
		}

		try {
			$friends1 = json_decode($user1->get('field_friends')->value ?? '[]', true) ?: [];
			$friends2 = json_decode($user2->get('field_friends')->value ?? '[]', true) ?: [];
			return !empty(array_intersect($friends1, $friends2));
		} catch (Exception $e) {
			return false;
		}
	}

	public static function getMutualFriends(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
		string $sort = 'desc',
	): array {
		try {
			if ($sort === 'rand') {
				$userFriendsIds =
					json_decode($user->get('field_friends')->value ?? '[]', true) ?: [];
				if (empty($userFriendsIds)) {
					return [];
				}
				$userFriends = array_filter(
					array_map(fn($id) => self::findById($id), $userFriendsIds),
				);
				$friendCounts = [];
				foreach ($userFriends as $friend) {
					$friendsOfFriendIds =
						json_decode($friend->get('field_friends')->value ?? '[]', true) ?: [];
					foreach ($friendsOfFriendIds as $potentialMutualId) {
						if ($potentialMutualId === $user->id()) {
							continue;
						}
						$friendCounts[$potentialMutualId] =
							($friendCounts[$potentialMutualId] ?? 0) + 1;
					}
				}
				$mutual = [];
				foreach ($friendCounts as $personId => $count) {
					if ($count >= 2 && ($potentialUser = self::findById($personId))) {
						$mutual[] = $potentialUser;
					}
				}
				shuffle($mutual);
				return array_slice($mutual, ($page - 1) * $limit, $limit);
			}

			$cacheKey =
				'helper:mutual:' .
				$user->id() .
				':' .
				$page .
				':' .
				$limit .
				':' .
				md5($search) .
				':' .
				$sort;

			$mutualIds = RedisHelper::cache(
				$cacheKey,
				function () use ($user, $limit, $page, $search, $sort) {
					$userFriendsIds =
						json_decode($user->get('field_friends')->value ?? '[]', true) ?: [];
					if (empty($userFriendsIds)) {
						return [];
					}

					$userFriends = array_filter(
						array_map(fn($id) => self::findById($id), $userFriendsIds),
					);
					$friendCounts = [];

					foreach ($userFriends as $friend) {
						$friendsOfFriendIds =
							json_decode($friend->get('field_friends')->value ?? '[]', true) ?: [];
						foreach ($friendsOfFriendIds as $potentialMutualId) {
							if ($potentialMutualId === $user->id()) {
								continue;
							}
							$friendCounts[$potentialMutualId] =
								($friendCounts[$potentialMutualId] ?? 0) + 1;
						}
					}

					$mutual = [];
					foreach ($friendCounts as $personId => $count) {
						if ($count >= 2 && ($potentialUser = self::findById($personId))) {
							$mutual[] = $potentialUser;
						}
					}

					if (!empty($search)) {
						$mutual = array_filter(
							$mutual,
							fn($u) => str_contains($u->getAccountName(), $search),
						);
					}

					usort($mutual, function ($a, $b) use ($sort) {
						$aTime = $a->getCreatedTime();
						$bTime = $b->getCreatedTime();
						return $sort === 'desc' ? $bTime <=> $aTime : $aTime <=> $bTime;
					});

					$sliced = array_slice($mutual, ($page - 1) * $limit, $limit);
					// Return only IDs for caching
					return array_map(fn($user) => $user->id(), $sliced);
				},
				120,
			);

			// Re-fetch UserInterface objects from cached IDs
			return array_filter(array_map(fn($id) => self::findById($id), $mutualIds));
		} catch (Exception $e) {
			return [];
		}
	}

	public static function getMutualFriendsCount(
		UserInterface $user,
		?UserInterface $requester = null,
		string $search = '',
	): int {
		if (!$requester) {
			return 0;
		}

		try {
			$userFriendsIds = json_decode($user->get('field_friends')->value ?? '[]', true) ?: [];
			$requesterFriendsIds =
				json_decode($requester->get('field_friends')->value ?? '[]', true) ?: [];

			$mutualIds = array_intersect($userFriendsIds, $requesterFriendsIds);

			if (!empty($search)) {
				$mutualUsers = array_filter(array_map(fn($id) => self::findById($id), $mutualIds));
				$mutualUsers = array_filter(
					$mutualUsers,
					fn($u) => str_contains($u->getAccountName(), $search),
				);
				return count($mutualUsers);
			}

			return count($mutualIds);
		} catch (Exception $e) {
			return 0;
		}
	}

	public static function getNonMutualFriends(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
		string $sort = 'desc',
	): array {
		try {
			$userFriendsIds = json_decode($user->get('field_friends')->value ?? '[]', true) ?: [];
			if (empty($userFriendsIds)) {
				return [];
			}

			$userFriends = array_filter(array_map(fn($id) => self::findById($id), $userFriendsIds));
			$nonMutual = [];

			foreach ($userFriends as $friend) {
				$friendsOfFriendIds =
					json_decode($friend->get('field_friends')->value ?? '[]', true) ?: [];
				foreach ($friendsOfFriendIds as $potentialNonMutualId) {
					if ($potentialNonMutualId === $user->id()) {
						continue;
					}
					$nonMutual[$potentialNonMutualId] =
						($nonMutual[$potentialNonMutualId] ?? 0) + 1;
				}
			}

			$nonMutual = array_filter($nonMutual, fn($count) => $count === 1);
			$nonMutualUsers = array_filter(
				array_map(fn($id) => self::findById($id), array_keys($nonMutual)),
			);

			// Apply search filter
			if (!empty($search)) {
				$nonMutualUsers = array_filter(
					$nonMutualUsers,
					fn($u) => str_contains($u->getAccountName(), $search),
				);
			}

			// Apply sorting
			if ($sort === 'rand') {
				shuffle($nonMutualUsers);
			} else {
				usort($nonMutualUsers, function ($a, $b) use ($sort) {
					$aTime = $a->getCreatedTime();
					$bTime = $b->getCreatedTime();
					return $sort === 'desc' ? $bTime <=> $aTime : $aTime <=> $bTime;
				});
			}

			// Apply pagination
			$offset = ($page - 1) * $limit;
			return array_slice($nonMutualUsers, $offset, $limit);
		} catch (Exception $e) {
			return [];
		}
	}

	public static function getNonMutualFriendsCount(UserInterface $user, string $search = ''): int
	{
		try {
			$userFriendsIds = json_decode($user->get('field_friends')->value ?? '[]', true) ?: [];
			if (empty($userFriendsIds)) {
				return 0;
			}

			$userFriends = array_filter(array_map(fn($id) => self::findById($id), $userFriendsIds));
			$nonMutual = [];

			foreach ($userFriends as $friend) {
				$friendsOfFriendIds =
					json_decode($friend->get('field_friends')->value ?? '[]', true) ?: [];
				foreach ($friendsOfFriendIds as $potentialNonMutualId) {
					if ($potentialNonMutualId === $user->id()) {
						continue;
					}
					$nonMutual[$potentialNonMutualId] =
						($nonMutual[$potentialNonMutualId] ?? 0) + 1;
				}
			}

			$nonMutual = array_filter($nonMutual, fn($count) => $count === 1);
			$nonMutualUsers = array_filter(
				array_map(fn($id) => self::findById($id), array_keys($nonMutual)),
			);

			if (!empty($search)) {
				$nonMutualUsers = array_filter(
					$nonMutualUsers,
					fn($u) => str_contains($u->getAccountName(), $search),
				);
			}

			return count($nonMutualUsers);
		} catch (Exception $e) {
			return 0;
		}
	}

	public static function getAddedBy(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
		string $sort = 'desc',
	): array {
		try {
			$limit = max(1, $limit);
			$page = max(1, $page);
			$sort = $sort === 'asc' || $sort === 'rand' ? $sort : 'desc';

			$addedByIndex = self::resolveAddedByIndex($user, $search);
			if (empty($addedByIndex)) {
				return [];
			}

			if ($sort === 'rand') {
				$addedByIds = array_map(fn($item) => $item['id'], $addedByIndex);
				shuffle($addedByIds);
			} else {
				usort($addedByIndex, function (array $a, array $b) use ($sort) {
					return $sort === 'desc'
						? $b['created'] <=> $a['created']
						: $a['created'] <=> $b['created'];
				});
				$addedByIds = array_map(fn($item) => $item['id'], $addedByIndex);
			}

			$offset = ($page - 1) * $limit;
			$addedByIds = array_slice($addedByIds, $offset, $limit);
			if (empty($addedByIds)) {
				return [];
			}

			$storage = Drupal::entityTypeManager()->getStorage('user');
			$addedByUsers = $storage->loadMultiple($addedByIds);

			// Preserve order from pagination/sort.
			$result = [];
			foreach ($addedByIds as $addedById) {
				if (isset($addedByUsers[$addedById])) {
					$result[] = $addedByUsers[$addedById];
				}
			}

			return $result;
		} catch (Exception $e) {
			return [];
		}
	}

	/**
	 * @return array<array{id: int, created: int}>
	 */
	private static function resolveAddedByIndex(UserInterface $user, string $search = ''): array
	{
		$cacheKey = 'helper:added_by_index:' . $user->id() . ':' . md5($search);

		$addedByIndex = RedisHelper::cache(
			$cacheKey,
			function () use ($user, $search) {
				$storage = Drupal::entityTypeManager()->getStorage('user');
				$uids = $storage
					->getQuery()
					->accessCheck(false)
					->condition('uid', 0, '>')
					->execute();
				$users = $storage->loadMultiple($uids);

				$targetId = (int) $user->id();
				$addedBy = [];

				foreach ($users as $candidate) {
					if (
						!$candidate instanceof UserInterface ||
						(int) $candidate->id() === $targetId
					) {
						continue;
					}

					$friendsRaw = $candidate->get('field_friends')->value ?? '[]';
					$friends = $friendsRaw ? json_decode($friendsRaw, true) : [];
					if (!is_array($friends)) {
						$friends = [];
					}

					$friends = array_map(fn($id) => (int) $id, $friends);
					if (!in_array($targetId, $friends, true)) {
						continue;
					}

					if (!empty($search) && !str_contains($candidate->getAccountName(), $search)) {
						continue;
					}

					$addedBy[] = [
						'id' => (int) $candidate->id(),
						'created' => (int) $candidate->getCreatedTime(),
					];
				}

				return $addedBy;
			},
			120,
		);

		return is_array($addedByIndex) ? $addedByIndex : [];
	}

	public static function getAddedByCount(UserInterface $user, string $search = ''): int
	{
		try {
			return count(self::resolveAddedByIndex($user, $search));
		} catch (Exception $e) {
			return 0;
		}
	}

	public static function addFriend(UserInterface $user, UserInterface $friend): bool
	{
		try {
			$friends = $user->get('field_friends')->value ?? '[]';
			$friends0 = $friends ? json_decode($friends, true) : [];
			if (in_array($friend->id(), $friends0, true)) {
				return false;
			}

			$friends0[] = $friend->id();
			$user->set('field_friends', json_encode($friends0));
			$user->save();

			$name = $user->getAccountName();

			if (self::isMutualFriend($user, $friend)) {
				self::addNotification(
					$friend,
					Drupal::translation()->translate('New Mutual Friend'),
					Drupal::translation()->translate(
						"$name has added you as a friend. You are now mutual friends!",
					),
					"/profile/{$user->id()}",
					'info',
					'system',
				);
			} else {
				self::addNotification(
					$friend,
					Drupal::translation()->translate('New Friend Added'),
					Drupal::translation()->translate("$name has added you as a friend."),
					"/profile/{$user->id()}",
					'info',
					'system',
				);
			}

			return true;
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to add friend: %message', [
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	public static function removeFriend(UserInterface $user, UserInterface $friend): bool
	{
		try {
			$friends = $user->get('field_friends')->value ?? '[]';
			$friends0 = $friends ? json_decode($friends, true) : [];
			if (!in_array($friend->id(), $friends0, true)) {
				return false;
			}

			$friends0 = array_filter($friends0, fn($id) => $id !== $friend->id());
			$user->set('field_friends', json_encode($friends0));
			$user->save();

			$name = $user->getAccountName();
			self::addNotification(
				$friend,
				Drupal::translation()->translate('Friend Removed'),
				Drupal::translation()->translate("$name has removed you as a friend."),
				"/profile/{$user->id()}",
				'info',
				'system',
			);

			return true;
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to remove friend: %message', [
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	#endregion

	#region User Circles

	/**
	 * @return UserInterface[]
	 */
	public static function getCircle(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
		string $sort = 'desc',
	): array {
		$circleValue = $user->get('field_circle')->value ?? '[]';

		/** @var int[] $circle */
		$circle = $circleValue ? json_decode($circleValue, true) : [];
		$circleUsers = array_filter(array_map(fn($id) => self::findById($id), $circle));

		// Apply search filter
		if (!empty($search)) {
			$circleUsers = array_filter(
				$circleUsers,
				fn($u) => str_contains($u->getAccountName(), $search),
			);
		}

		// Apply sorting
		if ($sort === 'rand') {
			shuffle($circleUsers);
		} else {
			usort($circleUsers, function ($a, $b) use ($sort) {
				$aTime = $a->getCreatedTime();
				$bTime = $b->getCreatedTime();
				return $sort === 'desc' ? $bTime <=> $aTime : $aTime <=> $bTime;
			});
		}

		// Apply pagination
		return array_slice($circleUsers, ($page - 1) * $limit, $limit);
	}

	public static function getCircleCount(UserInterface $user, string $search = ''): int
	{
		$circleValue = $user->get('field_circle')->value ?? '[]';

		/** @var int[] $circle */
		$circle = $circleValue ? json_decode($circleValue, true) : [];
		if (!empty($search)) {
			$circleUsers = array_filter(array_map(fn($id) => self::findById($id), $circle));
			$circleUsers = array_filter(
				$circleUsers,
				fn($u) => str_contains($u->getAccountName(), $search),
			);
			return count($circleUsers);
		}

		return count($circle);
	}

	public static function getMaxCircleCount(?UserInterface $user = null): int
	{
		if ($user == null) {
			return 100;
		}

		$accountType = self::getAccountType($user);
		return match ($accountType) {
			AccountType::FREE => 25,
			AccountType::PRO => 500,
			AccountType::WRITER => 500,
			AccountType::ORGANIZER => 1000,
			AccountType::ADMINISTRATOR => 1000,
		};
	}

	public static function isInCircle(UserInterface $user1, UserInterface $user2): bool
	{
		if ($user1->id() === $user2->id()) {
			return false; // cannot be in own circle
		}

		$circle = self::getCircle($user1);
		return in_array($user2, $circle, true);
	}

	public static function addToCircle(UserInterface $user, UserInterface $member): bool
	{
		try {
			if ($user->id() === $member->id()) {
				Drupal::logger('mantle2')->warning(
					'User %uid attempted to add themselves to their own circle.',
					[
						'%uid' => $user->id(),
					],
				);
				return false;
			}

			if (!self::isAddedFriend($user, $member)) {
				Drupal::logger('mantle2')->warning(
					'User %uid is not a friend of %friend_uid and cannot be added to the circle.',
					[
						'%uid' => $user->id(),
						'%friend_uid' => $member->id(),
					],
				);
				return false;
			}

			$circle = $user->get('field_circle')->value ?? '[]';
			$circle0 = $circle ? json_decode($circle, true) : [];

			$max = self::getMaxCircleCount($user);
			if (count($circle0) >= $max) {
				Drupal::logger('mantle2')->warning(
					'User %uid has reached the maximum circle size and cannot add more members.',
					[
						'%uid' => $user->id(),
					],
				);
				return false;
			}

			if (in_array($member->id(), $circle0, true)) {
				return false;
			}

			$circle0[] = $member->id();
			$user->set('field_circle', json_encode($circle0));
			$user->save();

			self::addNotification(
				$member,
				Drupal::translation()->translate('Added to Circle'),
				Drupal::translation()->translate("You have been added to %name's circle.", [
					'%name' => $user->getAccountName(),
				]),
				"/profile/{$user->id()}",
				'info',
				'system',
			);
			return true;
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to add to circle: %message', [
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	public static function removeFromCircle(UserInterface $user, UserInterface $member): bool
	{
		try {
			if ($user->id() === $member->id()) {
				Drupal::logger('mantle2')->warning(
					'User %uid attempted to remove themselves from their own circle.',
					[
						'%uid' => $user->id(),
					],
				);
				return false;
			}

			$circle = $user->get('field_circle')->value ?? '[]';
			$circle0 = $circle ? json_decode($circle, true) : [];
			if (!in_array($member->id(), $circle0, true)) {
				return false;
			}

			$circle0 = array_filter($circle0, fn($id) => $id !== $member->id());

			$user->set('field_circle', json_encode($circle0));
			$user->save();

			self::addNotification(
				$member,
				Drupal::translation()->translate('Removed from Circle'),
				Drupal::translation()->translate("You have been removed from %name's circle.", [
					'%name' => $user->getAccountName(),
				]),
				"/profile/{$user->id()}",
				'info',
				'system',
			);
			return true;
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to remove from circle: %message', [
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	#endregion

	#region User Activities

	public const MAX_ACTIVITIES = 10;

	/**
	 * @param UserInterface $user
	 * @return array<Activity>
	 */
	public static function getActivities(UserInterface $user): array
	{
		$activities = json_decode($user->get('field_activities')->value ?? '[]', true);
		return array_map(fn(array $data) => Activity::fromArray($data), $activities);
	}

	/**
	 * @param UserInterface $user
	 * @param array<Activity> $activities
	 */
	public static function setActivities(UserInterface $user, array $activities): void
	{
		try {
			if (count($activities) > self::MAX_ACTIVITIES) {
				Drupal::logger('mantle2')->warning(
					'User %uid has exceeded the maximum number of activities.',
					[
						'%uid' => $user->id(),
					],
				);
				return;
			}

			$user->set('field_activities', json_encode($activities));
			$user->save();
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to set activities: %message', [
				'%message' => $e->getMessage(),
			]);
		}
	}

	public static function hasActivity(UserInterface $user, string $id): bool
	{
		$activities = self::getActivities($user);
		return in_array($id, array_map(fn($a) => $a->getId(), $activities), true);
	}

	public static function addActivity(UserInterface $user, Activity $activity): void
	{
		$activities = self::getActivities($user);
		$activities[] = $activity;

		if (count($activities) + 1 > self::MAX_ACTIVITIES) {
			Drupal::logger('mantle2')->warning(
				'User %uid has exceeded the maximum number of activities.',
				[
					'%uid' => $user->id(),
				],
			);
			return;
		}

		self::setActivities($user, $activities);
	}

	public static function removeActivity(UserInterface $user, Activity $activity): void
	{
		$activities = self::getActivities($user);
		$activities = array_filter($activities, fn($a) => $a !== $activity);
		self::setActivities($user, $activities);
	}

	private static function serializeForCloud(Activity $activity): array
	{
		return [
			'type' => 'com.earthapp.activity.Activity',
			'id' => $activity->getId(),
			'name' => $activity->getName(),
			'description' => $activity->getDescription(),
			'aliases' => $activity->getAliases(),
			'activity_types' => array_map(
				fn($t) => $t instanceof ActivityType ? $t->name : $t,
				$activity->getTypes(),
			),
		];
	}

	/**
	 * @return array<Activity>
	 */
	public static function recommendActivities(UserInterface $user, int $poolLimit = 25): array
	{
		try {
			$userActivities = self::getActivities($user);

			$connection = Drupal::database();
			$query = $connection
				->select('node_field_data', 'n')
				->fields('n', ['nid'])
				->condition('status', 1)
				->condition('type', 'activity')
				->orderRandom()
				->range(0, $poolLimit);
			$nids = $query->execute()->fetchCol();

			$activitiesPool = array_map(fn($nid) => ActivityHelper::getActivityByNid($nid), $nids);

			if (empty($userActivities)) {
				return array_slice($activitiesPool, 0, 3);
			}

			$cacheKey = 'cloud:recommend:' . $user->id();
			$recommendations = RedisHelper::cache(
				$cacheKey,
				function () use ($activitiesPool, $userActivities) {
					return CloudHelper::sendRequest('/v1/users/recommend_activities', 'POST', [
						'all' => array_map(
							fn(Activity $activity) => self::serializeForCloud($activity),
							$activitiesPool,
						),
						'user' => array_map(
							fn(Activity $activity) => self::serializeForCloud($activity),
							$userActivities,
						),
					]);
				},
				300,
			);

			return array_map(function ($a) {
				$id = $a['id'] ?? null;
				return $id ? ActivityHelper::getActivity($id) : null;
			}, $recommendations);
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to recommend activities: %message', [
				'%message' => $e->getMessage(),
			]);
		}

		return [];
	}

	#endregion

	#region User Token Authentication

	/**
	 * Lifetime for API bearer tokens (seconds).
	 */
	private const TOKEN_TTL = 2592000; // 30 days
	private const MAX_SESSIONS = 5;

	/**
	 * Issue a persistent API bearer token for a user.
	 */
	public static function issueToken(UserInterface $user, ?int $ttlSeconds = null): string
	{
		$ttl = $ttlSeconds ?? self::TOKEN_TTL;
		$uid = (int) $user->id();
		$token = bin2hex(random_bytes(32));
		$now = time();
		$tokenStore = Drupal::service('keyvalue')->get('mantle2_tokens');
		$indexStore = Drupal::service('keyvalue')->get('mantle2_tokens_by_user');

		// Prune expired/old tokens so that after adding, the user has <= 5 tokens.
		self::pruneUserTokens($uid, self::MAX_SESSIONS - 1);

		$tokenStore->set($token, [
			'uid' => $uid,
			'created' => $now,
			'exp' => $now + $ttl,
		]);

		$tokens = $indexStore->get((string) $uid) ?? [];
		if (!in_array($token, $tokens, true)) {
			$tokens[] = $token;
			$indexStore->set((string) $uid, $tokens);
		}

		// ensure max 5 even under race conditions.
		self::pruneUserTokens($uid, self::MAX_SESSIONS);

		return $token;
	}

	public static function getUserByToken(string $token): ?UserInterface
	{
		if ($token === '') {
			return null;
		}

		// admin key points to root user
		$expectedKey = CloudHelper::getAdminKey();
		if ($expectedKey && hash_equals($expectedKey, $token)) {
			return UsersHelper::cloud();
		}

		$store = Drupal::service('keyvalue')->get('mantle2_tokens');
		$indexStore = Drupal::service('keyvalue')->get('mantle2_tokens_by_user');
		$data = $store->get($token);
		if (!$data || !is_array($data)) {
			return null;
		}
		$exp = (int) ($data['exp'] ?? 0);
		if ($exp < time()) {
			// Expired: cleanup and reject.
			$store->delete($token);
			$uid = (int) ($data['uid'] ?? 0);
			if ($uid > 0) {
				$tokens = $indexStore->get((string) $uid) ?? [];
				$tokens = array_values(array_filter($tokens, fn($t) => $t !== $token));
				$indexStore->set((string) $uid, $tokens);
			}
			return null;
		}
		// Sliding expiration: extend when half-life passed.
		if ($exp - time() < self::TOKEN_TTL / 2) {
			$data['exp'] = time() + self::TOKEN_TTL;
			$store->set($token, $data);
		}
		$uid = (int) ($data['uid'] ?? 0);
		if ($uid <= 0) {
			return null;
		}

		// Ensure index consistency.
		$tokens = $indexStore->get((string) $uid) ?? [];
		if (!in_array($token, $tokens, true)) {
			$tokens[] = $token;
			$indexStore->set((string) $uid, $tokens);
		}

		return User::load($uid);
	}

	/**
	 * Revoke a bearer token.
	 */
	public static function revokeToken(string $token): void
	{
		if ($token === '') {
			return;
		}
		$tokenStore = Drupal::service('keyvalue')->get('mantle2_tokens');
		$indexStore = Drupal::service('keyvalue')->get('mantle2_tokens_by_user');
		$data = $tokenStore->get($token);
		$tokenStore->delete($token);
		$uid = is_array($data) ? (int) ($data['uid'] ?? 0) : 0;
		if ($uid > 0) {
			$tokens = $indexStore->get((string) $uid) ?? [];
			$tokens = array_values(array_filter($tokens, fn($t) => $t !== $token));
			$indexStore->set((string) $uid, $tokens);
		}
	}

	/**
	 * Revoke all API bearer tokens for a user.
	 */
	public static function revokeAllTokensForUser(UserInterface|int $user): int
	{
		$uid = $user instanceof UserInterface ? (int) $user->id() : (int) $user;
		if ($uid <= 0) {
			return 0;
		}

		$tokenStore = Drupal::service('keyvalue')->get('mantle2_tokens');
		$indexStore = Drupal::service('keyvalue')->get('mantle2_tokens_by_user');
		$tokens = $indexStore->get((string) $uid) ?? [];
		if (!is_array($tokens)) {
			$tokens = [];
		}

		$revoked = 0;
		foreach ($tokens as $token) {
			if (!is_string($token) || $token === '') {
				continue;
			}

			$tokenStore->delete($token);
			$revoked++;
		}

		$indexStore->set((string) $uid, []);

		return $revoked;
	}

	/**
	 * Clear cache entries impacted by user authentication/visibility state.
	 */
	public static function clearCachedUserResponses(int $uid): void
	{
		if ($uid <= 0) {
			return;
		}

		RedisHelper::delete([
			'user:profile:' . $uid . ':*',
			'user:photo:' . $uid . ':*',
			'user:activities:' . $uid . '*',
			'user:friends:' . $uid . ':*',
			'user:circle:' . $uid . ':*',
			'user:notifications:' . $uid . ':*',
			'user:prompts:' . $uid . ':*',
			'user:articles:' . $uid . ':*',
			'user:events:' . $uid . ':*',
			'user:events_attending:' . $uid . ':*',
			'user:event_images:' . $uid . ':*',
			'user:badges:' . $uid . '*',
			'user:points:' . $uid . '*',
			'user:*:req:' . $uid . '*',
			'users:list:*',
			'prompts:list:req:' . $uid . ':*',
			'articles:list:req:' . $uid . ':*',
			'events:list:req:' . $uid . ':*',
			'event:*:req:' . $uid,
			'article:*:req:' . $uid,
			'prompt:*:req:' . $uid . '*',
			'prompt:responses:*:req:' . $uid . ':*',
		]);
	}

	/**
	 * Prune tokens for a user to ensure at most $keep remain (by created ASC),
	 * and remove any expired or orphaned tokens.
	 */
	private static function pruneUserTokens(int $uid, int $keep): void
	{
		$tokenStore = Drupal::service('keyvalue')->get('mantle2_tokens');
		$indexStore = Drupal::service('keyvalue')->get('mantle2_tokens_by_user');
		$tokens = $indexStore->get((string) $uid) ?? [];
		if (!is_array($tokens)) {
			$tokens = [];
		}

		// Load token data and filter invalid/expired/mismatched.
		$valid = [];
		foreach ($tokens as $tok) {
			$data = $tokenStore->get($tok);
			if (!is_array($data)) {
				// Orphaned index entry.
				continue;
			}
			if ((int) ($data['uid'] ?? 0) !== $uid) {
				// Mismatched uid; drop.
				continue;
			}
			if ((int) ($data['exp'] ?? 0) < time()) {
				// Expired; drop and cleanup store.
				$tokenStore->delete($tok);
				continue;
			}
			$valid[$tok] = (int) ($data['created'] ?? 0);
		}

		// Sort by created ASC (oldest first), then trim to $keep.
		asort($valid, SORT_NUMERIC);
		$toKeep = array_keys($valid);
		if (count($toKeep) > $keep) {
			$excess = array_slice($toKeep, 0, count($toKeep) - $keep);
			foreach ($excess as $tok) {
				$tokenStore->delete($tok);
				unset($valid[$tok]);
			}
			$toKeep = array_keys($valid);
		}

		$indexStore->set((string) $uid, array_values($toKeep));
	}

	#endregion

	#region User Notifications

	private const MAX_NOTIFICATIONS = 50;

	/**
	 * @param UserInterface $user
	 * @return array<Notification>
	 */
	public static function getNotifications(UserInterface $user): array
	{
		$notifications = json_decode($user->get('field_notifications')->value ?? '[]', true);

		$notifications0 = array_map(
			fn(array $data) => new Notification(
				$data['id'],
				$data['user_id'],
				$data['title'],
				$data['message'],
				$data['created_at'] ?? time(),
				$data['link'] ?? null,
				$data['type'] ?? 'info',
				$data['source'] ?? 'system',
				$data['read'] ?? false,
			),
			$notifications,
		);
		usort($notifications0, fn($a, $b) => $b->getTimestamp() <=> $a->getTimestamp());

		return $notifications0;
	}

	public static function getNotification(UserInterface $user, string $id): ?Notification
	{
		$notifications = self::getNotifications($user);
		foreach ($notifications as $notification) {
			if ($notification->getId() === $id) {
				return $notification;
			}
		}
		return null;
	}

	/**
	 * @param UserInterface $user
	 * @param array<Notification> $notifications
	 */
	private static function setNotifications(UserInterface $user, array $notifications): void
	{
		if (count($notifications) > self::MAX_NOTIFICATIONS) {
			Drupal::logger('mantle2')->warning(
				'Internal Warning: User %uid has exceeded the maximum number of notifications.',
				[
					'%uid' => $user->id(),
				],
			);
			return;
		}

		$serialized = array_map(
			fn(Notification $notification) => $notification->jsonSerialize(),
			$notifications,
		);
		$user->set('field_notifications', json_encode($serialized));
		$user->save();
	}

	public static function addNotification(
		UserInterface $user,
		string $title,
		string $message,
		?string $link = null,
		string $type = 'info',
		string $source = 'system',
	): ?Notification {
		// ignore notifications for root user
		if ($user->id() === self::cloud()->id()) {
			return null;
		}

		$id = bin2hex(random_bytes(16));
		$notification = new Notification(
			$id,
			$user->id(),
			$title,
			$message,
			time(),
			$link,
			$type,
			$source,
			false,
		);

		$notifications = self::getNotifications($user);
		$notifications[] = $notification;

		if (count($notifications) > self::MAX_NOTIFICATIONS) {
			$notifications = array_slice($notifications, -self::MAX_NOTIFICATIONS); // remove oldest notification
		}

		// notify via websocket
		CloudHelper::sendWebsocketMessage('users', $user->id(), [
			'type' => 'notification',
			'data' => $notification->jsonSerialize(),
		]);

		self::setNotifications($user, $notifications);
		return $notification;
	}

	public static function markNotificationAsRead(
		UserInterface $user,
		Notification $notification,
	): bool {
		if ($notification === null || $notification->isRead()) {
			return false;
		}

		$notifications = self::getNotifications($user);
		foreach ($notifications as $n) {
			if ($n->getId() === $notification->getId()) {
				$n->setRead();
				break;
			}
		}

		self::setNotifications($user, $notifications);
		return true;
	}

	public static function markNotificationAsUnread(
		UserInterface $user,
		Notification $notification,
	): bool {
		if ($notification === null || !$notification->isRead()) {
			return false;
		}

		$notifications = self::getNotifications($user);
		foreach ($notifications as $n) {
			if ($n->getId() === $notification->getId()) {
				$n->setRead(false);
				break;
			}
		}

		self::setNotifications($user, $notifications);
		return true;
	}

	public static function markAllNotificationsAsRead(UserInterface $user): void
	{
		$notifications = self::getNotifications($user);
		foreach ($notifications as $notification) {
			$notification->setRead();
		}
		self::setNotifications($user, $notifications);
	}

	public static function markAllNotificationsAsUnread(UserInterface $user): void
	{
		$notifications = self::getNotifications($user);
		foreach ($notifications as $notification) {
			$notification->setRead(false);
		}
		self::setNotifications($user, $notifications);
	}

	public static function removeNotification(UserInterface $user, Notification $notification): bool
	{
		$notifications = self::getNotifications($user);
		$notifications = array_filter(
			$notifications,
			fn($n) => $n->getId() !== $notification->getId(),
		);
		self::setNotifications($user, $notifications);
		return true;
	}

	public static function updateNotification(UserInterface $user, string $id, array $updates): bool
	{
		$notification = self::getNotification($user, $id);
		if ($notification === null) {
			return false;
		}

		if (isset($updates['message'])) {
			$notification->setMessage((string) $updates['message']);
		}
		if (array_key_exists('link', $updates)) {
			$notification->setLink($updates['link'] !== null ? (string) $updates['link'] : null);
		}

		if (isset($updates['read'])) {
			$notification->setRead((bool) $updates['read']);
		}

		self::setNotifications($user, self::getNotifications($user));
		return true;
	}

	public static function clearNotifications(UserInterface $user): void
	{
		self::setNotifications($user, []);
	}

	#endregion

	#region User Emails

	public static function sendEmailVerification(UserInterface $user): JsonResponse
	{
		$code = str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);

		$codeKey = 'email_verification_' . $user->id();
		$codeData = [
			'code' => $code,
			'timestamp' => time(),
			'user_id' => $user->id(),
		];

		// Store in Redis with 15 minutes TTL (900 seconds)
		if (!RedisHelper::set($codeKey, $codeData, 900)) {
			Drupal::logger('mantle2')->error('Failed to store email verification code in Redis');
			return GeneralHelper::internalError('Failed to generate verification code');
		}

		$userEmail = $user->getEmail();
		if (!$userEmail) {
			return GeneralHelper::badRequest('User has no email address');
		}

		self::sendEmail($user, 'email_verification', [
			'verification_code' => $code,
			'user' => $user,
		]);

		return new JsonResponse(
			[
				'message' => 'Verification email sent',
				'email' => $userEmail,
			],
			Response::HTTP_OK,
		);
	}

	public static function sendEmailCampaign(
		string $id,
		UserInterface $user,
		array $params = [],
	): bool {
		$campaign = CampaignHelper::getCampaign($id);
		if (!$campaign) {
			Drupal::logger('mantle2')->error('Email campaign %id not found', ['%id' => $id]);
			return false;
		}

		$payload = array_merge(
			[
				'user' => $user,
			],
			$params,
		);

		self::sendEmail($user, 'campaign:' . $id, $payload, $campaign['unsubscribable'] ?? true);

		return true;
	}

	public static function sendEmail(
		UserInterface $user,
		string $key,
		array $params,
		bool $unsubscribable = true,
	): void {
		$email = $user->getEmail();
		if (!$email) {
			Drupal::logger('mantle2')->warning(
				'Cannot send email notification %key to user %uid: no email address.',
				[
					'%key' => $key,
					'%uid' => $user->id(),
				],
			);
			return;
		}

		if ($unsubscribable) {
			if (!self::isSubscribed($user)) {
				Drupal::logger('mantle2')->info(
					'Not sending email %key to %email: user has unsubscribed.',
					[
						'%key' => $key,
						'%email' => $email,
					],
				);
				return;
			}

			// Add unsubscribe URLs to params
			// Frontend URL for visible link in email body
			$params['unsubscribe_url'] = self::getUnsubscribeUrl();

			// API URL for List-Unsubscribe header (one-click)
			$params['unsubscribe_api_url'] = self::getUnsubscribeApiUrl($user);
		}

		if ($user->id() === self::cloud()->id()) {
			// Do not send emails to root user
			return;
		}

		$params['user'] = $user; // Pass user object for mail headers

		/** @var \Drupal\Core\Mail\MailManagerInterface $mailManager */
		$mailManager = Drupal::service('plugin.manager.mail');
		$module = 'mantle2';
		$to = $email;
		$langcode = $user->getPreferredLangcode();
		$result = $mailManager->mail($module, $key, $to, $langcode, $params);
		if (!$result['result']) {
			Drupal::logger('mantle2')->error('Failed to send email %key to %email', [
				'%key' => $key,
				'%email' => $email,
			]);
		}
	}

	/**
	 * Initiate email change process by sending verification to new email
	 * and notification to old email.
	 */
	public static function sendEmailChangeVerification(
		UserInterface $user,
		string $newEmail,
	): JsonResponse {
		// Validate the new email format
		if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
			return GeneralHelper::badRequest('Invalid email format');
		}

		$currentEmail = $user->getEmail();
		if (!$currentEmail) {
			return GeneralHelper::badRequest('User has no current email address');
		}

		if ($currentEmail === $newEmail) {
			return GeneralHelper::badRequest('New email must be different from current email');
		}

		// Check if new email is already in use by another user
		$existingUser = self::findByEmail($newEmail);
		if ($existingUser && $existingUser->id() !== $user->id()) {
			return GeneralHelper::conflict('Email address is already in use');
		}

		// Check rate limit (5 minutes = 300 seconds)
		$rateLimitKey = 'email_change_rate_limit_' . $user->id();

		$lastSentData = RedisHelper::get($rateLimitKey);
		if ($lastSentData) {
			$timeSinceLastSent = time() - $lastSentData['timestamp'];
			if ($timeSinceLastSent < 300) {
				$remainingTime = 300 - $timeSinceLastSent;
				$response = new JsonResponse(
					[
						'error' => 'Rate limit exceeded',
						'message' =>
							'Please wait ' .
							ceil($remainingTime / 60) .
							' minutes before requesting another email change',
						'retryAfter' => $remainingTime,
					],
					Response::HTTP_TOO_MANY_REQUESTS,
				);
				$response->headers->set('Retry-After', (string) $remainingTime);
				return $response;
			}
		}

		// Store current timestamp for rate limiting (300 seconds TTL)
		RedisHelper::set(
			$rateLimitKey,
			[
				'timestamp' => time(),
				'user_id' => $user->id(),
			],
			300,
		);

		$code = str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);

		$codeKey = 'email_change_' . $user->id();
		$codeData = [
			'code' => $code,
			'timestamp' => time(),
			'user_id' => $user->id(),
			'new_email' => $newEmail,
			'old_email' => $currentEmail,
		];

		// Store in Redis with 15 minutes TTL (900 seconds)
		if (!RedisHelper::set($codeKey, $codeData, 900)) {
			Drupal::logger('mantle2')->error(
				'Failed to store email change verification code in Redis',
			);
			return GeneralHelper::internalError('Failed to generate verification code');
		}

		// Send verification email to new email address
		self::sendEmail($user, 'email_change_verification', [
			'verification_code' => $code,
			'user' => $user,
			'new_email' => $newEmail,
			'old_email' => $currentEmail,
		]);

		// Send notification to old email
		self::sendEmailChangeNotification($user, $newEmail, $currentEmail);

		return new JsonResponse(
			[
				'message' => 'Email change verification sent',
				'new_email' => $newEmail,
			],
			Response::HTTP_OK,
		);
	}

	/**
	 * Send notification to current email about email change request.
	 */
	public static function sendEmailChangeNotification(
		UserInterface $user,
		string $newEmail,
		string $currentEmail,
	): void {
		self::sendEmail($user, 'email_change_notification', [
			'user' => $user,
			'new_email' => $newEmail,
			'old_email' => $currentEmail,
		]);
	}

	/**
	 * Verify email change and update user's email if valid.
	 */
	public static function verifyEmailChange(UserInterface $user, string $code): JsonResponse
	{
		// Validate code format (8 digits)
		if (!preg_match('/^\d{8}$/', $code)) {
			return GeneralHelper::badRequest('Invalid verification code format');
		}

		$codeKey = 'email_change_' . $user->id();
		$storedData = RedisHelper::get($codeKey);

		if (!$storedData) {
			return GeneralHelper::badRequest(
				'No email change verification code found or code has expired',
			);
		}

		// Verify the code matches
		if ($storedData['code'] !== $code) {
			return GeneralHelper::badRequest('Invalid verification code');
		}

		$newEmail = $storedData['new_email'];
		$oldEmail = $storedData['old_email'];

		// Double-check that the new email is still available
		$existingUser = self::findByEmail($newEmail);
		if ($existingUser && $existingUser->id() !== $user->id()) {
			return GeneralHelper::conflict('Email address is no longer available');
		}

		// Update the user's email
		try {
			$user->setEmail($newEmail);
			// Reset email verification status since it's a new email
			$user->set('field_email_verified', false);
			$user->save();
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error('Failed to update user email: %message', [
				'%message' => $e->getMessage(),
			]);
			return GeneralHelper::internalError('Failed to update email');
		}

		// Clean up used verification code
		RedisHelper::delete($codeKey);

		// Send confirmation email to new address
		self::sendEmail($user, 'email_change_confirmed', [
			'user' => $user,
			'new_email' => $newEmail,
			'old_email' => $oldEmail,
		]);

		// Add notification to user about successful email change
		self::addNotification(
			$user,
			'Email Changed',
			'Your email address has been successfully changed to ' .
				$newEmail .
				'. If you did not perform this action, please contact support immediately.',
			null,
			'success',
			'system',
		);

		return new JsonResponse(
			[
				'message' => 'Email changed successfully',
				'new_email' => $newEmail,
				'email_verified' => false,
			],
			Response::HTTP_OK,
		);
	}

	public static function generateUnsubscribeToken(UserInterface $user): string
	{
		// Generate a cryptographically secure random token
		$token = bin2hex(random_bytes(32));

		$tokenKey = 'unsubscribe_token_' . $token;
		$tokenData = [
			'user_id' => $user->id(),
			'email' => $user->getEmail(),
			'timestamp' => time(),
		];

		// Store in Redis with 30 days TTL (2592000 seconds)
		// This allows unsubscribe links to work for a reasonable period
		RedisHelper::set($tokenKey, $tokenData, 2592000);

		return $token;
	}

	public static function validateUnsubscribeToken(string $token): ?UserInterface
	{
		if (!$token || !ctype_xdigit($token) || strlen($token) !== 64) {
			return null;
		}

		$tokenKey = 'unsubscribe_token_' . $token;
		$tokenData = RedisHelper::get($tokenKey);

		if (!$tokenData || !isset($tokenData['user_id'])) {
			return null;
		}

		$user = User::load($tokenData['user_id']);
		if (!$user) {
			return null;
		}

		// Verify email hasn't changed (security check)
		if ($user->getEmail() !== $tokenData['email']) {
			return null;
		}

		return $user;
	}

	public static function revokeUnsubscribeToken(string $token): void
	{
		$tokenKey = 'unsubscribe_token_' . $token;
		RedisHelper::delete($tokenKey);
	}

	public static function getUnsubscribeApiUrl(UserInterface $user): string
	{
		$token = self::generateUnsubscribeToken($user);
		return 'https://api.earth-app.com/v2/users/unsubscribe?token=' . $token;
	}

	public static function getUnsubscribeUrl(): string
	{
		return 'https://app.earth-app.com/api/unsubscribe';
	}

	#endregion

	#region User Passwords

	public static function hasPassword(UserInterface $user): bool
	{
		// In Drupal, if a user has no password set, the password hash field is empty
		$passField = $user->get('pass')->value;
		return !empty($passField);
	}

	public static function changePassword(UserInterface $user, string $newPassword): bool
	{
		try {
			$user->setPassword($newPassword);
			$user->save();

			// Send notifications about password change
			self::addNotification(
				$user,
				'Password Changed',
				'Your account password has been successfully changed. If you did not perform this action, please contact support immediately.',
				null,
				'success',
				'system',
			);

			self::sendEmail($user, 'new_password', [
				'user' => $user,
			]);

			return true;
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error('Failed to change user password: %message', [
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	public const RESET_TOKEN_TTL = 3600; // 1 hour

	public static function generateResetPasswordToken(UserInterface $user): string
	{
		$token = bin2hex(random_bytes(32));
		$tokenKey = 'password_reset_' . $user->id();
		$tokenData = [
			'token' => $token,
			'timestamp' => time(),
			'user_id' => $user->id(),
		];

		// Store in Redis with 1 hour TTL
		RedisHelper::set($tokenKey, $tokenData, self::RESET_TOKEN_TTL);

		return $token;
	}

	public static function validateResetPasswordToken(UserInterface $user, string $token): bool
	{
		$tokenKey = 'password_reset_' . $user->id();
		$storedData = RedisHelper::get($tokenKey);
		if (!$storedData) {
			return false;
		}

		return hash_equals($storedData['token'], $token);
	}

	public static function sendPasswordResetEmail(UserInterface $user): void
	{
		$userEmail = $user->getEmail();
		if (!$userEmail) {
			Drupal::logger('mantle2')->warning(
				'Attempted to send password reset email to user without email address: %uid',
				[
					'%uid' => $user->id(),
				],
			);
			return;
		}

		$token = self::generateResetPasswordToken($user);
		$resetLink =
			'https://app.earth-app.com/reset-password?uid=' . $user->id() . '&token=' . $token;

		self::sendEmail($user, 'password_reset', [
			'user' => $user,
			'reset_link' => $resetLink,
		]);
	}

	public static function validatePassword(UserInterface $user, string $password): bool
	{
		$auth = Drupal::service('user.auth');
		return $auth->authenticate($user->getAccountName(), $password) === $user->id();
	}

	#endregion

	#region User Content

	public static function getUserPrompts(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
		string $sort = 'desc',
	): array {
		try {
			$storage = Drupal::entityTypeManager()->getStorage('node');
			$query = $storage
				->getQuery()
				->accessCheck(false)
				->condition('type', 'prompt')
				->condition('field_owner_id', $user->id());

			if (!empty($search)) {
				$query->condition('field_prompt', $search, 'CONTAINS');
			}

			$countQuery = clone $query;
			$count = (int) $countQuery->count()->execute();

			// Handle random sorting differently
			if ($sort === 'rand') {
				$query->range(0, $limit * ($page + 1));
				$nids = $query->execute();
				$nids = array_values($nids);
				shuffle($nids);
				$nids = array_slice($nids, $page * $limit, $limit);
			} else {
				// Add sorting
				$sortDirection = $sort === 'desc' ? 'DESC' : 'ASC';
				$query->sort('created', $sortDirection);
				$query->range($page * $limit, $limit);
				$nids = $query->execute();
			}

			if (empty($nids)) {
				return [
					'prompts' => [],
					'total' => $count,
				];
			}

			$nodes = $storage->loadMultiple($nids);

			return [
				'prompts' => array_values(
					array_filter(
						array_map(function (Node $node) use ($user) {
							if (!$node) {
								return null;
							}

							$nid = $node->id();
							$obj = PromptsHelper::nodeToPrompt($node);

							return array_merge($obj->jsonSerialize(), [
								'id' => GeneralHelper::formatId($nid),
								'owner' => UsersHelper::serializeUser($obj->getOwner(), $user),
								'responses_count' => PromptsHelper::getCommentsCount($node),
								'created_at' => GeneralHelper::dateToIso($node->getCreatedTime()),
								'updated_at' => GeneralHelper::dateToIso($node->getChangedTime()),
							]);
						}, $nodes),
					),
				),
				'total' => $count,
			];
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			Drupal::logger('mantle2')->error('Failed to retrieve user prompts: %message', [
				'%message' => $e->getMessage(),
			]);
			return [
				'prompts' => [],
				'total' => 0,
			];
		}
	}

	public static function getUserArticles(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
		string $sort = 'desc',
	): array {
		try {
			$storage = Drupal::entityTypeManager()->getStorage('node');
			$query = $storage
				->getQuery()
				->accessCheck(false)
				->condition('type', 'article')
				->condition('field_author_id', $user->id());

			if (!empty($search)) {
				$query->condition('field_article_title', $search, 'CONTAINS');
			}

			$countQuery = clone $query;
			$count = (int) $countQuery->count()->execute();

			// add sorting
			$sortDirection = $sort === 'desc' ? 'DESC' : 'ASC';
			$query->sort('created', $sortDirection);

			$query->range($page * $limit, $limit);
			$nids = $query->execute();

			// handle random sorting differently
			if ($sort === 'rand') {
				$nids = array_values($nids);
				shuffle($nids);
			}

			if (empty($nids)) {
				return [
					'articles' => [],
					'total' => $count,
				];
			}

			$nodes = $storage->loadMultiple($nids);

			return [
				'articles' => array_values(
					array_filter(
						array_map(function (Node $node) use ($user) {
							if (!$node) {
								return null;
							}

							$obj = ArticlesHelper::nodeToArticle($node);
							return array_merge($obj->jsonSerialize(), [
								'author' => UsersHelper::serializeUser($obj->getAuthor(), $user),
								'created_at' => GeneralHelper::dateToIso($node->getCreatedTime()),
								'updated_at' => GeneralHelper::dateToIso($node->getChangedTime()),
							]);
						}, $nodes),
					),
				),
				'total' => $count,
			];
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			Drupal::logger('mantle2')->error('Failed to retrieve user articles: %message', [
				'%message' => $e->getMessage(),
			]);
			return [
				'articles' => [],
				'total' => 0,
			];
		}
	}

	public static function getUserHostedEvents(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
		string $sort = 'desc',
	) {
		try {
			$storage = Drupal::entityTypeManager()->getStorage('node');
			$query = $storage
				->getQuery()
				->condition('type', 'event')
				->condition('field_host_id', $user->id())
				->accessCheck(false);

			if (!empty($search)) {
				$query->condition('field_event_name', $search, 'CONTAINS');
			}

			$countQuery = clone $query;
			$count = (int) $countQuery->count()->execute();

			// Handle random sorting differently
			if ($sort === 'rand') {
				$query->range(0, $limit * ($page + 1));
				$nids = $query->execute();
				$nids = array_values($nids);
				shuffle($nids);
				$nids = array_slice($nids, $page * $limit, $limit);
			} else {
				// Add sorting
				$sortDirection = $sort === 'desc' ? 'DESC' : 'ASC';
				$query->sort('created', $sortDirection);
				$query->range($page * $limit, $limit);
				$nids = $query->execute();
			}

			if (empty($nids)) {
				return [
					'nodes' => [],
					'total' => $count,
				];
			}

			$nodes = $storage->loadMultiple($nids);

			return [
				'nodes' => array_values($nodes),
				'total' => $count,
			];
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			Drupal::logger('mantle2')->error('Failed to retrieve user events: %message', [
				'%message' => $e->getMessage(),
			]);
			return [
				'nodes' => [],
				'total' => 0,
			];
		}
	}

	/**
	 * Get events the user is hosting OR attending.
	 */
	public static function getUserEvents(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
		string $sort = 'desc',
	) {
		try {
			$storage = Drupal::entityTypeManager()->getStorage('node');

			// Get events where user is host
			$hostQuery = $storage
				->getQuery()
				->condition('type', 'event')
				->condition('field_host_id', $user->id())
				->accessCheck(false);

			if (!empty($search)) {
				$hostQuery->condition('field_event_name', $search, 'CONTAINS');
			}

			$hostNids = $hostQuery->execute();

			// Get events where user is attendee
			$attendeeQuery = $storage
				->getQuery()
				->condition('type', 'event')
				->condition('field_event_attendees', $user->id(), 'IN')
				->accessCheck(false);

			if (!empty($search)) {
				$attendeeQuery->condition('field_event_name', $search, 'CONTAINS');
			}

			$attendeeNids = $attendeeQuery->execute();

			// Merge and deduplicate event IDs
			$allNids = array_unique(
				array_merge(array_values($hostNids), array_values($attendeeNids)),
			);
			$count = count($allNids);

			if (empty($allNids)) {
				return [
					'nodes' => [],
					'total' => 0,
				];
			}

			// Load all nodes to sort them properly
			$nodes = $storage->loadMultiple($allNids);

			// Handle sorting
			if ($sort === 'rand') {
				shuffle($nodes);
			} else {
				$sortDirection = $sort === 'desc' ? -1 : 1;
				usort($nodes, function ($a, $b) use ($sortDirection) {
					if (!$a instanceof Node || !$b instanceof Node) {
						return 0;
					}
					return ($a->getCreatedTime() - $b->getCreatedTime()) * $sortDirection;
				});
			}

			// Apply pagination
			$nodes = array_slice($nodes, $page * $limit, $limit);

			return [
				'nodes' => array_values($nodes),
				'total' => $count,
			];
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			Drupal::logger('mantle2')->error('Failed to retrieve user events: %message', [
				'%message' => $e->getMessage(),
			]);
			return [
				'nodes' => [],
				'total' => 0,
			];
		}
	}

	public static function getUserEventsCount(UserInterface $user): int
	{
		try {
			$storage = Drupal::entityTypeManager()->getStorage('node');
			$query = $storage
				->getQuery()
				->accessCheck(false)
				->condition('type', 'event')
				->condition('field_host_id', $user->id());

			return (int) $query->count()->execute();
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			Drupal::logger('mantle2')->error('Failed to retrieve user events count: %message', [
				'%message' => $e->getMessage(),
			]);
			return 0;
		}
	}

	public static function getMaxEventAttendees(UserInterface $user): int
	{
		$type = self::getAccountType($user)->name;
		return match ($type) {
			'ADMINISTRATOR', 'ORGANIZER' => PHP_INT_MAX,
			'PRO', 'WRITER' => 5000,
			default => 25,
		};
	}

	public static function getMaxEventsCount(UserInterface $user): int
	{
		$type = self::getAccountType($user)->name;
		return match ($type) {
			'ADMINISTRATOR' => PHP_INT_MAX,
			'PRO', 'WRITER' => 50,
			'ORGANIZER' => 200,
			default => 20,
		};
	}

	#endregion

	#region User Badges

	public static function getAllBadges(): array
	{
		return RedisHelper::cache(
			'cloud:badges:all',
			function () {
				return CloudHelper::sendRequest('/v1/users/badges', 'GET');
			},
			3600,
		);
	}

	public static function getBadges(UserInterface $user): array
	{
		$cacheKey = 'cloud:badges:' . GeneralHelper::formatId($user->id());
		return RedisHelper::cache(
			$cacheKey,
			function () use ($user) {
				return CloudHelper::sendRequest(
					'/v1/users/badges/' . GeneralHelper::formatId($user->id()),
					'GET',
				);
			},
			600,
		);
	}

	public static function getBadge(UserInterface $user, string $badgeId): ?array
	{
		$cacheKey = 'cloud:badge:' . GeneralHelper::formatId($user->id()) . ':' . $badgeId;
		return RedisHelper::cache(
			$cacheKey,
			function () use ($user, $badgeId) {
				return CloudHelper::sendRequest(
					'/v1/users/badges/' . GeneralHelper::formatId($user->id()) . '/' . $badgeId,
					'GET',
				);
			},
			3600,
		);
	}

	public static function trackBadgeProgress(UserInterface $user, string $trackerId, $value): void
	{
		$trackerPath = '/v1/users/badges/' . GeneralHelper::formatId($user->id()) . '/track';
		CloudHelper::sendRequest($trackerPath, 'POST', [
			'tracker_id' => $trackerId,
			'value' => $value,
		]);
	}

	public static function grantBadge(UserInterface $user, string $badgeId): void
	{
		try {
			$grantPath =
				'/v1/users/badges/' .
				GeneralHelper::formatId($user->id()) .
				'/' .
				$badgeId .
				'/grant';
			CloudHelper::sendRequest($grantPath, 'POST');

			// invalidate caches
			RedisHelper::delete('cloud:badges:' . GeneralHelper::formatId($user->id()));
			RedisHelper::delete(
				'cloud:badge:' . GeneralHelper::formatId($user->id()) . ':' . $badgeId,
			);
		} catch (Exception $e) {
			// silently ignore already granted (409)
			if ($e->getCode() !== 409) {
				Drupal::logger('mantle2')->error(
					'Failed to grant badge %badgeId to user %uid: %message',
					[
						'%badgeId' => $badgeId,
						'%uid' => $user->id(),
						'%message' => $e->getMessage(),
					],
				);
			}
		}
	}

	public static function checkBadges(): void
	{
		$users = User::loadMultiple();
		foreach ($users as $user) {
			if ($user->id() === self::cloud()->id()) {
				continue; // skip root user
			}

			// grant 'verified' badge if email is verified
			if ($user->get('field_email_verified')->value) {
				$lastCheckKey = 'badge_check_verified_' . GeneralHelper::formatId($user->id());

				$lastCheck = RedisHelper::get($lastCheckKey);
				if (!$lastCheck) {
					if (!self::getBadge($user, 'verified')) {
						self::grantBadge($user, 'verified');
					}

					RedisHelper::set($lastCheckKey, ['value' => true], 3600);
				}
			}
		}
	}

	#endregion
}
