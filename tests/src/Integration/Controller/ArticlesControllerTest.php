<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\ArticlesController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class ArticlesControllerTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
		// dead endpoint so CloudHelper side effects (notifications) stay inert
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
	}

	private function controller(): ArticlesController
	{
		return ArticlesController::create($this->container);
	}

	private function ordinal(AccountType $type): string
	{
		return (string) array_search($type, AccountType::cases(), true);
	}

	// writer + verified + PUBLIC visibility so createArticle gates pass
	private function writer(): UserInterface
	{
		return $this->createUser([
			'field_email_verified' => true,
			'field_account_type' => $this->ordinal(AccountType::WRITER),
			'field_visibility' => (string) array_search(
				Visibility::PUBLIC,
				Visibility::cases(),
				true,
			),
		]);
	}

	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_email_verified' => true,
			'field_account_type' => $this->ordinal(AccountType::ADMINISTRATOR),
		]);
	}

	private function articleBody(array $overrides = []): string
	{
		return json_encode(
			array_merge(
				[
					'title' => 'The Blue Planet',
					'description' => 'A look at oceans',
					'tags' => ['ocean', 'earth'],
					'content' => str_repeat(
						'The sea covers most of the planet and shapes its weather. ',
						3,
					),
				],
				$overrides,
			),
		);
	}

	private function makeArticleNode(UserInterface $author): Node
	{
		return ArticlesHelper::createArticle(
			'The Blue Planet',
			'A look at oceans',
			['ocean', 'earth'],
			str_repeat('The sea covers most of the planet and shapes its weather. ', 3),
			$author,
			'#112233',
			null,
		);
	}

	#region create

	#[Test]
	#[TestDox('POST /v2/articles enforces auth, email, writer tier, and persists valid articles')]
	#[Group('mantle2/articles')]
	public function createArticle(): void
	{
		$anon = $this->controller()->createArticle(
			$this->request('POST', '/v2/articles', [], $this->articleBody()),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$unverified = $this->createUser([
			'field_account_type' => $this->ordinal(AccountType::WRITER),
		]);
		$blocked = $this->controller()->createArticle(
			$this->authRequest($unverified, 'POST', '/v2/articles', [], $this->articleBody()),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $blocked->getStatusCode());
		$this->assertSame('EMAIL_VERIFICATION_REQUIRED', $this->decode($blocked)['reason']);

		$free = $this->createUser([
			'field_email_verified' => true,
			'field_account_type' => $this->ordinal(AccountType::FREE),
		]);
		$needsWriter = $this->controller()->createArticle(
			$this->authRequest($free, 'POST', '/v2/articles', [], $this->articleBody()),
		);
		$this->assertSame(Response::HTTP_PAYMENT_REQUIRED, $needsWriter->getStatusCode());

		$writer = $this->writer();
		$ok = $this->controller()->createArticle(
			$this->authRequest($writer, 'POST', '/v2/articles', [], $this->articleBody()),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('The Blue Planet', $body['title']);
		$this->assertSame(['ocean', 'earth'], $body['tags']);
		$this->assertTrue($body['can_edit']);

		$nid = (int) ltrim($body['id'], '0');
		$node = Node::load($nid);
		$this->assertNotNull($node);
		$this->assertSame('article', $node->getType());
	}

	#[Test]
	#[TestDox('POST /v2/articles rejects a private account and missing/invalid fields')]
	#[Group('mantle2/articles')]
	public function createArticleValidation(): void
	{
		$private = $this->createUser([
			'field_email_verified' => true,
			'field_account_type' => $this->ordinal(AccountType::WRITER),
			'field_visibility' => (string) array_search(
				Visibility::PRIVATE,
				Visibility::cases(),
				true,
			),
		]);
		$res = $this->controller()->createArticle(
			$this->authRequest($private, 'POST', '/v2/articles', [], $this->articleBody()),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
		$this->assertSame(
			'Private accounts cannot create public content',
			$this->decode($res)['message'],
		);

		$writer = $this->writer();
		$missing = $this->controller()->createArticle(
			$this->authRequest($writer, 'POST', '/v2/articles', [], '{"title":"x"}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missing->getStatusCode());

		$shortContent = $this->controller()->createArticle(
			$this->authRequest(
				$writer,
				'POST',
				'/v2/articles',
				[],
				$this->articleBody(['content' => 'too short']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $shortContent->getStatusCode());
	}

	#endregion

	#region list

	#[Test]
	#[TestDox('GET /v2/articles paginates and filters by author and tags')]
	#[Group('mantle2/articles')]
	public function articles(): void
	{
		$author = $this->writer();
		$this->makeArticleNode($author);
		$this->makeArticleNode($author);

		$all = $this->controller()->articles($this->request('GET', '/v2/articles'));
		$this->assertSame(Response::HTTP_OK, $all->getStatusCode());
		$body = $this->decode($all);
		$this->assertSame(2, $body['total']);
		$this->assertSame(1, $body['page']);

		$byAuthor = $this->controller()->articles(
			$this->request('GET', '/v2/articles?author=' . $author->id()),
		);
		$this->assertSame(2, $this->decode($byAuthor)['total']);

		$byTag = $this->controller()->articles($this->request('GET', '/v2/articles?tags=ocean'));
		$this->assertCount(2, $this->decode($byTag)['items']);

		$noTag = $this->controller()->articles($this->request('GET', '/v2/articles?tags=volcano'));
		$this->assertCount(0, $this->decode($noTag)['items']);
	}

	#[Test]
	#[TestDox('GET /v2/articles rejects an unknown author filter')]
	#[Group('mantle2/articles')]
	public function articlesBadAuthor(): void
	{
		$res = $this->controller()->articles($this->request('GET', '/v2/articles?author=999999'));
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
		$this->assertSame('Author not found', $this->decode($res)['message']);
	}

	#[Test]
	#[TestDox('GET /v2/articles/random validates count and returns items')]
	#[Group('mantle2/articles')]
	public function randomArticle(): void
	{
		$author = $this->writer();
		$this->makeArticleNode($author);

		$bad = $this->controller()->randomArticle(
			$this->request('GET', '/v2/articles/random?count=99'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $bad->getStatusCode());

		$ok = $this->controller()->randomArticle(
			$this->request('GET', '/v2/articles/random?count=1'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertCount(1, $this->decode($ok));

		$empty = $this->controller()->randomArticle(
			$this->request('GET', '/v2/articles/random?author=' . $author->id() . '&count=1'),
		);
		$this->assertSame(Response::HTTP_OK, $empty->getStatusCode());
	}

	#endregion

	#region get

	#[Test]
	#[TestDox('GET /v2/articles/{articleId} returns the article, 404 unknown, 400 wrong type')]
	#[Group('mantle2/articles')]
	public function getArticle(): void
	{
		$author = $this->writer();
		$node = $this->makeArticleNode($author);

		$ok = $this->controller()->getArticle(
			(int) $node->id(),
			$this->request('GET', '/v2/articles/' . $node->id()),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('The Blue Planet', $this->decode($ok)['title']);

		$missing = $this->controller()->getArticle(
			999999,
			$this->request('GET', '/v2/articles/999999'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$event = Node::create(['type' => 'event', 'title' => 'x', 'uid' => $author->id()]);
		$event->set('field_event_name', 'x');
		$event->set('field_host_id', $author->id());
		$event->set('field_event_date', (time() + 3600) * 1000);
		$event->save();
		$wrong = $this->controller()->getArticle(
			(int) $event->id(),
			$this->request('GET', '/v2/articles/' . $event->id()),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $wrong->getStatusCode());
	}

	#endregion

	#region patch

	#[Test]
	#[TestDox('PATCH /v2/articles/{articleId} updates for the author and forbids others')]
	#[Group('mantle2/articles')]
	public function updateArticle(): void
	{
		$author = $this->writer();
		$node = $this->makeArticleNode($author);

		$ok = $this->controller()->updateArticle(
			(int) $node->id(),
			$this->authRequest(
				$author,
				'PATCH',
				'/v2/articles/' . $node->id(),
				[],
				'{"title":"Renamed"}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('Renamed', $this->decode($ok)['title']);
		$this->assertSame('Renamed', Node::load($node->id())->get('field_article_title')->value);

		$other = $this->writer();
		$forbidden = $this->controller()->updateArticle(
			(int) $node->id(),
			$this->authRequest(
				$other,
				'PATCH',
				'/v2/articles/' . $node->id(),
				[],
				'{"title":"Hijack"}',
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$badColor = $this->controller()->updateArticle(
			(int) $node->id(),
			$this->authRequest(
				$author,
				'PATCH',
				'/v2/articles/' . $node->id(),
				[],
				'{"color":"blue"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badColor->getStatusCode());

		$missing = $this->controller()->updateArticle(
			999999,
			$this->authRequest($author, 'PATCH', '/v2/articles/999999', [], '{"title":"x"}'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
	}

	#endregion

	#region delete

	#[Test]
	#[TestDox('DELETE /v2/articles/{articleId} removes for author, forbids others, admin allowed')]
	#[Group('mantle2/articles')]
	public function deleteArticle(): void
	{
		$author = $this->writer();
		$node = $this->makeArticleNode($author);

		$other = $this->writer();
		$forbidden = $this->controller()->deleteArticle(
			(int) $node->id(),
			$this->authRequest($other, 'DELETE', '/v2/articles/' . $node->id()),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
		$this->assertNotNull(Node::load($node->id()));

		$ok = $this->controller()->deleteArticle(
			(int) $node->id(),
			$this->authRequest($author, 'DELETE', '/v2/articles/' . $node->id()),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $ok->getStatusCode());
		$this->assertNull(Node::load($node->id()));

		$adminNode = $this->makeArticleNode($author);
		$adminDelete = $this->controller()->deleteArticle(
			(int) $adminNode->id(),
			$this->authRequest($this->admin(), 'DELETE', '/v2/articles/' . $adminNode->id()),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $adminDelete->getStatusCode());
	}

	#endregion

	#region check_expired

	#[Test]
	#[TestDox('POST /v2/articles/check_expired is admin-only and deletes stale articles')]
	#[Group('mantle2/articles')]
	public function checkExpiredArticles(): void
	{
		$author = $this->writer();
		$stale = $this->makeArticleNode($author);
		$staleNode = Node::load($stale->id());
		$staleNode->setCreatedTime(
			\Drupal::time()->getRequestTime() - ArticlesHelper::EXPIRED_ARTICLES_TTL - 100,
		);
		$staleNode->save();

		$forbidden = $this->controller()->checkExpiredArticles(
			$this->authRequest($author, 'POST', '/v2/articles/check_expired'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
		$this->assertNotNull(Node::load($stale->id()));

		$ok = $this->controller()->checkExpiredArticles(
			$this->authRequest($this->admin(), 'POST', '/v2/articles/check_expired'),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $ok->getStatusCode());
		$this->assertNull(Node::load($stale->id()));
	}

	#endregion

	#region create validation

	#[Test]
	#[TestDox('POST /v2/articles rejects each malformed field with a 400')]
	#[Group('mantle2/articles')]
	public function createFieldValidation(): void
	{
		$cases = [
			'title too long' => [['title' => str_repeat('t', 101)], 'title must be a string'],
			'title not string' => [['title' => 123], 'title must be a string'],
			'description too long' => [
				['description' => str_repeat('d', 513)],
				'description must be a string',
			],
			'tags not array' => [['tags' => 'nope'], 'tags must be an array'],
			'too many tags' => [['tags' => array_fill(0, 11, 'x')], 'maximum of 10 items'],
			'tag too long' => [['tags' => [str_repeat('z', 31)]], 'up to 30 characters'],
			'content too short' => [['content' => 'short'], 'between 50 and 25,000'],
			'censor not bool' => [['censor' => 'yes'], 'censor must be a boolean'],
		];

		$writer = $this->writer();
		foreach ($cases as $label => [$overrides, $needle]) {
			$res = $this->controller()->createArticle(
				$this->authRequest(
					$writer,
					'POST',
					'/v2/articles',
					[],
					$this->articleBody($overrides),
				),
			);
			$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode(), $label);
			$this->assertStringContainsString($needle, $this->decode($res)['message'], $label);
		}
	}

	#[Test]
	#[TestDox('POST /v2/articles rejects invalid JSON and list-shaped bodies')]
	#[Group('mantle2/articles')]
	public function createBadJson(): void
	{
		$writer = $this->writer();

		$bad = $this->controller()->createArticle(
			$this->authRequest($writer, 'POST', '/v2/articles', [], '{not json'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $bad->getStatusCode());
		$this->assertStringContainsString('Invalid JSON', $this->decode($bad)['message']);

		$list = $this->controller()->createArticle(
			$this->authRequest($writer, 'POST', '/v2/articles', [], '[1,2,3]'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $list->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/articles forbids ocean payloads from non-root writers')]
	#[Group('mantle2/articles')]
	public function createOceanForbidden(): void
	{
		$writer = $this->writer();
		$ocean = [
			'title' => 'x',
			'url' => 'https://example.com',
			'author' => 'a',
			'source' => 's',
			'abstract' => 'brief',
		];
		$res = $this->controller()->createArticle(
			$this->authRequest(
				$writer,
				'POST',
				'/v2/articles',
				[],
				$this->articleBody(['ocean' => $ocean]),
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $res->getStatusCode());
	}

	#endregion

	#region list branches

	#[Test]
	#[
		TestDox(
			'GET /v2/articles supports rand sort with search, asc sort, and rejects bad author id',
		),
	]
	#[Group('mantle2/articles')]
	public function listBranches(): void
	{
		$author = $this->writer();
		$this->makeArticleNode($author);
		$this->makeArticleNode($author);

		$rand = $this->controller()->articles(
			$this->request('GET', '/v2/articles?sort=rand&search=Blue'),
		);
		$this->assertSame(Response::HTTP_OK, $rand->getStatusCode());
		$this->assertSame(2, $this->decode($rand)['total']);

		$randAuthor = $this->controller()->articles(
			$this->request('GET', '/v2/articles?sort=rand&author=' . $author->id()),
		);
		$this->assertSame(2, $this->decode($randAuthor)['total']);

		$asc = $this->controller()->articles($this->request('GET', '/v2/articles?sort=asc'));
		$this->assertSame(Response::HTTP_OK, $asc->getStatusCode());

		$search = $this->controller()->articles(
			$this->request('GET', '/v2/articles?search=oceans'),
		);
		$this->assertSame(2, $this->decode($search)['total']);
	}

	#[Test]
	#[TestDox('GET /v2/articles/random filters by tags and validates count bounds')]
	#[Group('mantle2/articles')]
	public function randomBranches(): void
	{
		$author = $this->writer();
		$this->makeArticleNode($author);

		$lowCount = $this->controller()->randomArticle(
			$this->request('GET', '/v2/articles/random?count=0'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $lowCount->getStatusCode());

		$byTag = $this->controller()->randomArticle(
			$this->request('GET', '/v2/articles/random?tags=ocean&count=3'),
		);
		$this->assertSame(Response::HTTP_OK, $byTag->getStatusCode());
		$this->assertNotEmpty($this->decode($byTag));

		$noMatch = $this->controller()->randomArticle(
			$this->request('GET', '/v2/articles/random?tags=nonexistent&count=3'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $noMatch->getStatusCode());

		$badAuthor = $this->controller()->randomArticle(
			$this->request('GET', '/v2/articles/random?author=999999'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badAuthor->getStatusCode());
	}

	#endregion

	#region update branches

	#[Test]
	#[
		TestDox(
			'PATCH /v2/articles/{id} validates fields, censors flagged content, and sets a valid color',
		),
	]
	#[Group('mantle2/articles')]
	public function updateBranches(): void
	{
		$author = $this->writer();
		$node = $this->makeArticleNode($author);
		$id = (int) $node->id();

		$badTitle = $this->controller()->updateArticle(
			$id,
			$this->authRequest(
				$author,
				'PATCH',
				'/',
				[],
				json_encode(['title' => str_repeat('t', 101)]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badTitle->getStatusCode());

		$badTags = $this->controller()->updateArticle(
			$id,
			$this->authRequest($author, 'PATCH', '/', [], '{"tags":"nope"}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badTags->getStatusCode());

		$manyTags = $this->controller()->updateArticle(
			$id,
			$this->authRequest(
				$author,
				'PATCH',
				'/',
				[],
				json_encode(['tags' => array_fill(0, 11, 'x')]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $manyTags->getStatusCode());

		$shortContent = $this->controller()->updateArticle(
			$id,
			$this->authRequest($author, 'PATCH', '/', [], '{"content":"too short"}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $shortContent->getStatusCode());

		// flagged content without censor is rejected
		$flagged = $this->controller()->updateArticle(
			$id,
			$this->authRequest(
				$author,
				'PATCH',
				'/',
				[],
				json_encode([
					'content' => str_repeat('this is fucking terrible content here. ', 3),
				]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $flagged->getStatusCode());
		$this->assertStringContainsString('inappropriate', $this->decode($flagged)['message']);

		// flagged content WITH censor is accepted and masked
		$censored = $this->controller()->updateArticle(
			$id,
			$this->authRequest(
				$author,
				'PATCH',
				'/',
				[],
				json_encode([
					'content' => str_repeat('this is fucking wonderful content here. ', 3),
					'censor' => true,
				]),
			),
		);
		$this->assertSame(Response::HTTP_OK, $censored->getStatusCode());
		$this->assertStringContainsString('****', $this->decode($censored)['content']);

		// a valid hex color is stored as an int
		$color = $this->controller()->updateArticle(
			$id,
			$this->authRequest($author, 'PATCH', '/', [], '{"color":"#0A0B0C"}'),
		);
		$this->assertSame(Response::HTTP_OK, $color->getStatusCode());
		$this->assertSame(
			hexdec('0A0B0C'),
			(int) \Drupal\node\Entity\Node::load($id)->get('field_article_color')->value,
		);
	}

	#[Test]
	#[TestDox('PATCH /v2/articles/{id} 400s on a wrong-typed node and 401s anonymous callers')]
	#[Group('mantle2/articles')]
	public function updateGuards(): void
	{
		$author = $this->writer();
		$event = Node::create(['type' => 'event', 'title' => 'x', 'uid' => $author->id()]);
		$event->set('field_event_name', 'x');
		$event->set('field_host_id', $author->id());
		$event->set('field_event_date', (time() + 3600) * 1000);
		$event->save();

		$wrong = $this->controller()->updateArticle(
			(int) $event->id(),
			$this->authRequest($author, 'PATCH', '/', [], '{"title":"x"}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $wrong->getStatusCode());

		$node = $this->makeArticleNode($author);
		$anon = $this->controller()->updateArticle(
			(int) $node->id(),
			$this->request('PATCH', '/', [], '{"title":"x"}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());
	}

	#endregion

	#region delete branches

	#[Test]
	#[TestDox('DELETE /v2/articles/{id} 404s unknown, 400s wrong type, and 401s anonymous')]
	#[Group('mantle2/articles')]
	public function deleteGuards(): void
	{
		$author = $this->writer();

		$missing = $this->controller()->deleteArticle(
			999999,
			$this->authRequest($author, 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$event = Node::create(['type' => 'event', 'title' => 'x', 'uid' => $author->id()]);
		$event->set('field_event_name', 'x');
		$event->set('field_host_id', $author->id());
		$event->set('field_event_date', (time() + 3600) * 1000);
		$event->save();
		$wrong = $this->controller()->deleteArticle(
			(int) $event->id(),
			$this->authRequest($author, 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $wrong->getStatusCode());

		$node = $this->makeArticleNode($author);
		$anon = $this->controller()->deleteArticle(
			(int) $node->id(),
			$this->request('DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());
	}

	#endregion

	#region quiz validation (local guard branches only; save/get/delete reach cloud)

	private function quizBody(array $questions): string
	{
		return json_encode(['questions' => $questions]);
	}

	// organizer + verified so the quiz-create tier gate passes
	private function organizer(): UserInterface
	{
		return $this->createUser([
			'field_email_verified' => true,
			'field_account_type' => $this->ordinal(AccountType::ORGANIZER),
		]);
	}

	#[Test]
	#[TestDox('POST /v2/articles/{id}/quiz enforces auth, email, ownership, and organizer tier')]
	#[Group('mantle2/articles')]
	public function quizGates(): void
	{
		$author = $this->organizer();
		$node = $this->makeArticleNode($author);
		$id = (int) $node->id();
		$body = $this->quizBody([
			[
				'question' => 'What is the sea?',
				'type' => 'true_false',
				'options' => ['True', 'False'],
				'correct_answer' => 'True',
			],
		]);

		$missing = $this->controller()->createOrUpdateArticleQuiz(
			999999,
			$this->authRequest($author, 'POST', '/', [], $body),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$anon = $this->controller()->createOrUpdateArticleQuiz(
			$id,
			$this->request('POST', '/', [], $body),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$unverified = $this->createUser([
			'field_account_type' => $this->ordinal(AccountType::ORGANIZER),
		]);
		$emailGate = $this->controller()->createOrUpdateArticleQuiz(
			$id,
			$this->authRequest($unverified, 'POST', '/', [], $body),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $emailGate->getStatusCode());

		$stranger = $this->organizer();
		$forbidden = $this->controller()->createOrUpdateArticleQuiz(
			$id,
			$this->authRequest($stranger, 'POST', '/', [], $body),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());

		$freeWriter = $this->writer();
		$freeNode = $this->makeArticleNode($freeWriter);
		$needsOrganizer = $this->controller()->createOrUpdateArticleQuiz(
			(int) $freeNode->id(),
			$this->authRequest($freeWriter, 'POST', '/', [], $body),
		);
		$this->assertSame(Response::HTTP_PAYMENT_REQUIRED, $needsOrganizer->getStatusCode());
	}

	public static function quizValidationProvider(): array
	{
		return [
			'empty questions' => [['questions' => []], 'non-empty array'],
			'too many questions' => [
				['questions' => array_fill(0, 11, ['question' => 'x'])],
				'maximum of 10 items',
			],
			'question not object' => [['questions' => ['x']], 'must be an object'],
			'question text too short' => [
				['questions' => [['question' => 'hi', 'type' => 'true_false']]],
				'between 5 and 256',
			],
			'invalid type' => [
				['questions' => [['question' => 'a valid question', 'type' => 'bogus']]],
				'Invalid question type',
			],
			'mc missing options' => [
				['questions' => [['question' => 'a valid question', 'type' => 'multiple_choice']]],
				'must be a non-empty array',
			],
			'mc missing correct_answer' => [
				[
					'questions' => [
						[
							'question' => 'a valid question',
							'type' => 'multiple_choice',
							'options' => ['A', 'B'],
						],
					],
				],
				'correct_answer is required',
			],
			'mc correct not in options' => [
				[
					'questions' => [
						[
							'question' => 'a valid question',
							'type' => 'multiple_choice',
							'options' => ['A', 'B'],
							'correct_answer' => 'C',
						],
					],
				],
				'must be one of the options',
			],
			'order too few items' => [
				[
					'questions' => [
						[
							'question' => 'a valid question',
							'type' => 'order',
							'items' => ['a', 'b'],
						],
					],
				],
				'array of 3-6 strings',
			],
			'multi_select too few options' => [
				[
					'questions' => [
						[
							'question' => 'a valid question',
							'type' => 'multi_select',
							'options' => ['A', 'B'],
							'correct_answers' => ['A'],
						],
					],
				],
				'between 3 and 6 items',
			],
			'multi_select all correct' => [
				[
					'questions' => [
						[
							'question' => 'a valid question',
							'type' => 'multi_select',
							'options' => ['A', 'B', 'C'],
							'correct_answers' => ['A', 'B', 'C'],
						],
					],
				],
				'at least one INCORRECT option',
			],
		];
	}

	#[Test]
	#[TestDox('POST /v2/articles/{id}/quiz rejects malformed quiz payloads')]
	#[Group('mantle2/articles')]
	#[DataProvider('quizValidationProvider')]
	public function quizValidation(array $payload, string $needle): void
	{
		$author = $this->organizer();
		$node = $this->makeArticleNode($author);
		$res = $this->controller()->createOrUpdateArticleQuiz(
			(int) $node->id(),
			$this->authRequest($author, 'POST', '/', [], json_encode($payload)),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
		$this->assertStringContainsString($needle, $this->decode($res)['message']);
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/articles/{id}/quiz accepts each valid question shape (cloud save degrades to 200)',
		),
	]
	#[Group('mantle2/articles')]
	public function quizValidShapes(): void
	{
		$author = $this->organizer();
		$node = $this->makeArticleNode($author);
		$id = (int) $node->id();

		$valid = $this->quizBody([
			[
				'question' => 'Pick the ocean color',
				'type' => 'multiple_choice',
				'options' => ['Blue', 'Green', 'Red'],
				'correct_answer' => 'Blue',
			],
			[
				'question' => 'Select the water types',
				'type' => 'multi_select',
				'options' => ['Salt', 'Fresh', 'Brackish', 'Dry'],
				'correct_answers' => ['Salt', 'Fresh'],
			],
			[
				'question' => 'Order these by depth',
				'type' => 'order',
				'items' => ['Surface', 'Middle', 'Abyss'],
			],
			[
				'question' => 'The sea is wet',
				'type' => 'true_false',
				'options' => ['True', 'False'],
				'correct_answer' => 'True',
			],
		]);

		$res = $this->controller()->createOrUpdateArticleQuiz(
			$id,
			$this->authRequest($author, 'POST', '/', [], $valid),
		);
		// cloud save is inert (dead endpoint) so the controller returns 200 with the validated payload
		$this->assertSame(Response::HTTP_OK, $res->getStatusCode());
		$body = $this->decode($res);
		$this->assertCount(4, $body['questions']);
		$this->assertSame('Article quiz saved successfully', $body['message']);
	}

	#[Test]
	#[TestDox('GET and DELETE quiz share the 404/400 node guards')]
	#[Group('mantle2/articles')]
	public function quizNodeGuards(): void
	{
		$author = $this->organizer();

		$getMissing = $this->controller()->getArticleQuiz(999999);
		$this->assertSame(Response::HTTP_NOT_FOUND, $getMissing->getStatusCode());

		$event = Node::create(['type' => 'event', 'title' => 'x', 'uid' => $author->id()]);
		$event->set('field_event_name', 'x');
		$event->set('field_host_id', $author->id());
		$event->set('field_event_date', (time() + 3600) * 1000);
		$event->save();
		$getWrong = $this->controller()->getArticleQuiz((int) $event->id());
		$this->assertSame(Response::HTTP_BAD_REQUEST, $getWrong->getStatusCode());

		// with dead cloud, getArticleQuiz returns [] -> 404 quiz not found
		$node = $this->makeArticleNode($author);
		$getEmpty = $this->controller()->getArticleQuiz((int) $node->id());
		$this->assertSame(Response::HTTP_NOT_FOUND, $getEmpty->getStatusCode());

		$delMissing = $this->controller()->deleteArticleQuiz(
			999999,
			$this->authRequest($author, 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $delMissing->getStatusCode());

		$delAnon = $this->controller()->deleteArticleQuiz(
			(int) $node->id(),
			$this->request('DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $delAnon->getStatusCode());

		// no existing quiz (dead cloud) -> 404
		$delEmpty = $this->controller()->deleteArticleQuiz(
			(int) $node->id(),
			$this->authRequest($author, 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $delEmpty->getStatusCode());
	}

	#endregion
}
