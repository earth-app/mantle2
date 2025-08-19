<?php

namespace Drupal\earth_api\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Event Signup entity.
 *
 * @ContentEntityType(
 *   id = "earth_event_signup",
 *   label = @Translation("Event Signup"),
 *   base_table = "earth_event_signups",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *   }
 * )
 */
class EventSignup extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // User reference
    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setDescription(t('The user who signed up'))
      ->setSetting('target_type', 'earth_user')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -2,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Event reference
    $fields['event_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Event'))
      ->setDescription(t('The event'))
      ->setSetting('target_type', 'earth_event')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Signup date
    $fields['signup_date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Signup Date'))
      ->setDescription(t('When the user signed up for the event'))
      ->setDefaultValueCallback(static::class . '::getDefaultTimestamp')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Default value callback for the signup_date field.
   */
  public static function getDefaultTimestamp() {
    return time();
  }

  /**
   * Get the user ID.
   */
  public function getUserId() {
    return $this->get('user_id')->target_id;
  }

  /**
   * Set the user ID.
   */
  public function setUserId($user_id) {
    $this->set('user_id', $user_id);
    return $this;
  }

  /**
   * Get the event ID.
   */
  public function getEventId() {
    return $this->get('event_id')->target_id;
  }

  /**
   * Set the event ID.
   */
  public function setEventId($event_id) {
    $this->set('event_id', $event_id);
    return $this;
  }

  /**
   * Get the signup date.
   */
  public function getSignupDate() {
    return $this->get('signup_date')->value;
  }

  /**
   * Set the signup date.
   */
  public function setSignupDate($timestamp) {
    $this->set('signup_date', $timestamp);
    return $this;
  }

}