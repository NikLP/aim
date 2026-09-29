<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\aim\AimScopeTypePluginManagerInterface;
use Drupal\aim\Entity\AimScope;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add/edit form for the aim_scope config entity.
 *
 * Id/label, plus (ADR-0028 piece 2) a `plugin` type selector and the
 * chosen type's own inline settings sub-form - see AimScope's docblock
 * for the config entity shape this saves. Extends core's
 * BundleEntityFormBase, the base class every bundle config entity form in
 * core uses (NodeTypeForm, MediaTypeForm): it is a plain EntityForm plus
 * protectBundleIdElement(), which locks the machine name once the bundle
 * exists. It has nothing to do with Field UI - that is gated solely on
 * aim_fact's (deliberately absent) field_ui_base_route. This exists to let
 * entity.aim_fact.add_page resolve at all: core's
 * \Drupal\Core\Entity\Controller\EntityController::addPage() unconditionally
 * builds an "Add a new @entity_type" fallback link for aim_fact's
 * bundle_entity_type, even when bundles already exist, so aim_scope needs a
 * real add-form route regardless of whether this form is ever used to add a
 * fifth scope in practice.
 */
final class AimScopeForm extends BundleEntityFormBase {

  /**
   * The wrapper element ID the plugin selector's AJAX callback rebuilds.
   */
  protected const SETTINGS_WRAPPER_ID = 'aim-scope-settings-wrapper';

  public function __construct(
    protected AimScopeTypePluginManagerInterface $scopeTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Neither EntityForm nor BundleEntityFormBase declare their own
   * create() or constructor - the entityTypeManager/moduleHandler this
   * form otherwise needs come from EntityForm's own setEntityTypeManager()/
   * setModuleHandler(), called by the entity form builder after create()
   * returns, not through this constructor - so building the instance
   * directly here, instead of chaining through parent::create(), is safe
   * and matches AimFactAccessControlHandler::createInstance()'s same
   * shape elsewhere in this module.
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('plugin.manager.aim_scope_type'));
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\aim\Entity\AimScope $scope */
    $scope = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $scope->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $scope->id(),
      '#machine_name' => [
        'exists' => [AimScope::class, 'load'],
      ],
    ];

    $options = ['' => $this->t('- None (config-only scope, no dedicated behavior) -')];
    foreach ($this->scopeTypeManager->getDefinitions() as $pluginId => $definition) {
      $options[$pluginId] = $definition['label'] ?? $pluginId;
    }

    $form['plugin'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#description' => $this->t('What plugin, if any, supplies this scope its view-access logic, default subject, base fields, and settings. Changing this after facts already exist in this scope does not migrate their data to match the newly selected type.'),
      '#options' => $options,
      '#default_value' => $scope->get('plugin') ?? '',
      '#ajax' => [
        'callback' => '::updateSettingsForm',
        'wrapper' => self::SETTINGS_WRAPPER_ID,
      ],
    ];

    $form['settings'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#id' => self::SETTINGS_WRAPPER_ID,
    ];

    $selected = $form_state->getValue('plugin') ?? $scope->get('plugin') ?? '';
    if ($selected !== '' && $this->scopeTypeManager->hasDefinition($selected)) {
      $plugin = $this->scopeTypeManager->createInstance($selected);
      $current_settings = $selected === ($scope->get('plugin') ?? '') ? ($scope->get('settings') ?? []) : $plugin->defaultSettings();
      $form['settings'] = $plugin->buildSettingsForm($form['settings'], $form_state, $current_settings);
    }

    return $this->protectBundleIdElement($form);
  }

  /**
   * AJAX callback: returns the rebuilt settings sub-form.
   *
   * @param array $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The 'settings' sub-form element.
   */
  public function updateSettingsForm(array $form, FormStateInterface $form_state): array {
    return $form['settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\aim\Entity\AimScope $scope */
    $scope = $this->entity;
    $plugin_id = $form_state->getValue('plugin');
    $scope->set('plugin', $plugin_id !== '' ? $plugin_id : NULL);
    $scope->set('settings', $plugin_id !== '' ? ($form_state->getValue('settings') ?? []) : []);

    $result = parent::save($form, $form_state);

    $message_args = ['%label' => $this->entity->label()];
    $message = $result === SAVED_NEW
      ? $this->t('Created the %label scope.', $message_args)
      : $this->t('Updated the %label scope.', $message_args);
    $this->messenger()->addStatus($message);

    $form_state->setRedirectUrl($this->entity->toUrl('collection'));

    return $result;
  }

}
