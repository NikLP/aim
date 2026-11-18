<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\aim\Service\AimMemoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delete confirmation form for one aim_fact.
 *
 * Core's form plus AimFactDeleteNotice: a pointer to Retire, and the
 * retired facts that point at this one.
 */
final class AimFactDeleteForm extends ContentEntityDeleteForm {

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
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    (new AimFactDeleteNotice($this->memoryManager))->attach($form, [(int) $this->entity->id()]);
    return $form;
  }

}
