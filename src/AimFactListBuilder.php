<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * List builder for aim_fact.
 *
 * Only used for its getOperations() (the Views "Operations links" field on
 * views.view.aim_facts reads through this), not for a collection page - see
 * CLAUDE.md, no collection link is defined for aim_fact.
 */
class AimFactListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * Core's own default operations only cover edit/delete. aim_fact has a
   * separate read-only canonical route (unlike e.g. media, where canonical
   * and edit-form are the same page), so a "View" operation is added ahead
   * of them. Declares $cacheability directly (2026-09-18, CLAUDE.md's
   * "Code review" deferred-bugs list, item 10 - phpstan-drupal's
   * drupal.entityListBuilderMissingCacheabilityParameter,
   * https://www.drupal.org/node/3533080) rather than reading it off
   * func_get_args() - the parent method's own signature already declares
   * it as an optional parameter in this Drupal version, so the
   * forward-compat workaround was no longer needed.
   */
  protected function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL) {
    $cacheability ??= new CacheableMetadata();

    $operations = parent::getDefaultOperations($entity, $cacheability);

    $view_access = $entity->access('view', return_as_object: TRUE);
    $cacheability->addCacheableDependency($view_access);
    if ($view_access->isAllowed() && $entity->hasLinkTemplate('canonical')) {
      $operations = [
        'view' => [
          'title' => $this->t('View'),
          'weight' => 0,
          'url' => $this->ensureDestination($entity->toUrl('canonical')),
        ],
      ] + $operations;
    }

    return $operations;
  }

}
