<?php

namespace Drupal\earth_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for prompt-related API endpoints.
 */
class PromptsController extends ControllerBase {

  /**
   * List all prompts with pagination.
   */
  public function list(Request $request) {
    $page = (int) $request->query->get('page', 1);
    $limit = (int) $request->query->get('limit', 25);
    $search = $request->query->get('search', '');

    $samplePrompt = [
      'id' => '123e4567-e89b-12d3-a456-426614174000',
      'prompt' => 'What is the meaning of life?',
      'visibility' => 'PUBLIC',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => '2025-01-15T12:00:00Z',
    ];

    $data = [
      'page' => $page,
      'limit' => $limit,
      'total' => 1,
      'items' => [$samplePrompt],
    ];

    return new JsonResponse($data);
  }

  /**
   * Get random prompts.
   */
  public function random(Request $request) {
    $limit = (int) $request->query->get('limit', 10);

    $prompts = [
      [
        'id' => '123e4567-e89b-12d3-a456-426614174000',
        'prompt' => 'What is the meaning of life?',
        'visibility' => 'PUBLIC',
        'created_at' => '2025-01-15T10:00:00Z',
        'updated_at' => '2025-01-15T12:00:00Z',
      ],
      [
        'id' => '456e7890-e12b-34d5-a456-426614174001',
        'prompt' => 'What brings you joy?',
        'visibility' => 'PUBLIC',
        'created_at' => '2025-01-15T10:00:00Z',
        'updated_at' => '2025-01-15T12:00:00Z',
      ],
    ];

    return new JsonResponse(array_slice($prompts, 0, $limit));
  }

  /**
   * Create new prompt.
   */
  public function create(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    
    if (!$content || !isset($content['prompt'])) {
      return new JsonResponse([
        'code' => 400,
        'message' => 'Bad Request'
      ], 400);
    }

    $prompt = [
      'id' => '123e4567-e89b-12d3-a456-426614174000',
      'prompt' => $content['prompt'],
      'visibility' => $content['visibility'] ?? 'PUBLIC',
      'created_at' => date('c'),
    ];

    return new JsonResponse($prompt, 201);
  }

  /**
   * Get, update, or delete prompt by ID.
   */
  public function byId(Request $request, $promptId) {
    $method = $request->getMethod();

    $prompt = [
      'id' => $promptId,
      'prompt' => 'Sample prompt text',
      'visibility' => 'PUBLIC',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => '2025-01-15T12:00:00Z',
    ];

    if ($method === 'DELETE') {
      return new JsonResponse(null, 204);
    }

    if ($method === 'PATCH') {
      $content = json_decode($request->getContent(), TRUE);
      if ($content && isset($content['prompt'])) {
        $prompt['prompt'] = $content['prompt'];
        $prompt['updated_at'] = date('c');
      }
    }

    return new JsonResponse($prompt);
  }

  /**
   * Get or create prompt responses.
   */
  public function responses(Request $request, $promptId) {
    $method = $request->getMethod();

    if ($method === 'POST') {
      $content = json_decode($request->getContent(), TRUE);
      
      if (!$content || !isset($content['content'])) {
        return new JsonResponse([
          'code' => 400,
          'message' => 'Bad Request'
        ], 400);
      }

      $response = [
        'id' => '456e7890-e12b-34d5-a456-426614174000',
        'prompt_id' => $promptId,
        'response' => $content['content'],
        'created_at' => date('c'),
      ];

      return new JsonResponse($response, 201);
    }

    // GET responses
    $page = (int) $request->query->get('page', 1);
    $limit = (int) $request->query->get('limit', 25);

    $sampleResponse = [
      'id' => '456e7890-e12b-34d5-a456-426614174000',
      'prompt_id' => $promptId,
      'response' => 'The meaning of life is 42.',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => '2025-01-15T12:00:00Z',
    ];

    $data = [
      'page' => $page,
      'limit' => $limit,
      'total' => 1,
      'items' => [$sampleResponse],
    ];

    return new JsonResponse($data);
  }

  /**
   * Get count of prompt responses.
   */
  public function responsesCount(Request $request, $promptId) {
    $prompt = [
      'id' => $promptId,
      'prompt' => 'Sample prompt for counting',
      'visibility' => 'PUBLIC',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => '2025-01-15T12:00:00Z',
    ];

    $data = [
      'count' => 5,
      'prompt' => $prompt,
    ];

    return new JsonResponse($data);
  }

  /**
   * Get, update, or delete specific prompt response.
   */
  public function responseById(Request $request, $promptId, $responseId) {
    $method = $request->getMethod();

    $response = [
      'id' => $responseId,
      'prompt_id' => $promptId,
      'response' => 'Sample response text',
      'created_at' => '2025-01-15T10:00:00Z',
      'updated_at' => '2025-01-15T12:00:00Z',
    ];

    if ($method === 'DELETE') {
      return new JsonResponse(null, 204);
    }

    if ($method === 'PATCH') {
      $content = json_decode($request->getContent(), TRUE);
      if ($content && isset($content['content'])) {
        $response['response'] = $content['content'];
        $response['updated_at'] = date('c');
      }
    }

    return new JsonResponse($response);
  }

}