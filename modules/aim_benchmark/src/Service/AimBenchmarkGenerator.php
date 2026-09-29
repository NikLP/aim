<?php

declare(strict_types=1);

namespace Drupal\aim_benchmark\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\aim\Service\AimMemoryManager;

/**
 * Synthetic aim_fact generation for aim:benchmark.
 *
 * Split out of core aim's AimMemoryManager (which still owns reindex()/
 * recall(), the actual things being timed) so a site that only wants
 * production memory behavior doesn't also ship dev-only fact generation.
 */
class AimBenchmarkGenerator {

  /**
   * Sentence templates generateBenchmarkFacts() fills in with random words.
   *
   * Deliberately not Faker/devel_generate output - those aren't wired to
   * aim_fact's bundle, and this needs semantically plausible short
   * statements (so recall() queries have something real to match), not
   * arbitrary lorem ipsum.
   */
  protected const BENCHMARK_TEMPLATES = [
    'Prefers %s over %s for %s.',
    'Uses %s for %s on a regular basis.',
    'Mentioned interest in %s during a recent %s.',
    'Works mainly on %s, occasionally touches %s.',
    'Asked about %s pricing for %s.',
    'Reported an issue with %s while using %s.',
    'Recommended %s to a colleague for %s.',
    'Follows up on %s roughly every %s.',
  ];

  /**
   * Word pool BENCHMARK_TEMPLATES draws from.
   */
  protected const BENCHMARK_WORDS = [
    'email', 'phone', 'chat', 'billing', 'onboarding', 'the mobile app',
    'the API', 'support tickets', 'the newsletter', 'dark mode',
    'accessibility', 'the checkout flow', 'exports', 'the dashboard',
    'notifications', 'two-factor login', 'the search feature', 'reporting',
    'integrations', 'the calendar view', 'week', 'month', 'quarter',
    'marketing', 'engineering', 'sales', 'support', 'design', 'product',
  ];

  /**
   * Constructs an AimBenchmarkGenerator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager, used for scope validation (allowedScopes(),
   *   scopeRequiresAccount()) so a fifth scope added via a new aim_scope
   *   config entity is honored here automatically, no code change needed.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AimMemoryManager $memoryManager,
  ) {}

  /**
   * Returns a sample of real, active account IDs for user-scope benchmarking.
   *
   * A scope=user fact must reference a real account (ADR-0007) - can't be
   * fabricated, so benchmarking that scope round-robins generated facts
   * across whichever real accounts the site already has.
   *
   * @param int $limit
   *   Maximum number of account IDs to return.
   *
   * @return int[]
   *   Active, non-anonymous, non-uid-1 account IDs.
   */
  public function sampleUserIds(int $limit = 20): array {
    $ids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 1, '>')
      ->condition('status', 1)
      ->range(0, $limit)
      ->execute();
    return array_values(array_map('intval', $ids));
  }

  /**
   * Creates synthetic facts for benchmarking recall/consolidation at scale.
   *
   * Bypasses remember()'s guardrail check and consolidation enqueue on
   * purpose: generated text has nothing for a guardrail to catch, and
   * queuing thousands of facts for LLM-mediated consolidation would turn a
   * latency benchmark into an uncontrolled reasoning-call bill the moment
   * aim_consolidate's crontab entry next runs (aim's CLAUDE.md's
   * "Consolidation" section). Both checks run universally via
   * AimHooks::factPresave()/factInsert() (aim's src/Hook/AimHooks.php),
   * which skip an entity flagged with setSyncing(TRUE) - core's own "being
   * synchronized, skip side effects" flag (SynchronizableInterface, also
   * honored by pathauto, workspaces, and set by migrate destinations), so
   * this marks every generated entity that way rather than inventing a
   * private flag. The only real cost this leaves is one embedding-API call
   * per fact, at reindex() time.
   * Every created fact is tagged $runTag as its source so
   * deleteBenchmarkFacts() can find and remove exactly this run's data
   * afterward.
   *
   * @param string $scope
   *   One of user, role, site, case.
   * @param int $count
   *   How many facts to create.
   * @param string $runTag
   *   Provenance tag stored on every created fact.
   * @param int[] $subjectUids
   *   For scope=user, the pool of real account IDs to assign facts to,
   *   round-robin (see sampleUserIds()). Ignored for other scopes.
   *
   * @return int[]
   *   The created fact IDs.
   *
   * @throws \InvalidArgumentException
   *   If scope is invalid, or scope=user and $subjectUids is empty.
   */
  public function generateBenchmarkFacts(string $scope, int $count, string $runTag, array $subjectUids = []): array {
    $allowed = $this->memoryManager->allowedScopes();
    if (!in_array($scope, $allowed, TRUE)) {
      throw new \InvalidArgumentException('Invalid scope "' . $scope . '", expected one of: ' . implode(', ', $allowed));
    }
    $requiresUserAccount = $this->memoryManager->scopeRequiresAccount($scope);
    if ($requiresUserAccount && empty($subjectUids)) {
      throw new \InvalidArgumentException('scope=' . $scope . ' needs at least one real account ID in $subjectUids.');
    }

    $storage = $this->entityTypeManager->getStorage('aim_fact');
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
      $template = self::BENCHMARK_TEMPLATES[array_rand(self::BENCHMARK_TEMPLATES)];
      $words = [];
      for ($p = 0; $p < substr_count($template, '%s'); $p++) {
        $words[] = self::BENCHMARK_WORDS[array_rand(self::BENCHMARK_WORDS)];
      }
      $text = vsprintf($template, $words) . ' (#' . uniqid() . ')';

      $values = [
        'scope' => $scope,
        'text' => $text,
        'source' => $runTag,
        'subject' => '',
      ];
      if ($requiresUserAccount) {
        $values['user'] = $subjectUids[$i % count($subjectUids)];
      }

      /** @var \Drupal\aim\Entity\AimFact $entity */
      $entity = $storage->create($values);
      $entity->setSyncing(TRUE);
      $entity->save();
      $ids[] = (int) $entity->id();
    }

    return $ids;
  }

  /**
   * Deletes every fact created by a benchmark run, by its source tag.
   *
   * @param string $runTag
   *   The run tag passed to generateBenchmarkFacts().
   *
   * @return int
   *   The number of facts deleted.
   */
  public function deleteBenchmarkFacts(string $runTag): int {
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('source', $runTag)
      ->execute();
    if (empty($ids)) {
      return 0;
    }
    $storage->delete($storage->loadMultiple($ids));
    return count($ids);
  }

}
