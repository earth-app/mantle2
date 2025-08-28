<?php

namespace Drupal\mantle2\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GeneralController extends ControllerBase
{
	public function hi(): Response
	{
		return new Response('Hello!');
	}

	public function getInfo(): JsonResponse
	{
		return new JsonResponse([
			'name' => 'mantle2',
			'description' => 'The drupal backend for The Earth App',
			'status' => 'active',
		]);
	}
}
