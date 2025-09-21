<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\mantle2\Custom\Article;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class ArticlesHelper
{
	public static function loadArticleNode(int $nid): ?Article
	{
		$node = Node::load($nid);
		if (!$node || $node->getType() !== 'article') {
			return null;
		}

		return self::nodeToArticle($node);
	}

	public static function nodeToArticle(Node $node): Article
	{
		$id = $node->id();
		$title = $node->get('field_article_title')->value;
		$description = $node->get('field_article_description')->value;
		$tags = json_decode($node->get('field_article_tags')->value, true) ?? [];
		$content = $node->get('field_article_content')->value;
		$authorId = $node->get('field_author_id')->target_id;
		$color = (int) $node->get('field_article_color')->value;
		$ocean = json_decode($node->get('field_ocean_article')->value, true) ?? [];

		return new Article(
			$id,
			$title,
			$description,
			$tags,
			$content,
			$authorId,
			$color,
			$node->getCreatedTime(),
			$node->getChangedTime(),
			$ocean,
		);
	}

	public static function validateOcean(array $ocean): JsonResponse|array
	{
		if (!is_array($ocean)) {
			return GeneralHelper::badRequest('Field ocean must be an array');
		}

		$oceanRequiredFields = ['title', 'url', 'author', 'source'];
		foreach ($oceanRequiredFields as $field) {
			if (empty($ocean[$field])) {
				return GeneralHelper::badRequest("Missing required ocean field: $field");
			}
		}

		if (!filter_var($ocean['url'], FILTER_VALIDATE_URL)) {
			return GeneralHelper::badRequest('Field ocean.url must be a valid URL');
		}

		if (!isset($ocean['abstract']) && !isset($ocean['content'])) {
			return GeneralHelper::badRequest(
				'Field ocean must have either abstract or content defined',
			);
		}

		if (isset($ocean['abstract'])) {
			if (
				!is_string($ocean['abstract']) ||
				strlen($ocean['abstract']) < 50 ||
				strlen($ocean['abstract']) > 10000
			) {
				return GeneralHelper::badRequest(
					'Field ocean.abstract must be a string between 50 and 10,000 characters',
				);
			}
		}

		if (isset($ocean['content'])) {
			if (
				!is_string($ocean['content']) ||
				strlen($ocean['content']) < 50 ||
				strlen($ocean['content']) > 10000
			) {
				return GeneralHelper::badRequest(
					'Field ocean.content must be a string between 50 and 10,000 characters',
				);
			}
		}

		if (isset($ocean['keywords'])) {
			if (!is_array($ocean['keywords'])) {
				return GeneralHelper::badRequest('Field ocean.keywords must be an array');
			}

			if (count($ocean['keywords']) > 25) {
				return GeneralHelper::badRequest(
					'Field ocean.keywords can have a maximum of 25 items',
				);
			}

			foreach ($ocean['keywords'] as $keyword) {
				if (!is_string($keyword)) {
					return GeneralHelper::badRequest(
						'Field ocean.keywords must be an array of strings',
					);
				}

				if (strlen($keyword) > 35) {
					return GeneralHelper::badRequest(
						'Field ocean.keywords must be an array of strings up to 35 characters',
					);
				}
			}
		}

		if (isset($ocean['links'])) {
			if (!is_array($ocean['links'])) {
				return GeneralHelper::badRequest('Field ocean.links must be an array');
			}

			foreach ($ocean['links'] as $name => $link) {
				if (
					!is_string($name) ||
					!is_string($link) ||
					!filter_var($link, FILTER_VALIDATE_URL)
				) {
					return GeneralHelper::badRequest(
						'Field ocean.links must be a map of valid URL strings',
					);
				}
			}
		}

		if (isset($ocean['favicon'])) {
			if (
				!is_string($ocean['favicon']) ||
				!filter_var($ocean['favicon'], FILTER_VALIDATE_URL)
			) {
				return GeneralHelper::badRequest('Field ocean.favicon must be a valid URL');
			}
		}

		if (isset($ocean['theme_color'])) {
			if (
				!is_string($ocean['theme_color']) ||
				!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $ocean['theme_color'])
			) {
				return GeneralHelper::badRequest(
					'Field ocean.theme_color must be a valid hex color code',
				);
			}
		}

		if (isset($ocean['date'])) {
			if (!is_string($ocean['date'])) {
				return GeneralHelper::badRequest('Field ocean.date must be a string');
			}

			$date = strtotime($ocean['date']);
			if ($date === false) {
				return GeneralHelper::badRequest(
					'Field ocean.date must be a valid ISO date string',
				);
			}
		}

		return $ocean;
	}

	public const EXPIRED_ARTICLES_TTL = 1209600; // 14 days in seconds

	public static function checkExpiredArticles(): void
	{
		$expirationThreshold = Drupal::time()->getRequestTime() - self::EXPIRED_ARTICLES_TTL;

		$query = Drupal::entityQuery('node')
			->accessCheck(false)
			->condition('type', 'article')
			->condition('status', 1)
			->condition('created', $expirationThreshold, '<');

		$nids = $query->execute();

		if (empty($nids)) {
			return;
		}

		$nodes = Node::loadMultiple($nids);
		foreach ($nodes as $node) {
			$owner = $node->get('field_author_id')->entity;
			$article = self::nodeToArticle($node);
			if ($owner instanceof User) {
				UsersHelper::addNotification(
					$owner,
					Drupal::translation()->translate('Article Expired'),
					Drupal::translation()->translate(
						"Your article \"{$article->getTitle()}\" has expired and been deleted.",
					),
				);
			}

			$node->delete();
		}
	}

	public static function createArticle(
		string $title,
		string $description,
		array $tags,
		string $content,
		UserInterface $author,
		string $color,
		?array $ocean = null,
	): ?Node {
		$node = Node::create([
			'type' => 'article',
			'title' => $title,
			'uid' => $author->id(),
			'status' => 1, // Published
		]);

		$node->set('field_article_title', $title);
		$node->set('field_article_description', $description);
		$node->set('field_article_tags', json_encode($tags));
		$node->set('field_article_content', $content);
		$node->set('field_author_id', $author->id());

		$color0 = hexdec(substr($color, 1));
		$node->set('field_article_color', $color0);

		if ($ocean) {
			$node->set('field_ocean_article', json_encode($ocean));
		}

		$node->save();

		// Notify the author that their article was published
		UsersHelper::addNotification(
			$author,
			Drupal::translation()->translate('Article Published'),
			Drupal::translation()->translate(
				"Your article \"{$title}\" has been successfully published.",
			),
			null,
			'info',
			'system',
		);

		return $node;
	}
}
