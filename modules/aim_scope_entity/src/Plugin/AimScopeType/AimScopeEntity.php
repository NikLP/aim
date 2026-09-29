<?php

declare(strict_types=1);

namespace Drupal\aim_scope_entity\Plugin\AimScopeType;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Attribute\AimScopeType;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Plugin\AimScopeType\AimScopeTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * View access mirroring the referenced entity's own, for scope=entity facts.
 *
 * The second dedicated AimScopeType plugin (ADR-0025's first was
 * AimScopeUser). Unlike AimScopeUser, this does not need to assemble its
 * own CacheableMetadata - $entity->access(..., TRUE) already folds in the
 * target entity's own cache tags and any contexts its access handler adds.
 * checkViewAccess() only ever returns allowed or neutral, never forbidden,
 * per AimScopeTypeInterface's contract - see ADR-0027 for what "replace,
 * not widen" for this scope actually means given
 * AimFactAccessControlHandler always ORs this result against the flat
 * permission.
 */
#[AimScopeType(
  id: 'entity',
  label: new TranslatableMarkup('Entity scope view access'),
)]
class AimScopeEntity extends PluginBase implements AimScopeTypeInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load the referenced entity.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkViewAccess(AimFact $fact, AccountInterface $account): AccessResultInterface {
    $targetType = $fact->get('target_type')->value;
    $targetId = $fact->get('target_id')->value;
    if (!$targetType || !$targetId || !$this->entityTypeManager->hasDefinition($targetType)) {
      return AccessResult::neutral()->addCacheableDependency($fact);
    }

    $target = $this->entityTypeManager->getStorage($targetType)->load($targetId);
    if (!$target) {
      return AccessResult::neutral()->addCacheableDependency($fact);
    }

    return $target->access('view', $account, TRUE)->addCacheableDependency($fact);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSubject(): ?string {
    // A referenced entity can't be auto-minted - the caller must supply
    // target_type/target_id explicitly.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFieldDefinitions(): array {
    // Moved out of AimFact::baseFieldDefinitions() (core aim) here per
    // ADR-0028 piece 1 - this scope type is the only one that needs
    // these two columns, so the field declarations live with the plugin
    // that needs them. See aim_scope_entity.install for the field
    // lifecycle (installFieldStorageDefinition()/
    // uninstallFieldStorageDefinition()) this requires.
    //
    // setProvider() explicitly: these definitions are returned through
    // core aim's single hook_entity_base_field_info() merge point
    // (AimHooks::entityBaseFieldInfo()), so without this,
    // EntityFieldManager::buildBaseFieldDefinitions() would stamp them
    // with the *hook's* module (aim) as provider, not this plugin's own
    // module - it only defers to an already-set provider
    // ($definition->getProvider() == NULL check), never infers one from
    // which plugin built the definition.
    $fields = [];

    $fields['target_type'] = BaseFieldDefinition::create('string')
      ->setLabel($this->t('Target entity type'))
      ->setDescription($this->t('The referenced entity type ID (e.g. node), when scope is entity. Empty for every other scope.'))
      ->setProvider('aim_scope_entity')
      ->setSetting('max_length', 32)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 15]);

    $fields['target_id'] = BaseFieldDefinition::create('string')
      ->setLabel($this->t('Target entity ID'))
      ->setDescription($this->t("The referenced entity's ID, when scope is entity. A string, since not every entity type keys on an integer. Empty for every other scope."))
      ->setProvider('aim_scope_entity')
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 16]);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSettings(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettingsForm(array $form, FormStateInterface $form_state, array $settings): array {
    return $form;
  }

}
