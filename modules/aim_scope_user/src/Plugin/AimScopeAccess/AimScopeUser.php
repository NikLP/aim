<?php

declare(strict_types=1);

namespace Drupal\aim_scope_user\Plugin\AimScopeAccess;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Attribute\AimScopeAccess;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Plugin\AimScopeAccess\AimScopeAccessInterface;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Role-to-role visibility for scope=user aim_fact entities.
 *
 * The first dedicated AimScopeAccess plugin (ADR-0025), moved out of core
 * aim into this scope's own submodule (ADR-0026 piece 4) - was named
 * AimUserScopeVisibility there; renamed to match the rest of this module's
 * AimScope[Name] convention on the move, the logic itself is unchanged.
 *
 * Narrower than granting the flat "view user aim facts" permission (which
 * already means, and keeps meaning, "see every user-scope fact" - the
 * escape hatch, not something this class touches): a viewer role can
 * instead be allowed to see only subjects holding specific other roles, via
 * the aim.settings:user_scope_role_visibility matrix
 * (AimScopeUserAccessForm, still in core aim - see this module's
 * CLAUDE.md). checkViewAccess() only ever returns allowed or neutral,
 * never forbidden, per AimScopeAccessInterface's contract.
 */
#[AimScopeAccess(
  id: 'user',
  label: new TranslatableMarkup('User scope role visibility'),
)]
class AimScopeUser extends PluginBase implements AimScopeAccessInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkViewAccess(AimFact $fact, AccountInterface $account): AccessResultInterface {
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['user.roles'])
      ->addCacheableDependency($fact);

    $subject = $fact->get('user')->entity;
    if (!$subject instanceof UserInterface) {
      return AccessResult::neutral()->addCacheableDependency($cacheability);
    }

    $config = $this->configFactory->get('aim.settings');
    $cacheability->addCacheableDependency($subject)->addCacheableDependency($config);

    $subjectRoles = $subject->getRoles();
    $viewerRoles = $account->getRoles();

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

  /**
   * {@inheritdoc}
   */
  public function defaultSubject(): ?string {
    // scope=user has no sensible default - it requires a real account,
    // enforced separately by AimMemoryManager::scopeRequiresAccount().
    return NULL;
  }

}
