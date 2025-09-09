<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ArticlesController extends ControllerBase
{
	public static function create(ContainerInterface $container): ArticlesController|static
	{
		return new static();
	}

	// GET /v2/articles
	public function articles(Request $request): JsonResponse
	{
		$pagination = GeneralHelper::paginatedParameters($request);
		if ($pagination instanceof JsonResponse) {
			return $pagination;
		}

		$limit = $pagination['limit'];
		$page = $pagination['page'] - 1;
		$search = $pagination['search'];

		try {
			$storage = Drupal::entityTypeManager()->getStorage('node');
			$query = $storage->getQuery()->accessCheck(false)->condition('type', 'article');

			if ($search) {
				$group = $query->orConditionGroup();
				$group->condition('field_article_title', $search, 'CONTAINS');
				$group->condition('field_article_description', $search, 'CONTAINS');
				$query->condition($group);
			}

			$countQuery = clone $query;
			$total = $countQuery->count()->execute();

			$query->range($page * $limit, $limit);
			$nids = $query->execute();

			/** @var Node[] $nodes */
			$nodes = $storage->loadMultiple($nids);
			$data = [];
			foreach ($nodes as $node) {
				$article = ArticlesHelper::nodeToArticle($node);
				if ($article) {
					$item = $article->jsonSerialize();
					$item['id'] = GeneralHelper::formatId($node->id());
					$item['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
					$item['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());
					$data[] = $item;
				}
			}

			return new JsonResponse([
				'page' => $page + 1,
				'total' => $total,
				'limit' => $limit,
				'items' => $data,
			]);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load articles storage: ' . $e->getMessage(),
			);
		}
	}

	// POST /v2/articles
	public function createArticle(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isOrganizer($user)) {
			return GeneralHelper::paymentRequired('Upgrade to Organizer required');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$requiredFields = ['title', 'description', 'tags', 'content', 'author', 'author_id'];
		foreach ($requiredFields as $field) {
			if (empty($body[$field])) {
				return GeneralHelper::badRequest("Missing required field: $field");
			}
		}

		// Validate author_id
		$authorId = (int) $body['author_id'];
		$author = User::load($authorId);
		if (!$author) {
			return GeneralHelper::badRequest("Invalid author_id: $authorId");
		}

		// Create the article node
		$node = Node::create([
			'type' => 'article',
			'title' => $body['title'],
			'field_article_title' => $body['title'],
			'field_article_description' => $body['description'],
			'field_article_tags' => json_encode($body['tags']),
			'field_article_content' => $body['content'],
			'field_article_author' => $body['author'],
			'field_author_id' => ['target_id' => $authorId],
			'field_article_color' => (int) $body['color'],
			'field_article_ocean' => json_encode($body['ocean']),
			'status' => 1,
		]);

		try {
			$node->save();
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to create article: ' . $e->getMessage());
		}

		// Load the created article
		$article = ArticlesHelper::nodeToArticle($node);
		if (!$article) {
			return GeneralHelper::internalError('Failed to load created article');
		}

		$item = $article->jsonSerialize();
		$item['id'] = GeneralHelper::formatId($node->id());
		$item['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
		$item['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

		return new JsonResponse($item, Response::HTTP_CREATED);
	}

	// GET /v2/articles/:articleId
	public function getArticle(Node $articleId): JsonResponse
	{
		if ($articleId->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$article = ArticlesHelper::nodeToArticle($articleId);
		if (!$article) {
			return GeneralHelper::internalError('Failed to load article');
		}

		$item = $article->jsonSerialize();
		$item['id'] = GeneralHelper::formatId($articleId->id());
		$item['created_at'] = GeneralHelper::dateToIso($articleId->getCreatedTime());
		$item['updated_at'] = GeneralHelper::dateToIso($articleId->getChangedTime());

		return new JsonResponse($item, Response::HTTP_OK);
	}

	// PATCH /v2/articles/:articleId
	public function updateArticle(Request $request, Node $articleId): JsonResponse
	{
		if ($articleId->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isOrganizer($user)) {
			return GeneralHelper::paymentRequired('Upgrade to Organizer required');
		}

		$author = $articleId->get('field_author_id')->entity;
		if ($author && $author->id() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You do not have permission to update this article.');
		}

		$body = json_decode($request->getContent(), true);
		if (!is_array($body)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body');
		}

		$updatableFields = ['title', 'description', 'tags', 'content', 'color', 'ocean'];
		foreach ($updatableFields as $field) {
			if (array_key_exists($field, $body)) {
				if ($field === 'tags' || $field === 'ocean') {
					$articleId->set("field_article_$field", json_encode($body[$field]));
				} elseif ($field === 'color') {
					$articleId->set("field_article_$field", (int) $body[$field]);
				} else {
					$articleId->set("field_article_$field", $body[$field]);
				}
			}
		}

		try {
			$articleId->save();
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to update article: ' . $e->getMessage());
		}

		// Load the updated article
		$article = ArticlesHelper::nodeToArticle($articleId);
		if (!$article) {
			return GeneralHelper::internalError('Failed to load updated article');
		}

		$item = $article->jsonSerialize();
		$item['id'] = GeneralHelper::formatId($articleId->id());
		$item['created_at'] = GeneralHelper::dateToIso($articleId->getCreatedTime());
		$item['updated_at'] = GeneralHelper::dateToIso($articleId->getChangedTime());

		return new JsonResponse($item, Response::HTTP_OK);
	}

	// DELETE /v2/articles/:articleId
	public function deleteArticle(Request $request, Node $articleId): JsonResponse
	{
		if ($articleId->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isOrganizer($user)) {
			return GeneralHelper::paymentRequired('Upgrade to Organizer required');
		}

		$author = $articleId->get('field_author_id')->entity;
		if ($author && $author->id() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You do not have permission to delete this article.');
		}

		try {
			$articleId->delete();
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to delete article: ' . $e->getMessage());
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}
}
