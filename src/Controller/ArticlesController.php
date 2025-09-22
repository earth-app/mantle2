<?php

namespace Drupal\mantle2\Controller;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Exception\UnexpectedValueException;
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
		$requester = UsersHelper::getOwnerOfRequest($request);
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

					$item['author'] = UsersHelper::serializeUser($article->getAuthor(), $requester);
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

	// GET /v2/articles/random
	public function randomArticle(Request $request): JsonResponse
	{
		$requester = UsersHelper::getOwnerOfRequest($request);

		try {
			$count = $request->query->getInt('count', 3);
			if ($count < 1 || $count > 15) {
				return GeneralHelper::badRequest('Count must be between 1 and 15');
			}

			$connection = Drupal::database();
			$query = $connection
				->select('node_field_data', 'n')
				->fields('n', ['nid'])
				->condition('n.status', 1)
				->condition('n.type', 'article');

			$query->orderRandom()->range(0, $count);
			$nids = $query->execute()->fetchCol();

			if (empty($nids)) {
				return GeneralHelper::notFound('No prompts found');
			}

			$results = [];

			foreach ($nids as $randomNid) {
				$node = Node::load($randomNid);

				if (!$node) {
					return GeneralHelper::internalError('Failed to load random prompt');
				}

				$article = ArticlesHelper::nodeToArticle($node);
				if (!$article) {
					return GeneralHelper::internalError('Failed to convert node to article');
				}

				$result = $article->jsonSerialize();
				$result['author'] = UsersHelper::serializeUser($article->getAuthor(), $requester);
				$result['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
				$result['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

				$results[] = $result;
			}

			return new JsonResponse($results, Response::HTTP_OK);
		} catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
			return GeneralHelper::internalError(
				'Failed to load articles storage: ' . $e->getMessage(),
			);
		} catch (UnexpectedValueException $e) {
			return GeneralHelper::badRequest('Invalid count parameter: ' . $e->getMessage());
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

		if (UsersHelper::getVisibility($user) === Visibility::PRIVATE) {
			return GeneralHelper::badRequest('Private accounts cannot create public content');
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$requiredFields = ['title', 'description', 'content'];
		foreach ($requiredFields as $field) {
			if (empty($body[$field])) {
				return GeneralHelper::badRequest("Missing required field: $field");
			}
		}

		// Validate title
		$title = $body['title'];
		if (!is_string($title) || strlen($title) > 100) {
			return GeneralHelper::badRequest('Field title must be a string up to 100 characters');
		}

		// Validate description
		$description = $body['description'];
		if (!is_string($description) || strlen($description) > 512) {
			return GeneralHelper::badRequest(
				'Field description must be a string up to 512 characters',
			);
		}

		// Validate tags
		$tags = $body['tags'] ?? [];
		if (!is_array($tags)) {
			return GeneralHelper::badRequest('Field tags must be an array');
		}

		if (count($tags) > 10) {
			return GeneralHelper::badRequest('Field tags can have a maximum of 10 items');
		}

		foreach ($tags as $tag) {
			if (!is_string($tag) || strlen($tag) > 30) {
				return GeneralHelper::badRequest(
					'Field tags must be an array of strings up to 30 characters',
				);
			}
		}

		// Validate content
		$content = $body['content'];
		if (!is_string($content) || strlen($content) < 50 || strlen($content) > 10000) {
			return GeneralHelper::badRequest(
				'Field content must be a string between 50 and 10,000 characters',
			);
		}

		// Validate ocean article
		$ocean = $body['ocean'] ?? null;
		if ($ocean !== null) {
			$ocean = ArticlesHelper::validateOcean($ocean);
			if ($ocean instanceof JsonResponse) {
				return $ocean;
			}
		}

		// Create the article node
		$node = ArticlesHelper::createArticle(
			$title,
			$description,
			$tags,
			$content,
			$user,
			$body['color'] ?? 0,
			$ocean ?? [],
		);

		// Load the created article
		$article = ArticlesHelper::nodeToArticle($node);
		if (!$article) {
			return GeneralHelper::internalError('Failed to load created article');
		}

		$item = $article->jsonSerialize();
		$item['author'] = UsersHelper::serializeUser($article->getAuthor(), $user);
		$item['created_at'] = GeneralHelper::dateToIso($node->getCreatedTime());
		$item['updated_at'] = GeneralHelper::dateToIso($node->getChangedTime());

		return new JsonResponse($item, Response::HTTP_CREATED);
	}

	// GET /v2/articles/:articleId
	public function getArticle(Request $request, Node $articleId): JsonResponse
	{
		$requester = UsersHelper::getOwnerOfRequest($request);

		if ($articleId->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$article = ArticlesHelper::nodeToArticle($articleId);
		if (!$article) {
			return GeneralHelper::internalError('Failed to load article');
		}

		$item = $article->jsonSerialize();
		$item['author'] = UsersHelper::serializeUser($article->getAuthor(), $requester);
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
		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		// Validate title
		if (array_key_exists('title', $body)) {
			$title = $body['title'];
			if (!is_string($title) || strlen($title) > 100) {
				return GeneralHelper::badRequest(
					'Field title must be a string up to 100 characters',
				);
			}
		}

		// Validate description
		if (array_key_exists('description', $body)) {
			$description = $body['description'];
			if (!is_string($description) || strlen($description) > 512) {
				return GeneralHelper::badRequest(
					'Field description must be a string up to 512 characters',
				);
			}
		}

		// Validate tags
		if (array_key_exists('tags', $body)) {
			$tags = $body['tags'];
			if (!is_array($tags)) {
				return GeneralHelper::badRequest('Field tags must be an array');
			}

			if (count($tags) > 10) {
				return GeneralHelper::badRequest('Field tags can have a maximum of 10 items');
			}

			foreach ($tags as $tag) {
				if (!is_string($tag) || strlen($tag) > 30) {
					return GeneralHelper::badRequest(
						'Field tags must be an array of strings up to 30 characters',
					);
				}
			}
		}

		// Validate color
		$color = $body['color'] ?? null;
		if ($color !== null) {
			if (!is_string($color) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
				return GeneralHelper::badRequest('Field color must be a valid hex color code');
			}
		}

		// Validate content
		if (array_key_exists('content', $body)) {
			$content = $body['content'];
			if (!is_string($content) || strlen($content) < 50 || strlen($content) > 10000) {
				return GeneralHelper::badRequest(
					'Field content must be a string between 50 and 10,000 characters',
				);
			}
		}

		// Validate ocean article
		$ocean = $body['ocean'] ?? null;
		if ($ocean !== null) {
			$ocean = ArticlesHelper::validateOcean($ocean);
			if ($ocean instanceof JsonResponse) {
				return $ocean;
			}
		}

		$updatableFields = ['title', 'description', 'tags', 'content', 'color', 'ocean'];
		foreach ($updatableFields as $field) {
			if (array_key_exists($field, $body)) {
				if ($field === 'tags' || $field === 'ocean') {
					$articleId->set("field_article_$field", json_encode($body[$field]));
				} elseif ($field === 'color') {
					$color0 = hexdec(substr($color, 1));
					$articleId->set("field_article_$field", $color0);
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
		$item['author'] = UsersHelper::serializeUser($article->getAuthor(), $user);
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
		if ($author->id() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You do not have permission to delete this article.');
		}

		try {
			$articleId->delete();
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to delete article: ' . $e->getMessage());
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// POST /v2/articles/check_expired
	public function checkExpiredArticles(Request $request): JsonResponse
	{
		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if (!UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You do not have permission to perform this action.');
		}

		ArticlesHelper::checkExpiredArticles();
		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}
}
