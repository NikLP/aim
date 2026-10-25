<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Tool;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\LiteralLookup;
use Drupal\tool\Attribute\ToolMethod;
use Drupal\tool\Attribute\ToolOutput;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolOperation;

/**
 * The aim_literal tool: an exact value kept as a literal, under aim's name.
 *
 * A thin wrapper over the literals.lookup service, so an aim site exposes
 * one lookup under aim's name and need not enable literals_tool. Asking by
 * question uses literals_finder when it is enabled; aim does not depend on
 * it, and the finder stays out of recall. Use of the tool needs "view
 * literals", not "recall aim facts": a literal is not an aim fact, and each
 * value is access-checked for the caller anyway (a missing literal and one
 * the caller may not see give the same "none"). So a signed-in member can
 * look values up in chat.
 *
 * A #[ToolMethod] service (ADR-0044). It stays a wrapper rather than an
 * attribute on literals.lookup itself: lookup() takes an input array and
 * an account rather than typed parameters, and the tool ID aim_literal is
 * aim's, referenced by the recipe and the MCP config.
 */
final class AimLiteral {

  /**
   * The shape a query must have to be tried as a key, without the finder.
   */
  private const KEY_PATTERN = '/^[a-z0-9_]+$/';

  /**
   * Constructs an AimLiteral tool service.
   *
   * @param \Drupal\literals\LiteralLookup $lookup
   *   The shared literal lookup.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The calling account.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, to tell whether the finder is enabled.
   */
  public function __construct(
    private readonly LiteralLookup $lookup,
    private readonly AccountInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Looks up an exact value.
   *
   * One input, so the caller never picks a lookup mode: with the finder
   * enabled every query goes to it, as the chat gate's does; without it a
   * key-shaped query is tried as a key, then anything left goes to a search
   * that lists candidates. literals.lookup still takes key/question/search;
   * the mapping is this wrapper's.
   *
   * @param string $query
   *   The person's question as they asked it, not a rewording or a keyword,
   *   e.g. "how do I ring the library?". Or a key from [literal:key] in a
   *   recalled fact.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The lookup outcome, or a failure when the lookup could not run.
   */
  #[ToolMethod(
    id: 'aim_literal',
    label: new TranslatableMarkup('Look up an exact value'),
    description: new TranslatableMarkup("Returns an exact value (a phone number, a URL, a name) kept as a literal, the [literal:key] that recalled facts refer to. Pass the person's question as they asked it, or a key from [literal:key]. An unclear request returns candidates to choose from, or nothing, never a guess."),
    operation: ToolOperation::Read,
    permission: 'view literals+view restricted literals',
  )]
  #[ToolOutput(name: 'outcome', data_type: 'string', description: new TranslatableMarkup('match, ambiguous, candidates, none or unavailable (the lookup could not run: not the same as none, do not say the value is not recorded).'))]
  #[ToolOutput(name: 'key', data_type: 'string', description: new TranslatableMarkup('The key of the matched literal, or the candidate keys.'))]
  #[ToolOutput(name: 'candidates', data_type: 'string', description: new TranslatableMarkup('One line per candidate, "key: name - description", when several could fit. Call again with one key to get its value.'))]
  #[ToolOutput(name: 'value', data_type: 'string', description: new TranslatableMarkup('The exact value of the matched literal. Empty unless the outcome is match.'))]
  #[ToolOutput(name: 'label', data_type: 'string', description: new TranslatableMarkup('A human label for the value, usable as link text. Empty unless the outcome is match.'))]
  #[ToolOutput(name: 'kind', data_type: 'string', description: new TranslatableMarkup('What the value is: url, phone, email or text. Empty unless the outcome is match.'))]
  #[ToolOutput(name: 'gist', data_type: 'string', description: new TranslatableMarkup('What this value is, in simple language terms. Check that it fits what was asked before presenting the value, and say so if it does not. Empty unless the outcome is match.'))]
  public function lookup(string $query): ExecutableResult {
    $query = trim($query);
    // With the finder, everything goes to it, keys included: it resolves a
    // bare key as exactly as the key path and keeps its ambiguity and gist
    // checks, at one decision call. Results over tokens.
    if ($this->moduleHandler->moduleExists('literals_finder')) {
      return $this->toResult($this->run(['question' => $query]));
    }
    // Without it, search lists candidates and never values, so a key needs
    // its own exact path first.
    if (preg_match(self::KEY_PATTERN, $query)) {
      $result = $this->run(['key' => $query]);
      if ($result['success'] && $result['values']['outcome'] === 'match') {
        return $this->toResult($result);
      }
    }
    return $this->toResult($this->run(['search' => $query]));
  }

  /**
   * Runs one literals.lookup call as the calling account.
   *
   * @param array $input
   *   One of key, question or search.
   *
   * @return array
   *   The lookup result, as LiteralLookup::lookup() returns it.
   */
  private function run(array $input): array {
    return $this->lookup->lookup($input, $this->currentUser, 'aim_literal');
  }

  /**
   * Turns a lookup result into the tool result.
   *
   * @param array $result
   *   The lookup result.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The tool result.
   */
  private function toResult(array $result): ExecutableResult {
    return $result['success']
      ? ExecutableResult::success($result['message'], $result['values'])
      : ExecutableResult::failure($result['message'], NULL);
  }

}
