<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\Guardrail\AiGuardrailRepository;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\aim\Entity\AimFact;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface as SearchApiQueryInterface;
use Drupal\search_api\Query\ResultSetInterface;

/**
 * Read/write access to aim's memory store.
 *
 * Holds the logic every caller of aim_fact needs - drush commands today, a
 * Tool API plugin or a review Form later - so none of them reimplement
 * scope/subject validation, the vector index gotchas, or the consolidation
 * algorithm on their own. See CLAUDE.md for the design notes this class
 * implements.
 */
final class AimMemoryManager {

  /**
   * The scope values aim_fact's field definition allows.
   */
  protected const ALLOWED_SCOPES = ['user', 'role', 'site', 'case'];

  /**
   * The AI Guardrail Set applied to every candidate fact before it is saved.
   *
   * See CLAUDE.md decision 7 and config/install/ai.ai_guardrail_set.
   * aim_write_guardrails.yml for the shipped set (RegexpGuardrail +
   * InputLengthLimit, no extra LLM call).
   */
  protected const GUARDRAIL_SET_ID = 'aim_write_guardrails';

  /**
   * The Queue API queue that aim_consolidate's QueueWorker plugin drains.
   *
   * Deliberately not touched by hook_cron (decision 4, CLAUDE.md
   * "Consolidation (phase 2)") - only a dedicated crontab entry running
   * `drush queue:run aim_consolidate` processes it.
   */
  protected const CONSOLIDATE_QUEUE_ID = 'aim_consolidate';

  /**
   * Score at or below which a neighbor is retired automatically, no LLM call.
   *
   * Public and shared between the CLI sweep's option default and the queue
   * worker, so the two can't drift out of sync the way AimCommands'
   * hardcoded provider/model defaults already did twice earlier this
   * project. Empirically set against amazeeio__mistral-embed (see
   * CLAUDE.md's "Consolidation" section) - recalibrated 2026-09-09 from an
   * earlier 0.35/0.65 pair that was calibrated against
   * titan-embed-text-v2:0 and never re-checked after that model was
   * discontinued mid-session and swapped for mistral-embed.
   */
  public const DEFAULT_AUTO_THRESHOLD = 0.05;

  /**
   * Score at or below which an ambiguous neighbor gets a classification call.
   *
   * See DEFAULT_AUTO_THRESHOLD.
   */
  public const DEFAULT_AMBIGUOUS_THRESHOLD = 0.20;

  /**
   * Constructs an AimMemoryManager object.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher, used to run access-checked queries as a
   *   privileged account for callers with no logged-in user of their own
   *   (drush, cron).
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, used to stamp facts retired by consolidation.
   * @param \Drupal\ai\Guardrail\AiGuardrailRepository $guardrailRepository
   *   The AI guardrail repository, used to load the write-guardrail set.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory, used to enqueue newly-written facts for async
   *   consolidation.
   */
  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountSwitcherInterface $accountSwitcher,
    protected TimeInterface $time,
    protected AiGuardrailRepository $guardrailRepository,
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * Enqueues a fact for async consolidation against its nearest neighbor.
   *
   * Decision 4 (CLAUDE.md): consolidation runs unattended via Queue API,
   * not on the live request path and not mixed into hook_cron. Public, not
   * just called internally by remember()/createFactsFromCandidates():
   * aim_eca's FactWrite action calls this directly via the service locator
   * for the same reason it calls runGuardrails() that way (ConfigurableAction
   * Base's final __construct()).
   *
   * @param int $factId
   *   The ID of the newly-created fact.
   */
  public function enqueueForConsolidation(int $factId): void {
    $this->queueFactory->get(self::CONSOLIDATE_QUEUE_ID)->createItem($factId);
  }

  /**
   * Loads a single aim_fact by ID.
   *
   * @param int $factId
   *   The fact ID.
   *
   * @return \Drupal\aim\Entity\AimFact|null
   *   The fact, or NULL if it does not exist.
   */
  public function loadFact(int $factId): ?AimFact {
    $fact = $this->entityTypeManager->getStorage('aim_fact')->load($factId);
    return $fact instanceof AimFact ? $fact : NULL;
  }

  /**
   * Runs candidate fact text through the aim_write_guardrails set.
   *
   * Decision 7 (CLAUDE.md): every candidate fact is treated as untrusted
   * input by default. The shipped set only contains RegexpGuardrail and
   * InputLengthLimit (no NonDeterministicGuardrailInterface plugin), so this
   * never makes an LLM call - see the AI dependency map in CLAUDE.md. If the
   * set has been removed from this site, guardrail checking is silently
   * skipped rather than blocking every write.
   *
   * Public, not just used internally by remember()/createFactsFromCandidates():
   * aim_eca's FactWrite action calls this directly via the service locator
   * (its ActionBase has a final __construct(), the same reason
   * AccountResolverTrait calls resolveAccount() that way) rather than
   * duplicating the check, since a fact written by an ECA model is exactly
   * the kind of proposed-by-something-else write decision 7 is aimed at.
   *
   * @param string $text
   *   The candidate fact text.
   *
   * @throws \InvalidArgumentException
   *   If a guardrail's aggregated stop score reaches the set's stop
   *   threshold.
   */
  public function runGuardrails(string $text): void {
    $guardrail_set = $this->guardrailRepository->getGuardrailSetById(self::GUARDRAIL_SET_ID);
    if (!$guardrail_set) {
      return;
    }

    $input = new ChatInput([new ChatMessage('user', $text)]);
    $aggregated_score = 0.0;
    $messages = [];

    foreach ($guardrail_set->getPreGenerateGuardrails() as $guardrail) {
      $result = $guardrail->processInput($input);
      if (!$result instanceof StopResult) {
        continue;
      }
      $aggregated_score += $result->getScore();
      $messages[] = $result->getMessage();
      if ($aggregated_score >= $guardrail_set->getStopThreshold()) {
        throw new \InvalidArgumentException('Guardrail check failed: ' . implode(' ', $messages));
      }
    }
  }

  /**
   * Resolves a uid or username to a real user account.
   *
   * @param string $value
   *   A numeric uid, or an account name.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   The matching account, or NULL if none exists.
   */
  public function resolveAccount(string $value): ?AccountInterface {
    $storage = $this->entityTypeManager->getStorage('user');
    if (ctype_digit($value)) {
      $account = $storage->load((int) $value);
      return $account instanceof AccountInterface ? $account : NULL;
    }
    $accounts = $storage->loadByProperties(['name' => $value]);
    $account = reset($accounts);
    return $account instanceof AccountInterface ? $account : NULL;
  }

  /**
   * Loads the aim_vector_index search index.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index, or NULL if it does not exist.
   */
  public function loadVectorIndex(): ?IndexInterface {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load('aim_vector_index');
    return $index instanceof IndexInterface ? $index : NULL;
  }

  /**
   * Reindexes the vector index immediately.
   *
   * @return int|null
   *   The number of items indexed, or NULL if the index does not exist.
   */
  public function reindex(): ?int {
    $index = $this->loadVectorIndex();
    return $index ? $index->indexItems() : NULL;
  }

  /**
   * Resolves the site-wide default chat provider and model.
   *
   * Mirrors `AiAssistantApiRunner::getProviderAndModel()`'s own
   * `__default__` handling - a caller with no opinion on which provider to
   * use should resolve this instead of hardcoding one, so it follows
   * whatever `ai.settings`' `default_providers.chat` is currently set to
   * rather than drifting out of sync with it. Written after a hardcoded
   * `'anthropic'`/`'claude-sonnet-5'` default in `AimCommands` had to be
   * hand-edited twice already this project when the site's working
   * provider changed.
   *
   * @return array
   *   An array with keys 'provider_id' and 'model_id', or empty if no
   *   default chat provider is configured.
   */
  public function getDefaultChatProvider(): array {
    return $this->aiProvider->getDefaultProviderForOperationType('chat');
  }

  /**
   * Runs a search_api query as user 1, since some callers have no user.
   *
   * Drush (and cron) runs as the anonymous user by default, which has no
   * view access to aim_fact, so the AI Search backend's per-result entity
   * access check would silently drop every match. Run the query as user 1
   * instead of bypassing access outright, so this stays subject to whatever
   * real permission eventually governs aim_fact (CLAUDE.md decision 3).
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query to execute.
   *
   * @return \Drupal\search_api\Query\ResultSetInterface
   *   The query results.
   */
  public function executeAsAdmin(SearchApiQueryInterface $query): ResultSetInterface {
    $admin = $this->entityTypeManager->getStorage('user')->load(1);
    if (!$admin instanceof AccountInterface) {
      throw new \RuntimeException('User 1 does not exist, no account to run this query as.');
    }
    $this->accountSwitcher->switchTo($admin);
    try {
      return $query->execute();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Creates an aim_fact directly, no chat call.
   *
   * Unlike extractFacts(), this does not decide what is worth remembering -
   * it stores exactly what the caller (typically an agent that already did
   * that reasoning) hands it.
   *
   * @param string $text
   *   The fact text, one short statement.
   * @param string $scope
   *   One of user, role, site, case.
   * @param string|null $subject
   *   Who or what the fact is about. For scope=user, a uid or username of a
   *   real account on this site. Ignored for site scope.
   * @param string|null $source
   *   Provenance tag for this fact.
   * @param bool|null $state
   *   Optional boolean flag value. NULL for facts with no boolean shape.
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The created fact.
   *
   * @throws \InvalidArgumentException
   *   If scope is invalid, scope=user and subject does not resolve to a real
   *   account, or the text fails a guardrail check.
   */
  public function remember(string $text, string $scope, ?string $subject, ?string $source, ?bool $state): AimFact {
    if (!in_array($scope, self::ALLOWED_SCOPES, TRUE)) {
      throw new \InvalidArgumentException('Invalid scope "' . $scope . '", expected one of: ' . implode(', ', self::ALLOWED_SCOPES));
    }

    $this->runGuardrails($text);

    $values = [
      'scope' => $scope,
      'text' => $text,
      'source' => $source,
    ];

    if ($scope === 'user') {
      // A user-scope fact has to be about a real account: subject is a
      // genuine entity_reference (subject_uid), not a free-text string that
      // merely happens to hold a uid - that drift is exactly what let "1"
      // and "Nik" address the same person without ever matching.
      if (empty($subject)) {
        throw new \InvalidArgumentException('subject is required for scope=user: a uid or username of a real account on this site.');
      }
      $account = $this->resolveAccount($subject);
      if (!$account) {
        throw new \InvalidArgumentException('No user account found for subject "' . $subject . '". A user-scope fact must be about a real account.');
      }
      $values['subject_uid'] = $account->id();
      $values['subject'] = '';
    }
    else {
      $values['subject'] = $subject ?? '';
    }

    if ($state !== NULL) {
      $values['state'] = $state;
    }

    /** @var \Drupal\aim\Entity\AimFact $entity */
    $entity = $this->entityTypeManager->getStorage('aim_fact')->create($values);
    $entity->save();
    $this->enqueueForConsolidation((int) $entity->id());

    return $entity;
  }

  /**
   * Creates aim_fact entities from candidate facts, e.g. from extractFacts().
   *
   * @param array $facts
   *   A list of ['scope' => ..., 'subject' => ..., 'text' => ...] arrays.
   * @param string $source
   *   Provenance tag stored on every created fact.
   *
   * @return array
   *   An array with keys 'created' (\Drupal\aim\Entity\AimFact[]), 'skipped'
   *   (int, the number of user-scope candidates whose subject did not
   *   resolve to a real account), and 'blocked' (int, the number of
   *   candidates a guardrail rejected, per decision 7).
   */
  public function createFactsFromCandidates(array $facts, string $source): array {
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    $created = [];
    $skipped = 0;
    $blocked = 0;

    foreach ($facts as $fact) {
      try {
        $this->runGuardrails($fact['text']);
      }
      catch (\InvalidArgumentException) {
        // Every candidate is checked independently: one rejected fact
        // should not abort the rest of an extraction batch, the same
        // posture already taken for an unresolvable user-scope subject.
        $blocked++;
        continue;
      }

      $values = [
        'text' => $fact['text'],
        'source' => $source,
      ];

      if ($fact['scope'] === 'user') {
        // Same account-resolution rule as remember() - the model can only
        // return a name it read from the source text, which may not
        // resolve to one.
        $account = !empty($fact['subject']) ? $this->resolveAccount((string) $fact['subject']) : NULL;
        if (!$account) {
          $skipped++;
          continue;
        }
        $values['scope'] = 'user';
        $values['subject_uid'] = $account->id();
        $values['subject'] = '';
      }
      else {
        $values['scope'] = $fact['scope'];
        $values['subject'] = $fact['subject'] ?? '';
      }

      $entity = $storage->create($values);
      $entity->save();
      $this->enqueueForConsolidation((int) $entity->id());
      $created[] = $entity;
    }

    return ['created' => $created, 'skipped' => $skipped, 'blocked' => $blocked];
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
   *
   * @return array
   *   A list of rows, each with keys id, score, scope, subject, text,
   *   source, state.
   *
   * @throws \RuntimeException
   *   If the vector index does not exist.
   * @throws \InvalidArgumentException
   *   If $subjectUid does not resolve to a real account.
   */
  public function recall(string $text, ?string $scope, ?string $subject, ?string $subjectUid, int $limit): array {
    $index = $this->loadVectorIndex();
    if (!$index) {
      throw new \RuntimeException('The aim_vector_index search index does not exist.');
    }

    $filter_account = NULL;
    if (!empty($subjectUid)) {
      $filter_account = $this->resolveAccount($subjectUid);
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
    // subject_uid is not an indexed attribute, so this is a post-filter
    // below rather than a query condition here; over-fetch to compensate.
    $query->range(0, $filter_account ? $limit * 5 : $limit);

    $results = $this->executeAsAdmin($query);

    $rows = [];
    foreach ($results as $result) {
      $fact = $result->getOriginalObject()->getValue();
      // `expires` is not an indexed attribute, so a retired fact still
      // matches the vector query; filter it out here instead.
      if (!$fact->get('expires')->isEmpty()) {
        continue;
      }
      if ($filter_account && (int) $fact->get('subject_uid')->target_id !== (int) $filter_account->id()) {
        continue;
      }
      $rows[] = [
        'id' => $fact->id(),
        'score' => $result->getScore(),
        'scope' => $fact->get('scope')->value,
        'subject' => $fact->get('scope')->value === 'user' ? $fact->get('subject_uid')->target_id : $fact->get('subject')->value,
        'text' => $fact->get('text')->value,
        'source' => $fact->get('source')->value,
        'state' => $fact->get('state')->value,
      ];
      if (count($rows) >= $limit) {
        break;
      }
    }

    return $rows;
  }

  /**
   * Reviews existing facts for near-duplicates and merges or retires them.
   *
   * See CLAUDE.md's "Consolidation" section for the full algorithm and the
   * empirical threshold defaults. Each fact is compared to its nearest
   * vector neighbor within the same scope/subject: an obvious near-duplicate
   * is retired automatically, an ambiguous case gets a single classification
   * call (ADD/UPDATE/DELETE/NOOP), and anything past the ambiguous
   * threshold is left alone at zero cost. Retiring a fact sets its
   * `expires` field rather than deleting it, to keep an audit trail. An
   * UPDATE whose merged text fails the aim_write_guardrails check (decision
   * 7) is downgraded to a synthetic BLOCKED decision instead: both facts
   * are left untouched, since a rejected merge should not still retire the
   * candidate on the strength of text nobody approved.
   *
   * @param string|null $scope
   *   Restrict the sweep to one scope: user, role, site, case.
   * @param string $providerId
   *   The AI provider plugin ID to use for ambiguous cases.
   * @param string $modelId
   *   The chat model ID to use for ambiguous cases.
   * @param float $autoThreshold
   *   Score at or below which a neighbor is retired automatically, no LLM
   *   call.
   * @param float $ambiguousThreshold
   *   Score at or below which an ambiguous neighbor gets a classification
   *   call. Above this, facts are left alone.
   * @param bool $dryRun
   *   If TRUE, decisions are computed but nothing is saved.
   *
   * @return array
   *   An array with keys 'rows' (each a [kept id, candidate id, score,
   *   decision] tuple) and 'has_facts' (bool, whether any un-expired facts
   *   existed to sweep at all).
   *
   * @throws \RuntimeException
   *   If the vector index does not exist, or a query cannot be run.
   */
  public function consolidate(?string $scope, string $providerId, string $modelId, float $autoThreshold, float $ambiguousThreshold, bool $dryRun): array {
    $index = $this->loadVectorIndex();
    if (!$index) {
      throw new \RuntimeException('The aim_vector_index search index does not exist.');
    }

    $fact_storage = $this->entityTypeManager->getStorage('aim_fact');
    $query = $fact_storage->getQuery()->accessCheck(FALSE)->sort('id')->notExists('expires');
    if (!empty($scope)) {
      $query->condition('scope', $scope);
    }
    $ids = $query->execute();

    if (empty($ids)) {
      return ['rows' => [], 'has_facts' => FALSE];
    }

    $handled = [];
    $rows = [];

    foreach ($fact_storage->loadMultiple($ids) as $fact) {
      if (isset($handled[$fact->id()])) {
        continue;
      }

      $neighbor_result = $this->findNearestNeighbor($index, $fact, $handled);
      if ($neighbor_result === NULL) {
        continue;
      }
      [$neighbor, $score] = $neighbor_result;
      if ($score > $ambiguousThreshold) {
        continue;
      }

      // Lower id is the established fact, higher id the newer restatement
      // being evaluated against it.
      $kept = $fact->id() < $neighbor->id() ? $fact : $neighbor;
      $candidate = $fact->id() < $neighbor->id() ? $neighbor : $fact;

      $decision = $this->decideAndApply($kept, $candidate, $score, $autoThreshold, $providerId, $modelId, $dryRun);

      $handled[$kept->id()] = TRUE;
      $handled[$candidate->id()] = TRUE;
      $rows[] = [$kept->id(), $candidate->id(), round($score, 3), $decision];
    }

    return ['rows' => $rows, 'has_facts' => TRUE];
  }

  /**
   * Runs a single fact against its nearest neighbor and applies a decision.
   *
   * The per-fact counterpart to consolidate()'s sweep: used by
   * AimConsolidateQueueWorker to process one newly-written fact, enqueued
   * by enqueueForConsolidation() (decision 4's Queue API automation). No
   * $handled registry is needed here the way consolidate()'s sweep needs
   * one - each queue item is processed and saved independently, so a later
   * item sees an already-`expires`-set fact and findNearestNeighbor()
   * already filters those out.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact to consolidate.
   * @param string $providerId
   *   The AI provider plugin ID to use for ambiguous cases.
   * @param string $modelId
   *   The chat model ID to use for ambiguous cases.
   * @param float $autoThreshold
   *   Score at or below which a neighbor is retired automatically, no LLM
   *   call.
   * @param float $ambiguousThreshold
   *   Score at or below which an ambiguous neighbor gets a classification
   *   call. Above this, the fact is left alone.
   *
   * @return array|null
   *   A [kept id, candidate id, score, decision] tuple, or NULL if no
   *   eligible neighbor was found.
   *
   * @throws \RuntimeException
   *   If the vector index does not exist.
   */
  public function consolidateFact(AimFact $fact, string $providerId, string $modelId, float $autoThreshold, float $ambiguousThreshold): ?array {
    $index = $this->loadVectorIndex();
    if (!$index) {
      throw new \RuntimeException('The aim_vector_index search index does not exist.');
    }

    $neighbor_result = $this->findNearestNeighbor($index, $fact, []);
    if ($neighbor_result === NULL) {
      return NULL;
    }
    [$neighbor, $score] = $neighbor_result;
    if ($score > $ambiguousThreshold) {
      return NULL;
    }

    $kept = $fact->id() < $neighbor->id() ? $fact : $neighbor;
    $candidate = $fact->id() < $neighbor->id() ? $neighbor : $fact;

    $decision = $this->decideAndApply($kept, $candidate, $score, $autoThreshold, $providerId, $modelId, FALSE);

    return [$kept->id(), $candidate->id(), round($score, 3), $decision];
  }

  /**
   * Decides and, unless dry-running, applies a consolidation outcome.
   *
   * Shared by consolidate()'s sweep and consolidateFact()'s per-item path -
   * both already know the pair and its similarity score, only how they got
   * there differs. An UPDATE whose merged text fails the aim_write_
   * guardrails check (decision 7) is downgraded to a synthetic BLOCKED
   * outcome instead of falling back to NOOP: NOOP would still retire the
   * candidate, discarding whatever new information it held, on the
   * strength of merged text nobody approved.
   *
   * @param \Drupal\aim\Entity\AimFact $kept
   *   The established fact.
   * @param \Drupal\aim\Entity\AimFact $candidate
   *   The newer fact being evaluated against it.
   * @param float $score
   *   The similarity score between the two.
   * @param float $autoThreshold
   *   Score at or below which the candidate is retired automatically, no
   *   LLM call.
   * @param string $providerId
   *   The AI provider plugin ID to use for ambiguous cases.
   * @param string $modelId
   *   The chat model ID to use for ambiguous cases.
   * @param bool $dryRun
   *   If TRUE, the decision is computed but nothing is saved.
   *
   * @return string
   *   The decision: ADD, UPDATE, DELETE, NOOP, or the synthetic BLOCKED.
   */
  protected function decideAndApply(AimFact $kept, AimFact $candidate, float $score, float $autoThreshold, string $providerId, string $modelId, bool $dryRun): string {
    if ($score <= $autoThreshold) {
      $decision = 'NOOP';
      $merged_text = NULL;
    }
    else {
      [$decision, $merged_text] = $this->classifyPair($kept, $candidate, $providerId, $modelId);
      if ($decision === 'UPDATE') {
        try {
          $this->runGuardrails($merged_text);
        }
        catch (\InvalidArgumentException) {
          $decision = 'BLOCKED';
        }
      }
    }

    if ($dryRun) {
      return $decision;
    }

    switch ($decision) {
      case 'UPDATE':
        $kept->set('text', $merged_text);
        $kept->save();
        $candidate->set('expires', $this->time->getRequestTime());
        $candidate->set('related', [$kept->id()]);
        $candidate->save();
        break;

      case 'NOOP':
        $candidate->set('expires', $this->time->getRequestTime());
        $candidate->set('related', [$kept->id()]);
        $candidate->save();
        break;

      case 'DELETE':
        $candidate->delete();
        break;

      case 'ADD':
        // Genuinely distinct facts, nothing to change.
        break;

      case 'BLOCKED':
        // A guardrail rejected the proposed merged text; leave both
        // facts exactly as they were, same as ADD.
        break;
    }

    return $decision;
  }

  /**
   * Finds the closest indexed neighbor to a fact, excluding handled ids.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The vector index to query.
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact to find a neighbor for.
   * @param array $handled
   *   Fact IDs already consumed by a decision this run, keyed by ID.
   *
   * @return array|null
   *   A [neighbor fact, score] pair, or NULL if no eligible neighbor exists.
   */
  protected function findNearestNeighbor(IndexInterface $index, AimFact $fact, array $handled): ?array {
    $scope = $fact->get('scope')->value;
    $is_user_scope = $scope === 'user';

    $query = $index->query()->keys($fact->get('text')->value);
    $query->addCondition('scope', $scope);
    $subject = $fact->get('subject')->value;
    // subject_uid is not an indexed attribute (see the gotcha this method's
    // docblock references), so user scope cannot be narrowed at the index
    // level - post-filter after fetching a wider result set instead.
    if (!$is_user_scope && $subject !== NULL && $subject !== '') {
      $query->addCondition('subject', $subject);
    }
    $query->range(0, $is_user_scope ? 20 : 5);

    $subject_uid = $is_user_scope ? $fact->get('subject_uid')->target_id : NULL;

    $results = $this->executeAsAdmin($query);
    foreach ($results as $result) {
      $candidate = $result->getOriginalObject()->getValue();
      if ((int) $candidate->id() === (int) $fact->id() || isset($handled[$candidate->id()])) {
        continue;
      }
      // The vector index does not know about `expires` (it is not an
      // indexed attribute), so an already-retired fact would otherwise
      // keep resurfacing as a neighbor on every future run.
      if (!$candidate->get('expires')->isEmpty()) {
        continue;
      }
      if ($is_user_scope) {
        $candidate_subject_uid = $candidate->get('subject_uid')->target_id;
        if ($subject_uid === NULL || $candidate_subject_uid === NULL || (int) $candidate_subject_uid !== (int) $subject_uid) {
          continue;
        }
      }
      return [$candidate, (float) $result->getScore()];
    }
    return NULL;
  }

  /**
   * Asks the chat provider to classify a candidate against a kept fact.
   *
   * @param \Drupal\aim\Entity\AimFact $kept
   *   The established fact being compared against.
   * @param \Drupal\aim\Entity\AimFact $candidate
   *   The newer fact being evaluated against it.
   * @param string $providerId
   *   The AI provider plugin ID.
   * @param string $modelId
   *   The chat model ID.
   *
   * @return array
   *   A [decision, merged_text] pair. Decision is one of ADD, UPDATE,
   *   DELETE, NOOP. merged_text is only meaningful for UPDATE.
   */
  protected function classifyPair(AimFact $kept, AimFact $candidate, string $providerId, string $modelId): array {
    $kept_text = $kept->get('text')->value;
    $candidate_text = $candidate->get('text')->value;

    $prompt = <<<PROMPT
      Two memory facts about the same scope and subject were flagged as
      possibly related. Decide what to do with the candidate fact relative
      to the existing one.

      Existing fact: "$kept_text"
      Candidate fact: "$candidate_text"

      Choose one decision:
      - "ADD": the two facts are genuinely different and both should be
        kept as-is.
      - "UPDATE": the candidate refines, corrects, or supersedes the
        existing fact (e.g. a changed preference). Provide a single merged
        statement in merged_text that replaces the existing fact's text.
      - "NOOP": the candidate simply restates the existing fact with no
        new information. The existing fact stands unchanged and the
        candidate is redundant.
      - "DELETE": the candidate fact should not exist as a memory at all
        (e.g. nonsensical or clearly erroneous). Use sparingly.

      For any decision other than UPDATE, set merged_text to the existing
      fact's text unchanged.
      PROMPT;

    $schema = new StructuredOutputSchema(
      name: 'aim_consolidation_decision',
      description: 'Consolidation decision for a candidate fact against an existing one.',
      strict: TRUE,
      json_schema: [
        'type' => 'object',
        'additionalProperties' => FALSE,
        'properties' => [
          'decision' => [
            'type' => 'string',
            'enum' => ['ADD', 'UPDATE', 'DELETE', 'NOOP'],
          ],
          'merged_text' => ['type' => 'string'],
        ],
        'required' => ['decision', 'merged_text'],
      ],
    );

    $input = new ChatInput([new ChatMessage('user', $prompt)]);
    $input->setChatStructuredJsonSchema($schema);

    $provider = $this->aiProvider->createInstance($providerId);
    $output = $provider->chat($input, $modelId, ['aim_consolidate']);
    $response_text = $output->getNormalized()->getText();

    $decoded = json_decode($response_text, TRUE);
    if (!is_array($decoded) || !isset($decoded['decision'])) {
      throw new \RuntimeException("Model response was not the expected JSON shape: $response_text");
    }

    return [$decoded['decision'], $decoded['merged_text'] ?? NULL];
  }

  /**
   * Calls the configured chat provider and returns structured facts.
   *
   * @param string $text
   *   The source text to extract facts from.
   * @param string $providerId
   *   The AI provider plugin ID.
   * @param string $modelId
   *   The chat model ID.
   *
   * @return array
   *   A list of ['scope' => ..., 'subject' => ..., 'text' => ...] arrays.
   */
  public function extractFacts(string $text, string $providerId, string $modelId): array {
    $prompt = <<<PROMPT
      Extract every discrete, atomic fact worth remembering long-term from
      the text below. Each fact must be one short, self-contained sentence.
      Do not invent facts the text does not support. If nothing is worth
      remembering, return an empty facts array.

      For each fact, classify its scope:
      - "user": specific to one named person.
      - "role": true for everyone holding a particular role.
      - "site": about the site or organization itself, not one person.
      - "case": tied to a specific support case or tracked issue.

      Also give a short "subject": for "user" scope, the person's name or
      identifier; for "role", the role name; for "case", a case identifier;
      for "site", leave it empty.

      Text:
      """
      $text
      """
      PROMPT;

    $schema = new StructuredOutputSchema(
      name: 'aim_extracted_facts',
      description: 'Atomic facts extracted from source text.',
      strict: TRUE,
      json_schema: [
        'type' => 'object',
        'additionalProperties' => FALSE,
        'properties' => [
          'facts' => [
            'type' => 'array',
            'items' => [
              'type' => 'object',
              'additionalProperties' => FALSE,
              'properties' => [
                'scope' => [
                  'type' => 'string',
                  'enum' => ['user', 'role', 'site', 'case'],
                ],
                'subject' => ['type' => 'string'],
                'text' => ['type' => 'string'],
              ],
              'required' => ['scope', 'subject', 'text'],
            ],
          ],
        ],
        'required' => ['facts'],
      ],
    );

    $input = new ChatInput([new ChatMessage('user', $prompt)]);
    $input->setChatStructuredJsonSchema($schema);

    $provider = $this->aiProvider->createInstance($providerId);
    $output = $provider->chat($input, $modelId, ['aim_extract']);
    $response_text = $output->getNormalized()->getText();

    $decoded = json_decode($response_text, TRUE);
    if (!is_array($decoded) || !isset($decoded['facts']) || !is_array($decoded['facts'])) {
      throw new \RuntimeException("Model response was not the expected JSON shape: $response_text");
    }

    return $decoded['facts'];
  }

}
