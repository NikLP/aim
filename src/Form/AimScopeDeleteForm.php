<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Delete confirmation form for the aim_scope config entity.
 *
 * Refuses the delete outright if any aim_fact entities of this scope
 * still exist, the same precedent core's own NodeTypeDeleteConfirm/
 * MediaTypeDeleteConfirm use for their own bundles - without this, the
 * plain EntityDeleteForm this replaced would delete the aim_scope config
 * entity (and, via its config-dependency tracking, the "view {scope} aim
 * facts"/"create {scope} aim facts" permission grants
 * AimPermissions::buildPermissions() generated for it) while leaving every
 * aim_fact of that scope still in the database - an orphaned bundle no
 * form/route/permission can address any more.
 */
final class AimScopeDeleteForm extends EntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // $this->entityTypeManager is EntityForm's own injected property (this
    // class's real parent chain is EntityDeleteForm -> EntityConfirmFormBase
    // -> EntityForm), not a \Drupal:: call.
    $count = $this->entityTypeManager->getStorage('aim_fact')->getQuery()
      ->accessCheck(FALSE)
      ->condition('scope', $this->entity->id())
      ->count()
      ->execute();

    if ($count) {
      $form['#title'] = $this->getQuestion();
      $form['description'] = [
        '#markup' => '<p>' . $this->formatPlural(
          $count,
          '%label is used by 1 AIM fact. You may not remove this scope until every %label fact has been (re)moved from it.',
          '%label is used by @count AIM facts. You may not remove this scope until every %label fact has been (re)moved from it.',
          ['%label' => $this->entity->label()]
        ) . '</p>',
      ];
      return $form;
    }

    return parent::buildForm($form, $form_state);
  }

}
