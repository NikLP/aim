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
 * reshape it by hand. See aim_scope_user's AimScopeUser (an AimScopeType
 * plugin, ADR-0025) for how this is read back at access-check time and why
 * this exists alongside the flat "view user aim facts" permission rather
 * than instead of it. Renamed from AimUserScopeAccessForm to match the
 * AimScope[Name] convention the plugin itself was renamed to (Nik's
 * naming call, ADR-0026). Stays in core aim rather than moving into
 * aim_scope_user alongside the plugin - unlike the plugin class, this
 * form isn't discovered generically, so moving it would need its own
 * route/permission/menu-link split with no corresponding benefit yet;
 * not revisited unless a second scope needs its own settings form.
 */
final class AimScopeUserAccessForm extends ConfigFormBase {

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
    return 'aim_scope_user_access_form';
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
      '#description' => $this->t('Applies on top of the matrix below, not instead of it. With nothing checked below and this on, visibility is exactly the previous default: any shared role is enough. While this is on, same-role cells below (editor viewing editor, for example) are greyed out because the fallback already grants them. The "authenticated" cell is not greyed out: the fallback deliberately ignores that role, since every logged-in user has it, so checking that cell is the only way to let any logged-in user see another's facts.'),
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
        // Same-role diagonal, other than "authenticated": already granted by
        // the shared-role fallback whenever it's on, so checking it here
        // changes nothing - see AimScopeUser::checkViewAccess()'s
        // $meaningfulRoles exclusion for why "authenticated" itself doesn't
        // get this treatment (the fallback deliberately ignores that shared
        // role, since every two logged-in accounts have it).
        if ($subjectId === $viewerId && $viewerId !== RoleInterface::AUTHENTICATED_ID) {
          $row[$subjectId]['#states'] = [
            'disabled' => [
              ':input[name="user_scope_shared_role_fallback"]' => ['checked' => TRUE],
            ],
          ];
        }
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
    $oldMatrix = $this->config('aim.settings')->get('user_scope_role_visibility') ?? [];
    $fallback = (bool) $form_state->getValue('user_scope_shared_role_fallback');

    $matrix = [];
    foreach ($roleIds as $viewerId) {
      $visible = array_values(array_filter(
        $roleIds,
        function (string $subjectId) use ($viewerId, $form_state, $fallback, $oldMatrix): bool {
          // The diagonal checkbox (other than "authenticated") is disabled
          // client-side while the fallback is on, so a disabled browser
          // won't submit it at all - keep the stored value instead of
          // reading the missing field as unchecked and erasing it.
          if ($fallback && $subjectId === $viewerId && $viewerId !== RoleInterface::AUTHENTICATED_ID) {
            return in_array($subjectId, $oldMatrix[$viewerId] ?? [], TRUE);
          }
          return (bool) $form_state->getValue(['matrix', $viewerId, $subjectId]);
        },
      ));
      if ($visible) {
        $matrix[$viewerId] = $visible;
      }
    }

    $this->config('aim.settings')
      ->set('user_scope_role_visibility', $matrix)
      ->set('user_scope_shared_role_fallback', $fallback)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
