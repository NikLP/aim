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
 * never clears its cached definitions: a provider installed after the list
 * was first built stays missing ("The "mariadb" plugin does not exist").
 * Delete once drupal/ai tags the service (drupal.org #3547139, BACKLOG.md).
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
