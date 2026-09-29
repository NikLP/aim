<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\aim\Attribute\AimScopeType;
use Drupal\aim\Plugin\AimScopeType\AimScopeTypeInterface;

/**
 * Manages discovery and instantiation of aim scope type plugins.
 *
 * Plugins live in src/Plugin/AimScopeType of any enabled module and are
 * discovered via the #[AimScopeType] attribute. Mirrors the shape of
 * Annotations' TargetPluginManager (plugin.manager.annotations_target);
 * unlike that manager, aim has no per-entity-type multiplicity to cover,
 * so there is no deriver and no generic fallback plugin - see
 * getTypePlugin().
 *
 * Since ADR-0028 piece 3, a plugin's own ID (by convention still
 * matching the first aim_scope instance that used it, e.g. "user") is
 * not the same thing as which aim_scope config entity is asking for it -
 * an aim_scope's `plugin` property says which plugin instance to use,
 * so two differently-configured scopes (a "project" and a "document"
 * entity-scope, say) can share one type's code. getTypePlugin() takes
 * a scope ID and resolves the plugin indirectly through that property,
 * rather than assuming they are the same string.
 */
class AimScopeTypePluginManager extends DefaultPluginManager implements AimScopeTypePluginManagerInterface {

  /**
   * Constructs the scope type plugin manager.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used by getTypePlugin() to load the
   *   aim_scope instance and read its `plugin` property.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler, protected EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct('Plugin/AimScopeType', $namespaces, $module_handler, AimScopeTypeInterface::class, AimScopeType::class);
    $this->alterInfo('aim_scope_type_info');
    $this->setCacheBackend($cache_backend, 'aim_scope_type_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function getTypePlugin(string $scopeId): ?AimScopeTypeInterface {
    /** @var \Drupal\aim\Entity\AimScope|null $scope */
    $scope = $this->entityTypeManager->getStorage('aim_scope')->load($scopeId);
    $pluginId = $scope?->get('plugin');

    if (empty($pluginId) || !$this->hasDefinition($pluginId)) {
      // No plugin configured for this scope (e.g. role/site's config-only
      // shape - ADR-0001's "a fifth scope needs zero PHP" promise, kept
      // by leaving `plugin` unset rather than pointing every plain scope
      // at a no-op placeholder plugin that would add ceremony without
      // adding behavior), or an unknown/uninstalled plugin ID.
      return NULL;
    }

    /** @var \Drupal\aim\Plugin\AimScopeType\AimScopeTypeInterface $plugin */
    $plugin = $this->createInstance($pluginId);
    return $plugin;
  }

}
