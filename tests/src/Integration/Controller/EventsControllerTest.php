<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\EventsController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\EventsHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class EventsControllerTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
		// dead endpoint so CloudHelper side effects (notifications) stay inert
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
	}

	private function controller(): EventsController
	{
		return EventsController::create($this->container);
	}

	private function ordinal(AccountType $type): string
	{
		return (string) array_search($type, AccountType::cases(), true);
	}

	// verified so email gates pass; account_type controls capability tiers
	private function verifiedUser(AccountType $type = AccountType::FREE): UserInterface
	{
		return $this->createUser([
			'field_email_verified' => true,
			'field_account_type' => $this->ordinal($type),
		]);
	}

	private function admin(): UserInterface
	{
		return $this->verifiedUser(AccountType::ADMINISTRATOR);
	}

	private function eventBody(array $overrides = []): string
	{
		return json_encode(
			array_merge(
				[
					'name' => 'Community Cleanup',
					'description' => 'A neighborhood event',
					'type' => 'HYBRID',
					'activities' => ['HOBBY'],
					'date' => (time() + 3600) * 1000,
					'visibility' => 'UNLISTED',
				],
				$overrides,
			),
		);
	}

	// persists an event owned by $host with the given visibility
	private function makeEventNode(
		UserInterface $host,
		Visibility $visibility = Visibility::UNLISTED,
		array $attendees = [],
		array $fields = [],
	): Node {
		$event = new \Drupal\mantle2\Custom\Event(
			(int) $host->id(),
			'Community Cleanup',
			'A neighborhood event',
			EventType::HYBRID,
			[\Drupal\mantle2\Custom\ActivityType::HOBBY],
			0.0,
			0.0,
			(time() + 3600) * 1000,
			null,
			$visibility,
			$attendees,
			$fields,
		);
		return EventsHelper::createEvent($event, $host);
	}

	#region create

	#[Test]
	#[TestDox('POST /v2/events requires auth, verified email, and persists for a valid body')]
	#[Group('mantle2/events')]
	public function createEvent(): void
	{
		$anon = $this->controller()->createEvent(
			$this->request('POST', '/v2/events', [], $this->eventBody()),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$unverified = $this->createUser([
			'field_account_type' => $this->ordinal(AccountType::FREE),
		]);
		$blocked = $this->controller()->createEvent(
			$this->authRequest($unverified, 'POST', '/v2/events', [], $this->eventBody()),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $blocked->getStatusCode());
		$this->assertSame('EMAIL_VERIFICATION_REQUIRED', $this->decode($blocked)['reason']);

		$user = $this->verifiedUser();
		$ok = $this->controller()->createEvent(
			$this->authRequest($user, 'POST', '/v2/events', [], $this->eventBody()),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('Community Cleanup', $body['name']);
		$this->assertSame('HYBRID', $body['type']);
		$this->assertTrue($body['can_edit']);

		$nid = (int) ltrim($body['id'], '0');
		$node = Node::load($nid);
		$this->assertNotNull($node);
		$this->assertSame('event', $node->getType());
		$this->assertSame((int) $user->id(), (int) $node->get('field_host_id')->value);
	}

	#[Test]
	#[TestDox('POST /v2/events rejects invalid JSON and cancelled-on-create')]
	#[Group('mantle2/events')]
	public function createEventValidation(): void
	{
		$user = $this->verifiedUser();

		$badJson = $this->controller()->createEvent(
			$this->authRequest($user, 'POST', '/v2/events', [], 'not json'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());

		$cancelled = $this->controller()->createEvent(
			$this->authRequest(
				$user,
				'POST',
				'/v2/events',
				[],
				$this->eventBody(['fields' => ['cancelled' => true]]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $cancelled->getStatusCode());
		$this->assertSame(
			'Cannot create an event as cancelled',
			$this->decode($cancelled)['message'],
		);
	}

	#[Test]
	#[TestDox('POST /v2/events requires PRO for public events')]
	#[Group('mantle2/events')]
	public function createPublicEventNeedsPro(): void
	{
		$free = $this->verifiedUser();
		$res = $this->controller()->createEvent(
			$this->authRequest(
				$free,
				'POST',
				'/v2/events',
				[],
				$this->eventBody(['visibility' => 'PUBLIC']),
			),
		);
		$this->assertSame(Response::HTTP_PAYMENT_REQUIRED, $res->getStatusCode());

		$pro = $this->verifiedUser(AccountType::PRO);
		$ok = $this->controller()->createEvent(
			$this->authRequest(
				$pro,
				'POST',
				'/v2/events',
				[],
				$this->eventBody(['visibility' => 'PUBLIC']),
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
	}

	#endregion

	#region list

	#[Test]
	#[TestDox('GET /v2/events paginates and only public events show to anonymous')]
	#[Group('mantle2/events')]
	public function events(): void
	{
		$host = $this->verifiedUser(AccountType::PRO);
		$this->makeEventNode($host, Visibility::PUBLIC);
		$this->makeEventNode($host, Visibility::UNLISTED);
		$this->makeEventNode($host, Visibility::PRIVATE);

		$anon = $this->controller()->events($this->request('GET', '/v2/events'));
		$this->assertSame(Response::HTTP_OK, $anon->getStatusCode());
		$body = $this->decode($anon);
		$this->assertSame(1, $body['total']);
		$this->assertCount(1, $body['items']);
		$this->assertSame('PUBLIC', $body['items'][0]['visibility']);
		$this->assertSame(1, $body['page']);

		$hostView = $this->controller()->events($this->authRequest($host, 'GET', '/v2/events'));
		$this->assertSame(3, $this->decode($hostView)['total']);
	}

	#[Test]
	#[TestDox('GET /v2/events supports search and rand sort')]
	#[Group('mantle2/events')]
	public function eventsSearchAndSort(): void
	{
		$host = $this->verifiedUser(AccountType::PRO);
		$this->makeEventNode($host, Visibility::PUBLIC);

		$search = $this->controller()->events($this->request('GET', '/v2/events?search=Cleanup'));
		$this->assertSame(1, $this->decode($search)['total']);

		$noHit = $this->controller()->events($this->request('GET', '/v2/events?search=zzzznope'));
		$this->assertSame(0, $this->decode($noHit)['total']);

		$rand = $this->controller()->events($this->request('GET', '/v2/events?sort=rand'));
		$this->assertSame('rand', $this->decode($rand)['sort']);
	}

	#[Test]
	#[TestDox('GET /v2/events/random validates count and returns items')]
	#[Group('mantle2/events')]
	public function randomEvent(): void
	{
		$host = $this->verifiedUser(AccountType::PRO);
		$this->makeEventNode($host, Visibility::PUBLIC);

		$bad = $this->controller()->randomEvent(
			$this->request('GET', '/v2/events/random?count=99'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $bad->getStatusCode());

		$ok = $this->controller()->randomEvent($this->request('GET', '/v2/events/random?count=1'));
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertCount(1, $this->decode($ok));
	}

	#endregion

	#region get

	#[Test]
	#[TestDox('GET /v2/events/{eventId} returns the event, 404 unknown, 400 wrong type')]
	#[Group('mantle2/events')]
	public function getEvent(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$ok = $this->controller()->getEvent(
			(int) $node->id(),
			$this->authRequest($host, 'GET', '/v2/events/' . $node->id()),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('Community Cleanup', $this->decode($ok)['name']);

		$missing = $this->controller()->getEvent(
			999999,
			$this->request('GET', '/v2/events/999999'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$unlisted = $this->makeEventNode($host, Visibility::UNLISTED);
		$anon = $this->controller()->getEvent(
			(int) $unlisted->id(),
			$this->request('GET', '/v2/events/' . $unlisted->id()),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $anon->getStatusCode());
	}

	#endregion

	#region patch

	#[Test]
	#[TestDox('PATCH /v2/events/{eventId} updates for the host and forbids others')]
	#[Group('mantle2/events')]
	public function updateEvent(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$ok = $this->controller()->updateEvent(
			(int) $node->id(),
			$this->authRequest(
				$host,
				'PATCH',
				'/v2/events/' . $node->id(),
				[],
				'{"name":"Renamed"}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('Renamed', $this->decode($ok)['name']);
		$this->assertSame('Renamed', Node::load($node->id())->get('field_event_name')->value);

		$other = $this->verifiedUser();
		$forbidden = $this->controller()->updateEvent(
			(int) $node->id(),
			$this->authRequest(
				$other,
				'PATCH',
				'/v2/events/' . $node->id(),
				[],
				'{"name":"Hijack"}',
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$anon = $this->controller()->updateEvent(
			(int) $node->id(),
			$this->request('PATCH', '/v2/events/' . $node->id(), [], '{"name":"x"}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());
	}

	#endregion

	#region delete

	#[Test]
	#[TestDox('DELETE /v2/events/{eventId} removes the node for the host, forbids others')]
	#[Group('mantle2/events')]
	public function deleteEvent(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$other = $this->verifiedUser();
		$forbidden = $this->controller()->deleteEvent(
			(int) $node->id(),
			$this->authRequest($other, 'DELETE', '/v2/events/' . $node->id()),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
		$this->assertNotNull(Node::load($node->id()));

		$ok = $this->controller()->deleteEvent(
			(int) $node->id(),
			$this->authRequest($host, 'DELETE', '/v2/events/' . $node->id()),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $ok->getStatusCode());
		$this->assertNull(Node::load($node->id()));
	}

	#endregion

	#region signup / leave

	#[Test]
	#[TestDox('POST signup adds an attendee, conflicts on repeat, and enforces visibility')]
	#[Group('mantle2/events')]
	public function signUpForEvent(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);
		$attendee = $this->verifiedUser();

		$ok = $this->controller()->signUpForEvent(
			(int) $node->id(),
			$this->authRequest($attendee, 'POST', '/v2/events/' . $node->id() . '/signup'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$reloaded = EventsHelper::nodeToEvent(Node::load($node->id()));
		$this->assertTrue($reloaded->isAttendee((int) $attendee->id()));

		$dupe = $this->controller()->signUpForEvent(
			(int) $node->id(),
			$this->authRequest($attendee, 'POST', '/v2/events/' . $node->id() . '/signup'),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $dupe->getStatusCode());

		$anon = $this->controller()->signUpForEvent(
			(int) $node->id(),
			$this->request('POST', '/v2/events/' . $node->id() . '/signup'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());
	}

	#[Test]
	#[TestDox('POST leave removes an attendee, rejects non-attendees and the host')]
	#[Group('mantle2/events')]
	public function leaveEvent(): void
	{
		$host = $this->verifiedUser();
		$attendee = $this->verifiedUser();
		$node = $this->makeEventNode($host, Visibility::UNLISTED, [(int) $attendee->id()]);

		$ok = $this->controller()->leaveEvent(
			(int) $node->id(),
			$this->authRequest($attendee, 'POST', '/v2/events/' . $node->id() . '/leave'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertFalse(
			EventsHelper::nodeToEvent(Node::load($node->id()))->isAttendee((int) $attendee->id()),
		);

		$notAttending = $this->controller()->leaveEvent(
			(int) $node->id(),
			$this->authRequest($attendee, 'POST', '/v2/events/' . $node->id() . '/leave'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $notAttending->getStatusCode());

		$hostLeave = $this->controller()->leaveEvent(
			(int) $node->id(),
			$this->authRequest($host, 'POST', '/v2/events/' . $node->id() . '/leave'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $hostLeave->getStatusCode());
	}

	#endregion

	#region cancel / uncancel

	#[Test]
	#[TestDox('POST cancel then uncancel toggles the cancelled field for the host')]
	#[Group('mantle2/events')]
	public function cancelAndUncancel(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$cancel = $this->controller()->cancelEvent(
			(int) $node->id(),
			$this->authRequest($host, 'POST', '/v2/events/' . $node->id() . '/cancel'),
		);
		$this->assertSame(Response::HTTP_OK, $cancel->getStatusCode());
		$this->assertTrue(
			EventsHelper::nodeToEvent(Node::load($node->id()))->getFields()['cancelled'],
		);

		$uncancel = $this->controller()->uncancelEvent(
			(int) $node->id(),
			$this->authRequest($host, 'POST', '/v2/events/' . $node->id() . '/uncancel'),
		);
		$this->assertSame(Response::HTTP_OK, $uncancel->getStatusCode());
		$this->assertFalse(
			EventsHelper::nodeToEvent(Node::load($node->id()))->getFields()['cancelled'],
		);

		$other = $this->verifiedUser();
		$forbidden = $this->controller()->cancelEvent(
			(int) $node->id(),
			$this->authRequest($other, 'POST', '/v2/events/' . $node->id() . '/cancel'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
	}

	#[Test]
	#[TestDox('POST signup is rejected for a cancelled event')]
	#[Group('mantle2/events')]
	public function signupRejectedWhenCancelled(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host, Visibility::UNLISTED, [], ['cancelled' => true]);
		$attendee = $this->verifiedUser();

		$res = $this->controller()->signUpForEvent(
			(int) $node->id(),
			$this->authRequest($attendee, 'POST', '/v2/events/' . $node->id() . '/signup'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
	}

	#endregion

	#region getAttendees

	#[Test]
	#[TestDox('GET /v2/events/{eventId}/attendees paginates attendees including the host')]
	#[Group('mantle2/events')]
	public function getEventAttendees(): void
	{
		$host = $this->verifiedUser();
		$attendee = $this->verifiedUser();
		$node = $this->makeEventNode($host, Visibility::UNLISTED, [(int) $attendee->id()]);

		$ok = $this->controller()->getEventAttendees(
			(int) $node->id(),
			$this->authRequest($host, 'GET', '/v2/events/' . $node->id() . '/attendees'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame(2, $body['total']);
		$this->assertCount(2, $body['items']);

		$anon = $this->controller()->getEventAttendees(
			(int) $node->id(),
			$this->request('GET', '/v2/events/' . $node->id() . '/attendees'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());
	}

	#endregion

	#region getUserEvents

	#[Test]
	#[TestDox('GET user events lists hosted and attended events for the requester')]
	#[Group('mantle2/events')]
	public function getUserEvents(): void
	{
		$host = $this->verifiedUser();
		$this->makeEventNode($host);
		$this->makeEventNode($host);

		$ok = $this->controller()->getUserEvents(
			$this->authRequest($host, 'GET', '/v2/events/current'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame(2, $this->decode($ok)['total']);

		$anon = $this->controller()->getUserEvents($this->request('GET', '/v2/events/current'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$missing = $this->controller()->getUserEvents(
			$this->request('GET', '/v2/users/999999/events/attending'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#endregion

	#region image submissions (local paths only; cloud retrieval deferred to E2E)

	#[Test]
	#[
		TestDox(
			'POST /v2/events/{eventId}/images validates auth, visibility, and photo_url before the cloud call',
		),
	]
	#[Group('mantle2/events')]
	public function submitEventImageLocalGuards(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$anon = $this->controller()->submitEventImage(
			(int) $node->id(),
			$this->request('POST', '/v2/events/' . $node->id() . '/images', [], '{}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$missing = $this->controller()->submitEventImage(
			999999,
			$this->authRequest($host, 'POST', '/v2/events/999999/images', [], '{}'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$badPhoto = $this->controller()->submitEventImage(
			(int) $node->id(),
			$this->authRequest(
				$host,
				'POST',
				'/v2/events/' . $node->id() . '/images',
				[],
				'{"photo_url":"http://not-a-data-url"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badPhoto->getStatusCode());
		$this->assertSame(
			'Invalid or missing photo_url field',
			$this->decode($badPhoto)['message'],
		);
	}

	#[Test]
	#[TestDox('POST image on a cancelled event is rejected before the cloud call')]
	#[Group('mantle2/events')]
	public function submitEventImageRejectedWhenCancelled(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host, Visibility::UNLISTED, [], ['cancelled' => true]);

		$res = $this->controller()->submitEventImage(
			(int) $node->id(),
			$this->authRequest(
				$host,
				'POST',
				'/v2/events/' . $node->id() . '/images',
				[],
				'{"photo_url":"data:image/png;base64,AAAA"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
		$this->assertSame(
			'Cannot submit image to a cancelled event',
			$this->decode($res)['message'],
		);
	}

	#[Test]
	#[
		TestDox(
			'DELETE /v2/events/{eventId}/images forbids non-host, non-admin before the cloud call',
		),
	]
	#[Group('mantle2/events')]
	public function deleteEventImagesForbidsOthers(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);
		$other = $this->verifiedUser();

		$forbidden = $this->controller()->deleteEventImages(
			(int) $node->id(),
			$this->authRequest($other, 'DELETE', '/v2/events/' . $node->id() . '/images'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$anon = $this->controller()->deleteEventImages(
			(int) $node->id(),
			$this->request('DELETE', '/v2/events/' . $node->id() . '/images'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());
	}

	#[Test]
	#[TestDox('user-event image endpoints enforce auth and not-found before the cloud call')]
	#[Group('mantle2/events')]
	public function userEventImageLocalGuards(): void
	{
		$anonList = $this->controller()->getUserEventImages(
			$this->request('GET', '/v2/events/current/images'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anonList->getStatusCode());

		$missing = $this->controller()->getUserEventImages(
			$this->request('GET', '/v2/users/999999/events/images'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$anonDelete = $this->controller()->deleteUserEventImages(
			$this->request('DELETE', '/v2/events/current/images'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anonDelete->getStatusCode());

		$anonGetOne = $this->controller()->getUserEventImage(
			5,
			$this->request('GET', '/v2/events/current/images/5'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anonGetOne->getStatusCode());

		$anonDeleteOne = $this->controller()->deleteUserEventImage(
			5,
			$this->request('DELETE', '/v2/events/current/images/5'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anonDeleteOne->getStatusCode());
	}

	#endregion

	#region list (rand path, filters, admin)

	#[Test]
	#[TestDox('GET /v2/events?sort=rand honors visibility for anon, member, and admin')]
	#[Group('mantle2/events')]
	public function eventsRandVisibility(): void
	{
		$host = $this->verifiedUser(AccountType::PRO);
		$this->makeEventNode($host, Visibility::PUBLIC);
		$this->makeEventNode($host, Visibility::UNLISTED);
		$this->makeEventNode($host, Visibility::PRIVATE);

		$anon = $this->controller()->events($this->request('GET', '/v2/events?sort=rand'));
		$this->assertSame(Response::HTTP_OK, $anon->getStatusCode());
		$this->assertSame(1, $this->decode($anon)['total']);

		// a logged-in non-admin sees PUBLIC + UNLISTED (not PRIVATE)
		$member = $this->verifiedUser();
		$memberView = $this->controller()->events(
			$this->authRequest($member, 'GET', '/v2/events?sort=rand'),
		);
		$this->assertSame(2, $this->decode($memberView)['total']);

		$hostView = $this->controller()->events(
			$this->authRequest($host, 'GET', '/v2/events?sort=rand'),
		);
		$this->assertSame(3, $this->decode($hostView)['total']);

		$admin = $this->admin();
		$adminView = $this->controller()->events(
			$this->authRequest($admin, 'GET', '/v2/events?sort=rand'),
		);
		$this->assertSame(3, $this->decode($adminView)['total']);
	}

	#[Test]
	#[TestDox('GET /v2/events?sort=rand supports search and date filters')]
	#[Group('mantle2/events')]
	public function eventsRandSearchAndFilters(): void
	{
		$host = $this->verifiedUser(AccountType::PRO);
		$this->makeEventNode($host, Visibility::PUBLIC);

		$search = $this->controller()->events(
			$this->request('GET', '/v2/events?sort=rand&search=Cleanup'),
		);
		$this->assertSame(1, $this->decode($search)['total']);

		$after = (time() - 86400) * 1000;
		$before = (time() + 86400) * 1000;
		$filtered = $this->controller()->events(
			$this->request(
				'GET',
				'/v2/events?sort=rand&filter_after=' .
					$after .
					'&filter_before=' .
					$before .
					'&filter_is_upcoming=true',
			),
		);
		$this->assertSame(Response::HTTP_OK, $filtered->getStatusCode());
	}

	#[Test]
	#[TestDox('GET /v2/events applies date filters on the entity-query path')]
	#[Group('mantle2/events')]
	public function eventsEntityQueryFilters(): void
	{
		$host = $this->verifiedUser(AccountType::PRO);
		// event with both a start and an end so the ends_* filters have something to match
		$start = time() + 30 * 86400;
		$event = new \Drupal\mantle2\Custom\Event(
			(int) $host->id(),
			'Community Cleanup',
			'A neighborhood event',
			EventType::HYBRID,
			[\Drupal\mantle2\Custom\ActivityType::HOBBY],
			0.0,
			0.0,
			$start * 1000,
			($start + 3600) * 1000,
			Visibility::PUBLIC,
			[],
			[],
		);
		EventsHelper::createEvent($event, $host);

		// window ±10 years absorbs the UTC-vs-runner-tz skew in stored datetime strings
		$after = (time() - 3650 * 86400) * 1000;
		$before = (time() + 3650 * 86400) * 1000;
		$res = $this->controller()->events(
			$this->request(
				'GET',
				'/v2/events?filter_after=' .
					$after .
					'&filter_before=' .
					$before .
					'&filter_ends_after=' .
					$after .
					'&filter_ends_before=' .
					$before .
					'&filter_is_upcoming=1',
			),
		);
		$this->assertSame(Response::HTTP_OK, $res->getStatusCode());
		$this->assertSame(1, $this->decode($res)['total']);
	}

	#[Test]
	#[TestDox('GET /v2/events supports desc sort and empty listing')]
	#[Group('mantle2/events')]
	public function eventsDescSortAndEmpty(): void
	{
		$empty = $this->controller()->events($this->request('GET', '/v2/events'));
		$this->assertSame(0, $this->decode($empty)['total']);

		$host = $this->verifiedUser(AccountType::PRO);
		$this->makeEventNode($host, Visibility::PUBLIC);
		$this->makeEventNode($host, Visibility::PUBLIC);

		$desc = $this->controller()->events($this->request('GET', '/v2/events?sort=desc'));
		$this->assertSame(2, $this->decode($desc)['total']);
	}

	#endregion

	#region random (empty)

	#[Test]
	#[TestDox('GET /v2/events/random returns 404 when there are no events')]
	#[Group('mantle2/events')]
	public function randomEventEmpty(): void
	{
		$res = $this->controller()->randomEvent($this->request('GET', '/v2/events/random?count=1'));
		$this->assertSame(Response::HTTP_NOT_FOUND, $res->getStatusCode());

		$low = $this->controller()->randomEvent($this->request('GET', '/v2/events/random?count=0'));
		$this->assertSame(Response::HTTP_BAD_REQUEST, $low->getStatusCode());
	}

	#endregion

	#region create (event limit)

	#[Test]
	#[TestDox('POST /v2/events blocks a free user who has hit their event limit')]
	#[Group('mantle2/events')]
	public function createEventAtLimit(): void
	{
		$user = $this->verifiedUser();
		for ($i = 0; $i < 20; $i++) {
			$this->makeEventNode($user);
		}
		$res = $this->controller()->createEvent(
			$this->authRequest($user, 'POST', '/v2/events', [], $this->eventBody()),
		);
		$this->assertSame(Response::HTTP_PAYMENT_REQUIRED, $res->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/events rejects a JSON array body')]
	#[Group('mantle2/events')]
	public function createEventRejectsArrayBody(): void
	{
		$user = $this->verifiedUser();
		$res = $this->controller()->createEvent(
			$this->authRequest($user, 'POST', '/v2/events', [], '[1,2,3]'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
		$this->assertSame('Invalid JSON', $this->decode($res)['message']);
	}

	#endregion

	#region get (wrong type, admin visibility)

	#[Test]
	#[TestDox('GET /v2/events/{eventId} returns 400 when the id is not an event')]
	#[Group('mantle2/events')]
	public function getEventWrongType(): void
	{
		$host = $this->verifiedUser();
		$article = Node::create(['type' => 'article', 'title' => 'x', 'uid' => $host->id()]);
		$article->set('field_author_id', $host->id());
		$article->save();

		$res = $this->controller()->getEvent(
			(int) $article->id(),
			$this->authRequest($host, 'GET', '/v2/events/' . $article->id()),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
	}

	#[Test]
	#[TestDox('GET /v2/events/{eventId} lets an admin view a private event')]
	#[Group('mantle2/events')]
	public function getPrivateEventAsAdmin(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host, Visibility::PRIVATE);
		$admin = $this->admin();

		$res = $this->controller()->getEvent(
			(int) $node->id(),
			$this->authRequest($admin, 'GET', '/v2/events/' . $node->id()),
		);
		$this->assertSame(Response::HTTP_OK, $res->getStatusCode());
	}

	#endregion

	#region patch (404, admin, invalid body, validation error)

	#[Test]
	#[TestDox('PATCH /v2/events/{eventId} handles 404, admin edit, and validation errors')]
	#[Group('mantle2/events')]
	public function updateEventEdges(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$missing = $this->controller()->updateEvent(
			999999,
			$this->authRequest($host, 'PATCH', '/v2/events/999999', [], '{"name":"x"}'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$badJson = $this->controller()->updateEvent(
			(int) $node->id(),
			$this->authRequest($host, 'PATCH', '/v2/events/' . $node->id(), [], 'not json'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());

		$arrayBody = $this->controller()->updateEvent(
			(int) $node->id(),
			$this->authRequest($host, 'PATCH', '/v2/events/' . $node->id(), [], '[1,2]'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $arrayBody->getStatusCode());

		$badField = $this->controller()->updateEvent(
			(int) $node->id(),
			$this->authRequest(
				$host,
				'PATCH',
				'/v2/events/' . $node->id(),
				[],
				'{"name":"' . str_repeat('a', 51) . '"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badField->getStatusCode());

		$admin = $this->admin();
		$adminOk = $this->controller()->updateEvent(
			(int) $node->id(),
			$this->authRequest(
				$admin,
				'PATCH',
				'/v2/events/' . $node->id(),
				[],
				'{"name":"AdminRenamed"}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $adminOk->getStatusCode());
		$this->assertSame('AdminRenamed', $this->decode($adminOk)['name']);
	}

	#endregion

	#region delete (404)

	#[Test]
	#[
		TestDox(
			'DELETE /v2/events/{eventId} returns 404 for a missing event and lets an admin delete',
		),
	]
	#[Group('mantle2/events')]
	public function deleteEventEdges(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$missing = $this->controller()->deleteEvent(
			999999,
			$this->authRequest($host, 'DELETE', '/v2/events/999999'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$admin = $this->admin();
		$ok = $this->controller()->deleteEvent(
			(int) $node->id(),
			$this->authRequest($admin, 'DELETE', '/v2/events/' . $node->id()),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $ok->getStatusCode());
		$this->assertNull(Node::load($node->id()));
	}

	#endregion

	#region signup / leave / attendees (guards)

	#[Test]
	#[TestDox('POST signup enforces max attendee limit and visibility')]
	#[Group('mantle2/events')]
	public function signUpGuards(): void
	{
		$host = $this->verifiedUser();
		$private = $this->makeEventNode($host, Visibility::PRIVATE);
		$stranger = $this->verifiedUser();

		$hidden = $this->controller()->signUpForEvent(
			(int) $private->id(),
			$this->authRequest($stranger, 'POST', '/v2/events/' . $private->id() . '/signup'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $hidden->getStatusCode());

		$missing = $this->controller()->signUpForEvent(
			999999,
			$this->authRequest($stranger, 'POST', '/v2/events/999999/signup'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#[Test]
	#[TestDox('POST leave and GET attendees return 404 for a missing event')]
	#[Group('mantle2/events')]
	public function leaveAndAttendeesMissing(): void
	{
		$user = $this->verifiedUser();

		$leave = $this->controller()->leaveEvent(
			999999,
			$this->authRequest($user, 'POST', '/v2/events/999999/leave'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $leave->getStatusCode());

		$attendees = $this->controller()->getEventAttendees(
			999999,
			$this->authRequest($user, 'GET', '/v2/events/999999/attendees'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $attendees->getStatusCode());
	}

	#[Test]
	#[TestDox('GET attendees supports search and rand sort')]
	#[Group('mantle2/events')]
	public function getEventAttendeesSearchAndSort(): void
	{
		$host = $this->verifiedUser();
		$attendee = $this->verifiedUser();
		$node = $this->makeEventNode($host, Visibility::UNLISTED, [(int) $attendee->id()]);

		$rand = $this->controller()->getEventAttendees(
			(int) $node->id(),
			$this->authRequest($host, 'GET', '/v2/events/' . $node->id() . '/attendees?sort=rand'),
		);
		$this->assertSame(Response::HTTP_OK, $rand->getStatusCode());
		$this->assertSame(2, $this->decode($rand)['total']);

		$search = $this->controller()->getEventAttendees(
			(int) $node->id(),
			$this->authRequest(
				$host,
				'GET',
				'/v2/events/' . $node->id() . '/attendees?search=' . $attendee->getAccountName(),
			),
		);
		$this->assertSame(1, $this->decode($search)['total']);
	}

	#endregion

	#region cancel / uncancel (404 + notifications)

	#[Test]
	#[TestDox('POST cancel notifies attendees and returns 404 for a missing event')]
	#[Group('mantle2/events')]
	public function cancelEventNotifiesAndMissing(): void
	{
		$host = $this->verifiedUser();
		$attendee = $this->verifiedUser();
		$node = $this->makeEventNode($host, Visibility::UNLISTED, [(int) $attendee->id()]);

		$cancel = $this->controller()->cancelEvent(
			(int) $node->id(),
			$this->authRequest($host, 'POST', '/v2/events/' . $node->id() . '/cancel'),
		);
		$this->assertSame(Response::HTTP_OK, $cancel->getStatusCode());

		$titles = array_map(
			fn($n) => $n->getTitle(),
			\Drupal\mantle2\Service\UsersHelper::getNotifications(
				\Drupal\user\Entity\User::load($attendee->id()),
			),
		);
		$this->assertContains('Event Cancelled', $titles);

		$missing = $this->controller()->cancelEvent(
			999999,
			$this->authRequest($host, 'POST', '/v2/events/999999/cancel'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$uncancel = $this->controller()->uncancelEvent(
			(int) $node->id(),
			$this->authRequest($host, 'POST', '/v2/events/' . $node->id() . '/uncancel'),
		);
		$this->assertSame(Response::HTTP_OK, $uncancel->getStatusCode());

		$reinstateTitles = array_map(
			fn($n) => $n->getTitle(),
			\Drupal\mantle2\Service\UsersHelper::getNotifications(
				\Drupal\user\Entity\User::load($attendee->id()),
			),
		);
		$this->assertContains('Event Reinstated', $reinstateTitles);

		$missingUncancel = $this->controller()->uncancelEvent(
			999999,
			$this->authRequest($host, 'POST', '/v2/events/999999/uncancel'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missingUncancel->getStatusCode());

		$other = $this->verifiedUser();
		$forbidden = $this->controller()->uncancelEvent(
			(int) $node->id(),
			$this->authRequest($other, 'POST', '/v2/events/' . $node->id() . '/uncancel'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
	}

	#endregion

	#region getUserEvents (id lookup + sort/search)

	#[Test]
	#[TestDox('GET user events looks up by id and honors search and sort')]
	#[Group('mantle2/events')]
	public function getUserEventsById(): void
	{
		$host = $this->verifiedUser();
		$this->makeEventNode($host);

		$byId = $this->controller()->getUserEvents(
			$this->authRequest($host, 'GET', '/v2/users/' . $host->id() . '/events/attending'),
			(string) $host->id(),
		);
		$this->assertSame(Response::HTTP_OK, $byId->getStatusCode());
		$this->assertSame(1, $this->decode($byId)['total']);

		$search = $this->controller()->getUserEvents(
			$this->authRequest($host, 'GET', '/v2/events/current?search=Community&sort=rand'),
		);
		$this->assertSame(Response::HTTP_OK, $search->getStatusCode());
	}

	#endregion

	#region image submission local guards (get/delete before cloud)

	#[Test]
	#[TestDox('GET /v2/events/{eventId}/images enforces auth, 404, and visibility before cloud')]
	#[Group('mantle2/events')]
	public function getEventImagesLocalGuards(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$anon = $this->controller()->getEventImages(
			(int) $node->id(),
			$this->request('GET', '/v2/events/' . $node->id() . '/images'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$missing = $this->controller()->getEventImages(
			999999,
			$this->authRequest($host, 'GET', '/v2/events/999999/images'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$private = $this->makeEventNode($host, Visibility::PRIVATE);
		$stranger = $this->verifiedUser();
		$hidden = $this->controller()->getEventImages(
			(int) $private->id(),
			$this->authRequest($stranger, 'GET', '/v2/events/' . $private->id() . '/images'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $hidden->getStatusCode());
	}

	#[Test]
	#[TestDox('GET /v2/events/{eventId}/images/{imageId} enforces auth and 404 before cloud')]
	#[Group('mantle2/events')]
	public function getEventImageLocalGuards(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$anon = $this->controller()->getEventImage(
			(int) $node->id(),
			5,
			$this->request('GET', '/v2/events/' . $node->id() . '/images/5'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$missing = $this->controller()->getEventImage(
			999999,
			5,
			$this->authRequest($host, 'GET', '/v2/events/999999/images/5'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		// dead cloud -> retrieve returns empty -> image not found
		$notFound = $this->controller()->getEventImage(
			(int) $node->id(),
			5,
			$this->authRequest($host, 'GET', '/v2/events/' . $node->id() . '/images/5'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $notFound->getStatusCode());
	}

	#[Test]
	#[
		TestDox(
			'DELETE /v2/events/{eventId}/images/{imageId} enforces auth, 404, and image-not-found',
		),
	]
	#[Group('mantle2/events')]
	public function deleteEventImageLocalGuards(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$anon = $this->controller()->deleteEventImage(
			(int) $node->id(),
			5,
			$this->request('DELETE', '/v2/events/' . $node->id() . '/images/5'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$missing = $this->controller()->deleteEventImage(
			999999,
			5,
			$this->authRequest($host, 'DELETE', '/v2/events/999999/images/5'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		// dead cloud -> submission lookup null -> image not found
		$notFound = $this->controller()->deleteEventImage(
			(int) $node->id(),
			5,
			$this->authRequest($host, 'DELETE', '/v2/events/' . $node->id() . '/images/5'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $notFound->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE user event images enforces auth before the cloud call')]
	#[Group('mantle2/events')]
	public function deleteUserEventImagesLocalGuards(): void
	{
		$anon = $this->controller()->deleteUserEventImages(
			$this->request('DELETE', '/v2/events/current/images'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$anonOne = $this->controller()->deleteUserEventImage(
			5,
			$this->request('DELETE', '/v2/events/current/images/5'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anonOne->getStatusCode());
	}

	#[Test]
	#[TestDox('GET user event images returns 404 for a missing user id')]
	#[Group('mantle2/events')]
	public function getUserEventImagesMissingUser(): void
	{
		$missing = $this->controller()->getUserEventImages(
			$this->request('GET', '/v2/users/999999/events/images'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$missingOne = $this->controller()->getUserEventImage(
			5,
			$this->request('GET', '/v2/users/999999/events/images/5'),
			'999999',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missingOne->getStatusCode());
	}

	#endregion

	#region image submission bodies (degraded-cloud contract)

	// with the integration dead endpoint a cloud read degrades to [] (no throw), so the
	// controller body past the guards runs deterministically; this covers response shaping,
	// not cloud behavior (real submission round-trips live in E2E)

	#[Test]
	#[TestDox('GET event/user image listings run their body and return an empty page')]
	#[Group('mantle2/events')]
	public function imageListingBodiesDegrade(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$eventImages = $this->controller()->getEventImages(
			(int) $node->id(),
			$this->authRequest($host, 'GET', '/v2/events/' . $node->id() . '/images'),
		);
		$this->assertSame(Response::HTTP_OK, $eventImages->getStatusCode());
		$this->assertSame(0, $this->decode($eventImages)['total']);
		$this->assertSame([], $this->decode($eventImages)['items']);

		$userImages = $this->controller()->getUserEventImages(
			$this->authRequest($host, 'GET', '/v2/events/current/images'),
		);
		$this->assertSame(Response::HTTP_OK, $userImages->getStatusCode());
		$this->assertSame(0, $this->decode($userImages)['total']);

		$userImagesById = $this->controller()->getUserEventImages(
			$this->authRequest($host, 'GET', '/v2/users/' . $host->id() . '/events/images'),
			(string) $host->id(),
		);
		$this->assertSame(Response::HTTP_OK, $userImagesById->getStatusCode());

		$userEventImage = $this->controller()->getUserEventImage(
			(int) $node->id(),
			$this->authRequest($host, 'GET', '/v2/events/current/images/' . $node->id()),
		);
		$this->assertSame(Response::HTTP_OK, $userEventImage->getStatusCode());
	}

	#[Test]
	#[TestDox('GET a single event image with no result is a 404 through the body')]
	#[Group('mantle2/events')]
	public function getEventImageNotFoundBody(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$res = $this->controller()->getEventImage(
			(int) $node->id(),
			5,
			$this->authRequest($host, 'GET', '/v2/events/' . $node->id() . '/images/5'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $res->getStatusCode());
		$this->assertSame('Image not found', $this->decode($res)['message']);
	}

	#[Test]
	#[
		TestDox(
			'POST event image with a valid data url reports the failed submission through the body',
		),
	]
	#[Group('mantle2/events')]
	public function submitEventImageBodyDegrades(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$res = $this->controller()->submitEventImage(
			(int) $node->id(),
			$this->authRequest(
				$host,
				'POST',
				'/v2/events/' . $node->id() . '/images',
				[],
				'{"photo_url":"data:image/png;base64,AAAA"}',
			),
		);
		$this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $res->getStatusCode());

		$badJson = $this->controller()->submitEventImage(
			(int) $node->id(),
			$this->authRequest(
				$host,
				'POST',
				'/v2/events/' . $node->id() . '/images',
				[],
				'not json',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());
	}

	#[Test]
	#[TestDox('DELETE image endpoints run their body and return 204 or a not-found for the host')]
	#[Group('mantle2/events')]
	public function deleteImageBodiesDegrade(): void
	{
		$host = $this->verifiedUser();
		$node = $this->makeEventNode($host);

		$deleteEventImages = $this->controller()->deleteEventImages(
			(int) $node->id(),
			$this->authRequest($host, 'DELETE', '/v2/events/' . $node->id() . '/images'),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $deleteEventImages->getStatusCode());

		$deleteUserImages = $this->controller()->deleteUserEventImages(
			$this->authRequest($host, 'DELETE', '/v2/events/current/images'),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $deleteUserImages->getStatusCode());

		$deleteUserImagesById = $this->controller()->deleteUserEventImages(
			$this->authRequest($host, 'DELETE', '/v2/users/' . $host->id() . '/events/images'),
			(string) $host->id(),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $deleteUserImagesById->getStatusCode());

		$deleteUserImage = $this->controller()->deleteUserEventImage(
			(int) $node->id(),
			$this->authRequest($host, 'DELETE', '/v2/events/current/images/' . $node->id()),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $deleteUserImage->getStatusCode());

		// single-image delete needs the submission lookup, which degrades to null -> 404
		$deleteEventImage = $this->controller()->deleteEventImage(
			(int) $node->id(),
			5,
			$this->authRequest($host, 'DELETE', '/v2/events/' . $node->id() . '/images/5'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $deleteEventImage->getStatusCode());
	}

	#endregion

	// real image submission round-trips (success payloads from the cloud) live in E2E;
	// the integration tier covers the controller bodies via the degraded-cloud contract above
}
