<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Exception;
use Google\Auth\Credentials\ServiceAccountCredentials;

class FCMHelper
{
	public const KEY_NAME = 'mantle2_fcm_service_account';

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

	public static function send(string $token, string $title, string $body, array $data = [])
	{
		$scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

		$credentialsJson = self::loadCredentials();
		if (!$credentialsJson) {
			Drupal::logger('mantle2')->error('Failed to load FCM credentials: Not found');
			return;
		}

		$credsArray = json_decode($credentialsJson, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			Drupal::logger('mantle2')->error('Failed to decode FCM credentials JSON: %error', [
				'%error' => json_last_error_msg(),
			]);
			return;
		}

		try {
			$creds = new ServiceAccountCredentials($scopes, $credsArray);
			$auth = $creds->fetchAuthToken();
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to obtain access token for FCM: %message', [
				'%message' => $e->getMessage(),
			]);
			return;
		}

		$accessToken = $auth['access_token'] ?? null;
		if (!$accessToken) {
			Drupal::logger('mantle2')->error('Failed to obtain access token for FCM');
			return;
		}

		$projectId = $credsArray['project_id'] ?? null;
		if (!$projectId) {
			Drupal::logger('mantle2')->error('Failed to obtain project ID for FCM');
			return;
		}

		$requestBody = [
			'message' => [
				'token' => $token,
				'notification' => [
					'title' => $title,
					'body' => $body,
				],
				'data' => $data,
			],
		];

		$ch = curl_init();
		curl_setopt(
			$ch,
			CURLOPT_URL,
			'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send',
		);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . $accessToken,
			'Content-Type: application/json',
		]);

		$payload = json_encode($requestBody);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		unset($ch);

		if ($curlError) {
			Drupal::logger('mantle2')->warning('FCM request error (HTTP %code): %error', [
				'%code' => $httpCode,
				'%error' => $curlError,
			]);
			return;
		}

		if ($httpCode < 200 || $httpCode >= 300) {
			Drupal::logger('mantle2')->error('FCM delivery failed with HTTP %code: %response', [
				'%code' => $httpCode,
				'%response' => is_string($response) ? substr($response, 0, 500) : '<no body>',
			]);
		}
	}
}
