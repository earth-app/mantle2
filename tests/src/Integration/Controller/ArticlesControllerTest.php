<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\ArticlesController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class ArticlesControllerTest extends IntegrationTestBase
{
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

	// cloud-backed flows deferred to E2E: getArticleQuiz, createOrUpdateArticleQuiz,
	// deleteArticleQuiz all call ArticlesHelper quiz methods -> CloudHelper::sendRequest.
	// getMedia/uploadMedia and like/view/report have no routes in this module (cloud-side surface).
}
