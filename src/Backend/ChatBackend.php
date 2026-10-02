<?php

declare(strict_types=1);

namespace Drupal\aim\Backend;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\DecisionResponse;
use Drupal\ai\OperationType\Decision\Value\AnswerInterface;
use Drupal\ai\OperationType\Decision\Value\ChoiceAnswer;
use Drupal\ai\OperationType\Decision\Value\ChoiceQuestion;
use Drupal\ai\OperationType\Decision\Value\NoulAnswer;
use Drupal\ai\OperationType\Decision\Value\NoulQuestion;
use Drupal\ai\OperationType\Decision\Value\QuestionInterface;

/**
 * Bridges a Decision input onto a structured-output chat call.
 *
 * No chat provider implements decision(), so this builds the same questions
 * as one prompt plus a JSON schema and maps the reply back into Decision
 * answers. A chat model reports no probabilities: a choice answer is
 * one-hot (probability 1 on the pick, confidence 1), and a noul answer is
 * the probability the model states. Score questions are not bridged.
 */
class ChatBackend implements ActivityBackendInterface {

  /**
   * Constructs a ChatBackend.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   */
  public function __construct(
    protected AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(string $activity, DecisionInput $input, string $providerId, string $modelId): DecisionResponse {
    $questions = $input->getQuestions();
    $properties = [];
    $lines = [];
    foreach ($questions as $id => $question) {
      $properties[$id] = $this->schemaFor($question);
      $lines[] = $this->describe((string) $id, $question);
    }

    $prompt = "Judge the state below and answer every question.\n\nState (JSON):\n"
      . json_encode($input->getState(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
      . "\n\nQuestions:\n" . implode("\n", $lines);

    $schema = new StructuredOutputSchema(
      name: 'aim_decision_' . $activity,
      description: 'Typed answers to the decision questions.',
      strict: TRUE,
      json_schema: [
        'type' => 'object',
        'additionalProperties' => FALSE,
        'properties' => $properties,
        'required' => array_keys($properties),
      ],
    );
    $chat = new ChatInput([new ChatMessage('user', $prompt)]);
    $chat->setChatStructuredJsonSchema($schema);

    $output = $this->aiProvider->createInstance($providerId)->chat($chat, $modelId, [self::TAGS[$activity]]);
    $text = $output->getNormalized()->getText();
    $decoded = json_decode($text, TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('Chat reply was not a JSON object.');
    }

    $answers = [];
    foreach ($questions as $id => $question) {
      if (!array_key_exists($id, $decoded)) {
        throw new \RuntimeException("Chat reply has no answer for question $id.");
      }
      $answers[$id] = $this->answerFor($question, $decoded[$id]);
    }
    return new DecisionResponse($answers, $modelId, $output->getTokenUsage());
  }

  /**
   * Builds the schema for one question's answer.
   *
   * @param \Drupal\ai\OperationType\Decision\Value\QuestionInterface $question
   *   The question.
   *
   * @return array
   *   The JSON schema fragment.
   */
  protected function schemaFor(QuestionInterface $question): array {
    return match (TRUE) {
      $question instanceof ChoiceQuestion => ['type' => 'string', 'enum' => $question->getOptionKeys()],
      $question instanceof NoulQuestion => ['type' => 'number'],
      default => throw new \InvalidArgumentException('The chat backend bridges choice and noul questions only.'),
    };
  }

  /**
   * Renders one question as a prompt line.
   *
   * @param string $id
   *   The question ID.
   * @param \Drupal\ai\OperationType\Decision\Value\QuestionInterface $question
   *   The question.
   *
   * @return string
   *   The prompt text.
   */
  protected function describe(string $id, QuestionInterface $question): string {
    $instructions = $question->getInstructions();
    $text = "- $id: " . (is_array($instructions) ? json_encode($instructions) : $instructions);
    if ($question instanceof ChoiceQuestion) {
      $text .= "\n  Answer with exactly one of these options:";
      foreach ($question->getCriteria() as $key => $description) {
        $text .= "\n  - $key" . ($description === NULL ? '' : ': ' . (is_array($description) ? json_encode($description) : $description));
      }
    }
    else {
      $text .= "\n  Answer with the probability, from 0 to 1, that the statement is true.";
    }
    return $text;
  }

  /**
   * Maps one decoded reply value to a Decision answer.
   *
   * @param \Drupal\ai\OperationType\Decision\Value\QuestionInterface $question
   *   The question.
   * @param mixed $value
   *   The decoded value from the reply.
   *
   * @return \Drupal\ai\OperationType\Decision\Value\AnswerInterface
   *   The answer.
   */
  protected function answerFor(QuestionInterface $question, mixed $value): AnswerInterface {
    try {
      if ($question instanceof ChoiceQuestion) {
        $choice = (string) $value;
        $probabilities = array_fill_keys($question->getOptionKeys(), 0.0);
        $probabilities[$choice] = 1.0;
        return new ChoiceAnswer($choice, $probabilities, 1.0, ['choice' => $choice]);
      }
      return new NoulAnswer((float) $value, ['noul' => $value]);
    }
    catch (\InvalidArgumentException $e) {
      throw new \RuntimeException('Chat reply did not fit the question: ' . $e->getMessage(), 0, $e);
    }
  }

}
