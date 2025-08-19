<?php

namespace Drupal\earth_api\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Earth User entity.
 *
 * @ContentEntityType(
 *   id = "earth_user",
 *   label = @Translation("Earth User"),
 *   base_table = "earth_users",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "username" = "username",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *   }
 * )
 */
class EarthUser extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Username field - matches OpenAPI pattern
    $fields['username'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Username'))
      ->setDescription(t('The username of the user'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 30,
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

    // Email field
    $fields['email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Email'))
      ->setDescription(t('The email of the user'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Password hash field
    $fields['password_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Password Hash'))
      ->setDescription(t('The password hash of the user'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ]);

    // Password salt field  
    $fields['password_salt'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Password Salt'))
      ->setDescription(t('The salt used for password hashing'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ]);

    // First name
    $fields['first_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('First Name'))
      ->setDescription(t('The first name of the user'))
      ->setSettings([
        'max_length' => 30,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -2,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Last name
    $fields['last_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Last Name'))
      ->setDescription(t('The last name of the user'))
      ->setSettings([
        'max_length' => 30,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Country field
    $fields['country'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Country'))
      ->setDescription(t('The country code of the user'))
      ->setSettings([
        'max_length' => 2,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Phone number
    $fields['phone_number'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Phone Number'))
      ->setDescription(t('The phone number of the user'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Address
    $fields['address'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Address'))
      ->setDescription(t('The address of the user'))
      ->setSettings([
        'max_length' => 100,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Bio
    $fields['bio'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Bio'))
      ->setDescription(t('The bio of the user'))
      ->setSettings([
        'max_length' => 500,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Visibility settings - stored as JSON
    $fields['visibility_settings'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Visibility Settings'))
      ->setDescription(t('The visibility settings as JSON'))
      ->setDefaultValue('{}')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Account type
    $fields['account_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Account Type'))
      ->setDescription(t('The type of account'))
      ->setSettings([
        'allowed_values' => [
          'free' => 'Free',
          'pro' => 'Pro',
          'writer' => 'Writer',
          'organizer' => 'Organizer',
          'administrator' => 'Administrator',
        ],
      ])
      ->setDefaultValue('free')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Last login timestamp
    $fields['last_login'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last Login'))
      ->setDescription(t('The time when the user last logged in'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Get the username.
   */
  public function getUsername() {
    return $this->get('username')->value;
  }

  /**
   * Set the username.
   */
  public function setUsername($username) {
    $this->set('username', $username);
    return $this;
  }

  /**
   * Get the email.
   */
  public function getEmail() {
    return $this->get('email')->value;
  }

  /**
   * Set the email.
   */
  public function setEmail($email) {
    $this->set('email', $email);
    return $this;
  }

  /**
   * Get visibility settings as array.
   */
  public function getVisibilitySettings() {
    $settings = $this->get('visibility_settings')->value;
    return $settings ? json_decode($settings, TRUE) : [];
  }

  /**
   * Set visibility settings.
   */
  public function setVisibilitySettings(array $settings) {
    $this->set('visibility_settings', json_encode($settings));
    return $this;
  }

  /**
   * Get last login timestamp.
   */
  public function getLastLogin() {
    return $this->get('last_login')->value;
  }

  /**
   * Set last login timestamp.
   */
  public function setLastLogin($timestamp = NULL) {
    $this->set('last_login', $timestamp ?? time());
    return $this;
  }

}