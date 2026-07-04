<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Custom\Article;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ArticlesHelperTest extends IntegrationTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		// dead endpoint so CloudHelper side effects (notifications) stay inert
		$this->setSetting('mantle2.cloud_endpoint', 'http://127.0.0.1:1');
	}

	private function author(array $values = []): UserInterface
	{
		return $this->createUser($values);
	}

	private function make(
		UserInterface $author,
		string $title = 'On Tides',
		array $tags = ['ocean', 'science'],
		string $content = 'The moon pulls the sea in a slow rhythm that shapes every coast on earth.',
		string $color = '#3366FF',
		?array $ocean = null,
	): Node {
		return ArticlesHelper::createArticle(
			$title,
			'A short primer',
			$tags,
			$content,
			$author,
			$color,
			$ocean,
		);
	}

	#region createArticle / nodeToArticle round-trip

	#[Test]
	#[TestDox('createArticle persists an article node with all fields set')]
	#[Group('mantle2/articles')]
	public function createArticle(): void
	{
		$author = $this->author();
		$node = $this->make($author);

		$this->assertNotNull($node->id());
		$this->assertSame('article', $node->getType());
		$this->assertSame('On Tides', $node->get('field_article_title')->value);
		$this->assertSame((int) $author->id(), (int) $node->get('field_author_id')->target_id);
		$this->assertSame(hexdec('3366FF'), (int) $node->get('field_article_color')->value);
	}

	#[Test]
	#[TestDox('nodeToArticle decodes the persisted node into an Article value object')]
	#[Group('mantle2/articles')]
	public function nodeToArticle(): void
	{
		$author = $this->author();
		$node = $this->make($author, 'Kelp Forests', ['ocean']);

		$article = ArticlesHelper::nodeToArticle(Node::load($node->id()));
		$this->assertInstanceOf(Article::class, $article);
		$this->assertSame('Kelp Forests', $article->getTitle());
		$this->assertSame(['ocean'], $article->getTags());
		$this->assertSame((int) $author->id(), $article->getAuthorId());
		$this->assertSame(hexdec('3366FF'), $article->getColor());
	}

	#[Test]
	#[TestDox('loadArticleNode returns null for missing or wrong-typed nodes')]
	#[Group('mantle2/articles')]
	public function loadArticleNode(): void
	{
		$author = $this->author();
		$node = $this->make($author);
		$this->assertInstanceOf(Article::class, ArticlesHelper::loadArticleNode((int) $node->id()));
		$this->assertNull(ArticlesHelper::loadArticleNode(999999));

		$event = Node::create([
			'type' => 'event',
			'title' => 'x',
			'uid' => $author->id(),
		]);
		$event->set('field_event_name', 'x');
		$event->set('field_host_id', $author->id());
		$event->set('field_event_date', (time() + 3600) * 1000);
		$event->save();
		$this->assertNull(ArticlesHelper::loadArticleNode((int) $event->id()));
	}

	#endregion

	#region serializeArticle

	#[Test]
	#[TestDox('serializeArticle exposes author, timestamps, and can_edit')]
	#[Group('mantle2/articles')]
	public function serializeArticle(): void
	{
		$author = $this->author();
		$node = $this->make($author);
		$article = ArticlesHelper::nodeToArticle(Node::load($node->id()));

		$authorView = ArticlesHelper::serializeArticle($article, $author);
		$this->assertSame(24, strlen($authorView['id']));
		$this->assertSame('On Tides', $authorView['title']);
		$this->assertIsArray($authorView['author']);
		$this->assertTrue($authorView['can_edit']);
		$this->assertArrayHasKey('created_at', $authorView);
		$this->assertArrayHasKey('color_hex', $authorView);

		$stranger = $this->createUser();
		$strangerView = ArticlesHelper::serializeArticle($article, $stranger);
		$this->assertFalse($strangerView['can_edit']);
	}

	#endregion

	#region validateOcean

	#[Test]
	#[TestDox('validateOcean accepts a valid payload from the root user')]
	#[Group('mantle2/articles')]
	public function validateOceanValid(): void
	{
		$ocean = [
			'title' => 'Deep Sea',
			'url' => 'https://example.com/a',
			'author' => 'Jane',
			'source' => 'Journal',
			'abstract' => 'A brief look',
		];
		$result = ArticlesHelper::validateOcean($ocean, User::load(1));
		$this->assertSame('https://example.com/a', $result['url']);
	}

	#[Test]
	#[TestDox('validateOcean forbids non-root users')]
	#[Group('mantle2/articles')]
	public function validateOceanForbidsNonRoot(): void
	{
		$ocean = [
			'title' => 'Deep Sea',
			'url' => 'https://example.com/a',
			'author' => 'Jane',
			'source' => 'Journal',
			'abstract' => 'A brief look',
		];
		$result = ArticlesHelper::validateOcean($ocean, $this->author());
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_FORBIDDEN, $result->getStatusCode());
	}

	public static function invalidOceanProvider(): array
	{
		$base = [
			'title' => 'Deep Sea',
			'url' => 'https://example.com/a',
			'author' => 'Jane',
			'source' => 'Journal',
			'abstract' => 'A brief look',
		];
		return [
			'unknown field' => [$base + ['bogus' => 'x'], 'Invalid ocean field: bogus'],
			'missing required' => [
				['url' => 'https://example.com/a', 'author' => 'Jane', 'source' => 'Journal'],
				'Missing required ocean field: title',
			],
			'bad url' => [
				['title' => 'x', 'url' => 'nope', 'author' => 'a', 'source' => 's'],
				'Field ocean.url must be a valid URL',
			],
			'no body' => [
				['title' => 'x', 'url' => 'https://example.com', 'author' => 'a', 'source' => 's'],
				'Field ocean must have either abstract or content defined',
			],
		];
	}

	#[Test]
	#[TestDox('validateOcean rejects invalid payloads')]
	#[Group('mantle2/articles')]
	#[DataProvider('invalidOceanProvider')]
	public function validateOceanInvalid(array $ocean, string $message): void
	{
		$result = ArticlesHelper::validateOcean($ocean, User::load(1));
		$this->assertInstanceOf(JsonResponse::class, $result);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
		$this->assertSame($message, json_decode($result->getContent(), true)['message']);
	}

	#[Test]
	#[TestDox('createArticle stores ocean payload retrievable via nodeToArticle')]
	#[Group('mantle2/articles')]
	public function createArticleWithOcean(): void
	{
		$root = User::load(1);
		$ocean = [
			'title' => 'Deep Sea',
			'url' => 'https://example.com/a',
			'author' => 'Jane',
			'source' => 'Journal',
			'abstract' => 'A brief look',
		];
		$node = $this->make(
			$root,
			'Ocean Piece',
			['ocean'],
			'The abyss holds more mystery than the surface of the moon ever could.',
			'#000000',
			$ocean,
		);
		$article = ArticlesHelper::nodeToArticle(Node::load($node->id()));
		$this->assertSame('Deep Sea', $article->getOcean()['title']);
	}

	#endregion

	#region search (getRandomArticle / getRandomArticles)

	#[Test]
	#[TestDox('getRandomArticle and getRandomArticles read persisted articles')]
	#[Group('mantle2/articles')]
	public function randomSelection(): void
	{
		$author = $this->author();
		$this->assertNull(ArticlesHelper::getRandomArticle());
		$this->assertSame([], ArticlesHelper::getRandomArticles());

		for ($i = 0; $i < 3; $i++) {
			$this->make($author, "Article $i");
		}

		$this->assertInstanceOf(Article::class, ArticlesHelper::getRandomArticle());
		$this->assertCount(2, ArticlesHelper::getRandomArticles(2));
	}

	#endregion

	#region checkExpiredArticles

	#[Test]
	#[TestDox('checkExpiredArticles deletes articles older than the TTL and keeps fresh ones')]
	#[Group('mantle2/articles')]
	public function checkExpiredArticles(): void
	{
		$author = $this->author();
		$fresh = $this->make($author, 'Fresh');
		$stale = $this->make($author, 'Stale');

		$staleNode = Node::load($stale->id());
		$staleNode->setCreatedTime(
			\Drupal::time()->getRequestTime() - ArticlesHelper::EXPIRED_ARTICLES_TTL - 100,
		);
		$staleNode->save();

		ArticlesHelper::checkExpiredArticles();

		$this->assertNotNull(Node::load($fresh->id()));
		$this->assertNull(Node::load($stale->id()));
	}

	#endregion

	// cloud-backed methods deferred to E2E: getArticleQuiz, saveArticleQuiz,
	// deleteArticleQuiz (all call CloudHelper::sendRequest)
}
