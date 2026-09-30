<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Entity\AimFact;

/**
 * Marks facts trusted so recall() returns them.
 */
#[Action(
  id: 'aim_fact_trust',
  action_label: new TranslatableMarkup('Trust'),
  type: 'aim_fact',
)]
class TrustAction extends AimFactActionBase {

  /**
   * {@inheritdoc}
   */
  protected function apply(AimFact $fact): bool {
    if ($fact->get('trusted')->value) {
      return FALSE;
    }
    $fact->set('trusted', TRUE);
    return TRUE;
  }

}
