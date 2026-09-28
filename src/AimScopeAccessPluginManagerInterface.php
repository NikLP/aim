<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\aim\Plugin\AimScopeAccess\AimScopeAccessInterface;

/**
 * Interface for the aim scope access plugin manager.
 */
interface AimScopeAccessPluginManagerInterface extends PluginManagerInterface {

  /**
   * Returns the dedicated access plugin for one scope, if any.
   *
   * Unlike Annotations' TargetPluginManager::getPlugins(), there is no
   * generic/derived fallback plugin instance - a scope with nothing
   * registered simply has no dedicated logic, and the caller (
   * AimFactAccessControlHandler) already reproduces today's behavior (the
   * flat permission alone) whenever this returns NULL.
   *
   * @param string $scopeId
   *   An aim_scope config entity ID (an aim_fact bundle).
   *
   * @return \Drupal\aim\Plugin\AimScopeAccess\AimScopeAccessInterface|null
   *   The plugin registered for $scopeId, or NULL if none is.
   */
  public function getAccessPlugin(string $scopeId): ?AimScopeAccessInterface;

}
