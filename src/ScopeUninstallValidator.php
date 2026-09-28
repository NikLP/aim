<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\aim\Entity\AimScope;

/**
 * Blocks uninstalling a module that provides an in-use aim_scope.
 *
 * AimScopeDeleteForm already refuses to delete an aim_scope config entity
 * through its own confirmation route while aim_fact entities of that
 * bundle exist - but module uninstall removes dependent config entities
 * through ConfigManager::uninstall() directly, a code path that never
 * instantiates that form. Verified live 2026-09-28 (see ADR-0026's "Open
 * questions"): without this validator, `drush pmu`/the admin uninstall UI
 * force-deletes the scope and silently orphans its aim_fact rows.
 *
 * Mirrors core's own field.uninstall_validator (FieldUninstallValidator),
 * which blocks uninstalling a module with active field storage the same
 * way.
 *
 * Deliberately does not fire for the aim_fact entity type's own provider
 * (core aim itself, today) - uninstalling that module drops the aim_fact
 * table wholesale, so nothing survives to be orphaned. Without this
 * guard, uninstalling aim on any site with live facts (this one included
 * - 34 at last count, across all four scopes) would be blocked too,
 * contradicting CLAUDE.md's PoC reinstall workflow ("just change and
 * reinstall (drush pmu/drush en)"). The real risk this validator guards
 * against is a scope surviving its own content's storage - only possible
 * once a scope is provided by a module other than aim_fact's own.
 */
class ScopeUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  public function __construct(
    protected ConfigManagerInterface $configManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate($module) {
    if ($module === $this->entityTypeManager->getDefinition('aim_fact')->getProvider()) {
      return [];
    }

    $reasons = [];
    $dependents = $this->configManager->findConfigEntityDependenciesAsEntities('module', [$module]);
    foreach ($dependents as $entity) {
      if (!$entity instanceof AimScope) {
        continue;
      }

      $count = $this->entityTypeManager->getStorage('aim_fact')->getQuery()
        ->accessCheck(FALSE)
        ->condition('scope', $entity->id())
        ->count()
        ->execute();

      if ($count) {
        $reasons[] = $this->formatPlural(
          $count,
          'The %label AIM scope is used by 1 AIM fact. You may not remove this scope until every %label fact has been (re)moved from it.',
          'The %label AIM scope is used by @count AIM facts. You may not remove this scope until every %label fact has been (re)moved from it.',
          ['%label' => $entity->label()],
        );
      }
    }
    return $reasons;
  }

}
