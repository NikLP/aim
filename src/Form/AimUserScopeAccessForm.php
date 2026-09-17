<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures which roles may view another role's scope=user aim_fact data.
 *
 * A checkbox grid, not #config_target elements like AimSettingsForm - the
 * saved shape (viewer role ID => list of visible subject role IDs) is a
 * matrix, not one value per form element, so buildForm()/submitForm()
 * reshape it by hand. See AimUserScopeVisibility for how this is read back
 * at access-check time, and CLAUDE.md's "Per-scope access control" entry
 * for why this exists alongside the flat "view user aim facts" permission
 * rather than instead of it.
 */
final class AimUserScopeAccessForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aim_user_scope_access_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['aim.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('aim.settings');
    $matrix = $config->get('user_scope_role_visibility') ?? [];

    $form['user_scope_shared_role_fallback'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Also allow viewing when the viewer and the subject share at least one role'),
      '#description' => $this->t('Applies on top of the matrix below, not instead of it. With nothing checked below and this on, visibility is exactly the previous default: any shared role is enough.'),
      '#default_value' => $config->get('user_scope_shared_role_fallback') ?? TRUE,
    ];

    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();

    $form['matrix'] = [
      '#type' => 'table',
      '#header' => array_merge(
        [$this->t('Viewer role')],
        array_map(static fn (RoleInterface $role): string => $role->label(), $roles),
      ),
      '#empty' => $this->t('No roles exist yet.'),
    ];
    foreach ($roles as $viewerId => $viewerRole) {
      $row = ['viewer' => ['#plain_text' => $viewerRole->label()]];
      foreach ($roles as $subjectId => $subjectRole) {
        $row[$subjectId] = [
          '#type' => 'checkbox',
          '#title' => $this->t('@viewer may view scope=user facts about @subject', [
            '@viewer' => $viewerRole->label(),
            '@subject' => $subjectRole->label(),
          ]),
          '#title_display' => 'invisible',
          '#default_value' => in_array($subjectId, $matrix[$viewerId] ?? [], TRUE),
        ];
      }
      $form['matrix'][$viewerId] = $row;
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $roleIds = array_keys($this->entityTypeManager->getStorage('user_role')->loadMultiple());

    $matrix = [];
    foreach ($roleIds as $viewerId) {
      $visible = array_values(array_filter(
        $roleIds,
        static fn (string $subjectId): bool => (bool) $form_state->getValue(['matrix', $viewerId, $subjectId]),
      ));
      if ($visible) {
        $matrix[$viewerId] = $visible;
      }
    }

    $this->config('aim.settings')
      ->set('user_scope_role_visibility', $matrix)
      ->set('user_scope_shared_role_fallback', (bool) $form_state->getValue('user_scope_shared_role_fallback'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
