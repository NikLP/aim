<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\Value\ChoiceQuestion;
use Drupal\ai\OperationType\Decision\Value\NoulQuestion;
use Drupal\aim\Backend\ActivityBackendInterface;
use Drupal\aim\Entity\AimFact;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;

/**
 * Consolidates aim facts: finds near-duplicates and contradictions, merges.
 *
 * Split out of AimMemoryManager (which keeps loading, saving, guardrails,
 * access and recall). Nothing else in the manager calls into this class.
 * See CLAUDE.md decision 4 and the consolidation ADRs.
 */
class ConsolidationService {

  /**
   * Constructs a ConsolidationService object.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $manager
   *   The memory manager, for loading, saving, settings and search.
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The aim logger channel.
   * @param \Drupal\aim\Backend\ActivityBackendInterface $decisionBackend
   *   The decision backend.
   */
  public function __construct(
    protected AimMemoryManager $manager,
    protected AiProviderPluginManager $aiProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
    protected ActivityBackendInterface $decisionBackend,
  ) {}

  /**
   * Reviews existing facts for near-duplicates and merges or retires them.
   *
   * See CLAUDE.md's "Consolidation" section for the full algorithm and the
   * empirical threshold defaults. Each fact is compared to its nearest
   * vector neighbor within the same scope/subject: an obvious near-duplicate
   * is retired automatically, an ambiguous case gets a single classification
   * call (ADD/UPDATE/RETIRE/NOOP), and anything past the ambiguous
   * threshold is left alone at zero cost. Retiring a fact sets its
   * `retired` field rather than deleting it, to keep an audit trail; an
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
    $index = $this->manager->loadVectorIndex();
    if (!$index) {
      throw new \RuntimeException('The aim_vector_index search index does not exist.');
    }

    $fact_storage = $this->entityTypeManager->getStorage('aim_fact');
    $query = $fact_storage->getQuery()->accessCheck(FALSE)->sort('id')->notExists('retired');

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
   * item sees an already-`retired`-set fact and findNeighbors()
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
    $index = $this->manager->loadVectorIndex();
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

    $neighbors = $this->findNeighbors($index, $fact, $retired, $ambiguousThreshold, $this->manager->getNeighborLimit());
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

      if (in_array($decision, ['NOOP', 'RETIRE'], TRUE)) {
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
   *   A [decision, merged text] pair: the decision is ADD, UPDATE, RETIRE,
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
          $merged_text = $this->manager->runGuardrails((string) $merged_text);
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
          $this->manager->logAudit('Consolidation merge rejected by @check check (scope @scope, kept @kept, candidate @candidate), downgraded to ADD.', [
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
          $input->set('retired', $this->time->getRequestTime());
          $input->set('superseded_by', $merged->id());
        }
        $candidate->set('superseded_by_reason', $reason);
        $kept->save();
        $candidate->save();
        break;

      case 'NOOP':
        $candidate->set('retired', $this->time->getRequestTime());
        $candidate->set('superseded_by', $kept->id());
        $candidate->set('superseded_by_reason', $reason);
        $candidate->save();
        break;

      case 'RETIRE':
        // Soft retire with no replacement: superseded_by stays empty, the
        // reason records why. Reversible with the un-retire action.
        $candidate->set('retired', $this->time->getRequestTime());
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
    $decision_model = $this->manager->decisionModelFor('verifier');
    if ($decision_model !== NULL) {
      $input = new DecisionInput(
        [
          'fact_a' => (string) $kept->get('text')->value,
          'fact_b' => (string) $candidate->get('text')->value,
          'merged' => $mergedText,
        ],
        ['faithful' => new NoulQuestion('Every detail of fact_a and fact_b (names, numbers, dates, conditions, negations) survives in merged, and merged adds nothing the two facts do not say, except that a value in fact_b which differs from the value fact_a gives for the same attribute (a duration, day, price or place) supersedes it, so the old value should be absent. Judge faithfulness to the inputs, not whether they are true.')],
      );
      $response = $this->decisionBackend->run('verifier', $input, $decision_model[0], $decision_model[1]);
      $threshold = (float) ($this->configFactory->get('aim.settings')->get('activities.verifier.threshold') ?? 0);
      $cutoff = $threshold > 0 ? $threshold : 0.5;
      $faithful = $response->getNoul('faithful');
      $this->manager->logVerbose('Merge verifier (kept @kept, candidate @candidate): faithful probability @score, cutoff @cutoff.', [
        '@kept' => $kept->id(),
        '@candidate' => $candidate->id(),
        '@score' => round($faithful->getProbability(), 3),
        '@cutoff' => $cutoff,
      ]);
      return $faithful->isLikely($cutoff);
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
    $index = $this->manager->loadVectorIndex();
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
   *   UPDATE, RETIRE or NOOP (the decisions that retire a fact).
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
   * guardrail-checked merge; asserted is the candidate's only; source
   * prefers the candidate (the newer statement) and falls back to the
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
    foreach (['source'] as $field) {
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
    $this->manager->saveFact($merged);
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
   *   ADD, UPDATE, RETIRE, NOOP or BLOCKED.
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
    $this->manager->logAudit($message, $context);
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
    $is_user_scope = $this->manager->scopeRequiresAccount($scope);

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
    // Consolidation compares against every fact in the scope, whoever
    // triggered it, and returns nothing to a viewer.
    $results = $this->manager->executeSearchQuery($query, accessCheck: FALSE);
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
      if (!$candidate->get('retired')->isEmpty()) {
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
   *   RETIRE, NOOP. merged_text is only meaningful for UPDATE.
   */
  protected function classifyPair(AimFact $kept, AimFact $candidate, string $providerId, string $modelId): array {
    $decision_model = $this->manager->decisionModelFor('consolidation');
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
            'RETIRE' => 'The candidate should not exist as a memory at all (nonsensical or clearly erroneous). Use sparingly.',
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
            'enum' => ['ADD', 'UPDATE', 'RETIRE', 'NOOP'],
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

    $decision = $decoded['decision'];
    $merged = $decoded['merged_text'] ?? NULL;
    // The model sometimes labels a changed value ADD while returning a
    // rewritten merged_text, contradicting the prompt (unchanged for any
    // decision but UPDATE). The rewrite is the stronger signal: treat it as
    // an UPDATE and let the merge verifier gate it like any other.
    if ($decision === 'ADD' && is_string($merged) && trim($merged) !== '') {
      $norm = static fn (string $text): string => mb_strtolower(preg_replace('/\s+/', ' ', trim($text)));
      if ($norm($merged) !== $norm((string) $kept->get('text')->value)) {
        $decision = 'UPDATE';
      }
    }
    return [$decision, $merged];
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
    $default = $this->manager->getDefaultChatProvider();
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

}
