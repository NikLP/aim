<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;

/**
 * Registers ai's VDB provider manager with the plugin cache clearer.
 *
 * Core's PluginManagerPass only tags services named plugin.manager.*, and
 * drupal/ai names it ai.vdb_provider without the tag, so a module install
 * never clears its cached definitions. Without this, a provider enabled
 * before aim keeps definitions cached without AimHooks::vdbProviderInfoAlter()
 * (aim's own config/install index save then runs the stock provider), and
 * a provider enabled before ai_search keeps an empty list. Delete once
 * drupal/ai tags the service (BACKLOG.md, AimMariaDBProvider upstream item).
 */
class AimServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('ai.vdb_provider')) {
      $definition = $container->getDefinition('ai.vdb_provider');
      if (!$definition->hasTag('plugin_manager_cache_clear')) {
        $definition->addTag('plugin_manager_cache_clear');
      }
    }
  }

}
