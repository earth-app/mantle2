<?php

namespace Drupal\earth_api\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Event entity.
 *
 * @ContentEntityType(
 *   id = "earth_event",
 *   label = @Translation("Event"),
 *   base_table = "earth_events",
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
class Event extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Event ID (string identifier)
    $fields['event_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Event ID'))
      ->setDescription(t('The unique string identifier for the event'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 50,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Host ID - reference to user
    $fields['host_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Host'))
      ->setDescription(t('The user hosting this event'))
      ->setSetting('target_type', 'earth_user')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Name field
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setDescription(t('The name of the event'))
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
      ->setDescription(t('The description of the event'))
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

    // Event type
    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Type'))
      ->setDescription(t('The type of event'))
      ->setSettings([
        'allowed_values' => [
          'IN_PERSON' => 'In Person',
          'ONLINE' => 'Online',
          'HYBRID' => 'Hybrid',
        ],
      ])
      ->setDefaultValue('IN_PERSON')
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Activities - stored as JSON array
    $fields['activities'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Activities'))
      ->setDescription(t('The activity types as JSON array'))
      ->setDefaultValue('[]')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Location - stored as JSON (latitude, longitude)
    $fields['location'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Location'))
      ->setDescription(t('The location as JSON (latitude, longitude)'))
      ->setDefaultValue('{}')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Start date
    $fields['date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Start Date'))
      ->setDescription(t('The start date and time of the event'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'weight' => -2,
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // End date
    $fields['end_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('End Date'))
      ->setDescription(t('The end date and time of the event'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'weight' => -1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Visibility
    $fields['visibility'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Visibility'))
      ->setDescription(t('The visibility of the event'))
      ->setSettings([
        'allowed_values' => [
          'PRIVATE' => 'Private',
          'UNLISTED' => 'Unlisted',
          'PUBLIC' => 'Public',
        ],
      ])
      ->setDefaultValue('PUBLIC')
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Get the event ID.
   */
  public function getEventId() {
    return $this->get('event_id')->value;
  }

  /**
   * Set the event ID.
   */
  public function setEventId($event_id) {
    $this->set('event_id', $event_id);
    return $this;
  }

  /**
   * Get the host ID.
   */
  public function getHostId() {
    return $this->get('host_id')->target_id;
  }

  /**
   * Set the host ID.
   */
  public function setHostId($host_id) {
    $this->set('host_id', $host_id);
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
   * Get activities as array.
   */
  public function getActivities() {
    $activities = $this->get('activities')->value;
    return $activities ? json_decode($activities, TRUE) : [];
  }

  /**
   * Set activities.
   */
  public function setActivities(array $activities) {
    $this->set('activities', json_encode($activities));
    return $this;
  }

  /**
   * Get location as array.
   */
  public function getLocation() {
    $location = $this->get('location')->value;
    return $location ? json_decode($location, TRUE) : [];
  }

  /**
   * Set location.
   */
  public function setLocation(array $location) {
    $this->set('location', json_encode($location));
    return $this;
  }

}