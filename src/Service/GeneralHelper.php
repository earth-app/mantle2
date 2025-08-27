<?php

namespace Drupal\mantle2\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class GeneralHelper
{
	// Request Utilities

	public static function badRequest(string $message = 'Bad Request'): JsonResponse
	{
		return new JsonResponse(['code' => 400, 'message' => $message], Response::HTTP_BAD_REQUEST);
	}

	public static function unauthorized(string $message = 'Unauthorized'): JsonResponse
	{
		return new JsonResponse(
			['code' => 401, 'message' => $message],
			Response::HTTP_UNAUTHORIZED,
		);
	}

	public static function paymentRequired(string $message = 'Payment Required'): JsonResponse
	{
		return new JsonResponse(
			['code' => 402, 'message' => $message],
			Response::HTTP_PAYMENT_REQUIRED,
		);
	}

	public static function forbidden(string $message = 'Forbidden'): JsonResponse
	{
		return new JsonResponse(['code' => 403, 'message' => $message], Response::HTTP_FORBIDDEN);
	}

	public static function notFound(string $message = 'Not Found')
	{
		return new JsonResponse(['code' => 404, 'message' => $message], Response::HTTP_NOT_FOUND);
	}

	// Network Utilities

	public static function getBearerToken(Request $request): ?string
	{
		$header = $request->headers->get('Authorization');
		if ($header && stripos($header, 'Bearer ') === 0) {
			return substr($header, 7);
		}
		return null;
	}
}
