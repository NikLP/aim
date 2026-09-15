<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides dynamic per-scope permissions for aim_fact bundles.
 *
 * Called as a permission_callbacks entry in aim.permissions.yml. aim_fact's
 * four scopes (user/role/site/case) are code-defined bundles from
 * hook_entity_bundle_info(), not config entities like node types, so
 * \Drupal\Core\Entity\BundlePermissionHandlerTrait can't be reused as-is -
 * it hard-requires a real config entity per bundle to record as a
 * dependency on the generated permission. There is nothing to clean up
 * here instead: these bundles cannot be deleted via the UI, so a stale
 * permission string is not a real risk the way it is for node types.
 *
 * Generates two permissions per scope:
 *
 *   view {scope} aim facts
 *   create {scope} aim facts
 */
class AimPermissions implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  /**
   * Returns per-scope view/create permissions.
   *
   * @return array
   *   Permission definitions keyed by machine name.
   *
   * @see \Drupal\user\PermissionHandlerInterface::getPermissions()
   */
  public function permissions(): array {
    $permissions = [];
    foreach ($this->entityTypeBundleInfo->getBundleInfo('aim_fact') as $scope => $info) {
      $params = ['%label' => $info['label']];
      $permissions["view $scope aim facts"] = [
        'title' => $this->t('%label: view AIM facts', $params),
      ];
      $permissions["create $scope aim facts"] = [
        'title' => $this->t('%label: create AIM facts', $params),
      ];
    }
    return $permissions;
  }

}
