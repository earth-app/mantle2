<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Controller\TrailmarksController;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class TrailmarksControllerTest extends E2ETestBase
{
	private function controller(): TrailmarksController
	{
		return new TrailmarksController();
	}

	private function user(): UserInterface
	{
		return $this->createUser();
	}

	// a far-flung, unique-ish location so nearby lookups don't collide across runs
	private function coords(): array
	{
		return [37.0 + mt_rand(0, 8000) / 10000, -122.0 - mt_rand(0, 8000) / 10000];
	}

	#[Test]
	#[TestDox('Create a trailmark, find it nearby, and thank it once (409 on repeat) via cloud')]
	#[Group('mantle2/trailmarks')]
	public function createNearbyThank(): void
	{
		[$lat, $lng] = $this->coords();
		$author = $this->user();

		$create = $this->controller()->createTrailmark(
			$this->authRequest(
				$author,
				'POST',
				'/v2/trailmarks',
				[],
				json_encode([
					'geo' => ['lat' => $lat, 'lng' => $lng, 'place_label' => 'E2E Overlook'],
					'note' => 'A calm place to watch the sky.',
				]),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $create->getStatusCode());
		$mark = $this->decode($create);
		$this->assertArrayHasKey('id', $mark);
		$this->assertArrayHasKey('geo', $mark);
		$this->assertSame('A calm place to watch the sky.', $mark['note']);

		$nearby = $this->controller()->nearby(
			$this->authRequest(
				$author,
				'GET',
				'/v2/trailmarks?lat=' . $lat . '&lng=' . $lng . '&radius=1000',
			),
		);
		$this->assertSame(Response::HTTP_OK, $nearby->getStatusCode());
		$ids = array_column($this->decode($nearby), 'id');
		$this->assertContains($mark['id'], $ids);

		// a different visitor thanks the note (author cannot thank their own)
		$visitor = $this->user();
		$thank = $this->controller()->thank(
			$this->authRequest($visitor, 'POST', '/v2/trailmarks/' . $mark['id'] . '/thank'),
			$mark['id'],
		);
		$this->assertSame(Response::HTTP_OK, $thank->getStatusCode());
		$this->assertTrue($this->decode($thank)['thanked']);

		// the one-thank gate (cloud) rejects a repeat
		$again = $this->controller()->thank(
			$this->authRequest($visitor, 'POST', '/v2/trailmarks/' . $mark['id'] . '/thank'),
			$mark['id'],
		);
		$this->assertSame(Response::HTTP_CONFLICT, $again->getStatusCode());
	}

	#[Test]
	#[TestDox('Thanking a missing trailmark returns 404')]
	#[Group('mantle2/trailmarks')]
	public function thankMissing(): void
	{
		$res = $this->controller()->thank(
			$this->authRequest($this->user(), 'POST', '/v2/trailmarks/deadbeefcafe/thank'),
			'deadbeefcafe',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $res->getStatusCode());
	}
}
