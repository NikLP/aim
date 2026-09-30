<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\aim\Entity\AimFact;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access control handler for the aim_fact entity type.
 *
 * The default handler only ever checks the flat admin_permission
 * (administer aim memory) for every operation, so the per-scope
 * view/create permissions AimPermissions generates do nothing without
 * this. update/delete are deliberately left on administer aim memory
 * only - not asked to be scoped, see CLAUDE.md.
 *
 * The custom "trust" operation (trust/untrust bulk actions) is allowed by
 * "trust {scope} aim facts" or the admin permission.
 *
 * view additionally ORs in whatever AimScopeTypePluginManager returns for
 * the fact's own bundle (ADR-0025) - a scope with no registered plugin
 * (every bundle but user today) falls through to the flat permission
 * alone, unchanged from before this plugin type existed.
 */
class AimFactAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  public function __construct(
    EntityTypeInterface $entity_type,
    protected AimScopeTypePluginManagerInterface $scopeAccessManager,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static($entity_type, $container->get('plugin.manager.aim_scope_type'));
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
        ->orIf(AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission()));
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
