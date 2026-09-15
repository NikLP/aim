<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\aim\Entity\AimScope;

/**
 * Add/edit form for the aim_scope config entity.
 *
 * Deliberately minimal - id and label only, matching AimScope's own
 * config_export shape (see AimScope's docblock). This exists to let
 * entity.aim_fact.add_page resolve at all: core's
 * \Drupal\Core\Entity\Controller\EntityController::addPage() unconditionally
 * builds an "Add a new @entity_type" fallback link for aim_fact's
 * bundle_entity_type, even when bundles already exist, so aim_scope needs a
 * real add-form route regardless of whether this form is ever used to add a
 * fifth scope in practice.
 */
final class AimScopeForm extends EntityForm {

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
      '#disabled' => !$scope->isNew(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
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
