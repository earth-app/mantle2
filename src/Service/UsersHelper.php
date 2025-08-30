<?php

namespace Drupal\mantle2\Service;

use Drupal\mantle2\Controller\Schema\Mantle2Schemas;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UsersHelper
{
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
		// This assumes that the request is performing a write operation on a user by an identifier.
		// Only allow if it is themselves, or if $user2 is an administrator
		if ($user->id() === $user2->id() || $user2->hasPermission('administer users')) {
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
		$users = \Drupal::entityTypeManager()
			->getStorage('user')
			->loadByProperties(['name' => $username]);
		return $users ? reset($users) : null;
	}

	public static function findByEmail(string $email)
	{
		$users = \Drupal::entityTypeManager()
			->getStorage('user')
			->loadByProperties(['mail' => $email]);
		return $users ? reset($users) : null;
	}

	public static function checkVisibility(
		UserInterface $user,
		Request $request,
	): UserInterface|JsonResponse {
		$visibilityValue = $user->get('field_visibility')->getValue();
		$visibility = $visibilityValue ? $visibilityValue[0]['value'] : 'UNLISTED';

		// PUBLIC is visible to everyone
		if ($visibility === 'PUBLIC') {
			return $user;
		}

		// UNLISTED (and PRIVATE, see below) requires login
		$user2 = self::findByRequest($request);
		if (!$user2) {
			return GeneralHelper::notFound();
		}

		// PRIVATE requires admin
		if ($visibility === 'PRIVATE' && !$user2->hasPermission('administer users')) {
			return GeneralHelper::forbidden();
		}

		return $user;
	}

	public static function withSessionId(string $sid, callable $fn)
	{
		$session = \Drupal::service('session');
		if (method_exists($session, 'isStarted') && $session->isStarted()) {
			$current = method_exists($session, 'getId') ? $session->getId() : null;
			if ($current !== $sid && method_exists($session, 'save')) {
				$session->save();
			}
		}
		if (method_exists($session, 'setId')) {
			$session->setId($sid);
		}
		if (method_exists($session, 'start')) {
			$session->start();
		}
		return $fn($session);
	}

	public static function findByRequest(Request $request)
	{
		if (!$request->hasSession()) {
			return GeneralHelper::notFound();
		}

		$sessionId = GeneralHelper::getBearerToken($request);
		if (!$sessionId) {
			return GeneralHelper::unauthorized();
		}

		$user = self::withSessionId($sessionId, function ($session) {
			$uid = $session->get('uid');
			return $uid ? User::load($uid) : null;
		});

		if (!$user instanceof UserInterface) {
			return GeneralHelper::notFound();
		}

		return $user;
	}

	public static function getOwnerOfRequest(Request $request)
	{
		$user = self::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return null;
		}

		return $user;
	}

	public static function isVisible(UserInterface $user, ?UserInterface $user2, string $required)
	{
		if ($required === 'PUBLIC') {
			return true;
		}

		if (!$user2) {
			return false;
		}

		if ($user->id() === $user2->id() || $user2->hasPermission('administer users')) {
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
		$privacy = $user->get('field_privacy')->getValue();
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

	public static function formatId($id): string
	{
		$s = (string) $id;
		if (strlen($s) < 24) {
			$s = str_pad($s, 24, '0', STR_PAD_LEFT);
		}
		return substr($s, 0, 24);
	}

	public static function getFirstName(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?string {
		$privacy = self::getFieldPrivacy($user);
		$firstNameValue = $user->get('field_first_name')->getValue();
		return self::tryVisible(
			$firstNameValue ? $firstNameValue[0]['value'] : 'John',
			$user,
			$requester,
			$privacy['name'] ?? 'PUBLIC',
		);
	}

	public static function getLastName(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?string {
		$privacy = self::getFieldPrivacy($user);
		$lastNameValue = $user->get('field_last_name')->getValue();
		return self::tryVisible(
			$lastNameValue ? $lastNameValue[0]['value'] : 'Doe',
			$user,
			$requester,
			$privacy['name'] ?? 'PUBLIC',
		);
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
		$bioValue = $user->get('field_bio')->getValue();
		$bio = $bioValue ? $bioValue[0]['value'] : '';
		return self::tryVisible($bio, $user, $requester, $privacy['bio'] ?? 'PUBLIC');
	}

	public static function getPhoneNumber(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?int {
		$privacy = self::getFieldPrivacy($user);
		$phoneNumberValue = $user->get('field_phone')->getValue() ?? 0;
		$phoneNumber = $phoneNumberValue ? $phoneNumberValue[0]['value'] : 0;
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
		$addressValue = $user->get('field_address')->getValue() ?? null;
		$address = $addressValue ? $addressValue[0]['value'] : null;
		return self::tryVisible($address, $user, $requester, $privacy['address'] ?? 'PRIVATE');
	}

	public static function getCountry(
		UserInterface $user,
		?UserInterface $requester = null,
	): ?string {
		$privacy = self::getFieldPrivacy($user);
		$countryValue = $user->get('field_country')->getValue() ?? null;
		$country = $countryValue ? $countryValue[0]['value'] : null;
		return self::tryVisible($country, $user, $requester, $privacy['country'] ?? 'PUBLIC');
	}

	public static function serializeUser(
		UserInterface $user,
		?UserInterface $requester = null,
	): array {
		$privacy = self::getFieldPrivacy($user);
		$serialized = [
			'id' => self::formatId($user->id()),
			'username' => $user->getAccountName(),
			'fullName' => self::getName($user, $requester),
			'created_at' => date('c', $user->getCreatedTime()),
			'updated_at' => date('c', $user->getChangedTime()),
			'last_login' => date('c', $user->getLastLoginTime()),
			'account' => [
				'id' => self::formatId($user->id()),
				'username' => $user->getAccountName(),
				'first_name' => self::getFirstName($user, $requester),
				'last_name' => self::getLastName($user, $requester),
				'email' => self::getEmail($user, $requester),
				'bio' => self::getBiography($user, $requester),
				'phone_number' => self::getPhoneNumber($user, $requester),
				'address' => self::getAddress($user, $requester),
				'country' => self::getCountry($user, $requester),
				'field_privacy' => $privacy,
			],
			'activities' => [],
			'friends' => self::tryVisible(
				$user->get('field_friends')->getValue(),
				$user,
				$requester,
				$privacy['friends'] ?? 'PUBLIC',
			),
		];

		return $serialized;
	}

	public static function patchUser(UserInterface $user, array $data): JsonResponse
	{
		if (!$user || !$data) {
			return GeneralHelper::badRequest('Invalid user or data');
		}

		if (isset($body['username'])) {
			$username = (string) $body['username'];
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

		if (isset($body['email'])) {
			$email = (string) $body['email'];

			if (
				!str_contains($email, '@') || // Missing @
				!str_contains(explode('@', $email)[1], '.') // Period in domain
			) {
				return GeneralHelper::badRequest('Invalid email format: Must contain @ and .');
			}

			$existing = self::findByEmail($email);
			if ($existing && $existing->id() !== $user->id()) {
				return GeneralHelper::badRequest('Email already exists');
			}

			$user->setEmail($email);
		}

		if (isset($body['first_name'])) {
			$firstName = (string) $body['first_name'];
			$len = strlen($firstName);
			if ($len < 2 || $len > 50) {
				return GeneralHelper::badRequest('Invalid first name length');
			}

			$user->set('field_first_name', $firstName);
		}

		if (isset($body['last_name'])) {
			$lastName = (string) $body['last_name'];
			$len = strlen($lastName);
			if ($len < 2 || $len > 50) {
				return GeneralHelper::badRequest('Invalid last name length');
			}

			$user->set('field_last_name', $lastName);
		}

		if (isset($body['bio'])) {
			$bio = (string) $body['bio'];
			$len = strlen($bio);
			if ($len > 500) {
				return GeneralHelper::badRequest(
					'Invalid biography length: Maximum 500 characters',
				);
			}

			$user->set('field_bio', $bio);
		}

		if (isset($body['country'])) {
			$country = (string) $body['country'];
			$len = strlen($country);
			if ($len < 2 || $len > 2) {
				return GeneralHelper::badRequest(
					'Invalid country code length: Must be 2 characters',
				);
			}

			$user->set('field_country', $country);
		}

		if (isset($body['phone_number'])) {
			$phoneNumber = (int) $body['phone_number'];
			if ($phoneNumber < 10000 || $phoneNumber > 9999999999) {
				return GeneralHelper::badRequest(
					'Invalid phone number: Must be between 10000 and 9999999999',
				);
			}

			$user->set('field_phone', $phoneNumber);
		}

		if (isset($body['visibility'])) {
			$visibility = (string) $body['visibility'];
			if (Visibility::tryFrom($visibility) === null) {
				return GeneralHelper::badRequest('Invalid visibility value');
			}

			$user->set('field_visibility', $visibility);
		}

		$user->save();

		return new JsonResponse(self::serializeUser($user), Response::HTTP_OK);
	}

	private static function validKeys()
	{
		return array_keys(Mantle2Schemas::userFieldPrivacy()['properties']);
	}

	private static array $neverPublic = ['address', 'phone_number', 'circle'];

	public static function patchFieldPrivacy(UserInterface $user, array $data): JsonResponse
	{
		if (!$user) {
			return GeneralHelper::badRequest('Invalid user');
		}

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
		return new JsonResponse(self::serializeUser($user), Response::HTTP_OK);
	}

	// Field Utilities

	/**
	 * @return UserInterface[]
	 */
	public static function getAddedFriends(UserInterface $user): array
	{
		/** @var int[] */
		$friends = json_decode($user->get('field_friends')->getValue(), true);
		return array_map(fn($id) => self::findById($id), $friends);
	}

	public static function isMutualFriend(UserInterface $user1, UserInterface $user2): bool
	{
		$user1Friends = self::getAddedFriends($user1);
		$user2Friends = self::getAddedFriends($user2);
		return !empty(array_intersect($user1Friends, $user2Friends));
	}

	public static function getMutualFriends(UserInterface $user): array
	{
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
				$mutual[] = self::findById($personId);
			}
		}

		return $mutual;
	}

	/**
	 * @return UserInterface[]
	 */
	public static function getCircle(UserInterface $user): array
	{
		/** @var int[] */
		$circle = json_decode($user->get('field_circle')->getValue(), true);
		return array_map(fn($id) => self::findById($id), $circle);
	}

	public static function isInCircle(UserInterface $user1, UserInterface $user2): bool
	{
		$circle = self::getCircle($user1);
		return in_array($user2, $circle, true);
	}

	public static function getActivities(UserInterface $user): array
	{
		$activities = json_decode($user->get('field_activities')->getValue(), true);
		return array_map(fn($data) => Activity::fromArray($data), $activities);
	}
}
