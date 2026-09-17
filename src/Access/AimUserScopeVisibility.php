<?php

declare(strict_types=1);

namespace Drupal\aim\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\aim\Entity\AimFact;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/**
 * Role-to-role visibility for scope=user aim_fact entities.
 *
 * Narrower than granting the flat "view user aim facts" permission (which
 * already means, and keeps meaning, "see every user-scope fact" - the
 * escape hatch, not something this class touches): a viewer role can
 * instead be allowed to see only subjects holding specific other roles, via
 * the aim.settings:user_scope_role_visibility matrix
 * (AimUserScopeAccessForm). checkViewAccess() only ever returns allowed or
 * neutral, never forbidden, so AimFactAccessControlHandler can ->orIf() it
 * against that flat permission without this class being able to override a
 * grant it knows nothing about.
 *
 * See CLAUDE.md's "Per-scope access control" entry under "Ideas raised, not
 * designed" for the design discussion this implements.
 */
class AimUserScopeVisibility {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Checks whether $viewer may see $fact through the role visibility matrix.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   A scope=user aim_fact.
   * @param \Drupal\Core\Session\AccountInterface $viewer
   *   The account requesting view access.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed if the matrix (or its shared-role fallback) grants access,
   *   neutral otherwise. Never forbidden.
   */
  public function checkViewAccess(AimFact $fact, AccountInterface $viewer): AccessResultInterface {
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['user.roles'])
      ->addCacheableDependency($fact);

    $subject = $fact->get('subject_uid')->entity;
    if (!$subject instanceof UserInterface) {
      return AccessResult::neutral()->addCacheableDependency($cacheability);
    }

    $config = $this->configFactory->get('aim.settings');
    $cacheability->addCacheableDependency($subject)->addCacheableDependency($config);

    $subjectRoles = $subject->getRoles();
    $viewerRoles = $viewer->getRoles();

    $visibleToViewer = [];
    $matrix = $config->get('user_scope_role_visibility') ?? [];
    foreach ($viewerRoles as $role) {
      $visibleToViewer = array_merge($visibleToViewer, $matrix[$role] ?? []);
    }

    $matrixAllows = (bool) array_intersect($visibleToViewer, $subjectRoles);

    // Every authenticated account carries the "authenticated" role, so an
    // unfiltered intersection would make the fallback true for any two
    // logged-in users regardless of their real roles - excluded here so
    // "shared role" means a real, meaningfully assigned one.
    $meaningfulRoles = static fn (array $roles): array => array_diff($roles, [RoleInterface::AUTHENTICATED_ID]);
    $sharedRoleAllows = ($config->get('user_scope_shared_role_fallback') ?? TRUE)
      && array_intersect($meaningfulRoles($viewerRoles), $meaningfulRoles($subjectRoles));

    return AccessResult::allowedIf($matrixAllows || $sharedRoleAllows)
      ->addCacheableDependency($cacheability);
  }

}
