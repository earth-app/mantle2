<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Controller\EventsController;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\EventImageSubmission;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\EventsHelper;
use Drupal\node\Entity\Node;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class EventsImagesTest extends E2ETestBase
{
	protected bool $installContentTypes = true;

	// 1x1 png data url
	private const PHOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

	private function controller(): EventsController
	{
		return EventsController::create($this->container);
	}

	private function verifiedUser(): UserInterface
	{
		return $this->createUser(['field_email_verified' => true]);
	}

	private function makeEventNode(UserInterface $host): Node
	{
		$event = new Event(
			(int) $host->id(),
			'E2E Image Event',
			'A photo event',
			EventType::HYBRID,
			[ActivityType::HOBBY],
			0.0,
			0.0,
			(time() + 3600) * 1000,
			null,
			Visibility::UNLISTED,
			[],
			[],
		);
		$node = EventsHelper::createEvent($event, $host);

		// node ids reset per fresh test DB while cloud submissions are cumulative and
		// capped per event; clear any leftovers so submit is not rejected by the limit
		EventsHelper::deleteImageSubmission(null, null, (int) $node->id());

		return $node;
	}

	#[Test]
	#[TestDox('submitImage/retrieveImageSubmission/deleteImageSubmission round-trip through cloud')]
	#[Group('mantle2/events')]
	public function helperRoundTrip(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);
		$eventId = (int) $node->id();

		$submissionId = EventsHelper::submitImage($eventId, $host->id(), self::PHOTO);
		$this->assertNotNull($submissionId, 'cloud did not return a submission id');

		$single = EventsHelper::retrieveImageSubmission(null, null, $submissionId);
		$this->assertInstanceOf(EventImageSubmission::class, $single);
		$this->assertSame($submissionId, $single->submission_id);

		$byUser = EventsHelper::retrieveImageSubmission($host->id());
		$this->assertIsArray($byUser);
		$this->assertNotEmpty($byUser);
		$this->assertContainsOnlyInstancesOf(EventImageSubmission::class, $byUser);

		$this->assertTrue(EventsHelper::deleteImageSubmission($submissionId));

		$afterDelete = EventsHelper::retrieveImageSubmission(null, null, $submissionId);
		$this->assertNull($afterDelete);
	}

	#[Test]
	#[TestDox('retrieveImageSubmission returns null when no identifier is given')]
	#[Group('mantle2/events')]
	public function retrieveRequiresIdentifier(): void
	{
		$this->assertNull(EventsHelper::retrieveImageSubmission());
		$this->assertFalse(EventsHelper::deleteImageSubmission());
	}

	#[Test]
	#[TestDox('POST then GET /v2/events/{id}/images submits and lists an image via cloud')]
	#[Group('mantle2/events')]
	public function controllerSubmitAndList(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);
		$eventId = (int) $node->id();

		$submit = $this->controller()->submitEventImage(
			$eventId,
			$this->authRequest(
				$host,
				'POST',
				'/v2/events/' . $eventId . '/images',
				[],
				json_encode(['photo_url' => self::PHOTO]),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $submit->getStatusCode());
		$body = $this->decode($submit);
		$this->assertNotEmpty($body['submission_id']);

		$list = $this->controller()->getEventImages(
			$eventId,
			$this->authRequest($host, 'GET', '/v2/events/' . $eventId . '/images'),
		);
		$this->assertSame(Response::HTTP_OK, $list->getStatusCode());
		$listBody = $this->decode($list);
		$this->assertArrayHasKey('items', $listBody);
		$this->assertGreaterThanOrEqual(1, $listBody['total']);

		$delete = $this->controller()->deleteEventImages(
			$eventId,
			$this->authRequest($host, 'DELETE', '/v2/events/' . $eventId . '/images'),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $delete->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/events/{id}/images rejects a non-data-url photo before hitting cloud')]
	#[Group('mantle2/events')]
	public function controllerRejectsBadPhoto(): void
	{
		$host = $this->verifiedUser();
		$eventId = (int) $this->makeEventNode($host)->id();

		$response = $this->controller()->submitEventImage(
			$eventId,
			$this->authRequest(
				$host,
				'POST',
				'/v2/events/' . $eventId . '/images',
				[],
				json_encode(['photo_url' => 'https://example.com/not-a-data-url.png']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
	}
}
