<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\aim\Backend\ActivityBackendInterface;

/**
 * The aim:status check that ai_logging records every aim model call.
 *
 * Split out of AimStatusChecker: it reads only one config object, so it
 * tests without the checker's database and provider dependencies.
 */
final class AimModelCallLoggingCheck {

  /**
   * Checks that ai_logging, when on, records every aim model call.
   *
   * The settings are site-wide and aim does not write them (ADR-0060), so
   * logging off is a site's choice and passes. On, it fails when a tag in
   * ActivityBackendInterface::LOGGED_TAGS would not be recorded (the tag list
   * is an exact match, and an empty list records every call), when replies
   * are not recorded, or when entries are pruned before the 90 days eval
   * work wants.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $settings
   *   The ai_logging.settings config.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  public static function check(ImmutableConfig $settings): array {
    if (!$settings->get('prompt_logging')) {
      return [
        'label' => 'Model-call logging',
        'ok' => TRUE,
        'detail' => 'off, model prompts and replies are not recorded - turn on at /admin/config/ai/logging/settings if wanted (DEVELOPING.md "Model-call logging")',
      ];
    }

    // Split and normalize the way ai_logging's shouldLoggingHappen() does.
    $split = static fn (?string $list): array => array_values(array_filter(
      array_map(static fn (string $tag): string => strtolower(trim($tag)), explode(',', (string) $list)),
      static fn (string $tag): bool => $tag !== '',
    ));
    $included = $split($settings->get('prompt_logging_tags'));
    $excluded = $split($settings->get('prompt_logging_excluded_tags'));
    $missing = array_filter(
      ActivityBackendInterface::LOGGED_TAGS,
      static fn (string $tag): bool => in_array($tag, $excluded, TRUE) || ($included && !in_array($tag, $included, TRUE)),
    );
    $maxAge = (int) $settings->get('prompt_logging_max_age');

    $problems = [];
    if ($missing) {
      $problems[] = 'not recorded: ' . implode(', ', $missing) . ' - add to "Restrict automated logging by request tags" and take out of the excluded tags';
    }
    if (!$settings->get('prompt_logging_output')) {
      $problems[] = 'replies are not recorded - turn on "Automatically log responses"';
    }
    if ($maxAge > 0 && $maxAge < 90) {
      $problems[] = "entries are kept $maxAge day(s), under the 90 eval work wants";
    }

    $captured = $included ? 'on, all ' . count(ActivityBackendInterface::LOGGED_TAGS) . ' aim tags recorded' : 'on for every model call';
    $kept = $maxAge > 0 ? "$maxAge day(s)" : 'with no age limit';

    return [
      'label' => 'Model-call logging',
      'ok' => !$problems,
      'detail' => $problems
        ? implode('; ', $problems) . ' - fix at /admin/config/ai/logging/settings'
        : "$captured, kept $kept",
    ];
  }

}
