<?php

declare(strict_types=1);

namespace Drupal\aim_scope_case\Plugin\AimScopeType;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Attribute\AimScopeType;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Plugin\AimScopeType\AimScopeTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Subject auto-minting for scope=case aim_fact entities.
 *
 * Moved out of core aim's AimMemoryManager::remember() (ADR-0026's
 * follow-up audit flagged this as scope-specific behavior, not a config
 * flag, so requires_account's ThirdPartySetting mechanism didn't apply -
 * needed defaultSubject() added to AimScopeTypeInterface instead).
 * checkViewAccess() stays neutral: case-scope access control is a
 * separate, still-unbuilt problem, not addressed by this plugin.
 */
#[AimScopeType(
  id: 'case',
  label: new TranslatableMarkup('Case scope subject minting'),
)]
class AimScopeCase extends PluginBase implements AimScopeTypeInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID service, used to mint a new case ID.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected UuidInterface $uuid,
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
      $container->get('uuid'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkViewAccess(AimFact $fact, AccountInterface $account): AccessResultInterface {
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSubject(): ?string {
    return 'case-' . substr($this->uuid->generate(), 0, 8);
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFieldDefinitions(): array {
    return [];
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
