<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\aim\Attribute\AimScopeAccess;
use Drupal\aim\Plugin\AimScopeAccess\AimScopeAccessInterface;

/**
 * Manages discovery and instantiation of aim scope access plugins.
 *
 * Plugins live in src/Plugin/AimScopeAccess of any enabled module and are
 * discovered via the #[AimScopeAccess] attribute, keyed by the aim_scope ID
 * they handle. Mirrors the shape of Annotations' TargetPluginManager
 * (plugin.manager.annotations_target); unlike that manager, aim has no
 * per-entity-type multiplicity to cover, so there is no deriver and no
 * generic fallback plugin - see getAccessPlugin().
 */
class AimScopeAccessPluginManager extends DefaultPluginManager implements AimScopeAccessPluginManagerInterface {

  /**
   * Constructs the scope access plugin manager.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/AimScopeAccess', $namespaces, $module_handler, AimScopeAccessInterface::class, AimScopeAccess::class);
    $this->alterInfo('aim_scope_access_info');
    $this->setCacheBackend($cache_backend, 'aim_scope_access_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessPlugin(string $scopeId): ?AimScopeAccessInterface {
    if (!$this->hasDefinition($scopeId)) {
      return NULL;
    }

    /** @var \Drupal\aim\Plugin\AimScopeAccess\AimScopeAccessInterface $plugin */
    $plugin = $this->createInstance($scopeId);
    return $plugin;
  }

}
