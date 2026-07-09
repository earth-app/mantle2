<?php

namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class CachingValidationTest extends TestCase
{
	private static array $cachingConfig;
	private static array $routes;
	private static string $cachingFilePath;
	private static string $routingFilePath;

	public static function setUpBeforeClass(): void
	{
		self::$cachingFilePath = dirname(__DIR__, 3) . '/mantle2.caching.yml';
		self::$routingFilePath = dirname(__DIR__, 3) . '/mantle2.routing.yml';

		if (!file_exists(self::$cachingFilePath)) {
			self::fail('Caching file not found: ' . self::$cachingFilePath);
		}

		if (!file_exists(self::$routingFilePath)) {
			self::fail('Routing file not found: ' . self::$routingFilePath);
		}

		try {
			self::$cachingConfig = Yaml::parseFile(self::$cachingFilePath);
			self::$routes = Yaml::parseFile(self::$routingFilePath);
		} catch (ParseException $e) {
			self::fail('Failed to parse YAML: ' . $e->getMessage());
		}
	}

	public static function retrievalProvider(): array
	{
		if (!isset(self::$cachingConfig)) {
			self::setUpBeforeClass();
		}

		$data = [];
		$retrievals = self::$cachingConfig['cache']['retrievals'] ?? [];
		foreach ($retrievals as $index => $retrieval) {
			$route = $retrieval['route'] ?? 'unknown';
			$data["retrieval_$index ($route)"] = [$index, $retrieval, 'retrieval'];
		}
		return $data;
	}

	public static function updateProvider(): array
	{
		if (!isset(self::$cachingConfig)) {
			self::setUpBeforeClass();
		}

		$data = [];
		$updates = self::$cachingConfig['cache']['updates'] ?? [];
		foreach ($updates as $index => $update) {
			$route = $update['route'] ?? 'unknown';
			$data["update_$index ($route)"] = [$index, $update, 'update'];
		}
		return $data;
	}

	public static function deleteProvider(): array
	{
		if (!isset(self::$cachingConfig)) {
			self::setUpBeforeClass();
		}

		$data = [];
		$deletes = self::$cachingConfig['cache']['deletes'] ?? [];
		foreach ($deletes as $index => $delete) {
			$route = $delete['route'] ?? 'unknown';
			$data["delete_$index ($route)"] = [$index, $delete, 'delete'];
		}
		return $data;
	}

	public static function allCacheRulesProvider(): array
	{
		if (!isset(self::$cachingConfig)) {
			self::setUpBeforeClass();
		}

		$data = [];

		$retrievals = self::$cachingConfig['cache']['retrievals'] ?? [];
		foreach ($retrievals as $index => $retrieval) {
			$route = $retrieval['route'] ?? 'unknown';
			$data["retrieval_$index ($route)"] = [$index, $retrieval, 'retrieval'];
		}

		$updates = self::$cachingConfig['cache']['updates'] ?? [];
		foreach ($updates as $index => $update) {
			$route = $update['route'] ?? 'unknown';
			$data["update_$index ($route)"] = [$index, $update, 'update'];
		}

		$deletes = self::$cachingConfig['cache']['deletes'] ?? [];
		foreach ($deletes as $index => $delete) {
			$route = $delete['route'] ?? 'unknown';
			$data["delete_$index ($route)"] = [$index, $delete, 'delete'];
		}

		return $data;
	}

	public static function invalidationProvider(): array
	{
		if (!isset(self::$cachingConfig)) {
			self::setUpBeforeClass();
		}

		$data = [];

		$updates = self::$cachingConfig['cache']['updates'] ?? [];
		foreach ($updates as $index => $update) {
			$route = $update['route'] ?? 'unknown';
			if (isset($update['invalidate_patterns'])) {
				$data["update_$index ($route)"] = [$index, $update, 'update'];
			}
		}

		$deletes = self::$cachingConfig['cache']['deletes'] ?? [];
		foreach ($deletes as $index => $delete) {
			$route = $delete['route'] ?? 'unknown';
			if (isset($delete['invalidate_patterns'])) {
				$data["delete_$index ($route)"] = [$index, $delete, 'delete'];
			}
		}

		return $data;
	}

	#[Test]
	#[TestDox('Caching file should exist and be valid YAML')]
	#[Group('mantle2/caching')]
	public function testCachingFileIsValidYaml(): void
	{
		$this->assertFileExists(self::$cachingFilePath);
		$this->assertIsArray(self::$cachingConfig);
		$this->assertArrayHasKey('cache', self::$cachingConfig);
	}

	#[Test]
	#[TestDox('Cache rule #$index ($type) should have required parameters and methods')]
	#[Group('mantle2/caching')]
	#[DataProvider('allCacheRulesProvider')]
	public function testCacheRuleHasRequiredParameters(int $index, array $rule, string $type): void
	{
		$this->assertArrayHasKey('route', $rule, "$type rule #$index is missing 'route' parameter");
		$this->assertNotEmpty($rule['route'], "$type rule #$index has empty 'route' parameter");

		$this->assertArrayHasKey(
			'methods',
			$rule,
			"$type rule #$index is missing 'methods' parameter",
		);
		$this->assertIsArray($rule['methods'], "$type rule #$index 'methods' is not an array");
		$this->assertNotEmpty($rule['methods'], "$type rule #$index 'methods' is empty");

		foreach ($rule['methods'] as $method) {
			$this->assertContains(
				$method,
				['GET', 'POST', 'PATCH', 'PUT', 'DELETE'],
				"$type rule #$index has invalid method: $method",
			);
		}

		if ($type === 'retrieval') {
			$this->assertArrayHasKey(
				'key_template',
				$rule,
				"retrieval rule #$index is missing 'key_template' parameter",
			);
			$this->assertNotEmpty(
				$rule['key_template'],
				"retrieval rule #$index has empty 'key_template'",
			);

			$this->assertArrayHasKey(
				'ttl',
				$rule,
				"retrieval rule #$index is missing 'ttl' parameter",
			);
			$this->assertIsInt($rule['ttl'], "retrieval rule #$index 'ttl' is not an integer");
			$this->assertGreaterThan(0, $rule['ttl'], "retrieval rule #$index 'ttl' must be > 0");
		}

		if (in_array($type, ['update', 'delete'])) {
			$this->assertArrayHasKey(
				'invalidate_patterns',
				$rule,
				"$type rule #$index is missing 'invalidate_patterns' parameter",
			);
			$this->assertIsArray(
				$rule['invalidate_patterns'],
				"$type rule #$index 'invalidate_patterns' is not an array",
			);
			$this->assertNotEmpty(
				$rule['invalidate_patterns'],
				"$type rule #$index 'invalidate_patterns' is empty",
			);
		}
	}

	#[Test]
	#[TestDox('Cache rule #$index ($type) route should match at least one routing pattern')]
	#[Group('mantle2/caching')]
	#[DataProvider('allCacheRulesProvider')]
	public function testCacheRuleRoutesMatchRoutingFile(int $index, array $rule, string $type): void
	{
		$cacheRoutePattern = $rule['route'] ?? '';
		$this->assertNotEmpty($cacheRoutePattern, "$type rule #$index has empty route");

		$matchFound = false;
		$closestMatches = [];

		$normalizedCachePattern = $this->normalizeCachePattern($cacheRoutePattern);

		foreach (self::$routes as $routeName => $routeConfig) {
			$routePath = $routeConfig['path'] ?? '';
			$normalizedRoutePath = $this->normalizeRoutePath($routePath);

			if ($this->patternsMatch($normalizedCachePattern, $normalizedRoutePath)) {
				$matchFound = true;
				break;
			}

			$similarity = 0;
			similar_text($normalizedRoutePath, $normalizedCachePattern, $similarity);
			if ($similarity > 70) {
				$closestMatches[] =
					"$routeName: $routePath (similarity: " . round($similarity, 1) . '%)';
			}
		}

		$message =
			"$type rule #$index route pattern '$cacheRoutePattern' does not match any " .
			"route in mantle2.routing.yml.\nNormalized: '$normalizedCachePattern'";

		if (!empty($closestMatches)) {
			$message .= "\n\nPossible matches:\n  - " . implode("\n  - ", $closestMatches);
		}

		$this->assertTrue($matchFound, $message);
	}

	private function normalizeCachePattern(string $pattern): string
	{
		$normalized = $pattern;
		$normalized = preg_replace('/^\^/', '', $normalized);
		// drop lookahead/lookbehind assertions; they constrain matching but add no path text
		$normalized = preg_replace('/\(\?[!=<][^)]*\)/', '', $normalized);
		$normalized = preg_replace('/\$$/', '', $normalized);
		$normalized = str_replace('\\', '', $normalized);
		$normalized = preg_replace('/\(\[0-9\]\+\)/', '{param}', $normalized);
		$normalized = preg_replace('/\(\[a-zA-Z0-9_\]\+\)/', '{param}', $normalized);
		$normalized = preg_replace('/\(\[0-9\]\+\|\[a-zA-Z0-9_\]\+\)/', '{param}', $normalized);
		$normalized = preg_replace('/\([a-z]+(?:\|[a-z]+)+\)/', '{option}', $normalized);

		return $normalized;
	}

	private function normalizeRoutePath(string $path): string
	{
		return preg_replace('/\{[^}]+\}/', '{param}', $path);
	}

	private function patternsMatch(string $cachePattern, string $routePath): bool
	{
		if ($cachePattern === $routePath) {
			return true;
		}

		if (strpos($cachePattern, '{option}') !== false) {
			$parts = explode('{option}', $cachePattern);
			if (count($parts) === 2) {
				$prefix = preg_quote($parts[0], '/');
				$suffix = preg_quote($parts[1], '/');
				$regex = '/^' . $prefix . '[^\/]+' . $suffix . '$/';
				return preg_match($regex, $routePath) === 1;
			}
		}

		return false;
	}

	#[Test]
	#[
		TestDox(
			'Cache rule #$index ($type) invalidate_patterns should reference existing key_templates',
		),
	]
	#[Group('mantle2/caching')]
	#[DataProvider('invalidationProvider')]
	public function testInvalidatePatternsReferenceKeyTemplates(
		int $index,
		array $rule,
		string $type,
	): void {
		$invalidatePatterns = $rule['invalidate_patterns'] ?? [];
		$this->assertNotEmpty($invalidatePatterns, "$type rule #$index has no invalidate_patterns");

		$retrievals = self::$cachingConfig['cache']['retrievals'] ?? [];
		$allKeyTemplates = array_column($retrievals, 'key_template');

		foreach ($invalidatePatterns as $patternIndex => $pattern) {
			if ($this->isWildcardPattern($pattern)) {
				$this->assertTrue(
					true,
					"$type rule #$index invalidate_pattern[$patternIndex] is a wildcard pattern",
				);
				continue;
			}

			$basePattern = str_replace('*', '', $pattern);
			$basePattern = preg_replace('/:\{[a-z_]+\}/', ':*', $basePattern);

			$matchFound = false;
			$partialMatches = [];

			foreach ($allKeyTemplates as $keyTemplate) {
				$baseKeyTemplate = preg_replace('/:\{[a-z_]+\}/', ':*', $keyTemplate);

				if (str_starts_with($baseKeyTemplate, $basePattern)) {
					$matchFound = true;
					break;
				}

				if (str_contains($baseKeyTemplate, rtrim($basePattern, ':'))) {
					$partialMatches[] = $keyTemplate;
				}
			}

			$message =
				"$type rule #$index invalidate_pattern[$patternIndex] '$pattern' does " .
				"not reference any key_template in retrievals.\nBase pattern: '$basePattern'";

			if (!empty($partialMatches)) {
				$message .= "\n\nPartial matches:\n  - " . implode("\n  - ", $partialMatches);
			}

			$this->assertTrue($matchFound, $message);
		}
	}

	private function isWildcardPattern(string $pattern): bool
	{
		$parts = explode(':', $pattern);
		$wildcardCount = 0;
		foreach ($parts as $part) {
			if ($part === '*' || empty($part)) {
				$wildcardCount++;
			}
		}
		return $wildcardCount >= 2;
	}

	#[Test]
	#[TestDox('Exclusions list should not be empty')]
	#[Group('mantle2/caching')]
	public function testExclusionsIsNotEmpty(): void
	{
		$this->assertArrayHasKey('cache', self::$cachingConfig);
		$this->assertArrayHasKey('exclusions', self::$cachingConfig['cache']);

		$exclusions = self::$cachingConfig['cache']['exclusions'];
		$this->assertIsArray($exclusions, 'exclusions is not an array');
		$this->assertNotEmpty($exclusions, 'exclusions list is empty');

		foreach ($exclusions as $index => $exclusion) {
			$this->assertIsString($exclusion, "exclusions[$index] is not a string");
			$this->assertNotEmpty($exclusion, "exclusions[$index] is empty");
		}
	}

	// mirrors ResponseCacheSubscriber::findRetrievalConfig first-match semantics
	private function matchRetrieval(string $path, string $method = 'GET'): ?array
	{
		$retrievals = self::$cachingConfig['cache']['retrievals'] ?? [];
		foreach ($retrievals as $retrieval) {
			if (
				in_array($method, $retrieval['methods'], true) &&
				preg_match('#' . $retrieval['route'] . '#', $path)
			) {
				return $retrieval;
			}
		}
		return null;
	}

	#[Test]
	#[TestDox('Sibling collection routes never collapse onto the user-profile retrieval rule')]
	#[Group('mantle2/caching')]
	public function testReservedSubroutesDoNotHitProfileRule(): void
	{
		$profileKey = 'request_cache:user:profile:{uid}:req:{req_uid}';

		$profile = $this->matchRetrieval('/v2/users/123');
		$this->assertNotNull($profile, 'numeric id must match the profile rule');
		$this->assertSame($profileKey, $profile['key_template']);

		// quests is dynamic + per-query; it must not be cached at all
		$this->assertNull(
			$this->matchRetrieval('/v2/users/quests'),
			'/v2/users/quests must not match any retrieval rule',
		);

		// badges/cosmetics must fall through to their own dedicated list rules
		$badges = $this->matchRetrieval('/v2/users/badges');
		$this->assertNotNull($badges);
		$this->assertSame('request_cache:badges:list', $badges['key_template']);

		$cosmetics = $this->matchRetrieval('/v2/users/cosmetics');
		$this->assertNotNull($cosmetics);
		$this->assertSame('request_cache:cosmetics:list', $cosmetics['key_template']);

		// the "current" alias is still a profile fetch (subscriber resolves {uid} to req_uid)
		$current = $this->matchRetrieval('/v2/users/current');
		$this->assertNotNull($current, 'current must still match the profile rule');
		$this->assertSame($profileKey, $current['key_template']);

		// a username-shaped segment (not a reserved word) is still a profile lookup
		$named = $this->matchRetrieval('/v2/users/gregory');
		$this->assertNotNull($named);
		$this->assertSame($profileKey, $named['key_template']);

		// only the exact reserved words are excluded; a username prefixed with one is fine
		$prefixed = $this->matchRetrieval('/v2/users/questsmaster');
		$this->assertNotNull($prefixed);
		$this->assertSame($profileKey, $prefixed['key_template']);
	}

	#[Test]
	#[TestDox('Retrieval keys that expose per-requester data are partitioned by req_uid')]
	#[Group('mantle2/caching')]
	public function testPrivateRetrievalsKeyByRequester(): void
	{
		// endpoints whose visible payload depends on WHO is asking must include req_uid,
		// or a pre-auth cache HIT would serve one requester's view to another
		$requesterScoped = [
			'request_cache:user:profile:',
			'request_cache:user:notifications:',
			'request_cache:user:friends:',
			'request_cache:user:circle:',
		];
		$retrievals = self::$cachingConfig['cache']['retrievals'] ?? [];

		foreach ($retrievals as $retrieval) {
			$template = $retrieval['key_template'];
			foreach ($requesterScoped as $marker) {
				if (str_starts_with($template, $marker)) {
					$this->assertStringContainsString(
						'{req_uid}',
						$template,
						"retrieval '{$retrieval['route']}' exposes per-requester data " .
							'but its key is not partitioned by {req_uid}',
					);
				}
			}
		}
	}
}
