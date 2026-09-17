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
   * of them. Mirrors the parent method's own func_get_args() forward-compat
   * shape ($cacheability is not yet a declared parameter in this Drupal
   * version) rather than declaring it directly.
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $args = func_get_args();
    $cacheability = $args[1] ?? new CacheableMetadata();

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
