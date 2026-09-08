<?php

declare(strict_types=1);

namespace Drupal\aim\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the AIM fact content entity.
 *
 * PoC entity: no moderation gate, no revisions yet. Every fact is live and
 * retrievable the moment it is saved. See CLAUDE.md for the governance
 * layer this is deliberately skipping for now.
 */
#[ContentEntityType(
  id: 'aim_fact',
  label: new TranslatableMarkup('AIM fact'),
  label_collection: new TranslatableMarkup('AIM facts'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
    'owner' => 'uid',
  ],
  admin_permission: 'administer aim memory',
  base_table: 'aim_fact',
)]
class AimFact extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['scope'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Scope'))
      ->setDescription(t('Which of the four memory bundles this fact belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'user' => 'User',
        'role' => 'Role',
        'site' => 'Site',
        'case' => 'Case',
      ]);

    $fields['subject'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Subject'))
      ->setDescription(t('Who or what the fact is about: a uid, a role machine name, or a case ID. Empty for site scope.'))
      ->setSetting('max_length', 255);

    $fields['text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Text'))
      ->setDescription(t('The fact itself, as one short statement.'))
      ->setRequired(TRUE);

    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source'))
      ->setDescription(t('Free-text provenance reference for the episode this fact was extracted from.'))
      ->setSetting('max_length', 2048);

    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']
      ->setLabel(t('Extracted by'))
      ->setDescription(t('The user or service account the extraction ran as.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the fact was extracted.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the fact was last updated by consolidation.'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $text = $this->get('text')->value ?? '';
    return strlen($text) > 60 ? substr($text, 0, 57) . '...' : $text;
  }

}
