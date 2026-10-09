<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Component\Datetime\TimeInterface;
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
use Drupal\ai\OperationType\Decision\Value\ChoiceQuestion;
use Drupal\ai\OperationType\Decision\Value\NoulQuestion;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
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
   *   The current user, used by executeSearchQuery() to tell a real
   *   authenticated caller (Tool API/MCP, an interactive admin) apart from
   *   an anonymous one (drush, cron) - only the latter needs the
   *   search_api_bypass_access treatment that method exists for.
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
   * ("create {scope} aim facts", with "administer aim memory" as bypass)
   * for a caller outside a normal entity form context. Added so aim_tool's
   * aim_remember Tool plugin can check it too - previously that plugin
   * only checked the flat "store aim memory" permission and never this
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
   *   One of extraction, consolidation or verifier.
   *
   * @return array|null
   *   An array with keys 'provider_id', 'model_id' and 'backend', or NULL if
   *   neither a choice nor a usable default is configured.
   */
  public function getModelFor(string $activity): ?array {
    $choice = $this->configFactory->get('aim.settings')->get('activities.' . $activity) ?? [];
    $backend = ($choice['backend'] ?? 'chat') === 'decision' && $activity !== 'extraction' ? 'decision' : 'chat';
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
   *   One of consolidation or verifier.
   *
   * @return array|null
   *   A [provider_id, model_id] pair, or NULL when the activity uses the
   *   chat backend or has no decision model chosen.
   */
  protected function decisionModelFor(string $activity): ?array {
    $choice = $this->getModelFor($activity);
    return ($choice['backend'] ?? NULL) === 'decision' ? [$choice['provider_id'], $choice['model_id']] : NULL;
  }

  /**
   * Runs a search_api query, bypassing access only for an anonymous caller.
   *
   * Drush (and cron) runs as the anonymous user by default, which has no
   * view access to aim_fact, so the AI Search backend's per-result entity
   * access check would silently drop every match. Previously "fixed" by
   * account-switching to uid 1 for the query duration - dropped 2026-09-18
   * as unsound, not just imperfect: Drupal core has no special-cased uid-1
   * bypass (confirmed by reading \Drupal\Core\Session\PermissionChecker
   * directly - it evaluates roles/permissions like any other account) and
   * no storage-layer protection against uid 1 being deleted (confirmed by
   * reading \Drupal\user\Entity\User - the only guard is a UI form check, a
   * direct delete or drush user:cancel bypasses it), so "elevate to uid 1"
   * both could throw outright (the RuntimeException this method used to
   * carry for exactly that case) and, even when it didn't, provided no real
   * guarantee of admin access - purely a site-configuration accident.
   *
   * Explicit search_api_bypass_access (SearchApiAiSearchBackend::search(),
   * confirmed by reading it directly) is the correct replacement, not a
   * downgrade: a drush/cron caller already has raw database credentials, so
   * gating search_api's result set behind entity access checks a real
   * per-scope permission it could trivially route around with
   * `drush sql:query` is not a real security boundary in this context to
   * begin with. See ADR-0006's 2026-09-18 addendum for the full reversal
   * and why the ADR originally rejected this.
   *
   * A real authenticated caller (aim_tool's Tool API/MCP plugins, an
   * interactive admin) already has an account of its own to query as - it
   * must run the query as itself, not bypass access, so ai_search's own
   * per-result $entity->access('view', $account) check
   * (SearchApiAiSearchBackend::checkEntityAccess()) applies the real
   * per-scope permission AimFactAccessControlHandler enforces, the same
   * one the admin UI already relies on - no new filtering logic needed
   * here.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query to execute.
   *
   * @return \Drupal\search_api\Query\ResultSetInterface
   *   The query results.
   */
  public function executeSearchQuery(SearchApiQueryInterface $query): ResultSetInterface {
    if ($this->currentUser->isAnonymous()) {
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
   * @param bool|null $state
   *   Optional boolean flag value. NULL for facts with no boolean shape.
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
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The created fact.
   *
   * @throws \InvalidArgumentException
   *   If scope is invalid, scope=user and subject does not resolve to a real
   *   account, or the text fails a guardrail check.
   */
  public function remember(string $text, string $scope, ?string $subject = NULL, ?string $source = NULL, ?bool $state = NULL, array $category = [], ?int $asserted = NULL, ?bool $trusted = NULL, ?string $targetType = NULL, ?string $targetId = NULL): AimFact {
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

    if ($state !== NULL) {
      $values['state'] = $state;
    }

    if (!empty($category)) {
      $values['category'] = $this->resolveCategoryTerms($category);
    }

    if ($asserted !== NULL) {
      $values['asserted'] = $asserted;
    }

    if ($trusted !== NULL) {
      $values['trusted'] = $trusted;
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
  public function createFactsFromCandidates(array $facts, string $source, ?string $subjectUid = NULL, ?bool $trusted = NULL): array {
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

    foreach ($facts as $fact) {
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
    $result = $this->createFactsFromCandidates($facts, $source, $subjectUid ?: NULL);
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
   *   Fact objects with the keys text, scope, subject, source, state,
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
        [$state, $category, $asserted] = $this->parseFactFields($entry);
        $created[$i] = $this->remember(
          $entry['text'],
          $entry['scope'],
          $entry['subject'] ?? NULL,
          $entry['source'] ?? $defaultSource,
          $state,
          $category,
          $asserted,
          NULL,
          $entry['target_type'] ?? NULL,
          $entry['target_id'] ?? NULL,
        );
      }
      catch (\InvalidArgumentException $e) {
        $errors[$i] = $e->getMessage();
      }
    }
    return ['created' => $created, 'errors' => $errors];
  }

  /**
   * Parses the state/category/asserted fields of a fact entry.
   *
   * @param array $fields
   *   Raw values, from a command line or a decoded JSON entry.
   *
   * @return array
   *   [$state, $category, $asserted], typed as remember() expects them.
   *
   * @throws \InvalidArgumentException
   *   If state or asserted cannot be parsed.
   */
  public function parseFactFields(array $fields): array {
    $state = NULL;
    if (($fields['state'] ?? NULL) !== NULL) {
      $state = filter_var($fields['state'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if ($state === NULL) {
        throw new \InvalidArgumentException('Invalid state "' . $fields['state'] . '", expected true or false.');
      }
    }

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

    return [$state, $category, $asserted];
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
   *
   * @return array
   *   A list of rows, each with keys id, score, scope, subject, text,
   *   source, state, trusted, created, asserted (a timestamp, or NULL when
   *   never set; see formatFactLine()). Retired facts are never returned, so
   *   there is no expires key. score is what search_api reports for the
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
  public function recall(string $text, ?string $scope, ?string $subject, ?string $subjectUid, int $limit, ?float $maxDistance = NULL, bool $includeUntrusted = FALSE, ?float $gap = NULL): array {
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

    $results = $this->executeSearchQuery($query);

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
        // exists (deleted directly, or by consolidation's DELETE
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
      if (!$fact->get('expires')->isEmpty()) {
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
        'state' => $fact->get('state')->value,
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
   * Reviews existing facts for near-duplicates and merges or retires them.
   *
   * See CLAUDE.md's "Consolidation" section for the full algorithm and the
   * empirical threshold defaults. Each fact is compared to its nearest
   * vector neighbor within the same scope/subject: an obvious near-duplicate
   * is retired automatically, an ambiguous case gets a single classification
   * call (ADD/UPDATE/DELETE/NOOP), and anything past the ambiguous
   * threshold is left alone at zero cost. Retiring a fact sets its
   * `expires` field rather than deleting it, to keep an audit trail; an
   * UPDATE creates a new merged fact and retires both inputs, and every
   * retirement records its reason in `superseded_by_reason`. An
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
   *   An array with keys 'rows' (each a [kept id, candidate id,
   *   score, decision, merged text] tuple) and 'has_facts' (bool, whether
   *   any un-expired facts existed to sweep at all).
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

    // Pairs already compared (so A-B is not classified again from B's side)
    // and facts retired so far this sweep (a dry run changes nothing in the
    // database, so the sweep tracks retirements itself).
    $handled_pairs = [];
    $retired = [];
    $rows = [];

    foreach ($fact_storage->loadMultiple($ids) as $fact) {
      if (isset($retired[$fact->id()])) {
        continue;
      }

      $rows = array_merge($rows, $this->consolidateAgainstNeighbors($index, $fact, $providerId, $modelId, $autoThreshold, $ambiguousThreshold, $dryRun, $handled_pairs, $retired));
    }

    return ['rows' => $rows, 'has_facts' => TRUE];
  }

  /**
   * Runs a single fact against its nearest neighbors and applies decisions.
   *
   * The per-fact counterpart to consolidate()'s sweep: used by
   * AimConsolidateQueueWorker to process one newly-written fact, enqueued
   * by enqueueForConsolidation() (decision 4's Queue API automation). No
   * pair registry is needed here the way consolidate()'s sweep needs
   * one - each queue item is processed and saved independently, so a later
   * item sees an already-`expires`-set fact and findNeighbors()
   * already filters those out.
   *
   * Up to NEIGHBOR_LIMIT neighbors are checked, nearest first: a
   * contradiction is not always the single nearest fact, so stopping at
   * one let it through unseen. Checking stops early once the fact itself
   * is retired.
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
   * @return array
   *   A list of [kept id, candidate id, score, decision, merged text]
   *   tuples, one per neighbor compared; empty if no eligible neighbor
   *   was found.
   *
   * @throws \RuntimeException
   *   If the vector index does not exist.
   */
  public function consolidateFact(AimFact $fact, string $providerId, string $modelId, float $autoThreshold, float $ambiguousThreshold): array {
    $index = $this->loadVectorIndex();
    if (!$index) {
      throw new \RuntimeException('The aim_vector_index search index does not exist.');
    }

    $handled_pairs = [];
    $retired = [];
    return $this->consolidateAgainstNeighbors($index, $fact, $providerId, $modelId, $autoThreshold, $ambiguousThreshold, FALSE, $handled_pairs, $retired);
  }

  /**
   * Compares one fact to its nearest neighbors and applies each decision.
   *
   * Shared by consolidate()'s sweep and consolidateFact(). Neighbors are
   * fetched once, nearest first, so a retirement part-way through cannot
   * change the list; the loop stops as soon as the fact itself is retired
   * (a retired fact has nothing left to compare), and carries on after an
   * ADD or after retiring the neighbor.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The vector index.
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact to compare.
   * @param string $providerId
   *   The AI provider plugin ID to use for ambiguous cases.
   * @param string $modelId
   *   The chat model ID to use for ambiguous cases.
   * @param float $autoThreshold
   *   Score at or below which a neighbor is retired automatically.
   * @param float $ambiguousThreshold
   *   Score above which a neighbor is not compared at all.
   * @param bool $dryRun
   *   If TRUE, decisions are computed but nothing is saved.
   * @param array $handledPairs
   *   Pair keys ("lowid:highid") already compared; updated by reference.
   * @param array $retired
   *   Fact IDs retired so far, as keys; updated by reference.
   *
   * @return array
   *   A list of [kept id, candidate id, score, decision, merged text]
   *   tuples.
   */
  protected function consolidateAgainstNeighbors(IndexInterface $index, AimFact $fact, string $providerId, string $modelId, float $autoThreshold, float $ambiguousThreshold, bool $dryRun, array &$handledPairs, array &$retired): array {
    $rows = [];

    $neighbors = $this->findNeighbors($index, $fact, $retired, $ambiguousThreshold, $this->getNeighborLimit());
    foreach ($neighbors as [$neighbor, $score]) {
      $ids = [(int) $fact->id(), (int) $neighbor->id()];
      sort($ids);
      $pair = implode(':', $ids);
      if (isset($handledPairs[$pair])) {
        continue;
      }
      $handledPairs[$pair] = TRUE;

      // Lower id is the established fact, higher id the newer restatement
      // being evaluated against it.
      $kept = $fact->id() < $neighbor->id() ? $fact : $neighbor;
      $candidate = $fact->id() < $neighbor->id() ? $neighbor : $fact;

      [$decision, $merged_text] = $this->decideAndApply($kept, $candidate, $score, $autoThreshold, $providerId, $modelId, $dryRun);
      $rows[] = [$kept->id(), $candidate->id(), round($score, 3), $decision, $merged_text];

      if (in_array($decision, ['NOOP', 'DELETE'], TRUE)) {
        $retired[$candidate->id()] = TRUE;
      }
      elseif ($decision === 'UPDATE') {
        $retired[$kept->id()] = TRUE;
        $retired[$candidate->id()] = TRUE;
      }

      if (isset($retired[$fact->id()])) {
        break;
      }
    }

    return $rows;
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
   * @return array
   *   A [decision, merged text] pair: the decision is ADD, UPDATE, DELETE,
   *   NOOP, or the synthetic BLOCKED; the text is NULL unless the decision
   *   is UPDATE, BLOCKED, or an ADD downgraded from a merge that failed
   *   verification.
   */
  protected function decideAndApply(AimFact $kept, AimFact $candidate, float $score, float $autoThreshold, string $providerId, string $modelId, bool $dryRun): array {
    if ($score <= $autoThreshold) {
      $decision = 'NOOP';
      $merged_text = NULL;
    }
    else {
      [$decision, $merged_text] = $this->classifyPair($kept, $candidate, $providerId, $modelId);
      if ($decision === 'UPDATE') {
        try {
          $merged_text = $this->runGuardrails((string) $merged_text);
        }
        catch (\InvalidArgumentException) {
          $decision = 'BLOCKED';
        }
      }
      // Guardrails check policy, not fidelity: a merge that does not
      // demonstrably keep both inputs' details is downgraded to ADD (keep
      // both), since merging is where nuance is lost.
      $unfaithful = FALSE;
      if ($decision === 'UPDATE') {
        $failed = $this->verifyMerge($kept, $candidate, (string) $merged_text, $providerId, $modelId);
        if ($failed !== NULL) {
          $decision = 'ADD';
          $unfaithful = TRUE;
          $this->logAudit('Consolidation merge rejected by @check check (scope @scope, kept @kept, candidate @candidate), downgraded to ADD.', [
            '@check' => $failed,
            '@scope' => $kept->bundle(),
            '@kept' => $kept->id(),
            '@candidate' => $candidate->id(),
          ]);
        }
      }
    }

    // Only an UPDATE, a BLOCKED merge or a merge rejected as unfaithful has
    // merged text worth reporting.
    $reported_text = in_array($decision, ['UPDATE', 'BLOCKED'], TRUE) || !empty($unfaithful) ? $merged_text : NULL;

    if ($dryRun) {
      return [$decision, $reported_text];
    }

    $model = $score <= $autoThreshold ? NULL : [$providerId, $modelId];
    $this->logConsolidation($kept, $candidate, $score, $decision, $model);

    $reason = $this->buildSupersedeReason($decision, $score, $model);
    switch ($decision) {
      case 'UPDATE':
        // Non-destructive merge: a new fact carries the merged text and
        // both inputs are retired pointing at it, so the old wording stays
        // auditable and either input can be un-retired.
        $merged = $this->createMergedFact($kept, $candidate, (string) $merged_text);
        foreach ([$kept, $candidate] as $input) {
          $input->set('expires', $this->time->getRequestTime());
          $input->set('superseded_by', $merged->id());
        }
        $candidate->set('superseded_by_reason', $reason);
        $kept->save();
        $candidate->save();
        break;

      case 'NOOP':
        $candidate->set('expires', $this->time->getRequestTime());
        $candidate->set('superseded_by', $kept->id());
        $candidate->set('superseded_by_reason', $reason);
        $candidate->save();
        break;

      case 'DELETE':
        // Soft retire with no replacement: superseded_by stays empty, the
        // reason records why. Reversible with the un-retire action.
        $candidate->set('expires', $this->time->getRequestTime());
        $candidate->set('superseded_by_reason', $reason);
        $candidate->save();
        break;

      case 'ADD':
        // Genuinely distinct facts, nothing to change.
        break;

      case 'BLOCKED':
        // A guardrail rejected the proposed merged text; leave both
        // facts exactly as they were, same as ADD.
        break;
    }

    return [$decision, $reported_text];
  }

  /**
   * Verifies that a merged text is faithful to both inputs.
   *
   * Two independent checks, either of which fails the merge. The model
   * check asks a verifier model whether every detail of both inputs
   * survives and nothing was invented; the verifier is
   * aim.settings:activities.verifier when set, so a different model can check
   * the first, else the classifier's own model.
   * The distance check requires the merged text's embedding to sit within
   * aim.settings:merge_max_distance of each input (0 disables it). Both
   * check faithfulness to the inputs, not real-world truth. A verifier
   * error counts as a failure: the safe outcome is keeping both facts.
   *
   * @param \Drupal\aim\Entity\AimFact $kept
   *   The established fact.
   * @param \Drupal\aim\Entity\AimFact $candidate
   *   The newer fact.
   * @param string $mergedText
   *   The guardrail-checked merged text.
   * @param string $providerId
   *   The classifier's provider, the verifier fallback.
   * @param string $modelId
   *   The classifier's model, the verifier fallback.
   *
   * @return string|null
   *   NULL if the merge passed, else the failed check: model, distance or
   *   error.
   */
  protected function verifyMerge(AimFact $kept, AimFact $candidate, string $mergedText, string $providerId, string $modelId): ?string {
    $config = $this->configFactory->get('aim.settings');

    $max_distance = (float) $config->get('merge_max_distance');
    if ($max_distance > 0) {
      try {
        $merged = $this->embedText($mergedText);
        foreach ([$kept, $candidate] as $input) {
          if ($this->cosineDistance($merged, $this->embedText((string) $input->get('text')->value)) > $max_distance) {
            return 'distance';
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Merge distance check failed to run: @message', ['@message' => $e->getMessage()]);
        return 'error';
      }
    }

    if ($config->get('merge_verify') ?? TRUE) {
      $verifier = $config->get('activities.verifier');
      if (($verifier['backend'] ?? 'chat') === 'chat' && !empty($verifier['provider']) && !empty($verifier['model'])) {
        $providerId = $verifier['provider'];
        $modelId = $verifier['model'];
      }
      try {
        if (!$this->modelConfirmsMerge($kept, $candidate, $mergedText, $providerId, $modelId)) {
          return 'model';
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Merge verifier failed to run: @message', ['@message' => $e->getMessage()]);
        return 'error';
      }
    }

    return NULL;
  }

  /**
   * Asks a model whether a merged text keeps every detail of both inputs.
   *
   * @param \Drupal\aim\Entity\AimFact $kept
   *   The established fact.
   * @param \Drupal\aim\Entity\AimFact $candidate
   *   The newer fact.
   * @param string $mergedText
   *   The merged text to check.
   * @param string $providerId
   *   The AI provider plugin ID.
   * @param string $modelId
   *   The chat model ID.
   *
   * @return bool
   *   TRUE if the model confirms the merge is faithful.
   *
   * @throws \RuntimeException
   *   If the response is not the expected JSON shape.
   */
  protected function modelConfirmsMerge(AimFact $kept, AimFact $candidate, string $mergedText, string $providerId, string $modelId): bool {
    $decision_model = $this->decisionModelFor('verifier');
    if ($decision_model !== NULL) {
      $input = new DecisionInput(
        [
          'fact_a' => (string) $kept->get('text')->value,
          'fact_b' => (string) $candidate->get('text')->value,
          'merged' => $mergedText,
        ],
        ['faithful' => new NoulQuestion('Every detail of fact_a and fact_b (names, numbers, dates, conditions, negations) survives in merged, and merged adds nothing the two facts do not say. Judge faithfulness to the inputs, not whether they are true.')],
      );
      $response = $this->decisionBackend->run('verifier', $input, $decision_model[0], $decision_model[1]);
      $threshold = (float) ($this->configFactory->get('aim.settings')->get('activities.verifier.threshold') ?? 0);
      return $response->getNoul('faithful')->isLikely($threshold > 0 ? $threshold : 0.5);
    }

    $prompt = strtr($this->configFactory->get('aim.settings')->get('merge_verify_prompt'), [
      '{kept_text}' => $kept->get('text')->value,
      '{candidate_text}' => $candidate->get('text')->value,
      '{merged_text}' => $mergedText,
    ]);

    $schema = new StructuredOutputSchema(
      name: 'aim_merge_verification',
      description: 'Whether a merged fact is faithful to the two facts it replaces.',
      strict: TRUE,
      json_schema: [
        'type' => 'object',
        'additionalProperties' => FALSE,
        'properties' => [
          'faithful' => ['type' => 'boolean'],
        ],
        'required' => ['faithful'],
      ],
    );

    $input = new ChatInput([new ChatMessage('user', $prompt)]);
    $input->setChatStructuredJsonSchema($schema);

    $output = $this->aiProvider->createInstance($providerId)->chat($input, $modelId, ['aim_consolidate_verify']);
    $response_text = $output->getNormalized()->getText();
    $decoded = json_decode($response_text, TRUE);
    if (!is_array($decoded) || !isset($decoded['faithful'])) {
      throw new \RuntimeException("Verifier response was not the expected JSON shape: $response_text");
    }
    return $decoded['faithful'] === TRUE;
  }

  /**
   * Embeds a text with the vector server's own embeddings engine.
   *
   * @param string $text
   *   The text to embed.
   *
   * @return float[]
   *   The embedding vector.
   *
   * @throws \RuntimeException
   *   If the index or its embeddings engine is not configured.
   */
  protected function embedText(string $text): array {
    $index = $this->loadVectorIndex();
    $backend = $index?->getServerInstance()->getBackendConfig() ?? [];
    $engine = (string) ($backend['embeddings_engine'] ?? '');
    if (!str_contains($engine, '__')) {
      throw new \RuntimeException('The vector server has no embeddings engine configured.');
    }
    [$provider_id, $model_id] = explode('__', $engine, 2);
    $config = $backend['embeddings_engine_configuration'] ?? [];
    $provider = $this->aiProvider->createInstance($provider_id);
    if (!empty($config['set_dimensions']) && !empty($config['dimensions'])) {
      $provider->setConfiguration(['dimensions' => (int) $config['dimensions']] + $provider->getConfiguration());
    }
    return $provider->embeddings($text, $model_id, ['aim_consolidate_verify'])->getNormalized();
  }

  /**
   * Computes the cosine distance between two vectors.
   *
   * @param float[] $a
   *   The first vector.
   * @param float[] $b
   *   The second vector.
   *
   * @return float
   *   1 minus cosine similarity: 0 identical, larger less similar.
   */
  protected function cosineDistance(array $a, array $b): float {
    $dot = 0.0;
    $norm_a = 0.0;
    $norm_b = 0.0;
    foreach ($a as $i => $value) {
      $dot += $value * ($b[$i] ?? 0.0);
      $norm_a += $value * $value;
      $norm_b += ($b[$i] ?? 0.0) ** 2;
    }
    if ($norm_a == 0.0 || $norm_b == 0.0) {
      return 1.0;
    }
    return 1.0 - $dot / (sqrt($norm_a) * sqrt($norm_b));
  }

  /**
   * Builds the provenance string stored in superseded_by_reason.
   *
   * Compact JSON, never fact text. Kept as one small helper alongside
   * logConsolidation() so both move together if decideAndApply() changes.
   *
   * @param string $decision
   *   UPDATE, DELETE or NOOP (the decisions that retire a fact).
   * @param float $score
   *   The similarity score between the pair.
   * @param array|null $model
   *   A [provider ID, model ID] pair if the model decided, NULL if the
   *   auto threshold did.
   *
   * @return string
   *   The JSON reason.
   */
  protected function buildSupersedeReason(string $decision, float $score, ?array $model): string {
    return json_encode([
      'decision' => $decision,
      'by' => $model ? 'model' : 'auto',
      'score' => round($score, 3),
      'provider' => $model[0] ?? NULL,
      'model' => $model[1] ?? NULL,
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Creates the new fact an UPDATE merge produces.
   *
   * Identity (scope, subject/user, target) comes from the kept fact, since
   * both inputs share it. Metadata is reconciled deliberately: text is the
   * guardrail-checked merge; asserted is the candidate's only; state and
   * source prefer the candidate (the newer statement) and fall back to the
   * kept fact;
   * category is the union; trusted only if both inputs were; the owner is
   * the kept fact's.
   *
   * @param \Drupal\aim\Entity\AimFact $kept
   *   The established fact.
   * @param \Drupal\aim\Entity\AimFact $candidate
   *   The newer fact.
   * @param string $mergedText
   *   The guardrail-checked merged text.
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The saved merged fact.
   */
  protected function createMergedFact(AimFact $kept, AimFact $candidate, string $mergedText): AimFact {
    $values = [
      'scope' => $kept->bundle(),
      'text' => $mergedText,
      'uid' => $kept->getOwnerId(),
      'trusted' => (bool) $kept->get('trusted')->value && (bool) $candidate->get('trusted')->value,
    ];
    // Copy whichever identity fields this fact has: subject/user always
    // exist, target_* only where aim_scope_entity is installed.
    foreach (['subject', 'user', 'target_type', 'target_id'] as $field) {
      if ($kept->hasField($field)) {
        $values[$field] = $kept->get($field)->getValue();
      }
    }
    // The asserted field is the candidate's only: the kept fact's valid-time
    // start belongs to the older wording, not the merged statement.
    if (!$candidate->get('asserted')->isEmpty()) {
      $values['asserted'] = $candidate->get('asserted')->value;
    }
    foreach (['state', 'source'] as $field) {
      $source = !$candidate->get($field)->isEmpty() ? $candidate : $kept;
      if (!$source->get($field)->isEmpty()) {
        $values[$field] = $source->get($field)->value;
      }
    }
    $categories = [];
    foreach ([$kept, $candidate] as $input) {
      foreach ($input->get('category') as $item) {
        $categories[(int) $item->target_id] = ['target_id' => (int) $item->target_id];
      }
    }
    if ($categories) {
      $values['category'] = array_values($categories);
    }

    /** @var \Drupal\aim\Entity\AimFact $merged */
    $merged = $this->entityTypeManager->getStorage('aim_fact')->create($values);
    $this->saveFact($merged);
    return $merged;
  }

  /**
   * Logs one applied consolidation outcome.
   *
   * Kept as one small helper so decideAndApply() can be reworked without
   * touching the logging. BLOCKED is a warning and always logged; every
   * other decision is an audit event. Never logs fact text.
   *
   * @param \Drupal\aim\Entity\AimFact $kept
   *   The established fact.
   * @param \Drupal\aim\Entity\AimFact $candidate
   *   The newer fact evaluated against it.
   * @param float $score
   *   The similarity score between the two.
   * @param string $decision
   *   ADD, UPDATE, DELETE, NOOP or BLOCKED.
   * @param array|null $model
   *   A [provider ID, model ID] pair if the model decided, NULL if the
   *   auto threshold did.
   */
  protected function logConsolidation(AimFact $kept, AimFact $candidate, float $score, string $decision, ?array $model): void {
    $message = 'Consolidation @decision (scope @scope, kept @kept, candidate @candidate, score @score, decided by @by).';
    $context = [
      '@decision' => $decision,
      '@scope' => $kept->bundle(),
      '@kept' => $kept->id(),
      '@candidate' => $candidate->id(),
      '@score' => round($score, 3),
      '@by' => $model ? 'model ' . $model[0] . '/' . $model[1] : 'auto',
    ];
    if ($decision === 'BLOCKED') {
      $this->logger->warning($message, $context);
      return;
    }
    $this->logAudit($message, $context);
  }

  /**
   * Finds the closest indexed neighbors to a fact, nearest first.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The vector index to query.
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact to find neighbors for.
   * @param array $handled
   *   Fact IDs to skip (already retired this run), keyed by ID.
   * @param float $maxScore
   *   Neighbors scoring above this distance are left out.
   * @param int $limit
   *   The most neighbors to return.
   *
   * @return array
   *   A list of [neighbor fact, score] pairs, possibly empty.
   */
  protected function findNeighbors(IndexInterface $index, AimFact $fact, array $handled, float $maxScore, int $limit): array {
    $scope = $fact->bundle();
    $is_user_scope = $this->scopeRequiresAccount($scope);

    $query = $index->query()->keys($fact->get('text')->value);
    $query->addCondition('scope', $scope);
    if ($is_user_scope) {
      $user = $fact->get('user')->target_id;
      if ($user === NULL) {
        // A scope that requires a real account with no account set has no
        // neighbors to compare.
        return [];
      }
      $query->addCondition('user', (int) $user);
    }
    else {
      $subject = $fact->get('subject')->value;
      if ($subject !== NULL && $subject !== '') {
        $query->addCondition('subject', $subject);
      }
    }
    // Headroom for the fact itself and already-handled ids, which are
    // skipped below.
    $query->range(0, $is_user_scope ? max(20, $limit + 4) : $limit + 4);

    $neighbors = [];
    $results = $this->executeSearchQuery($query);
    foreach ($results as $result) {
      // Rows arrive nearest-first, so the first one past the cutoff ends
      // the useful part of the list.
      if ((float) $result->getScore() > $maxScore) {
        break;
      }

      try {
        $original = $result->getOriginalObject();
      }
      catch (SearchApiException) {
        // Same stale-index-entry case recall() guards against above - a
        // neighbor candidate that was deleted since being indexed.
        continue;
      }

      if ($original === NULL) {
        continue;
      }

      $candidate = $original->getValue();
      if ((int) $candidate->id() === (int) $fact->id() || isset($handled[$candidate->id()])) {
        continue;
      }

      // The aim_exclude_retired processor keeps retired facts out of the
      // index (ADR-0022), but one retired since the last index run is
      // still there and would otherwise resurface as a neighbor.
      if (!$candidate->get('expires')->isEmpty()) {
        continue;
      }

      $neighbors[] = [$candidate, (float) $result->getScore()];
      if (count($neighbors) >= $limit) {
        break;
      }
    }

    return $neighbors;
  }

  /**
   * Asks the chat provider to classify a candidate against a kept fact.
   *
   * The instruction prompt is aim.settings:consolidation_prompt
   * (admin-editable at /admin/config/aim/settings), not hardcoded - only
   * the requested output shape below (the decision/merged_text schema)
   * stays code-defined, since it's parsed by PHP downstream.
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
    $decision_model = $this->decisionModelFor('consolidation');
    if ($decision_model !== NULL) {
      $input = new DecisionInput(
        [
          'existing' => (string) $kept->get('text')->value,
          'candidate' => (string) $candidate->get('text')->value,
        ],
        [
          'decision' => new ChoiceQuestion('Decide what to do with the candidate fact relative to the existing fact. When unsure, choose ADD: keeping both facts is always safe.', [
            'ADD' => 'The facts are genuinely different; keep both.',
            'UPDATE' => 'The candidate refines, corrects or supersedes the existing fact.',
            'NOOP' => 'The candidate restates the existing fact with no new information.',
            'DELETE' => 'The candidate should not exist as a memory at all (nonsensical or clearly erroneous). Use sparingly.',
          ]),
        ],
      );
      $choice = $this->decisionBackend->run('consolidation', $input, $decision_model[0], $decision_model[1])->getChoice('decision')->getChoice();
      if ($choice === 'UPDATE') {
        // A decision model only judges; a chat model writes the merge, and
        // the verifier then checks it like any other merge.
        $merged = $this->writeMerge($kept, $candidate);
        if ($merged === NULL) {
          $this->logger->warning('Decision classifier chose UPDATE but no default chat provider can write the merge; keeping both facts.');
          return ['ADD', NULL];
        }
        return [$choice, $merged];
      }
      return [$choice, NULL];
    }

    $prompt = strtr($this->configFactory->get('aim.settings')->get('consolidation_prompt'), [
      '{kept_text}' => $kept->get('text')->value,
      '{candidate_text}' => $candidate->get('text')->value,
    ]);

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
   * Asks the site default chat model to merge two facts into one sentence.
   *
   * Used when the classifier is a decision model, which cannot write text.
   * The result is checked by the guardrails and verifier like any merge.
   *
   * @param \Drupal\aim\Entity\AimFact $kept
   *   The established fact.
   * @param \Drupal\aim\Entity\AimFact $candidate
   *   The newer fact.
   *
   * @return string|null
   *   The merged text, or NULL if no default chat provider is configured.
   *
   * @throws \RuntimeException
   *   If the reply is malformed.
   */
  protected function writeMerge(AimFact $kept, AimFact $candidate): ?string {
    $default = $this->getDefaultChatProvider();
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      return NULL;
    }
    $prompt = strtr($this->configFactory->get('aim.settings')->get('merge_prompt'), [
      '{kept_text}' => $kept->get('text')->value,
      '{candidate_text}' => $candidate->get('text')->value,
    ]);
    $schema = new StructuredOutputSchema(
      name: 'aim_merge_text',
      description: 'The merged fact text.',
      strict: TRUE,
      json_schema: [
        'type' => 'object',
        'additionalProperties' => FALSE,
        'properties' => ['merged_text' => ['type' => 'string']],
        'required' => ['merged_text'],
      ],
    );
    $input = new ChatInput([new ChatMessage('user', $prompt)]);
    $input->setChatStructuredJsonSchema($schema);
    $output = $this->aiProvider->createInstance($default['provider_id'])->chat($input, $default['model_id'], ['aim_consolidate_merge']);
    $decoded = json_decode($output->getNormalized()->getText(), TRUE);
    if (!is_array($decoded) || !is_string($decoded['merged_text'] ?? NULL) || trim($decoded['merged_text']) === '') {
      throw new \RuntimeException('Merge writer reply was not the expected JSON shape.');
    }
    return trim($decoded['merged_text']);
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
