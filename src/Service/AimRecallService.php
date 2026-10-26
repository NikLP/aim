<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\aim\Entity\AimFact;
use Drupal\literals\LiteralReader;
use Drupal\search_api\SearchApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Semantic recall over aim's vector index.
 *
 * Split out of AimMemoryManager, which keeps the shared search plumbing
 * (executeSearchQuery()), loading and access. See ADR-0006 and ADR-0019.
 */
class AimRecallService {

  /**
   * Constructs an AimRecallService object.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $manager
   *   The memory manager, for the index, account lookup and search.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, to skip facts that have expired.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user: the viewer literal tokens are replaced as.
   * @param \Drupal\literals\LiteralReader $literalReader
   *   The literals reader, for [literal:key] tokens in fact text.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for recent()'s listing.
   */
  public function __construct(
    protected AimMemoryManager $manager,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
    #[Autowire(service: 'literals.reader')]
    protected LiteralReader $literalReader,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Formats a recall() row as one line of text for a model or a person.
   *
   * Adds the fact's date so a reader can tell a recent fact from an old
   * one: "true since" when `asserted` was set, otherwise "recorded" with
   * the creation date.
   *
   * @param array $row
   *   A row from recall().
   *
   * @return string
   *   For example "- Nik lives in Leeds (recorded 2026-09-14)".
   */
  public function formatFactLine(array $row): string {
    if (!empty($row['asserted'])) {
      $date = '(true since ' . gmdate('Y-m-d', (int) $row['asserted']) . ')';
    }
    else {
      $date = '(recorded ' . gmdate('Y-m-d', (int) $row['created']) . ')';
    }
    return '- ' . $row['text'] . ' ' . $date;
  }

  /**
   * Runs a semantic query against the vector index.
   *
   * @param string $text
   *   The search text.
   * @param string|null $scope
   *   Restrict results to one scope: user, role, site, case.
   * @param string|null $subject
   *   Restrict results to one subject. Not used for scope=user, see
   *   $subjectUid.
   * @param string|null $subjectUid
   *   Restrict results to one user, by uid or username. Only meaningful
   *   with scope=user.
   * @param int $limit
   *   Maximum number of results.
   * @param float|null $maxDistance
   *   Drop matches whose distance exceeds this, so a poor match is not
   *   presented as relevant (ADR-0019). NULL, the default, returns the
   *   nearest facts however far. getRecallMaxDistance() is the site's
   *   calibrated value.
   * @param bool $includeUntrusted
   *   FALSE (the default) restricts results to trusted facts, per
   *   ADR-0002's addendum - every other caller should leave this alone.
   *   TRUE lifts that filter, for a review queue that needs to see
   *   untrusted facts too.
   * @param float|null $gap
   *   Keep only facts whose distance is within this of the best match's,
   *   so a clear winner is not returned with a tail of loosely related
   *   facts. NULL or 0 returns every fact under $maxDistance.
   *   getRecallGap() is the site's value.
   * @param bool $accessCheck
   *   TRUE (the default) returns only facts the current account may view.
   *   FALSE skips entity access, for Drush commands only. See
   *   executeSearchQuery().
   *
   * @return array
   *   A list of rows, each with keys id, score, scope, subject, text,
   *   source, trusted, created, asserted (a timestamp, or NULL when
   *   never set; see formatFactLine()). Retired facts are never returned, so
   *   there is no retired key. score is what search_api reports for the
   *   match, which for ai_vdb_provider_mariadb is MariaDB's
   *   VEC_DISTANCE_COSINE value: a cosine distance, 0.0 for an identical
   *   embedding and larger the less similar the fact is, so lower is a
   *   better match - the opposite of what "score" usually implies.
   *   Consolidation's thresholds are expressed in the same unit ("at or
   *   below").
   *
   * @throws \RuntimeException
   *   If the vector index does not exist.
   * @throws \InvalidArgumentException
   *   If $subjectUid does not resolve to a real account.
   */
  public function recall(string $text, ?string $scope, ?string $subject, ?string $subjectUid, int $limit, ?float $maxDistance = NULL, bool $includeUntrusted = FALSE, ?float $gap = NULL, bool $accessCheck = TRUE): array {
    $index = $this->manager->loadVectorIndex();

    if (!$index) {
      throw new \RuntimeException('The aim_vector_index search index does not exist.');
    }

    $filter_account = NULL;
    if (!empty($subjectUid)) {
      $filter_account = $this->manager->resolveAccount($subjectUid);
      if (!$filter_account) {
        throw new \InvalidArgumentException('No user account found for subject-uid "' . $subjectUid . '".');
      }
    }

    $query = $index->query()->keys($text);

    if (!empty($scope)) {
      $query->addCondition('scope', $scope);
    }

    if (!empty($subject)) {
      $query->addCondition('subject', $subject);
    }

    if ($filter_account) {
      $query->addCondition('user', (int) $filter_account->id());
    }

    if (!$includeUntrusted) {
      $query->addCondition('trusted', TRUE);
    }

    // A user with few facts is pre-filtered exactly through the BTREE index
    // on user, but one holding a large share of the table is served
    // by HNSW candidates post-filtered by the condition, so ask for extra
    // candidates. The loop below stops at $limit. See ADR-0018.
    $query->range(0, $filter_account ? $limit * 5 : $limit);

    $results = $this->manager->executeSearchQuery($query, $accessCheck);

    $rows = [];
    foreach ($results as $result) {
      // Checked first: a far match costs no entity load. Rows arrive
      // nearest-first, but the cutoff is applied before the limit so a
      // uid-filter over-fetch pool is spent on matches that qualify.
      if ($maxDistance !== NULL && (float) $result->getScore() > $maxDistance) {
        continue;
      }

      try {
        $original = $result->getOriginalObject();
      }
      catch (SearchApiException) {
        // A stale index entry pointing at an aim_fact that no longer
        // exists (deleted directly, or by consolidation's RETIRE
        // decision) and hasn't been reindexed away yet - skip it rather
        // than let one stale row fail the whole recall() call.
        continue;
      }

      if ($original === NULL) {
        continue;
      }

      $row = $this->row($original->getValue(), $result->getScore());
      if ($row === NULL) {
        continue;
      }
      $rows[] = $row;

      if (count($rows) >= $limit) {
        break;
      }
    }

    if ($gap && $rows) {
      $limitDistance = (float) $rows[0]['score'] + $gap;
      $rows = array_values(array_filter($rows, fn (array $row): bool => (float) $row['score'] <= $limitDistance));
    }

    $this->manager->logVerbose('Recall returned @count rows (scope @scope, subject @subject, subject uid @subject_uid, limit @limit, best score @best, untrusted included @untrusted, uid @uid).', [
      '@count' => count($rows),
      '@scope' => $scope ?: 'any',
      '@subject' => $subject ?: 'any',
      '@subject_uid' => $filter_account ? $filter_account->id() : 'any',
      '@limit' => $limit,
      '@best' => $rows ? round((float) $rows[0]['score'], 3) : 'none',
      '@untrusted' => $includeUntrusted ? 'yes' : 'no',
      '@uid' => $this->currentUser->id(),
    ]);
    if ($this->configFactory->get('aim.settings')->get('log_query_text')) {
      // Opt-in personal data: the query can name people. Gated on
      // logVerbose() so it never outlives the verbose tier.
      $this->manager->logVerbose('Recall query (best score @best): @query', [
        '@best' => $rows ? round((float) $rows[0]['score'], 3) : 'none',
        '@query' => $text,
      ]);
    }

    return $rows;
  }

  /**
   * Lists the newest trusted facts in one scope, with no search text.
   *
   * For a briefing at the start of work, which has no query to search by:
   * recall() is semantic and finds nothing for "everything". Same rows and
   * the same withholding as recall() (retired, past expiry, a literal the
   * viewer cannot read), and only facts the current account may view.
   *
   * @param string $scope
   *   The scope to list.
   * @param string[]|null $subjects
   *   Restrict to these subjects, e.g. the viewer's roles. NULL for any.
   * @param \Drupal\Core\Session\AccountInterface|null $user
   *   Restrict to facts about this account, for a scope that requires one.
   * @param int $limit
   *   Maximum number of rows.
   *
   * @return array
   *   Rows as recall() returns them, newest first, with score NULL.
   */
  public function recent(string $scope, ?array $subjects, ?AccountInterface $user, int $limit): array {
    if ($subjects === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('scope', $scope)
      ->condition('trusted', TRUE)
      ->notExists('retired')
      ->sort('created', 'DESC')
      ->sort('id', 'DESC');
    $query->condition($query->orConditionGroup()
      ->notExists('expires')
      ->condition('expires', $this->time->getRequestTime(), '>'));
    if ($subjects !== NULL) {
      $query->condition('subject', $subjects, 'IN');
    }
    if ($user) {
      $query->condition('user', (int) $user->id());
    }

    // Entity queries don't apply aim_fact's view access, so facts are
    // checked as they load; page on until the limit fills.
    $rows = [];
    $offset = 0;
    while (count($rows) < $limit) {
      $ids = (clone $query)->range($offset, $limit * 2)->execute();
      if (!$ids) {
        break;
      }
      $offset += count($ids);
      foreach ($storage->loadMultiple($ids) as $fact) {
        if (!$fact->access('view', $this->currentUser)) {
          continue;
        }
        $row = $this->row($fact, NULL);
        if ($row !== NULL) {
          $rows[] = $row;
        }
        if (count($rows) >= $limit) {
          break;
        }
      }
    }

    $this->manager->logVerbose('Recent returned @count rows (scope @scope, limit @limit, uid @uid).', [
      '@count' => count($rows),
      '@scope' => $scope,
      '@limit' => $limit,
      '@uid' => $this->currentUser->id(),
    ]);
    return $rows;
  }

  /**
   * Builds one result row, or NULL when the fact is withheld.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact.
   * @param float|null $score
   *   The match distance, or NULL when the row did not come from a search.
   *
   * @return array|null
   *   The row (see recall()), or NULL for a retired or past-due fact, or one
   *   naming a literal the viewer cannot read.
   */
  protected function row(AimFact $fact, ?float $score): ?array {
    // The aim_exclude_retired processor keeps retired facts out of the
    // index (ADR-0022), but one retired since the last index run is
    // still there; filter it out here for that gap.
    if (!$fact->get('retired')->isEmpty()) {
      return NULL;
    }
    // A past-due fact is withheld before the expiry sweep retires it
    // (ADR-0057), so expiry holds to the date, not the next cron run.
    $expires = $fact->get('expires')->value;
    if ($expires !== NULL && (int) $expires <= $this->time->getRequestTime()) {
      return NULL;
    }

    // Tokens resolve as the viewing account at read time, so the value is
    // never stored in the fact. A fact naming a literal this viewer cannot
    // read is withheld whole: a sentence with a hole in it misleads, and
    // its presence would hint at the restricted value. The
    // show_redacted_facts debug setting shows it with [redacted] instead.
    $text = $this->literalReader->replaceTokens((string) $fact->get('text')->value, $this->currentUser, NULL, !$this->configFactory->get('aim.settings')->get('show_redacted_facts'));
    if ($text === NULL) {
      return NULL;
    }

    return [
      'id' => $fact->id(),
      'score' => $score,
      'scope' => $fact->bundle(),
      'subject' => $this->manager->scopeRequiresAccount($fact->bundle()) ? $fact->get('user')->target_id : $fact->get('subject')->value,
      'text' => $text,
      'source' => $fact->get('source')->value,
      'trusted' => (bool) $fact->get('trusted')->value,
      'created' => (int) $fact->get('created')->value,
      'asserted' => $fact->get('asserted')->isEmpty() ? NULL : (int) $fact->get('asserted')->value,
    ];
  }

}
