<?php

declare(strict_types=1);

namespace Drupal\aim\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\AimFactAccessControlHandler;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

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
  handlers: [
    'views_data' => EntityViewsData::class,
    'view_builder' => EntityViewBuilder::class,
    'access' => AimFactAccessControlHandler::class,
    'form' => [
      'default' => ContentEntityForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
    ],
  ],
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
    'bundle' => 'scope',
    'owner' => 'uid',
  ],
  bundle_entity_type: 'aim_scope',
  links: [
    'add-page' => '/admin/content/aim-facts/add',
    'add-form' => '/admin/content/aim-facts/add/{aim_scope}',
    'canonical' => '/admin/content/aim-facts/{aim_fact}',
    'edit-form' => '/admin/content/aim-facts/{aim_fact}/edit',
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

    $fields['scope'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Scope'))
      ->setDescription(t('Which scope this fact belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['subject'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Subject'))
      ->setDescription(t('Who or what the fact is about: a role machine name or a case ID. Empty for site scope. Not used for user scope, see subject_uid.'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 10]);

    $fields['subject_uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Subject (user)'))
      ->setDescription(t('The real Drupal account this fact is about, when scope is user. A fact cannot be about a person with no account on this site; unlike subject, this is a real reference, not a free-text string that merely happens to hold a uid.'))
      ->setSetting('target_type', 'user')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 20]);

    $fields['text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Text'))
      ->setDescription(t('The fact itself, as one short statement.'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 0]);

    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source'))
      ->setDescription(t('Free-text provenance reference for the episode this fact was extracted from.'))
      ->setSetting('max_length', 2048)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 30]);

    // Not form-configurable: the field is a nullable tri-state (true/false/
    // empty, see the description above), but core's only widget for a plain
    // boolean field is a checkbox, which can only write true or false, never
    // leave it empty. Still settable via aim:remember --state or the MCP
    // tool. A real tri-state widget is unbuilt - see CLAUDE.md.
    $fields['state'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('State'))
      ->setDescription(t('Optional on/off value when this fact is itself a flag (e.g. "opted out of marketing email" = TRUE). Leave empty for facts that are just prose with no boolean shape.'));

    $fields['expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Expires'))
      ->setDescription(t('When set, this fact is superseded and should be excluded from retrieval past this time. Set by consolidation instead of deleting the fact outright, to preserve an audit trail.'));

    $fields['related'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Related facts'))
      ->setDescription(t('Other aim_fact entities this one is linked to, e.g. the fact that superseded it during consolidation.'))
      ->setSetting('target_type', 'aim_fact')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['category'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Category'))
      ->setDescription(t('Optional classification tag(s).'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['aim_category' => 'aim_category']])
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => 50]);

    $fields['asserted'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Asserted'))
      ->setDescription(t('When this fact became true in reality, if known and different from when it was recorded. Empty means "same as created" - only set this when a caller explicitly knows an earlier real-world date (e.g. "I moved three months ago").'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => 40]);

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
