<?php

declare(strict_types=1);

namespace Drupal\aim\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an AimScopeType plugin attribute.
 *
 * A scope access plugin supplies extra view-access logic for one aim_scope
 * bundle, ORed against that scope's flat "view {scope} aim facts"
 * permission by AimFactAccessControlHandler. Any module can provide one by
 * placing a class in src/Plugin/AimScopeType with this attribute; it is
 * discovered automatically. A scope with no registered plugin falls
 * through to the flat permission alone - see
 * \Drupal\aim\AimScopeTypePluginManager::getTypePlugin().
 *
 * @see \Drupal\aim\AimScopeTypePluginManager
 * @see \Drupal\aim\Plugin\AimScopeType\AimScopeTypeInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AimScopeType extends Plugin {

  /**
   * Constructs an AimScopeType attribute.
   *
   * @param string $id
   *   The plugin ID. By convention this is the aim_scope ID the plugin
   *   handles (e.g. "user").
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The human-readable label for this plugin.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
  ) {}

}
