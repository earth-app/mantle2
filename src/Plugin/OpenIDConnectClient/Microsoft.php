<?php

namespace Drupal\mantle2\Plugin\OpenIDConnectClient;

use Drupal\openid_connect\Plugin\OpenIDConnectClientBase;
use Drupal\Core\Form\FormStateInterface;
use Exception;

/**
 * Microsoft OpenID Connect client.
 *
 * @OpenIDConnectClient(
 *   id = "microsoft",
 *   label = @Translation("Microsoft")
 * )
 */
class Microsoft extends OpenIDConnectClientBase
{
	/**
	 * {@inheritdoc}
	 */
	public function getEndpoints(): array
	{
		return [
			'authorization' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
			'token' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
			'userinfo' => 'https://graph.microsoft.com/oidc/userinfo',
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
			$userinfo = [
				'sub' => $response_data['sub'] ?? null,
				'email' => $response_data['email'] ?? null,
				'name' => $response_data['name'] ?? null,
				'given_name' => $response_data['given_name'] ?? null,
				'family_name' => $response_data['family_name'] ?? null,
				'email_verified' => $response_data['email_verified'] ?? false,
				'picture' => $response_data['picture'] ?? null,
			];

			return $userinfo;
		} catch (Exception $e) {
			$this->loggerFactory
				->get('openid_connect_microsoft')
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
				'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
		];

		$form['token_endpoint'] = [
			'#title' => $this->t('Token endpoint'),
			'#type' => 'textfield',
			'#default_value' =>
				$this->configuration['token_endpoint'] ??
				'https://login.microsoftonline.com/common/oauth2/v2.0/token',
		];

		$form['userinfo_endpoint'] = [
			'#title' => $this->t('UserInfo endpoint'),
			'#type' => 'textfield',
			'#default_value' =>
				$this->configuration['userinfo_endpoint'] ??
				'https://graph.microsoft.com/oidc/userinfo',
		];

		return $form;
	}
}
