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
		$sort = $pagination['sort'];

		try {
			$storage = Drupal::entityTypeManager()->getStorage('node');

			// Handle random sorting separately using database query
			if ($sort === 'rand') {
				$connection = Drupal::database();
				$query = $connection
					->select('node_field_data', 'n')
					->fields('n', ['nid'])
					->condition('n.status', 1)
					->condition('n.type', 'article');

				if ($search) {
					$escapedSearch = Drupal::database()->escapeLike($search);
					$ft = $query->leftJoin(
						'node__field_article_title',
						'ft',
						'ft.entity_id = n.nid',
					);
					$fd = $query->leftJoin(
						'node__field_article_description',
						'fd',
						'fd.entity_id = n.nid',
					);
					$fc = $query->leftJoin(
						'node__field_article_content',
						'fc',
						'fc.entity_id = n.nid',
					);

					$group = $query
						->orConditionGroup()
						->condition("$ft.field_article_title_value", "%$escapedSearch%", 'LIKE')
						->condition(
							"$fd.field_article_description_value",
							"%$escapedSearch%",
							'LIKE',
						)
						->condition("$fc.field_article_content_value", "%$escapedSearch%", 'LIKE');
				}

				// Get total count for random
				$countQuery = clone $query;
				$total = (int) $countQuery->countQuery()->execute()->fetchField();

				$query->orderRandom()->range($page * $limit, $limit);
				$nids = $query->execute()->fetchCol();
			} else {
				// Use entity query for normal sorting
				$query = $storage->getQuery()->accessCheck(false)->condition('type', 'article');

				if ($search) {
					$group = $query->orConditionGroup();
					$group->condition('field_article_title', $search, 'CONTAINS');
					$group->condition('field_article_description', $search, 'CONTAINS');
					$group->condition('field_article_content', $search, 'CONTAINS');
					$query->condition($group);
				}

				$countQuery = clone $query;
				$total = (int) $countQuery->count()->execute();

				// Add sorting
				$sortDirection = $sort === 'desc' ? 'DESC' : 'ASC';
				$query->sort('created', $sortDirection);

				$query->range($page * $limit, $limit);
				$nids = $query->execute();
			}

			/** @var Node[] $nodes */
			$nodes = $storage->loadMultiple($nids);
			$data = [];
			foreach ($nodes as $node) {
				$article = ArticlesHelper::nodeToArticle($node);
				if ($article) {
					$item = ArticlesHelper::serializeArticle($article, $requester);
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
				return GeneralHelper::notFound('No articles found');
			}

			$results = [];

			foreach ($nids as $randomNid) {
				$node = Node::load($randomNid);

				if (!$node) {
					return GeneralHelper::internalError('Failed to load random article');
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
		if (!is_string($content) || strlen($content) < 50 || strlen($content) > 25000) {
			return GeneralHelper::badRequest(
				'Field content must be a string between 50 and 25,000 characters',
			);
		}

		$censor = $body['censor'] ?? false;
		if (!is_bool($censor)) {
			return GeneralHelper::badRequest('Field censor must be a boolean');
		}

		$flagResult = GeneralHelper::isFlagged($content);
		if ($flagResult['flagged']) {
			if ($censor) {
				$content = GeneralHelper::censorText($content);
			} else {
				Drupal::logger('mantle2')->warning(
					'User %uid attempted to create flagged article: %article (matched: %matched)',
					[
						'%uid' => $user->id(),
						'%article' => $content,
						'%matched' => $flagResult['matched_word'],
					],
				);
				return GeneralHelper::badRequest(
					'Article contains inappropriate content: ' . $flagResult['matched_word'],
				);
			}
		}

		// Validate ocean article
		$ocean = $body['ocean'] ?? null;
		if ($ocean !== null) {
			$ocean = ArticlesHelper::validateOcean($ocean, $user);
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

		$item = ArticlesHelper::serializeArticle($article, $user);
		return new JsonResponse($item, Response::HTTP_CREATED);
	}

	// GET /v2/articles/{articleId}
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

		$item = ArticlesHelper::serializeArticle($article, $requester);
		return new JsonResponse($item, Response::HTTP_OK);
	}

	// GET /v2/articles/{articleId}/quiz
	public function getArticleQuiz(Node $articleId): JsonResponse
	{
		if ($articleId->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$article = ArticlesHelper::nodeToArticle($articleId);
		if (!$article) {
			return GeneralHelper::internalError('Failed to load article');
		}

		$quiz = ArticlesHelper::getArticleQuiz($articleId->id());
		if ($quiz === null) {
			return GeneralHelper::notFound('Quiz not found for this article');
		}

		return new JsonResponse($quiz, Response::HTTP_OK);
	}

	// PATCH /v2/articles/{articleId}
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
			if (!is_string($content) || strlen($content) < 50 || strlen($content) > 25000) {
				return GeneralHelper::badRequest(
					'Field content must be a string between 50 and 25,000 characters',
				);
			}

			$censor = $body['censor'] ?? false;
			if (!is_bool($censor)) {
				return GeneralHelper::badRequest('Field censor must be a boolean');
			}

			$flagResult = GeneralHelper::isFlagged($content);
			if ($flagResult['flagged']) {
				if ($censor) {
					$body['content'] = GeneralHelper::censorText($content);
				} else {
					return GeneralHelper::badRequest(
						'Article content contains inappropriate language: ' .
							$flagResult['matched_word'],
					);
				}
			}
		}

		// Validate ocean article
		$ocean = $body['ocean'] ?? null;
		if ($ocean !== null) {
			$ocean = ArticlesHelper::validateOcean($ocean, $user);
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

		$item = ArticlesHelper::serializeArticle($article, $user);
		return new JsonResponse($item, Response::HTTP_OK);
	}

	// DELETE /v2/articles/{articleId}
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
