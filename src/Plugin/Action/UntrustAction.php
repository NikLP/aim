<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Entity\AimFact;

/**
 * Marks facts untrusted so recall() stops returning them.
 */
#[Action(
  id: 'aim_fact_untrust',
  action_label: new TranslatableMarkup('Untrust'),
  type: 'aim_fact',
)]
class UntrustAction extends AimFactActionBase {

  /**
   * {@inheritdoc}
   */
  protected function accessOperation(): string {
    return 'trust';
  }

  /**
   * {@inheritdoc}
   */
  protected function apply(AimFact $fact): bool {
    if (!$fact->get('trusted')->value) {
      return FALSE;
    }
    $fact->set('trusted', FALSE);
    return TRUE;
  }

}
