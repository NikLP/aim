<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\Guardrail\AiGuardrailRepository;
use Drupal\ai\Guardrail\Result\StopResult;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
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
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The entity type bundle info service, used to validate scope against
   *   aim_fact's code-defined bundles (see aim.module's
   *   hook_entity_bundle_info()) instead of a hardcoded list, so a module
   *   registering an additional scope via hook_entity_bundle_info_alter()
   *   passes validation here automatically.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID service, used to mint a new case ID for a scope=case fact
   *   with no caller-supplied subject, so every caller (MCP client, drush,
   *   a future ECA action) gets the same format for free instead of each
   *   needing to invent and agree on its own.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to read aim.settings' admin-editable
   *   consolidation thresholds and extraction/consolidation prompts (see
   *   /admin/config/aim/settings, Drupal\aim\Form\AimSettingsForm).
   */
  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountSwitcherInterface $accountSwitcher,
    protected TimeInterface $time,
    protected AiGuardrailRepository $guardrailRepository,
    protected QueueFactory $queueFactory,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected UuidInterface $uuid,
    protected ConfigFactoryInterface $configFactory,
  ) {}

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
   * aim_consolidate's crontab entry next runs (CLAUDE.md's "Consolidation"
   * section). The only real cost this leaves is one embedding-API call per
   * fact, at reindex() time. Every created fact is tagged $runTag as its
   * source so deleteBenchmarkFacts() can find and remove exactly this run's
   * data afterward.
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
    $allowed = $this->allowedScopes();
    if (!in_array($scope, $allowed, TRUE)) {
      throw new \InvalidArgumentException('Invalid scope "' . $scope . '", expected one of: ' . implode(', ', $allowed));
    }
    if ($scope === 'user' && empty($subjectUids)) {
      throw new \InvalidArgumentException('scope=user needs at least one real account ID in $subjectUids.');
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
      if ($scope === 'user') {
        $values['subject_uid'] = $subjectUids[$i % count($subjectUids)];
      }

      /** @var \Drupal\aim\Entity\AimFact $entity */
      $entity = $storage->create($values);
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
   *   real account on this site. Ignored for site scope. For scope=case, an
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
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The created fact.
   *
   * @throws \InvalidArgumentException
   *   If scope is invalid, scope=user and subject does not resolve to a real
   *   account, or the text fails a guardrail check.
   */
  public function remember(string $text, string $scope, ?string $subject, ?string $source, ?bool $state, array $category = [], ?int $asserted = NULL): AimFact {
    $allowed = $this->allowedScopes();
    if (!in_array($scope, $allowed, TRUE)) {
      throw new \InvalidArgumentException('Invalid scope "' . $scope . '", expected one of: ' . implode(', ', $allowed));
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
    elseif ($scope === 'case' && empty($subject)) {
      // No subject given for a new case-scoped fact: mint one here so
      // every caller (MCP client, drush, a future ECA action) gets the
      // same format for free instead of needing to agree on one. subject
      // is the only field this touches - source stays whatever provenance
      // the caller already passes, untouched by this.
      $values['subject'] = 'case-' . substr($this->uuid->generate(), 0, 8);
    }
    else {
      $values['subject'] = $subject ?? '';
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

    /** @var \Drupal\aim\Entity\AimFact $entity */
    $entity = $this->entityTypeManager->getStorage('aim_fact')->create($values);
    $entity->save();
    $this->enqueueForConsolidation((int) $entity->id());

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
   *   A uid or username of a real account to attach to any candidate the
   *   model classifies as scope=user. See ADR-0011: extraction never
   *   resolves scope=user against the model's own freeform subject text -
   *   a document naming someone is no guarantee that person has an account
   *   on this site. NULL means no such account was supplied, so every
   *   scope=user candidate is skipped.
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
  public function createFactsFromCandidates(array $facts, string $source, ?string $subjectUid = NULL): array {
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    $created = [];
    $skipped = 0;
    $blocked = 0;

    $subjectAccount = NULL;
    if (!empty($subjectUid)) {
      $subjectAccount = $this->resolveAccount($subjectUid);
      if (!$subjectAccount) {
        throw new \InvalidArgumentException('No user account found for subject-uid "' . $subjectUid . '". A user-scope fact must be about a real account.');
      }
    }

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
        // Never resolve the model's own freeform subject text against the
        // accounts table (ADR-0011) - a source document naming someone is
        // no guarantee that person is this site's user. Only a caller-
        // supplied $subjectAccount, resolved once above, can attach a
        // scope=user candidate to a real account.
        if (!$subjectAccount) {
          $skipped++;
          continue;
        }
        $values['scope'] = 'user';
        $values['subject_uid'] = $subjectAccount->id();
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
        'scope' => $fact->bundle(),
        'subject' => $fact->bundle() === 'user' ? $fact->get('subject_uid')->target_id : $fact->get('subject')->value,
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
    $scope = $fact->bundle();
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
