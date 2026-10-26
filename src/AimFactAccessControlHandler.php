<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\aim\Entity\AimFact;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access control handler for the aim_fact entity type.
 *
 * The default handler only ever checks the flat admin_permission
 * (administer aim) for every operation, so the per-scope
 * view/create permissions AimPermissions generates do nothing without
 * this. update/delete are deliberately left on administer aim
 * only - not asked to be scoped, see CLAUDE.md.
 *
 * The custom "trust" operation (trust/untrust bulk actions) is allowed by
 * "trust {scope} aim facts" or the admin permission.
 *
 * view additionally ORs in the fact's scope plugin's checkViewAccess()
 * (ADR-0025); a scope with no plugin has the flat permission alone. create
 * is forbidden for a disabled scope.
 */
class AimFactAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * Constructs the access control handler.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param \Drupal\aim\AimScopeTypePluginManagerInterface $scopeAccessManager
   *   The scope type plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for a scope's status.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    protected AimScopeTypePluginManagerInterface $scopeAccessManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static($entity_type, $container->get('plugin.manager.aim_scope_type'), $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation === 'view') {
      $access = AccessResult::allowedIfHasPermission($account, 'view ' . $entity->bundle() . ' aim facts');
      if ($entity instanceof AimFact) {
        $plugin = $this->scopeAccessManager->getTypePlugin($entity->bundle());
        if ($plugin) {
          $access = $access->orIf($plugin->checkViewAccess($entity, $account));
        }
      }
      return $access->orIf(parent::checkAccess($entity, $operation, $account));
    }
    if ($operation === 'trust') {
      // Not a core operation: only the trust/untrust bulk actions ask for
      // it, so a per-scope reviewer can flip the trusted flag without
      // gaining update access to the fact's text.
      return AccessResult::allowedIfHasPermission($account, 'trust ' . $entity->bundle() . ' aim facts')
        ->orIf(AccessResult::allowedIfHasPermission($account, 'review aim facts'))
        ->orIf(AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission()));
    }
    return parent::checkAccess($entity, $operation, $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // A disabled scope takes no new facts, admins included (ADR-0063).
    $scope = $entity_bundle !== NULL ? $this->entityTypeManager->getStorage('aim_scope')->load($entity_bundle) : NULL;
    if ($scope && !$scope->status()) {
      return AccessResult::forbidden('The ' . $entity_bundle . ' scope is disabled.')->addCacheableDependency($scope);
    }
    $access = AccessResult::allowedIfHasPermission($account, 'create ' . $entity_bundle . ' aim facts')
      ->orIf(parent::checkCreateAccess($account, $context, $entity_bundle));
    return $scope ? $access->addCacheableDependency($scope) : $access;
  }

}
