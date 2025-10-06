<?php

namespace Drupal\mantle2\Service;

use Symfony\Component\HttpFoundation\Exception\UnexpectedValueException;
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
			// avoid double padding
			$s = str_pad($s, 24, '0', STR_PAD_LEFT);
		}
		return substr($s, 0, 24);
	}

	public static function dateToIso(int $timestamp): string
	{
		return gmdate('c', $timestamp);
	}

	public static function paginatedParameters(
		Request $request,
		int $maxLimit = 100,
	): array|JsonResponse {
		try {
			$limit = $request->query->getInt('limit', 25);
			if ($limit < 1 || $limit > $maxLimit) {
				return self::badRequest("Invalid limit '$limit': must be between 1 and $maxLimit");
			}

			if ($limit < 1 || $limit > $maxLimit) {
				return self::badRequest("Invalid limit '$limit': must be between 1 and $maxLimit");
			}

			$page = $request->query->getInt('page', 1);
			if ($page < 1) {
				return self::badRequest("Invalid page '$page'");
			}

			$search = $request->query->get('search') ?? '';
			if (!is_string($search)) {
				return self::badRequest('Invalid search term');
			}

			$search = trim($search);
			if (strlen($search) > 40) {
				return self::badRequest("Search term '$search' too long");
			}

			return [
				'limit' => $limit,
				'page' => $page,
				'search' => $search,
			];
		} catch (UnexpectedValueException $e) {
			return self::badRequest('Invalid pagination parameters: ' . $e->getMessage());
		}
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

	public static function findOrdinal(array $array, $value): int
	{
		$index = array_search($value, $array, true);
		return $index === false ? -1 : $index;
	}

	public static function intToHex(int $color): string
	{
		return sprintf('#%06X', $color & 0xffffff);
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

	public static function getBasicAuth(Request $request): array|null
	{
		$header = $request->headers->get('Authorization');
		if ($header && stripos($header, 'Basic ') === 0) {
			$encoded = substr($header, 6);
			$decoded = base64_decode($encoded, true);
			if ($decoded !== false) {
				$parts = explode(':', $decoded, 2);
				if (count($parts) === 2) {
					return [
						'username' => $parts[0],
						'password' => $parts[1],
					];
				}
			}
		}
		return null;
	}

	// Content Validation

	private static array $badWords = [
		'nigger',
		'fuck',
		'shit',
		'bitch',
		'slut',
		'ass',
		'nigga',
		'whore',
		'cannabis',
		'clanker',
		'faggot',
		'pussy',
		'arse',
		'shite',
		'cunt',
		'wanker',
		'crap',
		'thot',
		'bozo',
		'cuck',
		'sex',
		'masturbation',
	];

	// Cache for normalized bad words to avoid repeated normalization
	private static ?array $normalizedBadWords = null;

	// Global leetspeak / symbol substitution map
	private static array $leetspeakMap = [
		// numbers -> letters
		'0' => 'o',
		'1' => 'i',
		'2' => 'z',
		'3' => 'e',
		'4' => 'a',
		'5' => 's',
		'6' => 'g',
		'7' => 't',
		'8' => 'b',
		'9' => 'g',

		// common symbols -> letters or removal
		'@' => 'a',
		'$' => 's',
		'+' => 't',
		'!' => 'i',
		'|' => 'i',
		'¥' => 'y',
		'%' => '',
		'^' => '',
		'&' => 'and',
		'*' => '',
		'_' => '',
		'-' => '',
		'=' => '',
		'~' => '',
		'`' => '',
		"'" => '',
		'"' => '',
		':' => '',
		';' => '',
		',' => '',
		'.' => '',
		'/' => '',
		'\\' => '',
		'?' => '',
		'<' => '',
		'>' => '',
		'(' => '',
		')' => '',
		'[' => '',
		']' => '',
		'{' => '',
		'}' => '',
		'#' => '',
		'¿' => '',
		'¡' => 'i',

		// visual / currency / punctuation homoglyphs
		'¢' => 'c',
		'£' => 'l',
		'€' => 'e',
		'©' => 'c',
		'®' => 'r',
		'°' => 'o',
		'º' => 'o',
		'‚' => '',
		'•' => '',
		'…' => '',

		// some ascii-art substitutions
		// (removed duplicates that were already defined above)

		// common bracket-like separators that people insert between letters
		'·' => '',
		'•' => '',
		'—' => '',
		'–' => '',
		'´' => '',
		'˝' => '',

		// letters that look similar in other alphabets (some examples)
		'а' => 'a', // Cyrillic a -> Latin a
		'е' => 'e', // Cyrillic e
		'о' => 'o', // Cyrillic o
		'р' => 'p', // Cyrillic r -> looks like p but often used for r/p confusion
		'с' => 'c', // Cyrillic s -> c
		'і' => 'i', // Cyrillic/ Ukrainian i
		'ј' => 'j', // Cyrillic small je
		'ѵ' => 'v', // old Cyrillic v
		'ʀ' => 'r', // small Latin/R variants

		// more visually similar punctuation mapped to nothing to avoid bypasses
		'﹫' => 'a',
		'﹤' => '',
		'﹥' => '',

		// common unicode non-letter confusables that should be removed
		'„' => '',
		'‹' => '',
		'›' => '',

		// whitespace variants we will normalize to plain space first
		"\t" => ' ',
		"\n" => ' ',
		"\r" => ' ',
	];

	private static function normalize_text(string $text): string
	{
		// 1) Normalize Unicode (NFKD) and remove combining diacritics (turn ó -> o, etc.)
		if (class_exists('Normalizer')) {
			$text = \Normalizer::normalize($text, \Normalizer::FORM_KD) ?: $text;
		}
		// strip combining marks (accents)
		$text = preg_replace('/\p{M}/u', '', $text);

		// 2) Remove zero-width and other format-control characters (U+200B, U+200C, U+200D, U+FEFF, etc.)
		$text = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\p{Cf}]+/u', '', $text);

		// 3) Lowercase (multibyte-safe)
		$text = mb_strtolower($text, 'UTF-8');

		// 4) Apply the leetspeak map (strtr is fast and handles single-char and multichar keys)
		$text = strtr($text, self::$leetspeakMap);

		// 5) Collapse "spaced-out" or punctuated letters:
		//    Example: "b a d", "b.a.d", "b-a-d" => "bad"
		//    We do two passes:
		//      a) collapse single-character separators between letters (keep letters)
		//      b) remove any remaining non-alphanumerics
		// a) remove separators between letters: letter [non-alnum]+ letter -> letterletter
		$text = preg_replace('/(\p{L})(?:[\s\W_]+)(?=\p{L})/u', '$1', $text);

		// b) remove anything left that's not a-z or 0-9
		$text = preg_replace('/[^a-z0-9]+/u', '', $text);

		// 6) Collapse repeated characters that are commonly used to evade detection (optional)
		//    Example: "baaaad" -> "baad" (keeps one duplication but removes extreme repeats)
		$text = preg_replace('/([a-z0-9])\1{2,}/u', '$1$1', $text); // if 3+ repeat, reduce to 2

		return $text;
	}

	public static function isFlagged(string $text): array
	{
		// Early exit for very short strings (less than 3 characters)
		if (strlen($text) < 3) {
			return ['flagged' => '', 'matched_word' => ''];
		}

		// Step 1: Normalize text, convert to lower
		$normalized = self::normalize_text($text);

		// Early exit if normalization resulted in too short text
		if (strlen($normalized) < 2) {
			return ['flagged' => '', 'matched_word' => ''];
		}

		// Initialize normalized bad words cache if not already done
		if (self::$normalizedBadWords === null) {
			self::$normalizedBadWords = [];
			foreach (self::$badWords as $word) {
				$wordNorm = self::normalize_text((string) $word);
				if ($wordNorm !== '' && strlen($wordNorm) >= 2) {
					self::$normalizedBadWords[] = $wordNorm;
				}
			}
		}

		foreach (self::$normalizedBadWords as $index => $wordNorm) {
			// Word boundary matching with optional suffix variants to avoid false positives
			$pattern = '/\b' . preg_quote($wordNorm, '/') . '(?:s|es|ed|ing)?\b/u';
			if (preg_match($pattern, $normalized)) {
				return [
					'flagged' => $text,
					'matched_word' => self::$badWords[$index],
				];
			}
		}

		return ['flagged' => '', 'matched_word' => ''];
	}
}
