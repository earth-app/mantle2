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

	// cloud-backed flows deferred to E2E: the success paths of submitEventImage,
	// getEventImages, getEventImage, deleteEventImage(s), getUserEventImage(s),
	// deleteUserEventImage(s) all call EventsHelper image methods -> CloudHelper::sendRequest
}
