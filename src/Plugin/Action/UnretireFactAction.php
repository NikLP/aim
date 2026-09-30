<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Entity\AimFact;

/**
 * Reverses a retirement: clears expires and superseded_by.
 */
#[Action(
  id: 'aim_fact_unretire',
  action_label: new TranslatableMarkup('Un-retire'),
  type: 'aim_fact',
)]
class UnretireFactAction extends AimFactActionBase {

  /**
   * {@inheritdoc}
   */
  protected function apply(AimFact $fact): bool {
    if ($fact->get('expires')->isEmpty() && $fact->get('superseded_by')->isEmpty()) {
      return FALSE;
    }
    $fact->set('expires', NULL);
    $fact->set('superseded_by', NULL);
    return TRUE;
  }

}
