<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\aim\Plugin\AimScopeType\AimScopeTypeInterface;

/**
 * Interface for the aim scope access plugin manager.
 */
interface AimScopeTypePluginManagerInterface extends PluginManagerInterface {

  /**
   * Returns the dedicated type plugin for one scope instance, if any.
   *
   * Resolves indirectly through the aim_scope config entity's own
   * `plugin` property (ADR-0028 piece 3), not by assuming the scope ID
   * and the plugin ID are the same string - a site can point two
   * differently-configured scope instances (e.g. a "project" and a
   * "document" entity-scope) at the same plugin. Unlike Annotations'
   * TargetPluginManager::getPlugins(), there is no generic/derived
   * fallback plugin instance - a scope with no `plugin` set (role/site's
   * config-only shape) simply has no dedicated logic, and the caller
   * (AimFactAccessControlHandler) already reproduces today's behavior
   * (the flat permission alone) whenever this returns NULL.
   *
   * @param string $scopeId
   *   An aim_scope config entity ID (an aim_fact bundle).
   *
   * @return \Drupal\aim\Plugin\AimScopeType\AimScopeTypeInterface|null
   *   The plugin $scopeId's own `plugin` property points to, or NULL if
   *   it has none set, or the scope itself does not exist.
   */
  public function getTypePlugin(string $scopeId): ?AimScopeTypeInterface;

}
