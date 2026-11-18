<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai\AiProviderPluginManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads aim's admin-editable settings.
 *
 * Config is the only source: a key in REQUIRED_KEYS that is missing is an
 * error, not a silent default.
 *
 * Thresholds, the model chosen per activity, and the grounded check's mode
 * and cutoff. Split out of AimMemoryManager, which still delegates to it for
 * existing callers.
 */
class AimSettings {

  /**
   * The aim.settings keys that have no default in code.
   *
   * Each ships in config/install/aim.settings.yml, with where its value came
   * from; a getter throws when one is missing, and aim:status lists any that
   * are.
   */
  public const REQUIRED_KEYS = [
    'compare_distance',
    'neighbor_limit',
    'recall_max_distance',
    'activities.verifier.threshold',
    'activities.grounding.threshold',
    'activities.grounding.time_threshold',
    'activities.grounding.max_questions',
  ];

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
   * Returns the live comparison distance from aim.settings.
   *
   * Shared by the CLI sweep and the queue worker so the two can't drift.
   * Pairs within it are compared: identical text is retired with no model
   * call, any other pair gets one classification call (ADR-0036's
   * 2026-10-27 addendum).
   *
   * @return float
   *   Distance at or below which a neighbor is compared.
   */
  public function getCompareDistance(): float {
    return (float) $this->required('compare_distance');
  }

  /**
   * Returns how many nearest neighbors consolidation compares a fact to.
   *
   * Each neighbor within the comparison distance can cost one
   * classification call, so this bounds the worst-case cost per fact.
   *
   * @return int
   *   At least 1.
   */
  public function getNeighborLimit(): int {
    return max(1, (int) $this->required('neighbor_limit'));
  }

  /**
   * Returns the live recall cutoff from aim.settings.
   *
   * Applied by callers that must abstain on a poor match: aim_tool's
   * aim_recall and aim_chatbot's recall-context subscriber pass it to
   * recall() as $maxDistance; recall() itself does not apply it unless asked.
   *
   * @return float
   *   Distance above which a recalled fact is too dissimilar to present as
   *   relevant.
   */
  public function getRecallMaxDistance(): float {
    return (float) $this->required('recall_max_distance');
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
   * Whether the aim_recall tool also asks the literals finder.
   *
   * So a chat model asking for a phone number or a web address gets the
   * exact value from the one recall tool, without having to choose a second
   * lookup tool (ADR-0061's addendum). Has no effect without literals_finder.
   *
   * @return bool
   *   aim.settings:recall_literals. Off when the key is missing (a site
   *   installed before it existed): set it to turn the lookup on.
   */
  public function isRecallLiteralsOn(): bool {
    return (bool) $this->configFactory->get('aim.settings')->get('recall_literals');
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
   * Returns the grounded check's mode: off, record or demote.
   *
   * Record stores each fact's score and changes nothing else; demote also
   * saves a fact scoring below the threshold untrusted (ADR-0065).
   *
   * @return string
   *   The mode, off when unset or unknown.
   */
  public function getGroundingMode(): string {
    $mode = $this->configFactory->get('aim.settings')->get('grounding_mode');
    return in_array($mode, ['record', 'demote'], TRUE) ? $mode : 'off';
  }

  /**
   * Whether the grounded check runs: a mode other than off and a model.
   *
   * @return bool
   *   TRUE when grounding_mode is record or demote and a decision model is
   *   chosen for activities.grounding.
   */
  public function isGroundingOn(): bool {
    return $this->getGroundingMode() !== 'off' && $this->decisionModelFor('grounding') !== NULL;
  }

  /**
   * Returns the score at or above which a fact counts as grounded.
   *
   * @return float
   *   aim.settings:activities.grounding.threshold.
   */
  public function getGroundingThreshold(): float {
    return (float) $this->required('activities.grounding.threshold');
  }

  /**
   * Returns the probability a decision-model verifier must exceed.
   *
   * @return float
   *   aim.settings:activities.verifier.threshold. Read only when the
   *   verifier runs on a decision model.
   */
  public function getVerifierThreshold(): float {
    return (float) $this->required('activities.verifier.threshold');
  }

  /**
   * Returns how many candidates one grounded-check call may carry.
   *
   * @return int
   *   aim.settings:activities.grounding.max_questions, at least 1.
   */
  public function getGroundingMaxQuestions(): int {
    return max(1, (int) $this->required('activities.grounding.max_questions'));
  }

  /**
   * Returns the time check's mode: off, flag or reject.
   *
   * The check asks, per candidate, whether it is only true for a date or
   * period (ADR-0057 addendum). Flag sends such a fact without an end date
   * to review (and has aim_remember ask its caller for one); reject refuses
   * it on the paths with no caller to ask.
   *
   * @return string
   *   The mode, off when unset or unknown.
   */
  public function getTimeCheckMode(): string {
    $mode = $this->configFactory->get('aim.settings')->get('time_check_mode');
    return in_array($mode, ['flag', 'reject'], TRUE) ? $mode : 'off';
  }

  /**
   * Whether the time check runs: a mode other than off and a model.
   *
   * It uses the grounded check's decision model, so grounding itself may be
   * off.
   *
   * @return bool
   *   TRUE when time_check_mode is flag or reject and a decision model is
   *   chosen for activities.grounding.
   */
  public function isTimeCheckOn(): bool {
    return $this->getTimeCheckMode() !== 'off' && $this->decisionModelFor('grounding') !== NULL;
  }

  /**
   * Returns the score at or above which a fact counts as time-bound.
   *
   * @return float
   *   aim.settings:activities.grounding.time_threshold.
   */
  public function getTimeCheckThreshold(): float {
    return (float) $this->required('activities.grounding.time_threshold');
  }

  /**
   * Returns the REQUIRED_KEYS a getter reads with today's settings.
   *
   * A threshold for a check that is off is never read, so a chat-only site
   * may leave the decision-model keys out without anything failing.
   *
   * @return string[]
   *   The keys, in REQUIRED_KEYS order.
   */
  public function requiredKeysInUse(): array {
    $checks = $this->isGroundingOn() || $this->isTimeCheckOn();
    $in_use = [
      'activities.verifier.threshold' => ($this->configFactory->get('aim.settings')->get('merge_verify') ?? TRUE) && $this->decisionModelFor('verifier') !== NULL,
      'activities.grounding.threshold' => $this->isGroundingOn(),
      'activities.grounding.time_threshold' => $this->isTimeCheckOn(),
      'activities.grounding.max_questions' => $checks,
    ];
    return array_values(array_filter(self::REQUIRED_KEYS, fn (string $key): bool => $in_use[$key] ?? TRUE));
  }

  /**
   * Returns a key from REQUIRED_KEYS, failing when it is missing.
   *
   * @param string $key
   *   The aim.settings key, dotted for a nested value.
   *
   * @return mixed
   *   The configured value.
   *
   * @throws \RuntimeException
   *   When the key is not set.
   */
  protected function required(string $key): mixed {
    $value = $this->configFactory->get('aim.settings')->get($key);
    if ($value === NULL) {
      throw new \RuntimeException(sprintf('aim.settings:%s is not set. aim ships it in config/install/aim.settings.yml; set it on /admin/config/aim/settings or with drush config:set.', $key));
    }
    return $value;
  }

}
