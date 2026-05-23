<?php

namespace Drupal\mantle2\Plugin\OpenIDConnectClient;

use Drupal\openid_connect\Plugin\OpenIDConnectClientBase;
use Drupal\Core\Form\FormStateInterface;

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
	 * Apple doesn't have a userinfo endpoint.
	 * The $access_token here is actually Apple's id_token JWT.
	 * We decode the payload to extract user info.
	 *
	 * {@inheritdoc}
	 */
	public function retrieveUserInfo(string $access_token): ?array
	{
		$parts = explode('.', $access_token);
		if (count($parts) !== 3) {
			$this->loggerFactory
				->get('openid_connect_apple')
				->error('Invalid Apple id_token format.');
			return null;
		}

		// Base64url decode the payload (middle segment)
		$payload = json_decode(
			base64_decode(
				str_pad(
					strtr($parts[1], '-_', '+/'),
					strlen($parts[1]) + ((4 - (strlen($parts[1]) % 4)) % 4),
					'=',
				),
			),
			true,
		);

		if (!$payload || !isset($payload['sub'])) {
			$this->loggerFactory
				->get('openid_connect_apple')
				->error('Failed to decode Apple id_token payload.');
			return null;
		}

		return [
			'sub' => $payload['sub'],
			'email' => $payload['email'] ?? null,
			'email_verified' => $payload['email_verified'] ?? false,
			'name' => null,
			'given_name' => null,
			'family_name' => null,
			'picture' => null,
		];
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
