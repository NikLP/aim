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
 * Label, machine name, the "which scope when" description, Enabled, and a
 * `plugin` type selector with the chosen type's own settings inline
 * (ADR-0028 piece 2), rebuilt by AJAX. Weight is set by dragging rows on
 * the scope list. BundleEntityFormBase is core's bundle form base
 * (NodeTypeForm): protectBundleIdElement() locks the machine name once
 * facts can exist.
 */
final class AimScopeForm extends BundleEntityFormBase {

  /**
   * The wrapper element ID the plugin selector's AJAX callback rebuilds.
   */
  protected const SETTINGS_WRAPPER_ID = 'aim-scope-settings-wrapper';

  /**
   * Constructs the form.
   *
   * @param \Drupal\aim\AimScopeTypePluginManagerInterface $scopeTypeManager
   *   The scope type plugin manager, for the type selector.
   */
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

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Which scope when'),
      '#description' => $this->t('What this scope is for and what its subject is. Models read this to choose a scope (the tools and the MCP server instructions), so an edit changes how they save facts.'),
      '#default_value' => $scope->getDescription(),
      '#rows' => 3,
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('A disabled scope takes no new facts and is not offered to models. A scope with facts cannot be disabled.'),
      '#default_value' => $scope->isNew() || $scope->status(),
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    /** @var \Drupal\aim\Entity\AimScope $scope */
    $scope = $this->entity;
    if ($scope->isNew() || $form_state->getValue('status')) {
      return;
    }
    $count = (int) $this->entityTypeManager->getStorage('aim_fact')->getQuery()
      ->accessCheck(FALSE)
      ->condition('scope', $scope->id())
      ->count()
      ->execute();
    if ($count) {
      $form_state->setErrorByName('status', $this->t('Cannot disable %label: @count facts use it.', [
        '%label' => $scope->label(),
        '@count' => $count,
      ]));
    }
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
    $scope->setStatus((bool) $form_state->getValue('status'));

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
