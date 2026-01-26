<?php

namespace Drupal\mantle2\Controller;

use DateTimeImmutable;
use Drupal;
use Exception;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Notification;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\mantle2\Controller\Schema\Mantle2Schemas;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\OAuthHelper;
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
		$sort = $pagination['sort'];

		// Determine visibility filter based on requester
		$isAdmin = $requester && UsersHelper::isAdmin($requester);

		try {
			$storage = Drupal::entityTypeManager()->getStorage('user');

			// Handle random sorting separately using database query
			if ($sort === 'rand') {
				$connection = Drupal::database();
				$query = $connection
					->select('users_field_data', 'u')
					->fields('u', ['uid'])
					->condition('u.status', 1)
					->condition('u.uid', 0, '!=');

				$fv = $query->leftJoin('user__field_visibility', 'fv', 'fv.entity_id = u.uid');
				if (!$isAdmin) {
					// only admins can see non-public users
					$query->condition(
						"$fv.field_visibility_value",
						GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
					);
				}

				if ($search) {
					$escapedSearch = Drupal::database()->escapeLike($search);
					$fn = $query->leftJoin('user__field_first_name', 'fn', 'fn.entity_id = u.uid');
					$fl = $query->leftJoin('user__field_last_name', 'fl', 'fl.entity_id = u.uid');

					$group = $query
						->orConditionGroup()
						->condition('u.name', "%$escapedSearch%", 'LIKE')
						->condition("$fn.field_first_name_value", "%$escapedSearch%", 'LIKE')
						->condition("$fl.field_last_name_value", "%$escapedSearch%", 'LIKE');
					$query->condition($group);
				}

				// Get total count for random
				$countQuery = clone $query;
				$total = (int) $countQuery->countQuery()->execute()->fetchField();

				$query->orderRandom()->range($page * $limit, $limit);
				$uids = $query->execute()->fetchCol();
			} else {
				// Use entity query for normal sorting
				$query = $storage->getQuery()->accessCheck(false)->condition('uid', 0, '!='); // Exclude anonymous user

				if (!$isAdmin) {
					// only admins can see non-public users
					$query->condition(
						'field_visibility',
						GeneralHelper::findOrdinal(Visibility::cases(), Visibility::PUBLIC),
						'=',
					);
				}

				if ($search) {
					$group = $query
						->orConditionGroup()
						->condition('name', $search, 'CONTAINS')
						->condition('field_first_name', $search, 'CONTAINS')
						->condition('field_last_name', $search, 'CONTAINS');
					$query->condition($group);
				}

				$countQuery = clone $query;
				$total = (int) $countQuery->count()->execute();

				// Add sorting
				$sortDirection = $sort === 'desc' ? 'DESC' : 'ASC';
				$query->sort('created', $sortDirection);

				$uids = $query->range($page * $limit, $limit)->execute();
			}

			/** @var UserInterface[] $loaded */
			$loaded = $storage->loadMultiple($uids);

			$users = array_filter($loaded, function ($user) use ($request) {
				$res = UsersHelper::checkVisibility($user, $request);
				if ($res instanceof JsonResponse) {
					return false;
				}
				return true;
			});

			$data = array_values(
				array_map(fn($user) => UsersHelper::serializeUser($user, $requester), $users),
			);
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

		/** @var UserInterface $account */
		$account = UsersHelper::findByUsername($name) ?? UsersHelper::findByEmail($name);

		if (!$account || $account->isBlocked()) {
			return GeneralHelper::unauthorized();
		}

		// Check if user has a password set (not OAuth-only user)
		if (!UsersHelper::hasPassword($account)) {
			return GeneralHelper::badRequest(
				'This account was created using OAuth. Please log in using your OAuth provider and set a password first.',
			);
		}

		/** @var UserAuthInterface $userAuth */
		$userAuth = Drupal::service('user.auth');
		$uid = $userAuth->authenticate($name, $pass);
		if (!$uid) {
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

		// Check if this is an OAuth signup
		$oauthProvider = $body['oauth_provider'] ?? null;
		$idToken = $body['id_token'] ?? null;

		if ($oauthProvider && $idToken) {
			// OAuth signup flow
			return $this->createUserWithOAuth($request, $oauthProvider, $idToken, $body);
		}

		// Traditional username/password signup
		$username = trim(strtolower($body['username'] ?? null));
		$password = trim($body['password'] ?? null);
		$email = trim($body['email'] ?? null);
		$firstName = trim($body['first_name'] ?? null);
		$lastName = trim($body['last_name'] ?? null);

		if (!$username || !$password) {
			return GeneralHelper::badRequest('Username and Password are required');
		}

		if (
			!is_string($username) ||
			!is_string($password) ||
			($email && !is_string($email)) ||
			($firstName && !is_string($firstName)) ||
			($lastName && !is_string($lastName))
		) {
			return GeneralHelper::badRequest(
				'Invalid data types for username, password, email, first_name, or last_name',
			);
		}

		if (!preg_match('/' . Mantle2Schemas::$username['pattern'] . '/', $username)) {
			return GeneralHelper::badRequest(
				'Username must be 3-30 characters long and can only contain letters, numbers, underscores, dashes, and periods.',
			);
		}

		if (!preg_match('/' . Mantle2Schemas::$password['pattern'] . '/', $password)) {
			return GeneralHelper::badRequest(
				'Password must be 8-100 characters long and can only contain letters, numbers, and special characters.',
			);
		}

		if ($firstName) {
			if (strlen($firstName) < 2 || strlen($firstName) > 50) {
				return GeneralHelper::badRequest('First name must be between 2 and 50 characters');
			}
		}

		if ($lastName) {
			if (strlen($lastName) < 2 || strlen($lastName) > 50) {
				return GeneralHelper::badRequest('Last name must be between 2 and 50 characters');
			}
		}

		if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return GeneralHelper::badRequest('Invalid email address provided');
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
		if ($firstName) {
			$user->set('field_first_name', $firstName);
		}
		if ($lastName) {
			$user->set('field_last_name', $lastName);
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
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if ($user->id() === 0 || $user->id() === 1) {
			return GeneralHelper::forbidden('Cannot delete the anonymous or root user');
		}

		if ($requester->id() === $user->id()) {
			// Require password for self deletion
			$body = json_decode((string) $request->getContent(), true) ?: [];
			if (!$body) {
				return GeneralHelper::badRequest('Invalid JSON');
			}

			if (json_last_error() !== JSON_ERROR_NONE) {
				return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
			}

			$password = $body['password'] ?? null;
			if (!$password || !is_string($password)) {
				return GeneralHelper::badRequest('Missing or invalid password');
			}

			if (!UsersHelper::validatePassword($user, $password)) {
				return GeneralHelper::badRequest('Password is incorrect');
			}
		}

		if (UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('Cannot delete admin users via the API');
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
		$size = $request->query->getInt('size', 1024);

		if ($size < 32 || $size > 1024) {
			return GeneralHelper::badRequest('Size must be between 32 and 1024');
		}

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

		$dataUrl = UsersHelper::getProfilePhoto($visible, $size);
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
		$sort = $pagination['sort'];

		$filter = $request->query->get('filter') ?? 'added';
		switch ($filter) {
			case 'mutual':
				$friends = UsersHelper::getMutualFriends($visible, $limit, $page, $search, $sort);
				break;
			case 'added':
				$friends = UsersHelper::getAddedFriends($visible, $limit, $page, $search, $sort);
				break;
			case 'non_mutual':
				$friends = UsersHelper::getNonMutualFriends(
					$visible,
					$limit,
					$page,
					$search,
					$sort,
				);
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

		if ($user->id() === $friend->id()) {
			return GeneralHelper::badRequest('Cannot add yourself as a friend');
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

		return new JsonResponse(
			[
				'user' => UsersHelper::serializeUser($user, $requester),
				'friend' => UsersHelper::serializeUser($friend, $requester),
				'is_mutual' => UsersHelper::isMutualFriend($user, $friend),
			],
			Response::HTTP_OK,
		);
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

		if ($user->id() === $friend->id()) {
			return GeneralHelper::badRequest('Cannot remove yourself from your own friends list');
		}

		// remove friend in circle if they are in circle
		if (UsersHelper::isInCircle($user, $friend)) {
			$result = UsersHelper::removeFromCircle($user, $friend);
			if (!$result) {
				Drupal::logger('mantle2')->error(
					'Failed to remove user %friend from circle of user %user while removing friend',
					[
						'friend' => $friend->id(),
						'user' => $user->id(),
					],
				);

				return GeneralHelper::internalError(
					'Failed to remove friend from circle while removing friend',
				);
			}
		}

		$result = UsersHelper::removeFriend($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is not added');
		}

		return new JsonResponse(
			[
				'user' => UsersHelper::serializeUser($user, $requester),
				'friend' => UsersHelper::serializeUser($friend, $requester),
				'is_mutual' => UsersHelper::isMutualFriend($user, $friend),
			],
			Response::HTTP_OK,
		);
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
		$sort = $pagination['sort'];

		$circle = UsersHelper::getCircle($visible, $limit, $page, $search, $sort);
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

		if ($user->id() === $friend->id()) {
			return GeneralHelper::badRequest('Cannot add yourself to your own circle');
		}

		if (!UsersHelper::isAddedFriend($user, $friend)) {
			return GeneralHelper::badRequest('Only friends can be added to circle');
		}

		$count = UsersHelper::getCircleCount($user);
		$max = UsersHelper::getMaxCircleCount($user);
		if ($count >= $max) {
			return GeneralHelper::badRequest('Circle size limit of ' . $max . ' reached');
		}

		$result = UsersHelper::addToCircle($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is already in circle');
		}

		return new JsonResponse(
			[
				'user' => UsersHelper::serializeUser($user, $requester),
				'friend' => UsersHelper::serializeUser($friend, $requester),
				'is_mutual' => UsersHelper::isMutualFriend($user, $friend),
			],
			Response::HTTP_OK,
		);
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

		if ($user->id() === $friend->id()) {
			return GeneralHelper::badRequest('Cannot remove yourself to your own circle');
		}

		if (!UsersHelper::isInCircle($user, $friend)) {
			return GeneralHelper::badRequest('Friend is not in circle');
		}

		$result = UsersHelper::removeFromCircle($user, $friend);
		if (!$result) {
			return GeneralHelper::conflict('Friend is not in circle');
		}

		return new JsonResponse(
			[
				'user' => UsersHelper::serializeUser($user, $requester),
				'friend' => UsersHelper::serializeUser($friend, $requester),
				'is_mutual' => UsersHelper::isMutualFriend($user, $friend),
			],
			Response::HTTP_OK,
		);
	}

	// POST /v2/users/reset_password
	public function resetPassword(Request $request): JsonResponse
	{
		$email = $request->query->get('email');
		if (!$email) {
			return GeneralHelper::badRequest('Missing email');
		}

		// Check rate limit (2 minutes = 120 seconds) per email
		$rateLimitKey = 'password_reset_rate_limit_' . hash('sha256', strtolower($email));
		$lastSentData = RedisHelper::get($rateLimitKey);
		if ($lastSentData) {
			$timeSinceLastSent = time() - $lastSentData['timestamp'];
			$timeRemaining = 120 - $timeSinceLastSent;
			if ($timeRemaining > 0) {
				return new JsonResponse(
					[
						'error' => 'Rate limit exceeded',
						'message' =>
							'Please wait ' .
							$timeRemaining .
							' seconds before requesting another password reset.',
						'retry_after' => $timeRemaining,
					],
					Response::HTTP_TOO_MANY_REQUESTS,
				);
			}
		}

		$user = UsersHelper::findByEmail($email);
		if (!$user) {
			// Store rate limit data even for non-existent users to prevent enumeration
			RedisHelper::set($rateLimitKey, ['timestamp' => time(), 'email' => $email], 120);
			return new JsonResponse(null, Response::HTTP_NO_CONTENT);
		}

		// Store rate limit data for valid requests
		RedisHelper::set($rateLimitKey, ['timestamp' => time(), 'email' => $email], 120);

		UsersHelper::sendPasswordResetEmail($user);

		// Always return 204 to avoid leaking user existence
		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// POST /v2/users/current/change_password
	// POST /v2/users/{id}/change_password
	// POST /v2/users/{username}/change_password
	public function changePassword(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		// Check if user has a password set
		$hasPassword = UsersHelper::hasPassword($user);

		$token = $request->query->get('token');
		$oldPassword = $request->query->get('old_password');

		// For users with existing passwords, require either token or old_password
		if ($hasPassword && !$token && !$oldPassword) {
			return GeneralHelper::badRequest('Missing token or old_password');
		}

		if ($token) {
			$result = UsersHelper::validateResetPasswordToken($user, $token);
			if (!$result) {
				return GeneralHelper::badRequest('Invalid or expired token');
			}
		}

		if ($oldPassword) {
			if (!UsersHelper::validatePassword($user, $oldPassword)) {
				return GeneralHelper::badRequest('Old password is incorrect');
			}
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		$newPassword = $body['new_password'] ?? null;
		if (!$newPassword) {
			return GeneralHelper::badRequest('Missing new_password');
		}

		if (
			!is_string($newPassword) ||
			!preg_match('/' . Mantle2Schemas::$password['pattern'] . '/', $newPassword)
		) {
			return GeneralHelper::badRequest(
				'Password must be 8-100 characters long and can only contain letters, numbers, and special characters.',
			);
		}

		UsersHelper::changePassword($user, $newPassword);
		return new JsonResponse(['message' => 'Password changed successfully'], Response::HTTP_OK);
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

		// Check rate limit (1 minute = 60 seconds)
		$rateLimitKey = 'email_verification_rate_limit_' . $user->id();

		$lastSentData = RedisHelper::get($rateLimitKey);
		if ($lastSentData) {
			$timeSinceLastSent = time() - $lastSentData['timestamp'];
			if ($timeSinceLastSent < 60) {
				$remainingTime = 60 - $timeSinceLastSent;
				$response = new JsonResponse(
					[
						'error' => 'Rate limit exceeded',
						'message' =>
							'Please wait ' .
							$remainingTime .
							' seconds before requesting another verification email',
						'retryAfter' => $remainingTime,
					],
					Response::HTTP_TOO_MANY_REQUESTS,
				);
				$response->headers->set('Retry-After', (string) $remainingTime);
				return $response;
			}
		}

		// Store current timestamp for rate limiting (60 seconds TTL)
		RedisHelper::set(
			$rateLimitKey,
			[
				'timestamp' => time(),
				'user_id' => $user->id(),
			],
			60,
		);

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

		$code = $request->query->get('code');
		if (!$code) {
			return GeneralHelper::badRequest('Verification code is required');
		}

		// Check if this is an email change verification first
		$emailChangeKey = 'email_change_' . $user->id();
		$emailChangeData = RedisHelper::get($emailChangeKey);
		if ($emailChangeData && $emailChangeData['code'] === $code) {
			// This is an email change verification
			return UsersHelper::verifyEmailChange($user, $code);
		}

		// Regular email verification
		if (UsersHelper::isEmailVerified($user)) {
			return GeneralHelper::conflict('Email is already verified');
		}

		// Validate code format (8 digits)
		if (!preg_match('/^\d{8}$/', $code)) {
			return GeneralHelper::badRequest('Invalid verification code format');
		}

		$codeKey = 'email_verification_' . $user->id();
		$storedData = RedisHelper::get($codeKey);

		if (!$storedData) {
			return GeneralHelper::badRequest('No verification code found or code has expired');
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
		RedisHelper::delete($codeKey);

		// Clean up rate limit since email is now verified
		$rateLimitKey = 'email_verification_rate_limit_' . $user->id();
		RedisHelper::delete($rateLimitKey);

		return new JsonResponse(
			[
				'message' => 'Email verified successfully',
				'email_verified' => true,
			],
			Response::HTTP_OK,
		);
	}

	#endregion

	#region Subscription Management

	// POST /v2/users/current/subscribe
	// POST /v2/users/{id}/subscribe
	// POST /v2/users/{username}/subscribe
	public function subscribe(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$wasSubscribed = UsersHelper::isSubscribed($user);
		if ($wasSubscribed) {
			return new JsonResponse(
				[
					'message' => 'User is already subscribed to marketing emails',
					'subscribed' => true,
				],
				Response::HTTP_OK,
			);
		}

		try {
			UsersHelper::setSubscribed($user, true);
			$user->save();
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error('Failed to update subscription status: %message', [
				'%message' => $e->getMessage(),
			]);
			return GeneralHelper::internalError('Failed to update subscription status');
		}

		return new JsonResponse(
			[
				'message' => 'Successfully subscribed to marketing emails',
				'subscribed' => true,
			],
			Response::HTTP_CREATED,
		);
	}

	// POST /v2/users/current/unsubscribe
	// POST /v2/users/{id}/unsubscribe
	// POST /v2/users/{username}/unsubscribe
	public function unsubscribe(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$wasSubscribed = UsersHelper::isSubscribed($user);
		if (!$wasSubscribed) {
			return new JsonResponse(
				[
					'message' => 'User is already unsubscribed from marketing emails',
					'subscribed' => false,
				],
				Response::HTTP_OK,
			);
		}

		try {
			UsersHelper::setSubscribed($user, false);
			$user->save();
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error('Failed to update subscription status: %message', [
				'%message' => $e->getMessage(),
			]);
			return GeneralHelper::internalError('Failed to update subscription status');
		}

		return new JsonResponse(
			[
				'message' => 'Successfully unsubscribed from marketing emails',
				'subscribed' => false,
			],
			Response::HTTP_CREATED,
		);
	}

	// Public Unsubscribe
	// GET /v2/users/unsubscribe
	// POST /v2/users/unsubscribe
	public function publicUnsubscribe(Request $request): JsonResponse
	{
		$token = $request->query->get('token');
		if (!$token) {
			return GeneralHelper::badRequest('Missing unsubscribe token');
		}

		$user = UsersHelper::validateUnsubscribeToken($token);
		if (!$user) {
			return GeneralHelper::badRequest('Invalid or expired unsubscribe token');
		}

		$wasSubscribed = UsersHelper::isSubscribed($user);
		if (!$wasSubscribed) {
			UsersHelper::revokeUnsubscribeToken($token);
			return new JsonResponse(
				[
					'message' => 'You are already unsubscribed from email notifications',
					'subscribed' => false,
				],
				Response::HTTP_OK,
			);
		}

		try {
			UsersHelper::setSubscribed($user, false);
			$user->save();
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error('Failed to unsubscribe user: %message', [
				'%message' => $e->getMessage(),
			]);
			return GeneralHelper::internalError('Failed to unsubscribe');
		}

		UsersHelper::revokeUnsubscribeToken($token);
		return new JsonResponse(
			[
				'message' => 'You have been successfully unsubscribed from email notifications',
				'subscribed' => false,
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
		$unreadCount = count(array_filter($notifications, fn(Notification $n) => !$n->isRead()));
		$hasWarnings =
			count(
				array_filter($notifications, fn(Notification $n) => $n->getType() === 'warning'),
			) > 0;
		$hasErrors =
			count(array_filter($notifications, fn(Notification $n) => $n->getType() === 'error')) >
			0;

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

	// POST /v2/users/current/notifications/mark_all_read
	// POST /v2/users/{id}/notifications/mark_all_read
	// POST /v2/users/{username}/notifications/mark_all_read
	public function markAllUserNotificationsRead(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		UsersHelper::markAllNotificationsAsRead($user);
		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// POST /v2/users/current/notifications/mark_all_unread
	// POST /v2/users/{id}/notifications/mark_all_unread
	// POST /v2/users/{username}/notifications/mark_all_unread
	public function markAllUserNotificationsUnread(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		UsersHelper::markAllNotificationsAsUnread($user);
		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
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

	// POST /v2/users/current/notifications/{notificationId}/mark_read
	// POST /v2/users/{id}/notifications/{notificationId}/mark_read
	// POST /v2/users/{username}/notifications/{notificationId}/mark_read
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

		if ($notification->isRead()) {
			return GeneralHelper::conflict('Notification is already marked as read');
		}

		$result = UsersHelper::markNotificationAsRead($user, $notification);
		if (!$result) {
			return GeneralHelper::internalError('Failed to mark notification as read');
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// POST /v2/users/current/notifications/{notificationId}/mark_unread
	// POST /v2/users/{id}/notifications/{notificationId}/mark_unread
	// POST /v2/users/{username}/notifications/{notificationId}/mark_unread
	public function markUserNotificationUnread(
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

		if (!$notification->isRead()) {
			return GeneralHelper::conflict('Notification is already marked as unread');
		}

		$result = UsersHelper::markNotificationAsUnread($user, $notification);
		if (!$result) {
			return GeneralHelper::internalError('Failed to mark notification as unread');
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// DELETE /v2/users/current/notifications/{notificationId}
	// DELETE /v2/users/{id}/notifications/{notificationId}
	// DELETE /v2/users/{username}/notifications/{notificationId}
	public function deleteUserNotification(
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

		$result = UsersHelper::removeNotification($user, $notification);
		if (!$result) {
			return GeneralHelper::internalError('Failed to delete notification');
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

	#region OAuth Routes

	// POST /v2/users/oauth/google
	public function oauthGoogle(Request $request): JsonResponse
	{
		return $this->handleOAuthLogin($request, 'google');
	}

	// POST /v2/users/oauth/microsoft
	public function oauthMicrosoft(Request $request): JsonResponse
	{
		return $this->handleOAuthLogin($request, 'microsoft');
	}

	// POST /v2/users/oauth/facebook
	public function oauthFacebook(Request $request): JsonResponse
	{
		return $this->handleOAuthLogin($request, 'facebook');
	}

	// POST /v2/users/oauth/discord
	public function oauthDiscord(Request $request): JsonResponse
	{
		return $this->handleOAuthLogin($request, 'discord');
	}

	// POST /v2/users/oauth/github
	public function oauthGitHub(Request $request): JsonResponse
	{
		return $this->handleOAuthLogin($request, 'github');
	}

	// DELETE /v2/users/oauth/google
	public function unlinkOAuthGoogle(Request $request): JsonResponse
	{
		return $this->handleOAuthUnlink($request, 'google');
	}

	// DELETE /v2/users/oauth/microsoft
	public function unlinkOAuthMicrosoft(Request $request): JsonResponse
	{
		return $this->handleOAuthUnlink($request, 'microsoft');
	}

	// DELETE /v2/users/oauth/facebook
	public function unlinkOAuthFacebook(Request $request): JsonResponse
	{
		return $this->handleOAuthUnlink($request, 'facebook');
	}

	// DELETE /v2/users/oauth/discord
	public function unlinkOAuthDiscord(Request $request): JsonResponse
	{
		return $this->handleOAuthUnlink($request, 'discord');
	}

	// DELETE /v2/users/oauth/github
	public function unlinkOAuthGitHub(Request $request): JsonResponse
	{
		return $this->handleOAuthUnlink($request, 'github');
	}

	private function handleOAuthLogin(Request $request, string $provider)
	{
		if (!in_array($provider, OAuthHelper::$providers, true)) {
			return GeneralHelper::badRequest('Unsupported OAuth provider: ' . $provider);
		}

		$body = json_decode((string) $request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		// Accept id_token (Microsoft) or access_token (Discord, GitHub, Facebook)
		$token = $body['id_token'] ?? ($body['access_token'] ?? null);
		if (!$token || !is_string($token)) {
			return GeneralHelper::badRequest('Missing or invalid id_token or access_token');
		}

		$userData = OAuthHelper::validateToken($provider, $token);
		if (!$userData) {
			return GeneralHelper::unauthorized('Invalid OAuth token');
		}

		$isLinking = $request->query->getBoolean('is_linking', false);

		$sessionToken = $body['session_token'] ?? null;
		$sessionUser = null;
		if ($sessionToken && is_string($sessionToken)) {
			$sessionUser = UsersHelper::getUserByToken($sessionToken);
		}

		$existingProviderUser = OAuthHelper::findByProviderSub($provider, $userData['sub']);
		if ($existingProviderUser) {
			$user = $existingProviderUser;
		} else {
			if ($sessionUser) {
				$success = OAuthHelper::linkProvider(
					$sessionUser,
					$provider,
					$userData['sub'],
					$userData,
				);
				if (!$success) {
					return GeneralHelper::internalError('Failed to link OAuth provider');
				}

				// send notification for OAuth provider linked
				$providerName = ucfirst($provider);
				$currentIP = $request->getClientIp() ?? 'Unknown';
				$date = new DateTimeImmutable();
				$timestamp = $date->format(DATE_ATOM);

				UsersHelper::addNotification(
					$sessionUser,
					Drupal::translation()->translate('New OAuth Provider Linked'),
					Drupal::translation()->translate(
						"A new OAuth provider ({$providerName}) has been linked to your account.\n\n" .
							"Time: {$timestamp}\n" .
							"IP: {$currentIP}\n\n" .
							"If this wasn't you, please secure your account immediately.",
					),
					null,
					'info',
					'system',
				);

				if (UsersHelper::isSubscribed($sessionUser)) {
					UsersHelper::sendEmail($sessionUser, 'oauth_provider_linked', [
						'provider' => $provider,
						'time' => $timestamp,
						'ip' => $currentIP,
					]);
				}

				$user = $sessionUser;
			} else {
				// Try email matching
				$emails = $userData['emails'] ?? [];
				$existingEmailUser = null;

				foreach ($emails as $email) {
					$existingEmailUser = UsersHelper::findByEmail($email);
					if ($existingEmailUser) {
						break;
					}
				}

				if ($existingEmailUser) {
					$success = OAuthHelper::linkProvider(
						$existingEmailUser,
						$provider,
						$userData['sub'],
						$userData,
					);
					if (!$success) {
						return GeneralHelper::internalError('Failed to link OAuth provider');
					}

					// send notification for OAuth provider linked
					$providerName = ucfirst($provider);
					$currentIP = $request->getClientIp() ?? 'Unknown';
					$date = new DateTimeImmutable();
					$timestamp = $date->format(DATE_ATOM);

					UsersHelper::addNotification(
						$existingEmailUser,
						Drupal::translation()->translate('New OAuth Provider Linked'),
						Drupal::translation()->translate(
							"A new OAuth provider ({$providerName}) has been linked to your account.\n\n" .
								"Time: {$timestamp}\n" .
								"IP: {$currentIP}\n\n" .
								"If this wasn't you, please secure your account immediately.",
						),
						null,
						'info',
						'system',
					);

					if (UsersHelper::isSubscribed($existingEmailUser)) {
						UsersHelper::sendEmail($existingEmailUser, 'oauth_provider_linked', [
							'provider' => $provider,
							'time' => $timestamp,
							'ip' => $currentIP,
						]);
					}

					$user = $existingEmailUser;
				} else {
					// no existing account found
					if ($isLinking) {
						return GeneralHelper::badRequest(
							'Cannot link OAuth provider: no existing account found with matching email',
						);
					}

					// create new user
					$user = OAuthHelper::findOrCreateUser($provider, $userData);
					if (!$user) {
						Drupal::logger('mantle2')->error(
							'Failed to find or create user for OAuth provider %provider with sub %sub',
							[
								'%provider' => $provider,
								'%sub' => $userData['sub'] ?? 'unknown',
							],
						);
						return GeneralHelper::internalError('Failed to create or find user');
					}
				}
			}
		}
		$this->finalizeLogin($user, $request, true);
		$token = UsersHelper::issueToken($user);

		$data = [
			'user' => UsersHelper::serializeUser($user, $user),
			'session_token' => $token,
		];

		return new JsonResponse($data, Response::HTTP_OK);
	}

	private function handleOAuthUnlink(Request $request, string $provider): JsonResponse
	{
		if (!in_array($provider, OAuthHelper::$providers, true)) {
			return GeneralHelper::badRequest('Unsupported OAuth provider: ' . $provider);
		}

		$user = UsersHelper::getOwnerOfRequest($request);
		if (!$user) {
			return GeneralHelper::unauthorized();
		}

		if (!OAuthHelper::hasProviderLinked($user, $provider)) {
			return GeneralHelper::badRequest(
				"OAuth provider {$provider} is not linked to your account",
			);
		}

		$success = OAuthHelper::unlinkProvider($user, $provider);
		if (!$success) {
			return GeneralHelper::badRequest(
				'Cannot unlink: this is your only login method. Set a password first.',
			);
		}

		// send notification for OAuth provider unlinked
		$providerName = ucfirst($provider);
		$currentIP = $request->getClientIp() ?? 'Unknown';
		$date = new DateTimeImmutable();
		$timestamp = $date->format(DATE_ATOM);

		UsersHelper::addNotification(
			$user,
			Drupal::translation()->translate('OAuth Provider Unlinked'),
			Drupal::translation()->translate(
				"An OAuth provider ({$providerName}) has been unlinked from your account.\n\n" .
					"Time: {$timestamp}\n" .
					"IP: {$currentIP}\n\n" .
					"If this wasn't you, please secure your account immediately.",
			),
			null,
			'info',
			'system',
		);

		if (UsersHelper::isSubscribed($user)) {
			UsersHelper::sendEmail($user, 'oauth_provider_unlinked', [
				'provider' => $provider,
				'time' => $timestamp,
				'ip' => $currentIP,
			]);
		}

		return new JsonResponse(
			[
				'message' => 'OAuth provider unlinked successfully',
				'user' => UsersHelper::serializeUser($user, $user),
			],
			Response::HTTP_OK,
		);
	}

	private function createUserWithOAuth(
		Request $request,
		string $provider,
		string $idToken,
		array $body,
	): JsonResponse {
		if (!in_array($provider, OAuthHelper::$providers)) {
			return GeneralHelper::badRequest("Invalid OAuth provider: {$provider}");
		}

		$userData = OAuthHelper::validateToken($provider, $idToken);
		if (!$userData) {
			return GeneralHelper::unauthorized('Invalid OAuth token');
		}

		$customUsername = isset($body['username']) ? trim(strtolower($body['username'])) : null;
		if ($customUsername) {
			if (!preg_match('/' . Mantle2Schemas::$username['pattern'] . '/', $customUsername)) {
				return GeneralHelper::badRequest(
					'Username must be 3-30 characters long and can only contain letters, numbers, underscores, dashes, and periods.',
				);
			}
			if (UsersHelper::findByUsername($customUsername) !== null) {
				return GeneralHelper::badRequest('Username already exists');
			}
		}

		$existingUser = OAuthHelper::findByProviderSub($provider, $userData['sub']);
		if ($existingUser) {
			return GeneralHelper::conflict('Account already exists with this OAuth provider');
		}

		if ($userData['email'] && UsersHelper::findByEmail($userData['email']) !== null) {
			return GeneralHelper::conflict(
				'An account with this email already exists. Please log in and link your OAuth provider.',
			);
		}

		$user = OAuthHelper::createUserFromOAuth($provider, $userData, $customUsername);
		if (!$user) {
			return GeneralHelper::internalError('Failed to create user');
		}

		$this->finalizeLogin($user, $request);
		$token = UsersHelper::issueToken($user);

		$data = [
			'user' => UsersHelper::serializeUser($user, $user),
			'session_token' => $token,
		];

		return new JsonResponse($data, Response::HTTP_CREATED);
	}

	#endregion

	#region Prompt Routes

	// GET /v2/users/current/prompts
	// GET /v2/users/{id}/prompts
	// GET /v2/users/{username}/prompts
	public function userPrompts(
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

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];
		$sort = $pagination['sort'];

		$data = UsersHelper::getUserPrompts($visible, $limit, $page, $search, $sort);
		$prompts = $data['prompts'];
		$total = (int) $data['total'];

		return new JsonResponse([
			'limit' => $limit,
			'page' => $page + 1,
			'items' => $prompts,
			'total' => $total,
		]);
	}

	#endregion

	#region Article Routes

	// GET /v2/users/current/articles
	// GET /v2/users/{id}/articles
	// GET /v2/users/{username}/articles
	public function userArticles(
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

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];
		$sort = $pagination['sort'];

		$data = UsersHelper::getUserArticles($visible, $limit, $page, $search, $sort);
		$articles = $data['articles'];
		$total = $data['total'];

		return new JsonResponse([
			'limit' => $limit,
			'page' => $page + 1,
			'items' => $articles,
			'total' => $total,
		]);
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
		$sort = $pagination['sort'];

		$data = UsersHelper::getUserEvents($requester, $limit, $page, $search, $sort);
		$events = $data['events'];
		$total = $data['total'];

		return new JsonResponse([
			'limit' => $limit,
			'page' => $page + 1,
			'search' => $search,
			'items' => $events,
			'total' => $total,
		]);
	}

	// GET /v2/users/current/events
	// GET /v2/users/{id}/events
	// GET /v2/users/{username}/events
	public function userHostedEvents(
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

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];
		$sort = $pagination['sort'];

		$data = UsersHelper::getUserHostedEvents($visible, $limit, $page, $search, $sort);
		$events = $data['events'];
		$total = $data['total'];

		return new JsonResponse([
			'limit' => $limit,
			'page' => $page + 1,
			'items' => $events,
			'total' => $total,
		]);
	}

	#endregion

	#region Utility Functions

	// Utility Functions

	private function finalizeLogin(
		UserInterface $account,
		?Request $request = null,
		bool $skipNewIpCheck = false,
	): void {
		// Check for new IP address and send notification if needed
		if ($request && !$skipNewIpCheck) {
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
			$date = new DateTimeImmutable();
			$timestamp = $date->format(DATE_ATOM);
			$ips = implode(
				', ',
				array_filter($request->getClientIps(), fn($ip) => $ip !== $currentIP),
			);
			if (empty($ips)) {
				$ips = 'no other IPs';
			}

			$userAgent = $request->headers->get('User-Agent', 'Unknown Device');
			$referer = $request->headers->get('Referer', 'No Referrer');

			// add notification for new IP login
			UsersHelper::addNotification(
				$account,
				Drupal::translation()->translate('New Login Location'),
				Drupal::translation()->translate(
					"Your account was accessed from a new IP address: {$currentIP}.\n\n" .
						'Additional Addresses used in this request:' .
						"{$ips}\n" .
						"Other Info:\n" .
						"Timestamp: {$timestamp}" .
						"\n" .
						"User Agent: {$userAgent}" .
						"\n" .
						"Referer: {$referer}" .
						"\n" .
						'Accept-Language: ' .
						$request->headers->get('Accept-Language', 'Unknown Language') .
						"\n\n" .
						"If this wasn't you, please secure your account immediately.",
				),
				null,
				'warning',
				'system',
			);

			if (UsersHelper::isSubscribed($account)) {
				// send new login email notification
				UsersHelper::sendEmail($account, 'new_login', [
					'time' => $timestamp,
					'ip' => $currentIP,
					'additional_ips' => $ips,
					'user_agent' => $userAgent,
					'referer' => $referer,
				]);
			}

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
