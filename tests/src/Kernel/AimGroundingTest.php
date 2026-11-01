<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The grounded check at write time (ADR-0037) and starting trust (ADR-0065).
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimGroundingTest extends KernelTestBase {

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
   * The text every test fact is written from.
   */
  protected const PASSAGE = 'Staff meeting. Lost property is kept for four weeks. Alex wants to add drop-in tech help on Mondays.';

  /**
   * A candidate the passage states.
   */
  protected const GOOD = 'Lost property is kept for four weeks.';

  /**
   * A candidate the passage only proposes.
   */
  protected const BAD = 'Drop-in tech help runs on Mondays.';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('aim.backend.decision', FakeDecisionBackend::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aim_fact');

    $path = $this->container->get('extension.list.module')->getPath('aim');
    $settings = Yaml::decode(file_get_contents($path . '/config/install/aim.settings.yml'));
    $settings['activities']['grounding'] = [
      'backend' => 'decision',
      'provider' => 'fake',
      'model' => 'fake-model',
      'threshold' => 0.7,
    ];
    $this->config('aim.settings')->setData($settings)->save();
    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();

    // A writer who may trust site facts, so their facts start trusted and
    // the grounded check visibly demotes or leaves them.
    $this->setUpCurrentUser([], ['trust site aim facts']);

    $this->backend()->scores = [self::GOOD => 0.92, self::BAD => 0.2];
  }

  /**
   * Record mode stores the score and changes nothing else.
   */
  public function testRecordStoresScoreOnly(): void {
    $this->setMode('record');
    $bad = $this->remember(self::BAD, self::PASSAGE);
    $this->assertEqualsWithDelta(0.2, (float) $bad->get('grounding_score')->value, 0.001);
    $this->assertTrue((bool) $bad->get('trusted')->value);
  }

  /**
   * Demote mode withholds trust below the threshold and never grants it.
   */
  public function testDemoteNeverPromotes(): void {
    $this->setMode('demote');
    $this->assertTrue((bool) $this->remember(self::GOOD, self::PASSAGE)->get('trusted')->value);
    $this->assertFalse((bool) $this->remember(self::BAD, self::PASSAGE)->get('trusted')->value);

    // A score exactly at the threshold counts as grounded.
    $this->backend()->scores['At the line.'] = 0.7;
    $this->assertTrue((bool) $this->remember('At the line.', self::PASSAGE)->get('trusted')->value);

    // No source text: unchecked, the writer's starting trust stands.
    $unchecked = $this->remember('No source.', NULL);
    $this->assertNull($unchecked->get('grounding_score')->value);
    $this->assertTrue((bool) $unchecked->get('trusted')->value);

    // A writer without a trust permission: a well-grounded fact is scored
    // but still waits for review.
    $this->setUpCurrentUser([], ['create site aim facts']);
    $good = $this->remember(self::GOOD . ' Again.', self::PASSAGE);
    $this->assertNotNull($good->get('grounding_score')->value);
    $this->assertFalse((bool) $good->get('trusted')->value);
  }

  /**
   * A failed check leaves no score; demote mode then fails closed.
   */
  public function testFailureFailsClosed(): void {
    $this->backend()->fail = TRUE;

    $this->setMode('demote');
    $fact = $this->remember(self::GOOD, self::PASSAGE);
    $this->assertNull($fact->get('grounding_score')->value);
    $this->assertFalse((bool) $fact->get('trusted')->value);

    $this->setMode('record');
    $fact = $this->remember(self::GOOD . ' Again.', self::PASSAGE);
    $this->assertNull($fact->get('grounding_score')->value);
    $this->assertTrue((bool) $fact->get('trusted')->value);
  }

  /**
   * With no grounding model the check does not run, so nothing is demoted.
   */
  public function testNoModelNoCheck(): void {
    $this->setMode('demote');
    $this->config('aim.settings')->set('activities.grounding.provider', '')->set('activities.grounding.model', '')->save();
    $fact = $this->remember(self::BAD, self::PASSAGE);
    $this->assertSame(0, $this->backend()->calls);
    $this->assertNull($fact->get('grounding_score')->value);
    $this->assertTrue((bool) $fact->get('trusted')->value);
  }

  /**
   * Extraction checks every candidate in one call, and remember() reuses it.
   */
  public function testBatchIsOneCall(): void {
    $this->setMode('demote');
    $result = $this->container->get('aim.remember')->createFactsFromCandidates([
      ['scope' => 'site', 'subject' => '', 'text' => self::GOOD],
      ['scope' => 'site', 'subject' => '', 'text' => self::BAD],
    ], 'test', NULL, self::PASSAGE);
    $this->assertSame(1, $this->backend()->calls);
    [$good, $bad] = $result['created'];
    $this->assertEqualsWithDelta(0.92, (float) $good->get('grounding_score')->value, 0.001);
    $this->assertTrue((bool) $good->get('trusted')->value);
    $this->assertFalse((bool) $bad->get('trusted')->value);

    // The same passage and candidate again: answered from this request's
    // cache, no second call.
    $this->remember(self::GOOD, self::PASSAGE);
    $this->assertSame(1, $this->backend()->calls);
  }

  /**
   * Starting trust is the writer's permission, nothing else.
   */
  public function testStartingTrustIsPermission(): void {
    $this->setMode('off');
    $memory = $this->container->get('aim.remember');

    foreach (['review aim facts', 'trust site aim facts', 'administer aim'] as $permission) {
      $this->setUpCurrentUser([], [$permission]);
      $this->assertTrue((bool) $memory->remember('Trusted.', 'site', NULL, 'test')->get('trusted')->value, $permission);
    }

    // Create alone: the fact waits for review.
    $this->setUpCurrentUser([], ['create site aim facts']);
    $this->assertFalse((bool) $memory->remember('Draft.', 'site', NULL, 'test')->get('trusted')->value);

    // The entity default (the add form) follows the same rule.
    $this->assertFalse((bool) AimFact::create(['scope' => 'site', 'text' => 'Form.'])->get('trusted')->value);
    $this->setUpCurrentUser([], ['trust site aim facts']);
    $this->assertTrue((bool) AimFact::create(['scope' => 'site', 'text' => 'Form.'])->get('trusted')->value);
  }

  /**
   * Candidates go to the model in groups of max_questions.
   */
  public function testChunking(): void {
    $this->setMode('demote');
    $this->config('aim.settings')->set('activities.grounding.max_questions', 2)->save();
    $facts = [];
    foreach (['One.', 'Two.', 'Three.', 'Four.', 'Five.'] as $text) {
      $this->backend()->scores[$text] = 0.9;
      $facts[] = ['scope' => 'site', 'subject' => '', 'text' => $text];
    }
    $result = $this->container->get('aim.remember')->createFactsFromCandidates($facts, 'test', NULL, self::PASSAGE);
    $this->assertSame(3, $this->backend()->calls);
    $this->assertCount(5, $result['created']);
    foreach ($result['created'] as $fact) {
      $this->assertEqualsWithDelta(0.9, (float) $fact->get('grounding_score')->value, 0.001);
    }
  }

  /**
   * Off makes no call and records nothing.
   */
  public function testOffMakesNoCall(): void {
    $this->setMode('off');
    $fact = $this->remember(self::BAD, self::PASSAGE);
    $this->assertSame(0, $this->backend()->calls);
    $this->assertNull($fact->get('grounding_score')->value);
  }

  /**
   * Returns the fake decision backend the memory manager calls.
   *
   * @return \Drupal\Tests\aim\Kernel\FakeDecisionBackend
   *   The fake.
   */
  protected function backend(): FakeDecisionBackend {
    return $this->container->get('aim.backend.decision');
  }

  /**
   * Sets aim.settings:grounding_mode.
   *
   * @param string $mode
   *   Off, record or demote.
   */
  protected function setMode(string $mode): void {
    $this->config('aim.settings')->set('grounding_mode', $mode)->save();
  }

  /**
   * Remembers a site fact, optionally with source text.
   *
   * @param string $text
   *   The fact text.
   * @param string|null $sourceText
   *   The text it came from, or NULL.
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The saved fact.
   */
  protected function remember(string $text, ?string $sourceText): AimFact {
    return $this->container->get('aim.remember')->remember($text, 'site', NULL, 'test', sourceText: $sourceText);
  }

}
