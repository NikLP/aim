<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\aim\Entity\AimFact;

/**
 * Base class for bulk actions that change one field on an aim_fact.
 *
 * Saves through the entity API so search_api's index tracking (or
 * index_directly) picks the change up, which is what pulls a retired fact
 * out of the vector index via aim_exclude_retired (ADR-0022).
 */
abstract class AimFactActionBase extends ActionBase {

  /**
   * Applies this action's change to the fact.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact to change.
   *
   * @return bool
   *   TRUE if the fact changed and needs saving.
   */
  abstract protected function apply(AimFact $fact): bool;

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof AimFact && $this->apply($entity)) {
      $entity->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $object->access('update', $account, TRUE);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
