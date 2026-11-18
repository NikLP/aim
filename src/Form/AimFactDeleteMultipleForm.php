<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\aim\Service\AimMemoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk delete confirmation form for aim_fact (the "Delete fact" action).
 *
 * Core's form plus AimFactDeleteNotice, for every selected fact.
 */
final class AimFactDeleteMultipleForm extends DeleteMultipleForm {

  /**
   * The aim memory manager, for the notice's list of retired facts.
   */
  protected AimMemoryManager $memoryManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->memoryManager = $container->get(AimMemoryManager::class);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $entity_type_id = NULL) {
    $form = parent::buildForm($form, $form_state, $entity_type_id);
    // An empty selection redirects back to the list.
    if (is_array($form)) {
      (new AimFactDeleteNotice($this->memoryManager))->attach($form, array_map('intval', array_keys($this->selection)));
    }
    return $form;
  }

}
