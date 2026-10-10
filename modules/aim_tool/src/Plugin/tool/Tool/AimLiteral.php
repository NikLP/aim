<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\LiteralLookup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Looks up an exact value kept as a literal, under aim's own tool name.
 *
 * A thin wrapper over the literals.lookup service, so an aim site exposes
 * one lookup under aim's name and need not enable literals_tool. Asking by
 * question uses literals_finder when it is enabled; aim does not depend on
 * it, and the finder stays out of recall. Use of the tool needs "view
 * literals", not "read aim memory": a literal is not an aim fact, and each
 * value is access-checked for the caller anyway (a missing literal and one
 * the caller may not see give the same "none"). So a signed-in member can
 * look values up in chat.
 */
#[Tool(
  id: 'aim_literal',
  label: new TranslatableMarkup('Look up an exact value'),
  description: new TranslatableMarkup('Returns an exact value (a phone number, a URL, a name) that memory refers to as [literal:key]. Give a key when it is known, search words to list candidate literals (key, name, description) to choose from, or a plain-language question (when the site has the finder) to have the closest literal picked; an unclear question returns candidates or nothing, never a guess.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'key' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Key'),
      description: new TranslatableMarkup('The literal key, when it is known. Takes precedence over search.'),
      required: FALSE,
    ),
    'question' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Question'),
      description: new TranslatableMarkup('What is wanted, in plain words, e.g. "the library phone number". Used only when no key is given. Needs the literals_finder module.'),
      required: FALSE,
    ),
    'search' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search words'),
      description: new TranslatableMarkup('Words from a literal name, key or description, e.g. "phone". Returns the matching literals to choose from, never values. Used only when no key or question is given.'),
      required: FALSE,
    ),
  ],
  output_definitions: [
    'outcome' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Outcome'),
      description: new TranslatableMarkup('match, ambiguous, candidates or none.'),
    ),
    'key' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Key'),
      description: new TranslatableMarkup('The key of the matched literal, or the candidate keys.'),
    ),
    'candidates' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Candidates'),
      description: new TranslatableMarkup('One line per candidate, "key: name - description", after a search. Call again with a key to get a value.'),
    ),
    'value' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Value'),
      description: new TranslatableMarkup('The exact value of the matched literal. Empty unless the outcome is match.'),
    ),
    'label' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Label'),
      description: new TranslatableMarkup('A human label for the value, usable as link text. Empty unless the outcome is match.'),
    ),
    'kind' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Kind'),
      description: new TranslatableMarkup('What the value is: url, phone, email or text. Empty unless the outcome is match.'),
    ),
  ],
)]
final class AimLiteral extends ToolBase {

  /**
   * The shared literal lookup.
   */
  protected LiteralLookup $lookup;

  /**
   * {@inheritdoc}
   *
   * See AimRemember for why create() rather than the constructor.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->lookup = $container->get('literals.lookup');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $result = $this->lookup->lookup($values, $this->currentUser, 'aim_literal');
    return $result['success']
      ? ExecutableResult::success($result['message'], $result['values'])
      : ExecutableResult::failure($result['message'], NULL);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowedIfHasPermissions($account, ['view literals', 'view restricted literals'], 'OR');
    return $return_as_object ? $access : $access->isAllowed();
  }

}
