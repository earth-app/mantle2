<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\mantle2\Custom\AccountType;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\user\Plugin\views\wizard\Users;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UsersController extends ControllerBase
{
	public static function create(ContainerInterface $container): UsersController|static
	{
		return new static();
	}

	// GET /v2/users
	public function users(Request $request): JsonResponse
	{
		$requester = UsersHelper::getOwnerOfRequest($request);
		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		try {
			$storage = Drupal::entityTypeManager()->getStorage('user');
			$query = $storage->getQuery()->accessCheck(false)->condition('uid', 0, '!='); // Exclude anonymous user

			if ($search) {
				$group = $query
					->orConditionGroup()
					->condition('name', $search, 'CONTAINS')
					->condition('field_first_name', $search, 'CONTAINS')
					->condition('field_last_name', $search, 'CONTAINS');
				$query->condition($group);
			}

			$countQuery = clone $query;
			$total = $countQuery->count()->execute();
			$uids = $query
				->range($page * $limit, $limit)
				->sort('created', 'DESC')
				->execute();
			$users = array_filter($storage->loadMultiple($uids), function ($user) use ($request) {
				$res = UsersHelper::checkVisibility($user, $request);
				if ($res instanceof JsonResponse) {
					return false;
				}
				return true;
			});
			$data = array_map(fn($user) => UsersHelper::serializeUser($user, $requester), $users);
			return new JsonResponse([
				'page' => $page + 1,
				'total' => $total,
				'limit' => $limit,
				'items' => $data,
			]);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError('Failed to load user storage: ' . $e->getMessage());
		}
	}

	// POST /v2/users/login (Basic auth expected)
	public function login(Request $request): JsonResponse
	{
		$auth = $request->headers->get('Authorization');
		if (!$auth || stripos($auth, 'Basic ') !== 0) {
			return GeneralHelper::unauthorized();
		}
		$decoded = base64_decode(substr($auth, 6) ?: '', true);
		if (!$decoded || !str_contains($decoded, ':')) {
			return GeneralHelper::unauthorized();
		}
		[$name, $pass] = explode(':', $decoded, 2);
		if (!$name || !$pass) {
			return GeneralHelper::badRequest('Invalid login credentials');
		}

		/** @var UserAuthInterface $userAuth */
		$userAuth = Drupal::service('user.auth');
		$uid = $userAuth->authenticate($name, $pass);
		if (!$uid) {
			return GeneralHelper::unauthorized();
		}

		/** @var UserInterface $account */
		$account = User::load($uid);
		if (!$account || $account->isBlocked()) {
			return GeneralHelper::unauthorized();
		}

		// Log the user in and migrate session.
		$this->finalizeLogin($account);

		// Use Drupal session ID as session_token per requirements.
		$session = Drupal::service('session');
		$session_id = method_exists($session, 'getId')
			? $session->getId()
			: ($request->getSession()
				? $request->getSession()->getId()
				: null);

		$data = [
			'id' => UsersHelper::formatId($account->id()),
			'username' => $account->getAccountName(),
			'session_token' => $session_id,
		];
		return new JsonResponse($data, Response::HTTP_OK);
	}

	// POST /v2/users/logout
	public function logout(Request $request): JsonResponse
	{
		$sessionId = GeneralHelper::getBearerToken($request);
		if (!$sessionId) {
			return GeneralHelper::unauthorized();
		}

		$payloadUser = UsersHelper::withSessionId($sessionId, function ($session) {
			$uid = $session->get('uid');
			if (!$uid) {
				return null;
			}
			$user = User::load($uid);
			return $user ? UsersHelper::serializeUser($user, $user) : null;
		});

		// Destroy that session.
		UsersHelper::withSessionId($sessionId, function () {
			Drupal::service('session_manager')->destroy();
			return null;
		});

		$data = [
			'message' => 'Logout successful',
			'session_token' => $sessionId,
		];
		if ($payloadUser) {
			$data['user'] = $payloadUser;
		}
		return new JsonResponse($data, Response::HTTP_OK);
	}

	// POST /v2/users/create
	public function createUser(Request $request): JsonResponse
	{
		$body = json_decode((string) $request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}
		$username = $body['username'] ?? null;
		$password = $body['password'] ?? null;
		$email = $body['email'] ?? null;
		if (!$username || !$password) {
			return GeneralHelper::badRequest('username and password are required');
		}

		if (UsersHelper::findByUsername($username) !== null) {
			return GeneralHelper::badRequest('Username already exists');
		}
		if ($email && UsersHelper::findByEmail($email) !== null) {
			return GeneralHelper::badRequest('Email already in use');
		}

		$user = User::create();
		$user->setUsername($username);
		if ($email) {
			$user->setEmail($email);
		}
		$user->activate();
		$user->setPassword($password);
		$user->enforceIsNew();

		try {
			$user->save();
		} catch (EntityStorageException $e) {
			return GeneralHelper::internalError('Failed to create user: ' . $e->getMessage());
		}

		// Immediately log user in and return session token
		$this->finalizeLogin($user);
		$session = Drupal::service('session');
		$session_id = method_exists($session, 'getId')
			? $session->getId()
			: ($request->getSession()
				? $request->getSession()->getId()
				: null);

		$data = [
			'user' => UsersHelper::serializeUser($user, $user),
			'session_token' => $session_id,
		];
		return new JsonResponse($data, Response::HTTP_CREATED);
	}

	#region Current User

	// GET /v2/users/current
	public function currentGet(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// PATCH /v2/users/current
	public function currentPatch(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		return UsersHelper::patchUser($user, $body, $user);
	}

	// DELETE /v2/users/current
	public function currentDelete(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		try {
			$user->delete();
		} catch (EntityStorageException $e) {
			return GeneralHelper::internalError('Failed to delete user: ' . $e->getMessage());
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// PATCH /v2/users/current/field_privacy
	public function currentFieldPrivacy(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		return UsersHelper::patchFieldPrivacy($user, $body, $user);
	}

	// GET /v2/users/current/profile_photo
	// GET /v2/users/current/profile_photo
	public function currentProfilePhoto(Request $request): Response
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$dataUrl = UsersHelper::getProfilePhoto($user);
		return GeneralHelper::fromDataURL($dataUrl);
	}

	// PUT /v2/users/current/profile_photo
	public function currentUpdateProfilePhoto(Request $request): Response
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$dataUrl = UsersHelper::regenerateProfilePhoto($user);
		return GeneralHelper::fromDataURL($dataUrl);
	}

	// PUT /v2/users/current/account_type
	public function currentSetAccountType(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!$user->hasPermission('administer users')) {
			return GeneralHelper::forbidden('Insufficient permissions to change account type');
		}

		$type = AccountType::tryFrom(strtoupper($request->query->get('account_type')));
		if (!$type) {
			return GeneralHelper::badRequest('Invalid account_type');
		}

		$user->set('field_account_type', $type->value);
		$user->save();

		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// GET /v2/users/current/activities
	public function currentUserActivities(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$activities = UsersHelper::getActivities($user);
		return new JsonResponse($activities, Response::HTTP_OK);
	}

	// PATCH /v2/users/current/activities
	public function currentSetUserActivities(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$activityIds = json_decode((string) $request->getContent(), true) ?: [];
		if (!$activityIds) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (empty($activityIds)) {
			return GeneralHelper::badRequest('No activity IDs provided');
		}

		$activities = array_filter(
			array_map(fn($id) => ActivityHelper::getActivity($id), $activityIds),
			fn($a) => $a !== null,
		);
		if (empty($activities)) {
			return GeneralHelper::badRequest('No valid activities found');
		}

		UsersHelper::setActivities($user, $activities);
		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// PUT /v2/users/current/activities
	public function currentAddUserActivity(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$activityId = $request->query->get('activityId');
		if (!$activityId) {
			return GeneralHelper::badRequest('Missing activityId');
		}

		$activity = ActivityHelper::getActivity($activityId);
		if (!$activity) {
			return GeneralHelper::notFound('Activity not found');
		}

		UsersHelper::addActivity($user, $activity);
		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// DELETE /v2/users/current/activities
	public function currentRemoveUserActivity(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$activityId = $request->query->get('activityId');
		if (!$activityId) {
			return GeneralHelper::badRequest('Missing activityId');
		}

		$activity = ActivityHelper::getActivity($activityId);
		if (!$activity) {
			return GeneralHelper::notFound('Activity not found');
		}

		UsersHelper::removeActivity($user, $activity);
		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// GET /v2/users/current/friends
	public function currentUserFriends(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		$filter = $request->query->get('filter') ?? 'added';
		switch ($filter) {
			case 'mutual':
				$friends = UsersHelper::getMutualFriends($user, $limit, $page, $search);
				break;
			case 'added':
				$friends = UsersHelper::getAddedFriends($user, $limit, $page, $search);
				break;
			case 'non_mutual':
				$friends = UsersHelper::getNonMutualFriends($user, $limit, $page, $search);
				break;
			default:
				return GeneralHelper::badRequest(
					"Invalid filter '$filter'; Must be one of 'mutual', 'added', or 'non_mutual'",
				);
		}

		$data = array_map(fn($u) => UsersHelper::serializeUser($u, $user), $friends);
		return new JsonResponse(
			[
				'limit' => $limit,
				'page' => $page + 1,
				'search' => $search,
				'items' => $data,
			],
			Response::HTTP_OK,
		);
	}

	// PUT /v2/users/current/friends
	public function currentAddUserFriend(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$friendId = $request->query->get('friend');
		if (!$friendId) {
			return GeneralHelper::badRequest('Missing friend ID or Username');
		}

		$friend = UsersHelper::findBy($friendId);
		if (!$friend) {
			return GeneralHelper::notFound('Friend not found');
		}

		// Ensure friend is visible
		$friend = UsersHelper::checkVisibility($friend, $request);
		if ($friend instanceof JsonResponse) {
			return $friend;
		}

		$result = UsersHelper::addFriend($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is already added');
		}

		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// DELETE /v2/users/current/friends
	public function currentRemoveUserFriend(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$friendId = $request->query->get('friend');
		if (!$friendId) {
			return GeneralHelper::badRequest('Missing friend ID or Username');
		}

		$friend = UsersHelper::findBy($friendId);
		if (!$friend) {
			return GeneralHelper::notFound('Friend not found');
		}

		$result = UsersHelper::removeFriend($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is not added');
		}

		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// GET /v2/users/current/circle
	public function currentUserCircle(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		$circle = UsersHelper::getCircle($user, $limit, $page, $search);
		$data = array_map(fn($u) => UsersHelper::serializeUser($u, $user), $circle);

		return new JsonResponse(
			[
				'limit' => $limit,
				'page' => $page + 1,
				'search' => $search,
				'items' => $data,
			],
			Response::HTTP_OK,
		);
	}

	// PUT /v2/users/current/circle
	public function currentAddUserToCircle(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$friendId = $request->query->get('friend');
		if (!$friendId) {
			return GeneralHelper::badRequest('Missing friend ID or Username');
		}

		$friend = UsersHelper::findBy($friendId);
		if (!$friend) {
			return GeneralHelper::notFound('Friend not found');
		}

		if (!UsersHelper::isAddedFriend($user, $friend)) {
			return GeneralHelper::badRequest('Only friends can be added to circle');
		}

		$result = UsersHelper::addToCircle($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is already in circle');
		}

		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// DELETE /v2/users/current/circle
	public function currentRemoveUserFromCircle(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$friendId = $request->query->get('friend');
		if (!$friendId) {
			return GeneralHelper::badRequest('Missing friend ID or Username');
		}

		$friend = UsersHelper::findBy($friendId);
		if (!$friend) {
			return GeneralHelper::notFound('Friend not found');
		}

		if (!UsersHelper::isInCircle($user, $friend)) {
			return GeneralHelper::badRequest('Friend is not in circle');
		}

		$result = UsersHelper::removeFromCircle($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is not in circle');
		}

		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	#endregion
	#region By ID/Username

	// GET /v2/users/:id
	// GET /v2/users/:username
	public function getUser(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = UsersHelper::findBy($id ?? $username);
		if (!$user) {
			return GeneralHelper::notFound('User not found');
		}

		$user = UsersHelper::checkVisibility($user, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		return new JsonResponse(UsersHelper::serializeUser($user, $requester), Response::HTTP_OK);
	}

	// PATCH /v2/users/:id
	// PATCH /v2/users/:username
	public function patchUser(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		return UsersHelper::patchUser($user, $body, $requester);
	}

	// DELETE /v2/users/:id
	// DELETE /v2/users/:username
	public function deleteUser(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		try {
			$user->delete();
		} catch (EntityStorageException $e) {
			return GeneralHelper::internalError('Failed to delete user: ' . $e->getMessage());
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// PATCH /v2/users/:id/field_privacy
	// PATCH /v2/users/:username/field_privacy
	public function patchFieldPrivacy(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		return UsersHelper::patchFieldPrivacy($user, $body, $requester);
	}

	// GET /v2/users/:id/profile_photo
	// GET /v2/users/:username/profile_photo
	public function getProfilePhoto(
		Request $request,
		?string $id = null,
		?string $username = null,
	): Response {
		$user = UsersHelper::findBy($id ?? $username);
		if (!$user) {
			return GeneralHelper::notFound('User not found');
		}

		$user = UsersHelper::checkVisibility($user, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$dataUrl = UsersHelper::getProfilePhoto($user);
		return GeneralHelper::fromDataURL($dataUrl);
	}

	// PUT /v2/users/:id/profile_photo
	// PUT /v2/users/:username/profile_photo
	public function updateProfilePhoto(
		Request $request,
		?string $id = null,
		?string $username = null,
	): Response {
		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$dataUrl = UsersHelper::regenerateProfilePhoto($user);
		return GeneralHelper::fromDataURL($dataUrl);
	}

	// PUT /v2/users/:id/account_type
	// PUT /v2/users/:username/account_type
	public function setAccountType(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		if (!$requester) {
			return GeneralHelper::unauthorized();
		}

		if (!$requester->hasPermission('administer users')) {
			return GeneralHelper::forbidden('You do not have permission to perform this action.');
		}

		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$type = AccountType::tryFrom(strtoupper($request->query->get('account_type')));
		if (!$type) {
			return GeneralHelper::badRequest('Invalid account_type');
		}

		$user->set('field_account_type', $type->value);
		$user->save();

		return new JsonResponse(UsersHelper::serializeUser($user, $requester), Response::HTTP_OK);
	}

	// GET /v2/users/:id/activities
	// GET /v2/users/:username/activities
	public function userActivities(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = UsersHelper::findBy($id ?? $username);
		if (!$user) {
			return GeneralHelper::notFound('User not found');
		}

		$user = UsersHelper::checkVisibility($user, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$activities = UsersHelper::getActivities($user);
		return new JsonResponse($activities, Response::HTTP_OK);
	}

	// PATCH /v2/users/:id/activities
	// PATCH /v2/users/:username/activities
	public function setUserActivities(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$activityIds = json_decode((string) $request->getContent(), true) ?: [];
		if (!$activityIds) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (empty($activityIds)) {
			return GeneralHelper::badRequest('No activity IDs provided');
		}

		$activities = array_filter(
			array_map(fn($id) => ActivityHelper::getActivity($id), $activityIds),
			fn($a) => $a !== null,
		);
		if (empty($activities)) {
			return GeneralHelper::badRequest('No valid activities found');
		}

		UsersHelper::setActivities($user, $activities);
		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// PUT /v2/users/:id/activities
	// PUT /v2/users/:username/activities
	public function addUserActivity(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$activityId = $request->query->get('activityId');
		if (!$activityId) {
			return GeneralHelper::badRequest('Missing activityId');
		}

		$activity = ActivityHelper::getActivity($activityId);
		if (!$activity) {
			return GeneralHelper::notFound('Activity not found');
		}

		$activities = UsersHelper::getActivities($user);
		if (in_array($activityId, array_map(fn($a) => $a->getId(), $activities))) {
			return GeneralHelper::conflict('Activity already added');
		}

		UsersHelper::addActivity($user, $activity);
		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// DELETE /v2/users/:id/activities
	// DELETE /v2/users/:username/activities
	public function removeUserActivity(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$activityId = $request->query->get('activityId');
		if (!$activityId) {
			return GeneralHelper::badRequest('Missing activityId');
		}

		$activity = ActivityHelper::getActivity($activityId);
		if (!$activity) {
			return GeneralHelper::notFound('Activity not found');
		}

		$activities = UsersHelper::getActivities($user);
		if (!in_array($activityId, array_map(fn($a) => $a->getId(), $activities))) {
			return GeneralHelper::notFound('Activity not associated with user');
		}

		UsersHelper::removeActivity($user, $activity);
		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// GET /v2/users/:id/friends
	// GET /v2/users/:username/friends
	public function userFriends(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = UsersHelper::findBy($id ?? $username);
		$requester = UsersHelper::getOwnerOfRequest($request);
		if (!$user) {
			return GeneralHelper::notFound('User not found');
		}

		$user = UsersHelper::checkVisibility($user, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		$filter = $request->query->get('filter') ?? 'added';
		switch ($filter) {
			case 'mutual':
				$friends = UsersHelper::getMutualFriends($user, $limit, $page, $search);
				break;
			case 'added':
				$friends = UsersHelper::getAddedFriends($user, $limit, $page, $search);
				break;
			case 'non_mutual':
				$friends = UsersHelper::getNonMutualFriends($user, $limit, $page, $search);
				break;
			default:
				return GeneralHelper::badRequest(
					"Invalid filter '$filter'; Must be one of 'mutual', 'added', or 'non_mutual'",
				);
		}

		$data = array_map(fn($u) => UsersHelper::serializeUser($u, $requester), $friends);
		return new JsonResponse(
			[
				'limit' => $limit,
				'page' => $page + 1,
				'search' => $search,
				'items' => $data,
			],
			Response::HTTP_OK,
		);
	}

	// PUT /v2/users/:id/friends
	// PUT /v2/users/:username/friends
	public function addUserFriend(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$friendId = $request->query->get('friend');
		if (!$friendId) {
			return GeneralHelper::badRequest('Missing friend ID or Username');
		}

		$friend = UsersHelper::findBy($friendId);
		if (!$friend) {
			return GeneralHelper::notFound('Friend not found');
		}

		// Ensure friend is visible
		$friend = UsersHelper::checkVisibility($friend, $request);
		if ($friend instanceof JsonResponse) {
			return $friend;
		}

		$result = UsersHelper::addFriend($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is already added');
		}

		return new JsonResponse(UsersHelper::serializeUser($user, $requester), Response::HTTP_OK);
	}

	// DELETE /v2/users/:id/friends
	// DELETE /v2/users/:username/friends
	public function removeUserFriend(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$friendId = $request->query->get('friend');
		if (!$friendId) {
			return GeneralHelper::badRequest('Missing friend ID or Username');
		}

		$friend = UsersHelper::findBy($friendId);
		if (!$friend) {
			return GeneralHelper::notFound('Friend not found');
		}

		$result = UsersHelper::removeFriend($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is not added');
		}

		return new JsonResponse(UsersHelper::serializeUser($user, $requester), Response::HTTP_OK);
	}

	// GET /v2/users/:id/circle
	// GET /v2/users/:username/circle
	public function userCircle(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = UsersHelper::findBy($id ?? $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$user = UsersHelper::checkVisibility($user, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		$circle = UsersHelper::getCircle($user, $limit, $page, $search);
		$data = array_map(fn($u) => UsersHelper::serializeUser($u, $requester), $circle);

		return new JsonResponse(
			[
				'limit' => $limit,
				'page' => $page + 1,
				'search' => $search,
				'items' => $data,
			],
			Response::HTTP_OK,
		);
	}

	// PUT /v2/users/:id/circle
	// PUT /v2/users/:username/circle
	public function addUserToCircle(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = UsersHelper::findByAuthorized($id ?? $username, $request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$friendId = $request->query->get('friend');
		if (!$friendId) {
			return GeneralHelper::badRequest('Missing friend ID or Username');
		}

		$friend = UsersHelper::findBy($friendId);
		if (!$friend) {
			return GeneralHelper::notFound('Friend not found');
		}

		if (!UsersHelper::isAddedFriend($user, $friend)) {
			return GeneralHelper::badRequest('Only friends can be added to circle');
		}

		$result = UsersHelper::addToCircle($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is already in circle');
		}

		return new JsonResponse(UsersHelper::serializeUser($user, $requester), Response::HTTP_OK);
	}

	// DELETE /v2/users/:id/circle
	// DELETE /v2/users/:username/circle
	public function removeUserFromCircle(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = UsersHelper::findBy($id ?? $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$friendId = $request->query->get('friend');
		if (!$friendId) {
			return GeneralHelper::badRequest('Missing friend ID or Username');
		}

		$friend = UsersHelper::findBy($friendId);
		if (!$friend) {
			return GeneralHelper::notFound('Friend not found');
		}

		if (!UsersHelper::isInCircle($user, $friend)) {
			return GeneralHelper::badRequest('Friend is not in circle');
		}

		$result = UsersHelper::removeFromCircle($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is not in circle');
		}

		return new JsonResponse(UsersHelper::serializeUser($user, $requester), Response::HTTP_OK);
	}

	#endregion

	#region Utility Functions

	// Utility Functions

	private function finalizeLogin(UserInterface $account): void
	{
		Drupal::currentUser()->setAccount($account);
		Drupal::logger('user')->info('Session opened for %name.', [
			'%name' => $account->getAccountName(),
		]);
		$account->setLastLoginTime(Drupal::time()->getRequestTime());

		// Persist the last login time; storage-specific optimization omitted to avoid static analysis issues.
		try {
			$account->save();
		} catch (EntityStorageException $e) {
			Drupal::logger('user')->error('Failed to save last login time for %name: %error', [
				'%name' => $account->getAccountName(),
				'%error' => $e->getMessage(),
			]);
		}

		$session = Drupal::service('session');
		if (method_exists($session, 'migrate')) {
			$session->migrate();
		}
		$session->set('uid', $account->id());
		$session->set('check_logged_in', true);
		Drupal::moduleHandler()->invokeAll('user_login', [$account]);
	}

	#endregion
}
