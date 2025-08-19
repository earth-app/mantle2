<?php

namespace Drupal\earth_api\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Activity entity.
 *
 * @ContentEntityType(
 *   id = "earth_activity",
 *   label = @Translation("Activity"),
 *   base_table = "earth_activities",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "name",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *   }
 * )
 */
class Activity extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Activity ID (string identifier)
    $fields['activity_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Activity ID'))
      ->setDescription(t('The unique string identifier for the activity'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 50,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Name field
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The name of the activity'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 100,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Description field
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDescription(t('The description of the activity'))
      ->setSettings([
        'max_length' => 1000,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Types field - stored as JSON array
    $fields['types'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Types'))
      ->setDescription(t('The types of the activity as JSON array'))
      ->setDefaultValue('[]')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Get the activity ID.
   */
  public function getActivityId() {
    return $this->get('activity_id')->value;
  }

  /**
   * Set the activity ID.
   */
  public function setActivityId($activity_id) {
    $this->set('activity_id', $activity_id);
    return $this;
  }

  /**
   * Get the name.
   */
  public function getName() {
    return $this->get('name')->value;
  }

  /**
   * Set the name.
   */
  public function setName($name) {
    $this->set('name', $name);
    return $this;
  }

  /**
   * Get the description.
   */
  public function getDescription() {
    return $this->get('description')->value;
  }

  /**
   * Set the description.
   */
  public function setDescription($description) {
    $this->set('description', $description);
    return $this;
  }

  /**
   * Get types as array.
   */
  public function getTypes() {
    $types = $this->get('types')->value;
    return $types ? json_decode($types, TRUE) : [];
  }

  /**
   * Set types.
   */
  public function setTypes(array $types) {
    $this->set('types', json_encode($types));
    return $this;
  }

}