<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\AimScopeType;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Attribute\AimScopeType;
use Drupal\aim\Entity\AimFact;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Facts about one account, visible by role pairing (ADR-0025).
 *
 * The flat "view user aim facts" permission still means "see every
 * user-scope fact". This widens it: a viewer may see facts about subjects
 * holding roles the scope's role_visibility matrix lists for the viewer's
 * roles, or, with shared_role_fallback on, sharing a role with the viewer
 * ("authenticated" aside). Both are this scope's own settings
 * (ADR-0063), edited on the scope's form.
 */
#[AimScopeType(
  id: 'user',
  label: new TranslatableMarkup('User: about one account'),
)]
class AimScopeUser extends PluginBase implements AimScopeTypeInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   The scope's settings.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the roles on the settings form.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration + $this->defaultSettings(), $plugin_id, $plugin_definition);
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
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['user.roles'])
      ->addCacheableDependency($fact)
      ->addCacheTags(['config:aim.aim_scope.' . $fact->bundle()]);

    $subject = $fact->get('user')->entity;
    if (!$subject instanceof UserInterface) {
      return AccessResult::neutral()->addCacheableDependency($cacheability);
    }
    $cacheability->addCacheableDependency($subject);

    $subjectRoles = $subject->getRoles();
    $viewerRoles = $account->getRoles();

    $visibleToViewer = [];
    $matrix = $this->configuration['role_visibility'] ?? [];
    foreach ($viewerRoles as $role) {
      $visibleToViewer = array_merge($visibleToViewer, $matrix[$role] ?? []);
    }
    $matrixAllows = (bool) array_intersect($visibleToViewer, $subjectRoles);

    // Every account has "authenticated", so it would make any two
    // logged-in users share a role.
    $meaningfulRoles = static fn (array $roles): array => array_diff($roles, [RoleInterface::AUTHENTICATED_ID]);
    $sharedRoleAllows = !empty($this->configuration['shared_role_fallback'])
      && array_intersect($meaningfulRoles($viewerRoles), $meaningfulRoles($subjectRoles));

    return AccessResult::allowedIf($matrixAllows || $sharedRoleAllows)
      ->addCacheableDependency($cacheability);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSubject(): ?string {
    // A user fact needs a real account (requires_account), never a default.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFieldDefinitions(): array {
    // The user field is core AimFact's own.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSettings(): array {
    return [
      'requires_account' => TRUE,
      'shared_role_fallback' => TRUE,
      'role_visibility' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettingsForm(array $form, FormStateInterface $form_state, array $settings): array {
    $settings += $this->defaultSettings();
    $form['requires_account'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Requires a real user account'),
      '#description' => $this->t('A fact of this scope must reference a real account (ADR-0007).'),
      '#default_value' => $settings['requires_account'],
    ];
    $form['shared_role_fallback'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also allow viewing when the viewer and the subject share a role'),
      '#description' => $this->t('On top of the grid below. "authenticated" does not count as shared, since every logged-in user has it: check its cell below to let any logged-in user see the others\' facts.'),
      '#default_value' => $settings['shared_role_fallback'],
    ];

    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $form['role_visibility'] = [
      '#type' => 'table',
      '#caption' => $this->t('Which viewer roles may see facts about accounts holding which roles, beyond the "view @scope aim facts" permission.', ['@scope' => 'user']),
      '#header' => array_merge(
        [$this->t('Viewer role')],
        array_map(static fn (RoleInterface $role): string => $role->label(), $roles),
      ),
      '#element_validate' => [[static::class, 'validateMatrix']],
    ];
    foreach ($roles as $viewerId => $viewerRole) {
      $row = ['viewer' => ['#plain_text' => $viewerRole->label()]];
      foreach ($roles as $subjectId => $subjectRole) {
        $row[$subjectId] = [
          '#type' => 'checkbox',
          '#title' => $this->t('@viewer may view facts about @subject', [
            '@viewer' => $viewerRole->label(),
            '@subject' => $subjectRole->label(),
          ]),
          '#title_display' => 'invisible',
          '#default_value' => in_array($subjectId, $settings['role_visibility'][$viewerId] ?? [], TRUE),
        ];
      }
      $form['role_visibility'][$viewerId] = $row;
    }
    return $form;
  }

  /**
   * Stores the grid as viewer role ID => the subject role IDs it may see.
   *
   * @param array $element
   *   The role_visibility table.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateMatrix(array &$element, FormStateInterface $form_state): void {
    $matrix = [];
    foreach ((array) $form_state->getValue($element['#parents']) as $viewerId => $row) {
      $visible = array_keys(array_filter((array) $row));
      if ($visible) {
        $matrix[$viewerId] = array_values(array_map('strval', $visible));
      }
    }
    $form_state->setValueForElement($element, $matrix);
  }

}
