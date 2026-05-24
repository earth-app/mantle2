<?php

namespace Drupal\mantle2\Plugin\OpenIDConnectClient;

use Drupal\openid_connect\Plugin\OpenIDConnectClientBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mantle2\Service\RedisHelper;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;

/**
 * Apple OpenID Connect client.
 *
 * @OpenIDConnectClient(
 *   id = "apple",
 *   label = @Translation("Apple")
 * )
 */
class Apple extends OpenIDConnectClientBase
{
	private const APPLE_ISSUER = 'https://appleid.apple.com';
	private const APPLE_JWKS_URL = 'https://appleid.apple.com/auth/keys';
	private const JWKS_CACHE_KEY = 'oauth:apple:jwks';
	private const JWKS_CACHE_TTL = 3600; // 1 hour
	private const ALLOWED_AUDIENCES = ['com.earthapp.crust', 'com.earthapp.sky'];

	/**
	 * {@inheritdoc}
	 */
	public function getEndpoints(): array
	{
		return [
			'authorization' => 'https://appleid.apple.com/auth/authorize',
			'token' => 'https://appleid.apple.com/auth/token',
			'userinfo' => '', // no userinfo endpoint — data is in the id_token
		];
	}

	/**
	 * Verify the Apple id_token and return a normalized user info array.
	 *
	 * The $access_token here is actually Apple's id_token JWT.
	 *
	 * {@inheritdoc}
	 */
	public function retrieveUserInfo(string $access_token): ?array
	{
		$logger = $this->loggerFactory->get('openid_connect_apple');

		$jwks = $this->getAppleJwks();
		if (!$jwks) {
			$logger->error('Unable to fetch Apple JWKS for id_token verification.');
			return null;
		}

		try {
			$keys = JWK::parseKeySet($jwks);
			$payload = JWT::decode($access_token, $keys);
		} catch (Throwable $e) {
			$logger->error('Apple id_token verification failed: @message', [
				'@message' => $e->getMessage(),
			]);
			return null;
		}

		if (!isset($payload->iss) || $payload->iss !== self::APPLE_ISSUER) {
			$logger->error('Apple id_token has invalid issuer: @iss', [
				'@iss' => $payload->iss ?? '(missing)',
			]);
			return null;
		}

		$aud = $payload->aud ?? null;
		if (!$this->audienceAllowed($aud)) {
			$logger->error('Apple id_token has disallowed audience: @aud', [
				'@aud' => is_array($aud) ? implode(',', $aud) : $aud ?? '(missing)',
			]);
			return null;
		}

		if (empty($payload->sub)) {
			$logger->error('Apple id_token missing sub claim.');
			return null;
		}

		return [
			'sub' => $payload->sub,
			'email' => $payload->email ?? null,
			'email_verified' => $this->coerceBool($payload->email_verified ?? false),
			'name' => null,
			'given_name' => null,
			'family_name' => null,
			'picture' => null,
		];
	}

	private function audienceAllowed(mixed $aud): bool
	{
		if (is_string($aud)) {
			return in_array($aud, self::ALLOWED_AUDIENCES, true);
		}
		if (is_array($aud)) {
			foreach ($aud as $candidate) {
				if (is_string($candidate) && in_array($candidate, self::ALLOWED_AUDIENCES, true)) {
					return true;
				}
			}
		}
		return false;
	}

	private function coerceBool(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_string($value)) {
			return strtolower($value) === 'true';
		}
		return (bool) $value;
	}

	/**
	 * Fetch (and cache) Apple's JWKS as an associative array.
	 */
	private function getAppleJwks(): ?array
	{
		$cached = RedisHelper::get(self::JWKS_CACHE_KEY);
		if (is_array($cached) && !empty($cached['keys'])) {
			return $cached;
		}

		$ch = curl_init(self::APPLE_JWKS_URL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Accept: application/json',
			'User-Agent: @earth-app/mantle2',
		]);

		$body = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		unset($ch);

		if ($body === false || $status !== 200) {
			$this->loggerFactory
				->get('openid_connect_apple')
				->error('Failed to fetch Apple JWKS (status @status): @error', [
					'@status' => $status,
					'@error' => $error ?: 'unknown',
				]);
			return null;
		}

		$jwks = json_decode((string) $body, true);
		if (!is_array($jwks) || empty($jwks['keys'])) {
			$this->loggerFactory
				->get('openid_connect_apple')
				->error('Apple JWKS response was invalid or empty.');
			return null;
		}

		RedisHelper::set(self::JWKS_CACHE_KEY, $jwks, self::JWKS_CACHE_TTL);
		return $jwks;
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildConfigurationForm(array $form, FormStateInterface $form_state): array
	{
		$form = parent::buildConfigurationForm($form, $form_state);

		$form['authorization_endpoint'] = [
			'#title' => $this->t('Authorization endpoint'),
			'#type' => 'textfield',
			'#default_value' =>
				$this->configuration['authorization_endpoint'] ??
				'https://appleid.apple.com/auth/authorize',
		];

		$form['token_endpoint'] = [
			'#title' => $this->t('Token endpoint'),
			'#type' => 'textfield',
			'#default_value' =>
				$this->configuration['token_endpoint'] ?? 'https://appleid.apple.com/auth/token',
		];

		return $form;
	}
}
