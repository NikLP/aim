<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralReader;
use Drupal\literals\LiteralSearch;
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
 * A thin wrapper over the literals module's reader and search services, so a
 * site that wants aim as its single entry point need not expose or even
 * enable literals_tool. Asking by question uses literals_finder when it is
 * enabled; aim does not depend on it, and the finder stays out of recall.
 * Access is the literal's own - a missing literal and one the
 * caller may not see give the same "not found" answer - on top of "read aim
 * memory" gating use of the tool at all.
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
   * The literal reader.
   */
  protected LiteralReader $reader;

  /**
   * The finder, when literals_finder is enabled.
   */
  protected ?object $finder = NULL;

  /**
   * The literal search.
   */
  protected LiteralSearch $search;

  /**
   * {@inheritdoc}
   *
   * See AimRemember for why create() rather than the (final) constructor.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->reader = $container->get('literals.reader');
    $instance->finder = $container->has('literals_finder.finder') ? $container->get('literals_finder.finder') : NULL;
    $instance->search = $container->get('literals.search');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $key = trim((string) ($values['key'] ?? ''));
    $question = trim((string) ($values['question'] ?? ''));
    $words = trim((string) ($values['search'] ?? ''));

    if ($key !== '') {
      $mode = 'key';
      $result = $this->byKey($key);
    }
    elseif ($question !== '') {
      $mode = 'question';
      $result = $this->finder
        ? $this->byQuestion($question)
        : ExecutableResult::failure(new TranslatableMarkup('Looking up by question needs the literals_finder module. Give a key or search words.'), NULL);
    }
    elseif ($words !== '') {
      $mode = 'search';
      $result = $this->bySearch($words);
    }
    else {
      $mode = 'none';
      $result = ExecutableResult::failure(new TranslatableMarkup('Give a key, a question or search words.'), NULL);
    }

    // Audit every call, mode, outcome and keys only: never the key typed, the
    // question or search words (personal data) or a value.
    $output = $result->getContextValues();
    $this->reader->logAudit('Aim literal tool: mode @mode, outcome @outcome, keys @keys, uid @uid.', [
      '@mode' => $mode,
      '@outcome' => $result->isSuccess() ? ($output['outcome'] ?? 'unknown') : 'error',
      '@keys' => ($output['key'] ?? '') !== '' ? $output['key'] : '-',
      '@uid' => $this->currentUser->id(),
    ]);
    return $result;
  }

  /**
   * Looks a literal up by key.
   *
   * @param string $key
   *   The literal key.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result.
   */
  protected function byKey(string $key): ExecutableResult {
    $item = $this->reader->readItem($key, $this->currentUser, new CacheableMetadata());
    if (!$item || $item->value === '') {
      return $this->notFound();
    }
    return ExecutableResult::success(
      new TranslatableMarkup('Found literal @key.', ['@key' => $key]),
      $this->output('match', $key, $item->toArray()),
    );
  }

  /**
   * Looks a literal up by question, through the finder.
   *
   * @param string $question
   *   The question.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result.
   */
  protected function byQuestion(string $question): ExecutableResult {
    /** @var \Drupal\literals_finder\Finder\LiteralFinderInterface $finder */
    $finder = $this->finder;
    $result = $finder->find($question, $this->currentUser);
    $keys = array_map(fn (Literal $literal): string => (string) $literal->get('key')->value, $result->literals);

    if ($result->outcome === 'match' && $keys) {
      $resolved = $this->reader->resolveFound($result->outcome, $result->literals, $this->currentUser);
      $item = reset($resolved['items']);
      return $item
        ? ExecutableResult::success(new TranslatableMarkup('Found literal @key.', ['@key' => $keys[0]]), $this->output('match', $keys[0], $item->toArray()))
        : $this->notFound();
    }
    if ($result->outcome === 'ambiguous' && $keys) {
      return ExecutableResult::success(
        new TranslatableMarkup('More than one literal fits: @keys. Ask again with a key or a clearer question.', ['@keys' => implode(', ', $keys)]),
        $this->output('ambiguous', implode(',', $keys), ['candidates' => $this->describe($result->literals)]),
      );
    }
    return $this->notFound();
  }

  /**
   * Lists literals matching typed words, for the caller to choose from.
   *
   * @param string $words
   *   The search words.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result: candidates, never values.
   */
  protected function bySearch(string $words): ExecutableResult {
    $literals = array_values($this->search->search($words, $this->currentUser, 10));
    if (!$literals) {
      return $this->notFound();
    }
    $keys = array_map(fn (Literal $literal): string => (string) $literal->get('key')->value, $literals);
    return ExecutableResult::success(
      new TranslatableMarkup('Found @count literal(s). Call again with a key to get a value.', ['@count' => count($literals)]),
      $this->output('candidates', implode(',', $keys), ['candidates' => $this->describe($literals)]),
    );
  }

  /**
   * Describes literals as "key: name - gist" lines, never values.
   *
   * @param \Drupal\literals\Entity\Literal[] $literals
   *   The literals.
   *
   * @return string
   *   One line per literal.
   */
  protected function describe(array $literals): string {
    $lines = [];
    foreach ($literals as $literal) {
      $gist = $literal->getGist();
      $lines[] = $literal->get('key')->value . ': ' . $literal->label() . ($gist !== '' ? ' - ' . $gist : '');
    }
    return implode("\n", $lines);
  }

  /**
   * Builds the not-found result: the same for missing and not visible.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The result.
   */
  protected function notFound(): ExecutableResult {
    return ExecutableResult::success(
      new TranslatableMarkup('No matching literal found.'),
      $this->output('none', ''),
    );
  }

  /**
   * Builds the output values with every output defined.
   *
   * @param string $outcome
   *   The outcome.
   * @param string $key
   *   The key, or the comma-separated candidate keys.
   * @param array $values
   *   Any of candidates, value, label and kind.
   *
   * @return array
   *   The complete output values.
   */
  protected function output(string $outcome, string $key, array $values = []): array {
    return $values + [
      'outcome' => $outcome,
      'key' => $key,
      'candidates' => '',
      'value' => '',
      'label' => '',
      'kind' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowedIfHasPermission($account, 'read aim memory');
    return $return_as_object ? $access : $access->isAllowed();
  }

}
