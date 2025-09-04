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

	public static function notFound(string $message = 'Not Found'): JsonResponse
	{
		return new JsonResponse(['code' => 404, 'message' => $message], Response::HTTP_NOT_FOUND);
	}

	public static function conflict(string $message = 'Conflict'): JsonResponse
	{
		return new JsonResponse(['code' => 409, 'message' => $message], Response::HTTP_CONFLICT);
	}

	public static function internalError(string $message = 'Internal Server Error'): JsonResponse
	{
		return new JsonResponse(
			['code' => 500, 'message' => $message],
			Response::HTTP_INTERNAL_SERVER_ERROR,
		);
	}

	// Response Utilities

	public static function formatId($id): string
	{
		$s = (string) $id;
		if (strlen($s) < 24) {
			$s = str_pad($s, 24, '0', STR_PAD_LEFT);
		}
		return substr($s, 0, 24);
	}

	public static function paginatedParameters(Request $request): array|JsonResponse
	{
		/** @var int $limit */
		$limit = $request->query->get('limit') ?? 25;
		if ($limit < 1 || $limit > 100) {
			return self::badRequest("Invalid limit '$limit'");
		}

		/** @var int $page */
		$page = $request->query->get('page') ?? 1;

		if ($page < 1) {
			return self::badRequest("Invalid page '$page'");
		}

		/** @var string $search */
		$search = $request->query->get('search') ?? '';
		if (strlen($search) > 40) {
			return self::badRequest("Search term '$search' too long");
		}

		return [
			'limit' => $limit,
			'page' => $page,
			'search' => $search,
		];
	}

	public static function fromDataURL(string $dataUrl): Response
	{
		if (!$dataUrl) {
			return GeneralHelper::notFound('Profile photo not found');
		}

		[$meta, $data] = explode(',', $dataUrl, 2);
		if (stripos($meta, 'base64') === false) {
			return GeneralHelper::internalError('Invalid profile photo data');
		}
		$matches = [];
		if (!preg_match('/data:(.*?);base64/', $meta, $matches) || count($matches) < 2) {
			return GeneralHelper::internalError('Invalid profile photo data');
		}
		$mime = $matches[1];
		$decoded = base64_decode($data, true);
		if ($decoded === false) {
			return GeneralHelper::internalError('Failed to decode profile photo data');
		}

		return new Response($decoded, Response::HTTP_OK, [
			'Content-Type' => $mime,
			'Content-Length' => strlen($decoded),
			'Cache-Control' => 'public, max-age=86400', // Cache for 1 day
		]);
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
