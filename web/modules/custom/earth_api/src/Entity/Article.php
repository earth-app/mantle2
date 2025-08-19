<?php

namespace Drupal\earth_api\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Article entity.
 *
 * @ContentEntityType(
 *   id = "earth_article",
 *   label = @Translation("Article"),
 *   base_table = "earth_articles",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *   }
 * )
 */
class Article extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Article ID (string identifier)
    $fields['article_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Article ID'))
      ->setDescription(t('The unique string identifier for the article'))
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

    // Title field
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The title of the article'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 48,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Summary field
    $fields['summary'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Summary'))
      ->setDescription(t('The summary of the article'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 512,
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

    // Tags - stored as JSON array
    $fields['tags'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Tags'))
      ->setDescription(t('The tags as JSON array'))
      ->setDefaultValue('[]')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Content field
    $fields['content'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Content'))
      ->setDescription(t('The content of the article'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 10000,
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

    // Ocean data - stored as JSON
    $fields['ocean'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Ocean Data'))
      ->setDescription(t('The Ocean metadata as JSON'))
      ->setDefaultValue('{}')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Author - reference to user
    $fields['author_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setDescription(t('The user who created this article'))
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
   * Get the article ID.
   */
  public function getArticleId() {
    return $this->get('article_id')->value;
  }

  /**
   * Set the article ID.
   */
  public function setArticleId($article_id) {
    $this->set('article_id', $article_id);
    return $this;
  }

  /**
   * Get the title.
   */
  public function getTitle() {
    return $this->get('title')->value;
  }

  /**
   * Set the title.
   */
  public function setTitle($title) {
    $this->set('title', $title);
    return $this;
  }

  /**
   * Get the summary.
   */
  public function getSummary() {
    return $this->get('summary')->value;
  }

  /**
   * Set the summary.
   */
  public function setSummary($summary) {
    $this->set('summary', $summary);
    return $this;
  }

  /**
   * Get tags as array.
   */
  public function getTags() {
    $tags = $this->get('tags')->value;
    return $tags ? json_decode($tags, TRUE) : [];
  }

  /**
   * Set tags.
   */
  public function setTags(array $tags) {
    $this->set('tags', json_encode($tags));
    return $this;
  }

  /**
   * Get the content.
   */
  public function getContent() {
    return $this->get('content')->value;
  }

  /**
   * Set the content.
   */
  public function setContent($content) {
    $this->set('content', $content);
    return $this;
  }

  /**
   * Get ocean data as array.
   */
  public function getOcean() {
    $ocean = $this->get('ocean')->value;
    return $ocean ? json_decode($ocean, TRUE) : [];
  }

  /**
   * Set ocean data.
   */
  public function setOcean(array $ocean) {
    $this->set('ocean', json_encode($ocean));
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