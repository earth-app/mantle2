<?php

namespace Drupal\mantle2\Service;

use CurlHandle;
use Drupal;
use Exception;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Throwable;

class FCMHelper
{
	public const KEY_NAME = 'mantle2_fcm_service_account';

	private const SCOPES = ['https://www.googleapis.com/auth/firebase.messaging'];

	// shared across requests and cron runs, so a fan-out costs one oauth exchange
	// instead of one per device token
	private const ACCESS_TOKEN_KEY = 'fcm:access_token';

	// google access tokens live ~3600s; retiring ours a minute early keeps one from
	// expiring between the cache read and fcm answering
	private const ACCESS_TOKEN_MARGIN = 60;

	private const DEFAULT_TOKEN_LIFETIME = 3600;

	private const SEND_TIMEOUT = 30;

	private const CONNECT_TIMEOUT = 5;

	private static ?string $cachedToken = null;

	private static int $cachedTokenExpires = 0;

	private static ?array $cachedCredentials = null;

	// test seam: stands in for the google oauth round trip (mirror CloudHelper::setRequestOverride)
	private static $tokenFetchOverride = null;

	// test seam: stands in for the fcm post so the gate lane never opens a socket
	private static $requestOverride = null;

	// lets tests fake the oauth exchange (receives the decoded service account, returns
	// an array with access_token + expires_in)
	public static function setTokenFetchOverride(?callable $override): void
	{
		self::$tokenFetchOverride = $override;
	}

	// lets tests fake fcm (receives url, access token, message body; returns code/body/error)
	public static function setRequestOverride(?callable $override): void
	{
		self::$requestOverride = $override;
	}

	// drops the memoized credentials and access token (in-process and redis)
	public static function resetCaches(): void
	{
		self::$cachedCredentials = null;
		self::invalidateAccessToken();
	}

	private static function loadCredentials(): ?string
	{
		$key = Drupal::service('key.repository')->getKey(self::KEY_NAME);
		if ($key) {
			$value = $key->getKeyValue();
			if (!empty($value)) {
				return $value;
			}
		}

		$credentialsEnv = getenv('FCM_SERVICE_ACCOUNT_JSON');
		if (!empty($credentialsEnv)) {
			return $credentialsEnv;
		}

		$credentialsPath =
			Drupal::service('extension.list.module')->getPath('mantle2') .
			'/data/service-account.json';

		if (file_exists($credentialsPath)) {
			$credentialsJson = file_get_contents($credentialsPath);
			if ($credentialsJson === false) {
				Drupal::logger('mantle2')->error('Failed to read FCM credentials from file');
				return null;
			}
			return $credentialsJson;
		}

		return null;
	}

	// memoized in-process only; the service account carries a private key, so it never
	// goes into redis the way the short-lived access token does
	private static function credentials(): ?array
	{
		if (self::$cachedCredentials !== null) {
			return self::$cachedCredentials;
		}

		$credentialsJson = self::loadCredentials();
		if (!$credentialsJson) {
			Drupal::logger('mantle2')->error('Failed to load FCM credentials: Not found');
			return null;
		}

		$decoded = json_decode($credentialsJson, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
			Drupal::logger('mantle2')->error('Failed to decode FCM credentials JSON: %error', [
				'%error' => json_last_error_msg(),
			]);
			return null;
		}

		self::$cachedCredentials = $decoded;
		return $decoded;
	}

	private static function invalidateAccessToken(): void
	{
		self::$cachedToken = null;
		self::$cachedTokenExpires = 0;
		RedisHelper::delete(self::ACCESS_TOKEN_KEY);
	}

	private static function accessToken(array $credentials, bool $forceRefresh = false): ?string
	{
		$now = time();

		if (!$forceRefresh) {
			if (self::$cachedToken !== null && self::$cachedTokenExpires > $now) {
				return self::$cachedToken;
			}

			$cached = RedisHelper::get(self::ACCESS_TOKEN_KEY);
			if (is_array($cached)) {
				$token = $cached['token'] ?? null;
				$expires = $cached['expires'] ?? null;
				if (is_string($token) && $token !== '' && is_int($expires) && $expires > $now) {
					self::$cachedToken = $token;
					self::$cachedTokenExpires = $expires;
					return $token;
				}
			}
		}

		self::$cachedToken = null;
		self::$cachedTokenExpires = 0;

		try {
			$auth = self::fetchAuthToken($credentials);
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to obtain access token for FCM: %message', [
				'%message' => $e->getMessage(),
			]);
			return null;
		}

		$token = $auth['access_token'] ?? null;
		if (!is_string($token) || $token === '') {
			Drupal::logger('mantle2')->error('Failed to obtain access token for FCM');
			return null;
		}

		$lifetime = $auth['expires_in'] ?? null;
		// the token endpoint may answer form-encoded, where expires_in arrives as a string
		if (is_string($lifetime) && ctype_digit($lifetime)) {
			$lifetime = (int) $lifetime;
		}
		if (!is_int($lifetime) || $lifetime <= 0) {
			$lifetime = self::DEFAULT_TOKEN_LIFETIME;
		}

		$ttl = max(1, $lifetime - self::ACCESS_TOKEN_MARGIN);
		self::$cachedToken = $token;
		self::$cachedTokenExpires = $now + $ttl;
		RedisHelper::set(
			self::ACCESS_TOKEN_KEY,
			['token' => $token, 'expires' => self::$cachedTokenExpires],
			$ttl,
		);

		return $token;
	}

	private static function fetchAuthToken(array $credentials): array
	{
		if (self::$tokenFetchOverride !== null) {
			$result = (self::$tokenFetchOverride)($credentials);
			return is_array($result) ? $result : [];
		}

		$creds = new ServiceAccountCredentials(self::SCOPES, $credentials);
		return $creds->fetchAuthToken();
	}

	public static function send(string $token, string $title, string $body, array $data = []): void
	{
		self::sendMulticast([$token], $title, $body, $data);
	}

	// fcm http v1 has no multi-token endpoint (the legacy registration_ids field is gone), so
	// this is one oauth exchange plus sequential posts over a single kept-alive connection
	public static function sendMulticast(
		array $tokens,
		string $title,
		string $body,
		array $data = [],
	): void {
		$targets = [];
		foreach ($tokens as $token) {
			if (is_string($token) && $token !== '' && !in_array($token, $targets, true)) {
				$targets[] = $token;
			}
		}

		if ($targets === []) {
			return;
		}

		try {
			$credentials = self::credentials();
			if ($credentials === null) {
				return;
			}

			$projectId = $credentials['project_id'] ?? null;
			if (!is_string($projectId) || $projectId === '') {
				Drupal::logger('mantle2')->error('Failed to obtain project ID for FCM');
				return;
			}

			$accessToken = self::accessToken($credentials);
			if ($accessToken === null) {
				return;
			}

			$url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, self::SEND_TIMEOUT);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);

			$refreshed = false;
			foreach ($targets as $token) {
				$message = self::messageBody($token, $title, $body, $data);
				$responseBody = '';
				$error = '';
				$code = self::postMessage($ch, $url, $accessToken, $message, $responseBody, $error);

				// a cached token can be revoked before it expires; that is the only failure the
				// cache introduces, so refresh once and replay
				if ($code === 401 && !$refreshed) {
					$refreshed = true;
					self::invalidateAccessToken();

					$fresh = self::accessToken($credentials, true);
					if ($fresh === null) {
						return;
					}

					$accessToken = $fresh;
					$code = self::postMessage(
						$ch,
						$url,
						$accessToken,
						$message,
						$responseBody,
						$error,
					);
				}

				self::handleResponse($token, $code, $responseBody, $error);
			}

			unset($ch);
		} catch (Throwable $e) {
			Drupal::logger('mantle2')->error('FCM delivery aborted: %message', [
				'%message' => $e->getMessage(),
			]);
		}
	}

	private static function messageBody(
		string $token,
		string $title,
		string $body,
		array $data,
	): array {
		return [
			'message' => [
				'token' => $token,
				'notification' => [
					'title' => $title,
					'body' => $body,
				],
				'data' => $data,
			],
		];
	}

	private static function postMessage(
		CurlHandle $ch,
		string $url,
		string $accessToken,
		array $message,
		string &$responseBody,
		string &$error,
	): int {
		$responseBody = '';
		$error = '';

		$payload = json_encode($message);
		if ($payload === false) {
			$error = 'failed to encode FCM payload: ' . json_last_error_msg();
			return 0;
		}

		if (self::$requestOverride !== null) {
			$result = (self::$requestOverride)($url, $accessToken, $message);
			$result = is_array($result) ? $result : [];

			$code = $result['code'] ?? 0;
			$body = $result['body'] ?? '';
			$failure = $result['error'] ?? '';

			$responseBody = is_string($body) ? $body : '';
			$error = is_string($failure) ? $failure : '';
			return is_int($code) ? $code : 0;
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . $accessToken,
			'Content-Type: application/json',
		]);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

		$response = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		$responseBody = is_string($response) ? $response : '';
		$error = curl_error($ch);
		return is_int($code) ? $code : 0;
	}

	private static function handleResponse(
		string $token,
		int $code,
		string $responseBody,
		string $error,
	): void {
		if ($error !== '') {
			Drupal::logger('mantle2')->warning('FCM request error (HTTP %code): %error', [
				'%code' => $code,
				'%error' => $error,
			]);
			return;
		}

		if ($code >= 200 && $code < 300) {
			return;
		}

		// 404 UNREGISTERED and 403 SENDER_ID_MISMATCH are always dead tokens; a 400
		// INVALID_ARGUMENT only is when fcm blames the token rather than the payload
		if ($code === 404 || $code === 403 || ($code === 400 && self::blamesToken($responseBody))) {
			self::removeInvalidToken($token, $code);
			return;
		}

		Drupal::logger('mantle2')->error('FCM delivery failed with HTTP %code: %response', [
			'%code' => $code,
			'%response' => $responseBody !== '' ? substr($responseBody, 0, 500) : '<no body>',
		]);
	}

	// pruning on a payload-shaped 400 would wipe every token in the fan-out, so only a
	// 400 that names the token counts as a dead token
	private static function blamesToken(string $responseBody): bool
	{
		if ($responseBody === '') {
			return false;
		}

		return str_contains($responseBody, 'message.token') ||
			stripos($responseBody, 'registration token') !== false;
	}

	private static function removeInvalidToken(string $token, int $httpCode): void
	{
		try {
			Drupal::database()->delete('push_tokens')->condition('token', $token)->execute();
		} catch (Exception $e) {
			Drupal::logger('mantle2')->warning(
				'FCM: failed to remove invalid push token (HTTP %code): %message',
				['%code' => $httpCode, '%message' => $e->getMessage()],
			);
		}
	}
}
