<?php

declare(strict_types=1);

namespace Drupal\aim\Entity;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Form\RevisionDeleteForm;
use Drupal\Core\Entity\Form\RevisionRevertForm;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\Routing\RevisionHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\AimFactAccessControlHandler;
use Drupal\aim\AimFactViewsData;
use Drupal\aim\Form\AimFactForm;
use Drupal\aim\Routing\AimFactHtmlRouteProvider;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the AIM fact content entity.
 *
 * Revisionable but not translatable, and no Content Moderation: the
 * draft-to-trusted gate is the `trusted` field, which recall() filters on
 * (ADR-0002 addendum). Only text and grounding_score are revisionable, and
 * a new revision is saved only when the text changes (AimHooks::
 * factPresave(), ADR-0064).
 */
#[ContentEntityType(
  id: 'aim_fact',
  // The scope's plugin checks who or what a fact is about
  // (validateSubject()), e.g. a role or entity target that does not exist.
  constraints: ['AimScopeSubject' => []],
  label: new TranslatableMarkup('AIM fact'),
  label_collection: new TranslatableMarkup('AIM facts'),
  handlers: [
    'views_data' => AimFactViewsData::class,
    'view_builder' => EntityViewBuilder::class,
    'list_builder' => EntityListBuilder::class,
    'access' => AimFactAccessControlHandler::class,
    'form' => [
      'default' => AimFactForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
      'revision-revert' => RevisionRevertForm::class,
      'revision-delete' => RevisionDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AimFactHtmlRouteProvider::class,
      'revision' => RevisionHtmlRouteProvider::class,
    ],
  ],
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
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
    'version-history' => '/admin/content/aim-facts/{aim_fact}/revisions',
    'revision' => '/admin/content/aim-facts/{aim_fact}/revisions/{aim_fact_revision}/view',
    'revision-revert-form' => '/admin/content/aim-facts/{aim_fact}/revisions/{aim_fact_revision}/revert',
    'revision-delete-form' => '/admin/content/aim-facts/{aim_fact}/revisions/{aim_fact_revision}/delete',
  ],
  admin_permission: 'administer aim',
  base_table: 'aim_fact',
  revision_table: 'aim_fact_revision',
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class AimFact extends ContentEntityBase implements EntityOwnerInterface, EntityChangedInterface, RevisionLogInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use RevisionLogEntityTrait;

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
      ->setDescription(t('Who or what the fact is about: a role machine name, or a case ID (a new case is named by it). Empty for site scope. Not used for user scope, see user.'))
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
    // not declared here - they are the entity plugin's own base fields
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
      // A revision corrects the wording (ADR-0064); the view display is
      // what a revision's page shows.
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 0])
      ->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 0]);

    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source'))
      ->setDescription(t('Free-text provenance reference for the episode this fact was extracted from.'))
      ->setSetting('max_length', 2048)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 30]);

    $fields['retired'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Retired'))
      ->setDescription(t('When set, this fact is out of retrieval. Set by consolidation and the Retire action instead of deleting the fact outright, to preserve an audit trail.'));

    $fields['expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Expires'))
      ->setDescription(t('Optional end date. `drush aim:expire` (a crontab entry) retires the fact once it passes (ADR-0057).'));

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
      ->setDescription(t('Valid time: when this fact became true in the world, if known and different from when it was recorded. Empty means "same as created" - only set this when a caller explicitly knows an earlier real-world date (e.g. "I moved three months ago"). Not when it was checked or proven: that is a separate, unbuilt verified date.'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => 40]);

    // The draft-to-trusted gate (ADR-0002's addendum): every fact is live in
    // storage the moment it is saved regardless of this value - it is a
    // recall()-time filter, not a moderation state, and carries no revision
    // history. A new fact starts trusted when its writer may trust facts in
    // its scope (getDefaultTrusted() below, ADR-0065).
    $fields['trusted'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Trusted'))
      ->setDescription(t('Whether this fact is trusted enough to surface in recall() results. Untrusted facts stay in storage and are visible in this admin listing for review.'))
      ->setDefaultValueCallback(static::class . '::getDefaultTrusted')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 60]);

    // ADR-0037's grounded check, run at write time against the text the fact
    // came from; that text is never stored. Empty means not checked.
    // Revisionable: a score belongs to the wording it was measured against,
    // and a new revision clears it (ADR-0064 decision 4).
    $fields['grounding_score'] = BaseFieldDefinition::create('float')
      ->setLabel(t('Grounding score'))
      ->setDescription(t('How likely a decision model judged it that the source text states this fact, 0 to 1, checked when the fact was written. Empty when there was no source text or the check was off or failed.'))
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', ['label' => 'inline', 'type' => 'number_decimal', 'weight' => 10]);

    $fields += static::revisionLogBaseFieldDefinitions($entity_type);
    $fields['revision_log_message']->setDisplayConfigurable('form', TRUE);

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
   * Whether this fact says word for word what another fact says.
   *
   * Compared after normalizeText(). The one kind of duplicate safe to
   * retire without a model: a fact that differs in any word may be a
   * correction (ADR-0036's 2026-10-27 addendum).
   *
   * @param \Drupal\aim\Entity\AimFact $other
   *   The other fact.
   *
   * @return bool
   *   TRUE if the normalized texts match.
   */
  public function hasSameText(AimFact $other): bool {
    return static::normalizeText((string) $this->get('text')->value) === static::normalizeText((string) $other->get('text')->value);
  }

  /**
   * Normalizes fact text for an identical-text comparison.
   *
   * Ignores case, runs of whitespace and a final full stop only. Other
   * punctuation and symbols are kept: "10.30" and "1030", or "£10" and
   * "$10", are different facts.
   *
   * @param string $text
   *   The fact text.
   *
   * @return string
   *   The normalized text.
   */
  public static function normalizeText(string $text): string {
    return rtrim(mb_strtolower((string) preg_replace('/\s+/', ' ', trim($text))), '. ');
  }

  /**
   * Default value callback for the trusted field.
   *
   * The current user's trust permission in the fact's scope (ADR-0065), so
   * the add form's checkbox starts where remember() would. Only a writer
   * who may trust can change it (AimFactAccessControlHandler's field
   * access).
   *
   * @param \Drupal\aim\Entity\AimFact $entity
   *   The new fact.
   *
   * @return bool
   *   TRUE when the current user may trust facts in the fact's scope.
   */
  public static function getDefaultTrusted(AimFact $entity): bool {
    /** @var \Drupal\aim\AimFactAccessControlHandler $access */
    $access = \Drupal::entityTypeManager()->getAccessControlHandler('aim_fact');
    return $access->canApprove(\Drupal::currentUser(), $entity->bundle());
  }

}
