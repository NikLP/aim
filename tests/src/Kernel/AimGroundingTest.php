<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The grounded check at write time (ADR-0037): modes, batching, failure.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimGroundingTest extends KernelTestBase {

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
    // Trusted by default, so shadow mode visibly leaves a bad fact trusted.
    $settings['default_trusted'] = TRUE;
    $this->config('aim.settings')->setData($settings)->save();
    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();

    $this->backend()->scores = [self::GOOD => 0.92, self::BAD => 0.2];
  }

  /**
   * Shadow mode records the score and leaves trusted alone.
   */
  public function testShadowRecordsScoreOnly(): void {
    $this->setMode('shadow');
    $bad = $this->remember(self::BAD, self::PASSAGE);
    $this->assertEqualsWithDelta(0.2, (float) $bad->get('grounding_score')->value, 0.001);
    $this->assertTrue((bool) $bad->get('trusted')->value);
  }

  /**
   * Enforce mode sets trusted from the score, but never over the caller.
   */
  public function testEnforceSetsTrusted(): void {
    $this->setMode('enforce');
    $this->assertTrue((bool) $this->remember(self::GOOD, self::PASSAGE)->get('trusted')->value);
    $this->assertFalse((bool) $this->remember(self::BAD, self::PASSAGE)->get('trusted')->value);

    // A score exactly at the threshold counts as grounded.
    $this->backend()->scores['At the line.'] = 0.7;
    $this->assertTrue((bool) $this->remember('At the line.', self::PASSAGE)->get('trusted')->value);

    // An explicit trusted value from the caller wins.
    $memory = $this->container->get('aim.memory_manager');
    $kept = $memory->remember('Overridden.', 'site', NULL, 'test', trusted: TRUE, sourceText: self::PASSAGE);
    $this->assertTrue((bool) $kept->get('trusted')->value);

    // No source text: unchecked, the site default applies.
    $unchecked = $this->remember('No source.', NULL);
    $this->assertNull($unchecked->get('grounding_score')->value);
    $this->assertTrue((bool) $unchecked->get('trusted')->value);
  }

  /**
   * A failed check leaves no score; enforce mode then fails closed.
   */
  public function testFailureFailsClosed(): void {
    $this->backend()->fail = TRUE;

    $this->setMode('enforce');
    $fact = $this->remember(self::GOOD, self::PASSAGE);
    $this->assertNull($fact->get('grounding_score')->value);
    $this->assertFalse((bool) $fact->get('trusted')->value);

    $this->setMode('shadow');
    $fact = $this->remember(self::GOOD . ' Again.', self::PASSAGE);
    $this->assertNull($fact->get('grounding_score')->value);
    $this->assertTrue((bool) $fact->get('trusted')->value);
  }

  /**
   * Extraction checks every candidate in one call, and remember() reuses it.
   */
  public function testBatchIsOneCall(): void {
    $this->setMode('enforce');
    $result = $this->container->get('aim.memory_manager')->createFactsFromCandidates([
      ['scope' => 'site', 'subject' => '', 'text' => self::GOOD],
      ['scope' => 'site', 'subject' => '', 'text' => self::BAD],
    ], 'test', NULL, NULL, self::PASSAGE);
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
   *   Off, shadow or enforce.
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
    return $this->container->get('aim.memory_manager')->remember($text, 'site', NULL, 'test', sourceText: $sourceText);
  }

}
