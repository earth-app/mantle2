<?php

namespace Drupal\mantle2\Plugin\OpenIDConnectClient;

use Drupal\openid_connect\Plugin\OpenIDConnectClientBase;
use Drupal\Core\Form\FormStateInterface;
use Exception;

/**
 * Discord OpenID Connect client.
 *
 * @OpenIDConnectClient(
 *   id = "discord",
 *   label = @Translation("Discord")
 * )
 */
class Discord extends OpenIDConnectClientBase
{
	/**
	 * {@inheritdoc}
	 */
	public function getEndpoints(): array
	{
		return [
			'authorization' => 'https://discord.com/api/oauth2/authorize',
			'token' => 'https://discord.com/api/oauth2/token',
			'userinfo' => 'https://discord.com/api/users/@me',
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function retrieveUserInfo(string $access_token): ?array
	{
		$endpoints = $this->getEndpoints();
		$request_options = [
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Accept' => 'application/json',
			],
		];

		try {
			$response = $this->httpClient->request('GET', $endpoints['userinfo'], $request_options);
			$response_data = json_decode((string) $response->getBody(), true);

			// map Discord user data to standard OpenID Connect format
			$userinfo = [
				'sub' => $response_data['id'] ?? null,
				'id' => $response_data['id'] ?? null,
				'name' => $response_data['username'] ?? null,
				'given_name' =>
					$response_data['global_name'] ?? ($response_data['username'] ?? null),
				'email' => $response_data['email'] ?? null,
				'email_verified' => $response_data['verified'] ?? false,
				'picture' => null,
			];

			// Construct avatar URL if available
			if (!empty($response_data['avatar']) && !empty($response_data['id'])) {
				$userinfo['picture'] = sprintf(
					'https://cdn.discordapp.com/avatars/%s/%s.png',
					$response_data['id'],
					$response_data['avatar'],
				);
			}

			return $userinfo;
		} catch (Exception $e) {
			$this->loggerFactory
				->get('openid_connect_discord')
				->error('Could not retrieve user profile information: @message', [
					'@message' => $e->getMessage(),
				]);
			return null;
		}
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
				'https://discord.com/api/oauth2/authorize',
		];

		$form['token_endpoint'] = [
			'#title' => $this->t('Token endpoint'),
			'#type' => 'textfield',
			'#default_value' =>
				$this->configuration['token_endpoint'] ?? 'https://discord.com/api/oauth2/token',
		];

		$form['userinfo_endpoint'] = [
			'#title' => $this->t('UserInfo endpoint'),
			'#type' => 'textfield',
			'#default_value' =>
				$this->configuration['userinfo_endpoint'] ?? 'https://discord.com/api/users/@me',
		];

		return $form;
	}
}
