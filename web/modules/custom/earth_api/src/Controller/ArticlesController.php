<?php

namespace Drupal\earth_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for article-related API endpoints.
 */
class ArticlesController extends ControllerBase {

  /**
   * List all articles with pagination.
   */
  public function list(Request $request) {
    $page = (int) $request->query->get('page', 1);
    $limit = (int) $request->query->get('limit', 25);
    $search = $request->query->get('search', '');

    $sampleArticle = [
      'id' => 'cbfjwIXdiqBwdn4dyd83g9cq',
      'article_id' => 'article123',
      'title' => 'Understanding Quantum Computing',
      'summary' => 'A deep dive into the principles of quantum computing and its potential applications.',
      'tags' => ['quantum', 'computing', 'technology'],
      'content' => 'Quantum computing is a type of computation that harnesses the principles of quantum mechanics. It uses quantum bits, or qubits, which can exist in multiple states simultaneously, allowing for parallel processing of information. This capability enables quantum computers to solve certain problems much faster than classical computers.',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => '2025-01-15T12:00:00Z',
      'ocean' => [
        'title' => 'Understanding Quantum Computing',
        'url' => 'https://example.com/quantum-computing',
        'author' => 'John Doe',
        'source' => 'Tech Journal',
        'links' => [
          'related' => 'https://example.com/quantum-computing/related',
          'more_info' => 'https://example.com/quantum-computing/more-info',
        ],
        'abstract' => 'A brief overview of quantum computing principles.',
        'content' => 'Quantum computing is a type of computation that harnesses the principles of quantum mechanics. It uses quantum bits, or qubits, which can exist in multiple states simultaneously, allowing for parallel processing of information. This capability enables quantum computers to solve certain problems much faster than classical computers.',
        'theme_color' => '#ff11ff',
        'keywords' => ['quantum', 'computing', 'technology'],
        'date' => '2025-01-15T10:00:00Z',
        'favicon' => 'https://example.com/quantum-computing/favicon.ico',
      ],
    ];

    $data = [
      'page' => $page,
      'limit' => $limit,
      'total' => 1,
      'items' => [$sampleArticle],
    ];

    return new JsonResponse($data);
  }

  /**
   * Create new article.
   */
  public function create(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    
    if (!$content || !isset($content['title']) || !isset($content['description']) || !isset($content['content'])) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $article = [
      'id' => 'article_' . uniqid(),
      'article_id' => 'article_' . uniqid(),
      'title' => $content['title'],
      'summary' => $content['description'],
      'tags' => $content['tags'] ?? [],
      'content' => $content['content'],
      'created_at' => date('c'),
      'ocean' => $content['ocean'] ?? null,
    ];

    return new JsonResponse($article, 201);
  }

  /**
   * Get, update, or delete article by ID.
   */
  public function byId(Request $request, $articleId) {
    $method = $request->getMethod();

    $article = [
      'id' => $articleId,
      'article_id' => $articleId,
      'title' => 'Sample Article',
      'summary' => 'A sample article description',
      'tags' => ['sample', 'article'],
      'content' => 'This is sample article content that demonstrates the structure and format of articles in the Earth App system.',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => '2025-01-15T12:00:00Z',
      'ocean' => [
        'title' => 'Sample Article',
        'url' => 'https://example.com/sample-article',
        'author' => 'Sample Author',
        'source' => 'Sample Source',
        'links' => [],
        'abstract' => 'A sample article abstract',
        'content' => 'Sample article content',
        'theme_color' => '#000000',
        'keywords' => ['sample'],
        'date' => '2025-01-15T10:00:00Z',
        'favicon' => 'https://example.com/favicon.ico',
      ],
    ];

    if ($method === 'DELETE') {
      return new JsonResponse(null, 204);
    }

    if ($method === 'PATCH') {
      $content = json_decode($request->getContent(), TRUE);
      if ($content) {
        if (isset($content['title'])) {
          $article['title'] = $content['title'];
        }
        if (isset($content['description'])) {
          $article['summary'] = $content['description'];
        }
        if (isset($content['content'])) {
          $article['content'] = $content['content'];
        }
        if (isset($content['tags'])) {
          $article['tags'] = $content['tags'];
        }
        $article['updated_at'] = date('c');
      }
    }

    return new JsonResponse($article);
  }

}