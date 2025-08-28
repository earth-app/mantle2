<?php

namespace Drupal\mantle2\Service;

use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

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
		$sessionId = GeneralHelper::getBearerToken($request);
		if (!$sessionId) {
			return GeneralHelper::unauthorized();
		}

		$user = self::withSessionId($sessionId, function ($session) {
			$uid = $session->get('uid');
			return $uid ? User::load($uid) : null;
		});

		if (!$user instanceof UserInterface) {
			return GeneralHelper::unauthorized();
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

	public static function findByRequestAuthorized(Request $request, string $identifier)
	{
		$user = self::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$user2 = self::findBy($identifier);

		if (!$user2) {
			return GeneralHelper::notFound();
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
