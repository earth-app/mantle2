<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Exception;
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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Exception\UnexpectedValueException;
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

		// Log the user in for Drupal bookkeeping (last login, hooks) then issue API token.
		$this->finalizeLogin($account, $request);
		$token = UsersHelper::issueToken($account);

		$data = [
			'id' => GeneralHelper::formatId($account->id()),
			'username' => $account->getAccountName(),
			'session_token' => $token,
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

		// Prefer API token revocation; fall back to PHP session destruction if needed.
		$payloadUser = null;
		$tokenUser = UsersHelper::getUserByToken($sessionId);
		if ($tokenUser) {
			$payloadUser = UsersHelper::serializeUser($tokenUser, $tokenUser);
			UsersHelper::revokeToken($sessionId);
		} else {
			$payloadUser = UsersHelper::withSessionId($sessionId, function ($session) {
				$uid = $session->get('uid');
				if (!$uid) {
					return null;
				}
				$user = User::load($uid);
				return $user ? UsersHelper::serializeUser($user, $user) : null;
			});

			// Destroy that session (legacy behavior).
			UsersHelper::withSessionId($sessionId, function () {
				Drupal::service('session_manager')->destroy();
				return null;
			});
		}

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
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
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
			UsersHelper::sendEmailVerification($user);
		}
		$user->activate();
		$user->setPassword($password);
		$user->enforceIsNew();

		try {
			$user->save();
		} catch (EntityStorageException $e) {
			return GeneralHelper::internalError('Failed to create user: ' . $e->getMessage());
		}

		// Immediately log user in (hooks/metadata) and return persistent API token
		$this->finalizeLogin($user, $request);
		$token = UsersHelper::issueToken($user);
		$data = [
			'user' => UsersHelper::serializeUser($user, $user),
			'session_token' => $token,
		];
		return new JsonResponse($data, Response::HTTP_CREATED);
	}

	#region User Routes

	// GET /v2/users/current
	// GET /v2/users/{id}
	// GET /v2/users/{username}
	public function getUser(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$resolved = $this->resolveUser($request, $id, $username);
		if ($resolved instanceof JsonResponse) {
			return $resolved;
		}
		if (!$resolved) {
			return GeneralHelper::notFound('User not found');
		}
		$visible = UsersHelper::checkVisibility($resolved, $request);
		if ($visible instanceof JsonResponse) {
			return $visible;
		}
		return new JsonResponse(
			UsersHelper::serializeUser($visible, $requester),
			Response::HTTP_OK,
		);
	}

	// PATCH /v2/users/current
	// PATCH /v2/users/{id}
	// PATCH /v2/users/{username}
	public function patchUser(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		return UsersHelper::patchUser($user, $body, $requester);
	}

	// DELETE /v2/users/current
	// DELETE /v2/users/{id}
	// DELETE /v2/users/{username}
	public function deleteUser(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
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
	// PATCH /v2/users/{id}/field_privacy
	// PATCH /v2/users/{username}/field_privacy
	public function patchFieldPrivacy(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		return UsersHelper::patchFieldPrivacy($user, $body, $requester);
	}

	// GET /v2/users/current/profile_photo
	// GET /v2/users/{id}/profile_photo
	// GET /v2/users/{username}/profile_photo
	public function getProfilePhoto(
		Request $request,
		?string $id = null,
		?string $username = null,
	): Response {
		$resolved = $this->resolveUser($request, $id, $username);
		if ($resolved instanceof JsonResponse) {
			return $resolved;
		}
		if (!$resolved) {
			return GeneralHelper::notFound('User not found');
		}
		$visible = UsersHelper::checkVisibility($resolved, $request);
		if ($visible instanceof JsonResponse) {
			return $visible;
		}

		$dataUrl = UsersHelper::getProfilePhoto($visible);
		return GeneralHelper::fromDataURL($dataUrl);
	}

	// PUT /v2/users/current/profile_photo
	// PUT /v2/users/{id}/profile_photo
	// PUT /v2/users/{username}/profile_photo
	public function updateProfilePhoto(
		Request $request,
		?string $id = null,
		?string $username = null,
	): Response {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$dataUrl = UsersHelper::regenerateProfilePhoto($user);
		return GeneralHelper::fromDataURL($dataUrl);
	}

	// PUT /v2/users/current/account_type
	// PUT /v2/users/{id}/account_type
	// PUT /v2/users/{username}/account_type
	public function setAccountType(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		if (!$requester) {
			return GeneralHelper::unauthorized();
		}

		if (!UsersHelper::isAdmin($requester)) {
			return GeneralHelper::forbidden('You do not have permission to perform this action.');
		}

		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$account_type = $request->query->get('type');
		if (!$account_type) {
			return GeneralHelper::badRequest('Missing type');
		}

		$type = AccountType::tryFrom(strtolower($account_type));
		if (!$type) {
			return GeneralHelper::badRequest("Invalid type: $type");
		}

		$ordinal = GeneralHelper::findOrdinal(AccountType::cases(), $type);
		$user->set('field_account_type', $ordinal);
		$user->save();

		return new JsonResponse(UsersHelper::serializeUser($user, $requester), Response::HTTP_OK);
	}

	// GET /v2/users/current/activities
	// GET /v2/users/{id}/activities
	// GET /v2/users/{username}/activities
	public function userActivities(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$resolved = $this->resolveUser($request, $id, $username);
		if ($resolved instanceof JsonResponse) {
			return $resolved;
		}
		if (!$resolved) {
			return GeneralHelper::notFound('User not found');
		}
		$visible = UsersHelper::checkVisibility($resolved, $request);
		if ($visible instanceof JsonResponse) {
			return $visible;
		}

		$activities = UsersHelper::getActivities($visible);
		return new JsonResponse($activities, Response::HTTP_OK);
	}

	// PATCH /v2/users/current/activities
	// PATCH /v2/users/{id}/activities
	// PATCH /v2/users/{username}/activities
	public function setUserActivities(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$activityIds = json_decode((string) $request->getContent(), true) ?: [];
		if (!$activityIds) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (empty($activityIds)) {
			return GeneralHelper::badRequest('No activity IDs provided');
		}

		if (count($activityIds) > 10) {
			return GeneralHelper::badRequest('Cannot set more than 10 activities');
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
	// PUT /v2/users/{id}/activities
	// PUT /v2/users/{username}/activities
	public function addUserActivity(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
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

		if (count($activities) >= 10) {
			return GeneralHelper::badRequest('Cannot have more than 10 activities');
		}

		UsersHelper::addActivity($user, $activity);
		return new JsonResponse(UsersHelper::serializeUser($user, $user), Response::HTTP_OK);
	}

	// DELETE /v2/users/current/activities
	// DELETE /v2/users/{id}/activities
	// DELETE /v2/users/{username}/activities
	public function removeUserActivity(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
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

	// GET /v2/users/current/activities/recommend
	// GET /v2/users/{id}/activities/recommend
	// GET /v2/users/{username}/activities/recommend
	public function recommendUserActivities(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$resolved = $this->resolveAuthorizedUser($request, $id, $username);
		if ($resolved instanceof JsonResponse) {
			return $resolved;
		}

		if (!$resolved) {
			return GeneralHelper::notFound('User not found');
		}

		try {
			$poolLimit = $request->query->getInt('pool_limit', 25);
			if ($poolLimit <= 0 || $poolLimit > 100) {
				return GeneralHelper::badRequest(
					'Invalid pool_limit; must be an integer between 1 and 100',
				);
			}

			$activities = UsersHelper::recommendActivities($resolved, $poolLimit);
			return new JsonResponse($activities, Response::HTTP_OK);
		} catch (UnexpectedValueException $e) {
			return GeneralHelper::badRequest('Invalid pool_limit parameter: ' . $e->getMessage());
		}
	}

	// GET /v2/users/current/friends
	// GET /v2/users/{id}/friends
	// GET /v2/users/{username}/friends
	public function userFriends(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$resolved = $this->resolveUser($request, $id, $username);
		if ($resolved instanceof JsonResponse) {
			return $resolved;
		}
		if (!$resolved) {
			return GeneralHelper::notFound('User not found');
		}
		$visible = UsersHelper::checkVisibility($resolved, $request);
		if ($visible instanceof JsonResponse) {
			return $visible;
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
				$friends = UsersHelper::getMutualFriends($visible, $limit, $page, $search);
				break;
			case 'added':
				$friends = UsersHelper::getAddedFriends($visible, $limit, $page, $search);
				break;
			case 'non_mutual':
				$friends = UsersHelper::getNonMutualFriends($visible, $limit, $page, $search);
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

	// PUT /v2/users/current/friends
	// PUT /v2/users/{id}/friends
	// PUT /v2/users/{username}/friends
	public function addUserFriend(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = $this->resolveAuthorizedUser($request, $id, $username);
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

	// DELETE /v2/users/current/friends
	// DELETE /v2/users/{id}/friends
	// DELETE /v2/users/{username}/friends
	public function removeUserFriend(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = $this->resolveAuthorizedUser($request, $id, $username);
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

	// GET /v2/users/current/circle
	// GET /v2/users/{id}/circle
	// GET /v2/users/{username}/circle
	public function userCircle(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$resolved = $this->resolveUser($request, $id, $username);
		if ($resolved instanceof JsonResponse) {
			return $resolved;
		}
		if (!$resolved) {
			return GeneralHelper::notFound('User not found');
		}
		$visible = UsersHelper::checkVisibility($resolved, $request);
		if ($visible instanceof JsonResponse) {
			return $visible;
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		$circle = UsersHelper::getCircle($visible, $limit, $page, $search);
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

	// PUT /v2/users/current/circle
	// PUT /v2/users/{id}/circle
	// PUT /v2/users/{username}/circle
	public function addUserToCircle(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = $this->resolveAuthorizedUser($request, $id, $username);
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

	// DELETE /v2/users/current/circle
	// DELETE /v2/users/{id}/circle
	// DELETE /v2/users/{username}/circle
	public function removeUserFromCircle(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = $this->resolveAuthorizedUser($request, $id, $username);
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

	#region Email Verification

	// POST /v2/users/current/send_email_verification
	// POST /v2/users/{id}/send_email_verification
	// POST /v2/users/{username}/send_email_verification
	public function sendEmailVerification(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (UsersHelper::isEmailVerified($user)) {
			return GeneralHelper::conflict('Email is already verified');
		}

		return UsersHelper::sendEmailVerification($user);
	}

	// POST /v2/users/current/verify_email
	// POST /v2/users/{id}/verify_email
	// POST /v2/users/{username}/verify_email
	public function verifyEmail(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (UsersHelper::isEmailVerified($user)) {
			return GeneralHelper::conflict('Email is already verified');
		}

		$code = $request->query->get('code');
		if (!$code) {
			return GeneralHelper::badRequest('Verification code is required');
		}

		// Validate code format (8 digits)
		if (!preg_match('/^\d{8}$/', $code)) {
			return GeneralHelper::badRequest('Invalid verification code format');
		}

		/** @var \Drupal\Core\TempStore\PrivateTempStore $tempstore */
		$tempstore = \Drupal::service('mantle2.tempstore.email_verification')->get('mantle2');
		$codeKey = 'email_verification_' . $user->id();

		try {
			$storedData = $tempstore->get($codeKey);
		} catch (\Exception $e) {
			\Drupal::logger('mantle2')->error(
				'Failed to retrieve email verification code: %message',
				[
					'%message' => $e->getMessage(),
				],
			);
			return GeneralHelper::internalError('Failed to verify code');
		}

		if (!$storedData) {
			return GeneralHelper::badRequest('No verification code found or code has expired');
		}

		// Check if code has expired (15 minutes = 900 seconds)
		$currentTime = time();
		if ($currentTime - $storedData['timestamp'] > 900) {
			// Clean up expired code
			try {
				$tempstore->delete($codeKey);
			} catch (\Exception $e) {
				// Log but don't fail the request
				\Drupal::logger('mantle2')->warning(
					'Failed to delete expired verification code: %message',
					[
						'%message' => $e->getMessage(),
					],
				);
			}
			return GeneralHelper::badRequest('Verification code has expired');
		}

		// Verify the code matches
		if ($storedData['code'] !== $code) {
			return GeneralHelper::badRequest('Invalid verification code');
		}

		// Set email as verified
		try {
			$user->set('field_email_verified', true);
			$user->save();
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error(
				'Failed to update email verification status: %message',
				[
					'%message' => $e->getMessage(),
				],
			);
			return GeneralHelper::internalError('Failed to verify email');
		}

		// Clean up used verification code
		try {
			$tempstore->delete($codeKey);
		} catch (\Exception $e) {
			// Log but don't fail the request since verification was successful
			\Drupal::logger('mantle2')->warning(
				'Failed to delete used verification code: %message',
				[
					'%message' => $e->getMessage(),
				],
			);
		}

		return new JsonResponse(
			[
				'message' => 'Email verified successfully',
				'email_verified' => true,
			],
			Response::HTTP_OK,
		);
	}

	#endregion

	#region User Notifications

	// GET /v2/users/current/notifications
	// GET /v2/users/{id}/notifications
	// GET /v2/users/{username}/notifications
	public function userNotifications(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$resolved = $this->resolveAuthorizedUser($request, $id, $username);
		if ($resolved instanceof JsonResponse) {
			return $resolved;
		}

		$notifications = UsersHelper::getNotifications($resolved);
		$unreadCount = count(array_filter($notifications, fn($n) => !$n['read']));
		$hasWarnings = count(array_filter($notifications, fn($n) => $n['type'] === 'warning')) > 0;
		$hasErrors = count(array_filter($notifications, fn($n) => $n['type'] === 'error')) > 0;

		return new JsonResponse(
			[
				'unread_count' => $unreadCount,
				'has_warnings' => $hasWarnings,
				'has_errors' => $hasErrors,
				'items' => $notifications,
			],
			Response::HTTP_OK,
		);
	}

	// GET /v2/users/current/notifications/{notificationId}
	// GET /v2/users/{id}/notifications/{notificationId}
	// GET /v2/users/{username}/notifications/{notificationId}
	public function getUserNotification(
		Request $request,
		string $notificationId,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$notification = UsersHelper::getNotification($user, $notificationId);
		if (!$notification) {
			return GeneralHelper::notFound('Notification not found');
		}

		return new JsonResponse($notification, Response::HTTP_OK);
	}

	// POST /v2/users/current/notifications/{notificationId}/read
	// POST /v2/users/{id}/notifications/{notificationId}/read
	// POST /v2/users/{username}/notifications/{notificationId}/read
	public function markUserNotificationRead(
		Request $request,
		string $notificationId,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$notification = UsersHelper::getNotification($user, $notificationId);
		if (!$notification) {
			return GeneralHelper::notFound('Notification not found');
		}

		if ($notification['read']) {
			return GeneralHelper::conflict('Notification is already marked as read');
		}

		$result = UsersHelper::markNotificationAsRead($user, $notificationId);
		if (!$result) {
			return GeneralHelper::internalError('Failed to mark notification as read');
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// DELETE /v2/users/current/notifications/clear
	// DELETE /v2/users/{id}/notifications/clear
	// DELETE /v2/users/{username}/notifications/clear
	public function clearUserNotifications(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		UsersHelper::clearNotifications($user);
		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	#endregion

	#region Event Routes

	// GET /v2/events/current
	public function userEvents(Request $request): JsonResponse
	{
		$requester = UsersHelper::getOwnerOfRequest($request);
		if (!$requester) {
			return GeneralHelper::unauthorized();
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		$events = UsersHelper::getUserEvents($requester, $limit, $page, $search);
		return new JsonResponse([
			'limit' => $limit,
			'page' => $page + 1,
			'search' => $search,
			'items' => $events,
		]);
	}

	#endregion

	#region Utility Functions

	// Utility Functions

	private function finalizeLogin(UserInterface $account, ?Request $request = null): void
	{
		// Check for new IP address and send notification if needed
		if ($request) {
			$this->checkAndNotifyNewIP($account, $request);
		}

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
		// Ensure session is started before migrating to get/keep a valid ID.
		if (method_exists($session, 'start') && !$session->isStarted()) {
			$session->start();
		}
		if (method_exists($session, 'migrate')) {
			$session->migrate();
		}
		$session->set('uid', $account->id());
		$session->set('check_logged_in', true);
		if (method_exists($session, 'save')) {
			$session->save();
		}
		Drupal::moduleHandler()->invokeAll('user_login', [$account]);
	}

	private function checkAndNotifyNewIP(UserInterface $account, Request $request): void
	{
		$currentIP = $request->getClientIp();
		if (!$currentIP) {
			return; // Cannot determine IP
		}

		// Get previous IP addresses from user field (or create field if it doesn't exist)
		$previousIPs = [];
		if ($account->hasField('field_previous_ips')) {
			$ipData = $account->get('field_previous_ips')->value;
			if ($ipData) {
				$previousIPs = json_decode($ipData, true) ?: [];
			}
		}

		// Check if this is a new IP address
		if (!in_array($currentIP, $previousIPs, true)) {
			// Add notification for new IP login
			UsersHelper::addNotification(
				$account,
				Drupal::translation()->translate('New Login Location'),
				Drupal::translation()->translate(
					"Your account was accessed from a new IP address: {$currentIP}. If this wasn't you, please secure your account immediately.",
				),
				null,
				'warning',
				'system',
			);

			// Update the list of known IPs (keep only last 10 for storage efficiency)
			$previousIPs[] = $currentIP;
			if (count($previousIPs) > 10) {
				$previousIPs = array_slice($previousIPs, -10);
			}

			// Save updated IP list back to user
			if ($account->hasField('field_previous_ips')) {
				$account->set('field_previous_ips', json_encode($previousIPs));
			} else {
				// If field doesn't exist, we'll store it in a keyvalue store instead
				$store = Drupal::service('keyvalue')->get('mantle2_user_ips');
				$store->set((string) $account->id(), $previousIPs);
			}
		}
	}

	private function resolveUser(
		Request $request,
		?string $id,
		?string $username,
	): UserInterface|JsonResponse|null {
		$identifier = $id ?? $username;
		if ($identifier !== null) {
			$user = UsersHelper::findBy($identifier);
			return $user ?: null;
		}

		// Fallback to current user via session/bearer.
		return UsersHelper::findByRequest($request);
	}

	/**
	 * Resolve a user ensuring the requester is authorized to modify it. If no id/username,
	 * uses the current user from the request.
	 */
	private function resolveAuthorizedUser(
		Request $request,
		?string $id,
		?string $username,
	): UserInterface|JsonResponse {
		$identifier = $id ?? $username;
		if ($identifier !== null) {
			return UsersHelper::findByAuthorized($identifier, $request);
		}
		return UsersHelper::findByRequest($request);
	}

	#endregion
}
