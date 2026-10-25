<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Plugin\CachedDiscoveryClearerInterface;
use Drupal\aim_tool\AimScopeGuide;
use Drupal\tool\Tool\ToolDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations for the aim_tool module.
 */
class AimToolHooks {

  /**
   * Constructs the hook implementations.
   *
   * @param \Drupal\aim_tool\AimScopeGuide $scopeGuide
   *   The scope guide.
   * @param \Drupal\Core\Plugin\CachedDiscoveryClearerInterface $pluginCacheClearer
   *   The plugin cache clearer.
   */
  public function __construct(
    private readonly AimScopeGuide $scopeGuide,
    #[Autowire(service: 'plugin.cache_clearer')]
    private readonly CachedDiscoveryClearerInterface $pluginCacheClearer,
  ) {}

  /**
   * Implements hook_tool_info_alter().
   *
   * Writes the installed scopes and what each is for into the scope inputs,
   * so the schema a caller sees matches the site rather than a hardcoded
   * list. Recall gets the one-line list, remember the full guide, since
   * choosing a scope matters when writing.
   *
   * @param array $definitions
   *   The tool definitions, keyed by tool ID.
   */
  #[Hook('tool_info_alter')]
  public function toolInfoAlter(array &$definitions): void {
    if (($definitions['aim_recall'] ?? NULL) instanceof ToolDefinition) {
      $definitions['aim_recall']->getInputDefinition('scope')->setDescription(
        'Optional. Leave empty to search every scope you can see, or restrict to one: ' . $this->scopeGuide->scopeList() . ". Search by topic; you don't need a case ID or role name to find their facts.",
      );
    }
    if (($definitions['aim_remember'] ?? NULL) instanceof ToolDefinition) {
      $definitions['aim_remember']->getInputDefinition('scope')->setDescription(
        "Required when passing text (inside facts, each entry has its own). One of the scopes below; ask if none clearly fits, or if the role or case is unclear.\n" . $this->scopeGuide->guide(),
      );
    }
  }

  /**
   * Implements hook_aim_scope_insert(), _update() and _delete().
   *
   * The scope descriptions above are built into cached plugin definitions,
   * so a scope added outside a module install (the scope admin form) needs
   * the caches cleared to show up.
   */
  #[Hook('aim_scope_insert')]
  #[Hook('aim_scope_update')]
  #[Hook('aim_scope_delete')]
  public function scopeChanged(): void {
    $this->pluginCacheClearer->clearCachedDefinitions();
  }

}
