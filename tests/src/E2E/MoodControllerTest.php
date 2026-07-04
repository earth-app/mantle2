<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Controller\MoodController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class MoodControllerTest extends E2ETestBase
{
	private function controller(): MoodController
	{
		return new MoodController();
	}

	private function freshTopic(): string
	{
		return 'e2e_' . bin2hex(random_bytes(6));
	}

	#[Test]
	#[TestDox('GET /v2/mood/{topic}/{date} returns an aggregated snapshot from cloud')]
	#[Group('mantle2/mood')]
	public function getMoodReturnsSnapshot(): void
	{
		$response = $this->controller()->getMood(
			$this->request(),
			$this->freshTopic(),
			'2026-07-03',
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());

		$body = $this->decode($response);
		$this->assertArrayHasKey('counts', $body);
		$this->assertArrayHasKey('total', $body);
		$this->assertArrayHasKey('updated_at', $body);
	}

	#[Test]
	#[TestDox('GET /v2/mood rejects malformed topic and date before hitting cloud')]
	#[Group('mantle2/mood')]
	public function getMoodValidatesInput(): void
	{
		$badTopic = $this->controller()->getMood($this->request(), 'bad topic!', '2026-07-03');
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badTopic->getStatusCode());
		$this->assertSame('Invalid topic', $this->decode($badTopic)['message']);

		$badDate = $this->controller()->getMood($this->request(), 'happiness', '07/03/2026');
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badDate->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/mood records a vote once and throttles repeats with 409')]
	#[Group('mantle2/mood')]
	public function postMoodRecordsThenThrottles(): void
	{
		$topic = $this->freshTopic();
		$date = '2026-07-03';
		$body = json_encode(['emoji' => '😍']);

		$first = $this->controller()->postMood(
			$this->request('POST', '/', [], $body),
			$topic,
			$date,
		);
		$this->assertSame(Response::HTTP_OK, $first->getStatusCode());

		$second = $this->controller()->postMood(
			$this->request('POST', '/', [], $body),
			$topic,
			$date,
		);
		$this->assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/mood rejects an emoji outside the allowed set')]
	#[Group('mantle2/mood')]
	public function postMoodRejectsBadEmoji(): void
	{
		$response = $this->controller()->postMood(
			$this->request('POST', '/', [], json_encode(['emoji' => '🚀'])),
			$this->freshTopic(),
			'2026-07-03',
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
		$this->assertSame('Invalid emoji', $this->decode($response)['message']);
	}
}
