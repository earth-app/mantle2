<?php

namespace Drupal\earth_api\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the User Activity relationship entity.
 *
 * @ContentEntityType(
 *   id = "earth_user_activity",
 *   label = @Translation("User Activity"),
 *   base_table = "earth_user_activities",
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
class UserActivity extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // User reference
    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setDescription(t('The user'))
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

    // Activity reference
    $fields['activity_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Activity'))
      ->setDescription(t('The activity'))
      ->setSetting('target_type', 'earth_activity')
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

    return $fields;
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
   * Get the activity ID.
   */
  public function getActivityId() {
    return $this->get('activity_id')->target_id;
  }

  /**
   * Set the activity ID.
   */
  public function setActivityId($activity_id) {
    $this->set('activity_id', $activity_id);
    return $this;
  }

}