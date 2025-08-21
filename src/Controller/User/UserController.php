<?php

namespace Drupal\mantle2\Controller\User;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\user\UserInterface;

class UserController extends ControllerBase
{
	public function getUser(UserInterface $user)
	{
		$data = [
			'id' => $user->id(),
			'username' => $user->getAccountName(),
		];

		return new JsonResponse($data);
	}
}
