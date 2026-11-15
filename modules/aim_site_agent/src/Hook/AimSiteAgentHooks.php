<?php

declare(strict_types=1);

namespace Drupal\aim_site_agent\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\aim\Entity\AimScope;
use Drupal\site_agent\Entity\ToolCallInterface;
use Drupal\user\UserInterface;

/**
 * Shows aim_remember's facts as a list on Site Agent's approval cards.
 *
 * Site Agent lists a call's arguments as they come, so a remember call's
 * facts would show as one raw value. An aim_remember card gets its own
 * template (a suggestion of site_agent_card) showing each fact's text as a
 * heading with one line of its scope, subject and end date under it. It
 * changes only how the card looks: the decision still covers the whole call
 * and the stored payload runs as is.
 */
class AimSiteAgentHooks {

  use StringTranslationTrait;

  /**
   * The suggestion an aim_remember card renders with.
   */
  public const SUGGESTION = 'site_agent_card__aim_remember';

  /**
   * Constructs the hook implementations.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the call record, scopes and accounts.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      self::SUGGESTION => [
        'base hook' => 'site_agent_card',
        'template' => 'site-agent-card--aim-remember',
      ],
    ];
  }

  /**
   * Implements hook_theme_suggestions_HOOK_alter() for site_agent_card.
   *
   * @param array $suggestions
   *   The suggestions so far.
   * @param array $variables
   *   The card's variables.
   */
  #[Hook('theme_suggestions_site_agent_card_alter')]
  public function themeSuggestionsSiteAgentCardAlter(array &$suggestions, array $variables): void {
    if (($variables['tool_id'] ?? '') === 'aim_remember') {
      $suggestions[] = self::SUGGESTION;
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for site_agent_card.
   *
   * Gives an aim_remember card facts (each with text and a line of its
   * scope, subject and end date) and, when the call quotes one, its source
   * text in place of the tool description written for the model.
   *
   * @param array $variables
   *   The card's template variables.
   */
  #[Hook('preprocess_site_agent_card')]
  public function preprocessSiteAgentCard(array &$variables): void {
    if (($variables['tool_id'] ?? '') !== 'aim_remember' || empty($variables['record'])) {
      return;
    }
    $record = $this->entityTypeManager->getStorage('site_agent_tool_call')->load($variables['record']);
    if (!$record instanceof ToolCallInterface) {
      return;
    }
    $arguments = $record->getArguments();
    $facts = $arguments['facts'] ?? NULL;
    if (!is_array($facts) || $facts === []) {
      return;
    }

    $variables['facts'] = [];
    foreach ($facts as $fact) {
      $fact = is_array($fact) ? $fact : [];
      $variables['facts'][] = [
        'text' => (string) ($fact['text'] ?? $this->t('No text given')),
        'details' => implode(' - ', $this->details($fact)),
      ];
    }

    // The tool's own description is written for the model; the words the
    // facts came from tell the person more.
    $source = $arguments['source_text'] ?? '';
    if (is_string($source) && $source !== '') {
      $variables['description'] = $this->t('From: "@source"', ['@source' => $source]);
    }
  }

  /**
   * Lists a fact's details: its scope, subject, extra fields and end date.
   *
   * @param array $fact
   *   One entry of the call's facts.
   *
   * @return string[]
   *   Each detail as "label: value".
   */
  private function details(array $fact): array {
    $details = [];
    $scope_id = (string) ($fact['scope'] ?? '');
    $scope = $scope_id === '' ? NULL : $this->entityTypeManager->getStorage('aim_scope')->load($scope_id);
    $details[] = $this->t('scope: @scope', ['@scope' => $scope instanceof AimScope ? $scope->label() : ($scope_id ?: $this->t('none given'))]);

    $subject = (string) ($fact['subject'] ?? '');
    if ($scope instanceof AimScope && $scope->get('plugin') === 'user') {
      $details[] = $this->t('about: @who', ['@who' => $subject === '' ? $this->t('the account saving it') : $this->accountLabel($subject)]);
    }
    elseif ($subject !== '') {
      $details[] = $this->t('subject: @subject', ['@subject' => $subject]);
    }

    $fields = $fact['scope_fields'] ?? [];
    if (is_array($fields)) {
      foreach ($fields as $name => $value) {
        if ($value !== NULL && $value !== '') {
          $details[] = $name . ': ' . (is_scalar($value) ? (string) $value : json_encode($value));
        }
      }
    }

    $expires = (string) ($fact['expires'] ?? '');
    if ($expires !== '') {
      $details[] = $this->t('expires: @expires', ['@expires' => $expires === 'open-ended' ? $this->t('open-ended') : $expires]);
    }
    return array_map('strval', $details);
  }

  /**
   * Names the account a user fact is about.
   *
   * @param string $uid
   *   The subject as given: a uid.
   *
   * @return string
   *   The account's display name and uid, or the uid alone when no such
   *   account exists.
   */
  private function accountLabel(string $uid): string {
    $account = ctype_digit($uid) ? $this->entityTypeManager->getStorage('user')->load($uid) : NULL;
    if (!$account instanceof UserInterface) {
      return (string) $this->t('@uid (no such account)', ['@uid' => $uid]);
    }
    return (string) $this->t('@name (uid @uid)', ['@name' => $account->getDisplayName(), '@uid' => $uid]);
  }

}
