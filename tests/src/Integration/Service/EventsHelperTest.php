<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\EventsHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EventsHelperTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
		// dead endpoint so CloudHelper side effects (notifications) stay inert
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
	}

	private function host(array $values = []): UserInterface
	{
		return $this->createUser($values);
	}

	// builds a domain Event with valid defaults, host defaulting to uid 1
	private function makeEvent(
		int $hostId = 1,
		EventType $type = EventType::HYBRID,
		Visibility $visibility = Visibility::UNLISTED,
		?int $date = null,
		?int $endDate = null,
		array $attendees = [],
		array $fields = [],
	): Event {
		return new Event(
			$hostId,
			'Beach Cleanup',
			'A community cleanup',
			$type,
			[ActivityType::HOBBY],
			10.5,
			20.25,
			$date ?? (time() + 3600) * 1000,
			$endDate,
			$visibility,
			$attendees,
			$fields,
		);
	}

	private function persist(Event $event, UserInterface $host): Node
	{
		return EventsHelper::createEvent($event, $host);
	}

	#region loadEventContentNode

	#[Test]
	#[TestDox('loadEventContentNode returns the node, 404 for missing, 400 for wrong type')]
	#[Group('mantle2/events')]
	public function loadEventContentNode(): void
	{
		$host = $this->host();
		$node = $this->persist($this->makeEvent((int) $host->id()), $host);

		$loaded = EventsHelper::loadEventContentNode((int) $node->id());
		$this->assertInstanceOf(Node::class, $loaded);
		$this->assertSame((int) $node->id(), (int) $loaded->id());

		$missing = EventsHelper::loadEventContentNode(999999);
		$this->assertInstanceOf(JsonResponse::class, $missing);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$article = Node::create(['type' => 'article', 'title' => 'x', 'uid' => $host->id()]);
		$article->set('field_author_id', $host->id());
		$article->save();
		$wrong = EventsHelper::loadEventContentNode((int) $article->id());
		$this->assertInstanceOf(JsonResponse::class, $wrong);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $wrong->getStatusCode());
	}

	#endregion

	#region nodeToEvent / round-trip

	#[Test]
	#[TestDox('createEvent then nodeToEvent round-trips core fields and persists the node')]
	#[Group('mantle2/events')]
	public function createAndRoundTrip(): void
	{
		$host = $this->host();
		$date = (time() + 7200) * 1000;
		$event = $this->makeEvent(
			(int) $host->id(),
			EventType::ONLINE,
			Visibility::PUBLIC,
			$date,
			$date + 3600000,
			[],
			['link' => 'https://example.com', 'info' => 'bring gloves'],
		);
		$node = $this->persist($event, $host);

		$this->assertNotNull($node->id());
		$this->assertSame('event', $node->getType());
		$this->assertSame('Beach Cleanup', $node->getTitle());

		$reloaded = Node::load($node->id());
		$decoded = EventsHelper::nodeToEvent($reloaded);
		$this->assertSame('Beach Cleanup', $decoded->getName());
		$this->assertSame('A community cleanup', $decoded->getDescription());
		$this->assertSame(EventType::ONLINE, $decoded->getType());
		$this->assertSame(Visibility::PUBLIC, $decoded->getVisibility());
		$this->assertSame((int) $host->id(), $decoded->getHostId());
		// datetime fields round-trip via strtotime (runner tz), so allow a tz-wide delta
		$this->assertEqualsWithDelta($date, $decoded->getRawDate(), 86400000);
		$this->assertSame(3600000, $decoded->getRawEndDate() - $decoded->getRawDate());
		$this->assertSame('https://example.com', $decoded->getFields()['link']);
		$this->assertEqualsWithDelta(10.5, $decoded->getLatitude(), 0.0001);
		$this->assertCount(1, $decoded->getActivities());
		$this->assertInstanceOf(ActivityType::class, $decoded->getActivities()[0]);
	}

	#[Test]
	#[TestDox('nodeToEvent falls back to HYBRID and UNLISTED for unknown enum indices')]
	#[Group('mantle2/events')]
	public function nodeToEventEnumFallbacks(): void
	{
		$host = $this->host();
		$node = $this->persist($this->makeEvent((int) $host->id()), $host);
		$node->set('field_event_type', 99);
		$node->set('field_visibility', 99);
		$node->save();

		$decoded = EventsHelper::nodeToEvent(Node::load($node->id()));
		$this->assertSame(EventType::HYBRID, $decoded->getType());
		$this->assertSame(Visibility::UNLISTED, $decoded->getVisibility());
	}

	#[Test]
	#[TestDox('nodeToEvent throws when the event date is missing')]
	#[Group('mantle2/events')]
	public function nodeToEventRequiresDate(): void
	{
		$host = $this->host();
		$node = $this->persist($this->makeEvent((int) $host->id()), $host);
		$node->set('field_event_date', null);
		$node->save();

		$this->expectException(\Exception::class);
		EventsHelper::nodeToEvent(Node::load($node->id()));
	}

	#endregion

	#region validateFields

	#[Test]
	#[TestDox('validateFields returns the fields for a valid payload')]
	#[Group('mantle2/events')]
	public function validateFieldsValid(): void
	{
		$fields = ['link' => 'https://example.com', 'info' => 'hi', 'icon' => 'mdi:leaf'];
		$result = EventsHelper::validateFields($fields, $this->host());
		$this->assertSame($fields, $result);
	}

	public static function invalidFieldProvider(): array
	{
		return [
			'unknown key' => [['bogus' => 'x'], "Field 'bogus' is not allowed"],
			'bad url' => [['link' => 'not-a-url'], 'Field link must be a valid URL'],
			'non-positive max' => [
				['max_in_person' => 0],
				'Field max_in_person must be a positive number',
			],
			'cancelled not bool' => [['cancelled' => 'yes'], 'Field cancelled must be a boolean'],
		];
	}

	#[Test]
	#[TestDox('validateFields rejects invalid field payloads')]
	#[Group('mantle2/events')]
	#[DataProvider('invalidFieldProvider')]
	public function validateFieldsInvalid(array $fields, string $message): void
	{
		$result = EventsHelper::validateFields($fields, $this->host());
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
		$this->assertSame($message, json_decode($result->getContent(), true)['message']);
	}

	#[Test]
	#[TestDox('validateFields forbids moho_ fields for non-admins')]
	#[Group('mantle2/events')]
	public function validateFieldsMohoForbidden(): void
	{
		$result = EventsHelper::validateFields(['moho_id' => 'abc'], $this->host());
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_FORBIDDEN, $result->getStatusCode());
	}

	#endregion

	#region isVisible

	public static function visibilityProvider(): array
	{
		return [
			'public anon' => [Visibility::PUBLIC, false, true],
			'unlisted anon' => [Visibility::UNLISTED, false, false],
			'private anon' => [Visibility::PRIVATE, false, false],
			'unlisted logged in' => [Visibility::UNLISTED, true, true],
		];
	}

	#[Test]
	#[TestDox('isVisible enforces visibility rules by viewer')]
	#[Group('mantle2/events')]
	#[DataProvider('visibilityProvider')]
	public function isVisible(Visibility $visibility, bool $loggedIn, bool $expected): void
	{
		$host = $this->host();
		$event = $this->makeEvent((int) $host->id(), EventType::HYBRID, $visibility);
		$viewer = $loggedIn ? $this->createUser() : null;
		$this->assertSame($expected, EventsHelper::isVisible($event, $viewer));
	}

	#[Test]
	#[TestDox('isVisible lets host, attendee, and admin see a private event')]
	#[Group('mantle2/events')]
	public function isVisiblePrivilegedViewers(): void
	{
		$host = $this->host();
		$attendee = $this->createUser();
		$event = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::PRIVATE,
			null,
			null,
			[(int) $attendee->id()],
		);

		$this->assertTrue(EventsHelper::isVisible($event, $host));
		$this->assertTrue(EventsHelper::isVisible($event, $attendee));

		$stranger = $this->createUser();
		$this->assertFalse(EventsHelper::isVisible($event, $stranger));

		$admin = $this->createUser([
			'field_account_type' => (string) array_search(
				\Drupal\mantle2\Custom\AccountType::ADMINISTRATOR,
				\Drupal\mantle2\Custom\AccountType::cases(),
				true,
			),
		]);
		$this->assertTrue(EventsHelper::isVisible($event, $admin));
	}

	#endregion

	#region validateEventData

	#[Test]
	#[TestDox('validateEventData builds an Event for a valid body')]
	#[Group('mantle2/events')]
	public function validateEventDataValid(): void
	{
		$host = $this->host();
		$body = [
			'name' => 'Trail Run',
			'description' => 'Morning run',
			'type' => 'IN_PERSON',
			'activities' => ['HOBBY'],
			'date' => (time() + 3600) * 1000,
			'visibility' => 'UNLISTED',
			'location' => ['latitude' => 1.0, 'longitude' => 2.0],
		];
		$event = EventsHelper::validateEventData($body, $host);
		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame('Trail Run', $event->getName());
		$this->assertSame(EventType::IN_PERSON, $event->getType());
		$this->assertSame(Visibility::UNLISTED, $event->getVisibility());
	}

	public static function invalidEventDataProvider(): array
	{
		$date = (time() + 3600) * 1000;
		return [
			'missing name' => [
				['type' => 'ONLINE', 'date' => $date, 'visibility' => 'UNLISTED'],
				Response::HTTP_BAD_REQUEST,
			],
			'bad type' => [
				['name' => 'x', 'type' => 'NOPE', 'date' => $date, 'visibility' => 'UNLISTED'],
				Response::HTTP_BAD_REQUEST,
			],
			'missing date' => [
				['name' => 'x', 'type' => 'ONLINE', 'visibility' => 'UNLISTED'],
				Response::HTTP_BAD_REQUEST,
			],
			'bad visibility' => [
				['name' => 'x', 'type' => 'ONLINE', 'date' => $date, 'visibility' => 'NOPE'],
				Response::HTTP_BAD_REQUEST,
			],
			'end before start' => [
				[
					'name' => 'x',
					'type' => 'ONLINE',
					'date' => $date,
					'end_date' => $date - 1000,
					'visibility' => 'UNLISTED',
				],
				Response::HTTP_BAD_REQUEST,
			],
		];
	}

	#[Test]
	#[TestDox('validateEventData rejects invalid bodies')]
	#[Group('mantle2/events')]
	#[DataProvider('invalidEventDataProvider')]
	public function validateEventDataInvalid(array $body, int $status): void
	{
		$result = EventsHelper::validateEventData($body, $this->host());
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame($status, $result->getStatusCode());
	}

	#[Test]
	#[TestDox('validateEventData requires PRO for public events')]
	#[Group('mantle2/events')]
	public function validateEventDataPublicNeedsPro(): void
	{
		$free = $this->host();
		$body = [
			'name' => 'x',
			'type' => 'ONLINE',
			'date' => (time() + 3600) * 1000,
			'visibility' => 'PUBLIC',
		];
		$result = EventsHelper::validateEventData($body, $free);
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_PAYMENT_REQUIRED, $result->getStatusCode());
	}

	#endregion

	#region applyEventUpdates

	#[Test]
	#[TestDox('applyEventUpdates mutates the event in place')]
	#[Group('mantle2/events')]
	public function applyEventUpdates(): void
	{
		$host = $this->host();
		$event = $this->makeEvent((int) $host->id());
		$result = EventsHelper::applyEventUpdates(
			$event,
			['name' => 'Renamed', 'activities' => ['SPORT']],
			$host,
		);
		$this->assertTrue($result);
		$this->assertSame('Renamed', $event->getName());
		$this->assertSame(ActivityType::SPORT, $event->getActivities()[0]);
	}

	#[Test]
	#[TestDox('applyEventUpdates rejects an end date before the start date')]
	#[Group('mantle2/events')]
	public function applyEventUpdatesRejectsBadEndDate(): void
	{
		$host = $this->host();
		$event = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			1000000,
		);
		$result = EventsHelper::applyEventUpdates($event, ['end_date' => 500000], $host);
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
	}

	#endregion

	#region updateEvent / deleteEvent

	#[Test]
	#[TestDox('updateEvent persists changes and deleteEvent removes the node')]
	#[Group('mantle2/events')]
	public function updateAndDelete(): void
	{
		$host = $this->host();
		$event = $this->makeEvent((int) $host->id());
		$node = $this->persist($event, $host);

		$event->setName('Updated Name');
		EventsHelper::updateEvent($node, $event);
		$this->assertSame('Updated Name', Node::load($node->id())->get('field_event_name')->value);

		$id = $node->id();
		EventsHelper::deleteEvent($node);
		$this->assertNull(Node::load($id));
	}

	#[Test]
	#[TestDox('updateEvent rejects a non-event node')]
	#[Group('mantle2/events')]
	public function updateEventRejectsWrongType(): void
	{
		$host = $this->host();
		$article = Node::create(['type' => 'article', 'title' => 'x', 'uid' => $host->id()]);
		$article->set('field_author_id', $host->id());
		$article->save();

		$this->expectException(InvalidArgumentException::class);
		EventsHelper::updateEvent($article, $this->makeEvent((int) $host->id()));
	}

	#endregion

	#region attendance

	#[Test]
	#[TestDox('addAttendee and removeAttendee persist through updateEvent')]
	#[Group('mantle2/events')]
	public function attendanceRoundTrip(): void
	{
		$host = $this->host();
		$attendee = $this->createUser();
		$event = $this->makeEvent((int) $host->id());
		$node = $this->persist($event, $host);

		$event->addAttendee((int) $attendee->id());
		EventsHelper::updateEvent($node, $event);

		$reloaded = EventsHelper::nodeToEvent(Node::load($node->id()));
		$this->assertTrue($reloaded->isAttendee((int) $attendee->id()));
		$this->assertContainsEquals((int) $attendee->id(), $reloaded->getAttendeeIds());
		$this->assertSame(2, $reloaded->getAttendeesCount());

		$reloaded->removeAttendee((int) $attendee->id());
		EventsHelper::updateEvent($node, $reloaded);
		$after = EventsHelper::nodeToEvent(Node::load($node->id()));
		$this->assertFalse($after->isAttendee((int) $attendee->id()));
	}

	#[Test]
	#[TestDox('getLastAttendedEvent returns the users most recent event by date')]
	#[Group('mantle2/events')]
	public function getLastAttendedEvent(): void
	{
		$host = $this->host();
		$attendee = $this->createUser();
		$this->assertNull(EventsHelper::getLastAttendedEvent($attendee));

		$older = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			(time() - 86400) * 1000,
			null,
			[(int) $attendee->id()],
		);
		$older->setName('Older');
		$this->persist($older, $host);

		$newer = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			(time() + 86400) * 1000,
			null,
			[(int) $attendee->id()],
		);
		$newer->setName('Newer');
		$this->persist($newer, $host);

		$last = EventsHelper::getLastAttendedEvent($attendee);
		$this->assertInstanceOf(Event::class, $last);
		$this->assertSame('Newer', $last->getName());
	}

	#endregion

	#region random selection

	#[Test]
	#[TestDox('getRandomEvent and getRandomEvents pull persisted events')]
	#[Group('mantle2/events')]
	public function randomSelection(): void
	{
		$host = $this->host();
		$this->assertNull(EventsHelper::getRandomEvent());
		$this->assertSame([], EventsHelper::getRandomEvents());

		for ($i = 0; $i < 3; $i++) {
			$this->persist($this->makeEvent((int) $host->id()), $host);
		}

		$this->assertInstanceOf(Event::class, EventsHelper::getRandomEvent());
		$this->assertCount(2, EventsHelper::getRandomEvents(2));
	}

	#endregion

	#region serializeEvent

	#[Test]
	#[TestDox('serializeEvent exposes id, host, timing, and viewer flags')]
	#[Group('mantle2/events')]
	public function serializeEvent(): void
	{
		$host = $this->host();
		$attendee = $this->createUser();
		$event = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			(time() + 3600) * 1000,
			null,
			[(int) $attendee->id()],
		);
		$node = $this->persist($event, $host);

		$serialized = EventsHelper::serializeEvent($event, $node, $host);
		$this->assertSame(24, strlen($serialized['id']));
		$this->assertSame('Beach Cleanup', $serialized['name']);
		$this->assertIsArray($serialized['host']);
		$this->assertTrue($serialized['can_edit']);
		$this->assertArrayHasKey('timing', $serialized);
		$this->assertTrue($serialized['timing']['is_upcoming']);
		$this->assertFalse($serialized['timing']['has_passed']);

		$attendeeView = EventsHelper::serializeEvent($event, $node, $attendee);
		$this->assertTrue($attendeeView['is_attending']);
		$this->assertFalse($attendeeView['can_edit']);

		$stranger = $this->createUser();
		$strangerView = EventsHelper::serializeEvent($event, $node, $stranger);
		$this->assertFalse($strangerView['is_attending']);
		$this->assertFalse($strangerView['can_edit']);
	}

	#[Test]
	#[TestDox('serializeEvent hides internal underscore-prefixed fields')]
	#[Group('mantle2/events')]
	public function serializeEventHidesInternalFields(): void
	{
		$host = $this->host();
		$event = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			null,
			null,
			[],
			['info' => 'public', '_secret' => 'hidden'],
		);
		$node = $this->persist($event, $host);
		$serialized = EventsHelper::serializeEvent($event, $node, $host);
		$this->assertArrayHasKey('info', $serialized['fields']);
		$this->assertArrayNotHasKey('_secret', $serialized['fields']);
	}

	#endregion

	// cloud-backed methods deferred to E2E: submitImage, retrieveImageSubmission,
	// deleteImageSubmission, deleteThumbnail (all call CloudHelper::sendRequest)
}
