<?php

namespace Drupal\earth_api\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Prompt entity.
 *
 * @ContentEntityType(
 *   id = "earth_prompt",
 *   label = @Translation("Prompt"),
 *   base_table = "earth_prompts",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "prompt",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *   }
 * )
 */
class Prompt extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Prompt ID (UUID string)
    $fields['prompt_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Prompt ID'))
      ->setDescription(t('The UUID identifier for the prompt'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 36,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Prompt text
    $fields['prompt'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Prompt'))
      ->setDescription(t('The prompt text'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 1000,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Visibility
    $fields['visibility'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Visibility'))
      ->setDescription(t('The visibility of the prompt'))
      ->setSettings([
        'allowed_values' => [
          'PRIVATE' => 'Private',
          'CIRCLE' => 'Circle',
          'MUTUAL' => 'Mutual',
          'PUBLIC' => 'Public',
        ],
      ])
      ->setDefaultValue('PUBLIC')
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Author - reference to user (optional since some endpoints don't show it)
    $fields['author_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setDescription(t('The user who created this prompt'))
      ->setSetting('target_type', 'earth_user')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Get the prompt ID.
   */
  public function getPromptId() {
    return $this->get('prompt_id')->value;
  }

  /**
   * Set the prompt ID.
   */
  public function setPromptId($prompt_id) {
    $this->set('prompt_id', $prompt_id);
    return $this;
  }

  /**
   * Get the prompt text.
   */
  public function getPrompt() {
    return $this->get('prompt')->value;
  }

  /**
   * Set the prompt text.
   */
  public function setPrompt($prompt) {
    $this->set('prompt', $prompt);
    return $this;
  }

  /**
   * Get the visibility.
   */
  public function getVisibility() {
    return $this->get('visibility')->value;
  }

  /**
   * Set the visibility.
   */
  public function setVisibility($visibility) {
    $this->set('visibility', $visibility);
    return $this;
  }

  /**
   * Get the author ID.
   */
  public function getAuthorId() {
    return $this->get('author_id')->target_id;
  }

  /**
   * Set the author ID.
   */
  public function setAuthorId($author_id) {
    $this->set('author_id', $author_id);
    return $this;
  }

}