<?php

namespace Drupal\earth_api\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * API Manager service for Earth API operations.
 */
class ApiManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs an ApiManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->cache = $cache;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Get the application name.
   *
   * @return string
   *   The application name.
   */
  public function getApplicationName(): string {
    return 'Mantle2';
  }

  /**
   * Get API version.
   *
   * @return string
   *   The API version.
   */
  public function getApiVersion(): string {
    return '1.0.0';
  }

}