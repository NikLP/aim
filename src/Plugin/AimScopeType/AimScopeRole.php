<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\AimScopeType;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Attribute\AimScopeType;
use Drupal\aim\Entity\AimFact;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Guidance for everyone holding one site role: the subject is a role ID.
 *
 * The subject must be a role on this site (validateSubject()), the fact
 * form offers a role picker (the aim_role_select widget), and aim_brief
 * lists the caller's own roles' facts. checkViewAccess() stays
 * neutral: the flat "view {scope} aim facts" permission decides, as it did
 * before this scope had a plugin (ADR-0028's addendum).
 */
#[AimScopeType(
  id: 'role',
  label: new TranslatableMarkup('Role: guidance for one site role'),
)]
class AimScopeRole extends PluginBase implements AimScopeTypeInterface, ContainerFactoryPluginInterface {

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
   *   The entity type manager, used to check the role exists.
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
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  public function resolveSubject(string $subject, string $scopeId): string {
    return $subject;
  }

  /**
   * {@inheritdoc}
   */
  public function validateSubject(AimFact $fact): ?TranslatableMarkup {
    $subject = (string) $fact->get('subject')->value;
    if ($subject === '') {
      return new TranslatableMarkup('A role fact needs a subject: the machine name of a role on this site, e.g. content_editor.');
    }
    if (!$this->entityTypeManager->getStorage('user_role')->load($subject)) {
      return new TranslatableMarkup('No role "@role" on this site. A role fact must be about a real role, by its machine name.', ['@role' => $subject]);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function alterFormDisplay(EntityFormDisplayInterface $display, array $removed): void {
    $display->setComponent('subject', ['type' => 'aim_role_select', 'settings' => []] + ($removed['subject'] ?? []));
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
