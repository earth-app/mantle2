<?php

namespace Drupal\earth_api\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Prompt Response entity.
 *
 * @ContentEntityType(
 *   id = "earth_prompt_response",
 *   label = @Translation("Prompt Response"),
 *   base_table = "earth_prompt_responses",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "response",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *   }
 * )
 */
class PromptResponse extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Response ID (UUID string)
    $fields['response_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Response ID'))
      ->setDescription(t('The UUID identifier for the response'))
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

    // Prompt ID reference
    $fields['prompt_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Prompt'))
      ->setDescription(t('The prompt this is a response to'))
      ->setSetting('target_type', 'earth_prompt')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Response text
    $fields['response'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Response'))
      ->setDescription(t('The response text'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 700,
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

    // Author - reference to user
    $fields['author_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setDescription(t('The user who created this response'))
      ->setSetting('target_type', 'earth_user')
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

    return $fields;
  }

  /**
   * Get the response ID.
   */
  public function getResponseId() {
    return $this->get('response_id')->value;
  }

  /**
   * Set the response ID.
   */
  public function setResponseId($response_id) {
    $this->set('response_id', $response_id);
    return $this;
  }

  /**
   * Get the prompt ID.
   */
  public function getPromptId() {
    return $this->get('prompt_id')->target_id;
  }

  /**
   * Set the prompt ID.
   */
  public function setPromptId($prompt_id) {
    $this->set('prompt_id', $prompt_id);
    return $this;
  }

  /**
   * Get the response text.
   */
  public function getResponse() {
    return $this->get('response')->value;
  }

  /**
   * Set the response text.
   */
  public function setResponse($response) {
    $this->set('response', $response);
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