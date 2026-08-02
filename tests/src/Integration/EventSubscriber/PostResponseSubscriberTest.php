<?php

namespace Drupal\Tests\mantle2\Integration\EventSubscriber;

use DateTime;
use DateTimeZone;
use Drupal\mantle2\EventSubscriber\PostResponseSubscriber;
use Drupal\mantle2\Service\CloudHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class PostResponseSubscriberTest extends IntegrationTestBase
{
	/** @var array<int,array{path:string,method:string,data:array}> */
	private array $cloudCalls = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->cloudCalls = [];
		CloudHelper::setRequestOverride(function (string $path, string $method, array $data) {
			$this->cloudCalls[] = ['path' => $path, 'method' => $method, 'data' => $data];
			return [];
		});
	}

	protected function tearDown(): void
	{
		CloudHelper::setRequestOverride(null);
		parent::tearDown();
	}

	private function terminate(Request $request, Response $response): void
	{
		$event = new TerminateEvent($this->container->get('http_kernel'), $request, $response);
		new PostResponseSubscriber()->onTerminate($event);
	}

	private function routed(
		string $method,
		string $route,
		string $uri,
		?UserInterface $user = null,
		string $body = '{}',
	): Request {
		$request = $user
			? $this->authRequest($user, $method, $uri, [], null)
			: $this->request($method, $uri);
		$request->attributes->set('_route', $route);
		return $request;
	}

	#region Cloud Call Assertions

	/** badge ids passed to /v1/users/badges/{uid}/{badge}/grant */
	private function grantedBadges(): array
	{
		$granted = [];
		foreach ($this->cloudCalls as $call) {
			if (preg_match('#^/v1/users/badges/[^/]+/([^/]+)/grant$#', $call['path'], $matches)) {
				$granted[] = $matches[1];
			}
		}
		return $granted;
	}

	/** tracker_id => value pairs pushed to /v1/users/badges/{uid}/track */
	private function trackedProgress(): array
	{
		$tracked = [];
		foreach ($this->trackCalls() as $call) {
			$tracked[$call['data']['tracker_id']] = $call['data']['value'];
		}
		return $tracked;
	}

	/** every raw tracker push, so repeats of one tracker_id stay distinguishable */
	private function trackCalls(): array
	{
		return array_values(
			array_filter($this->cloudCalls, fn($call) => str_ends_with($call['path'], '/track')),
		);
	}

	/** @return array<int,array{title:string,message:string,link:?string}> */
	private function notificationsOf(UserInterface $user): array
	{
		$fresh = User::load($user->id());
		return array_map(
			fn($notification) => [
				'title' => $notification->getTitle(),
				'message' => $notification->getMessage(),
				'link' => $notification->getLink(),
			],
			UsersHelper::getNotifications($fresh),
		);
	}

	private function notificationTitles(UserInterface $user): array
	{
		return array_column($this->notificationsOf($user), 'title');
	}

	#endregion

	#[Test]
	#[TestDox('Subscribes to KernelEvents::TERMINATE')]
	#[Group('mantle2/subscribers')]
	public function subscribedEvents(): void
	{
		$events = PostResponseSubscriber::getSubscribedEvents();
		$this->assertArrayHasKey(KernelEvents::TERMINATE, $events);
		$this->assertSame('onTerminate', $events[KernelEvents::TERMINATE]);
	}

	#[Test]
	#[TestDox('An unmapped route triggers no callback and does not throw')]
	#[Group('mantle2/subscribers')]
	public function unmappedRouteNoop(): void
	{
		$request = $this->routed('GET', 'mantle2.info', '/v2/info');
		$this->terminate($request, new JsonResponse(['status' => 'active']));
		$this->assertSame([], $this->cloudCalls);
	}

	#[Test]
	#[
		TestDox(
			'A mapped route with an anonymous requester is a no-op (callbacks bail on null user)',
		),
	]
	#[Group('mantle2/subscribers')]
	public function anonymousRequesterNoop(): void
	{
		$request = $this->routed('PUT', 'mantle2.users.current.circle.add', '/v2/users/@me/circle');
		$this->terminate($request, new JsonResponse(['id' => 5]));
		$this->assertSame([], $this->grantedBadges());
	}

	#[Test]
	#[TestDox('Adding to a circle grants close_friends')]
	#[Group('mantle2/subscribers')]
	public function circleAddGrantsCloseFriends(): void
	{
		$user = $this->createUser();
		$request = $this->routed(
			'PUT',
			'mantle2.users.current.circle.add',
			'/v2/users/@me/circle',
			$user,
		);
		$this->terminate($request, new JsonResponse(['id' => 7]));

		$this->assertSame(['close_friends'], $this->grantedBadges());
	}

	#[Test]
	#[TestDox('Non-JSON response content decodes to an empty data array without error')]
	#[Group('mantle2/subscribers')]
	public function nonJsonBodyDecodesToEmpty(): void
	{
		$user = $this->createUser();
		$request = $this->routed(
			'PATCH',
			'mantle2.users.current.activities.set',
			'/v2/users/@me/activities',
			$user,
		);
		$this->terminate($request, new Response('not json at all'));

		// no activities key means nothing to track, but the callback must still run cleanly
		$this->assertSame([], $this->trackedProgress());
	}

	#[Test]
	#[TestDox('A mapped route with no _route attribute set does not match any callback')]
	#[Group('mantle2/subscribers')]
	public function missingRouteAttribute(): void
	{
		$user = $this->createUser();
		$request = $this->authRequest($user, 'PUT', '/v2/users/@me/circle');
		$this->terminate($request, new JsonResponse(['id' => 1]));

		$this->assertSame([], $this->grantedBadges());
	}

	#region Creation Fan-Out

	// makes $adder follow $author so the author's creations reach the adder's feed
	private function addsAsFriend(UserInterface $adder, UserInterface $author): void
	{
		UsersHelper::addFriend($adder, $author);
	}

	private function createdPrompt(UserInterface $author, array $body): void
	{
		$this->terminate(
			$this->routed('POST', 'mantle2.prompts.create', '/v2/prompts', $author),
			new JsonResponse($body),
		);
	}

	#[Test]
	#[TestDox('Creating a prompt notifies every user who added the author')]
	#[Group('mantle2/subscribers')]
	public function promptCreateNotifiesEveryAdder(): void
	{
		$author = $this->createUser(['name' => 'author']);
		$first = $this->createUser(['name' => 'first_adder']);
		$second = $this->createUser(['name' => 'second_adder']);
		$stranger = $this->createUser(['name' => 'stranger']);

		$this->addsAsFriend($first, $author);
		$this->addsAsFriend($second, $author);

		$this->createdPrompt($author, ['id' => 12, 'prompt' => 'What did you notice today?']);

		$expected = [
			'title' => 'New Prompt from @author',
			'message' => 'What did you notice today?',
			'link' => '/prompts/12',
		];
		$this->assertContains($expected, $this->notificationsOf($first));
		$this->assertContains($expected, $this->notificationsOf($second));
		$this->assertSame([], $this->notificationsOf($stranger));
		$this->assertSame(['prompts_created' => 12], $this->trackedProgress());
	}

	#[Test]
	#[TestDox('A creation with no id in the response notifies nobody')]
	#[Group('mantle2/subscribers')]
	public function creationWithoutIdNotifiesNobody(): void
	{
		$author = $this->createUser(['name' => 'author']);
		$adder = $this->createUser(['name' => 'adder']);
		$this->addsAsFriend($adder, $author);

		$this->createdPrompt($author, ['prompt' => 'no id here']);

		$this->assertSame([], $this->notificationsOf($adder));
		$this->assertSame([], $this->trackedProgress());
	}

	#[Test]
	#[TestDox('A creation falls back to a generic message when the prompt text is missing')]
	#[Group('mantle2/subscribers')]
	public function creationFallsBackToAGenericMessage(): void
	{
		$author = $this->createUser(['name' => 'author']);
		$adder = $this->createUser(['name' => 'adder']);
		$this->addsAsFriend($adder, $author);

		$this->createdPrompt($author, ['id' => 3]);

		$this->assertSame(
			['Click to see what they have to say'],
			array_column($this->notificationsOf($adder), 'message'),
		);
	}

	#[Test]
	#[TestDox('An author nobody added notifies nobody and still tracks progress')]
	#[Group('mantle2/subscribers')]
	public function authorWithNoAddersNotifiesNobody(): void
	{
		$author = $this->createUser(['name' => 'author']);
		$stranger = $this->createUser(['name' => 'stranger']);

		$this->createdPrompt($author, ['id' => 8, 'prompt' => 'alone']);

		$this->assertSame([], $this->notificationsOf($stranger));
		$this->assertSame(['prompts_created' => 8], $this->trackedProgress());
	}

	#[Test]
	#[TestDox('Creation notifications stop once the adder hourly budget is spent')]
	#[Group('mantle2/subscribers')]
	public function creationNotificationsStopAtTheHourlyBudget(): void
	{
		$author = $this->createUser(['name' => 'author']);
		$adder = $this->createUser(['name' => 'adder']);
		$this->addsAsFriend($adder, $author);

		// the budget is 5 creations per hour per adder; a bulk import from cloud
		// must not fan out beyond that
		for ($i = 1; $i <= 8; $i++) {
			$this->createdPrompt($author, ['id' => $i, 'prompt' => 'prompt ' . $i]);
		}

		$this->assertCount(5, $this->notificationsOf($adder));
		$this->assertCount(8, $this->trackCalls(), 'progress tracking is never budgeted');
	}

	#[Test]
	#[TestDox('The creation budget is spent per adder, never shared between them')]
	#[Group('mantle2/subscribers')]
	public function creationBudgetIsPerAdder(): void
	{
		$author = $this->createUser(['name' => 'author']);
		$spent = $this->createUser(['name' => 'spent_adder']);
		$fresh = $this->createUser(['name' => 'fresh_adder']);
		$this->addsAsFriend($spent, $author);
		$this->addsAsFriend($fresh, $author);

		// pre-exhaust only the first adder's window
		RedisHelper::set(
			'notify_creation_rate_limit_' . $spent->id(),
			['window_start' => time(), 'count' => 5],
			3600,
		);

		$this->createdPrompt($author, ['id' => 21, 'prompt' => 'still worth telling someone']);

		$this->assertSame([], $this->notificationsOf($spent));
		$this->assertSame(['New Prompt from @author'], $this->notificationTitles($fresh));
	}

	#[Test]
	#[TestDox('A stale rate-limit window resets instead of suppressing forever')]
	#[Group('mantle2/subscribers')]
	public function staleBudgetWindowResets(): void
	{
		$author = $this->createUser(['name' => 'author']);
		$adder = $this->createUser(['name' => 'adder']);
		$this->addsAsFriend($adder, $author);

		// a window opened more than an hour ago is expired, so the count restarts
		RedisHelper::set(
			'notify_creation_rate_limit_' . $adder->id(),
			['window_start' => time() - 7200, 'count' => 5],
			3600,
		);

		$this->createdPrompt($author, ['id' => 31, 'prompt' => 'new hour, new budget']);

		$this->assertSame(['New Prompt from @author'], $this->notificationTitles($adder));
	}

	#[Test]
	#[TestDox('Creating an event notifies adders and tracks events_created')]
	#[Group('mantle2/subscribers')]
	public function eventCreateNotifiesAdders(): void
	{
		$author = $this->createUser(['name' => 'host']);
		$adder = $this->createUser(['name' => 'adder']);
		$this->addsAsFriend($adder, $author);

		$this->terminate(
			$this->routed('POST', 'mantle2.events.create', '/v2/events', $author),
			new JsonResponse(['id' => 55]),
		);

		$this->assertSame(['New Event from @host'], $this->notificationTitles($adder));
		$this->assertSame(['events_created' => 55], $this->trackedProgress());
	}

	#[Test]
	#[TestDox('Creating an article notifies adders with the generic message when the node is gone')]
	#[Group('mantle2/subscribers')]
	public function articleCreateNotifiesAdders(): void
	{
		$author = $this->createUser(['name' => 'writer']);
		$adder = $this->createUser(['name' => 'adder']);
		$this->addsAsFriend($adder, $author);

		$this->terminate(
			$this->routed('POST', 'mantle2.articles.create', '/v2/articles', $author),
			new JsonResponse(['id' => 91]),
		);

		$this->assertSame(
			[
				[
					'title' => 'New Article from @writer',
					'message' => 'Click to view what they wrote about',
					'link' => '/articles/91',
				],
			],
			$this->notificationsOf($adder),
		);
		$this->assertSame(['articles_created' => 91], $this->trackedProgress());
	}

	#[Test]
	#[TestDox('Responding to a prompt tracks progress without fanning out a notification')]
	#[Group('mantle2/subscribers')]
	public function promptResponseTracksOnly(): void
	{
		$author = $this->createUser(['name' => 'responder']);
		$adder = $this->createUser(['name' => 'adder']);
		$this->addsAsFriend($adder, $author);

		$this->terminate(
			$this->routed(
				'POST',
				'mantle2.prompts.responses.create',
				'/v2/prompts/1/responses',
				$author,
			),
			new JsonResponse(['id' => 77]),
		);

		$this->assertSame(['prompts_responded' => 77], $this->trackedProgress());
		$this->assertSame([], $this->notificationsOf($adder));
	}

	#endregion

	#region Friend Badges

	private function friendAdded(
		UserInterface $user,
		?UserInterface $friend,
		array $extra = [],
	): void {
		$body = $friend ? ['friend' => ['id' => (int) $friend->id()] + $extra] : $extra;
		$this->terminate(
			$this->routed(
				'PUT',
				'mantle2.users.current.friends.add',
				'/v2/users/current/friends',
				$user,
			),
			new JsonResponse($body),
		);
	}

	#[Test]
	#[TestDox('Befriending an administrator grants you_know_ball')]
	#[Group('mantle2/subscribers')]
	public function befriendingAnAdminGrantsYouKnowBall(): void
	{
		$user = $this->createUser(['name' => 'fan', 'field_country' => 'US']);
		$admin = $this->createUser(['name' => 'staff', 'field_country' => 'US']);
		$admin->addRole($this->adminRole());
		$admin->save();

		$this->friendAdded($user, $admin);

		$this->assertContains('you_know_ball', $this->grantedBadges());
		$this->assertNotContains('outreacher', $this->grantedBadges());
	}

	#[Test]
	#[TestDox('Befriending someone in another country grants outreacher')]
	#[Group('mantle2/subscribers')]
	public function befriendingAcrossBordersGrantsOutreacher(): void
	{
		$user = $this->createUser(['name' => 'local', 'field_country' => 'US']);
		$friend = $this->createUser(['name' => 'abroad', 'field_country' => 'ca']);

		$this->friendAdded($user, $friend);

		// the comparison is case-insensitive, so "ca" and "US" still count as different
		$this->assertSame(['outreacher'], $this->grantedBadges());
		$this->assertSame(['friends_added' => (int) $friend->id()], $this->trackedProgress());
	}

	#[Test]
	#[TestDox('Befriending someone in the same country grants nothing')]
	#[Group('mantle2/subscribers')]
	public function befriendingSameCountryGrantsNothing(): void
	{
		$user = $this->createUser(['name' => 'local', 'field_country' => 'US']);
		$friend = $this->createUser(['name' => 'neighbour', 'field_country' => 'US']);

		$this->friendAdded($user, $friend);

		$this->assertSame([], $this->grantedBadges());
	}

	#[Test]
	#[TestDox('A missing country on either side never grants outreacher')]
	#[Group('mantle2/subscribers')]
	public function unknownCountryNeverGrantsOutreacher(): void
	{
		// field_country defaults to US, so an unknown country has to be stored empty
		$user = $this->createUser(['name' => 'local', 'field_country' => 'US']);
		$friend = $this->createUser(['name' => 'unknown', 'field_country' => '']);

		$this->friendAdded($user, $friend);

		$this->assertSame([], $this->grantedBadges());
	}

	#[Test]
	#[TestDox('A friend payload with no id tracks nothing and grants nothing')]
	#[Group('mantle2/subscribers')]
	public function friendPayloadWithoutIdIsIgnored(): void
	{
		$user = $this->createUser(['name' => 'local', 'field_country' => 'US']);

		$this->friendAdded($user, null, ['friend' => ['name' => 'nameless']]);

		$this->assertSame([], $this->grantedBadges());
		$this->assertSame([], $this->trackedProgress());
	}

	#[Test]
	#[TestDox('A friend id that no longer resolves still tracks progress')]
	#[Group('mantle2/subscribers')]
	public function deletedFriendStillTracksProgress(): void
	{
		$user = $this->createUser(['name' => 'local', 'field_country' => 'US']);

		$this->terminate(
			$this->routed(
				'PUT',
				'mantle2.users.current.friends.add',
				'/v2/users/current/friends',
				$user,
			),
			new JsonResponse(['friend' => ['id' => 987654]]),
		);

		$this->assertSame(['friends_added' => 987654], $this->trackedProgress());
		$this->assertSame([], $this->grantedBadges());
	}

	private function adminRole(): string
	{
		$storage = $this->container->get('entity_type.manager')->getStorage('user_role');
		if (!$storage->load('administrator')) {
			$storage->create(['id' => 'administrator', 'label' => 'Administrator'])->save();
		}
		return 'administrator';
	}

	#endregion

	#region Activity Tracking

	#[Test]
	#[TestDox('Setting activities tracks every named activity in one push')]
	#[Group('mantle2/subscribers')]
	public function activitiesSetTracksEveryName(): void
	{
		$user = $this->createUser();
		$this->terminate(
			$this->routed(
				'PATCH',
				'mantle2.users.current.activities.set',
				'/v2/users/current/activities',
				$user,
			),
			new JsonResponse([
				'activities' => [['name' => 'Hiking'], ['id' => 'no_name'], ['name' => 'Running']],
			]),
		);

		$this->assertSame(['activities_added' => ['Hiking', 'Running']], $this->trackedProgress());
	}

	#[Test]
	#[TestDox('Setting an empty activity list tracks nothing')]
	#[Group('mantle2/subscribers')]
	public function emptyActivitiesTrackNothing(): void
	{
		$user = $this->createUser();
		$this->terminate(
			$this->routed(
				'PATCH',
				'mantle2.users.current.activities.set',
				'/v2/users/current/activities',
				$user,
			),
			new JsonResponse(['activities' => []]),
		);

		$this->assertSame([], $this->trackedProgress());
	}

	#endregion

	#region Signup Time-of-Day Badges

	/**
	 * Finds an ISO-2 country whose primary timezone currently sits in [$from, $to).
	 * PostResponseSubscriber::userTimezone() uses the first identifier per country,
	 * so the scan mirrors that choice exactly.
	 */
	private function countryWithLocalHourIn(int $from, int $to): ?string
	{
		foreach (DateTimeZone::listIdentifiers() as $identifier) {
			$zone = new DateTimeZone($identifier);
			$location = $zone->getLocation();
			$country = $location['country_code'] ?? '';
			if (strlen($country) !== 2 || $country === '??') {
				continue;
			}

			$primary = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country);
			if (empty($primary)) {
				continue;
			}

			$hour = (int) new DateTime('now', new DateTimeZone($primary[0]))->format('G');
			if ($hour >= $from && $hour < $to) {
				return $country;
			}
		}

		return null;
	}

	private function signedUp(UserInterface $user): void
	{
		$this->terminate(
			$this->routed('POST', 'mantle2.events.signup', '/v2/events/1/signup', $user),
			new JsonResponse(['id' => 1]),
		);
	}

	#[Test]
	#[TestDox('Signing up after midnight local time grants night_owl')]
	#[Group('mantle2/subscribers')]
	public function afterMidnightSignupGrantsNightOwl(): void
	{
		$country = $this->countryWithLocalHourIn(0, 4);
		$this->assertNotNull($country, 'no country is currently between midnight and 4 AM');

		$this->signedUp($this->createUser(['field_country' => $country]));

		$this->assertSame(['night_owl'], $this->grantedBadges());
	}

	#[Test]
	#[TestDox('Signing up in the early morning grants early_bird')]
	#[Group('mantle2/subscribers')]
	public function earlyMorningSignupGrantsEarlyBird(): void
	{
		$country = $this->countryWithLocalHourIn(4, 9);
		$this->assertNotNull($country, 'no country is currently between 4 AM and 9 AM');

		$this->signedUp($this->createUser(['field_country' => $country]));

		$this->assertSame(['early_bird'], $this->grantedBadges());
	}

	#[Test]
	#[TestDox('Signing up during the day grants no time-of-day badge')]
	#[Group('mantle2/subscribers')]
	public function daytimeSignupGrantsNothing(): void
	{
		$country = $this->countryWithLocalHourIn(9, 24);
		$this->assertNotNull($country, 'no country is currently between 9 AM and midnight');

		$this->signedUp($this->createUser(['field_country' => $country]));

		$this->assertSame([], $this->grantedBadges());
	}

	#[Test]
	#[TestDox('An unknown country falls back to UTC rather than throwing')]
	#[Group('mantle2/subscribers')]
	public function unknownCountryFallsBackToUtc(): void
	{
		// ZZ is not a real ISO-2 code, so listIdentifiers returns nothing and UTC stands in
		$this->signedUp($this->createUser(['field_country' => 'ZZ']));

		$utcHour = (int) new DateTime('now', new DateTimeZone('UTC'))->format('G');
		$expected = match (true) {
			$utcHour < 4 => ['night_owl'],
			$utcHour < 9 => ['early_bird'],
			default => [],
		};
		$this->assertSame($expected, $this->grantedBadges());
	}

	#endregion
}
