<?php

namespace Drupal\earth_api\Service;

use Drupal\Core\Queue\QueueFactory;

/**
 * Queue Manager for handling background jobs.
 */
class QueueManager {

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Constructs a QueueManager object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   */
  public function __construct(QueueFactory $queue_factory) {
    $this->queueFactory = $queue_factory;
  }

  /**
   * Add a job to the queue.
   *
   * @param string $queue_name
   *   The queue name.
   * @param array $data
   *   The job data.
   *
   * @return int
   *   The queue item ID.
   */
  public function addJob(string $queue_name, array $data): int {
    $queue = $this->queueFactory->get($queue_name);
    return $queue->createItem($data);
  }

  /**
   * Add a prompt generation job.
   *
   * @param array $params
   *   The prompt generation parameters.
   *
   * @return int
   *   The job ID.
   */
  public function addPromptGenerationJob(array $params): int {
    $data = [
      'type' => 'prompt_generation',
      'params' => $params,
      'created' => time(),
    ];
    
    return $this->addJob('earth_api_prompts', $data);
  }

  /**
   * Add a profile generation job.
   *
   * @param array $params
   *   The profile generation parameters.
   *
   * @return int
   *   The job ID.
   */
  public function addProfileGenerationJob(array $params): int {
    $data = [
      'type' => 'profile_generation',
      'params' => $params,
      'created' => time(),
    ];
    
    return $this->addJob('earth_api_profiles', $data);
  }

  /**
   * Add a content synthesis job.
   *
   * @param array $params
   *   The content synthesis parameters.
   *
   * @return int
   *   The job ID.
   */
  public function addContentSynthesisJob(array $params): int {
    $data = [
      'type' => 'content_synthesis',
      'params' => $params,
      'created' => time(),
    ];
    
    return $this->addJob('earth_api_content', $data);
  }

  /**
   * Get queue statistics.
   *
   * @param string $queue_name
   *   The queue name.
   *
   * @return array
   *   Queue statistics.
   */
  public function getQueueStats(string $queue_name): array {
    $queue = $this->queueFactory->get($queue_name);
    
    return [
      'name' => $queue_name,
      'items' => $queue->numberOfItems(),
      'class' => get_class($queue),
    ];
  }

}