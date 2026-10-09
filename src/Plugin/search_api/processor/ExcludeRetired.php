<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\search_api\processor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Entity\AimFact;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Processor\ProcessorPluginBase;

/**
 * Keeps retired (superseded) facts out of the vector index.
 *
 * A fact with `retired` set stays an aim_fact entity as the audit trail
 * (ADR-0005) but never needs to be searched. Search API deletes an item a
 * processor rejects from the server, so retiring a fact (a plain save,
 * which re-tracks it) removes its vector row on the next index run, and
 * clearing `retired` puts it back. See ADR-0022.
 */
#[SearchApiProcessor(
  id: 'aim_exclude_retired',
  label: new TranslatableMarkup('Exclude retired AIM facts'),
  description: new TranslatableMarkup('Drops facts with "retired" set from the index, so retired facts never occupy vector search result slots.'),
  stages: [
    'alter_items' => 0,
  ],
)]
class ExcludeRetired extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function alterIndexedItems(array &$items) {
    foreach ($items as $item_id => $item) {
      $fact = $item->getOriginalObject()->getValue();
      // Any non-empty `retired` means retired, the same test recall() and
      // consolidate() use, not a comparison against the current time.
      if ($fact instanceof AimFact && !$fact->get('retired')->isEmpty()) {
        unset($items[$item_id]);
      }
    }
  }

}
