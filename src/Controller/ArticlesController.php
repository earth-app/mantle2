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

final class ArticlesController extends ControllerBase
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

		$filter_tags = $request->query->get('tags');
		if ($filter_tags) {
			$filter_tags = explode(',', $filter_tags);
			$filter_tags = array_map('trim', $filter_tags);
			$filter_tags = array_filter($filter_tags, fn($tag) => !empty($tag));
			$filter_tags = array_map(
				fn($tag) => strtolower(str_replace('_', ' ', $tag)),
				$filter_tags,
			);
		}

		$filter_author = $request->query->getInt('author');
		if ($filter_author) {
			if ($filter_author <= 0) {
				return GeneralHelper::badRequest('Invalid author ID');
			}

			if (!User::load($filter_author)) {
				return GeneralHelper::badRequest('Author not found');
			}
		}

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
					$query->condition($group);
				}

				if ($filter_author) {
					$query->condition('n.field_author_id', $filter_author);
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

				if ($filter_author) {
					$query->condition('field_author_id', $filter_author);
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

			if ($filter_tags) {
				$normalize_tag = fn($tag) => strtolower(str_replace('_', ' ', $tag));
				$nodes = array_filter($nodes, function ($node) use ($filter_tags, $normalize_tag) {
					$stored_tags = (array) json_decode(
						$node->get('field_article_tags')->value ?? '[]',
						true,
					);
					$normalized_stored = array_map($normalize_tag, $stored_tags);
					return !empty(array_intersect($filter_tags, $normalized_stored));
				});
			}

			$data = [];
			foreach ($nodes as $node) {
				$article = ArticlesHelper::nodeToArticle($node);
				$item = ArticlesHelper::serializeArticle($article, $requester);
				$data[] = $item;
			}

			return new JsonResponse([
				'page' => $page + 1,
				'total' => $total,
				'limit' => $limit,
				'items' => $data,
				'search' => $search,
				'author' => $filter_author ? GeneralHelper::formatId($filter_author) : null,
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

				$results[] = ArticlesHelper::serializeArticle($article, $requester);
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

		if ($block = UsersHelper::requireEmailVerified($user, 'publish articles')) {
			return $block;
		}

		if (!UsersHelper::isWriter($user)) {
			return GeneralHelper::paymentRequired('Upgrade to Writer required');
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

		$validated = GeneralHelper::validateUserContent(
			$content,
			$censor,
			'article',
			(int) $user->id(),
		);
		if ($validated instanceof JsonResponse) {
			return $validated;
		}
		$content = $validated;

		// Validate ocean article
		$ocean = $body['ocean'] ?? null;
		if ($ocean !== null) {
			$ocean = ArticlesHelper::validateOcean($ocean, $user);
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
		$item = ArticlesHelper::serializeArticle($article, $user);
		return new JsonResponse($item, Response::HTTP_CREATED);
	}

	// GET /v2/articles/{articleId}
	public function getArticle(int $articleId, Request $request): JsonResponse
	{
		$requester = UsersHelper::getOwnerOfRequest($request);

		$node = Node::load($articleId);
		if (!$node) {
			return GeneralHelper::notFound('Article not found');
		}

		if ($node->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$article = ArticlesHelper::nodeToArticle($node);
		$item = ArticlesHelper::serializeArticle($article, $requester);
		return new JsonResponse($item, Response::HTTP_OK);
	}

	// GET /v2/articles/{articleId}/quiz
	public function getArticleQuiz(int $articleId): JsonResponse
	{
		$node = Node::load($articleId);
		if (!$node) {
			return GeneralHelper::notFound('Article not found');
		}

		if ($node->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$quiz = ArticlesHelper::getArticleQuiz($articleId);
		if (empty($quiz) || empty($quiz['questions'])) {
			return GeneralHelper::notFound('Quiz not found for this article');
		}

		return new JsonResponse($quiz, Response::HTTP_OK);
	}

	// PATCH /v2/articles/{articleId}
	public function updateArticle(int $articleId, Request $request): JsonResponse
	{
		$node = Node::load($articleId);
		if (!$node) {
			return GeneralHelper::notFound('Article not found');
		}

		if ($node->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$author = $node->get('field_author_id')->entity;
		if (!$author) {
			return GeneralHelper::internalError('Article author not found');
		}

		if ($author->id() !== $user->id() && !UsersHelper::isAdmin($user)) {
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
					$node->set("field_article_$field", json_encode($body[$field]));
				} elseif ($field === 'color') {
					$color0 = hexdec(substr($color, 1));
					$node->set("field_article_$field", $color0);
				} else {
					$node->set("field_article_$field", $body[$field]);
				}
			}
		}

		try {
			$node->save();
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to update article: ' . $e->getMessage());
		}

		// Load the updated article
		$article = ArticlesHelper::nodeToArticle($node);

		$item = ArticlesHelper::serializeArticle($article, $user);
		return new JsonResponse($item, Response::HTTP_OK);
	}

	// DELETE /v2/articles/{articleId}
	public function deleteArticle(int $articleId, Request $request): JsonResponse
	{
		$node = Node::load($articleId);
		if (!$node) {
			return GeneralHelper::notFound('Article not found');
		}

		if ($node->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$author = $node->get('field_author_id')->entity;
		if (!$author) {
			return GeneralHelper::internalError('Article author not found');
		}

		if ($author->id() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden('You do not have permission to delete this article.');
		}

		try {
			$node->delete();
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to delete article: ' . $e->getMessage());
		}

		return new JsonResponse(null, Response::HTTP_NO_CONTENT);
	}

	// POST /v2/articles/{articleId}/quiz
	public function createOrUpdateArticleQuiz(int $articleId, Request $request): JsonResponse
	{
		$node = Node::load($articleId);
		if (!$node) {
			return GeneralHelper::notFound('Article not found');
		}

		if ($node->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		if ($block = UsersHelper::requireEmailVerified($user, 'create quizzes')) {
			return $block;
		}

		$author = $node->get('field_author_id')->entity;
		if (!$author) {
			return GeneralHelper::internalError('Article author not found');
		}

		if ($author->id() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden(
				'You do not have permission to create a quiz for this article.',
			);
		}

		$rank = UsersHelper::getAccountType($user)->name;
		if ($rank !== 'ORGANIZER' && $rank !== 'ADMINISTRATOR') {
			return GeneralHelper::paymentRequired('Upgrade to Organizer required');
		}

		$body = json_decode($request->getContent(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return GeneralHelper::badRequest('Invalid JSON body: ' . json_last_error_msg());
		}

		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$questions = $body['questions'] ?? null;
		if (!is_array($questions) || empty($questions)) {
			return GeneralHelper::badRequest('Field questions must be a non-empty array');
		}

		if (count($questions) > 10) {
			return GeneralHelper::badRequest('Field questions can have a maximum of 10 items');
		}

		$uid = (int) $user->id();
		foreach ($questions as $index => $question) {
			if (!is_array($question)) {
				return GeneralHelper::badRequest("Question at index $index must be an object");
			}

			$qText = $question['question'] ?? null;
			if (!is_string($qText) || strlen($qText) < 5 || strlen($qText) > 256) {
				return GeneralHelper::badRequest(
					"Question text at index $index must be a string between 5 and 256 characters",
				);
			}

			$validatedQ = GeneralHelper::validateUserContent(
				$qText,
				false,
				"quiz question $index",
				$uid,
			);
			if ($validatedQ instanceof JsonResponse) {
				return $validatedQ;
			}

			$qType = $question['type'] ?? null;
			if (
				!in_array($qType, ['multiple_choice', 'multi_select', 'true_false', 'order'], true)
			) {
				return GeneralHelper::badRequest("Invalid question type at index $index");
			}

			// per-type validation. multiple_choice + true_false share the single-correct_answer
			// path; multi_select uses correct_answers (array); order uses items (no options).
			if ($qType === 'order') {
				$items = $question['items'] ?? null;
				if (!is_array($items) || count($items) < 3 || count($items) > 6) {
					return GeneralHelper::badRequest(
						"Field items for order question at index $index must be an array of 3-6 strings",
					);
				}
				foreach ($items as $itemIndex => $item) {
					if (!is_string($item) || strlen($item) < 1 || strlen($item) > 64) {
						return GeneralHelper::badRequest(
							"Each item for order question at index $index must be a string between 1 and 64 characters",
						);
					}
					$validatedItem = GeneralHelper::validateUserContent(
						$item,
						false,
						"quiz question $index item $itemIndex",
						$uid,
					);
					if ($validatedItem instanceof JsonResponse) {
						return $validatedItem;
					}
				}
				// strip option/answer fields that don't belong on the order shape so storage stays clean
				unset(
					$question['options'],
					$question['correct_answer'],
					$question['correct_answer_index'],
				);
			} else {
				$options = $question['options'] ?? null;
				if (!is_array($options) || empty($options)) {
					return GeneralHelper::badRequest(
						"Field options for question at index $index must be a non-empty array",
					);
				}

				if ($qType === 'multi_select') {
					if (count($options) < 3 || count($options) > 6) {
						return GeneralHelper::badRequest(
							"Field options for multi_select question at index $index must have between 3 and 6 items",
						);
					}
					$correctAnswers = $question['correct_answers'] ?? null;
					if (!is_array($correctAnswers) || count($correctAnswers) < 1) {
						return GeneralHelper::badRequest(
							"Field correct_answers for multi_select question at index $index must be a non-empty array",
						);
					}
					if (count($correctAnswers) >= count($options)) {
						return GeneralHelper::badRequest(
							"multi_select question at index $index must have at least one INCORRECT option",
						);
					}
					$correctIndices = [];
					foreach ($correctAnswers as $answer) {
						$idx = array_search($answer, $options, true);
						if ($idx === false) {
							return GeneralHelper::badRequest(
								"Each correct_answers entry for question at index $index must match one of the options",
							);
						}
						$correctIndices[] = $idx;
					}
					sort($correctIndices);
					$question['correct_answer_indices'] = array_values(
						array_unique($correctIndices),
					);
					unset($question['correct_answer'], $question['correct_answer_index']);

					foreach ($options as $optIndex => $option) {
						if (!is_string($option) || strlen($option) < 1 || strlen($option) > 64) {
							return GeneralHelper::badRequest(
								"Each option for question at index $index must be a string between 1 and 64 characters",
							);
						}
						$validatedOpt = GeneralHelper::validateUserContent(
							$option,
							false,
							"quiz question $index option $optIndex",
							$uid,
						);
						if ($validatedOpt instanceof JsonResponse) {
							return $validatedOpt;
						}
					}
				} else {
					// multiple_choice + true_false: single correct_answer string
					$correctAnswer = $question['correct_answer'] ?? null;
					if ($correctAnswer === null) {
						return GeneralHelper::badRequest(
							"Field correct_answer is required for question at index $index",
						);
					}

					$correctAnswerIndex = array_search($correctAnswer, $options, true);
					if ($correctAnswerIndex === false) {
						return GeneralHelper::badRequest(
							"Correct answer for question at index $index must be one of the options",
						);
					}
					$question['correct_answer_index'] = $correctAnswerIndex;

					if ($qType === 'multiple_choice') {
						if (count($options) < 2 || count($options) > 6) {
							return GeneralHelper::badRequest(
								"Field options for multiple choice question at index $index must have between 2 and 6 items",
							);
						}
						foreach ($options as $optIndex => $option) {
							if (
								!is_string($option) ||
								strlen($option) < 1 ||
								strlen($option) > 64
							) {
								return GeneralHelper::badRequest(
									"Each option for question at index $index must be a string between 1 and 64 characters",
								);
							}
							$validatedOpt = GeneralHelper::validateUserContent(
								$option,
								false,
								"quiz question $index option $optIndex",
								$uid,
							);
							if ($validatedOpt instanceof JsonResponse) {
								return $validatedOpt;
							}
						}
					} elseif ($qType === 'true_false') {
						if (
							count($options) !== 2 &&
							!in_array('True', $options, true) &&
							!in_array('False', $options, true)
						) {
							return GeneralHelper::badRequest(
								"Field options for true/false question at index $index must have exactly 2 items",
							);
						}
						$question['is_true'] = $correctAnswer === 'True';
						$question['is_false'] = $correctAnswer === 'False';
					}
				}
			}
		}

		try {
			ArticlesHelper::saveArticleQuiz($articleId, $questions);
			return new JsonResponse(
				['message' => 'Article quiz saved successfully', 'questions' => $questions],
				Response::HTTP_OK,
			);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to save quiz: ' . $e->getMessage());
		}
	}

	// DELETE /v2/articles/{articleId}/quiz
	public function deleteArticleQuiz(int $articleId, Request $request): JsonResponse
	{
		$node = Node::load($articleId);
		if (!$node) {
			return GeneralHelper::notFound('Article not found');
		}

		if ($node->getType() !== 'article') {
			return GeneralHelper::badRequest('ID does not point to an article');
		}

		$user = UsersHelper::findByRequest($request);
		if ($user instanceof JsonResponse) {
			return $user;
		}

		$author = $node->get('field_author_id')->entity;
		if (!$author) {
			return GeneralHelper::internalError('Article author not found');
		}

		if ($author->id() !== $user->id() && !UsersHelper::isAdmin($user)) {
			return GeneralHelper::forbidden(
				'You do not have permission to delete the quiz for this article.',
			);
		}

		$existingQuiz = ArticlesHelper::getArticleQuiz($articleId);
		if (empty($existingQuiz) || empty($existingQuiz['questions'])) {
			return GeneralHelper::notFound('Quiz not found for this article');
		}

		try {
			ArticlesHelper::deleteArticleQuiz($articleId);
			return new JsonResponse(null, Response::HTTP_NO_CONTENT);
		} catch (Exception $e) {
			return GeneralHelper::internalError('Failed to delete quiz: ' . $e->getMessage());
		}
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
