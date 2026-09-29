<?php

declare(strict_types=1);

namespace Drupal\aim_benchmark\Drush\Commands;

use Drupal\aim\EventSubscriber\AimEmbeddingCacheSubscriber;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim_benchmark\Service\AimBenchmarkGenerator;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush front end for aim_benchmark's synthetic fact generator.
 *
 * All the actual logic being measured (reindex(), recall()) lives in
 * \Drupal\aim\Service\AimMemoryManager; fact generation lives in
 * \Drupal\aim_benchmark\Service\AimBenchmarkGenerator. These commands only
 * handle CLI-specific concerns: parsing option strings, timing loops, and
 * printing results via $this->io().
 */
final class AimBenchmarkCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs an AimBenchmarkCommands object.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager, for allowedScopes()/scopeRequiresAccount()
   *   validation and the reindex()/recall() calls being timed.
   * @param \Drupal\aim_benchmark\Service\AimBenchmarkGenerator $generator
   *   The synthetic fact generator.
   * @param \Drupal\aim\EventSubscriber\AimEmbeddingCacheSubscriber $embeddingCache
   *   The query-embedding cache subscriber (ADR-0017), used by
   *   aim:benchmark's --bypass-cache option.
   */
  public function __construct(
    protected AimMemoryManager $memoryManager,
    protected AimBenchmarkGenerator $generator,
    protected AimEmbeddingCacheSubscriber $embeddingCache,
  ) {
    parent::__construct();
  }

  /**
   * Benchmarks recall latency at increasing fact counts.
   *
   * Generates synthetic facts in batches up to each requested checkpoint,
   * reindexes, then times a batch of recall() calls at that fact count -
   * see aim's CLAUDE.md's AI dependency map and ADR-0010's open question 5
   * (retrieval latency asserted safe by reasoning about SQL cost, never
   * actually benchmarked). Generation bypasses guardrails and the
   * consolidation queue (see AimBenchmarkGenerator::generateBenchmarkFacts()),
   * so the only real cost here is one embedding-API call per generated
   * fact, at reindex time - no reasoning/LLM calls anywhere in this
   * command, cost is predictable up front.
   *
   * --bypass-cache disables ADR-0017's query-embedding cache for the
   * whole run: the sample queries repeat every `--queries` iterations
   * within a checkpoint, so without it every repeat after the first
   * becomes a cache hit and the numbers stop being comparable to earlier
   * measurements (recorded with no cache in place) or to a differently-
   * sized `--queries` run.
   *
   * @param array $options
   *   Command options.
   */
  #[CLI\Command(name: 'aim:benchmark', aliases: ['aim-benchmark'])]
  #[CLI\Option(name: 'scope', description: 'Which scope pool to generate facts into: site, role, case, or user.')]
  #[CLI\Option(name: 'checkpoints', description: 'Comma-separated cumulative fact counts to measure at.')]
  #[CLI\Option(name: 'queries', description: 'How many timed recall() calls to run at each checkpoint.')]
  #[CLI\Option(name: 'cleanup', description: 'Delete every fact this run created once the benchmark finishes.')]
  #[CLI\Option(name: 'bypass-cache', description: 'Disable the query-embedding cache for this run, so repeat sample queries are not served from it.')]
  #[CLI\Usage(name: 'drush aim:benchmark --scope=site --checkpoints=50,200,500 --cleanup', description: 'Generate up to 500 site-scope facts in three steps, timing recall() at each, then remove them all.')]
  #[CLI\Usage(name: 'drush aim:benchmark --scope=site --bypass-cache', description: 'Measure uncached recall() latency, comparable to earlier ADR-0017 measurements.')]
  public function benchmark(
    array $options = [
      'scope' => NULL,
      'checkpoints' => '50,200,500',
      'queries' => 10,
      'cleanup' => FALSE,
      'bypass-cache' => FALSE,
    ],
  ): void {
    $scope = $options['scope'];
    $allowed = $this->memoryManager->allowedScopes();
    if (!in_array($scope, $allowed, TRUE)) {
      $this->io()->error('Invalid --scope "' . $scope . '", expected one of: ' . implode(', ', $allowed) . '.');
      return;
    }

    $checkpoints = array_unique(array_map('intval', explode(',', (string) $options['checkpoints'])));
    sort($checkpoints);
    $queryCount = max(1, (int) $options['queries']);
    $runTag = 'benchmark:' . date('Ymd-His');

    $requiresUserAccount = $this->memoryManager->scopeRequiresAccount($scope);
    $subjectUids = [];
    if ($requiresUserAccount) {
      $subjectUids = $this->generator->sampleUserIds();
      if (empty($subjectUids)) {
        $this->io()->error('No real user accounts found to benchmark scope=' . $scope . ' against (a scope requiring an account must reference a real one, ADR-0007).');
        return;
      }
      $this->io()->note('Distributing generated facts across ' . count($subjectUids) . ' real account(s).');
    }

    $sampleQueries = [
      'email preference', 'billing question', 'mobile app issue',
      'onboarding process', 'dashboard feedback', 'support ticket follow-up',
      'notification settings', 'integration request',
    ];

    $this->io()->note("Run tag: $runTag. Bypasses guardrails and consolidation (synthetic text needs neither) - the only real cost is one embedding-API call per fact at reindex time.");

    if (!empty($options['bypass-cache'])) {
      $this->embeddingCache->setBypassed(TRUE);
    }

    $rows = [];
    $createdSoFar = 0;
    try {
      foreach ($checkpoints as $target) {
        if ($target > $createdSoFar) {
          $this->generator->generateBenchmarkFacts($scope, $target - $createdSoFar, $runTag, $subjectUids);
          $createdSoFar = $target;
        }

        $indexStart = microtime(TRUE);
        $indexed = $this->memoryManager->reindex();
        $indexMs = (int) round((microtime(TRUE) - $indexStart) * 1000);

        $subjectUid = $requiresUserAccount ? (string) $subjectUids[array_rand($subjectUids)] : NULL;
        $timings = [];
        for ($i = 0; $i < $queryCount; $i++) {
          $start = microtime(TRUE);
          try {
            $this->memoryManager->recall($sampleQueries[$i % count($sampleQueries)], $scope, NULL, $subjectUid, 10);
          }
          catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->io()->error($e->getMessage());
            return;
          }
          $timings[] = (microtime(TRUE) - $start) * 1000;
        }
        sort($timings);
        $avg = (int) round(array_sum($timings) / count($timings));
        $p95 = (int) round($timings[(int) floor(0.95 * (count($timings) - 1))]);

        $rows[] = [$createdSoFar, $indexed ?? 'n/a', $indexMs, $avg, $p95];
      }
    }
    finally {
      $this->embeddingCache->setBypassed(FALSE);
    }

    $this->io()->table(['Facts', 'Indexed this batch', 'Reindex ms', 'Recall avg ms', 'Recall p95 ms'], $rows);

    if (!empty($options['cleanup'])) {
      $deleted = $this->generator->deleteBenchmarkFacts($runTag);
      $this->memoryManager->reindex();
      $this->io()->success("Cleaned up $deleted benchmark fact(s).");
    }
    else {
      $this->io()->note("Benchmark facts left in place, tagged source=\"$runTag\". Remove them later with: drush aim:benchmark-cleanup $runTag");
    }
  }

  /**
   * Deletes every fact created by a previous aim:benchmark run.
   *
   * @param string $runTag
   *   The run tag printed by aim:benchmark, e.g. benchmark:20260910-141500.
   */
  #[CLI\Command(name: 'aim:benchmark-cleanup', aliases: ['aim-benchmark-cleanup'])]
  #[CLI\Argument(name: 'runTag', description: 'The run tag printed by aim:benchmark.')]
  public function benchmarkCleanup(string $runTag): void {
    $deleted = $this->generator->deleteBenchmarkFacts($runTag);
    if ($deleted === 0) {
      $this->io()->note('No facts found tagged "' . $runTag . '".');
      return;
    }
    $this->memoryManager->reindex();
    $this->io()->success("Deleted $deleted fact(s), reindexed.");
  }

}
