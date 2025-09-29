<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\mantle2\Controller\Schema\Mantle2Schemas;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Notification;
use Drupal\mantle2\Custom\Visibility;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UsersHelper
{
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

	public static function getVisibility(UserInterface $user): Visibility
	{
		$visibility = $user->get('field_visibility')->value ?? 1;
		return Visibility::cases()[$visibility] ?? Visibility::UNLISTED;
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
		$user2 = self::findByRequestSilent($request);
		if (!$user2) {
			return GeneralHelper::notFound();
		}

		// PRIVATE requires admin or as an added friend
		if (
			$visibility === Visibility::PRIVATE &&
			!UsersHelper::isAdmin($user2) &&
			!self::isAddedFriend($user2, $user)
		) {
			return GeneralHelper::notFound();
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
			if ($adminKey && $adminKey === CloudHelper::getAdminKey()) {
				return self::cloud();
			}
		}

		$sessionId = GeneralHelper::getBearerToken($request);
		if ($sessionId) {
			// First, try persistent API token lookup.
			$user = self::getUserByToken($sessionId);
			if ($user instanceof UserInterface) {
				return $user;
			}

			// Back-compat: attempt to treat bearer as a PHP session id.
			$user = self::withSessionId($sessionId, function ($session) {
				$uid = $session->get('uid');
				return $uid ? User::load($uid) : null;
			});
			if ($user instanceof UserInterface) {
				return $user;
			}

			return GeneralHelper::unauthorized('Invalid or expired session token');
		}

		$basicAuth = GeneralHelper::getBasicAuth($request);
		if ($basicAuth) {
			$username = $basicAuth['username'] ?? null;
			$password = $basicAuth['password'] ?? null;

			if (!$username) {
				return GeneralHelper::unauthorized('Username is required for basic authentication');
			}

			if (!$password) {
				return GeneralHelper::unauthorized('Password is required for basic authentication');
			}

			/** @var \Drupal\user\UserAuthInterface $userAuth */
			$userAuth = Drupal::service('user.auth');
			$uid = $userAuth->authenticate($username, $password);
			if ($uid) {
				$user = User::load($uid);
				if ($user && !$user->isBlocked()) {
					return $user;
				}
			}

			return GeneralHelper::unauthorized('Invalid username or password');
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

	/**
	 * Get user from request without error responses - returns null if not authenticated
	 */
	public static function findByRequestSilent(Request $request): ?UserInterface
	{
		if ($request->headers->has('X-Admin-Key')) {
			$adminKey = $request->headers->get('X-Admin-Key');
			if ($adminKey && $adminKey === CloudHelper::getAdminKey()) {
				return self::cloud();
			}
		}

		$sessionId = GeneralHelper::getBearerToken($request);
		if ($sessionId) {
			// Try persistent API token lookup first.
			$user = self::getUserByToken($sessionId);
			if ($user instanceof UserInterface) {
				return $user;
			}

			// Fallback to PHP session id semantics for legacy tokens.
			$user = self::withSessionId($sessionId, function ($session) {
				$uid = $session->get('uid');
				return $uid ? User::load($uid) : null;
			});
			if ($user instanceof UserInterface) {
				return $user;
			}
		}

		$basicAuth = GeneralHelper::getBasicAuth($request);
		if ($basicAuth) {
			$username = $basicAuth['username'] ?? null;
			$password = $basicAuth['password'] ?? null;

			if ($username && $password) {
				/** @var \Drupal\user\UserAuthInterface $userAuth */
				$userAuth = Drupal::service('user.auth');
				$uid = $userAuth->authenticate($username, $password);
				if ($uid) {
					$user = User::load($uid);
					if ($user && !$user->isBlocked()) {
						return $user;
					}
				}
			}
		}

		return null;
	}

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
	];

	public static function getFieldPrivacy(UserInterface $user)
	{
		$privacy = $user->get('field_privacy')->value ?? '{}';
		$privacy0 = [];
		if ($privacy) {
			$privacy0 = json_decode($privacy, true);
		}

		foreach (self::$defaultPrivacy as $key => $value) {
			if (!isset($privacy0[$key])) {
				$privacy0[$key] = $value;
			}
		}

		return $privacy0;
	}

	public static function setFieldPrivacy(UserInterface $user, array $privacy): void
	{
		$user->set('field_privacy', json_encode($privacy));
	}

	// User Fields

	public static function getFirstName(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?string {
		$privacy = self::getFieldPrivacy($user);
		$firstName = $user->get('field_first_name')->value ?? 'John';
		return self::tryVisible($firstName, $user, $requester, $privacy['name'] ?? 'PUBLIC');
	}

	public static function getLastName(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?string {
		$privacy = self::getFieldPrivacy($user);
		$lastName = $user->get('field_last_name')->value ?? 'Doe';
		return self::tryVisible($lastName, $user, $requester, $privacy['name'] ?? 'PUBLIC');
	}

	public static function getName(UserInterface $user, ?UserInterface $requester = null): ?string
	{
		$firstName = self::getFirstName($user, $requester);
		$lastName = self::getLastName($user, $requester);
		return trim("$firstName $lastName");
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

	public static function isEmailVerified(UserInterface $user): bool
	{
		return (bool) $user->get('field_email_verified')->value;
	}

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

		return [
			'id' => GeneralHelper::formatId($user->id()),
			'username' => $user->getAccountName(),
			'full_name' => self::getName($user, $requester),
			'created_at' => date('c', $user->getCreatedTime()),
			'updated_at' => date('c', $user->getChangedTime()),
			'last_login' => date('c', $user->getLastLoginTime()),
			'account' => [
				'id' => GeneralHelper::formatId($user->id()),
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
				'visibility' => self::getVisibility($user)->name,
				'field_privacy' => $privacy,
			],
			'activities' => self::getActivities($user),
			'friends' => self::tryVisible(
				json_decode($user->get('field_friends')->value ?? '[]', true),
				$user,
				$requester,
				$privacy['friends'] ?? 'MUTUAL',
			),
		];
	}

	public static function patchUser(
		UserInterface $user,
		array $data,
		?UserInterface $requester = null,
	): JsonResponse {
		if (!$data) {
			return GeneralHelper::badRequest('Invalid user or data');
		}

		if (isset($data['username'])) {
			$username = (string) $data['username'];
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
			$email = (string) $data['email'];
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
			$firstName = (string) $data['first_name'];
			$len = strlen($firstName);
			if ($len < 2 || $len > 50) {
				return GeneralHelper::badRequest('Invalid first name length');
			}

			$user->set('field_first_name', $firstName);
		}

		if (isset($data['last_name'])) {
			$lastName = (string) $data['last_name'];
			$len = strlen($lastName);
			if ($len < 2 || $len > 50) {
				return GeneralHelper::badRequest('Invalid last name length');
			}

			$user->set('field_last_name', $lastName);
		}

		if (isset($data['bio'])) {
			$bio = (string) $data['bio'];
			$len = strlen($bio);
			if ($len > 500) {
				return GeneralHelper::badRequest(
					'Invalid biography length: Maximum 500 characters',
				);
			}

			$user->set('field_bio', $bio);
		}

		if (isset($data['country'])) {
			$country = (string) $data['country'];
			$len = strlen($country);
			if ($len < 2 || $len > 2) {
				return GeneralHelper::badRequest(
					'Invalid country code length: Must be 2 characters',
				);
			}

			$user->set('field_country', $country);
		}

		if (isset($data['phone_number'])) {
			$phoneNumber = (int) $data['phone_number'];
			if ($phoneNumber < 10000 || $phoneNumber > 9999999999) {
				return GeneralHelper::badRequest(
					'Invalid phone number: Must be between 10000 and 9999999999',
				);
			}

			$user->set('field_phone', $phoneNumber);
		}

		if (isset($data['visibility'])) {
			$visibility = (string) $data['visibility'];
			$visibility0 = Visibility::tryFrom($visibility);
			if ($visibility0 === null) {
				return GeneralHelper::badRequest('Invalid visibility value');
			}

			$user->set(
				'field_visibility',
				GeneralHelper::findOrdinal(Visibility::cases(), $visibility0),
			);
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

	public static function getProfilePhoto(UserInterface $user): string
	{
		try {
			$res = CloudHelper::sendRequest('/v1/users/profile_photo/' . $user->id());
			$data = $res['data'] ?? null;
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

	// Field Utilities

	/**
	 * @return UserInterface[]
	 */
	public static function getAddedFriends(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
	): array {
		$friendsValue = $user->get('field_friends')->value ?? '[]';

		/** @var int[] $friends*/
		$friends = $friendsValue ? json_decode($friendsValue, true) : [];
		$friends = array_slice($friends, ($page - 1) * $limit, $limit);
		return array_filter(array_map(fn($id) => self::findById($id), $friends), function ($u) use (
			$search,
		) {
			if (empty($search)) {
				return true;
			}

			return str_contains($u->getAccountName(), $search);
		});
	}

	public static function getAddedFriendsCount(UserInterface $user, string $search = ''): int
	{
		$friendsValue = $user->get('field_friends')->value ?? '[]';

		/** @var int[] $friends*/
		$friends = $friendsValue ? json_decode($friendsValue, true) : [];
		if (!empty($search)) {
			$friends = array_filter(
				$friends,
				fn($id) => str_contains(self::findById($id)->getAccountName(), $search),
			);
		}

		return count($friends);
	}

	public static function isAddedFriend(UserInterface $user, UserInterface $friend): bool
	{
		$friends = $user->get('field_friends')->value ?? '[]';
		$friends = $friends ? json_decode($friends, true) : [];
		return in_array($friend->id(), $friends, true);
	}

	public static function isMutualFriend(UserInterface $user1, UserInterface $user2): bool
	{
		$user1Friends = self::getAddedFriends($user1);
		$user2Friends = self::getAddedFriends($user2);
		return !empty(array_intersect($user1Friends, $user2Friends));
	}

	public static function getMutualFriends(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
	): array {
		$userFriends = self::getAddedFriends($user);
		$friendCounts = [];

		// Count how many of user's friends each person is connected to
		foreach ($userFriends as $friend) {
			$friendsOfFriend = self::getAddedFriends($friend);
			foreach ($friendsOfFriend as $potentialMutual) {
				// Skip the original user
				if ($potentialMutual === $user) {
					continue;
				}

				$friendCounts[$potentialMutual->id()] =
					($friendCounts[$potentialMutual->id()] ?? 0) + 1;
			}
		}

		$mutual = [];
		foreach ($friendCounts as $personId => $count) {
			if ($count >= 2) {
				// Adjust threshold as needed
				$potentialUser = self::findById($personId);
				if ($potentialUser) {
					$mutual[] = $potentialUser;
				}
			}
		}

		// Apply search filter
		if (!empty($search)) {
			$mutual = array_filter($mutual, function ($u) use ($search) {
				return str_contains($u->getAccountName(), $search);
			});
		}

		// Apply pagination
		$offset = ($page - 1) * $limit;
		return array_slice($mutual, $offset, $limit);
	}

	public static function getNonMutualFriends(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
	): array {
		$userFriends = self::getAddedFriends($user);
		$nonMutual = [];

		foreach ($userFriends as $friend) {
			$friendsOfFriend = self::getAddedFriends($friend);
			foreach ($friendsOfFriend as $potentialNonMutual) {
				if ($potentialNonMutual === $user) {
					continue;
				}

				$nonMutual[$potentialNonMutual->id()] =
					($nonMutual[$potentialNonMutual->id()] ?? 0) + 1;
			}
		}

		$nonMutual = array_filter($nonMutual, fn($count) => $count === 1);
		$nonMutual = array_map(fn($id) => self::findById($id), array_keys($nonMutual));

		// Apply search filter
		if (!empty($search)) {
			$nonMutual = array_filter($nonMutual, function ($u) use ($search) {
				return str_contains($u->getAccountName(), $search);
			});
		}

		// Apply pagination
		$offset = ($page - 1) * $limit;
		return array_slice($nonMutual, $offset, $limit);
	}

	public static function addFriend(UserInterface $user, UserInterface $friend): bool
	{
		$friends = $user->get('field_friends')->value ?? '[]';
		$friends0 = $friends ? json_decode($friends, true) : [];
		if (in_array($friend->id(), $friends0, true)) {
			return false;
		}

		$friends0[] = $friend->id();
		$user->set('field_friends', json_encode($friends0));
		$user->save();

		self::addNotification(
			$friend,
			Drupal::translation()->translate('New Friend Added'),
			Drupal::translation()->translate(
				"{$user->getDisplayName()} has added you as a friend.",
			),
			"/profile/{$user->id()}",
			'info',
			'system',
		);

		return true;
	}

	public static function removeFriend(UserInterface $user, UserInterface $friend): bool
	{
		$friends = $user->get('field_friends')->value ?? '[]';
		$friends0 = $friends ? json_decode($friends, true) : [];
		if (!in_array($friend->id(), $friends0, true)) {
			return false;
		}

		$friends0 = array_filter($friends0, fn($id) => $id !== $friend->id());
		$user->set('field_friends', json_encode($friends0));
		$user->save();

		self::addNotification(
			$friend,
			Drupal::translation()->translate('Friend Removed'),
			Drupal::translation()->translate(
				"{$user->getDisplayName()} has removed you as a friend.",
			),
			"/profile/{$user->id()}",
			'info',
			'system',
		);

		return true;
	}

	/**
	 * @return UserInterface[]
	 */
	public static function getCircle(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
	): array {
		$circleValue = $user->get('field_circle')->value ?? '{}';

		/** @var int[] $circle */
		$circle = $circleValue ? json_decode($circleValue, true) : [];
		if (empty($search)) {
			$circle = array_slice($circle, ($page - 1) * $limit, $limit);
			return array_map(fn($id) => self::findById($id), $circle);
		} else {
			$circleUsers = array_map(fn($id) => self::findById($id), $circle);
			$circleUsers = array_filter(
				$circleUsers,
				fn($u) => str_contains($u->getAccountName(), $search),
			);
			$circleUsers = array_slice($circleUsers, ($page - 1) * $limit, $limit);
			return $circleUsers;
		}
	}

	public static function isInCircle(UserInterface $user1, UserInterface $user2): bool
	{
		$circle = self::getCircle($user1);
		return in_array($user2, $circle, true);
	}

	public static function addToCircle(UserInterface $user, UserInterface $member): bool
	{
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

		$circle = self::getCircle($user);
		if (in_array($member, $circle, true)) {
			return false;
		}

		$circle[] = $member->id();
		$user->set('field_circle', json_encode($circle));
		$user->save();
		return true;
	}

	public static function removeFromCircle(UserInterface $user, UserInterface $member): bool
	{
		$circle = self::getCircle($user);
		if (!in_array($member, $circle, true)) {
			return false;
		}

		$circle = array_filter($circle, fn($id) => $id !== $member->id());
		$user->set('field_circle', json_encode($circle));
		$user->save();
		return true;
	}

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
		if (count($activities) > 10) {
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

		if (count($activities) + 1 > 10) {
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
			$connection = Drupal::database();
			$query = $connection
				->select('node_field_data', 'n')
				->fields('n', ['nid'])
				->condition('status', 1)
				->condition('type', 'activity')
				->orderRandom()
				->range(0, $poolLimit);
			$nids = $query->execute()->fetchCol();

			$activitiesPool = array_map(
				fn($nid) => self::serializeForCloud(ActivityHelper::getActivityByNid($nid)),
				$nids,
			);
			$userActivities = array_map(
				fn(Activity $activity) => self::serializeForCloud($activity),
				self::getActivities($user),
			);

			$res = CloudHelper::sendRequest('/v1/users/recommend_activities', 'POST', [
				'all' => $activitiesPool,
				'user' => $userActivities,
			]);

			return array_map(function ($a) {
				$id = $a['id'] ?? null;
				return $id ? ActivityHelper::getActivity($id) : null;
			}, $res);
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to recommend activities: %message', [
				'%message' => $e->getMessage(),
			]);
		}

		return [];
	}

	// Token-based authentication helpers
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
		if ($token === CloudHelper::getAdminKey()) {
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

	// User field utilities

	public static function getUserEvents(
		UserInterface $user,
		int $limit = 25,
		int $page = 1,
		string $search = '',
	) {
		try {
			$storage = Drupal::entityTypeManager()->getStorage('node');
			$query = $storage
				->getQuery()
				->condition('type', 'event')
				->condition('field_host_id', $user->id());

			if (!empty($search)) {
				$query->condition('field_event_name', $search, 'CONTAINS');
			}

			$countQuery = clone $query;
			$count = $countQuery->count()->execute();

			$query->range(($page - 1) * $limit, $limit);
			$nids = $query->execute();
			$nodes = $storage->loadMultiple($nids);

			return [
				'events' => array_values(
					array_filter(
						array_map(
							fn($node) => $node ? EventsHelper::nodeToEvent($node) : null,
							$nodes,
						),
					),
				),
				'total' => $count,
			];
		} catch (Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException | Drupal\Component\Plugin\Exception\PluginNotFoundException $e) {
			Drupal::logger('mantle2')->error('Failed to retrieve user events: %message', [
				'%message' => $e->getMessage(),
			]);
			return [
				'events' => [],
				'total' => 0,
			];
		}
	}

	public static function getMaxEventAttendees(UserInterface $user): int
	{
		$type = self::getAccountType($user)->name;
		return match ($type) {
			'ADMINISTRATOR' => PHP_INT_MAX,
			'PRO', 'WRITER' => 5000,
			'ORGANIZER' => 1_000_000,
			default => 100,
		};
	}

	// Notification System Utilities

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
	): void {
		// ignore notifications for root user
		if ($user->id() === self::cloud()->id()) {
			return;
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

		self::setNotifications($user, $notifications);
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

	public static function sendEmailVerification(UserInterface $user): JsonResponse
	{
		$code = str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);

		/** @var \Drupal\Core\TempStore\PrivateTempStore $tempstore */
		$tempstore = Drupal::service('mantle2.tempstore.email_verification')->get('mantle2');
		$codeKey = 'email_verification_' . $user->id();
		$codeData = [
			'code' => $code,
			'timestamp' => time(),
			'user_id' => $user->id(),
		];

		try {
			$tempstore->set($codeKey, $codeData);
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to store email verification code: %message', [
				'%message' => $e->getMessage(),
			]);
			return GeneralHelper::internalError('Failed to generate verification code');
		}

		$userEmail = $user->getEmail();
		if (!$userEmail) {
			return GeneralHelper::badRequest('User has no email address');
		}

		/** @var \Drupal\Core\Mail\MailManagerInterface $mailManager */
		$mailManager = Drupal::service('plugin.manager.mail');
		$module = 'mantle2';
		$key = 'email_verification';
		$to = $userEmail;
		$langcode = $user->getPreferredLangcode();
		$params = [
			'verification_code' => $code,
			'user' => $user,
		];

		$result = $mailManager->mail($module, $key, $to, $langcode, $params);

		if (!$result['result']) {
			Drupal::logger('mantle2')->error('Failed to send email verification to %email', [
				'%email' => $userEmail,
			]);
			return GeneralHelper::internalError('Failed to send verification email');
		}

		return new JsonResponse(
			[
				'message' => 'Verification email sent',
				'email' => $userEmail,
			],
			Response::HTTP_OK,
		);
	}

	public static function sendEmail(UserInterface $user, string $key, array $params): void
	{
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
		/** @var \Drupal\Core\TempStore\PrivateTempStore $rateLimitStore */
		$rateLimitStore = Drupal::service('mantle2.tempstore.email_verification')->get(
			'email_change_rate_limit',
		);
		$rateLimitKey = 'email_change_rate_limit_' . $user->id();

		try {
			$lastSentData = $rateLimitStore->get($rateLimitKey);
			if ($lastSentData) {
				$timeSinceLastSent = time() - $lastSentData['timestamp'];
				// Clean up expired entries (300 seconds TTL)
				if ($timeSinceLastSent >= 300) {
					try {
						$rateLimitStore->delete($rateLimitKey);
					} catch (Exception $e) {
						// Log but continue
					}
					$lastSentData = null; // Treat as if no data exists
				}
			}

			if ($lastSentData && $timeSinceLastSent < 300) {
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
		} catch (Exception $e) {
			Drupal::logger('mantle2')->warning(
				'Failed to check email change rate limit: %message',
				[
					'%message' => $e->getMessage(),
				],
			);
		}

		// Store current timestamp for rate limiting
		try {
			$rateLimitStore->set($rateLimitKey, [
				'timestamp' => time(),
				'user_id' => $user->id(),
			]);
		} catch (Exception $e) {
			// Log error but continue - rate limiting storage failure shouldn't block the request
			Drupal::logger('mantle2')->warning(
				'Failed to store email change rate limit: %message',
				[
					'%message' => $e->getMessage(),
				],
			);
		}

		$code = str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);

		/** @var \Drupal\Core\TempStore\PrivateTempStore $tempstore */
		$tempstore = Drupal::service('mantle2.tempstore.email_verification')->get('email_change');
		$codeKey = 'email_change_' . $user->id();
		$codeData = [
			'code' => $code,
			'timestamp' => time(),
			'user_id' => $user->id(),
			'new_email' => $newEmail,
			'old_email' => $currentEmail,
		];

		try {
			$tempstore->set($codeKey, $codeData);
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error(
				'Failed to store email change verification code: %message',
				[
					'%message' => $e->getMessage(),
				],
			);
			return GeneralHelper::internalError('Failed to generate verification code');
		}

		// Send verification email to new email address
		/** @var \Drupal\Core\Mail\MailManagerInterface $mailManager */
		$mailManager = Drupal::service('plugin.manager.mail');
		$module = 'mantle2';
		$key = 'email_change_verification';
		$to = $newEmail;
		$langcode = $user->getPreferredLangcode();
		$params = [
			'verification_code' => $code,
			'user' => $user,
			'new_email' => $newEmail,
			'old_email' => $currentEmail,
		];

		$result = $mailManager->mail($module, $key, $to, $langcode, $params);

		if (!$result['result']) {
			Drupal::logger('mantle2')->error('Failed to send email change verification to %email', [
				'%email' => $newEmail,
			]);
			return GeneralHelper::internalError('Failed to send verification email');
		}

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
		/** @var \Drupal\Core\Mail\MailManagerInterface $mailManager */
		$mailManager = Drupal::service('plugin.manager.mail');
		$module = 'mantle2';
		$key = 'email_change_notification';
		$to = $currentEmail;
		$langcode = $user->getPreferredLangcode();
		$params = [
			'user' => $user,
			'new_email' => $newEmail,
			'old_email' => $currentEmail,
		];

		$result = $mailManager->mail($module, $key, $to, $langcode, $params);

		if (!$result['result']) {
			Drupal::logger('mantle2')->error('Failed to send email change notification to %email', [
				'%email' => $currentEmail,
			]);
		}
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

		/** @var \Drupal\Core\TempStore\PrivateTempStore $tempstore */
		$tempstore = Drupal::service('mantle2.tempstore.email_verification')->get('email_change');
		$codeKey = 'email_change_' . $user->id();

		try {
			$storedData = $tempstore->get($codeKey);
			// Check if code has expired (15 minutes = 900 seconds)
			if ($storedData) {
				$currentTime = time();
				if ($currentTime - $storedData['timestamp'] > 900) {
					// Clean up expired code
					try {
						$tempstore->delete($codeKey);
					} catch (Exception $e) {
						// Log but don't fail the request
					}
					$storedData = null; // Treat as expired
				}
			}
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error(
				'Failed to retrieve email change verification code: %message',
				[
					'%message' => $e->getMessage(),
				],
			);
			return GeneralHelper::internalError('Failed to verify code');
		}

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
		try {
			$tempstore->delete($codeKey);
		} catch (Exception $e) {
			// Log but don't fail the request since email change was successful
			Drupal::logger('mantle2')->warning(
				'Failed to delete used email change verification code: %message',
				[
					'%message' => $e->getMessage(),
				],
			);
		}

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
			'Your email address has been successfully changed to ' . $newEmail,
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
}
