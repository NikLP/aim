<?php

declare(strict_types=1);

namespace Drupal\aim_tool_oauth\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\aim_tool\AimScopeGuide;

/**
 * Hook implementations for the aim_tool_oauth module.
 */
class AimToolOauthHooks {

  /**
   * Constructs the hook implementations.
   *
   * @param \Drupal\aim_tool\AimScopeGuide $scopeGuide
   *   The scope guide.
   */
  public function __construct(
    private readonly AimScopeGuide $scopeGuide,
  ) {}

  /**
   * Implements hook_mcp_server_instructions_alter().
   *
   * Appends how to use aim's tools to the instructions an MCP client gets
   * at the handshake, after any text the site sets in
   * mcp_server.settings:server_instructions. Built here rather than shipped
   * as that config, which is mcp_server's and per site, so a rebuild keeps
   * it and a site's own text is never overwritten.
   *
   * @param string|null $instructions
   *   The instructions so far, or NULL when the site sets none.
   */
  #[Hook('mcp_server_instructions_alter')]
  public function mcpServerInstructionsAlter(?string &$instructions): void {
    $aim = <<<TEXT
    This server is AIM, the site's long-term memory: short facts that staff have approved, not conversation transcripts.

    - At the start of work, call aim_brief once for the newest facts about the site, your roles and you.
    - Call aim_recall before answering anything a stored fact could change, rather than relying on what is already in the conversation. Results are the nearest facts and may not answer the question: use only those that do. If none do, or the reply is "No relevant facts found", nothing is recorded; say so rather than guessing.
    - Use aim_literal, not recall, for exact values such as phone numbers, web addresses and names, and give the value exactly as returned.
    - Save with aim_remember only what is worth keeping long term, as short statements with calendar dates ("closed until 16 October 2026", not "until Friday"); set expires when a fact has an end date. Pass source_text with the words they came from. Put everything worth keeping from one exchange in one call's facts list.
    - A saved fact may wait for staff review before recall returns it; the reply says so. Tell the person it is noted for review, not that it is now known.

    Facts have a scope. Ask before saving if none clearly fits, or if the role or case is unclear:
    TEXT;
    $aim .= "\n" . $this->scopeGuide->guide();
    $instructions = ($instructions !== NULL && trim($instructions) !== '') ? $instructions . "\n\n" . $aim : $aim;
  }

}
