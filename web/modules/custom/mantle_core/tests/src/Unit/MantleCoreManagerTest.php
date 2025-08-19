<?php

namespace Drupal\Tests\mantle_core\Unit;

use Drupal\mantle_core\MantleCoreManager;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Tests the MantleCoreManager service.
 *
 * @group mantle_core
 * @coversDefaultClass \Drupal\mantle_core\MantleCoreManager
 */
class MantleCoreManagerTest extends UnitTestCase {

  /**
   * The mantle core manager.
   *
   * @var \Drupal\mantle_core\MantleCoreManager
   */
  protected $mantleCoreManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);

    $this->mantleCoreManager = new MantleCoreManager($entity_type_manager, $config_factory);
  }

  /**
   * Tests getting the application name.
   *
   * @covers ::getApplicationName
   */
  public function testGetApplicationName(): void {
    $this->assertEquals('Mantle2', $this->mantleCoreManager->getApplicationName());
  }

  /**
   * Tests getting the application version.
   *
   * @covers ::getApplicationVersion
   */
  public function testGetApplicationVersion(): void {
    $this->assertEquals('1.0.0', $this->mantleCoreManager->getApplicationVersion());
  }

}