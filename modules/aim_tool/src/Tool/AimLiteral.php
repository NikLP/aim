<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Tool;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\LiteralLookup;
use Drupal\tool\Attribute\ToolInput;
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
   * Constructs an AimLiteral tool service.
   *
   * @param \Drupal\literals\LiteralLookup $lookup
   *   The shared literal lookup.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The calling account.
   */
  public function __construct(
    private readonly LiteralLookup $lookup,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Looks up an exact value.
   *
   * @param string|null $key
   *   The literal key, when it is known. Takes precedence over search.
   * @param string|null $question
   *   What is wanted, in plain words, e.g. "the library phone number". Used
   *   only when no key is given. Needs the literals_finder module.
   * @param string|null $search
   *   Words from a literal name, key or description, e.g. "phone". Returns
   *   the matching literals to choose from, never values. Used only when no
   *   key or question is given.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The lookup outcome, or a failure when the lookup could not run.
   */
  #[ToolMethod(
    id: 'aim_literal',
    label: new TranslatableMarkup('Look up an exact value'),
    description: new TranslatableMarkup('Returns an exact value (a phone number, a URL, a name) that memory refers to as [literal:key]. Give a key when it is known, search words to list candidate literals (key, name, description) to choose from, or a plain-language question (when the site has the finder) to have the closest literal picked; an unclear question returns candidates or nothing, never a guess.'),
    operation: ToolOperation::Read,
    permission: 'view literals+view restricted literals',
  )]
  #[ToolOutput(name: 'outcome', data_type: 'string', description: new TranslatableMarkup('match, ambiguous, candidates, none or unavailable (the lookup could not run: not the same as none, do not say the value is not recorded).'))]
  #[ToolOutput(name: 'key', data_type: 'string', description: new TranslatableMarkup('The key of the matched literal, or the candidate keys.'))]
  #[ToolOutput(name: 'candidates', data_type: 'string', description: new TranslatableMarkup('One line per candidate, "key: name - description", after a search. Call again with a key to get a value.'))]
  #[ToolOutput(name: 'value', data_type: 'string', description: new TranslatableMarkup('The exact value of the matched literal. Empty unless the outcome is match.'))]
  #[ToolOutput(name: 'label', data_type: 'string', description: new TranslatableMarkup('A human label for the value, usable as link text. Empty unless the outcome is match.'))]
  #[ToolOutput(name: 'kind', data_type: 'string', description: new TranslatableMarkup('What the value is: url, phone, email or text. Empty unless the outcome is match.'))]
  #[ToolOutput(name: 'gist', data_type: 'string', description: new TranslatableMarkup('What this value is, in simple language terms. Check that it fits what was asked before presenting the value, and say so if it does not. Empty unless the outcome is match.'))]
  public function lookup(
    ?string $key = NULL,
    ?string $question = NULL,
    #[ToolInput(label: new TranslatableMarkup('Search words'))]
    ?string $search = NULL,
  ): ExecutableResult {
    $result = $this->lookup->lookup(
      ['key' => $key, 'question' => $question, 'search' => $search],
      $this->currentUser,
      'aim_literal',
    );
    return $result['success']
      ? ExecutableResult::success($result['message'], $result['values'])
      : ExecutableResult::failure($result['message'], NULL);
  }

}
