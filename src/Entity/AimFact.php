<?php

declare(strict_types=1);

namespace Drupal\aim\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\AimFactAccessControlHandler;
use Drupal\aim\AimFactListBuilder;
use Drupal\aim\AimFactViewsData;
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
  handlers: [
    'views_data' => AimFactViewsData::class,
    'view_builder' => EntityViewBuilder::class,
    'list_builder' => AimFactListBuilder::class,
    'access' => AimFactAccessControlHandler::class,
    'form' => [
      'default' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
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
    'delete-form' => '/admin/content/aim-facts/{aim_fact}/delete',
    'delete-multiple-form' => '/admin/content/aim-facts/delete',
  ],
  admin_permission: 'administer aim memory',
  base_table: 'aim_fact',
)]
class AimFact extends ContentEntityBase implements EntityOwnerInterface, EntityChangedInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

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
      ->setDescription(t('Who or what the fact is about: a role machine name or a case ID. Empty for site scope. Not used for user scope, see user.'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 10]);

    $fields['user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Subject (user)'))
      ->setDescription(t('The real Drupal account this fact is about, when scope is user. A fact cannot be about a person with no account on this site; unlike subject, this is a real reference, not a free-text string that merely happens to hold a uid.'))
      ->setSetting('target_type', 'user')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 20]);

    // target_type/target_id (scope=entity's referenced-entity pair) are
    // not declared here - they are aim_scope_entity's own base fields
    // (AimScopeEntity::getBaseFieldDefinitions()), merged in generically
    // by hook_entity_base_field_info() (AimHooks) per ADR-0028 piece 1.
    $fields['text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Text'))
      ->setDescription(t('The fact itself, as one short statement.'))
      ->setRequired(TRUE)
      // Runs every candidate fact through aim's write guardrails
      // as a real field-level constraint rather than a
      // presave hook, so a rejection surfaces as a normal field error on
      // the entity add/edit form instead of an uncaught exception. See
      // AimGuardrailsConstraint's own docblock.
      ->addConstraint('AimGuardrails')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 0]);

    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source'))
      ->setDescription(t('Free-text provenance reference for the episode this fact was extracted from.'))
      ->setSetting('max_length', 2048)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 30]);

    // A nullable tri-state (true/false/empty, see the description). Core's
    // options_buttons widget lists boolean among its field types and, for a
    // non-required single-value field, adds an "N/A" radio that writes an
    // empty item - so the tri-state needs no custom widget, just not the
    // default boolean_checkbox (which can only ever write true or false).
    $fields['state'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('State'))
      ->setDescription(t('Optional on/off value when this fact is itself a flag (e.g. "opted out of marketing email" = TRUE). Leave empty for facts that are just prose with no boolean shape.'))
      ->setSetting('on_label', t('True'))
      ->setSetting('off_label', t('False'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => 35]);

    $fields['expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Expires'))
      ->setDescription(t('When set, this fact is superseded and should be excluded from retrieval past this time. Set by consolidation instead of deleting the fact outright, to preserve an audit trail.'));

    $fields['superseded_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Superseded by'))
      ->setDescription(t('The aim_fact that replaced this one, set by consolidation when this fact is soft-retired.'))
      ->setSetting('target_type', 'aim_fact');

    $fields['superseded_by_reason'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Retirement reason'))
      ->setDescription(t('JSON provenance for the consolidation decision that retired this fact: decision, auto or model, similarity score, provider and model ID. Never holds fact text.'));

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

    // The draft-to-trusted gate (ADR-0002's addendum): every fact is live in
    // storage the moment it is saved regardless of this value - it is a
    // recall()-time filter, not a moderation state, and carries no revision
    // history. New facts get aim.settings:default_trusted via
    // getDefaultTrusted() below, uniformly across every creation path.
    $fields['trusted'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Trusted'))
      ->setDescription(t('Whether this fact is trusted enough to surface in recall() results. Untrusted facts stay in storage and are visible in this admin listing for review.'))
      ->setDefaultValueCallback(static::class . '::getDefaultTrusted')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 60]);

    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']
      ->setLabel(t('Extracted by'))
      ->setDescription(t('The user or service account the extraction ran as.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the fact was extracted.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the fact was last saved, whether by consolidation, an edit, or any other write.'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return Unicode::truncate($this->get('text')->value ?? '', 60, TRUE, TRUE);
  }

  /**
   * Default value callback for the trusted field.
   *
   * Reads aim.settings:default_trusted so every creation path -
   * remember(), createFactsFromCandidates(), the entity add form, a
   * future migration - applies the same site-wide policy, rather than
   * each caller branching on it individually (ADR-0002's addendum).
   *
   * @return bool
   *   The site's configured default.
   */
  public static function getDefaultTrusted(): bool {
    return (bool) \Drupal::config('aim.settings')->get('default_trusted');
  }

}
