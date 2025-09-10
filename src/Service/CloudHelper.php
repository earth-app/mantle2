<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Exception;

class CloudHelper
{
	public static function getAdminKey(): string
	{
		// MANTLE2_ADMIN_KEY
		$key = Drupal::service('key.repository')->getKey('mantle2_admin_key');
		if ($key === null) {
			return '';
		}

		return $key->getKeyValue() ?? '';
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
	public static function sendRequest(
		string $path,
		string $method = 'GET',
		array $data = [],
	): array {
		$cloud = self::getCloudEndpoint();
		$ch = curl_init();
		$payload = json_encode($data);

		curl_setopt($ch, CURLOPT_URL, $cloud . '/' . ltrim($path, '/'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

		if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		}

		curl_setopt($ch, CURLOPT_TIMEOUT, 10);

		$headers = ['Content-Type: application/json', 'Content-Length: ' . strlen($payload)];

		if (!empty(($adminKey = self::getAdminKey()))) {
			$headers[] = 'Authorization: Bearer ' . $adminKey;
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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
