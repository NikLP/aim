<?php

declare(strict_types=1);

namespace Drupal\aim_chatbot\Drush\Commands;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\AccountInterface;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai_agents\Event\AgentToolPreExecuteEvent;
use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\literals\LiteralLookup;
use Drupal\literals\LiteralReader;
use Drupal\user\Entity\User;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * End-to-end comparison of two ways to answer a one-shot value lookup.
 *
 * Answers the gate eval (ADR-0040 section 6).
 * Runs a gold set through the real assistant twice: "tool" (the assistant calls
 * aim_literal itself, the shipped design) and "prompt" (no tool, the
 * viewer's readable literals listed in the system prompt, so the large model
 * does the same lookup alone), and "gate" (the finder decides first: a
 * match is answered from the literal with no chat model, anything else goes
 * to the chat model with no lookup tool). Counts provider calls, tokens and
 * seconds per question, split by whether the provider is listed in
 * --inhouse (the models that could run inside the perimeter, e.g. Jev), so
 * hosted usage is separate from usage that would be free once local. Needs a
 * live provider; goes through Drupal's provider layer only.
 */
final class AimChatbotEvalCommands extends DrushCommands {

  /**
   * Provider calls of the question in flight.
   *
   * @var array{calls: int, in: int, out: int, tools: list<string>, prov: array<string, array{calls: int, tok: int}>, gate: string}
   */
  protected array $run = [];

  /**
   * Provider IDs counted as in-house.
   *
   * @var list<string>
   */
  protected array $inhouse = [];

  /**
   * Constructs the commands.
   *
   * @param \Drupal\ai_assistant_api\AiAssistantApiRunner $runner
   *   The assistant runner.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher.
   * @param \Drupal\literals\LiteralReader $reader
   *   The literal reader.
   * @param \Drupal\literals\LiteralLookup $lookup
   *   The literal lookup, which runs the finder.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected AiAssistantApiRunner $runner,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountSwitcherInterface $accountSwitcher,
    protected LiteralReader $reader,
    protected LiteralLookup $lookup,
    protected EventDispatcherInterface $dispatcher,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Creates the commands from the container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_assistant_api.runner'),
      $container->get('entity_type.manager'),
      $container->get('account_switcher'),
      $container->get('literals.reader'),
      $container->get('literals.lookup'),
      $container->get('event_dispatcher'),
      $container->get('config.factory'),
    );
  }

  /**
   * Compares the tool path with the prompt-stuffed path on a gold set.
   *
   * Gold sets use the literals:eval format (literals_finder ships
   * eval/gold.example.yml, for the literals_demo_library recipe). A question
   * whose expected literal no longer exists is skipped. Verdicts are
   * mechanical (is the resolved value in the reply); read the --dump file
   * for the wording.
   */
  #[CLI\Command(name: 'aim:chatbot-eval')]
  #[CLI\Argument(name: 'file', description: 'Gold set YAML, in the literals:eval format.')]
  #[CLI\Option(name: 'assistant', description: 'Assistant ID (default aim_demo_assistant).')]
  #[CLI\Option(name: 'modes', description: 'Comma-separated: tool, prompt, gate (default all three).')]
  #[CLI\Option(name: 'inhouse', description: 'Provider IDs counted as in-house (default typesafeai,ollama).')]
  #[CLI\Option(name: 'limit', description: 'Run only the first N usable questions.')]
  #[CLI\Option(name: 'dump', description: 'Write every reply and call count to this JSON file.')]
  public function evaluate(
    string $file,
    array $options = [
      'assistant' => 'aim_demo_assistant',
      'modes' => 'tool,prompt,gate',
      'inhouse' => 'typesafeai,ollama',
      'limit' => 0,
      'dump' => '',
    ],
  ): void {
    if (!is_file($file)) {
      throw new \InvalidArgumentException("No gold set at $file");
    }
    $gold = Yaml::decode(file_get_contents($file))['queries'] ?? [];
    // Let any signed-in visitor through the assistant's role check, for this
    // process only, so a gold item can ask as a non-admin account.
    $GLOBALS['config']['ai_assistant_api.ai_assistant.' . $options['assistant']]['roles']['authenticated'] = 'authenticated';
    $assistant = $this->entityTypeManager->getStorage('ai_assistant')->load($options['assistant']);
    if (!$assistant) {
      throw new \InvalidArgumentException('No such assistant: ' . $options['assistant']);
    }
    $agentId = (string) $assistant->get('ai_agent');
    $live = array_map('strval', array_keys($this->entityTypeManager->getStorage('literal')->loadMultiple()));

    // Keep questions whose expected keys exist now.
    $usable = [];
    foreach ($gold as $item) {
      $expect = (array) $item['expect'];
      $keys = array_values(array_diff($expect, ['none', 'ambiguous']));
      if ($keys && array_diff($keys, $live)) {
        continue;
      }
      $usable[] = $item;
    }
    $skipped = count($gold) - count($usable);
    if ($options['limit']) {
      $usable = array_slice($usable, 0, (int) $options['limit']);
    }

    $this->dispatcher->addListener(PreGenerateResponseEvent::EVENT_NAME, $pre = $this->onPre(...));
    $this->dispatcher->addListener(PostGenerateResponseEvent::EVENT_NAME, $post = $this->onPost(...));
    $this->dispatcher->addListener(AgentToolPreExecuteEvent::EVENT_NAME, $tool = $this->onTool(...));

    $this->inhouse = array_map('trim', explode(',', $options['inhouse']));
    $summary = [];
    $dump = [];
    try {
      foreach (array_map('trim', explode(',', $options['modes'])) as $mode) {
        $this->applyMode($mode, $agentId);
        $rows = [];
        $tot = [
          'ok' => 0,
          'ans' => 0,
          'leak' => 0,
          'err' => 0,
          'calls' => 0,
          'hosted' => 0,
          'home' => 0,
          'hcalls' => 0,
          'gate' => 0,
          's' => 0.0,
        ];
        foreach ($usable as $i => $item) {
          $account = $this->visitor($item['as'] ?? 0);
          $this->run = ['calls' => 0, 'in' => 0, 'out' => 0, 'tools' => [], 'prov' => [], 'gate' => '-'];
          $start = microtime(TRUE);
          $reply = '';
          $error = FALSE;
          $this->accountSwitcher->switchTo($account);
          try {
            $reply = $mode === 'gate' ? $this->gate($item['q'], $account) : NULL;
            $reply ??= $this->askAssistant($assistant, $item['q'], "eval-$mode-$i-" . bin2hex(random_bytes(4)));
          }
          catch (\Throwable $e) {
            $error = TRUE;
            $reply = 'ERROR ' . $e->getMessage();
          }
          finally {
            $this->accountSwitcher->switchBack();
          }
          $took = microtime(TRUE) - $start;
          $verdict = $error ? 'error' : $this->judge($item, $reply, $account);
          $tot['s'] += $took;
          $tot['calls'] += $this->run['calls'];
          foreach ($this->run['prov'] as $id => $use) {
            if (in_array($id, $this->inhouse, TRUE)) {
              $tot['home'] += $use['tok'];
            }
            else {
              $tot['hosted'] += $use['tok'];
              $tot['hcalls'] += $use['calls'];
            }
          }
          $tot['gate'] += $this->run['gate'] === 'hit' ? 1 : 0;
          match ($verdict) {
            'ok' => $tot['ok']++,
            'leak' => $tot['leak']++,
            'error' => $tot['err']++,
            default => NULL,
          };
          if (in_array($verdict, ['ok', 'wrong'], TRUE)) {
            $tot['ans']++;
          }
          $rows[] = [
            $verdict,
            $item['q'],
            implode('|', (array) $item['expect']),
            $this->run['calls'],
            $this->run['in'] . '/' . $this->run['out'],
            (implode(',', $this->run['tools']) ?: '-') . ($this->run['gate'] === 'hit' ? 'gate' : ''),
            sprintf('%.1fs', $took),
          ];
          $dump[$mode][] = [
            'q' => $item['q'],
            'expect' => $item['expect'],
            'verdict' => $verdict,
            'reply' => $reply,
          ] + $this->run;
        }
        $this->io()->section("Mode: $mode");
        $this->io()->table(['', 'Question', 'Expected', 'Calls', 'Tok in/out', 'Tools', 'Time'], $rows);
        $n = max(1, count($usable));
        $summary[] = [
          $mode,
          sprintf('%d/%d', $tot['ok'], $tot['ans']),
          $tot['leak'],
          $tot['err'],
          sprintf('%.1f / %.1f', $tot['hcalls'] / $n, ($tot['calls'] - $tot['hcalls']) / $n),
          sprintf('%d', $tot['hosted'] / $n),
          sprintf('%d', $tot['home'] / $n),
          $mode === 'gate' ? sprintf('%d/%d', $tot['gate'], count($usable)) : '-',
          sprintf('%.1fs', $tot['s'] / $n),
        ];
      }
    }
    finally {
      $this->dispatcher->removeListener(PreGenerateResponseEvent::EVENT_NAME, $pre);
      $this->dispatcher->removeListener(PostGenerateResponseEvent::EVENT_NAME, $post);
      $this->dispatcher->removeListener(AgentToolPreExecuteEvent::EVENT_NAME, $tool);
      unset($GLOBALS['config']['ai_agents.ai_agent.' . $agentId]);
      $this->configFactory->reset('ai_agents.ai_agent.' . $agentId);
      Cache::invalidateTags(['config:ai_agents.ai_agent.' . $agentId]);
    }

    $this->io()->section('Summary (per-question means; answerable correct, leaks, errors)');
    $this->io()->table([
      'Mode',
      'Correct',
      'Leaks',
      'Errors',
      'Calls hosted / in-house',
      'Tok hosted',
      'Tok in-house',
      'Gate hits',
      'Time',
    ], $summary);
    $this->io()->writeln(sprintf('%d questions run, %d skipped (expected literal no longer exists).', count($usable), $skipped));
    if ($options['dump']) {
      file_put_contents($options['dump'], json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
  }

  /**
   * Runs multi-turn conversations through the assistant, per recall mode.
   *
   * Each scenario is one thread: its turns share the assistant's history, as
   * in the widget. Modes, for this process only: "forced" turns
   * require_usage on for recall (shipped until 2026-10-22), "free" turns it
   * off (shipped since), "context" turns it off, recalls in code for each
   * message (AimChatbotRecallContextSubscriber) and drops the persona's "recall
   * first" sentence. A turn passes when its checks pass and the agent did not
   * run out of loops. Scenario format: see the site's demo/chat-eval/.
   */
  #[CLI\Command(name: 'aim:chatbot-converse')]
  #[CLI\Argument(name: 'file', description: 'Scenario YAML: as, scenarios[name, turns[q, any, all, not, literal]].')]
  #[CLI\Option(name: 'assistant', description: 'Assistant ID (default aim_demo_assistant).')]
  #[CLI\Option(name: 'modes', description: 'Comma-separated: forced, free, context (default all three).')]
  #[CLI\Option(name: 'repeat', description: 'Run each scenario this many times per mode (default 1).')]
  #[CLI\Option(name: 'only', description: 'Comma-separated scenario names to run.')]
  #[CLI\Option(name: 'dump', description: 'Write every reply and call count to this JSON file.')]
  public function converse(
    string $file,
    array $options = [
      'assistant' => 'aim_demo_assistant',
      'modes' => 'forced,free,context',
      'repeat' => 1,
      'only' => '',
      'dump' => '',
    ],
  ): void {
    if (!is_file($file)) {
      throw new \InvalidArgumentException("No scenario file at $file");
    }
    $data = Yaml::decode(file_get_contents($file));
    $scenarios = $data['scenarios'] ?? [];
    if ($options['only']) {
      $only = array_map('trim', explode(',', $options['only']));
      $scenarios = array_values(array_filter($scenarios, fn (array $s): bool => in_array($s['name'], $only, TRUE)));
    }
    $GLOBALS['config']['ai_assistant_api.ai_assistant.' . $options['assistant']]['roles']['authenticated'] = 'authenticated';
    $assistant = $this->entityTypeManager->getStorage('ai_assistant')->load($options['assistant']);
    if (!$assistant) {
      throw new \InvalidArgumentException('No such assistant: ' . $options['assistant']);
    }
    $agentId = (string) $assistant->get('ai_agent');
    $account = $this->visitor($data['as'] ?? 1);
    $repeat = max(1, (int) $options['repeat']);

    $this->dispatcher->addListener(PreGenerateResponseEvent::EVENT_NAME, $pre = $this->onPre(...));
    $this->dispatcher->addListener(PostGenerateResponseEvent::EVENT_NAME, $post = $this->onPost(...));
    $this->dispatcher->addListener(AgentToolPreExecuteEvent::EVENT_NAME, $tool = $this->onTool(...));
    $summary = [];
    $dump = [];
    try {
      foreach (array_map('trim', explode(',', $options['modes'])) as $mode) {
        $this->applyRecallMode($mode, $agentId);
        $rows = [];
        $tot = [
          'turns' => 0,
          'ok' => 0,
          'convs' => 0,
          'convok' => 0,
          'loops' => 0,
          'err' => 0,
          'calls' => 0,
          's' => 0.0,
        ];
        foreach ($scenarios as $scenario) {
          for ($r = 1; $r <= $repeat; $r++) {
            $thread = "converse-$mode-{$scenario['name']}-$r-" . bin2hex(random_bytes(4));
            $allOk = TRUE;
            $this->accountSwitcher->switchTo($account);
            try {
              $this->runner->setAssistant($assistant);
              $this->runner->setThrowException(TRUE);
              $this->runner->setThreadsKey($thread);
              $this->runner->resetMessageHistory();
              foreach ($scenario['turns'] as $t => $turn) {
                $this->run = ['calls' => 0, 'in' => 0, 'out' => 0, 'tools' => [], 'prov' => [], 'gate' => '-'];
                $start = microtime(TRUE);
                $error = FALSE;
                try {
                  $this->runner->setUserMessage(new UserMessage($turn['q']));
                  $reply = trim($this->runner->process()->getNormalized()->getText());
                  $this->runner->setAssistantMessage($reply);
                }
                catch (\Throwable $e) {
                  $error = TRUE;
                  $reply = 'ERROR ' . $e->getMessage();
                }
                $took = microtime(TRUE) - $start;
                $looped = !$error && stripos($reply, 'processing steps') !== FALSE;
                $verdict = $error ? 'error' : ($looped ? 'loops' : $this->checkTurn($turn, $reply, $account));
                $allOk = $allOk && $verdict === 'ok';
                $tot['turns']++;
                $tot['s'] += $took;
                $tot['calls'] += $this->run['calls'];
                match ($verdict) {
                  'ok' => $tot['ok']++,
                  'loops' => $tot['loops']++,
                  'error' => $tot['err']++,
                  default => NULL,
                };
                $rows[] = [
                  $verdict,
                  $scenario['name'] . ($repeat > 1 ? " #$r" : '') . ' ' . ($t + 1),
                  mb_strimwidth($turn['q'], 0, 40, '...'),
                  $this->run['calls'],
                  implode(',', $this->run['tools']) ?: '-',
                  sprintf('%.1fs', $took),
                ];
                $dump[$mode][] = [
                  'scenario' => $scenario['name'],
                  'run' => $r,
                  'turn' => $t + 1,
                  'q' => $turn['q'],
                  'verdict' => $verdict,
                  'reply' => $reply,
                  'seconds' => round($took, 1),
                ] + $this->run;
              }
            }
            finally {
              $this->accountSwitcher->switchBack();
            }
            $tot['convs']++;
            $tot['convok'] += $allOk ? 1 : 0;
          }
        }
        $this->io()->section("Mode: $mode");
        $this->io()->table(['', 'Scenario turn', 'Question', 'Calls', 'Tools', 'Time'], $rows);
        $n = max(1, $tot['turns']);
        $summary[] = [
          $mode,
          sprintf('%d/%d', $tot['ok'], $tot['turns']),
          sprintf('%d/%d', $tot['convok'], $tot['convs']),
          $tot['loops'],
          $tot['err'],
          sprintf('%.1f', $tot['calls'] / $n),
          sprintf('%.1fs', $tot['s'] / $n),
        ];
      }
    }
    finally {
      $this->dispatcher->removeListener(PreGenerateResponseEvent::EVENT_NAME, $pre);
      $this->dispatcher->removeListener(PostGenerateResponseEvent::EVENT_NAME, $post);
      $this->dispatcher->removeListener(AgentToolPreExecuteEvent::EVENT_NAME, $tool);
      unset($GLOBALS['config']['ai_agents.ai_agent.' . $agentId]);
      $this->configFactory->reset('ai_agents.ai_agent.' . $agentId);
      Cache::invalidateTags(['config:ai_agents.ai_agent.' . $agentId]);
    }

    $this->io()->section('Summary (per-turn means)');
    $this->io()->table(['Mode', 'Turns ok', 'Conversations ok', 'Out of loops', 'Errors', 'Calls', 'Time'], $summary);
    if ($options['dump']) {
      file_put_contents($options['dump'], json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
  }

  /**
   * Overrides the agent config for a recall mode, for this process only.
   *
   * @param string $mode
   *   The mode: forced, free or context; see converse().
   * @param string $agentId
   *   The ai_agent ID.
   */
  protected function applyRecallMode(string $mode, string $agentId): void {
    $name = 'ai_agents.ai_agent.' . $agentId;
    unset($GLOBALS['config'][$name]);
    $this->configFactory->reset($name);
    if (!in_array($mode, ['forced', 'free', 'context'], TRUE)) {
      throw new \InvalidArgumentException("Unknown mode: $mode");
    }
    $config = $this->configFactory->get($name)->getRawData();
    $settings = $config['tool_settings'];
    $settings['aim_chatbot:recall']['require_usage'] = $mode === 'forced' ? 1 : 0;
    $override = ['tool_settings' => $settings];
    if ($mode === 'context') {
      $override['third_party_settings']['aim_chatbot']['recall_context'] = TRUE;
      $override['system_prompt'] = preg_replace('/\s*Check memory with the recall tool first[^.]*\./', '', (string) $config['system_prompt']);
    }
    $GLOBALS['config'][$name] = $override;
    $this->configFactory->reset($name);
    $this->entityTypeManager->getStorage('ai_agent')->resetCache([$agentId]);
    Cache::invalidateTags(['config:' . $name]);
  }

  /**
   * Checks one turn's reply against its expectations.
   *
   * @param array $turn
   *   The turn: any (one of these substrings), all, not (none of these), and
   *   literal (a key whose value must appear).
   * @param string $reply
   *   The reply.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The visitor.
   *
   * @return string
   *   ok or wrong.
   */
  protected function checkTurn(array $turn, string $reply, AccountInterface $account): string {
    $has = fn (string $needle): bool => stripos($reply, $needle) !== FALSE;
    if (!empty($turn['any']) && !array_filter((array) $turn['any'], $has)) {
      return 'wrong';
    }
    foreach ((array) ($turn['all'] ?? []) as $needle) {
      if (!$has($needle)) {
        return 'wrong';
      }
    }
    if (array_filter((array) ($turn['not'] ?? []), $has)) {
      return 'wrong';
    }
    if (!empty($turn['literal']) && !$this->contains($reply, $turn['literal'], $account)) {
      return 'wrong';
    }
    return 'ok';
  }

  /**
   * Builds the visitor for a gold item's "as".
   *
   * @param int|string $as
   *   0 for anonymous, a user ID, or a user name.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  protected function visitor(int|string $as): AccountInterface {
    if ($as === 0 || $as === '0') {
      return new AnonymousUserSession();
    }
    if (is_numeric($as)) {
      return User::load((int) $as);
    }
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $as]);
    if (!$users) {
      throw new \InvalidArgumentException("No such user: $as");
    }
    return reset($users);
  }

  /**
   * Asks the finder first; a match is answered from the literal alone.
   *
   * @param string $question
   *   The visitor's question.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The visitor.
   *
   * @return string|null
   *   The literal's value on a match, or NULL to hand on to the chat model.
   */
  protected function gate(string $question, AccountInterface $account): ?string {
    $result = $this->lookup->lookup(['question' => $question], $account, 'eval');
    $values = $result['values'] ?? [];
    if (!empty($result['success']) && ($values['outcome'] ?? '') === 'match' && ($values['value'] ?? '') !== '') {
      $this->run['gate'] = 'hit';
      return $values['value'];
    }
    $this->run['gate'] = 'pass';
    return NULL;
  }

  /**
   * Sends one question to the assistant on its own thread.
   *
   * @param object $assistant
   *   The assistant entity.
   * @param string $question
   *   The visitor's question.
   * @param string $thread
   *   A fresh thread key.
   *
   * @return string
   *   The reply text.
   */
  protected function askAssistant(object $assistant, string $question, string $thread): string {
    $this->runner->setAssistant($assistant);
    $this->runner->setThrowException(TRUE);
    $this->runner->setThreadsKey($thread);
    $this->runner->resetMessageHistory();
    $this->runner->setUserMessage(new UserMessage($question));
    $out = $this->runner->process();
    return trim($out->getNormalized()->getText());
  }

  /**
   * Overrides the agent config for a mode, for this process only.
   *
   * @param string $mode
   *   The mode: "tool" leaves the agent alone; "prompt" drops the lookup
   *   tool and lists the anonymous-readable literals in the system prompt.
   * @param string $agentId
   *   The ai_agent ID.
   */
  protected function applyMode(string $mode, string $agentId): void {
    $name = 'ai_agents.ai_agent.' . $agentId;
    unset($GLOBALS['config'][$name]);
    if ($mode === 'gate') {
      $config = $this->configFactory->getEditable($name)->getRawData();
      $prompt = preg_replace('/When a visitor needs an exact value.*$/s', '', (string) $config['system_prompt']);
      $prompt .= "You have no lookup tool. If a visitor asks for an exact value such as a phone number or a web address and it is not in your recalled facts, say you don't have it recorded.";
      $tools = $config['tools'];
      $tools['tool:aim_literal'] = FALSE;
      $GLOBALS['config'][$name] = ['system_prompt' => $prompt, 'tools' => $tools];
    }
    elseif ($mode === 'prompt') {
      $config = $this->configFactory->getEditable($name)->getRawData();
      $lines = [];
      foreach ($this->entityTypeManager->getStorage('literal')->loadMultiple() as $literal) {
        $item = $this->reader->readItem((string) $literal->id(), new AnonymousUserSession());
        if ($item && $item->value !== '') {
          $lines[] = '- ' . ((string) $literal->id()) . ' (' . $literal->getGist() . '): ' . $item->value;
        }
      }
      // Replace the lookup-tool sentence; add the table of exact values.
      $prompt = preg_replace('/When a visitor needs an exact value.*$/s', '', (string) $config['system_prompt']);
      $prompt .= "When a visitor needs an exact value such as a phone number or a web address, take it from this list and give it exactly as written. If it is not on the list, say you don't have it recorded.\nExact values:\n" . implode("\n", $lines);
      $tools = $config['tools'];
      $tools['tool:aim_literal'] = FALSE;
      $GLOBALS['config'][$name] = ['system_prompt' => $prompt, 'tools' => $tools];
    }
    $this->configFactory->reset($name);
    Cache::invalidateTags(['config:' . $name]);
  }

  /**
   * Judges a reply mechanically.
   *
   * @param array $item
   *   The gold item.
   * @param string $reply
   *   The assistant's reply.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The visitor.
   *
   * @return string
   *   ok, wrong, ok-none, or leak.
   */
  protected function judge(array $item, string $reply, AccountInterface $account): string {
    $expect = (array) $item['expect'];
    $keys = array_values(array_diff($expect, ['none', 'ambiguous']));
    $has = fn (string $key): bool => $this->contains($reply, $key, $account);
    // A leak is any literal value, seen as user 1, whoever the visitor is.
    $root = User::load(1);
    $hasAny = fn (string $key): bool => $this->contains($reply, $key, $root);
    if ($keys && !in_array('none', $expect, TRUE) && !in_array('ambiguous', $expect, TRUE)) {
      return array_filter($keys, $has) ? 'ok' : 'wrong';
    }
    // Unanswerable or vague: a literal value in the reply is a false positive
    // unless the gold set accepts that key.
    foreach ($this->entityTypeManager->getStorage('literal')->loadMultiple() as $literal) {
      $key = (string) $literal->id();
      if (!in_array($key, $keys, TRUE) && $hasAny($key)) {
        return 'leak';
      }
    }
    return 'ok-none';
  }

  /**
   * Tells whether a reply carries a literal's value.
   *
   * @param string $reply
   *   The reply.
   * @param string $key
   *   The literal key.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The visitor.
   *
   * @return bool
   *   TRUE when the value (digits only for phones) appears.
   */
  protected function contains(string $reply, string $key, AccountInterface $account): bool {
    $item = $this->reader->readItem($key, $account);
    if (!$item || $item->value === '') {
      return FALSE;
    }
    if ($item->kind === 'phone') {
      $digits = preg_replace('/\D/', '', $item->value);
      return $digits !== '' && str_contains(preg_replace('/\D/', '', $reply), $digits);
    }
    return stripos($reply, $item->value) !== FALSE
      || ($item->kind === 'url' && stripos($reply, (string) parse_url($item->value, PHP_URL_PATH)) !== FALSE);
  }

  /**
   * Counts a provider call.
   *
   * @param \Drupal\ai\Event\PreGenerateResponseEvent $event
   *   The event.
   */
  public function onPre(PreGenerateResponseEvent $event): void {
    $this->run['calls']++;
  }

  /**
   * Adds a finished provider call's tokens.
   *
   * @param \Drupal\ai\Event\PostGenerateResponseEvent $event
   *   The event.
   */
  public function onPost(PostGenerateResponseEvent $event): void {
    $output = $event->getOutput();
    $usage = is_object($output) && method_exists($output, 'getTokenUsage') ? $output->getTokenUsage() : NULL;
    if ($usage instanceof TokenUsageDto) {
      $this->run['in'] += (int) $usage->input;
      $this->run['out'] += (int) $usage->output;
    }
    $id = $event->getProviderId();
    $this->run['prov'][$id]['calls'] = ($this->run['prov'][$id]['calls'] ?? 0) + 1;
    $this->run['prov'][$id]['tok'] = ($this->run['prov'][$id]['tok'] ?? 0)
      + ($usage instanceof TokenUsageDto ? (int) $usage->input + (int) $usage->output : 0);
  }

  /**
   * Records which tool the agent ran.
   *
   * @param \Drupal\ai_agents\Event\AgentToolPreExecuteEvent $event
   *   The event.
   */
  public function onTool(AgentToolPreExecuteEvent $event): void {
    $this->run['tools'][] = $event->getTool()->getPluginId();
  }

}
