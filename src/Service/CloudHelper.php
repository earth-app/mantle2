<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Exception;

class CloudHelper
{
	public static function getAdminKey(): string
	{
		// MANTLE2_ADMIN_KEY
		$key = Drupal::service('key.repository')->getKey('mantle2_api_key');
		if (!$key) {
			Drupal::logger('mantle2')->warning(
				'Admin API key not found. Please create a key named "mantle2_api_key".',
			);
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
		if (empty($path)) {
			throw new Exception('Path is required for the request.');
		}

		$ch = curl_init();
		$method = strtoupper($method);

		$url = rtrim($cloud, '/') . '/' . ltrim($path, '/');
		$payload = '';
		if ($method === 'GET' || $method === 'DELETE') {
			if (!empty($data)) {
				$query = http_build_query($data);
				$url .= (str_contains($url, '?') ? '&' : '?') . $query;
			}
			curl_setopt($ch, CURLOPT_HTTPGET, true);
		} elseif (!empty($data)) {
			$payload = json_encode($data);
			if ($payload === false) {
				throw new Exception('Failed to encode data to JSON: ' . json_last_error_msg());
			}

			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		}

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLINFO_HEADER_OUT, true);

		$headers = ['Accept: application/json'];

		if ($payload !== '') {
			$headers[] = 'Content-Type: application/json';
			$headers[] = 'Content-Length: ' . strlen($payload);
		}

		if (!empty(($adminKey = self::getAdminKey()))) {
			$headers[] = 'Authorization: Bearer ' . $adminKey;
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$requestHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);

		$curlError = curl_error($ch);
		if ($curlError) {
			// return empty on timeout or connection error
			if (
				str_contains($curlError, 'timed out') ||
				str_contains($curlError, 'Failed to connect')
			) {
				Drupal::logger('mantle2')->warning(
					'Cloud request timeout or connection error: @error for URL: @url',
					[
						'@error' => $curlError,
						'@url' => $url,
					],
				);
				return [];
			}

			// otherwise, throw an exception for other types of cURL errors
			throw new Exception('cURL Error: ' . $curlError);
		}

		unset($ch);

		preg_match('/^(GET|POST|PATCH|DELETE|PUT|OPTIONS|HEAD)\s/i', $requestHeaders, $matches);
		$actualMethod = $matches[1] ?? 'UNKNOWN';

		if ($actualMethod !== $method) {
			Drupal::logger('mantle2_cloud')->warning(
				'Expected HTTP method @expected but got @actual for URL: @url',
				[
					'@expected' => $method,
					'@actual' => $actualMethod,
					'@url' => $url,
				],
			);
		}

		// truncate response for logging if it's too long
		$logResponse =
			strlen($response) > 250 ? substr($response, 0, 250) . '... [truncated]' : $response;

		Drupal::logger('mantle2_cloud')->info(
			'Cloud request: [@code] @method @url : @payload : @response',
			[
				'@method' => $method,
				'@url' => $url,
				'@payload' => $payload ?? '<empty>',
				'@response' => $logResponse,
				'@code' => $httpCode,
			],
		);

		if ($httpCode === 204) {
			return [];
		}

		if ($httpCode === 404) {
			return [];
		}

		if ($httpCode === 400) {
			return [
				'code' => 400,
				'message' => 'Bad Request: ' . $response,
				'details' => json_decode($response, true) ?? [],
			];
		}

		if ($httpCode < 200 || $httpCode >= 300) {
			throw new Exception('HTTP Error: ' . $httpCode . ' Response: ' . $response, $httpCode);
		}

		return json_decode($response, true) ?? [];
	}

	public static function sendWebsocketMessage(string $channel, string $id, array $data): array
	{
		return self::sendRequest('/ws/notify', 'POST', [
			'channel' => $channel . ':' . $id,
			'data' => $data,
		]);
	}
}
