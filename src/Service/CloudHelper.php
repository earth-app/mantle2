<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Exception;

class CloudHelper
{
	public static function getAdminKey(): string
	{
		// MANTLE2_ADMIN_KEY
		return Drupal::service('settings')->get('mantle2.admin_key');
	}

	public static function getCloudEndpoint(): string
	{
		// MANTLE2_CLOUD_ENDPOINT
		return Drupal::service('settings')->get('mantle2.cloud_endpoint') ??
			'http://127.0.0.1:9898';
	}

	/**
	 * @throws Exception
	 */
	public static function sendRequest(string $path, array $data = []): array
	{
		$ch = curl_init();
		$payload = json_encode($data);

		curl_setopt($ch, CURLOPT_URL, self::getCloudEndpoint() . '/' . ltrim($path, '/'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . strlen($payload),
			'Authorization: Bearer ' . self::getAdminKey(),
		]);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if (curl_errno($ch)) {
			throw new Exception('Request Error: ' . curl_error($ch));
		}

		curl_close($ch);

		if ($httpCode < 200 || $httpCode >= 300) {
			throw new Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response);
		}

		return json_decode($response, true) ?? [];
	}
}
