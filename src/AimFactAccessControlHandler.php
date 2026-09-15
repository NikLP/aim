<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for the aim_fact entity type.
 *
 * The default handler only ever checks the flat admin_permission
 * (administer aim memory) for every operation, so the per-scope
 * view/create permissions AimPermissions generates do nothing without
 * this. update/delete are deliberately left on administer aim memory
 * only - not asked to be scoped, see CLAUDE.md.
 */
class AimFactAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation === 'view') {
      return AccessResult::allowedIfHasPermission($account, 'view ' . $entity->bundle() . ' aim facts')
        ->orIf(parent::checkAccess($entity, $operation, $account));
    }
    return parent::checkAccess($entity, $operation, $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'create ' . $entity_bundle . ' aim facts')
      ->orIf(parent::checkCreateAccess($account, $context, $entity_bundle));
  }

}
