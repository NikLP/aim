<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A role picker for a fact's subject, used by the role scope.
 *
 * Set by AimScopeRole::alterFormDisplay(); offered for aim_fact's subject
 * field only. Stores the role's machine name, as the subject always has.
 */
#[FieldWidget(
  id: 'aim_role_select',
  label: new TranslatableMarkup('Role select'),
  field_types: ['string'],
)]
class AimRoleSelectWidget extends WidgetBase {

  /**
   * The entity type manager, for the site's roles.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $widget = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $widget->entityTypeManager = $container->get('entity_type.manager');
    return $widget;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    return $field_definition->getTargetEntityTypeId() === 'aim_fact' && $field_definition->getName() === 'subject';
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $options = array_map(static fn (RoleInterface $role): string => (string) $role->label(), $roles);
    $current = $items[$delta]->value ?? NULL;
    // A subject saved before the role existed, or since deleted, stays
    // visible rather than silently changing to the first option.
    if ($current !== NULL && $current !== '' && !isset($options[$current])) {
      $options[$current] = $this->t('@role (not a role on this site)', ['@role' => $current]);
    }

    // Ahead of $element, so the role wording replaces the field's own
    // title and description (written for every scope's subject).
    $element['value'] = [
      '#type' => 'select',
      '#title' => $this->t('Role'),
      '#description' => $this->t('The role this guidance is for: everyone holding it sees it in their brief.'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select a role -'),
      '#default_value' => $current,
      '#required' => TRUE,
    ] + $element;
    return $element;
  }

}
