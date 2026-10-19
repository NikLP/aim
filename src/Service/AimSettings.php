<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads aim's admin-editable settings, with shipped defaults as fallbacks.
 *
 * Thresholds, the model chosen per activity, and the grounded check's mode
 * and cutoff. Split out of AimMemoryManager, which still delegates to it for
 * existing callers.
 */
class AimSettings {

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
   * The grounding cutoff when activities.grounding.threshold is unset.
   *
   * Hosted Jev split supported from unsupported candidates at about 0.76 on
   * the 44-item eval set (tuned on that set, so optimistic).
   */
  protected const GROUNDING_DEFAULT_THRESHOLD = 0.7;

  /**
   * Candidates per grounded-check call when none is configured (subq's cap).
   */
  protected const GROUNDING_MAX_QUESTIONS = 16;

  /**
   * Constructs an AimSettings object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager, for the default chat provider.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'ai.provider')]
    protected AiProviderPluginManager $aiProvider,
  ) {}

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
   * Returns how many candidates one grounded-check call may carry.
   *
   * @return int
   *   aim.settings:activities.grounding.max_questions, or the shipped
   *   default (16, subq's limit) when unset or below 1.
   */
  public function getGroundingMaxQuestions(): int {
    $max = (int) ($this->configFactory->get('aim.settings')->get('activities.grounding.max_questions') ?? 0);
    return $max > 0 ? $max : self::GROUNDING_MAX_QUESTIONS;
  }

}
