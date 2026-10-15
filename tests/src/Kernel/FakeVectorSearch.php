<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\aim\Entity\AimFact;
use Drupal\search_api\Backend\BackendInterface;
use Drupal\search_api\Query\Condition;
use Drupal\search_api\Query\QueryInterface;

/**
 * A search backend callback standing in for the vector store.
 *
 * Applies the query's equality conditions (scope, subject, user, trusted) to
 * the stored facts and ranks them by a score each test sets per fact, nearest
 * first. It cannot say whether real vectors rank well; that is what the live
 * benchmarks are for.
 */
final class FakeVectorSearch {

  /**
   * Search backend override: filter by the query's conditions, rank by score.
   *
   * @param \Drupal\search_api\Backend\BackendInterface $backend
   *   The test backend.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query being run.
   */
  public static function search(BackendInterface $backend, QueryInterface $query): void {
    $index = $query->getIndex();
    $fieldsHelper = \Drupal::service('search_api.fields_helper');
    $scores = \Drupal::state()->get('aim_recall_test.scores', []);

    $items = [];
    foreach (AimFact::loadMultiple() as $fact) {
      foreach ($query->getConditionGroup()->getConditions() as $condition) {
        if ($condition instanceof Condition && !self::factMatches($fact, $condition)) {
          continue 2;
        }
      }
      // The real backend checks entity access per hit unless the query asks
      // to bypass it (AimMemoryManager::executeSearchQuery()).
      if (!$query->getOption('search_api_bypass_access') && !$fact->access('view')) {
        continue;
      }

      $item = $fieldsHelper->createItemFromObject($index, $fact->getTypedData(), NULL, $index->getDatasource('entity:aim_fact'));
      $item->setScore($scores[$fact->id()] ?? 0.1);
      $items[$item->getId()] = $item;
    }
    uasort($items, fn ($a, $b) => $a->getScore() <=> $b->getScore());

    $limit = $query->getOption('limit');
    if ($limit !== NULL) {
      $items = array_slice($items, (int) $query->getOption('offset', 0), (int) $limit, TRUE);
    }
    $query->getResults()->setResultItems($items)->setResultCount(count($items));
  }

  /**
   * Whether a fact satisfies one equality condition.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact.
   * @param \Drupal\search_api\Query\Condition $condition
   *   The condition.
   *
   * @return bool
   *   TRUE if it matches.
   */
  protected static function factMatches(AimFact $fact, Condition $condition): bool {
    $actual = match ($condition->getField()) {
      'scope' => $fact->bundle(),
      'subject' => (string) $fact->get('subject')->value,
      'user' => (int) $fact->get('user')->target_id,
      'trusted' => (bool) $fact->get('trusted')->value,
    };
    return $actual === $condition->getValue();
  }

}
