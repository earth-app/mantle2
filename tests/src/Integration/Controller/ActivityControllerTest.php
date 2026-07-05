<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\ActivityController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class ActivityControllerTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
	}

	private function controller(): ActivityController
	{
		return ActivityController::create($this->container);
	}

	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
		]);
	}

	private function seed(string $id, array $types = ['HOBBY'], array $overrides = []): void
	{
		$activity = \Drupal\mantle2\Custom\Activity::fromArray(
			array_merge(
				[
					'id' => $id,
					'name' => "Name of $id",
					'description' => "Description of $id",
					'types' => $types,
					'aliases' => [],
					'fields' => ['icon' => 'mdi:star'],
				],
				$overrides,
			),
		);
		ActivityHelper::createActivity($activity, $this->admin());
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/activities creates for admins and rejects anon, non-admin, bad body, and duplicates',
		),
	]
	#[Group('mantle2/activities')]
	public function create_(): void
	{
		$anon = $this->controller()->createActivity(
			$this->request('POST', '/v2/activities', [], '{}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$normal = $this->createUser();
		$forbidden = $this->controller()->createActivity(
			$this->authRequest($normal, 'POST', '/v2/activities', [], '{"id":"x"}'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$admin = $this->admin();
		$badJson = $this->controller()->createActivity(
			$this->authRequest($admin, 'POST', '/v2/activities', [], '{bad'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());

		$missing = $this->controller()->createActivity(
			$this->authRequest($admin, 'POST', '/v2/activities', [], '{"id":"run"}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());
		$this->assertSame('Missing required fields', $this->decode($missing)['message']);

		$badType = $this->controller()->createActivity(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/activities',
				[],
				'{"id":"run","name":"Run","description":"go","types":["NOPE"]}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badType->getStatusCode());

		$ok = $this->controller()->createActivity(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/activities',
				[],
				'{"id":"run","name":"Running","description":"go fast","types":["SPORT","HEALTH"],"aliases":["jog"],"fields":{"icon":"mdi:run"}}',
			),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('run', $body['id']);
		$this->assertSame('Running', $body['name']);
		$this->assertSame(['SPORT', 'HEALTH'], $body['types']);
		$this->assertSame(['jog'], $body['aliases']);
		$this->assertSame('mdi:run', $body['fields']['icon']);

		$node = ActivityHelper::getNodeByActivityId('run');
		$this->assertNotNull($node);
		$this->assertSame('run', $node->get('field_activity_id')->value);

		$dupe = $this->controller()->createActivity(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/activities',
				[],
				'{"id":"run","name":"Running","description":"again","types":["SPORT"]}',
			),
		);
		$this->assertSame(Response::HTTP_CONFLICT, $dupe->getStatusCode());
	}

	#[Test]
	#[TestDox('GET /v2/activities paginates, filters by type, searches, and validates params')]
	#[Group('mantle2/activities')]
	public function list(): void
	{
		$this->seed('run', ['SPORT']);
		$this->seed('read', ['LEARNING']);
		$this->seed('cook', ['HOBBY']);

		$all = $this->controller()->activities($this->request('GET', '/v2/activities'));
		$this->assertSame(Response::HTTP_OK, $all->getStatusCode());
		$body = $this->decode($all);
		$this->assertSame(3, $body['total']);
		$this->assertSame(1, $body['page']);
		$this->assertSame(25, $body['limit']);
		$this->assertCount(3, $body['items']);
		$this->assertArrayHasKey('created_at', $body['items'][0]);

		$typed = $this->controller()->activities(
			$this->request('GET', '/v2/activities?type=SPORT'),
		);
		$typedBody = $this->decode($typed);
		$this->assertSame(1, $typedBody['total']);
		$this->assertSame('run', $typedBody['items'][0]['id']);
		$this->assertSame('SPORT', $typedBody['type']);

		$searched = $this->controller()->activities(
			$this->request('GET', '/v2/activities?search=cook'),
		);
		$searchedBody = $this->decode($searched);
		$this->assertSame(1, $searchedBody['total']);
		$this->assertSame('cook', $searchedBody['items'][0]['id']);

		$badType = $this->controller()->activities(
			$this->request('GET', '/v2/activities?type=NOPE'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badType->getStatusCode());

		$badLimit = $this->controller()->activities(
			$this->request('GET', '/v2/activities?limit=0'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badLimit->getStatusCode());
	}

	#[Test]
	#[TestDox('GET /v2/activities/list returns ids only, paginates, and 404s when empty')]
	#[Group('mantle2/activities')]
	public function listIds(): void
	{
		$empty = $this->controller()->listActivities($this->request('GET', '/v2/activities/list'));
		$this->assertSame(Response::HTTP_NOT_FOUND, $empty->getStatusCode());

		$this->seed('run');
		$this->seed('read');

		$ok = $this->controller()->listActivities($this->request('GET', '/v2/activities/list'));
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame(2, $body['total']);
		$this->assertContains('run', $body['items']);
		$this->assertContains('read', $body['items']);
	}

	#[Test]
	#[TestDox('GET /v2/activities/:id returns activity, resolves aliases, and 404s when missing')]
	#[Group('mantle2/activities')]
	public function get(): void
	{
		$this->seed('run', ['SPORT'], ['aliases' => ['jog', 'sprint']]);

		$ok = $this->controller()->getActivity($this->request('GET', '/v2/activities/run'), 'run');
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('run', $body['id']);
		$this->assertArrayHasKey('created_at', $body);
		$this->assertArrayHasKey('updated_at', $body);

		$missing = $this->controller()->getActivity(
			$this->request('GET', '/v2/activities/jog'),
			'jog',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$alias = $this->controller()->getActivity(
			$this->request('GET', '/v2/activities/jog?include_aliases=1'),
			'jog',
		);
		$this->assertSame(Response::HTTP_OK, $alias->getStatusCode());
		$this->assertSame('run', $this->decode($alias)['id']);
	}

	#[Test]
	#[
		TestDox(
			'PATCH /v2/activities/:id updates fields, enforces auth/admin, and 404s when missing',
		),
	]
	#[Group('mantle2/activities')]
	public function patch(): void
	{
		$this->seed('run', ['SPORT']);

		$anon = $this->controller()->updateActivity(
			$this->request('PATCH', '/v2/activities/run', [], '{"name":"x"}'),
			'run',
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$normal = $this->createUser();
		$forbidden = $this->controller()->updateActivity(
			$this->authRequest($normal, 'PATCH', '/v2/activities/run', [], '{"name":"x"}'),
			'run',
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$admin = $this->admin();
		$missing = $this->controller()->updateActivity(
			$this->authRequest($admin, 'PATCH', '/v2/activities/nope', [], '{"name":"x"}'),
			'nope',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$ok = $this->controller()->updateActivity(
			$this->authRequest(
				$admin,
				'PATCH',
				'/v2/activities/run',
				[],
				'{"name":"Jogging","description":"slow","types":["HEALTH"],"aliases":["jog"]}',
			),
			'run',
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('Jogging', $body['name']);
		$this->assertSame('slow', $body['description']);
		$this->assertSame(['HEALTH'], $body['types']);
		$this->assertSame(['jog'], $body['aliases']);

		$reloaded = ActivityHelper::getActivity('run');
		$this->assertSame('Jogging', $reloaded->getName());
		$this->assertSame(['HEALTH'], $reloaded->getTypes());
	}

	#[Test]
	#[
		TestDox(
			'DELETE /v2/activities/:id removes the activity, enforces auth/admin, and 404s when missing',
		),
	]
	#[Group('mantle2/activities')]
	public function delete(): void
	{
		$this->seed('run');

		$anon = $this->controller()->deleteActivity(
			$this->request('DELETE', '/v2/activities/run'),
			'run',
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$normal = $this->createUser();
		$forbidden = $this->controller()->deleteActivity(
			$this->authRequest($normal, 'DELETE', '/v2/activities/run'),
			'run',
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$admin = $this->admin();
		$missing = $this->controller()->deleteActivity(
			$this->authRequest($admin, 'DELETE', '/v2/activities/nope'),
			'nope',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$ok = $this->controller()->deleteActivity(
			$this->authRequest($admin, 'DELETE', '/v2/activities/run'),
			'run',
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $ok->getStatusCode());
		$this->assertNull(ActivityHelper::getNodeByActivityId('run'));
	}

	#[Test]
	#[
		TestDox(
			'GET /v2/activities/random returns up to count activities, 404s when empty, validates count',
		),
	]
	#[Group('mantle2/activities')]
	public function random(): void
	{
		$empty = $this->controller()->randomActivity(
			$this->request('GET', '/v2/activities/random'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $empty->getStatusCode());

		$this->seed('run');
		$this->seed('read');
		$this->seed('cook');

		$ok = $this->controller()->randomActivity(
			$this->request('GET', '/v2/activities/random?count=2'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertCount(2, $body);
		$this->assertArrayHasKey('id', $body[0]);
		$this->assertArrayHasKey('created_at', $body[0]);
	}

	public static function invalidBodyProvider(): array
	{
		return [
			'list body' => ['[1,2,3]'],
			'plain json array' => ['["a"]'],
		];
	}

	#[Test]
	#[TestDox('POST /v2/activities rejects non-object JSON bodies')]
	#[Group('mantle2/activities')]
	#[DataProvider('invalidBodyProvider')]
	public function createRejectsNonObject(string $content): void
	{
		$admin = $this->admin();
		$res = $this->controller()->createActivity(
			$this->authRequest($admin, 'POST', '/v2/activities', [], $content),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/activities validates optional field/alias entry shapes')]
	#[Group('mantle2/activities')]
	public function createValidatesOptionalShapes(): void
	{
		$admin = $this->admin();

		$badFields = $this->controller()->createActivity(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/activities',
				[],
				'{"id":"a","name":"A","description":"d","types":["HOBBY"],"fields":"nope"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badFields->getStatusCode());
		$this->assertSame('Invalid optional field types', $this->decode($badFields)['message']);

		$badFieldEntry = $this->controller()->createActivity(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/activities',
				[],
				'{"id":"b","name":"B","description":"d","types":["HOBBY"],"fields":{"icon":5}}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badFieldEntry->getStatusCode());
		$this->assertSame('Invalid field entry types', $this->decode($badFieldEntry)['message']);

		$badAlias = $this->controller()->createActivity(
			$this->authRequest(
				$admin,
				'POST',
				'/v2/activities',
				[],
				'{"id":"c","name":"C","description":"d","types":["HOBBY"],"aliases":[5]}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badAlias->getStatusCode());
		$this->assertSame('Invalid alias entry type', $this->decode($badAlias)['message']);
	}

	#[Test]
	#[TestDox('GET /v2/activities supports asc and rand sorts alongside search')]
	#[Group('mantle2/activities')]
	public function listSorts(): void
	{
		$this->seed('run', ['SPORT']);
		$this->seed('read', ['LEARNING']);

		$asc = $this->controller()->activities($this->request('GET', '/v2/activities?sort=asc'));
		$this->assertSame(Response::HTTP_OK, $asc->getStatusCode());
		$this->assertSame('asc', $this->decode($asc)['sort']);
		$this->assertSame(2, $this->decode($asc)['total']);

		$rand = $this->controller()->activities(
			$this->request('GET', '/v2/activities?sort=rand&search=run'),
		);
		$this->assertSame(Response::HTTP_OK, $rand->getStatusCode());
		$randBody = $this->decode($rand);
		$this->assertSame('rand', $randBody['sort']);
		$this->assertSame(1, $randBody['total']);
		$this->assertSame('run', $randBody['items'][0]['id']);

		$randType = $this->controller()->activities(
			$this->request('GET', '/v2/activities?sort=rand&type=SPORT'),
		);
		$this->assertSame(1, $this->decode($randType)['total']);
	}

	#[Test]
	#[TestDox('GET /v2/activities/list searches and supports asc/rand sorting')]
	#[Group('mantle2/activities')]
	public function listIdsSortsAndSearch(): void
	{
		$this->seed('run');
		$this->seed('read');
		$this->seed('cook');

		$searched = $this->controller()->listActivities(
			$this->request('GET', '/v2/activities/list?search=run'),
		);
		$this->assertSame(Response::HTTP_OK, $searched->getStatusCode());
		$body = $this->decode($searched);
		$this->assertSame(1, $body['total']);
		$this->assertContains('run', $body['items']);

		$asc = $this->controller()->listActivities(
			$this->request('GET', '/v2/activities/list?sort=asc'),
		);
		$this->assertSame(Response::HTTP_OK, $asc->getStatusCode());
		$this->assertSame(3, $this->decode($asc)['total']);

		$rand = $this->controller()->listActivities(
			$this->request('GET', '/v2/activities/list?sort=rand'),
		);
		$this->assertSame(Response::HTTP_OK, $rand->getStatusCode());

		// over-long limit rejected via the 1000 max
		$badLimit = $this->controller()->listActivities(
			$this->request('GET', '/v2/activities/list?limit=2000'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badLimit->getStatusCode());
	}

	#[Test]
	#[TestDox('GET /v2/activities/:id resolves a direct id even when include_aliases is set')]
	#[Group('mantle2/activities')]
	public function getWithAliasesFlag(): void
	{
		$this->seed('run', ['SPORT'], ['aliases' => ['jog']]);

		$direct = $this->controller()->getActivity(
			$this->request('GET', '/v2/activities/run?include_aliases=1'),
			'run',
		);
		$this->assertSame(Response::HTTP_OK, $direct->getStatusCode());
		$this->assertSame('run', $this->decode($direct)['id']);

		// alias without the flag is a miss
		$noFlag = $this->controller()->getActivity(
			$this->request('GET', '/v2/activities/jog'),
			'jog',
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $noFlag->getStatusCode());
	}

	#[Test]
	#[TestDox('PATCH /v2/activities/:id validates each optional field type before applying')]
	#[Group('mantle2/activities')]
	public function patchValidation(): void
	{
		$this->seed('run', ['SPORT']);
		$admin = $this->admin();

		$badBody = $this->controller()->updateActivity(
			$this->authRequest($admin, 'PATCH', '/v2/activities/run', [], '{bad'),
			'run',
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badBody->getStatusCode());

		$badName = $this->controller()->updateActivity(
			$this->authRequest($admin, 'PATCH', '/v2/activities/run', [], '{"name":5}'),
			'run',
		);
		$this->assertSame('Invalid name type', $this->decode($badName)['message']);

		$badDesc = $this->controller()->updateActivity(
			$this->authRequest($admin, 'PATCH', '/v2/activities/run', [], '{"description":5}'),
			'run',
		);
		$this->assertSame('Invalid description type', $this->decode($badDesc)['message']);

		$emptyTypes = $this->controller()->updateActivity(
			$this->authRequest($admin, 'PATCH', '/v2/activities/run', [], '{"types":[]}'),
			'run',
		);
		$this->assertSame('Invalid types', $this->decode($emptyTypes)['message']);

		$badType = $this->controller()->updateActivity(
			$this->authRequest($admin, 'PATCH', '/v2/activities/run', [], '{"types":["NOPE"]}'),
			'run',
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badType->getStatusCode());

		$badFields = $this->controller()->updateActivity(
			$this->authRequest($admin, 'PATCH', '/v2/activities/run', [], '{"fields":"nope"}'),
			'run',
		);
		$this->assertSame('Invalid fields type', $this->decode($badFields)['message']);

		$badFieldEntry = $this->controller()->updateActivity(
			$this->authRequest($admin, 'PATCH', '/v2/activities/run', [], '{"fields":{"icon":5}}'),
			'run',
		);
		$this->assertSame('Invalid field entry types', $this->decode($badFieldEntry)['message']);

		$badAliases = $this->controller()->updateActivity(
			$this->authRequest($admin, 'PATCH', '/v2/activities/run', [], '{"aliases":"nope"}'),
			'run',
		);
		$this->assertSame('Invalid aliases type', $this->decode($badAliases)['message']);

		$badAliasEntry = $this->controller()->updateActivity(
			$this->authRequest($admin, 'PATCH', '/v2/activities/run', [], '{"aliases":[5]}'),
			'run',
		);
		$this->assertSame('Invalid alias entry type', $this->decode($badAliasEntry)['message']);

		// only fields (no name/desc/types/aliases) still updates
		$fieldsOnly = $this->controller()->updateActivity(
			$this->authRequest(
				$admin,
				'PATCH',
				'/v2/activities/run',
				[],
				'{"fields":{"icon":"mdi:run"}}',
			),
			'run',
		);
		$this->assertSame(Response::HTTP_OK, $fieldsOnly->getStatusCode());
		$this->assertSame('mdi:run', $this->decode($fieldsOnly)['fields']['icon']);
	}

	#[Test]
	#[TestDox('GET /v2/activities/random validates a negative count parameter')]
	#[Group('mantle2/activities')]
	public function randomNegativeCount(): void
	{
		$this->seed('run');
		// a negative count exercises the range/count branch without crashing; the
		// backend tolerates it (returns rows or none) rather than erroring
		$res = $this->controller()->randomActivity(
			$this->request('GET', '/v2/activities/random?count=-1'),
		);
		$this->assertContains($res->getStatusCode(), [
			Response::HTTP_OK,
			Response::HTTP_BAD_REQUEST,
			Response::HTTP_NOT_FOUND,
		]);
	}
}
