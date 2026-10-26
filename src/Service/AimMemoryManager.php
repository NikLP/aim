<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Guardrail\AiGuardrailRepository;
use Drupal\ai\Guardrail\NonDeterministicGuardrailInterface;
use Drupal\ai\Guardrail\Result\PassResult;
use Drupal\ai\Guardrail\Result\RewriteInputResult;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\aim\EventSubscriber\AimEmbeddingCacheSubscriber;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface as SearchApiQueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\Utility\PostRequestIndexingInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read/write access to aim's memory store.
 *
 * Holds the logic every caller of aim_fact needs - drush commands today, a
 * Tool API plugin or a review Form later - so none of them reimplement
 * scope/subject validation, the vector index gotchas, or the consolidation
 * algorithm on their own. See CLAUDE.md for the design notes this class
 * implements.
 */
class AimMemoryManager {

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
   * Constructs an AimMemoryManager object.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, used to stamp facts retired by consolidation.
   * @param \Drupal\ai\Guardrail\AiGuardrailRepository $guardrailRepository
   *   The AI guardrail repository, used to load the write-guardrail set.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory, used to enqueue newly-written facts for async
   *   consolidation.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to read aim.settings' admin-editable
   *   consolidation thresholds and extraction/consolidation prompts (see
   *   /admin/config/aim/settings, Drupal\aim\Form\AimSettingsForm).
   * @param \Drupal\aim\EventSubscriber\AimEmbeddingCacheSubscriber $embeddingCache
   *   The query-embedding cache subscriber (ADR-0017), activated by
   *   executeSearchQuery() for the duration of a query's execute() call.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The aim logger channel. Warnings and errors are always logged; the
   *   info and debug tiers are gated by aim.settings' log_audit and
   *   log_verbose (see logAudit() and logVerbose()).
   * @param \Drupal\search_api\Utility\PostRequestIndexingInterface $postRequestIndexing
   *   Search API's post-request queue (index_directly), which reindex()
   *   clears of the items it indexes itself.
   */
  public function __construct(
    #[Autowire(service: 'ai.provider')]
    protected AiProviderPluginManager $aiProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected AiGuardrailRepository $guardrailRepository,
    protected QueueFactory $queueFactory,
    protected ConfigFactoryInterface $configFactory,
    protected AimEmbeddingCacheSubscriber $embeddingCache,
    #[Autowire(service: 'logger.channel.aim')]
    protected LoggerChannelInterface $logger,
    protected PostRequestIndexingInterface $postRequestIndexing,
  ) {}

  /**
   * Logs an audit event at info level, if aim.settings:log_audit is on.
   *
   * Callers must pass IDs, scope, uid, subject and decision metadata only,
   * never fact text or recall query text (personal data).
   *
   * @param string $message
   *   The log message, with placeholders.
   * @param array $context
   *   The placeholder values.
   */
  public function logAudit(string $message, array $context = []): void {
    if ($this->configFactory->get('aim.settings')->get('log_audit')) {
      $this->logger->info($message, $context);
    }
  }

  /**
   * Logs detail at debug level, if aim.settings:log_verbose is on.
   *
   * Same data rules as logAudit().
   *
   * @param string $message
   *   The log message, with placeholders.
   * @param array $context
   *   The placeholder values.
   */
  public function logVerbose(string $message, array $context = []): void {
    if ($this->configFactory->get('aim.settings')->get('log_verbose')) {
      $this->logger->debug($message, $context);
    }
  }

  /**
   * Returns the enabled scopes, the ones a new fact may use.
   *
   * @return string[]
   *   Scope IDs, by weight then label, as the scope list shows them.
   */
  public function allowedScopes(): array {
    $scopes = $this->entityTypeManager->getStorage('aim_scope')->loadByProperties(['status' => TRUE]);
    uasort($scopes, [AimScope::class, 'sort']);
    return array_map('strval', array_keys($scopes));
  }

  /**
   * Whether $scope requires a fact to reference a real Drupal account.
   *
   * ADR-0007's rule, read from the scope's `requires_account` setting
   * (ADR-0028 piece 2). Only the user plugin sets it today.
   *
   * @param string $scope
   *   A scope ID, e.g. "user".
   *
   * @return bool
   *   TRUE if $scope requires a real account, FALSE if it has no such
   *   setting (including an unknown scope, treated as not requiring one).
   */
  public function scopeRequiresAccount(string $scope): bool {
    /** @var \Drupal\aim\Entity\AimScope|null $scopeEntity */
    $scopeEntity = $this->entityTypeManager->getStorage('aim_scope')->load($scope);
    $settings = $scopeEntity?->get('settings') ?? [];
    return (bool) ($settings['requires_account'] ?? FALSE);
  }

  /**
   * Enqueues a fact for async consolidation against its nearest neighbor.
   *
   * Decision 4 (CLAUDE.md): consolidation runs unattended via Queue API,
   * not on the live request path and not mixed into hook_cron. Called from
   * AimHooks::factInsert() for every newly inserted fact.
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
   * The ai module only ever runs a guardrail set from inside a chat call
   * (GuardrailsEventSubscriber::applyPreGenerateGuardrails(), fired by the
   * provider's PreGenerateResponseEvent) - there is no public API to apply
   * a set to arbitrary text, and AiGuardrailHelper::applyGuardrailSetToChat
   * Input() only attaches a set to an input for a later chat() call. This
   * method therefore mirrors that subscriber's pre-generate loop, result
   * type for result type, so a set behaves the same here as it would in
   * front of a chat call: PassResult is ignored, StopResult scores are
   * aggregated against the set's stop threshold, RewriteInputResult
   * replaces the text (which is why this returns the text instead of
   * void - a redacting guardrail rewrites what gets stored), and a
   * NonDeterministicGuardrailInterface plugin gets the provider manager it
   * needs. The subscriber's per-fiber re-entrancy counter is not needed
   * here: an LLM-backed guardrail's own chat call carries no guardrail set
   * of its own, so nothing recurses into this method. Re-check this against
   * the subscriber whenever drupal/ai is updated.
   *
   * @param string $text
   *   The candidate fact text.
   *
   * @return string
   *   The text to store: $text as given, or the rewritten text if a
   *   RewriteInputResult guardrail changed it.
   *
   * @throws \InvalidArgumentException
   *   If a guardrail's aggregated stop score reaches the set's stop
   *   threshold.
   */
  public function runGuardrails(string $text): string {
    $guardrail_set = $this->guardrailRepository->getGuardrailSetById(self::GUARDRAIL_SET_ID);
    if (!$guardrail_set) {
      return $text;
    }

    $message = new ChatMessage('user', $text);
    $input = new ChatInput([$message]);
    $aggregated_score = 0.0;
    $messages = [];

    foreach ($guardrail_set->getPreGenerateGuardrails() as $guardrail) {
      if ($guardrail instanceof NonDeterministicGuardrailInterface) {
        $guardrail->setAiPluginManager($this->aiProvider);
      }
      $result = $guardrail->processInput($input);

      if ($result instanceof PassResult) {
        continue;
      }

      if ($result instanceof StopResult) {
        $aggregated_score += $result->getScore();
        $messages[] = $result->getMessage();
        if ($aggregated_score >= $guardrail_set->getStopThreshold()) {
          throw new \InvalidArgumentException('Guardrail check failed: ' . implode(' ', $messages));
        }
      }

      if ($result instanceof RewriteInputResult) {
        // Same as the subscriber: the rewritten text replaces the message
        // in place, so every later guardrail in the set sees the rewrite.
        $message->setText($result->getMessage());
      }
    }

    return $message->getText();
  }

  /**
   * Saves an aim_fact entity, validating first.
   *
   * Guardrails (decision 7) run as a real field-level Constraint
   * (AimGuardrails, on the text field - see
   * src/Plugin/Validation/Constraint/) rather than a presave hook. The
   * entity add/edit form already calls $entity->validate() itself
   * (ContentEntityForm::validateForm()) and renders a violation as a
   * normal field error - but a programmatic writer with no form of its
   * own (remember(), createFactsFromCandidates()) has nothing calling
   * validate() for it, so this method does that explicitly before saving,
   * the same "programmatic writers call validate() before save()"
   * discipline the form gets for free. A guardrail rejection (or any
   * other entity constraint violation) throws \InvalidArgumentException,
   * the same exception every caller already catches - previously this
   * method existed to unwrap that exception back out of the
   * EntityStorageException a presave-hook exception got wrapped in; with
   * nothing left throwing from inside save() itself, that unwrapping is
   * gone too.
   *
   * aim_benchmark's fact generator deliberately bypasses this entirely by
   * calling $entity->save() directly instead of going through saveFact() -
   * synthetic benchmark text needs no validation, see its own docblock.
   *
   * @param \Drupal\aim\Entity\AimFact $entity
   *   The entity to save.
   *
   * @throws \InvalidArgumentException
   *   If a guardrail, or any other entity constraint, rejects the entity.
   */
  public function saveFact(AimFact $entity): void {
    $violations = $entity->validate();
    if (count($violations) > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = (string) $violation->getMessage();
      }
      throw new \InvalidArgumentException(implode(' ', $messages));
    }
    $entity->save();
  }

  /**
   * Checks whether an account may create a fact of the given scope.
   *
   * Wraps AimFactAccessControlHandler's per-scope create-access check
   * ("create {scope} aim facts", with "administer aim" as bypass)
   * for a caller outside a normal entity form context. Added so aim_tool's
   * aim_remember Tool plugin can check it too - previously that plugin
   * only checked the flat "remember aim facts" permission and never this
   * per-scope one, so the per-scope permissions bound the admin UI only,
   * not Tool API/MCP writes.
   *
   * @param string $scope
   *   The scope to check create access for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkCreateAccess(string $scope, AccountInterface $account): AccessResultInterface {
    return $this->entityTypeManager->getAccessControlHandler('aim_fact')
      ->createAccess($scope, $account, [], TRUE);
  }

  /**
   * Resolves a uid or username to a real user account.
   *
   * Read paths only (recall()'s subject filter): a wrong match here just
   * returns a wrong query result, not a permanent misattributed write. See
   * resolveAccountByUid() for the stricter uid-only resolution write paths
   * use instead.
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
   * Resolves a uid to a real user account, for write paths only.
   *
   * Unlike resolveAccount(), this never falls back to matching a freeform
   * username string: an exact match on a typo'd string can silently
   * collide with a different real account's actual username, permanently
   * attaching a written fact to the wrong person. The two legitimate
   * sources of a write-path user value are the current
   * authenticated user (already uid-formatted by every caller that does
   * this) and a widget-selected value (an entity_reference autocomplete
   * never emits freeform text), so requiring a literal uid costs nothing
   * real. Used by remember() and createFactsFromCandidates().
   *
   * @param string $value
   *   A numeric uid.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   The matching account, or NULL if $value is not numeric or does not
   *   match a real account.
   */
  public function resolveAccountByUid(string $value): ?AccountInterface {
    if (!ctype_digit($value)) {
      return NULL;
    }
    $account = $this->entityTypeManager->getStorage('user')->load((int) $value);
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
    if (!$index) {
      return NULL;
    }
    $pending = $index->getTrackerInstance()->getRemainingItems();
    $indexed = $index->indexItems();
    // With index_directly on, the facts saved earlier in this request are
    // also queued for indexing at the end of it. Left queued, that second
    // pass deletes each fact's vector row and re-inserts nothing:
    // ai_search's chunk slicing starts at the item's processed_chunks, which
    // the first pass left at its total. Confirmed live 2026-10-15.
    $this->postRequestIndexing->removeFromIndexing($index->id(), $pending);
    return $indexed;
  }

  /**
   * Runs a search_api query, checking entity access unless told not to.
   *
   * Same idiom as core's entity query accessCheck(): access is checked by
   * default, and a caller opts out explicitly. By default the query runs as
   * the current account, so ai_search's per-result
   * $entity->access('view', $account) check
   * (SearchApiAiSearchBackend::checkEntityAccess()) applies the per-scope
   * permission AimFactAccessControlHandler enforces, anonymous included.
   *
   * Only Drush commands and consolidation pass FALSE: Drush has no viewer to
   * check for and holds raw database credentials already, and consolidation
   * compares against every fact in a scope and returns nothing to a viewer.
   * Never switch accounts instead, see ADR-0006's 2026-09-18 addendum.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query to execute.
   * @param bool $accessCheck
   *   FALSE sets search_api_bypass_access, so entity access is not checked.
   *
   * @return \Drupal\search_api\Query\ResultSetInterface
   *   The query results.
   */
  public function executeSearchQuery(SearchApiQueryInterface $query, bool $accessCheck = TRUE): ResultSetInterface {
    if (!$accessCheck) {
      $query->setOption('search_api_bypass_access', TRUE);
    }
    // ADR-0017: cache query-time embeddings for the duration of this call
    // only, so index-time embeds (never routed through this method) are
    // never read from or written to the cache.
    $this->embeddingCache->setActive(TRUE);
    try {
      return $query->execute();
    }
    finally {
      $this->embeddingCache->setActive(FALSE);
    }
  }

  /**
   * Retires every live fact whose expires date has passed (ADR-0057).
   *
   * Soft retirement, like consolidation's: the fact stays for audit and
   * leaves recall and the vector index.
   *
   * @return int
   *   The number of facts retired.
   */
  public function retireExpired(): int {
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    $now = $this->time->getRequestTime();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('expires', $now, '<=')
      ->notExists('retired')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $fact) {
      $fact->set('retired', $now);
      $fact->save();
      $this->logAudit('Fact @id retired: expires date passed (scope @scope).', [
        '@id' => $fact->id(),
        '@scope' => $fact->bundle(),
      ]);
    }
    return count($ids);
  }

}
