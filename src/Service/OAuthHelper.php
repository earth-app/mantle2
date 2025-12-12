<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Exception;
use Drupal\openid_connect\Plugin\OpenIDConnectClientManager;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\Core\Entity\EntityStorageException;

class OAuthHelper
{
	public static array $providers = ['microsoft', 'discord', 'github', 'facebook'];

	public static function validateToken(string $provider, string $token): ?array
	{
		/** @var OpenIDConnectClientManager $clientManager */
		$clientManager = Drupal::service('plugin.manager.openid_connect_client');

		try {
			$client = $clientManager->createInstance($provider);
			$userInfo = $client->retrieveUserInfo($token);

			if (!$userInfo) {
				Drupal::logger('mantle2')->error(
					'OAuth token validation failed: no user info returned for provider %provider',
					[
						'%provider' => $provider,
					],
				);
				return null;
			}

			$emails = [];
			if (!empty($userInfo['email'])) {
				$emails[] = $userInfo['email'];
			}

			if (!empty($userInfo['emails']) && is_array($userInfo['emails'])) {
				foreach ($userInfo['emails'] as $emailData) {
					if (is_array($emailData) && !empty($emailData['email'])) {
						$emails[] = $emailData['email'];
					} elseif (is_string($emailData)) {
						$emails[] = $emailData;
					}
				}
			}
			$emails = array_unique($emails);

			return [
				'sub' => $userInfo['sub'] ?? ($userInfo['id'] ?? null),
				'email' => $userInfo['email'] ?? null,
				'emails' => $emails, // all available emails
				'name' => $userInfo['name'] ?? null,
				'given_name' => $userInfo['given_name'] ?? null,
				'family_name' => $userInfo['family_name'] ?? null,
				'picture' => $userInfo['picture'] ?? null,
			];
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('OAuth validation failed: %message', [
				'%message' => $e->getMessage(),
			]);
			return null;
		}
	}

	public static function findOrCreateUser(string $provider, array $userData): ?UserInterface
	{
		$sub = $userData['sub'];
		$existing = self::findByProviderSub($provider, $sub);

		if ($existing) {
			return $existing;
		}

		return self::createUserFromOAuth($provider, $userData);
	}

	public static function findByProviderSub(string $provider, string $sub): ?UserInterface
	{
		try {
			$users = Drupal::entityTypeManager()
				->getStorage('user')
				->loadByProperties([
					"field_oauth_{$provider}_sub" => $sub,
				]);

			return $users ? reset($users) : null;
		} catch (Exception $e) {
			return null;
		}
	}

	public static function createUserFromOAuth(
		string $provider,
		array $userData,
		?string $customUsername = null,
	): ?UserInterface {
		try {
			$email = $userData['email'] ?? null;
			$givenName = $userData['given_name'] ?? null;
			$familyName = $userData['family_name'] ?? null;

			if ($customUsername) {
				$username = $customUsername;
			} else {
				if ($email) {
					$baseUsername = explode('@', $email)[0];
				} elseif ($givenName) {
					$baseUsername = strtolower($givenName);
				} else {
					// Fallback: use provider + random suffix
					$baseUsername = $provider . '_user_' . bin2hex(random_bytes(4));
				}
				$username = self::generateUniqueUsername($baseUsername);
			}

			$user = User::create([
				'name' => $username,
				'status' => 1,
			]);

			if ($email) {
				$user->setEmail($email);
				$user->set('field_email_verified', true); // OAuth emails are pre-verified
			}

			if ($givenName) {
				$user->set('field_first_name', $givenName);
			}
			if ($familyName) {
				$user->set('field_last_name', $familyName);
			}

			// store OAuth provider sub
			$user->set("field_oauth_{$provider}_sub", $userData['sub']);
			$user->activate();

			$user->save();
			return $user;
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to create OAuth user: %message', [
				'%message' => $e->getMessage(),
			]);
			return null;
		}
	}

	// link OAuth provider to existing user
	public static function linkProvider(
		UserInterface $user,
		string $provider,
		string $sub,
		array $userData = [],
	): bool {
		// check if provider is already linked to another account
		$existingUser = self::findByProviderSub($provider, $sub);
		if ($existingUser && $existingUser->id() !== $user->id()) {
			return false; // Provider already linked to different account
		}

		$user->set("field_oauth_{$provider}_sub", $sub);

		if (!$user->getEmail() && !empty($userData['email'])) {
			$user->setEmail($userData['email']);
			$user->set('field_email_verified', true);
		}

		try {
			$user->save();
			return true;
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error('Failed to link OAuth provider: %message', [
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	public static function hasProviderLinked(UserInterface $user, string $provider): bool
	{
		// Check if field exists before trying to access it
		if (!$user->hasField("field_oauth_{$provider}_sub")) {
			return false;
		}

		$sub = $user->get("field_oauth_{$provider}_sub")->value ?? null;
		return !empty($sub);
	}

	public static function getLinkedProviders(UserInterface $user): array
	{
		$linked = [];

		foreach (self::$providers as $provider) {
			if (self::hasProviderLinked($user, $provider)) {
				$linked[] = $provider;
			}
		}

		return $linked;
	}

	// unlink OAuth provider from user
	public static function unlinkProvider(UserInterface $user, string $provider): bool
	{
		// validate provider is supported
		if (!in_array($provider, self::$providers)) {
			return false;
		}

		// check if provider is actually linked
		if (!self::hasProviderLinked($user, $provider)) {
			return false;
		}

		// safety check: ensure user has another way to log in
		$linkedProviders = self::getLinkedProviders($user);
		$hasPassword = UsersHelper::hasPassword($user);

		// prevent unlinking if this is the only login method
		if (count($linkedProviders) === 1 && !$hasPassword) {
			return false;
		}

		$user->set("field_oauth_{$provider}_sub", null);

		try {
			$user->save();
			return true;
		} catch (EntityStorageException $e) {
			Drupal::logger('mantle2')->error('Failed to unlink OAuth provider: %message', [
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	// find unique username based on base string
	private static function generateUniqueUsername(string $base): string
	{
		$username = preg_replace('/[^a-z0-9_.-]/', '', strtolower($base));
		$username = substr($username, 0, 30);

		if (UsersHelper::findByUsername($username) === null) {
			return $username;
		}

		$counter = 1;
		while (true) {
			$testUsername = substr($username, 0, 27) . $counter;
			if (UsersHelper::findByUsername($testUsername) === null) {
				return $testUsername;
			}
			$counter++;
		}
	}
}
