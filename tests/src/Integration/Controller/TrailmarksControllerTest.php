<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\TrailmarksController;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

// cloud is dead in the integration tier, so this covers auth, geo/note validation, and the censor
// pre-check (the pure-mantle2 branches); cloud round-trips are exercised in the E2E suite
class TrailmarksControllerTest extends IntegrationTestBase
{
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

		// a valid note passes validation + censor and is forwarded (cloud dead -> empty body, 201)
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
}
