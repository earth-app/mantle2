<?php

namespace Drupal\mantle2\Controller;

use DateTimeImmutable;
use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\Merge;
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
use Drupal\mantle2\Service\CloudHelper;
use Drupal\mantle2\Service\EventsHelper;
use Drupal\mantle2\Service\OAuthHelper;
use Drupal\mantle2\Service\PointsHelper;
use Drupal\mantle2\Service\ReferralHelper;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Exception\UnexpectedValueException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UsersController extends ControllerBase
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
				$query->condition('status', 1);

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
				if (UsersHelper::isDisabled($user)) {
					return false;
				}

				$res = UsersHelper::checkVisibility($user, $request);
				if ($res instanceof JsonResponse) {
					return false;
				}
				return true;
			});

			$data = array_values(
				array_filter(
					array_map(fn($user) => UsersHelper::serializeUser($user, $requester), $users),
				),
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

		// Allow login with either username or email; resolve to a canonical username for user.auth.
		$account = UsersHelper::findByUsername($name) ?? UsersHelper::findByEmail($name);

		if (!$account) {
			return GeneralHelper::unauthorized();
		}

		if (UsersHelper::isDisabled($account)) {
			return GeneralHelper::forbidden('Account disabled by administrator');
		}

		// Check if user has a password set (not OAuth-only user)
		if (!UsersHelper::hasPassword($account)) {
			return GeneralHelper::badRequest(
				'This account was created using OAuth. Please log in using your OAuth provider and set a password first.',
			);
		}

		/** @var UserAuthInterface $userAuth */
		$userAuth = Drupal::service('user.auth');
		// `user.auth` expects the canonical username; pass that even when the client
		// supplied an email.
		$uid = $userAuth->authenticate($account->getAccountName(), $pass);
		if (!$uid) {
			return GeneralHelper::unauthorized();
		}

		// rate-limit successful token issuance to prevent token spam
		$rateLimitKey = 'login_success_rate_limit_' . $account->id();
		$lastSuccessData = RedisHelper::get($rateLimitKey);
		if ($lastSuccessData && isset($lastSuccessData['timestamp'])) {
			$timeSinceLastSuccess = time() - (int) $lastSuccessData['timestamp'];
			if ($timeSinceLastSuccess < 30) {
				$remainingTime = 30 - $timeSinceLastSuccess;
				$response = new JsonResponse(
					[
						'error' => 'Login token rate limit exceeded',
						'message' =>
							'Login was successful, but a new session token was issued recently. Please wait ' .
							$remainingTime .
							' seconds before requesting another token.',
						'retry_after' => $remainingTime,
					],
					Response::HTTP_CONFLICT,
				);
				$response->headers->set('Retry-After', (string) $remainingTime);
				return $response;
			}
		}

		// New-IP email 2FA: if this client IP is not in the user's known list AND the
		// account has an email address on file, hold the token issuance behind an
		// 8-digit code sent to that email. Accounts without an email skip this step
		// (email is optional).
		if (UsersHelper::shouldGate2FAForNewIP($account, $request)) {
			$ticket = UsersHelper::beginLogin2FAChallenge($account, $request);
			if ($ticket instanceof JsonResponse) {
				return $ticket;
			}

			return new JsonResponse(
				[
					'requires_verification' => true,
					'ticket' => $ticket['ticket'],
					'email' => $ticket['masked_email'],
					'expires_in' => $ticket['expires_in'],
					'message' =>
						'A verification code was emailed to the address on file. Submit it to /v2/users/login/verify_new_ip with the returned ticket to complete sign-in.',
				],
				Response::HTTP_ACCEPTED,
			);
		}

		// log the user in for bookkeeping then issue API token
		$this->finalizeLogin($account, $request);
		$token = UsersHelper::issueToken($account);
		UsersHelper::markReauthenticated($account);
		RedisHelper::set(
			$rateLimitKey,
			[
				'timestamp' => time(),
				'user_id' => $account->id(),
			],
			30,
		);

		$data = [
			'id' => GeneralHelper::formatId($account->id()),
			'username' => $account->getAccountName(),
			'user' => UsersHelper::serializeUser($account, $account),
			'session_token' => $token,
		];
		return new JsonResponse($data, Response::HTTP_OK);
	}

	// POST /v2/users/login/verify_new_ip
	public function verifyLoginNewIP(Request $request): JsonResponse
	{
		$ticket = $request->query->get('ticket');
		$code = $request->query->get('code');

		if (!$ticket || !is_string($ticket)) {
			return GeneralHelper::badRequest('Missing ticket');
		}
		if (!$code || !is_string($code)) {
			return GeneralHelper::badRequest('Missing verification code');
		}
		if (!preg_match('/^\d{8}$/', $code)) {
			return GeneralHelper::badRequest('Invalid verification code format');
		}

		$result = UsersHelper::consumeLogin2FAChallenge($ticket, $code);
		if ($result instanceof JsonResponse) {
			return $result;
		}

		/** @var UserInterface $account */
		$account = $result;

		if (UsersHelper::isDisabled($account)) {
			return GeneralHelper::forbidden('Account disabled by administrator');
		}

		// finalizeLogin records the new IP and emits the standard "New Login" email/notification.
		$this->finalizeLogin($account, $request);
		$token = UsersHelper::issueToken($account);
		UsersHelper::markReauthenticated($account);

		$rateLimitKey = 'login_success_rate_limit_' . $account->id();
		RedisHelper::set(
			$rateLimitKey,
			[
				'timestamp' => time(),
				'user_id' => $account->id(),
			],
			30,
		);

		return new JsonResponse(
			[
				'id' => GeneralHelper::formatId($account->id()),
				'username' => $account->getAccountName(),
				'user' => UsersHelper::serializeUser($account, $account),
				'session_token' => $token,
			],
			Response::HTTP_OK,
		);
	}

	// POST /v2/users/logout
	public function logout(Request $request): JsonResponse
	{
		$sessionId = GeneralHelper::getBearerToken($request);
		if (!$sessionId) {
			return GeneralHelper::unauthorized();
		}

		// Optional body { platform: 'web' | 'ios' | 'android' } so the client can
		// unregister its push token at sign-out. Missing/invalid body is fine —
		// older clients don't send one.
		$platform = null;
		$rawBody = (string) $request->getContent();
		if ($rawBody !== '') {
			$body = json_decode($rawBody, true);
			if (is_array($body) && isset($body['platform']) && is_string($body['platform'])) {
				$platform = $body['platform'];
			}
		}

		// Prefer API token revocation; fall back to PHP session destruction if needed.
		$payloadUser = null;
		$uid = null;
		$tokenUser = UsersHelper::getUserByToken($sessionId);
		if ($tokenUser) {
			$uid = (int) $tokenUser->id();
			$payloadUser = UsersHelper::serializeUser($tokenUser, $tokenUser);
			UsersHelper::revokeToken($sessionId);
			UsersHelper::clearReauthenticated($tokenUser);
		} else {
			$payloadUser = UsersHelper::withSessionId($sessionId, function ($session) use (&$uid) {
				$sessUid = $session->get('uid');
				if (!$sessUid) {
					return null;
				}
				$user = User::load($sessUid);
				if (!$user) {
					return null;
				}
				$uid = (int) $user->id();
				return UsersHelper::serializeUser($user, $user);
			});

			// Destroy that session (legacy behavior).
			UsersHelper::withSessionId($sessionId, function () {
				Drupal::service('session_manager')->destroy();
				return null;
			});
		}

		// Remove the device's push token if the client identified itself.
		// 'web' has no push_tokens row to delete.
		if ($uid !== null && ($platform === 'ios' || $platform === 'android')) {
			try {
				Drupal::database()
					->delete('push_tokens')
					->condition('user_id', $uid)
					->condition('platform', $platform)
					->execute();
			} catch (Exception $e) {
				Drupal::logger('mantle2')->warning(
					'Failed to remove push token on logout (uid %uid, platform %platform): %message',
					[
						'%uid' => $uid,
						'%platform' => $platform,
						'%message' => $e->getMessage(),
					],
				);
			}
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
		$email = trim(strtolower($body['email'] ?? null));
		$firstName = trim($body['first_name'] ?? null);
		$lastName = trim($body['last_name'] ?? null);
		$referralCode = trim($body['referral_code'] ?? '');

		if (!$username || !$password) {
			return GeneralHelper::badRequest('Username and Password are required');
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

		if (GeneralHelper::isFlagged($username)) {
			return GeneralHelper::badRequest('Username contains inappropriate content');
		}

		if ($firstName) {
			if (strlen($firstName) < 2 || strlen($firstName) > 50) {
				return GeneralHelper::badRequest('First name must be between 2 and 50 characters');
			}

			if (GeneralHelper::isFlagged($firstName)) {
				return GeneralHelper::badRequest('First name contains inappropriate content');
			}
		}

		if ($lastName) {
			if (strlen($lastName) < 2 || strlen($lastName) > 50) {
				return GeneralHelper::badRequest('Last name must be between 2 and 50 characters');
			}

			if (GeneralHelper::isFlagged($lastName)) {
				return GeneralHelper::badRequest('Last name contains inappropriate content');
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

		// admin blacklist gate — block known-bad usernames/emails before persisting
		try {
			$usernameCheck = CloudHelper::sendRequest(
				'/v1/admin/blacklist/check?kind=username&value=' . urlencode($username),
			);
			if (!empty($usernameCheck['blacklisted'])) {
				return GeneralHelper::badRequest('This username is not allowed');
			}
			if ($email) {
				$emailCheck = CloudHelper::sendRequest(
					'/v1/admin/blacklist/check?kind=email&value=' . urlencode($email),
				);
				if (!empty($emailCheck['blacklisted'])) {
					return GeneralHelper::badRequest('This email is not allowed');
				}
			}
		} catch (Exception $e) {
			Drupal::logger('mantle2')->warning(
				'Blacklist check failed for signup, allowing through: @msg',
				['@msg' => $e->getMessage()],
			);
			// fail open on cloud unreachable — better UX than blocking all signups
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

		// store referral code as a pending marker; convert only after email verification
		$this->storePendingReferral($user, $referralCode);

		// Immediately log user in (hooks/metadata) and return persistent API token
		$this->finalizeLogin($user, $request);
		$token = UsersHelper::issueToken($user);
		UsersHelper::markReauthenticated($user);
		$data = [
			'user' => UsersHelper::serializeUser($user, $user),
			'id' => GeneralHelper::formatId($user->id()),
			'username' => $user->getAccountName(),
			'session_token' => $token,
		];

		// Send user notification
		UsersHelper::sendEmail(
			$user,
			'welcome',
			[
				'user' => $user,
			],
			false,
		);

		UsersHelper::addNotification(
			$user,
			'Welcome to The Earth App!',
			'Your account has been successfully created. Explore the app and discover new activities to connect with the Earth!',
		);

		// fire-and-forget funnel bump for the admin analytics dashboard
		try {
			CloudHelper::sendRequest('/v1/admin/funnel/signups_completed', 'POST');
		} catch (Exception $e) {
			// analytics is non-critical — never block signup on a cloud blip
		}

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

		if (UsersHelper::isDisabled($resolved) && !UsersHelper::isAdmin($requester)) {
			return GeneralHelper::forbidden('Profile photos are unavailable for disabled accounts');
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
			// recently-authenticated window bypasses password reprompt
			[$recent, $atMs] = UsersHelper::getReauthState($user);

			if (!$recent) {
				$rawBody = (string) $request->getContent();
				$body = $rawBody !== '' ? json_decode($rawBody, true) : [];
				if ($rawBody !== '' && json_last_error() !== JSON_ERROR_NONE) {
					return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
				}
				if (!is_array($body)) {
					$body = [];
				}

				$password = $body['password'] ?? null;
				$hasPassword = UsersHelper::hasPassword($user);

				if (!$password || !is_string($password)) {
					if (!$hasPassword) {
						return new JsonResponse(
							[
								'code' => 403,
								'reason' => 'REAUTH_REQUIRED',
								'message' => 'Reauthentication required',
							],
							Response::HTTP_FORBIDDEN,
						);
					}
					return GeneralHelper::badRequest('Missing or invalid password');
				}

				if (!$hasPassword || !UsersHelper::validatePassword($user, $password)) {
					return GeneralHelper::badRequest('Password is incorrect');
				}
			}
		}

		if (UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('Cannot delete admin users via the API');
		}

		$deletedUid = (int) $user->id();

		try {
			$user->delete();
			CloudHelper::sendRequest('/v1/users/' . $deletedUid, 'DELETE');
		} catch (EntityStorageException $e) {
			return GeneralHelper::internalError('Failed to delete user: ' . $e->getMessage());
		}

		try {
			Drupal::database()->delete('push_tokens')->condition('user_id', $deletedUid)->execute();
		} catch (Exception $e) {
			Drupal::logger('mantle2')->warning(
				'Failed to remove push tokens for deleted user %uid: %message',
				[
					'%uid' => $deletedUid,
					'%message' => $e->getMessage(),
				],
			);
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// GET /v2/users/current/reauth_state
	public function getReauthState(Request $request): JsonResponse
	{
		$user = UsersHelper::getOwnerOfRequest($request);
		if (!$user) {
			return GeneralHelper::unauthorized();
		}

		[$recent, $atMs] = UsersHelper::getReauthState($user);
		$expiresAt = $atMs !== null ? $atMs + UsersHelper::REAUTH_WINDOW_SECONDS * 1000 : null;
		return new JsonResponse(
			[
				'recently_authenticated' => $recent,
				'expires_at' => $recent ? $expiresAt : null,
				'window_seconds' => UsersHelper::REAUTH_WINDOW_SECONDS,
			],
			Response::HTTP_OK,
		);
	}

	// POST /v2/users/current/reauth/password
	public function reauthWithPassword(Request $request): JsonResponse
	{
		$user = UsersHelper::getOwnerOfRequest($request);
		if (!$user) {
			return GeneralHelper::unauthorized();
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		$password = $body['password'] ?? null;
		if (!$password || !is_string($password)) {
			return GeneralHelper::badRequest('Missing or invalid password');
		}

		if (!UsersHelper::hasPassword($user) || !UsersHelper::validatePassword($user, $password)) {
			return GeneralHelper::unauthorized('Password is incorrect');
		}

		UsersHelper::markReauthenticated($user);
		return new JsonResponse(
			[
				'recently_authenticated' => true,
				'expires_at' =>
					(int) (microtime(true) * 1000) + UsersHelper::REAUTH_WINDOW_SECONDS * 1000,
				'window_seconds' => UsersHelper::REAUTH_WINDOW_SECONDS,
			],
			Response::HTTP_OK,
		);
	}

	// POST /v2/users/current/reauth/oauth
	public function reauthWithOAuth(Request $request): JsonResponse
	{
		$user = UsersHelper::getOwnerOfRequest($request);
		if (!$user) {
			return GeneralHelper::unauthorized();
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		$provider = $body['provider'] ?? null;
		if (
			!$provider ||
			!is_string($provider) ||
			!in_array($provider, OAuthHelper::$providers, true)
		) {
			return GeneralHelper::badRequest('Unsupported OAuth provider');
		}

		$token = $body['id_token'] ?? ($body['access_token'] ?? null);
		if (!$token || !is_string($token)) {
			return GeneralHelper::badRequest('Missing OAuth token');
		}

		$userData = OAuthHelper::validateToken($provider, $token);
		if (!$userData || empty($userData['sub'])) {
			return GeneralHelper::unauthorized('Invalid OAuth token');
		}

		// the verified sub must match this account's linked provider sub
		$linkedSub = $user->hasField("field_oauth_{$provider}_sub")
			? $user->get("field_oauth_{$provider}_sub")->value
			: null;
		if (!$linkedSub || $linkedSub !== $userData['sub']) {
			return GeneralHelper::unauthorized('OAuth identity does not match linked account');
		}

		UsersHelper::markReauthenticated($user);
		return new JsonResponse(
			[
				'recently_authenticated' => true,
				'expires_at' =>
					(int) (microtime(true) * 1000) + UsersHelper::REAUTH_WINDOW_SECONDS * 1000,
				'window_seconds' => UsersHelper::REAUTH_WINDOW_SECONDS,
				'provider' => $provider,
			],
			Response::HTTP_OK,
		);
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
		$cosmeticKey = $request->query->get('cosmetic');

		if (!in_array($size, [32, 128, 1024], true)) {
			return GeneralHelper::badRequest('Size must be one of: 32, 128, or 1024');
		}

		if ($resolved instanceof JsonResponse) {
			return $resolved;
		}
		if (!$resolved) {
			return GeneralHelper::notFound('User not found');
		}

		if (UsersHelper::isDisabled($resolved)) {
			return GeneralHelper::forbidden('Account Disabled');
		}

		$visible = UsersHelper::checkVisibility($resolved, $request);
		if ($visible instanceof JsonResponse) {
			return $visible;
		}

		$dataUrl = UsersHelper::getProfilePhoto($visible, $size);

		if ($cosmeticKey) {
			$availableCosmetics = PointsHelper::getAvailableCosmetics($visible);
			if (in_array($cosmeticKey, $availableCosmetics)) {
				$dataUrl = PointsHelper::applyCosmetic($dataUrl, $cosmeticKey);
			}
		}

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

		try {
			$dataUrl = UsersHelper::regenerateProfilePhoto($user);
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to regenerate profile photo: %message', [
				'%message' => $e->getMessage(),
			]);

			// prefer the cloud-surfaced message when present, otherwise the local exception text
			$cloudMessage = CloudHelper::extractCloudMessage($e);
			$message = $cloudMessage !== '' ? $cloudMessage : $e->getMessage();
			return GeneralHelper::internalError($message);
		}

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
			return GeneralHelper::badRequest("Invalid type: $account_type");
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
		$total = 0;
		switch ($filter) {
			case 'mutual':
				$friends = UsersHelper::getMutualFriends($visible, $limit, $page, $search, $sort);
				$total = UsersHelper::getMutualFriendsCount($visible, $requester, $search);
				break;
			case 'added':
				$friends = UsersHelper::getAddedFriends($visible, $limit, $page, $search, $sort);
				$total = UsersHelper::getAddedFriendsCount($visible, $search);
				break;
			case 'added_by':
				$friends = UsersHelper::getAddedBy($visible, $limit, $page, $search, $sort);
				$total = UsersHelper::getAddedByCount($visible, $search);
				break;
			case 'non_mutual':
				$friends = UsersHelper::getNonMutualFriends(
					$visible,
					$limit,
					$page,
					$search,
					$sort,
				);
				$total = UsersHelper::getNonMutualFriendsCount($visible, $search);
				break;
			default:
				return GeneralHelper::badRequest(
					"Invalid filter '$filter'; Must be one of 'mutual', 'added', 'added_by', or 'non_mutual'",
				);
		}

		$data = array_values(
			array_filter(array_map(fn($u) => UsersHelper::serializeUser($u, $requester), $friends)),
		);
		return new JsonResponse(
			[
				'limit' => $limit,
				'page' => $page + 1,
				'search' => $search,
				'items' => $data,
				'total' => $total,
			],
			Response::HTTP_OK,
		);
	}

	// GET /v2/users/current/leaderboard
	// GET /v2/users/{id}/leaderboard
	// GET /v2/users/{username}/leaderboard
	public function userLeaderboard(
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

		if (!$requester) {
			return GeneralHelper::unauthorized('Authentication required');
		}

		$type = $request->query->get('type', 'points');
		if (!in_array($type, ['points', 'article', 'prompt', 'event'], true)) {
			return GeneralHelper::badRequest(
				"Invalid type '$type'; Must be one of 'points', 'article', 'prompt', or 'event'",
			);
		}

		$scope = $request->query->get('scope', 'friends');
		if (!in_array($scope, ['global', 'friends', 'circle'], true)) {
			return GeneralHelper::badRequest(
				"Invalid scope '$scope'; Must be one of 'global', 'friends', or 'circle'",
			);
		}

		$limit = $request->query->getInt('limit', 25);
		if ($limit < 1 || $limit > 250) {
			return GeneralHelper::badRequest('Limit must be between 1 and 250');
		}

		$result = UsersHelper::getScopedLeaderboard($visible, $requester, $type, $scope, $limit);
		return new JsonResponse($result, Response::HTTP_OK);
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
		$data = array_values(
			array_filter(array_map(fn($u) => UsersHelper::serializeUser($u, $requester), $circle)),
		);

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

	// PUT /v2/users/current/account_type/trial
	// PUT /v2/users/{id}/account_type/trial
	// PUT /v2/users/{username}/account_type/trial
	public function createTypeTrial(
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
			return GeneralHelper::badRequest("Invalid type: $account_type");
		}

		$days = $request->query->getInt('days', 7);
		if ($days <= 0 || $days > 90) {
			return GeneralHelper::badRequest('Invalid days value. Must be between 1 and 90.');
		}

		UsersHelper::createTierTrial($user, $type, $days);
		return new JsonResponse(
			[
				'message' =>
					"Trial for account type '$account_type' created successfully for " .
					$days .
					' days.',
				'expires_at' => time() + $days * 24 * 60 * 60,
				'type' => $account_type,
			],
			Response::HTTP_OK,
		);
	}

	#endregion

	#region Engagement Routes

	// GET /v2/users/badges
	public function allBadges(): JsonResponse
	{
		$badges = UsersHelper::getAllBadges();
		return new JsonResponse($badges, Response::HTTP_OK);
	}

	// GET /v2/users/current/badges
	// GET /v2/users/{id}/badges
	// GET /v2/users/{username}/badges
	public function badges(
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

		$requester = UsersHelper::getOwnerOfRequest($request);
		$privacy = UsersHelper::getFieldPrivacy($visible)['badges'] ?? 'PUBLIC';
		if (!UsersHelper::isVisible($visible, $requester, $privacy)) {
			return GeneralHelper::notFound('User not found');
		}

		$badges = UsersHelper::getBadges($visible);
		return new JsonResponse($badges, Response::HTTP_OK);
	}

	// GET /v2/users/current/badges/{badgeId}
	// GET /v2/users/{id}/badges/{badgeId}
	// GET /v2/users/{username}/badges/{badgeId}
	public function badge(
		Request $request,
		?string $id = null,
		?string $username = null,
		?string $badgeId = null,
	): JsonResponse {
		if (!$badgeId) {
			return GeneralHelper::badRequest('Missing badgeId');
		}

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

		$requester = UsersHelper::getOwnerOfRequest($request);
		$privacy = UsersHelper::getFieldPrivacy($visible)['badges'] ?? 'PUBLIC';
		if (!UsersHelper::isVisible($visible, $requester, $privacy)) {
			return GeneralHelper::notFound('User not found');
		}

		$badge = UsersHelper::getBadge($visible, $badgeId);
		if (!$badge) {
			return GeneralHelper::notFound('Badge not found');
		}

		return new JsonResponse($badge, Response::HTTP_OK);
	}

	// GET /v2/users/{id}/badges/{badgeId}/mastery
	// GET /v2/users/{username}/badges/{badgeId}/mastery
	public function badgeMastery(
		Request $request,
		?string $id = null,
		?string $username = null,
		?string $badgeId = null,
	): JsonResponse {
		if (!$badgeId) {
			return GeneralHelper::badRequest('Missing badgeId');
		}

		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		try {
			$data = CloudHelper::sendRequest(
				'/v1/users/badges/' .
					GeneralHelper::formatId($user->id()) .
					'/' .
					$badgeId .
					'/mastery',
			);
		} catch (Exception $e) {
			return CloudHelper::mapCloudException($e, 'Failed to fetch badge mastery status');
		}

		if (empty($data)) {
			return GeneralHelper::notFound('Badge not found');
		}

		return new JsonResponse($data, Response::HTTP_OK);
	}

	// POST /v2/users/{id}/badges/{badgeId}/mastery/generate
	// POST /v2/users/{username}/badges/{badgeId}/mastery/generate
	public function generateBadgeMastery(
		Request $request,
		?string $id = null,
		?string $username = null,
		?string $badgeId = null,
	): JsonResponse {
		if (!$badgeId) {
			return GeneralHelper::badRequest('Missing badgeId');
		}

		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$payload = UsersHelper::buildUserProfilePromptData($user);

		try {
			$data = CloudHelper::sendRequest(
				'/v1/users/badges/' .
					GeneralHelper::formatId($user->id()) .
					'/' .
					$badgeId .
					'/mastery/generate',
				'POST',
				$payload,
				300,
			);
		} catch (Exception $e) {
			$code = (int) $e->getCode();
			$message = CloudHelper::extractCloudMessage($e);

			// cloud emits 429 when the per-user active-mastery cap is hit; surface as 400 so
			// the frontend can show a disabled CTA + countdown without parsing rate-limit headers
			return match ($code) {
				400 => GeneralHelper::badRequest($message ?: 'Bad Request'),
				409 => GeneralHelper::conflict($message ?: 'Conflict'),
				410 => GeneralHelper::gone($message ?: 'Gone'),
				429 => GeneralHelper::badRequest($message ?: 'Active mastery cap reached'),
				default => GeneralHelper::internalError(
					'Mastery generation failed; please try again.',
				),
			};
		}

		// CloudHelper swallows curl timeouts into [] (see sendRequest); detect that here
		// so the client gets a real 504 instead of an empty 201
		if (empty($data) || !isset($data['id'])) {
			return new JsonResponse(
				['error' => 'Mastery generation timed out; please try again.'],
				Response::HTTP_GATEWAY_TIMEOUT,
			);
		}

		return new JsonResponse($data, Response::HTTP_CREATED);
	}

	// GET /v2/users/{id}/badges/masteries
	// GET /v2/users/{username}/badges/masteries
	// GET /v2/users/current/badges/masteries
	public function badgesMasteries(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		try {
			$data = CloudHelper::sendRequest(
				'/v1/users/' . GeneralHelper::formatId($user->id()) . '/badges/masteries',
			);
		} catch (Exception $e) {
			return CloudHelper::mapCloudException($e, 'Failed to list badge masteries');
		}

		return new JsonResponse($data, Response::HTTP_OK);
	}

	// GET /v2/users/current/points
	// GET /v2/users/{id}/points
	// GET /v2/users/{username}/points
	public function points(
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

		$requester = UsersHelper::getOwnerOfRequest($request);
		$privacy = UsersHelper::getFieldPrivacy($visible)['impact_points'] ?? 'PUBLIC';
		if (!UsersHelper::isVisible($visible, $requester, $privacy)) {
			return GeneralHelper::notFound('User not found');
		}

		$data = PointsHelper::getPoints($visible);
		return new JsonResponse(['points' => $data[0], 'history' => $data[1]], Response::HTTP_OK);
	}

	// GET /v2/users/current/referral
	// GET /v2/users/{id}/referral
	// GET /v2/users/{username}/referral
	public function referral(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		return new JsonResponse(['code' => ReferralHelper::getCode($user)], Response::HTTP_OK);
	}

	// GET /v2/users/current/referral/stats
	// GET /v2/users/{id}/referral/stats
	// GET /v2/users/{username}/referral/stats
	public function referralStats(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		return new JsonResponse(ReferralHelper::getStats($user), Response::HTTP_OK);
	}

	// GET /v2/users/cosmetics
	public function getCosmeticsCatalog(Request $request): JsonResponse
	{
		$user = UsersHelper::getOwnerOfRequest($request);
		$catalog = PointsHelper::getCosmeticsCatalog($user);
		return new JsonResponse(['cosmetics' => $catalog], Response::HTTP_OK);
	}

	// GET /v2/users/cosmetics/preview
	public function previewCosmetic(Request $request): Response
	{
		$cosmeticKey = $request->query->get('cosmetic');
		if (!$cosmeticKey || !is_string($cosmeticKey)) {
			return GeneralHelper::badRequest('Missing or invalid cosmetic parameter');
		}

		$cosmetics = PointsHelper::cosmetics();
		if (!isset($cosmetics[$cosmeticKey])) {
			return GeneralHelper::badRequest('Invalid cosmetic key');
		}

		$size = $request->query->getInt('size', 1024);
		if (!in_array($size, [32, 128, 1024], true)) {
			return GeneralHelper::badRequest('Size must be one of: 32, 128, or 1024');
		}

		$withSelf = filter_var($request->query->get('withSelf', 'false'), FILTER_VALIDATE_BOOLEAN);

		$baseUser = UsersHelper::cloud();
		if ($withSelf) {
			$owner = UsersHelper::getOwnerOfRequest($request);
			if ($owner instanceof UserInterface) {
				$baseUser = $owner;
			}
		}

		$dataUrl = PointsHelper::getAvatar($baseUser, $cosmeticKey, $size);

		if (!$dataUrl) {
			return GeneralHelper::internalError('Failed to generate cosmetic preview');
		}

		return GeneralHelper::fromDataURL($dataUrl);
	}

	// DELETE /v2/users/current/profile_photo/cache
	// DELETE /v2/users/{id}/profile_photo/cache
	// DELETE /v2/users/{username}/profile_photo/cache
	// belt-and-suspenders: lets the client flush cosmetic-applied photo entries
	// without triggering a full user cache rebuild
	public function clearProfilePhotoCache(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		try {
			PointsHelper::clearUserPhotoCache((string) $user->id());
		} catch (\Throwable $e) {
			\Drupal::logger('mantle2')->warning(
				'Failed to clear profile photo cache for user %uid: %message',
				[
					'%uid' => $user->id(),
					'%message' => $e->getMessage(),
				],
			);
			return GeneralHelper::internalError('Failed to clear profile photo cache');
		}

		return new JsonResponse(['success' => true], Response::HTTP_OK);
	}

	// GET /v2/users/current/profile_photo/cosmetic
	// GET /v2/users/{id}/profile_photo/cosmetic
	// GET /v2/users/{username}/profile_photo/cosmetic
	public function getUserCosmetics(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$unlocked = PointsHelper::getAvailableCosmetics($user);
		$current = PointsHelper::getAvatarCosmetic($user);

		return new JsonResponse(
			['unlocked' => $unlocked, 'current' => $current],
			Response::HTTP_OK,
		);
	}

	// PUT /v2/users/current/profile_photo/cosmetic
	// PUT /v2/users/{id}/profile_photo/cosmetic
	// PUT /v2/users/{username}/profile_photo/cosmetic
	public function setUserCosmetic(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::getOwnerOfRequest($request);
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode((string) $request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		// null = reset, handled automatically
		$cosmeticKey = $body['current'] ?? null;
		$availableCosmetics = PointsHelper::getAvailableCosmetics($user);
		if ($cosmeticKey !== null && !UsersHelper::isAdmin($requester)) {
			if (!in_array($cosmeticKey, $availableCosmetics, true)) {
				return GeneralHelper::badRequest(
					'You do not own this cosmetic. Please purchase it first.',
				);
			}
		}

		PointsHelper::setAvatarCosmetic($user, $cosmeticKey);

		$unlocked =
			$cosmeticKey !== null
				? $availableCosmetics
				: PointsHelper::getAvailableCosmetics($user);
		$current = PointsHelper::getAvatarCosmetic($user);

		return new JsonResponse(
			['unlocked' => $unlocked, 'current' => $current],
			Response::HTTP_OK,
		);
	}

	// POST /v2/users/current/profile_photo/purchase_cosmetic
	// POST /v2/users/{id}/profile_photo/purchase_cosmetic
	// POST /v2/users/{username}/profile_photo/purchase_cosmetic
	public function purchaseCosmetic(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$cosmeticKey = $request->query->get('key');
		if (!$cosmeticKey || !is_string($cosmeticKey)) {
			return GeneralHelper::badRequest('Missing or invalid key parameter');
		}

		$success = PointsHelper::purchaseCosmetic($user, $cosmeticKey);
		if ($success instanceof JsonResponse) {
			return $success;
		}

		$user = User::load($user->id());
		[$points, $_] = PointsHelper::getPoints($user);
		$unlocked = PointsHelper::getAvailableCosmetics($user);

		return new JsonResponse(
			['success' => true, 'points' => $points, 'unlocked' => $unlocked],
			Response::HTTP_OK,
		);
	}

	// GET /v2/users/quests
	public function quests(Request $request): JsonResponse
	{
		$id = $request->query->get('id', '');

		if ($id) {
			// mastery quest state is per-user and private; require auth so we know whose
			// generated quest to look up (cloud needs userId to resolve badge_mastery_*)
			if (str_starts_with($id, 'badge_mastery_')) {
				$user = UsersHelper::getOwnerOfRequest($request);
				if (!$user) {
					return GeneralHelper::unauthorized(
						'Authentication required to fetch badge mastery quests',
					);
				}

				$quest = PointsHelper::getQuestForUser($id, (string) $user->id());
				if (!$quest) {
					return GeneralHelper::notFound(
						'Mastery quest for badge has not been generated yet',
					);
				}

				return new JsonResponse($quest, Response::HTTP_OK);
			}

			$quest = PointsHelper::getQuest($id);
			if (!$quest) {
				return GeneralHelper::notFound("Quest '$id' not found");
			}

			return new JsonResponse($quest, Response::HTTP_OK);
		}

		$quests = PointsHelper::getAllQuests();
		return new JsonResponse(
			[
				'total' => count($quests),
				'quests' => $quests,
			],
			Response::HTTP_OK,
		);
	}

	// GET /v2/users/current/quest
	// GET /v2/users/{id}/quest
	// GET /v2/users/{username}/quest
	public function userQuests(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$quests = PointsHelper::getCurrentQuest($user);

		return new JsonResponse($quests, Response::HTTP_OK);
	}

	// GET /v2/users/current/quest/step/{step}
	// GET /v2/users/{id}/quest/step/{step}
	// GET /v2/users/{username}/quest/step/{step}
	public function userQuestStep(
		Request $request,
		?string $id = null,
		?string $username = null,
		?string $step = null,
	): JsonResponse {
		if (!$step) {
			return GeneralHelper::badRequest('Missing step parameter');
		}

		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$questStep = PointsHelper::getCurrentQuestStepProgress($user, (int) $step);
		return new JsonResponse($questStep, Response::HTTP_OK);
	}

	// POST /v2/users/current/quest
	// POST /v2/users/{id}/quest
	// POST /v2/users/{username}/quest
	public function startQuest(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$questId = $request->query->get('quest_id');
		$override = $request->query->getBoolean('override', false);
		if (!$questId) {
			return GeneralHelper::badRequest('Missing quest_id parameter');
		}

		$hasOngoing = PointsHelper::hasOngoingQuest($user);
		if ($hasOngoing && !$override) {
			return GeneralHelper::conflict(
				'You already have an ongoing quest. Complete it before starting a new one or set override=true to discard the current quest and start a new one.',
			);
		}

		$result = PointsHelper::startQuest($user, $questId);
		if (!$result) {
			return GeneralHelper::badRequest(
				'Failed to start quest. Please check if the quest_id is valid and you meet the requirements.',
			);
		}

		return new JsonResponse(
			['message' => 'Quest started successfully'],
			Response::HTTP_CREATED,
		);
	}

	// POST /v2/users/current/quest/challenge
	// POST /v2/users/{id}/quest/challenge
	// POST /v2/users/{username}/quest/challenge
	public function challengeFriendToQuest(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
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

		// challenges are friends-only
		if (!UsersHelper::isAddedFriend($user, $friend)) {
			return GeneralHelper::forbidden('You can only challenge friends');
		}

		$questId = $request->query->get('quest');
		if (!$questId) {
			return GeneralHelper::badRequest('Missing quest parameter');
		}

		$quest = PointsHelper::getQuest($questId);
		if (!$quest) {
			return GeneralHelper::badRequest('Invalid quest');
		}

		// rate-limit: max 10 challenges per hour per challenger
		$throttleKey = 'challenge:throttle:' . $user->id();
		$throttle = RedisHelper::get($throttleKey);
		$count = (int) ($throttle['count'] ?? 0);
		if ($count >= 10) {
			return GeneralHelper::conflict(
				'You have sent too many quest challenges; please try again later.',
			);
		}

		// dedupe: one challenge per friend+quest pair per 24h
		$dedupeKey = 'challenge:dedupe:' . $user->id() . ':' . $friend->id() . ':' . $questId;
		if (RedisHelper::exists($dedupeKey)) {
			return GeneralHelper::conflict(
				'You have already challenged this friend to this quest recently.',
			);
		}

		// server-templated message — no free text
		$title = 'Quest Challenge';
		$message = sprintf(
			'%s challenged you to the "%s" quest!',
			$user->getAccountName(),
			$quest->title,
		);
		$link = '/profile/quests?open=' . $questId;
		$source = '@' . $user->getAccountName();

		$notification = UsersHelper::addNotification(
			$friend,
			$title,
			$message,
			$link,
			'info',
			$source,
		);
		if ($notification === null) {
			return GeneralHelper::internalError('Failed to send quest challenge');
		}

		// record dedupe marker and bump the hourly throttle counter
		RedisHelper::set($dedupeKey, ['sent_at' => time()], 86400);
		$ttl = $count > 0 ? max(1, RedisHelper::ttl($throttleKey)) : 3600;
		RedisHelper::set($throttleKey, ['count' => $count + 1], $ttl);

		return new JsonResponse($notification->jsonSerialize(), Response::HTTP_CREATED);
	}

	// <updating quests is handled on the frontend server endpoints for more security>

	// DELETE /v2/users/current/quest
	// DELETE /v2/users/{id}/quest
	// DELETE /v2/users/{username}/quest
	public function cancelQuest(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$hasOngoing = PointsHelper::hasOngoingQuest($user);
		if (!$hasOngoing) {
			return GeneralHelper::conflict('You do not have an ongoing quest to cancel.');
		}

		$result = PointsHelper::resetQuest($user);
		if (!$result) {
			return GeneralHelper::internalError('Failed to cancel quest. Please try again later.');
		}

		return new JsonResponse(['message' => 'Quest cancelled successfully'], Response::HTTP_OK);
	}

	// GET /v2/users/current/onboarding
	// GET /v2/users/{id}/onboarding
	// GET /v2/users/{username}/onboarding
	public function getOnboarding(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		try {
			$data = CloudHelper::sendRequest('/v1/users/onboarding/' . $user->id());
			return new JsonResponse($data, Response::HTTP_OK);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to fetch onboarding state');
		}
	}

	// POST /v2/users/current/onboarding/step
	public function completeOnboardingStep(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body) || empty($body['step']) || !is_string($body['step'])) {
			return GeneralHelper::badRequest('Missing or invalid step');
		}

		try {
			$data = CloudHelper::sendRequest(
				'/v1/users/onboarding/' . $user->id() . '/step',
				'POST',
				['step' => $body['step']],
			);
			return new JsonResponse($data, Response::HTTP_OK);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to record onboarding step');
		}
	}

	// POST /v2/users/current/onboarding/persona
	public function setOnboardingPersona(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$body = json_decode($request->getContent(), true);
		if (
			!is_array($body) ||
			empty($body['persona']) ||
			!is_string($body['persona']) ||
			strlen($body['persona']) > 64
		) {
			return GeneralHelper::badRequest('Missing or invalid persona');
		}

		$interests = $body['interests'] ?? [];
		if (!is_array($interests)) {
			return GeneralHelper::badRequest('Field interests must be an array');
		}

		$interests = array_values(
			array_filter($interests, fn($i) => is_string($i) && $i !== '' && strlen($i) <= 64),
		);

		try {
			$data = CloudHelper::sendRequest(
				'/v1/users/onboarding/' . $user->id() . '/persona',
				'POST',
				['persona' => $body['persona'], 'interests' => array_slice($interests, 0, 20)],
			);
			return new JsonResponse($data, Response::HTTP_OK);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to save onboarding persona');
		}
	}

	// POST /v2/users/current/onboarding/dismiss
	public function dismissOnboarding(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		try {
			$data = CloudHelper::sendRequest(
				'/v1/users/onboarding/' . $user->id() . '/dismiss',
				'POST',
			);
			return new JsonResponse($data, Response::HTTP_OK);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to dismiss onboarding');
		}
	}

	// GET /v2/users/current/quest/history
	// GET /v2/users/{id}/quest/history
	// GET /v2/users/{username}/quest/history
	public function questHistory(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		try {
			$data = CloudHelper::sendRequest(
				'/v1/users/quests/history/' . GeneralHelper::formatId($user->id()),
				'GET',
				$pagination,
			);
		} catch (Exception $e) {
			return CloudHelper::mapCloudException($e, 'Failed to fetch quest history');
		}

		$items = is_array($data['items'] ?? null) ? $data['items'] : [];
		// drop entries the cloud couldn't resolve (expired KV, deleted custom quest, etc.)
		$items = array_values(
			array_filter(
				$items,
				fn($entry) => is_array($entry) &&
					is_array($entry['quest'] ?? null) &&
					isset($entry['quest']['id']),
			),
		);

		// preserve legacy `history: {questId: entry}` shape for clients that read by quest id,
		// plus expose `items: [...]` for paginated rendering
		$history = [];
		foreach ($items as $entry) {
			$history[$entry['quest']['id']] = $entry;
		}

		return new JsonResponse(
			[
				'total' => $data['total'] ?? count($items),
				'page' => $data['page'] ?? 1,
				'limit' => $data['limit'] ?? count($items),
				'items' => $items,
				'history' => $history,
			],
			Response::HTTP_OK,
		);
	}

	// GET /v2/users/current/quest/history/{quest_id}
	// GET /v2/users/{id}/quest/history/{quest_id}
	// GET /v2/users/{username}/quest/history/{quest_id}
	public function questHistoryEntry(
		Request $request,
		?string $id = null,
		?string $username = null,
		?string $quest_id = null,
	): JsonResponse {
		if (!$quest_id) {
			return GeneralHelper::badRequest('Missing quest_id');
		}

		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		try {
			$data = CloudHelper::sendRequest(
				'/v1/users/quests/history/' .
					GeneralHelper::formatId($user->id()) .
					'/' .
					$quest_id,
			);
		} catch (Exception $e) {
			return CloudHelper::mapCloudException($e, 'Failed to fetch quest history entry');
		}

		if (empty($data)) {
			return GeneralHelper::notFound('Quest history entry not found');
		}

		return new JsonResponse($data, Response::HTTP_OK);
	}

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

		// badges: 'verified'
		UsersHelper::grantBadge($user, 'verified');

		// attribute any pending referral now that the user is a verified human
		$this->attributePendingReferral($user);

		// fire-and-forget funnel bump for the admin analytics dashboard
		try {
			CloudHelper::sendRequest('/v1/admin/funnel/verifications_completed', 'POST');
		} catch (Exception $e) {
			// analytics is non-critical
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

	// POST /v2/users/{id}/notifications
	// POST /v2/users/{username}/notifications
	public function createUserNotification(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('Only admins can create notifications for other users');
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		if (!$body) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		$title = $body['title'] ?? null;
		$description = $body['description'] ?? null;
		$type = $body['type'] ?? 'info';
		$link = $body['link'] ?? null;
		$source = $body['source'] ?? 'system';

		if (!$title || !$description) {
			return GeneralHelper::badRequest('Missing title or description');
		}

		if (!is_string($type) || $type === '') {
			$type = 'info';
		}

		if (!is_string($source) || $source === '') {
			$source = 'system';
		}

		$notification = UsersHelper::addNotification(
			$user,
			$title,
			$description,
			$link,
			$type,
			$source,
		);

		// null means notification was ignored
		if ($notification !== null) {
			return new JsonResponse($notification, Response::HTTP_CREATED);
		} else {
			return new JsonResponse(null, Response::HTTP_NO_CONTENT);
		}
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

	// POST /v2/users/current/notifications/push
	public function registerPushToken(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			// findByRequest already logged the specific auth failure reason.
			return $user;
		}

		$uid = $user->id();

		$userAgent = $request->headers->get('User-Agent', '');
		if (!$userAgent) {
			Drupal::logger('mantle2')->warning(
				'registerPushToken rejected: missing User-Agent header (uid %uid)',
				['%uid' => $uid],
			);
			return GeneralHelper::badRequest('Missing User-Agent header');
		}

		$data = json_decode((string) $request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			Drupal::logger('mantle2')->warning(
				'registerPushToken rejected: invalid JSON body (uid %uid, error %error)',
				[
					'%uid' => $uid,
					'%error' => json_last_error_msg(),
				],
			);
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		$token = $data['token'] ?? null;
		$platform = $data['platform'] ?? null;
		if (!$token || !is_string($token)) {
			Drupal::logger('mantle2')->warning(
				'registerPushToken rejected: token field missing or non-string (uid %uid, type %type)',
				[
					'%uid' => $uid,
					'%type' => gettype($token),
				],
			);
			return GeneralHelper::badRequest('Missing or invalid token');
		}

		if (strlen($token) > 512 || strlen($token) < 10) {
			Drupal::logger('mantle2')->warning(
				'registerPushToken rejected: token length %len out of range [10, 512] (uid %uid)',
				[
					'%uid' => $uid,
					'%len' => strlen($token),
				],
			);
			return GeneralHelper::badRequest('Invalid token');
		}

		if (!$platform || !is_string($platform)) {
			Drupal::logger('mantle2')->warning(
				'registerPushToken rejected: platform field missing or non-string (uid %uid, type %type)',
				[
					'%uid' => $uid,
					'%type' => gettype($platform),
				],
			);
			return GeneralHelper::badRequest('Missing or invalid platform');
		}

		if ($platform !== 'ios' && $platform !== 'android') {
			Drupal::logger('mantle2')->warning(
				'registerPushToken rejected: invalid platform value (uid %uid, platform %platform)',
				[
					'%uid' => $uid,
					'%platform' => $platform,
				],
			);
			return GeneralHelper::badRequest(
				'Platform must be either "ios" or "android," found "' . $platform . '"',
			);
		}

		try {
			$mergeResult = Drupal::database()
				->merge('push_tokens')
				->keys([
					'user_id' => $uid,
					'platform' => $platform,
				])
				->fields([
					'token' => $token,
					'updated' => time(),
				])
				->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error(
				'registerPushToken db merge failed (uid %uid, platform %platform, token_length %len): %class :: %message',
				[
					'%uid' => $uid,
					'%platform' => $platform,
					'%len' => strlen($token),
					'%class' => get_class($e),
					'%message' => $e->getMessage(),
				],
			);
			return GeneralHelper::badRequest('Failed to register push notification token');
		}

		try {
			// remove when token exists for other users
			Drupal::database()
				->delete('push_tokens')
				->condition('token', $token)
				->condition('user_id', $uid, '<>')
				->execute();

			// remove when token exists on other platform for same user
			Drupal::database()
				->delete('push_tokens')
				->condition('token', $token)
				->condition('user_id', $uid)
				->condition('platform', $platform, '<>')
				->execute();
		} catch (Exception $e) {
			Drupal::logger('mantle2')->warning(
				'Failed to cleanup duplicate push token entries (uid %uid): %message',
				[
					'%uid' => $uid,
					'%message' => $e->getMessage(),
				],
			);
		}

		return new JsonResponse(
			['message' => 'Push notification token registered successfully'],
			Response::HTTP_OK,
		);
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

	// POST /v2/users/oauth/apple
	public function oauthApple(Request $request): JsonResponse
	{
		return $this->handleOAuthLogin($request, 'apple');
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

	// DELETE /v2/users/oauth/apple
	public function unlinkOAuthApple(Request $request): JsonResponse
	{
		return $this->handleOAuthUnlink($request, 'apple');
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

		// Accept id_token (Microsoft, Apple) or access_token (Discord, GitHub, Facebook)
		$token = $body['id_token'] ?? ($body['access_token'] ?? null);
		if (!$token || !is_string($token)) {
			return GeneralHelper::badRequest('Missing or invalid id_token or access_token');
		}

		$userData = OAuthHelper::validateToken($provider, $token);
		if (!$userData) {
			return GeneralHelper::unauthorized('Invalid OAuth token');
		}

		$bodyEmail = is_string($body['email'] ?? null) ? trim($body['email']) : null;
		$bodyGivenName = is_string($body['given_name'] ?? null) ? trim($body['given_name']) : null;
		$bodyFamilyName = is_string($body['family_name'] ?? null)
			? trim($body['family_name'])
			: null;

		$isLinking = $request->query->getBoolean('is_linking', false);

		$sessionToken = $body['session_token'] ?? null;
		$sessionUser = null;
		if ($sessionToken && is_string($sessionToken)) {
			$sessionUser = UsersHelper::getUserByToken($sessionToken);
			if ($sessionUser instanceof UserInterface && UsersHelper::isDisabled($sessionUser)) {
				return GeneralHelper::forbidden('Account disabled by administrator');
			}
		}

		$existingProviderUser = OAuthHelper::findByProviderSub($provider, $userData['sub']);
		if ($existingProviderUser) {
			$user = $existingProviderUser;
		} else {
			if ($sessionUser) {
				$autoSetEmail = null;
				$success = OAuthHelper::linkProvider(
					$sessionUser,
					$provider,
					$userData['sub'],
					$userData,
					$autoSetEmail,
				);
				if (!$success) {
					return GeneralHelper::internalError('Failed to link OAuth provider');
				}

				UsersHelper::clearCachedUserResponses((int) $sessionUser->id());

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

				if ($autoSetEmail !== null) {
					$this->notifyOAuthEmailAutoSet($sessionUser, $provider, $autoSetEmail);
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
					$autoSetEmail = null;
					$success = OAuthHelper::linkProvider(
						$existingEmailUser,
						$provider,
						$userData['sub'],
						$userData,
						$autoSetEmail,
					);
					if (!$success) {
						return GeneralHelper::internalError('Failed to link OAuth provider');
					}

					UsersHelper::clearCachedUserResponses((int) $existingEmailUser->id());

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

					if ($autoSetEmail !== null) {
						$this->notifyOAuthEmailAutoSet(
							$existingEmailUser,
							$provider,
							$autoSetEmail,
						);
					}

					$user = $existingEmailUser;
				} else {
					// no existing account found
					if ($isLinking) {
						return GeneralHelper::badRequest(
							'Cannot link OAuth provider: no existing account found with matching email',
						);
					}

					// merge client-supported profile fields on trusted new signups
					if (!empty($bodyEmail) && empty($userData['email'])) {
						$userData['email'] = $bodyEmail;
						$userData['emails'] = array_values(
							array_unique(array_merge($userData['emails'] ?? [], [$bodyEmail])),
						);
					}
					if (!empty($bodyGivenName) && empty($userData['given_name'])) {
						$userData['given_name'] = $bodyGivenName;
					}
					if (!empty($bodyFamilyName) && empty($userData['family_name'])) {
						$userData['family_name'] = $bodyFamilyName;
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
		if (UsersHelper::isDisabled($user)) {
			return GeneralHelper::forbidden('Account disabled by administrator');
		}

		$this->finalizeLogin($user, $request, true);
		$token = UsersHelper::issueToken($user);
		UsersHelper::markReauthenticated($user);

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

		UsersHelper::clearCachedUserResponses((int) $user->id());

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

		// store referral code as a pending marker; convert only after email verification
		$this->storePendingReferral($user, trim($body['referral_code'] ?? ''));

		// OAuth emails are pre-verified at creation, so attribute now rather than waiting
		// on a verify_email flow that never fires for these users
		if (UsersHelper::isEmailVerified($user)) {
			$this->attributePendingReferral($user);
		}

		$this->finalizeLogin($user, $request);
		$token = UsersHelper::issueToken($user);
		UsersHelper::markReauthenticated($user);

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

		$requester = UsersHelper::getOwnerOfRequest($request);
		$data = UsersHelper::getUserHostedEvents($visible, $limit, $page, $search, $sort);
		$nodes = $data['nodes'];
		$total = $data['total'];

		// Serialize events with IDs
		$events = array_map(function ($node) use ($requester) {
			$event = EventsHelper::nodeToEvent($node);
			return EventsHelper::serializeEvent($event, $node, $requester);
		}, $nodes);

		return new JsonResponse([
			'limit' => $limit,
			'page' => $page + 1,
			'items' => $events,
			'total' => $total,
		]);
	}

	#endregion

	#region Poll Routes

	// GET /v2/users/current/poll
	// GET /v2/users/{id}/poll
	// GET /v2/users/{username}/poll
	public function getUserPolls(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$polls = UsersHelper::getUserVotes((int) $user->id());
		return new JsonResponse(['items' => $polls], Response::HTTP_OK);
	}

	// POST /v2/users/current/poll
	// POST /v2/users/{id}/poll
	// POST /v2/users/{username}/poll
	public function submitVote(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::findByRequest($request);
		if ($requester instanceof JsonResponse) {
			return $requester;
		}

		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		// only self may submit a vote on behalf of "this user" — admins can read, not impersonate
		if ((int) $requester->id() !== (int) $user->id()) {
			return GeneralHelper::forbidden('You may only submit votes as yourself.');
		}

		if (UsersHelper::isPollRateLimited((int) $user->id())) {
			return GeneralHelper::conflict(
				'Too many votes in a short window. Please wait a moment.',
			);
		}

		$body = json_decode((string) $request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$pollId = UsersHelper::sanitizePollId($body['poll_id'] ?? null);
		if (!$pollId) {
			return GeneralHelper::badRequest(
				'Invalid or missing poll_id (1–64 lowercase alphanum, _ or -)',
			);
		}

		$optionIndex = $body['option_index'] ?? null;
		if (!is_int($optionIndex) || $optionIndex < 0) {
			return GeneralHelper::badRequest('option_index must be a non-negative integer');
		}

		$question = $body['question'] ?? '';
		if (!is_string($question) || trim($question) === '') {
			return GeneralHelper::badRequest('question is required');
		}
		$question = mb_substr(trim($question), 0, 240);

		$options = $body['options'] ?? null;
		if (!is_array($options) || count($options) < 2) {
			return GeneralHelper::badRequest('options must be an array of at least 2 strings');
		}

		// pre-validate non-empty option strings BEFORE handing to recordVote so we can return the
		// specific error rather than the generic "Invalid options or option_index" path
		$nonEmptyOptions = array_filter($options, fn($o) => is_string($o) && trim($o) !== '');
		if (count($nonEmptyOptions) < 2) {
			return GeneralHelper::badRequest('options must contain at least 2 non-empty strings');
		}

		// option_index bounds check — without this a malformed payload can write a sparse counts
		// array to Redis, which breaks the frontend percentage math on the subsequent aggregate read
		if ($optionIndex >= count($options)) {
			return GeneralHelper::badRequest(
				'option_index must be less than the number of options',
			);
		}

		try {
			$result = UsersHelper::recordVote(
				(int) $user->id(),
				$pollId,
				$optionIndex,
				$question,
				$options,
			);
		} catch (\InvalidArgumentException $e) {
			return GeneralHelper::badRequest($e->getMessage());
		}

		return new JsonResponse($result, Response::HTTP_OK);
	}

	// DELETE /v2/users/current/poll
	// DELETE /v2/users/{id}/poll
	// DELETE /v2/users/{username}/poll
	public function retractVote(
		Request $request,
		?string $id = null,
		?string $username = null,
	): JsonResponse {
		$requester = UsersHelper::findByRequest($request);
		if ($requester instanceof JsonResponse) {
			return $requester;
		}

		$user = $this->resolveAuthorizedUser($request, $id, $username);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if ((int) $requester->id() !== (int) $user->id()) {
			return GeneralHelper::forbidden('You may only retract your own votes.');
		}

		if (UsersHelper::isPollRateLimited((int) $user->id())) {
			return GeneralHelper::conflict(
				'Too many vote changes in a short window. Please wait a moment.',
			);
		}

		$body = json_decode((string) $request->getContent(), true) ?: [];
		$pollId = UsersHelper::sanitizePollId(is_array($body) ? $body['poll_id'] ?? null : null);
		if (!$pollId) {
			$pollId = UsersHelper::sanitizePollId($request->query->get('poll_id'));
		}
		if (!$pollId) {
			return GeneralHelper::badRequest('poll_id is required');
		}

		$removed = UsersHelper::retractVote((int) $user->id(), $pollId);
		if (!$removed) {
			return GeneralHelper::notFound('No vote on this poll to retract.');
		}

		return new JsonResponse(['removed' => true, 'poll_id' => $pollId], Response::HTTP_OK);
	}

	// GET /v2/admin/polls
	public function getGlobalPolls(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}
		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('Admin only.');
		}

		return new JsonResponse(['items' => UsersHelper::getGlobalAggregates()], Response::HTTP_OK);
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
		if (!$session->isStarted()) {
			$session->start();
		}
		$session->migrate();
		$session->set('uid', $account->id());
		$session->set('check_logged_in', true);
		$session->save();
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

	// store a referral code as a pending marker; cloud convert is deferred until verification
	private function storePendingReferral(UserInterface $user, string $referralCode): void
	{
		if ($referralCode === '') {
			return;
		}

		try {
			$user->set('field_referrer_id', $referralCode);
			$user->save();
		} catch (Exception $e) {
			// referral attribution is non-critical — never block signup on it
			Drupal::logger('mantle2')->warning(
				'Failed to store pending referral for user %uid: %message',
				[
					'%uid' => $user->id(),
					'%message' => $e->getMessage(),
				],
			);
		}
	}

	private function attributePendingReferral(UserInterface $user): void
	{
		$pending = trim($user->get('field_referrer_id')->value ?? '');
		// already a numeric referrer id (or empty) means nothing to attribute
		if ($pending === '' || ctype_digit($pending)) {
			return;
		}

		$referrerId = ReferralHelper::attributeReferral($user, $pending);
		if ($referrerId === null) {
			return;
		}

		try {
			$user->set('field_referrer_id', $referrerId);
			$user->save();
		} catch (Exception $e) {
			Drupal::logger('mantle2')->warning(
				'Failed to persist attributed referrer for user %uid: %message',
				[
					'%uid' => $user->id(),
					'%message' => $e->getMessage(),
				],
			);
		}
	}

	private function notifyOAuthEmailAutoSet(
		UserInterface $user,
		string $provider,
		string $newEmail,
	): void {
		$providerName = ucfirst($provider);

		UsersHelper::addNotification(
			$user,
			Drupal::translation()->translate('Email Address Set'),
			Drupal::translation()->translate(
				'Your account did not have an email address on file. ' .
					"It has been automatically set to {$newEmail} based on the one present in {$providerName}.\n\n" .
					'You can change this from your account settings.',
			),
			null,
			'info',
			'system',
		);

		UsersHelper::sendEmail(
			$user,
			'oauth_email_auto_set',
			[
				'provider' => $provider,
				'new_email' => $newEmail,
				'user' => $user,
			],
			false,
		);
	}

	#endregion

	// GET /v2/users/{id}/share/quest/{questId}
	// public share card (PNG) for a completed quest; cacheable, no auth so social
	// crawlers can fetch it for link previews
	public function shareQuestCard(Request $request, string $id, string $questId): Response
	{
		$user = UsersHelper::findById((int) $id);
		if (!$user || UsersHelper::isDisabled($user)) {
			return GeneralHelper::notFound('User not found');
		}

		$quest = PointsHelper::getQuest($questId);
		if (!$quest) {
			return GeneralHelper::notFound('Quest not found');
		}

		$dataUrl = PointsHelper::renderQuestShareCard($user, $quest);
		return GeneralHelper::fromDataURL($dataUrl);
	}
}
