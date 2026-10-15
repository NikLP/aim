<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\Guardrail\AiGuardrailRepository;
use Drupal\ai\Guardrail\NonDeterministicGuardrailInterface;
use Drupal\ai\Guardrail\Result\PassResult;
use Drupal\ai\Guardrail\Result\RewriteInputResult;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\Value\NoulQuestion;
use Drupal\aim\AimScopeTypePluginManagerInterface;
use Drupal\aim\Backend\ActivityBackendInterface;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\EventSubscriber\AimEmbeddingCacheSubscriber;
use Drupal\literals\LiteralReader;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface as SearchApiQueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\SearchApiException;

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
   * The grounded check's question, %1$s being the candidate's state key.
   *
   * The "strict" wording measured in aim_benchmark's decision-eval.php
   * grounding task (ADR-0037 addenda): naming the failure kinds stops
   * proposals and wishes passing as settled facts. Narrower wordings were
   * tested 2026-10-11 and not adopted.
   */
  protected const GROUNDING_QUESTION = 'Does the text state the fact in %1$s? Answer no if %1$s adds, changes or drops any detail, gives an old value the text has replaced, pins a detail on the wrong person or thing, or states as settled something the text only proposes, hedges or reports second-hand.';

  /**
   * The grounding cutoff when activities.grounding.threshold is unset.
   *
   * Hosted Jev split supported from unsupported candidates at about 0.76 on
   * the 44-item eval set (tuned on that set, so optimistic).
   */
  protected const GROUNDING_DEFAULT_THRESHOLD = 0.7;

  /**
   * Shipped default for aim.settings' auto_threshold, and a fallback.
   *
   * The live, admin-editable value is aim.settings:auto_threshold
   * (/admin/config/aim/settings) - see getAutoThreshold(). This constant is
   * only the value config/install ships on a fresh install, and the
   * fallback if that config is ever missing entirely. Public and shared
   * between the CLI sweep's option default and the queue worker, so the two
   * can't drift out of sync the way AimCommands' hardcoded provider/model
   * defaults already did twice earlier this project. Empirically set
   * against ollama__nomic-embed-text:latest (see
   * CLAUDE.md's "Consolidation" section) - recalibrated 2026-09-10 from an
   * earlier 0.05/0.20 pair that was calibrated against amazeeio's
   * mistral-embed and never re-checked after the site's embeddings_engine
   * was switched to a local Ollama model.
   *
   * First pass set this to 0.12 from two known duplicate pairs (cosine
   * distance 0.059 and 0.082). That was too loose - live testing produced
   * a real false auto-merge at 0.1186 ("Nik likes chocolate biscuits" vs.
   * "Nik likes chocolate digestives", genuinely distinct preferences, not
   * a restatement) that got silently retired with zero LLM review. Pulled
   * back to sit clearly below that observed false positive - this small
   * embedding model apparently packs "same topic, different content" much
   * closer to "restated duplicate" than mistral-embed did, so the safe
   * auto-merge band is narrower here than the first calibration assumed.
   * Still not a large sample - re-check as real data grows, and favor the
   * ambiguous (LLM-reviewed) band over widening this one on a hunch.
   */
  public const DEFAULT_AUTO_THRESHOLD = 0.09;

  /**
   * Shipped default for aim.settings' ambiguous_threshold, and a fallback.
   *
   * See DEFAULT_AUTO_THRESHOLD - same relationship to the live config value,
   * read via getAmbiguousThreshold(). Set to cover the observed
   * distinct-but-topically-related band (0.29-0.41) for an LLM judgment
   * call, while still excluding clearly unrelated pairs (0.67+).
   */
  public const DEFAULT_AMBIGUOUS_THRESHOLD = 0.45;

  /**
   * Shipped default for aim.settings' neighbor_limit, and a fallback.
   *
   * How many nearest neighbors consolidation compares a fact against, read
   * via getNeighborLimit(). Each neighbor inside the ambiguous band can
   * cost one classification call, so this bounds the worst-case cost per
   * fact. Measured 2026-10-02 on the live site: 1 found 40 decisions, 3
   * found 86, including two merges 1 never saw.
   */
  public const DEFAULT_NEIGHBOR_LIMIT = 3;

  /**
   * Shipped default for aim.settings' recall_max_distance, and a fallback.
   *
   * See DEFAULT_AUTO_THRESHOLD - same relationship to the live config value,
   * read via getRecallMaxDistance(). Measured 2026-09-19 against
   * ollama__nomic-embed-text:latest on the live site-scope facts, question
   * text against fact text (not fact against fact, so the consolidation
   * thresholds above don't apply): answerable questions' best match
   * 0.12-0.29 (full questions and terse keyword queries alike),
   * near-topic-but-unanswerable 0.25-0.33, off-topic 0.51-0.64. 0.45 sits
   * in the empty band between the last two, with room either way.
   * It cannot separate an answerable question from a near-topic
   * unanswerable one (their ranges overlap) - that stays the model's call.
   * The off-topic floor drifts down as the corpus grows and diversifies
   * (more candidates, more coincidental near matches), so recheck it then,
   * not only when the embeddings model changes.
   */
  public const DEFAULT_RECALL_MAX_DISTANCE = 0.45;

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
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The entity type bundle info service, used to validate scope against
   *   aim_fact's real aim_scope bundles (see
   *   \Drupal\aim\Entity\AimScope) instead of a hardcoded list, so a
   *   fifth scope added via a new aim_scope config entity passes
   *   validation here automatically, no code change needed.
   * @param \Drupal\aim\AimScopeTypePluginManagerInterface $scopeAccessManager
   *   The scope access plugin manager, used to ask a scope's own plugin
   *   (if any) for a default subject when the caller omits one - e.g.
   *   aim_scope_case's plugin mints a new case ID, so every caller (MCP
   *   client, drush, a future ECA action) gets the same format for free
   *   instead of each needing to invent and agree on its own.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to read aim.settings' admin-editable
   *   consolidation thresholds and extraction/consolidation prompts (see
   *   /admin/config/aim/settings, Drupal\aim\Form\AimSettingsForm).
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user: the author uid in audit lines, and the viewer
   *   recall() replaces literal tokens as.
   * @param \Drupal\aim\EventSubscriber\AimEmbeddingCacheSubscriber $embeddingCache
   *   The query-embedding cache subscriber (ADR-0017), activated by
   *   executeSearchQuery() for the duration of a query's execute() call.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The aim logger channel. Warnings and errors are always logged; the
   *   info and debug tiers are gated by aim.settings' log_audit and
   *   log_verbose (see logAudit() and logVerbose()).
   * @param \Drupal\aim\Backend\ActivityBackendInterface $decisionBackend
   *   The Decision API backend, used for an activity whose
   *   aim.settings:activities backend is "decision".
   * @param \Drupal\literals\LiteralReader $literalReader
   *   The literals reader. recall() uses it to replace [literal:key] tokens
   *   in fact text as the viewing account.
   */
  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected AiGuardrailRepository $guardrailRepository,
    protected QueueFactory $queueFactory,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected AimScopeTypePluginManagerInterface $scopeAccessManager,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
    protected AimEmbeddingCacheSubscriber $embeddingCache,
    protected LoggerChannelInterface $logger,
    protected ActivityBackendInterface $decisionBackend,
    protected LiteralReader $literalReader,
  ) {}

  /**
   * Grounding scores fetched this request, keyed by passage and candidate.
   *
   * Lets a caller check a whole batch in one call, after which remember()
   * finds each fact's score here instead of calling again.
   *
   * @var array<string, float|null>
   */
  protected array $groundingCache = [];

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
   * Returns the scope values aim_fact's declared bundles allow.
   *
   * @return string[]
   *   The bundle machine names currently registered for aim_fact.
   */
  public function allowedScopes(): array {
    return array_keys($this->bundleInfo->getBundleInfo('aim_fact'));
  }

  /**
   * Whether $scope requires a fact to reference a real Drupal account.
   *
   * ADR-0007's rule, formerly a hardcoded `$scope === 'user'`/
   * `$fact->bundle() === 'user'` check in this class and AimCommands - now
   * a `requires_account` entry in the `settings` a scope's own plugin
   * declares (ADR-0028 piece 2, migrated off aim_scope_user's earlier
   * ThirdPartySetting), so a future scope can opt into the same
   * requirement without another hardcoded string comparison here. Only
   * aim_scope_user's plugin sets this today.
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
   * Returns the live auto-merge threshold from aim.settings.
   *
   * Falls back to DEFAULT_AUTO_THRESHOLD only if the config value is
   * missing (e.g. aim.settings was deleted outside of a normal uninstall) -
   * config/install ships a real value, so this should not normally be hit.
   *
   * @return float
   *   Score at or below which a neighbor is retired automatically.
   */
  public function getAutoThreshold(): float {
    $value = $this->configFactory->get('aim.settings')->get('auto_threshold');
    return $value !== NULL ? (float) $value : self::DEFAULT_AUTO_THRESHOLD;
  }

  /**
   * Returns the live ambiguous threshold from aim.settings.
   *
   * See getAutoThreshold() - same fallback behavior.
   *
   * @return float
   *   Score at or below which an ambiguous neighbor gets a classification
   *   call.
   */
  public function getAmbiguousThreshold(): float {
    $value = $this->configFactory->get('aim.settings')->get('ambiguous_threshold');
    return $value !== NULL ? (float) $value : self::DEFAULT_AMBIGUOUS_THRESHOLD;
  }

  /**
   * Returns how many nearest neighbors consolidation compares a fact to.
   *
   * See getAutoThreshold() - same fallback behavior.
   *
   * @return int
   *   At least 1.
   */
  public function getNeighborLimit(): int {
    $value = $this->configFactory->get('aim.settings')->get('neighbor_limit');
    return $value !== NULL ? max(1, (int) $value) : self::DEFAULT_NEIGHBOR_LIMIT;
  }

  /**
   * Returns the live recall cutoff from aim.settings.
   *
   * See getAutoThreshold() - same fallback behavior. Applied by callers
   * that must abstain on a poor match: aim_chatbot:recall and aim_tool's
   * aim_recall pass it to recall() as $maxDistance; recall() itself does
   * not apply it unless asked.
   *
   * @return float
   *   Distance above which a recalled fact is too dissimilar to present as
   *   relevant.
   */
  public function getRecallMaxDistance(): float {
    $value = $this->configFactory->get('aim.settings')->get('recall_max_distance');
    return $value !== NULL ? (float) $value : self::DEFAULT_RECALL_MAX_DISTANCE;
  }

  /**
   * Returns the recall gap callers apply to trim loose matches.
   *
   * @return float
   *   Keep only facts whose distance is within this of the best match's. 0
   *   (the default) turns the trim off. Like getRecallMaxDistance(), it is
   *   applied only when a caller passes it to recall(): the model-facing
   *   recall tools do, consolidation's neighbor search does not.
   */
  public function getRecallGap(): float {
    return (float) $this->configFactory->get('aim.settings')->get('recall_gap');
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
   * @return array|null
   *   An array with keys 'provider_id' and 'model_id', or NULL if no
   *   default chat provider is configured.
   */
  public function getDefaultChatProvider(): ?array {
    return $this->aiProvider->getDefaultProviderForOperationType('chat');
  }

  /**
   * Resolves the backend and model configured for an activity.
   *
   * Reads aim.settings:activities.{activity}; an empty chat choice falls
   * back to the site default chat provider. A decision backend has no site
   * default, so it needs an explicit choice.
   *
   * @param string $activity
   *   One of extraction, consolidation, verifier or grounding. Extraction is
   *   always chat, grounding always decision.
   *
   * @return array|null
   *   An array with keys 'provider_id', 'model_id' and 'backend', or NULL if
   *   neither a choice nor a usable default is configured.
   */
  public function getModelFor(string $activity): ?array {
    $choice = $this->configFactory->get('aim.settings')->get('activities.' . $activity) ?? [];
    $backend = $activity === 'grounding' || (($choice['backend'] ?? 'chat') === 'decision' && $activity !== 'extraction') ? 'decision' : 'chat';
    if (!empty($choice['provider']) && !empty($choice['model'])) {
      return ['provider_id' => $choice['provider'], 'model_id' => $choice['model'], 'backend' => $backend];
    }
    if ($backend === 'decision') {
      return NULL;
    }
    $default = $this->getDefaultChatProvider();
    return !empty($default['provider_id']) && !empty($default['model_id']) ? $default + ['backend' => 'chat'] : NULL;
  }

  /**
   * Returns the decision-backend model for an activity, if it uses one.
   *
   * @param string $activity
   *   One of consolidation, verifier or grounding.
   *
   * @return array|null
   *   A [provider_id, model_id] pair, or NULL when the activity uses the
   *   chat backend or has no decision model chosen.
   */
  public function decisionModelFor(string $activity): ?array {
    $choice = $this->getModelFor($activity);
    return ($choice['backend'] ?? NULL) === 'decision' ? [$choice['provider_id'], $choice['model_id']] : NULL;
  }

  /**
   * Returns the grounded check's mode: off, shadow or enforce.
   *
   * Shadow records each fact's score and leaves trusted alone; enforce also
   * sets trusted from the score (ADR-0037).
   *
   * @return string
   *   The mode, off when unset or unknown.
   */
  public function getGroundingMode(): string {
    $mode = $this->configFactory->get('aim.settings')->get('grounding_mode');
    return in_array($mode, ['shadow', 'enforce'], TRUE) ? $mode : 'off';
  }

  /**
   * Returns the score at or above which a fact counts as grounded.
   *
   * @return float
   *   aim.settings:activities.grounding.threshold, or the shipped default
   *   when unset or 0.
   */
  public function getGroundingThreshold(): float {
    $threshold = (float) ($this->configFactory->get('aim.settings')->get('activities.grounding.threshold') ?? 0);
    return $threshold > 0 ? $threshold : self::GROUNDING_DEFAULT_THRESHOLD;
  }

  /**
   * Asks the decision model whether a passage states each candidate fact.
   *
   * One call for the whole list: every candidate goes into the state next
   * to the passage with its own question, which measured as fast and as
   * accurate as one call per fact (ADR-0037 addendum). Scores already
   * fetched this request are reused. The passage is never stored; with
   * log_query_text on, it is logged with each score for diagnosis.
   *
   * @param string $passage
   *   The text the facts were written from.
   * @param string[] $candidates
   *   Candidate fact texts, keyed however the caller likes.
   *
   * @return array
   *   A grounding probability (0 to 1) per candidate, same keys, or NULL for
   *   each candidate when the check is off, has no decision model, or failed.
   */
  public function checkGrounding(string $passage, array $candidates): array {
    $scores = array_fill_keys(array_keys($candidates), NULL);
    $passage = trim($passage);
    if ($this->getGroundingMode() === 'off' || $passage === '' || !$candidates) {
      return $scores;
    }
    $model = $this->decisionModelFor('grounding');
    if ($model === NULL) {
      $this->logger->warning('Grounding is @mode but no decision model is chosen for it in the AIM settings; facts are not checked.', ['@mode' => $this->getGroundingMode()]);
      return $scores;
    }

    $state = ['text' => $passage];
    $questions = [];
    $asked = [];
    foreach ($candidates as $key => $candidate) {
      $hash = hash('sha256', $passage . "\0" . $candidate);
      if (array_key_exists($hash, $this->groundingCache)) {
        $scores[$key] = $this->groundingCache[$hash];
        continue;
      }
      $id = 'candidate_' . (count($asked) + 1);
      $state[$id] = (string) $candidate;
      $questions[$id] = new NoulQuestion(sprintf(self::GROUNDING_QUESTION, $id));
      $asked[$id] = [$key, $hash];
    }
    if (!$asked) {
      return $scores;
    }

    try {
      $response = $this->decisionBackend->run('grounding', new DecisionInput($state, $questions), $model[0], $model[1]);
      foreach ($asked as $id => [$key, $hash]) {
        $scores[$key] = $this->groundingCache[$hash] = $response->getNoul($id)->getProbability();
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Grounding check failed to run for @count candidate(s): @message', [
        '@count' => count($asked),
        '@message' => $e->getMessage(),
      ]);
      return $scores;
    }

    return $scores;
  }

  /**
   * Field values a grounding score sets on a new fact.
   *
   * @param float|null $score
   *   The checkGrounding() score, NULL when it could not be had.
   * @param bool|null $trusted
   *   The caller's explicit trusted value, which enforce mode never
   *   overrides.
   *
   * @return array
   *   grounding_score, plus trusted in enforce mode: TRUE at or above the
   *   threshold, FALSE below it or when the check failed (fail closed).
   */
  protected function groundingValues(?float $score, ?bool $trusted): array {
    $values = ['grounding_score' => $score];
    if ($trusted === NULL && $this->getGroundingMode() === 'enforce') {
      $values['trusted'] = $score !== NULL && $score >= $this->getGroundingThreshold();
    }
    return $values;
  }

  /**
   * Logs a fact's grounding verdict. IDs and the score only, no text.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The saved fact.
   */
  protected function logGrounding(AimFact $fact): void {
    $score = $fact->get('grounding_score')->value;
    $this->logAudit('Grounding check for fact @id: score @score, cutoff @cutoff, mode @mode, trusted @trusted.', [
      '@id' => $fact->id(),
      '@score' => $score === NULL ? 'none (check failed or no model)' : round((float) $score, 3),
      '@cutoff' => $this->getGroundingThreshold(),
      '@mode' => $this->getGroundingMode(),
      '@trusted' => $fact->get('trusted')->value ? 'yes' : 'no',
    ]);
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
   *   Who or what the fact is about. For scope=user, a uid of a real
   *   account on this site. Ignored for site scope. For scope=case, an
   *   existing case ID to continue - omit to start a new case, which mints
   *   one and stamps it onto the created fact.
   * @param string|null $source
   *   Provenance tag for this fact.
   * @param string[] $category
   *   Category term names to resolve against the aim_category vocabulary.
   *   A name with no matching term is skipped, not created.
   * @param int|null $asserted
   *   When this fact became true in reality, as a Unix timestamp, if known
   *   and different from now. NULL means "same as created".
   * @param bool|null $trusted
   *   Explicit trusted value for this fact, bypassing
   *   aim.settings:default_trusted. NULL (the default) leaves the field
   *   unset so AimFact::getDefaultTrusted() applies the site's configured
   *   policy - the right fallback for every LLM/human-mixed caller the
   *   draft-to-trusted gate exists for. Pass TRUE only for curated data
   *   that was never subject to that gate in the first place, e.g. demo
   *   seed data.
   * @param string|null $targetType
   *   The referenced entity's type ID, for scope=entity (e.g. node).
   *   Ignored for every other scope.
   * @param string|null $targetId
   *   The referenced entity's ID, for scope=entity. Ignored for every
   *   other scope.
   * @param string|null $sourceText
   *   The text this fact was written from (a visitor's message, a
   *   document), for the grounded check (ADR-0037). Checked, never stored.
   *   NULL leaves the fact unchecked.
   * @param int|null $expires
   *   When this fact stops being true, as a Unix timestamp (ADR-0057).
   *   retireExpired() retires it once this passes. NULL means no end date.
   *   Must be in the future.
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The created fact.
   *
   * @throws \InvalidArgumentException
   *   If scope is invalid, scope=user and subject does not resolve to a real
   *   account, or the text fails a guardrail check.
   */
  public function remember(string $text, string $scope, ?string $subject = NULL, ?string $source = NULL, array $category = [], ?int $asserted = NULL, ?bool $trusted = NULL, ?string $targetType = NULL, ?string $targetId = NULL, ?string $sourceText = NULL, ?int $expires = NULL): AimFact {
    $allowed = $this->allowedScopes();
    if (!in_array($scope, $allowed, TRUE)) {
      throw new \InvalidArgumentException('Invalid scope "' . $scope . '", expected one of: ' . implode(', ', $allowed));
    }

    $values = [
      'scope' => $scope,
      'text' => $text,
      'source' => $source,
    ];

    if ($this->scopeRequiresAccount($scope)) {
      // A fact in a scope that requires a real account (ADR-0007,
      // aim_scope_user's requires_account setting for scope=user)
      // has to be about one:
      // subject is a genuine entity_reference (user), not a free-text string
      // that merely happens to hold a uid - that drift is exactly what let
      // "1" and "Nik" address the same person without ever matching.
      if (empty($subject)) {
        throw new \InvalidArgumentException('subject is required for scope=' . $scope . ': a uid of a real account on this site.');
      }
      $account = $this->resolveAccountByUid($subject);
      if (!$account) {
        throw new \InvalidArgumentException('No user account found for subject "' . $subject . '". A scope=' . $scope . ' fact must be about a real account.');
      }
      $values['user'] = $account->id();
      $values['subject'] = '';
    }
    elseif (empty($subject)) {
      // No subject given: ask this scope's access plugin (if any) for a
      // default instead of leaving the field empty - e.g. aim_scope_case's
      // plugin mints a new case ID.
      $plugin = $this->scopeAccessManager->getTypePlugin($scope);
      $values['subject'] = $plugin?->defaultSubject() ?? '';
    }
    else {
      $values['subject'] = $subject;
    }

    if (!empty($category)) {
      $values['category'] = $this->resolveCategoryTerms($category);
    }

    if ($asserted !== NULL) {
      $values['asserted'] = $asserted;
    }

    if ($expires !== NULL) {
      if ($expires <= $this->time->getRequestTime()) {
        throw new \InvalidArgumentException('Invalid expires ' . gmdate('Y-m-d', $expires) . ', it is already past.');
      }
      $values['expires'] = $expires;
    }

    if ($trusted !== NULL) {
      $values['trusted'] = $trusted;
    }

    $grounded = $sourceText !== NULL && $this->getGroundingMode() !== 'off';
    if ($grounded) {
      $values += $this->groundingValues($this->checkGrounding($sourceText, [$text])[0], $trusted);
    }

    if ($targetType !== NULL) {
      $values['target_type'] = $targetType;
    }

    if ($targetId !== NULL) {
      $values['target_id'] = $targetId;
    }

    /** @var \Drupal\aim\Entity\AimFact $entity */
    $entity = $this->entityTypeManager->getStorage('aim_fact')->create($values);
    try {
      $this->saveFact($entity);
    }
    catch (\InvalidArgumentException $e) {
      // The violation message can quote the rejected text, so it is not
      // logged.
      $this->logger->warning('Write rejected for scope @scope (uid @uid): failed validation or a guardrail.', [
        '@scope' => $scope,
        '@uid' => $this->currentUser->id(),
      ]);
      throw $e;
    }

    $this->logAudit('Fact @id written (scope @scope, subject @subject, uid @uid, trusted @trusted).', [
      '@id' => $entity->id(),
      '@scope' => $scope,
      '@subject' => $values['user'] ?? $values['subject'] ?? '',
      '@uid' => $this->currentUser->id(),
      '@trusted' => $entity->get('trusted')->value ? 'yes' : 'no',
    ]);
    if ($grounded) {
      $this->logGrounding($entity);
    }

    return $entity;
  }

  /**
   * Resolves category names against the aim_category vocabulary.
   *
   * Admin-curated, not model-invented (CLAUDE.md's "Ideas raised" section):
   * a name with no matching term is silently skipped rather than creating
   * one on the fly, same posture as resolveAccount() not fabricating an
   * account.
   *
   * @param string[] $names
   *   Category term names to resolve.
   *
   * @return int[]
   *   The matching term IDs. Names with no match are omitted.
   */
  protected function resolveCategoryTerms(array $names): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = [];
    foreach ($names as $name) {
      $name = trim($name);
      if ($name === '') {
        continue;
      }
      $terms = $storage->loadByProperties(['vid' => 'aim_category', 'name' => $name]);
      if ($terms) {
        $tids[] = (int) reset($terms)->id();
      }
    }
    return $tids;
  }

  /**
   * Creates aim_fact entities from candidate facts, e.g. from extractFacts().
   *
   * @param array $facts
   *   A list of ['scope' => ..., 'subject' => ..., 'text' => ...] arrays.
   * @param string $source
   *   Provenance tag stored on every created fact.
   * @param string|null $subjectUid
   *   A uid of a real account to attach to any candidate the model
   *   classifies as scope=user. See ADR-0011: extraction never
   *   resolves scope=user against the model's own freeform subject text -
   *   a document naming someone is no guarantee that person has an account
   *   on this site. NULL means no such account was supplied, so every
   *   scope=user candidate is skipped.
   * @param bool|null $trusted
   *   Explicit trusted value applied to every fact created from $facts,
   *   bypassing aim.settings:default_trusted. See remember()'s own
   *   $trusted docblock - NULL is the right default here too, since this
   *   method's callers are extraction paths the draft-to-trusted gate
   *   exists for.
   * @param string|null $sourceText
   *   The text the candidates were extracted from, for the grounded check
   *   (ADR-0037): one call for every candidate. Never stored. NULL leaves
   *   the facts unchecked.
   *
   * @return array
   *   An array with keys 'created' (\Drupal\aim\Entity\AimFact[]), 'skipped'
   *   (int, the number of user-scope candidates skipped because no
   *   $subjectUid was supplied), and 'blocked' (int, the number of
   *   candidates a guardrail rejected, per decision 7).
   *
   * @throws \InvalidArgumentException
   *   If $subjectUid is given but does not resolve to a real account.
   */
  public function createFactsFromCandidates(array $facts, string $source, ?string $subjectUid = NULL, ?bool $trusted = NULL, ?string $sourceText = NULL): array {
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    $created = [];
    $skipped = 0;
    $blocked = 0;

    $subjectAccount = NULL;
    if (!empty($subjectUid)) {
      $subjectAccount = $this->resolveAccountByUid($subjectUid);
      if (!$subjectAccount) {
        throw new \InvalidArgumentException('No user account found for subject-uid "' . $subjectUid . '". A user-scope fact must be about a real account.');
      }
    }

    $grounded = $sourceText !== NULL && $this->getGroundingMode() !== 'off';
    $scores = $grounded ? $this->checkGrounding($sourceText, array_column($facts, 'text')) : [];

    foreach (array_values($facts) as $i => $fact) {
      $values = [
        'text' => $fact['text'],
        'source' => $source,
      ];

      if ($this->scopeRequiresAccount($fact['scope'])) {
        // Never resolve the model's own freeform subject text against the
        // accounts table (ADR-0011) - a source document naming someone is
        // no guarantee that person is this site's user. Only a caller-
        // supplied $subjectAccount, resolved once above, can attach a
        // candidate in such a scope to a real account.
        if (!$subjectAccount) {
          $skipped++;
          continue;
        }
        $values['scope'] = $fact['scope'];
        $values['user'] = $subjectAccount->id();
        $values['subject'] = '';
      }
      else {
        $values['scope'] = $fact['scope'];
        $values['subject'] = $fact['subject'] ?? '';
      }

      if ($trusted !== NULL) {
        $values['trusted'] = $trusted;
      }
      if ($grounded) {
        $values += $this->groundingValues($scores[$i] ?? NULL, $trusted);
      }

      $entity = $storage->create($values);
      try {
        // Guardrail check runs inside save() now, via hook_aim_fact_presave()
        // - still per-candidate, since a rejected save throws before the
        // loop's next iteration. One rejected fact should not abort the
        // rest of an extraction batch, the same posture already taken for
        // an unresolvable user-scope subject above. saveFact() unwraps the
        // EntityStorageException SqlContentEntityStorage::save() wraps a
        // presave hook's exception in, back to the \InvalidArgumentException
        // this catch expects - see saveFact()'s own docblock.
        $this->saveFact($entity);
      }
      catch (\InvalidArgumentException) {
        $blocked++;
        $this->logger->warning('Extracted candidate rejected for scope @scope (uid @uid): failed validation or a guardrail.', [
          '@scope' => $fact['scope'],
          '@uid' => $this->currentUser->id(),
        ]);
        continue;
      }
      $created[] = $entity;
      $this->logAudit('Fact @id written from extraction (scope @scope, subject @subject, uid @uid, trusted @trusted).', [
        '@id' => $entity->id(),
        '@scope' => $fact['scope'],
        '@subject' => $values['user'] ?? $values['subject'],
        '@uid' => $this->currentUser->id(),
        '@trusted' => $entity->get('trusted')->value ? 'yes' : 'no',
      ]);
      if ($grounded) {
        $this->logGrounding($entity);
      }
    }

    if ($skipped > 0) {
      $this->logVerbose('Extraction skipped @skipped user-scope candidates: no subject-uid supplied.', ['@skipped' => $skipped]);
    }

    return ['created' => $created, 'skipped' => $skipped, 'blocked' => $blocked];
  }

  /**
   * Extracts facts from prose with a model and saves them.
   *
   * Shared by aim:extract and the ingest form (ADR-0016, mode 1).
   *
   * @param string $text
   *   The document text. Sent whole in one prompt, no chunking.
   * @param string $source
   *   Provenance tag stored on every created fact.
   * @param string|null $subjectUid
   *   A uid of a real account to attach scope=user candidates to. NULL skips
   *   them.
   * @param string|null $providerId
   *   The chat provider, or NULL for the extraction activity's configured one.
   * @param string|null $modelId
   *   The chat model, or NULL for the extraction activity's configured one.
   *
   * @return array
   *   The createFactsFromCandidates() result plus 'extracted' (int, how many
   *   candidates the model returned).
   *
   * @throws \InvalidArgumentException
   *   If $subjectUid does not resolve to a real account.
   * @throws \RuntimeException
   *   If no provider/model is given and none is configured.
   */
  public function ingestText(string $text, string $source, ?string $subjectUid = NULL, ?string $providerId = NULL, ?string $modelId = NULL): array {
    if (empty($providerId) || empty($modelId)) {
      $default = $this->getModelFor('extraction');
      if ($default === NULL) {
        throw new \RuntimeException('No extraction model is configured. Set one at /admin/config/ai/settings or in the AIM settings.');
      }
      $providerId = $default['provider_id'];
      $modelId = $default['model_id'];
    }

    $facts = $this->extractFacts(trim($text), $providerId, $modelId);
    if (empty($facts)) {
      return ['created' => [], 'skipped' => 0, 'blocked' => 0, 'extracted' => 0];
    }
    $result = $this->createFactsFromCandidates($facts, $source, $subjectUid ?: NULL, NULL, trim($text));
    $result['extracted'] = count($facts);
    return $result;
  }

  /**
   * Saves a list of fact entries through remember(), one failure at a time.
   *
   * Shared by aim:remember --file and the ingest form's JSON and one-fact-
   * per-line modes. One bad entry (missing text or scope, an invalid value,
   * a rejected guardrail) is recorded and skipped, never aborting the rest.
   *
   * @param array $entries
   *   Fact objects with the keys text, scope, subject, source,
   *   category (string or array), asserted, target_type, target_id. Unknown
   *   keys are ignored.
   * @param string|null $defaultSource
   *   The source for entries that name none.
   *
   * @return array
   *   An array with keys 'created' (\Drupal\aim\Entity\AimFact[] keyed by
   *   entry index) and 'errors' (string messages keyed by entry index).
   */
  public function rememberBatch(array $entries, ?string $defaultSource = NULL): array {
    $created = [];
    $errors = [];
    foreach ($entries as $i => $entry) {
      if (!is_array($entry) || empty($entry['text'])) {
        $errors[$i] = 'missing text';
        continue;
      }
      if (empty($entry['scope'])) {
        $errors[$i] = 'missing scope';
        continue;
      }
      try {
        [$category, $asserted] = $this->parseFactFields($entry);
        $created[$i] = $this->remember(
          $entry['text'],
          $entry['scope'],
          $entry['subject'] ?? NULL,
          $entry['source'] ?? $defaultSource,
          $category,
          $asserted,
          NULL,
          $entry['target_type'] ?? NULL,
          $entry['target_id'] ?? NULL,
          expires: $this->parseExpires($entry),
        );
      }
      catch (\InvalidArgumentException $e) {
        $errors[$i] = $e->getMessage();
      }
    }
    return ['created' => $created, 'errors' => $errors];
  }

  /**
   * Parses the category/asserted fields of a fact entry.
   *
   * @param array $fields
   *   Raw values, from a command line or a decoded JSON entry.
   *
   * @return array
   *   [$category, $asserted], typed as remember() expects them.
   *
   * @throws \InvalidArgumentException
   *   If asserted cannot be parsed.
   */
  public function parseFactFields(array $fields): array {
    $category = [];
    if (!empty($fields['category'])) {
      $category = is_array($fields['category']) ? $fields['category'] : explode(',', $fields['category']);
      $category = array_map('trim', $category);
    }

    $asserted = NULL;
    if (($fields['asserted'] ?? NULL) !== NULL) {
      $asserted = strtotime($fields['asserted']);
      if ($asserted === FALSE) {
        throw new \InvalidArgumentException('Invalid asserted "' . $fields['asserted'] . '", expected a date strtotime() can parse.');
      }
    }

    return [$category, $asserted];
  }

  /**
   * Parses the expires field of a fact entry.
   *
   * @param array $fields
   *   A fact entry or command options, possibly with an expires date.
   *
   * @return int|null
   *   A Unix timestamp (end of that day, UTC), or NULL when not given.
   *
   * @throws \InvalidArgumentException
   *   If expires cannot be parsed.
   */
  public function parseExpires(array $fields): ?int {
    $value = $fields['expires'] ?? NULL;
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $parsed = strtotime((string) $value . ' 23:59:59 UTC');
    if ($parsed === FALSE) {
      throw new \InvalidArgumentException('Invalid expires "' . $value . '", expected a calendar date such as 2026-10-16.');
    }
    return $parsed;
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

    $results = $this->executeSearchQuery($query, $accessCheck);

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

      $fact = $original->getValue();
      // The aim_exclude_retired processor keeps retired facts out of the
      // index (ADR-0022), but one retired since the last index run is
      // still there; filter it out here for that gap.
      if (!$fact->get('retired')->isEmpty()) {
        continue;
      }
      // A past-due fact is withheld before the expiry sweep retires it
      // (ADR-0057), so expiry holds to the date, not the next cron run.
      $expires = $fact->get('expires')->value;
      if ($expires !== NULL && (int) $expires <= $this->time->getRequestTime()) {
        continue;
      }

      // Tokens resolve as the viewing account at read time, so the value is
      // never stored in the fact. A fact naming a literal this viewer cannot
      // read is withheld whole: a sentence with a hole in it misleads, and
      // its presence would hint at the restricted value. The
      // show_redacted_facts debug setting shows it with [redacted] instead.
      $text = $this->literalReader->replaceTokens((string) $fact->get('text')->value, $this->currentUser, NULL, !$this->configFactory->get('aim.settings')->get('show_redacted_facts'));
      if ($text === NULL) {
        continue;
      }

      $rows[] = [
        'id' => $fact->id(),
        'score' => $result->getScore(),
        'scope' => $fact->bundle(),
        'subject' => $this->scopeRequiresAccount($fact->bundle()) ? $fact->get('user')->target_id : $fact->get('subject')->value,
        'text' => $text,
        'source' => $fact->get('source')->value,
        'trusted' => (bool) $fact->get('trusted')->value,
        'created' => (int) $fact->get('created')->value,
        'asserted' => $fact->get('asserted')->isEmpty() ? NULL : (int) $fact->get('asserted')->value,
      ];

      if (count($rows) >= $limit) {
        break;
      }
    }

    if ($gap && $rows) {
      $limitDistance = (float) $rows[0]['score'] + $gap;
      $rows = array_values(array_filter($rows, fn (array $row): bool => (float) $row['score'] <= $limitDistance));
    }

    $this->logVerbose('Recall returned @count rows (scope @scope, subject @subject, subject uid @subject_uid, limit @limit, best score @best, untrusted included @untrusted, uid @uid).', [
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
      $this->logVerbose('Recall query (best score @best): @query', [
        '@best' => $rows ? round((float) $rows[0]['score'], 3) : 'none',
        '@query' => $text,
      ]);
    }

    return $rows;
  }

  /**
   * Calls the configured chat provider and returns structured facts.
   *
   * The instruction prompt is aim.settings:extraction_prompt (admin-
   * editable at /admin/config/aim/settings), not hardcoded - only the
   * requested output shape below (the facts/scope/subject/text schema)
   * stays code-defined, since it's parsed by PHP downstream.
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
    $prompt = strtr($this->configFactory->get('aim.settings')->get('extraction_prompt'), [
      '{text}' => $text,
    ]);

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
                  // The live aim_scope bundles, not a literal list, so a
                  // scope added as config is extractable with no code
                  // change (see CLAUDE.md's "Scope as a config entity").
                  'enum' => $this->allowedScopes(),
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

    $this->logVerbose('Extraction returned @count candidate facts (provider @provider, model @model, uid @uid).', [
      '@count' => count($decoded['facts']),
      '@provider' => $providerId,
      '@model' => $modelId,
      '@uid' => $this->currentUser->id(),
    ]);

    return $decoded['facts'];
  }

}
