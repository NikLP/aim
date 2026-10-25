<?php

declare(strict_types=1);

namespace Drupal\aim_tool;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;

/**
 * Says what each installed scope is for, for a model choosing one.
 *
 * The one source of the "which scope when" wording: the tools' scope input
 * descriptions (AimToolHooks::toolInfoAlter()) and the MCP server
 * instructions (aim_tool_oauth) both read it, and aim_tool's README carries
 * the same text for people. Only installed scopes are listed; a scope with
 * no entry here is listed by its label. Expected to move onto each scope's
 * own config when scopes are reworked.
 */
final class AimScopeGuide {

  /**
   * One line per known scope: what it is for, and what its subject is.
   */
  private const GUIDE = [
    'site' => 'true about the organization for everyone who asks, e.g. opening hours or a fees policy. No subject.',
    'role' => 'guidance for everyone holding one site role, e.g. "editors must add alt text to every image". Subject: the role machine name, e.g. content_editor.',
    'user' => 'about one person, by default the one you are talking to, e.g. how they like answers formatted. Subject: their account (uid); omit it for yourself. Never save a user fact about someone else without confirming who it is about.',
    'case' => 'one piece of ongoing work with a start and an end, e.g. a site redesign. Subject: the case ID. Omit it on the first save and a new ID comes back; pass that ID to add more to the same case.',
    'entity' => 'about one specific piece of content, e.g. a page or a product. Needs the content type and ID in scope_fields.',
  ];

  /**
   * A few words per known scope, for a one-line list.
   */
  private const SHORT = [
    'site' => 'about the organization',
    'role' => 'guidance for a site role',
    'user' => 'about one person',
    'case' => 'one piece of ongoing work',
    'entity' => 'about one piece of content',
  ];

  /**
   * Constructs the scope guide.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service; aim_fact's bundles are the installed scopes.
   */
  public function __construct(
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * The installed scopes as one line, e.g. "site (about the organization)".
   *
   * @return string
   *   The scopes, comma-separated, each with its short meaning.
   */
  public function scopeList(): string {
    $items = [];
    foreach ($this->scopes() as $id => $label) {
      $items[] = $id . ' (' . (self::SHORT[$id] ?? $label) . ')';
    }
    return implode(', ', $items);
  }

  /**
   * The installed scopes, one line each, with what they are for.
   *
   * @return string
   *   Lines of the form "- site: ...", newline-separated.
   */
  public function guide(): string {
    $lines = [];
    foreach ($this->scopes() as $id => $label) {
      $lines[] = '- ' . $id . ': ' . (self::GUIDE[$id] ?? $label . '.');
    }
    return implode("\n", $lines);
  }

  /**
   * The installed scopes, known ones first in GUIDE order (site first).
   *
   * @return array<string, string>
   *   Labels, keyed by scope ID.
   */
  private function scopes(): array {
    $scopes = [];
    foreach ($this->bundleInfo->getBundleInfo('aim_fact') as $id => $info) {
      $scopes[$id] = (string) ($info['label'] ?? $id);
    }
    $order = array_flip(array_keys(self::GUIDE));
    $rank = fn (string $id): array => [$order[$id] ?? PHP_INT_MAX, $id];
    uksort($scopes, fn (string $a, string $b): int => $rank($a) <=> $rank($b));
    return $scopes;
  }

}
