<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Decides a new fact's starting trust and what the grounded check may change.
 *
 * ADR-0037's 2026-10-18 addendum. The entry channel is named by the code
 * that writes (a CHANNEL_* constant), never by the caller-supplied source
 * tag, which anyone can set to anything. A writer who could trust the fact
 * by hand starts it trusted whatever the channel (2026-10-22 addendum).
 */
class AimTrustPolicy {

  /**
   * Written trusted; the grounded check (enforce mode) may demote it.
   */
  public const TRUSTED = 'trusted';

  /**
   * Written untrusted; the grounded check (enforce mode) may promote it.
   */
  public const UNTRUSTED = 'untrusted';

  /**
   * Written untrusted and never promoted by the check; a person clears it.
   */
  public const HOLD = 'hold';

  /**
   * The chat assistant's remember tool.
   */
  public const CHANNEL_CHAT = 'chat';

  /**
   * The Tool API and MCP remember tool.
   */
  public const CHANNEL_MCP = 'mcp';

  /**
   * The ingest form (prose, JSON and one-fact-per-line).
   */
  public const CHANNEL_INGEST = 'ingest';

  /**
   * The Drush commands (aim:remember, aim:extract).
   */
  public const CHANNEL_DRUSH = 'drush';

  /**
   * The channels a policy can name.
   */
  public const CHANNELS = [
    self::CHANNEL_CHAT,
    self::CHANNEL_MCP,
    self::CHANNEL_INGEST,
    self::CHANNEL_DRUSH,
  ];

  /**
   * Constructs an AimTrustPolicy object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for aim.settings.
   * @param \Drupal\aim\Service\AimSettings $settings
   *   The settings reader, for the grounded check's mode and threshold.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected AimSettings $settings,
  ) {}

  /**
   * Whether an account may trust a fact in a scope by hand.
   *
   * The same permissions the Trust/Untrust actions check
   * (AimFactAccessControlHandler's trust operation).
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param string $scope
   *   The fact's scope ID.
   *
   * @return bool
   *   TRUE with administer aim, review aim facts or the scope's trust
   *   permission.
   */
  public function canApprove(AccountInterface $account, string $scope): bool {
    return $account->hasPermission('administer aim')
      || $account->hasPermission('review aim facts')
      || $account->hasPermission('trust ' . $scope . ' aim facts');
  }

  /**
   * Resolves the policy for a new fact.
   *
   * A writer who canApprove() gets TRUSTED. Otherwise the channel's rule
   * wins, then the scope's, then the default. A default left empty follows
   * the older default_trusted switch.
   *
   * @param string|null $channel
   *   A CHANNEL_* constant, or NULL for a caller that names none.
   * @param string $scope
   *   The fact's scope ID.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account writing the fact.
   *
   * @return string
   *   TRUSTED, UNTRUSTED or HOLD.
   */
  public function resolve(?string $channel, string $scope, AccountInterface $account): string {
    if ($this->canApprove($account, $scope)) {
      return self::TRUSTED;
    }
    $config = $this->configFactory->get('aim.settings');
    $policy = $config->get('trust_policy') ?? [];
    foreach ([$policy['channels'][$channel] ?? NULL, $policy['scopes'][$scope] ?? NULL, $policy['default'] ?? NULL] as $rule) {
      if (in_array($rule, [self::TRUSTED, self::UNTRUSTED, self::HOLD], TRUE)) {
        return $rule;
      }
    }
    return $config->get('default_trusted') ? self::TRUSTED : self::UNTRUSTED;
  }

  /**
   * Field values a policy and a grounding score set on a new fact.
   *
   * @param string $policy
   *   The resolve() result.
   * @param bool $checked
   *   Whether the grounded check ran (it needs source text and a mode).
   * @param float|null $score
   *   The check's score, NULL when it failed to run.
   *
   * @return array
   *   trusted, plus grounding_score when checked. In shadow mode the score
   *   is only recorded. In enforce mode a trusted-policy fact is demoted
   *   below the threshold (or when the check failed: fail closed), and an
   *   untrusted-policy fact is promoted at or above it. Hold never moves.
   */
  public function values(string $policy, bool $checked, ?float $score): array {
    $values = ['trusted' => $policy === self::TRUSTED];
    if (!$checked) {
      return $values;
    }

    $values['grounding_score'] = $score;
    if ($this->settings->getGroundingMode() === 'enforce' && $policy !== self::HOLD) {
      $values['trusted'] = $score !== NULL && $score >= $this->settings->getGroundingThreshold();
    }

    return $values;
  }

}
