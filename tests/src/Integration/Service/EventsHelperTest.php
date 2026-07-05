<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\EventsHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\Entity\User;
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

	// nodeToEvent parses the stored date via strtotime in the runner tz (Chicago),
	// while Event::getDate serializes in UTC; that skew is fine for most tests but
	// breaks cron window math, so write the datetime string in the local tz here
	private function persistAtInstant(
		UserInterface $host,
		int $startSeconds,
		?int $endSeconds = null,
		array $attendees = [],
	): Node {
		$event = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			$startSeconds * 1000,
			$endSeconds !== null ? $endSeconds * 1000 : null,
			$attendees,
		);
		$node = $this->persist($event, $host);
		$node->set('field_event_date', date('Y-m-d\TH:i:s', $startSeconds));
		if ($endSeconds !== null) {
			$node->set('field_event_enddate', date('Y-m-d\TH:i:s', $endSeconds));
		}
		$node->save();
		return $node;
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

	#region validateFields (branch coverage)

	private function adminUser(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				\Drupal\mantle2\Custom\AccountType::ADMINISTRATOR,
				\Drupal\mantle2\Custom\AccountType::cases(),
				true,
			),
		]);
	}

	private function proUser(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				\Drupal\mantle2\Custom\AccountType::PRO,
				\Drupal\mantle2\Custom\AccountType::cases(),
				true,
			),
		]);
	}

	#[Test]
	#[TestDox('validateFields accepts moho_ fields and typed fields for an admin')]
	#[Group('mantle2/events')]
	public function validateFieldsMohoAllowedForAdmin(): void
	{
		$admin = $this->adminUser();
		$fields = [
			'moho_id' => 'abc',
			'moho_source' => 'src',
			'moho_kind' => 'kind',
			'max_in_person' => 10,
			'max_online' => 5,
			'address' => '123 Main St',
			'cancelled' => false,
		];
		$result = EventsHelper::validateFields($fields, $admin);
		$this->assertSame($fields, $result);
	}

	public static function fieldTypeErrorProvider(): array
	{
		return [
			'link not string' => [['link' => 123], 'Field link must be a string'],
			'info not string' => [['info' => 123], 'Field info must be a string'],
			'info too long' => [
				['info' => str_repeat('a', 1001)],
				'Field info must be at most 1000 characters',
			],
			'max_in_person not numeric' => [
				['max_in_person' => 'abc'],
				'Field max_in_person must be a number',
			],
			'max_online not numeric' => [
				['max_online' => 'abc'],
				'Field max_online must be a number',
			],
			'max_online non-positive' => [
				['max_online' => 0],
				'Field max_online must be a positive number',
			],
			'icon not string' => [['icon' => 123], 'Field icon must be a string'],
			'icon too long' => [
				['icon' => str_repeat('a', 129)],
				'Field icon must be at most 128 characters',
			],
			'address not string' => [['address' => 123], 'Field address must be a string'],
			'address too long' => [
				['address' => str_repeat('a', 256)],
				'Field address must be at most 255 characters',
			],
		];
	}

	#[Test]
	#[TestDox('validateFields rejects each malformed typed field')]
	#[Group('mantle2/events')]
	#[DataProvider('fieldTypeErrorProvider')]
	public function validateFieldsTypeErrors(array $fields, string $message): void
	{
		$admin = $this->adminUser();
		$result = EventsHelper::validateFields($fields, $admin);
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
		$this->assertSame($message, json_decode($result->getContent(), true)['message']);
	}

	#[Test]
	#[TestDox('validateFields rejects a moho_ value that is not a string for an admin')]
	#[Group('mantle2/events')]
	public function validateFieldsMohoNonString(): void
	{
		$admin = $this->adminUser();
		$result = EventsHelper::validateFields(['moho_id' => 123], $admin);
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
		$this->assertSame(
			"Field 'moho_id' must be a string",
			json_decode($result->getContent(), true)['message'],
		);
	}

	#[Test]
	#[TestDox('validateFields rejects a max that exceeds the free-account capacity')]
	#[Group('mantle2/events')]
	public function validateFieldsMaxExceedsCapacity(): void
	{
		$free = $this->host();
		$inPerson = EventsHelper::validateFields(['max_in_person' => 26], $free);
		$this->assertInstanceOf(JsonResponse::class, $inPerson);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $inPerson->getStatusCode());
		$this->assertStringContainsString(
			'attendance capacity',
			json_decode($inPerson->getContent(), true)['message'],
		);

		$online = EventsHelper::validateFields(['max_online' => 26], $free);
		$this->assertInstanceOf(JsonResponse::class, $online);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $online->getStatusCode());
	}

	#[Test]
	#[TestDox('validateFields accepts a numeric-string max and an empty link')]
	#[Group('mantle2/events')]
	public function validateFieldsNumericStringAndEmptyLink(): void
	{
		$pro = $this->proUser();
		$fields = ['max_in_person' => '10', 'max_online' => '5', 'link' => ''];
		$result = EventsHelper::validateFields($fields, $pro);
		$this->assertSame($fields, $result);
	}

	#endregion

	#region validateEventData (branch coverage)

	public static function invalidEventDataBranchProvider(): array
	{
		$date = (time() + 3600) * 1000;
		$base = ['name' => 'x', 'type' => 'ONLINE', 'date' => $date, 'visibility' => 'UNLISTED'];
		return [
			'name too long' => [
				array_merge($base, ['name' => str_repeat('a', 51)]),
				'Missing or invalid name; Max length is 50 characters',
			],
			'description not string' => [
				array_merge($base, ['description' => 123]),
				'Invalid description; Max length is 3000 characters',
			],
			'description too long' => [
				array_merge($base, ['description' => str_repeat('a', 3001)]),
				'Invalid description; Max length is 3000 characters',
			],
			'censor not bool' => [
				array_merge($base, ['censor' => 'yes']),
				'Field censor must be a boolean',
			],
			'type not string' => [array_merge($base, ['type' => 123]), 'Missing or invalid type'],
			'date not integer' => [
				array_merge($base, ['date' => 'soon']),
				'Missing or invalid date',
			],
			'end date not integer' => [
				array_merge($base, ['end_date' => 'later']),
				'Invalid end date',
			],
			'too many activities' => [
				array_merge($base, ['activities' => array_fill(0, 21, 'HOBBY')]),
				'Too many activities, max is 20',
			],
			'activity not string' => [
				array_merge($base, ['activities' => [123]]),
				'Each activity must be a string',
			],
			'activities not array' => [
				array_merge($base, ['activities' => 'HOBBY']),
				'Invalid activity types',
			],
			'unknown activity id' => [
				array_merge($base, ['activities' => ['not-a-real-activity']]),
				'Invalid activity: "not-a-real-activity" is not a valid ActivityType or Activity ID',
			],
			'longitude without latitude' => [
				array_merge($base, ['location' => ['longitude' => 5.0]]),
				'Latitude is required when longitude is provided',
			],
			'latitude without longitude' => [
				array_merge($base, ['location' => ['latitude' => 5.0]]),
				'Longitude is required when latitude is provided',
			],
			'latitude not numeric' => [
				array_merge($base, ['location' => ['latitude' => 'x', 'longitude' => 5.0]]),
				'Invalid latitude',
			],
			'latitude out of range' => [
				array_merge($base, ['location' => ['latitude' => 91.0, 'longitude' => 5.0]]),
				'Invalid latitude',
			],
			'longitude not numeric' => [
				array_merge($base, ['location' => ['latitude' => 5.0, 'longitude' => 'x']]),
				'Invalid longitude',
			],
			'longitude out of range' => [
				array_merge($base, ['location' => ['latitude' => 5.0, 'longitude' => 181.0]]),
				'Invalid longitude',
			],
		];
	}

	#[Test]
	#[TestDox('validateEventData rejects every malformed field with its message')]
	#[Group('mantle2/events')]
	#[DataProvider('invalidEventDataBranchProvider')]
	public function validateEventDataBranches(array $body, string $message): void
	{
		$result = EventsHelper::validateEventData($body, $this->proUser());
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
		$this->assertSame($message, json_decode($result->getContent(), true)['message']);
	}

	#[Test]
	#[TestDox('validateEventData builds an Event with description, location, and end date')]
	#[Group('mantle2/events')]
	public function validateEventDataFullBody(): void
	{
		$pro = $this->proUser();
		$date = (time() + 3600) * 1000;
		$body = [
			'name' => 'Trail Run',
			'description' => 'Morning run',
			'type' => 'HYBRID',
			'activities' => ['HOBBY', 'SPORT'],
			'date' => $date,
			'end_date' => $date + 3600000,
			'visibility' => 'PUBLIC',
			'location' => ['latitude' => 12.5, 'longitude' => -70.25],
			'fields' => ['info' => 'bring water'],
		];
		$event = EventsHelper::validateEventData($body, $pro);
		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame('Morning run', $event->getDescription());
		$this->assertSame(EventType::HYBRID, $event->getType());
		$this->assertSame(Visibility::PUBLIC, $event->getVisibility());
		$this->assertEqualsWithDelta(12.5, $event->getLatitude(), 0.0001);
		$this->assertEqualsWithDelta(-70.25, $event->getLongitude(), 0.0001);
		$this->assertSame($date + 3600000, $event->getRawEndDate());
		$this->assertCount(2, $event->getActivities());
	}

	#[Test]
	#[TestDox('validateEventData resolves a custom activity id to an Activity object')]
	#[Group('mantle2/events')]
	public function validateEventDataCustomActivityId(): void
	{
		$this->seedActivity('mountain-biking');
		$body = [
			'name' => 'Ride',
			'type' => 'IN_PERSON',
			'activities' => ['mountain-biking'],
			'date' => (time() + 3600) * 1000,
			'visibility' => 'UNLISTED',
		];
		$event = EventsHelper::validateEventData($body, $this->host());
		$this->assertInstanceOf(Event::class, $event);
		$this->assertInstanceOf(Activity::class, $event->getActivities()[0]);
	}

	#[Test]
	#[TestDox('validateEventData allows a public event when no user is supplied (no PRO gate)')]
	#[Group('mantle2/events')]
	public function validateEventDataPublicNoUser(): void
	{
		$body = [
			'name' => 'x',
			'type' => 'ONLINE',
			'date' => (time() + 3600) * 1000,
			'visibility' => 'PUBLIC',
		];
		$event = EventsHelper::validateEventData($body, null);
		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame(1, $event->getHostId());
	}

	private function seedActivity(string $id): Node
	{
		$node = Node::create(['type' => 'activity', 'title' => $id, 'uid' => 1]);
		$node->set('field_activity_id', $id);
		$node->set('field_activity_name', ucfirst($id));
		$node->set('field_activity_description', 'desc');
		$node->set('field_activity_types', [0]);
		$node->save();
		return $node;
	}

	#endregion

	#region applyEventUpdates (branch coverage)

	public static function applyUpdateErrorProvider(): array
	{
		return [
			'name too long' => [
				['name' => str_repeat('a', 51)],
				'Invalid name; Max length is 50 characters',
			],
			'censor not bool with name' => [
				['name' => 'ok', 'censor' => 'yes'],
				'Field censor must be a boolean',
			],
			'description too long' => [
				['description' => str_repeat('a', 3001)],
				'Invalid description; Max length is 3000 characters',
			],
			'type not string' => [['type' => 123], 'Invalid type'],
			'type bad value' => [['type' => 'NOPE'], 'Invalid event type'],
			'too many activities' => [
				['activities' => array_fill(0, 21, 'HOBBY')],
				'Too many activities, max is 20',
			],
			'activity not string' => [['activities' => [123]], 'Each activity must be a string'],
			'activities not array' => [['activities' => 'HOBBY'], 'Invalid activity types'],
			'unknown activity id' => [
				['activities' => ['nope-nope']],
				'Invalid activity: "nope-nope" is not a valid ActivityType or Activity ID',
			],
			'longitude without latitude' => [
				['location' => ['longitude' => 5.0]],
				'Latitude is required when longitude is provided',
			],
			'latitude without longitude' => [
				['location' => ['latitude' => 5.0]],
				'Longitude is required when latitude is provided',
			],
			'latitude not numeric' => [
				['location' => ['latitude' => 'x', 'longitude' => 5.0]],
				'Invalid latitude: x',
			],
			'latitude out of range' => [
				['location' => ['latitude' => 200, 'longitude' => 5.0]],
				'Invalid latitude: 200',
			],
			'longitude out of range' => [
				['location' => ['latitude' => 5.0, 'longitude' => 200]],
				'Invalid longitude: 200',
			],
			'date not integer' => [
				['date' => 'soon'],
				'Invalid date: soon; Must be in the form of a number',
			],
			'end date not integer' => [
				['end_date' => 'later'],
				'Invalid end date: later; Must be in the form of a number',
			],
			'visibility not string' => [['visibility' => 123], 'Invalid visibility: 123'],
			'visibility bad value' => [['visibility' => 'NOPE'], 'Invalid visibility: NOPE'],
		];
	}

	#[Test]
	#[TestDox('applyEventUpdates rejects each malformed field with its message')]
	#[Group('mantle2/events')]
	#[DataProvider('applyUpdateErrorProvider')]
	public function applyEventUpdatesBranches(array $body, string $message): void
	{
		$host = $this->proUser();
		$event = $this->makeEvent((int) $host->id());
		$result = EventsHelper::applyEventUpdates($event, $body, $host);
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
		$this->assertSame($message, json_decode($result->getContent(), true)['message']);
	}

	#[Test]
	#[TestDox('applyEventUpdates blocks a free user switching an event to public')]
	#[Group('mantle2/events')]
	public function applyEventUpdatesPublicNeedsPro(): void
	{
		$free = $this->host();
		$event = $this->makeEvent((int) $free->id());
		$result = EventsHelper::applyEventUpdates($event, ['visibility' => 'PUBLIC'], $free);
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_PAYMENT_REQUIRED, $result->getStatusCode());
	}

	#[Test]
	#[TestDox('applyEventUpdates applies every valid field in one pass')]
	#[Group('mantle2/events')]
	public function applyEventUpdatesAllFields(): void
	{
		$pro = $this->proUser();
		$event = $this->makeEvent(
			(int) $pro->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			1000000,
		);
		$newDate = 2000000;
		$result = EventsHelper::applyEventUpdates(
			$event,
			[
				'description' => 'updated',
				'type' => 'ONLINE',
				'activities' => ['SPORT'],
				'location' => ['latitude' => 30.0, 'longitude' => 40.0],
				'date' => $newDate,
				'end_date' => $newDate + 5000,
				'visibility' => 'PUBLIC',
				'fields' => ['info' => 'note'],
			],
			$pro,
		);
		$this->assertTrue($result);
		$this->assertSame('updated', $event->getDescription());
		$this->assertSame(EventType::ONLINE, $event->getType());
		$this->assertSame(ActivityType::SPORT, $event->getActivities()[0]);
		$this->assertEqualsWithDelta(30.0, $event->getLatitude(), 0.0001);
		$this->assertEqualsWithDelta(40.0, $event->getLongitude(), 0.0001);
		$this->assertSame($newDate, $event->getRawDate());
		$this->assertSame($newDate + 5000, $event->getRawEndDate());
		$this->assertSame(Visibility::PUBLIC, $event->getVisibility());
		$this->assertSame('note', $event->getFields()['info']);
	}

	#[Test]
	#[TestDox('applyEventUpdates propagates a validateFields failure')]
	#[Group('mantle2/events')]
	public function applyEventUpdatesFieldsFailure(): void
	{
		$host = $this->host();
		$event = $this->makeEvent((int) $host->id());
		$result = EventsHelper::applyEventUpdates($event, ['fields' => ['bogus' => 'x']], $host);
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
	}

	// regression: the description branch used to discard the value and force a
	// type on the same request; description and type must update independently
	#[Test]
	#[TestDox('applyEventUpdates applies a description on its own and a type on its own')]
	#[Group('mantle2/events')]
	public function applyEventUpdatesDescriptionAndTypeIndependent(): void
	{
		$host = $this->host();

		$descOnly = $this->makeEvent((int) $host->id());
		$result = EventsHelper::applyEventUpdates(
			$descOnly,
			['description' => 'brand new description'],
			$host,
		);
		$this->assertTrue($result);
		$this->assertSame('brand new description', $descOnly->getDescription());

		$typeOnly = $this->makeEvent((int) $host->id(), EventType::HYBRID);
		$result = EventsHelper::applyEventUpdates($typeOnly, ['type' => 'ONLINE'], $host);
		$this->assertTrue($result);
		$this->assertSame(EventType::ONLINE, $typeOnly->getType());
		$this->assertSame('A community cleanup', $typeOnly->getDescription());
	}

	#endregion

	#region isVisible (mutual + trailing branch)

	#[Test]
	#[
		TestDox(
			'isVisible returns true for a non-private (UNLISTED) event to any logged-in stranger',
		),
	]
	#[Group('mantle2/events')]
	public function isVisibleUnlistedStranger(): void
	{
		$host = $this->host();
		$event = $this->makeEvent((int) $host->id(), EventType::HYBRID, Visibility::UNLISTED);
		$stranger = $this->createUser();
		$this->assertTrue(EventsHelper::isVisible($event, $stranger));
	}

	#endregion

	#region nodeToEvent (activity json branches)

	#[Test]
	#[TestDox('nodeToEvent decodes activity objects and skips invalid activity entries')]
	#[Group('mantle2/events')]
	public function nodeToEventActivityDecoding(): void
	{
		$host = $this->host();
		$node = $this->persist($this->makeEvent((int) $host->id()), $host);

		$node->set(
			'field_event_activity_types',
			json_encode([
				['type' => 'activity_type', 'value' => 'SPORT'],
				['type' => 'activity_type', 'value' => 'NOT_A_TYPE'],
				['type' => 'activity_type'],
				['type' => 'unknown'],
				'not-an-array',
				[
					'type' => 'activity',
					'id' => 'yoga',
					'name' => 'Yoga',
					'types' => ['HEALTH'],
					'description' => 'stretch',
				],
			]),
		);
		$node->save();

		$decoded = EventsHelper::nodeToEvent(Node::load($node->id()));
		$this->assertCount(2, $decoded->getActivities());
		$this->assertSame(ActivityType::SPORT, $decoded->getActivities()[0]);
		$this->assertInstanceOf(Activity::class, $decoded->getActivities()[1]);
	}

	#[Test]
	#[TestDox('nodeToEvent throws on an unparseable event date string')]
	#[Group('mantle2/events')]
	public function nodeToEventBadDateString(): void
	{
		$host = $this->host();
		$node = $this->persist($this->makeEvent((int) $host->id()), $host);
		$node->set('field_event_date', 'not-a-date');
		$node->save();

		$this->expectException(\Exception::class);
		EventsHelper::nodeToEvent(Node::load($node->id()));
	}

	#endregion

	#region serializeEvent (timing branches)

	#[Test]
	#[TestDox('serializeEvent reports has_passed and is_ongoing from the end date')]
	#[Group('mantle2/events')]
	public function serializeEventTimingBranches(): void
	{
		$host = $this->host();

		$past = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			(time() - 7200) * 1000,
			(time() - 3600) * 1000,
		);
		$pastNode = $this->persist($past, $host);
		$pastSerialized = EventsHelper::serializeEvent($past, $pastNode, $host);
		$this->assertTrue($pastSerialized['timing']['has_passed']);
		$this->assertFalse($pastSerialized['timing']['is_ongoing']);
		$this->assertIsInt($pastSerialized['timing']['ends_in']);
		$this->assertFalse($pastSerialized['timing']['is_upcoming']);

		$ongoing = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			(time() - 3600) * 1000,
			(time() + 3600) * 1000,
		);
		$ongoingNode = $this->persist($ongoing, $host);
		$ongoingSerialized = EventsHelper::serializeEvent($ongoing, $ongoingNode, $host);
		$this->assertTrue($ongoingSerialized['timing']['is_ongoing']);
		$this->assertFalse($ongoingSerialized['timing']['has_passed']);

		$noEnd = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			(time() - 3600) * 1000,
		);
		$noEndNode = $this->persist($noEnd, $host);
		$noEndSerialized = EventsHelper::serializeEvent($noEnd, $noEndNode, $host);
		$this->assertTrue($noEndSerialized['timing']['has_passed']);
		$this->assertNull($noEndSerialized['timing']['ends_in']);
	}

	#[Test]
	#[TestDox('serializeEvent lets an admin edit an event they do not host')]
	#[Group('mantle2/events')]
	public function serializeEventAdminCanEdit(): void
	{
		$host = $this->host();
		$event = $this->makeEvent((int) $host->id());
		$node = $this->persist($event, $host);

		$admin = $this->adminUser();
		$serialized = EventsHelper::serializeEvent($event, $node, $admin);
		$this->assertTrue($serialized['can_edit']);
		$this->assertFalse($serialized['is_attending']);
	}

	#endregion

	#region random selection (upcoming filter)

	#[Test]
	#[TestDox('getRandomEvent and getRandomEvents honor the upcoming filter')]
	#[Group('mantle2/events')]
	public function randomUpcomingFilter(): void
	{
		$host = $this->host();
		$past = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			(time() - 86400) * 1000,
		);
		$this->persist($past, $host);

		$this->assertNull(EventsHelper::getRandomEvent(true));
		$this->assertSame([], EventsHelper::getRandomEvents(5, true));

		$future = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			(time() + 86400) * 1000,
		);
		$this->persist($future, $host);

		$this->assertInstanceOf(Event::class, EventsHelper::getRandomEvent(true));
		$this->assertCount(1, EventsHelper::getRandomEvents(5, true));
	}

	#endregion

	#region cron notifications

	#[Test]
	#[TestDox('checkEventNotifications notifies host and attendee that an event starts soon')]
	#[Group('mantle2/events')]
	public function checkEventNotificationsStartingSoon(): void
	{
		$host = $this->host();
		$attendee = $this->createUser();
		$this->persistAtInstant($host, time() + 600, null, [(int) $attendee->id()]);

		EventsHelper::checkEventNotifications();

		$hostTitles = array_map(
			fn($n) => $n->getTitle(),
			UsersHelper::getNotifications(User::load($host->id())),
		);
		$this->assertContains('Event Starting Soon', $hostTitles);

		$attendeeTitles = array_map(
			fn($n) => $n->getTitle(),
			UsersHelper::getNotifications(User::load($attendee->id())),
		);
		$this->assertContains('Event Starting Soon', $attendeeTitles);
	}

	#[Test]
	#[TestDox('checkEventNotifications notifies about an event ending soon and one that ended')]
	#[Group('mantle2/events')]
	public function checkEventNotificationsEndingAndEnded(): void
	{
		$host = $this->host();

		$this->persistAtInstant($host, time() - 600, time() + 600);
		$this->persistAtInstant($host, time() - 7200, time() - 3600);

		EventsHelper::checkEventNotifications();

		$titles = array_map(
			fn($n) => $n->getTitle(),
			UsersHelper::getNotifications(User::load($host->id())),
		);
		$this->assertContains('Event Ending Soon', $titles);
		$this->assertContains('Event Ended', $titles);
	}

	#[Test]
	#[TestDox('checkEventNotifications only notifies the start once (Redis dedupe key)')]
	#[Group('mantle2/events')]
	public function checkEventNotificationsDedupes(): void
	{
		$host = $this->host();
		$this->persistAtInstant($host, time() + 600);

		EventsHelper::checkEventNotifications();
		EventsHelper::checkEventNotifications();

		$starting = array_filter(
			UsersHelper::getNotifications(User::load($host->id())),
			fn($n) => $n->getTitle() === 'Event Starting Soon',
		);
		$this->assertCount(1, $starting);
	}

	#[Test]
	#[TestDox('checkEventNotifications is a no-op when there are no events')]
	#[Group('mantle2/events')]
	public function checkEventNotificationsNoEvents(): void
	{
		EventsHelper::checkEventNotifications();
		$this->assertTrue(true);
	}

	#endregion

	#region expired events

	#[Test]
	#[TestDox('checkExpiredEvents deletes events past the TTL and notifies the host')]
	#[Group('mantle2/events')]
	public function checkExpiredEventsDeletes(): void
	{
		$host = $this->host();

		$expiredDate = (time() - (EventsHelper::EXPIRED_EVENTS_TTL + 86400)) * 1000;
		$expired = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			$expiredDate,
		);
		$expiredNode = $this->persist($expired, $host);

		$fresh = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			(time() + 3600) * 1000,
		);
		$freshNode = $this->persist($fresh, $host);

		EventsHelper::checkExpiredEvents();

		$this->assertNull(Node::load($expiredNode->id()));
		$this->assertNotNull(Node::load($freshNode->id()));

		$titles = array_map(
			fn($n) => $n->getTitle(),
			UsersHelper::getNotifications(User::load($host->id())),
		);
		$this->assertContains('Event Expired', $titles);
	}

	#[Test]
	#[TestDox('checkExpiredEvents keeps events whose end date is within the TTL')]
	#[Group('mantle2/events')]
	public function checkExpiredEventsKeepsRecent(): void
	{
		$host = $this->host();
		$recent = $this->makeEvent(
			(int) $host->id(),
			EventType::HYBRID,
			Visibility::UNLISTED,
			(time() - 172800) * 1000,
			(time() - 86400) * 1000,
		);
		$node = $this->persist($recent, $host);

		EventsHelper::checkExpiredEvents();
		$this->assertNotNull(Node::load($node->id()));
	}

	#[Test]
	#[TestDox('checkExpiredEvents is a no-op when there are no events')]
	#[Group('mantle2/events')]
	public function checkExpiredEventsNoEvents(): void
	{
		EventsHelper::checkExpiredEvents();
		$this->assertTrue(true);
	}

	#endregion

	#region image submission local guards

	#[Test]
	#[TestDox('submitImage throws for missing args and a missing event before the cloud call')]
	#[Group('mantle2/events')]
	public function submitImageLocalGuards(): void
	{
		$missingArgs = false;
		try {
			EventsHelper::submitImage(0, 5, '');
		} catch (InvalidArgumentException $e) {
			$missingArgs = true;
		}
		$this->assertTrue($missingArgs);

		$missingEvent = false;
		try {
			EventsHelper::submitImage(999999, 5, 'data:image/png;base64,AAAA');
		} catch (InvalidArgumentException $e) {
			$missingEvent = true;
		}
		$this->assertTrue($missingEvent);
	}

	#[Test]
	#[
		TestDox(
			'retrieveImageSubmission and deleteImageSubmission short-circuit with no identifiers',
		),
	]
	#[Group('mantle2/events')]
	public function imageSubmissionNoIdentifiers(): void
	{
		$this->assertNull(EventsHelper::retrieveImageSubmission());
		$this->assertFalse(EventsHelper::deleteImageSubmission());
	}

	#[Test]
	#[TestDox('submitImage loads the event then returns null on the degraded cloud response')]
	#[Group('mantle2/events')]
	public function submitImageDegradedCloudReturnsNull(): void
	{
		$host = $this->host();
		$node = $this->persist($this->makeEvent((int) $host->id()), $host);
		$result = EventsHelper::submitImage(
			(int) $node->id(),
			(int) $host->id(),
			'data:image/png;base64,AAAA',
		);
		$this->assertNull($result);
	}

	#endregion

	// cloud-backed success paths deferred to E2E: submitImage/retrieveImageSubmission/
	// deleteImageSubmission/deleteThumbnail all call CloudHelper::sendRequest
}
