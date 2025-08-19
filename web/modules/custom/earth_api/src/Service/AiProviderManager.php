<?php

namespace Drupal\earth_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * AI Provider Manager for handling AI integrations.
 */
class AiProviderManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an AiProviderManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Get the configured AI provider.
   *
   * @return string
   *   The AI provider name.
   */
  public function getProvider(): string {
    $provider = getenv('DRUPAL_AI_PROVIDER') ?: 'stub';
    return $provider;
  }

  /**
   * Get the configured text model.
   *
   * @return string
   *   The text model name.
   */
  public function getTextModel(): string {
    $model = getenv('DRUPAL_AI_MODEL_TEXT') ?: 'meta-llama/llama-3.1-8b-instruct:free';
    return $model;
  }

  /**
   * Get the configured embedding model.
   *
   * @return string
   *   The embedding model name.
   */
  public function getEmbeddingModel(): string {
    $model = getenv('DRUPAL_AI_MODEL_EMBED') ?: 'text-embedding-3-large';
    return $model;
  }

  /**
   * Check if live AI calls are enabled.
   *
   * @return bool
   *   TRUE if live AI is enabled.
   */
  public function isLiveEnabled(): bool {
    $enabled = getenv('AI_ENABLE_LIVE') ?: 'false';
    return $enabled === 'true';
  }

  /**
   * Generate text using the configured AI provider.
   *
   * @param string $prompt
   *   The prompt to send to the AI.
   * @param array $options
   *   Additional options for the AI call.
   *
   * @return string
   *   The generated text response.
   */
  public function generateText(string $prompt, array $options = []): string {
    if (!$this->isLiveEnabled()) {
      // Return deterministic stub response for testing
      return $this->getStubResponse($prompt);
    }

    $provider = $this->getProvider();
    
    switch ($provider) {
      case 'openrouter':
        return $this->callOpenRouter($prompt, $options);
      case 'openai':
        return $this->callOpenAI($prompt, $options);
      case 'anthropic':
        return $this->callAnthropic($prompt, $options);
      case 'stub':
      default:
        return $this->getStubResponse($prompt);
    }
  }

  /**
   * Get a deterministic stub response for testing.
   *
   * @param string $prompt
   *   The input prompt.
   *
   * @return string
   *   A deterministic response.
   */
  protected function getStubResponse(string $prompt): string {
    // Generate deterministic response based on prompt hash for testing
    $hash = substr(md5($prompt), 0, 8);
    return "Generated response for prompt {$hash}. This is a stub response for testing purposes.";
  }

  /**
   * Call OpenRouter API.
   *
   * @param string $prompt
   *   The prompt.
   * @param array $options
   *   Options for the call.
   *
   * @return string
   *   The response.
   */
  protected function callOpenRouter(string $prompt, array $options): string {
    // Implementation would use OpenRouter API
    // For now, return stub
    return $this->getStubResponse($prompt);
  }

  /**
   * Call OpenAI API.
   *
   * @param string $prompt
   *   The prompt.
   * @param array $options
   *   Options for the call.
   *
   * @return string
   *   The response.
   */
  protected function callOpenAI(string $prompt, array $options): string {
    // Implementation would use OpenAI API
    // For now, return stub
    return $this->getStubResponse($prompt);
  }

  /**
   * Call Anthropic API.
   *
   * @param string $prompt
   *   The prompt.
   * @param array $options
   *   Options for the call.
   *
   * @return string
   *   The response.
   */
  protected function callAnthropic(string $prompt, array $options): string {
    // Implementation would use Anthropic API
    // For now, return stub
    return $this->getStubResponse($prompt);
  }

}