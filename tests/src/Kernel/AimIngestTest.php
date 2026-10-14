<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\aim\Form\AimIngestForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The ingest form's lines and JSON modes, and the batch write under them.
 *
 * Extract mode calls a model, so it is left to the live checks.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimIngestTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'key',
    'taxonomy',
    'views',
    'ai',
    'search_api',
    'literals',
    'aim',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aim_fact');
    $path = $this->container->get('extension.list.module')->getPath('aim');
    $this->config('aim.settings')
      ->setData(Yaml::decode(file_get_contents($path . '/config/install/aim.settings.yml')))
      ->save();
    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    AimScope::create(['id' => 'role', 'label' => 'Role'])->save();
    $this->createUser([], 'root');
    $this->setCurrentUser($this->createUser(['ingest aim facts', 'administer aim']));
  }

  /**
   * One fact per line, in the chosen scope; blank lines are skipped.
   */
  public function testLinesMode(): void {
    $state = $this->submit([
      'mode' => 'lines',
      'scope' => 'site',
      'text' => "Open at nine.\n\n  Closed Sundays.  \n",
      'source' => '',
    ]);
    $this->assertSame([], $state->getErrors());
    $result = $state->get('result');
    $this->assertCount(2, $result['created']);
    $this->assertSame([], $result['errors']);

    $texts = array_map(fn (AimFact $f) => $f->get('text')->value, array_values(AimFact::loadMultiple()));
    $this->assertSame(['Open at nine.', 'Closed Sundays.'], $texts);
    $this->assertSame('paste', AimFact::load(1)->get('source')->value, 'no source given: paste');
  }

  /**
   * A JSON array carries a scope per entry; a bad entry skips only itself.
   */
  public function testJsonMode(): void {
    $json = json_encode([
      ['text' => 'A site fact.', 'scope' => 'site'],
      ['text' => 'A role fact.', 'scope' => 'role', 'source' => 'handbook'],
      ['text' => 'No scope.'],
      ['text' => 'Bad scope.', 'scope' => 'nonsense'],
      ['scope' => 'site'],
    ]);
    $state = $this->submit(['mode' => 'json', 'text' => $json, 'source' => 'import']);

    $result = $state->get('result');
    $this->assertCount(2, $result['created']);
    $this->assertCount(3, $result['errors']);
    $this->assertSame('handbook', AimFact::load(2)->get('source')->value);
    $this->assertSame('import', AimFact::load(1)->get('source')->value);
  }

  /**
   * JSON that is not an array of facts is reported, and nothing is written.
   */
  public function testJsonNotAnArray(): void {
    $state = $this->submit(['mode' => 'json', 'text' => '"just a string"']);
    $this->assertCount(0, $state->get('result')['created']);
    $this->assertCount(0, AimFact::loadMultiple());
  }

  /**
   * Validation: empty input, and lines mode without a scope.
   */
  public function testValidation(): void {
    $empty = $this->submit(['mode' => 'lines', 'scope' => 'site', 'text' => '   ']);
    $this->assertArrayHasKey('upload', $empty->getErrors());

    $noScope = $this->submit(['mode' => 'lines', 'scope' => '', 'text' => 'A fact.']);
    $this->assertArrayHasKey('scope', $noScope->getErrors());
    $this->assertCount(0, AimFact::loadMultiple());
  }

  /**
   * Submits the ingest form with the given values.
   *
   * @param array $values
   *   Form values.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state after validate and submit.
   */
  protected function submit(array $values): FormState {
    $state = new FormState();
    $state->setValues($values + [
      'subject' => '',
      'subject_uid' => '',
      'target_type' => '',
      'target_id' => '',
      'source' => '',
    ]);
    $this->container->get('form_builder')->submitForm(AimIngestForm::class, $state);
    return $state;
  }

}
