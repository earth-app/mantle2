<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\TrailmarksController;
use Drupal\mantle2\Service\CloudHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

// cloud is dead in the integration tier (auth, geo/note validation, and the censor pre-check run
// pure-mantle2); the prompt-link + sentiment-mapping branches drive cloud via the CloudHelper seam
class TrailmarksControllerTest extends IntegrationTestBase
{
	protected function tearDown(): void
	{
		CloudHelper::setRequestOverride(null);
		parent::tearDown();
	}

	private function controller(): TrailmarksController
	{
		return new TrailmarksController();
	}

	private function user(): UserInterface
	{
		return $this->createUser();
	}

	#[Test]
	#[TestDox('GET /v2/trailmarks rejects anon and requires valid lat/lng')]
	#[Group('mantle2/trailmarks')]
	public function nearbyValidation(): void
	{
		$anon = $this->controller()->nearby(
			$this->request('GET', '/v2/trailmarks?lat=41.8&lng=-87.6'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->user();

		$missing = $this->controller()->nearby($this->authRequest($user, 'GET', '/v2/trailmarks'));
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());
		$this->assertSame('Valid lat and lng are required', $this->decode($missing)['message']);

		$ok = $this->controller()->nearby(
			$this->authRequest($user, 'GET', '/v2/trailmarks?lat=41.8781&lng=-87.6298&radius=800'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/trailmarks rejects anon and validates geo and note')]
	#[Group('mantle2/trailmarks')]
	public function createValidation(): void
	{
		$anon = $this->controller()->createTrailmark(
			$this->request('POST', '/v2/trailmarks', [], json_encode(['note' => 'hi'])),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$user = $this->user();

		$badJson = $this->controller()->createTrailmark(
			$this->authRequest($user, 'POST', '/v2/trailmarks', [], 'not-json'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());

		$badGeo = $this->controller()->createTrailmark(
			$this->authRequest($user, 'POST', '/v2/trailmarks', [], json_encode(['note' => 'hi'])),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badGeo->getStatusCode());
		$this->assertSame('Valid geo (lat, lng) is required', $this->decode($badGeo)['message']);

		$emptyNote = $this->controller()->createTrailmark(
			$this->authRequest(
				$user,
				'POST',
				'/v2/trailmarks',
				[],
				json_encode(['geo' => ['lat' => 41.8, 'lng' => -87.6], 'note' => '   ']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $emptyNote->getStatusCode());

		// a valid note passes validation + censor and is forwarded; cloud echoes the created mark
		CloudHelper::setRequestOverride(
			fn($path, $method, $data) => [
				'id' => 'u4pruabc123',
				'note' => $data['note'],
				'geo' => $data['geo'],
			],
		);
		$ok = $this->controller()->createTrailmark(
			$this->authRequest(
				$user,
				'POST',
				'/v2/trailmarks',
				[],
				json_encode([
					'geo' => ['lat' => 41.8781, 'lng' => -87.6298, 'place_label' => 'Lincoln Park'],
					'note' => 'Look up — the oaks here are ancient.',
				]),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$this->assertSame('u4pruabc123', $this->decode($ok)['id']);
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/trailmarks returns a 500 (not a fake 201) when the cloud create times out to []',
		),
	]
	#[Group('mantle2/trailmarks')]
	public function createFailsWhenCloudReturnsEmpty(): void
	{
		// CloudHelper folds a timeout / connection failure into an empty array; a create that
		// returns nothing must surface as an error, never a 201 with an empty body
		CloudHelper::setRequestOverride(fn($path, $method, $data) => []);

		$res = $this->controller()->createTrailmark(
			$this->authRequest(
				$this->user(),
				'POST',
				'/v2/trailmarks',
				[],
				json_encode([
					'geo' => ['lat' => 41.8781, 'lng' => -87.6298],
					'note' => 'A quiet bend in the river.',
				]),
			),
		);
		$this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $res->getStatusCode());
		$this->assertSame('Failed to create trailmark', $this->decode($res)['message']);
	}

	#[Test]
	#[TestDox('POST /v2/trailmarks/{id}/thank rejects anon and 404s a missing note')]
	#[Group('mantle2/trailmarks')]
	public function thankAuth(): void
	{
		$anon = $this->controller()->thank(
			$this->request('POST', '/v2/trailmarks/abc123/thank'),
			'abc123',
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		// with a dead cloud the thank degrades to empty -> 404
		$missing = $this->controller()->thank(
			$this->authRequest($this->user(), 'POST', '/v2/trailmarks/abc123/thank'),
			'abc123',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/trailmarks forwards an optional prompt_id to cloud')]
	#[Group('mantle2/trailmarks')]
	public function createForwardsPromptId(): void
	{
		$captured = [];
		CloudHelper::setRequestOverride(function ($path, $method, $data) use (&$captured) {
			$captured = ['path' => $path, 'data' => $data];
			return ['id' => 'tm1', 'prompt_id' => $data['prompt_id'] ?? null];
		});

		$ok = $this->controller()->createTrailmark(
			$this->authRequest(
				$this->user(),
				'POST',
				'/v2/trailmarks',
				[],
				json_encode([
					'geo' => ['lat' => 41.8781, 'lng' => -87.6298],
					'note' => 'A calm place to breathe.',
					'prompt_id' => '482',
				]),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$this->assertSame('/v1/trailmarks', $captured['path']);
		$this->assertSame('482', $captured['data']['prompt_id']);
	}

	#[Test]
	#[TestDox('POST /v2/trailmarks maps a cloud 422 (negative sentiment) to a gentle 400')]
	#[Group('mantle2/trailmarks')]
	public function createRejectsNegativeSentiment(): void
	{
		CloudHelper::setRequestOverride(function () {
			throw new Exception('HTTP Error: 422', 422);
		});

		$res = $this->controller()->createTrailmark(
			$this->authRequest(
				$this->user(),
				'POST',
				'/v2/trailmarks',
				[],
				json_encode([
					'geo' => ['lat' => 41.8, 'lng' => -87.6],
					'note' => 'this place is awful and everyone here is the worst',
				]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
		$this->assertStringContainsString('kind and encouraging', $this->decode($res)['message']);
	}

	#[Test]
	#[TestDox('GET /v2/prompts/{id}/trailmarks requires auth and proxies to cloud with the viewer')]
	#[Group('mantle2/trailmarks')]
	public function nearbyForPromptAuth(): void
	{
		$anon = $this->controller()->nearbyForPrompt(
			$this->request('GET', '/v2/prompts/482/trailmarks'),
			'482',
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$captured = [];
		CloudHelper::setRequestOverride(function ($path, $method, $data) use (&$captured) {
			$captured = ['path' => $path, 'data' => $data];
			return [['id' => 'tm1', 'prompt_id' => '482']];
		});

		$ok = $this->controller()->nearbyForPrompt(
			$this->authRequest($this->user(), 'GET', '/v2/prompts/482/trailmarks'),
			'482',
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('/v1/prompts/482/trailmarks', $captured['path']);
		$this->assertArrayHasKey('viewer', $captured['data']);
		$this->assertCount(1, $this->decode($ok));
	}
}
