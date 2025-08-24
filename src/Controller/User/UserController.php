<?php

namespace Drupal\mantle2\Controller\User;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\user\UserInterface;

class UserController extends ControllerBase
{
	private function getAccountFields(UserInterface $user) {}

	public function getUser(UserInterface $user)
	{
		$data = [
			'id' => $user->id(),
			'username' => $user->getAccountName(),
			'created_at' => date('c', $user->getCreatedTime()),
			'updated_at' => date('c', $user->getChangedTime()),
			'last_login' => date('c', $user->getLastLoginTime()),
			'account' => [
				'email' => $user->getEmail(),
			],
		];

		return new JsonResponse($data);
	}
}
