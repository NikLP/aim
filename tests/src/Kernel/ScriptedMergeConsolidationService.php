<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimConsolidationService;

/**
 * Consolidation with the merge writer's chat call replaced by a set text.
 *
 * A decision model only classifies; the merge text comes from the site's
 * default chat model, which a kernel test has none of.
 */
final class ScriptedMergeConsolidationService extends AimConsolidationService {

  /**
   * The text writeMerge() returns.
   */
  public ?string $mergedText = NULL;

  /**
   * {@inheritdoc}
   */
  protected function writeMerge(AimFact $kept, AimFact $candidate): ?string {
    return $this->mergedText;
  }

}
