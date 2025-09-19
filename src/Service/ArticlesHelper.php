<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\mantle2\Custom\Article;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

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
		$author = $node->get('field_article_author')->value;
		$authorId = $node->get('field_author_id')->target_id;
		$color = (int) $node->get('field_article_color')->value;
		$ocean = json_decode($node->get('field_article_ocean')->value, true) ?? [];

		return new Article(
			$id,
			$title,
			$description,
			$tags,
			$content,
			$author,
			$authorId,
			$color,
			$node->getCreatedTime(),
			$node->getChangedTime(),
			$ocean,
		);
	}

	public const EXPIRED_ARTICLES_TTL = 1209600; // 14 days in seconds

	public static function checkExpiredArticles(): void
	{
		$expirationThreshold = \Drupal::time()->getRequestTime() - self::EXPIRED_ARTICLES_TTL;

		$query = \Drupal::entityQuery('node')
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
					Drupal::translation()->translate(
						"Your article \"{$article->getTitle()}\" has expired and been deleted.",
					),
				);
			}

			$node->delete();
		}
	}
}
