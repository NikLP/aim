<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\AimScopeType;

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
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Facts about one piece of content, viewable as that content is (ADR-0027).
 *
 * Adds target_type/target_id to aim_fact. checkViewAccess() returns the
 * referenced entity's own view access, never forbidden.
 */
#[AimScopeType(
  id: 'entity',
  label: new TranslatableMarkup('Entity: about one piece of content'),
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
  public function resolveSubject(string $subject, string $scopeId): string {
    // A referenced entity can't be auto-minted - the caller must supply
    // target_type/target_id explicitly.
    return $subject;
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFieldDefinitions(): array {
    // Installed with aim, whether or not a scope of this type is enabled
    // (ADR-0063 decision 5).
    $fields = [];

    $fields['target_type'] = BaseFieldDefinition::create('string')
      ->setLabel($this->t('Target entity type'))
      ->setDescription($this->t('The referenced entity type ID (e.g. node), when scope is entity. Empty for every other scope.'))
      ->setProvider('aim')
      ->setSetting('max_length', 32)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', ['weight' => 15]);

    $fields['target_id'] = BaseFieldDefinition::create('string')
      ->setLabel($this->t('Target entity ID'))
      ->setDescription($this->t("The referenced entity's ID, when scope is entity. A string, since not every entity type keys on an integer. Empty for every other scope."))
      ->setProvider('aim')
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
