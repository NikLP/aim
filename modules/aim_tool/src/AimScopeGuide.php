<?php

declare(strict_types=1);

namespace Drupal\aim_tool;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\aim\Service\AimMemoryManager;

/**
 * Says what each enabled scope is for, for a model choosing one.
 *
 * Reads each scope's own description, in the scope list's order (ADR-0063).
 * The tools' scope inputs (AimToolHooks::toolInfoAlter()), aim_brief's
 * grouping and the MCP server instructions (aim_tool_oauth) all read it.
 */
final class AimScopeGuide {

  /**
   * Constructs the scope guide.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The memory manager, for the enabled scopes in order.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for each scope's description.
   */
  public function __construct(
    private readonly AimMemoryManager $memoryManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The enabled scopes as one line, e.g. "site, role, user".
   *
   * @return string
   *   The scope IDs, comma-separated.
   */
  public function scopeList(): string {
    return implode(', ', $this->scopeIds());
  }

  /**
   * The enabled scopes, one line each, with what they are for.
   *
   * @return string
   *   Lines of the form "- site: ...", newline-separated.
   */
  public function guide(): string {
    $lines = [];
    /** @var \Drupal\aim\Entity\AimScope $scope */
    foreach ($this->entityTypeManager->getStorage('aim_scope')->loadMultiple($this->scopeIds()) as $id => $scope) {
      $lines[] = '- ' . $id . ': ' . ($scope->getDescription() !== '' ? $scope->getDescription() : $scope->label() . '.');
    }
    return implode("\n", $lines);
  }

  /**
   * The enabled scope IDs, in the order the scope list sets.
   *
   * @return string[]
   *   Scope IDs, lightest weight first.
   */
  public function scopeIds(): array {
    return $this->memoryManager->allowedScopes();
  }

}
