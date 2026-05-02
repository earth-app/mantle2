<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Exception;
use Google\Auth\Credentials\ServiceAccountCredentials;

class FCMHelper
{
	private static function loadCredentials(): ?string
	{
		$credentialsJson = null;

		$credentialsPath =
			Drupal::service('extension.list.module')->getPath('mantle2') .
			'/data/service-account.json';

		$credentialsEnv = getenv('FCM_SERVICE_ACCOUNT_JSON');

		if ($credentialsEnv) {
			$credentialsJson = $credentialsEnv;
		} elseif (file_exists($credentialsPath)) {
			$credentialsJson = file_get_contents($credentialsPath);
			if ($credentialsJson === false) {
				Drupal::logger('mantle2')->error('Failed to read FCM credentials from file');
				throw new Exception('Failed to read FCM credentials from file');
			}
		}

		return $credentialsJson;
	}

	public static function send(string $token, string $title, string $body, array $data = [])
	{
		$scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

		$credentialsJson = self::loadCredentials();
		if (!$credentialsJson) {
			return; // fail silently if credentials are not available
		}

		$creds = new ServiceAccountCredentials($scopes, json_decode($credentialsJson, true));
		$auth = $creds->fetchAuthToken();
		$accessToken = $auth['access_token'] ?? null;

		if (!$accessToken) {
			Drupal::logger('mantle2')->error('Failed to obtain access token for FCM');
			throw new Exception('Failed to obtain access token for FCM');
		}

		// send post request via curl
		$ch = curl_init();
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

		curl_setopt(
			$ch,
			CURLOPT_URL,
			'https://fcm.googleapis.com/v1/projects/mantle2/messages:send',
		);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . $accessToken,
			'Content-Type: application/json',
		]);

		$payload = json_encode($requestBody);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		curl_exec($ch);

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);

		if ($curlError) {
			// return empty on timeout or connection error
			if (
				str_contains($curlError, 'timed out') ||
				str_contains($curlError, 'Failed to connect')
			) {
				Drupal::logger('mantle2')->warning(
					'FCM request timeout or connection error: @error',
					[
						'@error' => $curlError,
					],
				);
				return [];
			}

			// otherwise, throw an exception for other types of cURL errors
			throw new Exception('FCM cURL Error: ' . $httpCode . $curlError);
		}

		// validate HTTP response code for successful delivery
		if ($httpCode < 200 || $httpCode >= 300) {
			Drupal::logger('mantle2')->error('FCM notification delivery failed with HTTP %code', [
				'%code' => $httpCode,
			]);
			throw new Exception('FCM request failed with HTTP status code: ' . $httpCode);
		}

		unset($ch);
	}
}
