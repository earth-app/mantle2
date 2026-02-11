<?php

namespace Drupal\mantle2\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

	public function getMotd(): JsonResponse
	{
		$motd = RedisHelper::get('motd');
		if ($motd == null) {
			return GeneralHelper::notFound('No MOTD set');
		}

		$icon = RedisHelper::get('motd_icon') ?? 'mdi:earth';
		$type = RedisHelper::get('motd_type') ?? 'info';

		$ttl = RedisHelper::ttl('motd');
		return new JsonResponse([
			'motd' => $motd,
			'icon' => $icon,
			'type' => $type,
			'ttl' => $ttl,
		]);
	}

	public function setMotd(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('Only admins can set the MOTD');
		}

		$data = json_decode($request->getContent(), true);
		$motd = $data['motd'] ?? null;
		if ($motd == null) {
			return GeneralHelper::badRequest('MOTD is required');
		}

		$ttl = $data['ttl'] ?? 86400; // default to 24 hours
		$icon = $data['icon'] ?? 'mdi:earth';
		$type = $data['type'] ?? 'info';

		RedisHelper::set('motd', $motd, $ttl);
		if ($icon) {
			RedisHelper::set('motd_icon', $icon, $ttl);
		}

		if ($type) {
			RedisHelper::set('motd_type', $type, $ttl);
		}

		return new JsonResponse(
			['motd' => $motd, 'ttl' => $ttl, 'icon' => $icon, 'type' => $type],
			Response::HTTP_CREATED,
		);
	}
}
