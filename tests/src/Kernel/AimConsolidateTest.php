<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The consolidation sweep: which pairs it compares and what it changes.
 *
 * The paths that need no model (identical text is retired with no call, a
 * distant pair or a pair with different end dates is never compared), that
 * any other close pair reaches for the model, and what happens to an UPDATE
 * once scripted models (FakeDecisionBackend, ScriptedMergeConsolidationService)
 * chose it. How well a real model judges the ambiguous middle is measured
 * by the evals in TESTS.md instead.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimConsolidateTest extends KernelTestBase {

  use FakeVectorSearchTrait;

  /**
   * Distance above which a pair is not compared at all.
   */
  protected const COMPARE = 0.45;

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
    'search_api_test',
    'literals',
    'aim',
  ];

  /**
   * The established fact in the merge tests.
   */
  protected const KEPT = 'Members can borrow up to 20 items.';

  /**
   * The correction in the merge tests.
   */
  protected const CORRECTION = 'Members can borrow up to 10 items.';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('aim.backend.decision', FakeDecisionBackend::class);
    $container->getDefinition('aim.consolidation')->setClass(ScriptedMergeConsolidationService::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('literal');
    $this->installEntitySchema('aim_fact');
    $path = $this->container->get('extension.list.module')->getPath('aim');
    $this->config('aim.settings')
      ->setData(Yaml::decode(file_get_contents($path . '/config/install/aim.settings.yml')))
      ->save();
    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    AimScope::create(['id' => 'role', 'label' => 'Role'])->save();
    $this->installFakeVectorIndex();
  }

  /**
   * Identical text: the newer fact is retired, pointing at the older.
   *
   * Case, spacing and a final full stop are ignored. The 'fake' provider
   * does not exist, so any model call would throw.
   */
  public function testIdenticalTextRetiredWithoutModel(): void {
    [$kept, $dupe] = [$this->fact('Open at nine.'), $this->fact('open at  nine')];
    $this->setScores([$kept->id() => 0.02, $dupe->id() => 0.03]);

    $result = $this->sweep();
    $this->assertTrue($result['has_facts']);
    $this->assertCount(1, $result['rows'], 'the pair is compared once, not from both sides');
    [$keptId, $candidateId, , $decision] = $result['rows'][0];
    $this->assertSame([(int) $kept->id(), (int) $dupe->id(), 'NOOP'], [(int) $keptId, (int) $candidateId, $decision]);

    $this->assertEmpty($this->reload($kept)->get('retired')->value);
    $retired = $this->reload($dupe);
    $this->assertNotEmpty($retired->get('retired')->value);
    $this->assertSame((int) $kept->id(), (int) $retired->get('superseded_by')->target_id);
    $this->assertNotEmpty($retired->get('superseded_by_reason')->value);
  }

  /**
   * A dry run reports the decision and changes nothing.
   */
  public function testDryRunChangesNothing(): void {
    $this->fact('Open at nine.');
    $dupe = $this->fact('Open at nine.');
    $this->setScores([1 => 0.02, 2 => 0.03]);

    $result = $this->sweep(dryRun: TRUE);
    $this->assertSame('NOOP', $result['rows'][0][3]);
    $this->assertEmpty($this->reload($dupe)->get('retired')->value);
  }

  /**
   * Facts farther apart than the ambiguous distance are left alone.
   */
  public function testDistantFactsUntouched(): void {
    $a = $this->fact('Open at nine.');
    $b = $this->fact('The cat is called Biscuit.');
    $this->setScores([$a->id() => 0.7, $b->id() => 0.8]);

    $this->assertSame([], $this->sweep()['rows']);
    $this->assertEmpty($this->reload($b)->get('retired')->value);
  }

  /**
   * Near-identical facts in different scopes are not compared.
   */
  public function testScopesAreNotCompared(): void {
    $site = $this->fact('Open at nine.');
    $role = $this->fact('Open at nine.', 'role');
    $this->setScores([$site->id() => 0.01, $role->id() => 0.01]);

    $this->assertSame([], $this->sweep()['rows']);
    $this->assertEmpty($this->reload($role)->get('retired')->value);
  }

  /**
   * The scope argument limits the sweep to that scope.
   */
  public function testScopeArgumentLimitsSweep(): void {
    $this->fact('Open at nine.');
    $this->fact('Open at nine.');
    $r1 = $this->fact('Closed Sundays.', 'role');
    $r2 = $this->fact('Closed Sundays.', 'role');
    $this->setScores([1 => 0.02, 2 => 0.03, $r1->id() => 0.02, $r2->id() => 0.03]);

    $rows = $this->sweep(scope: 'role')['rows'];
    $this->assertCount(1, $rows);
    $this->assertEmpty($this->reload(AimFact::load(2))->get('retired')->value, 'the site pair was not swept');
    $this->assertNotEmpty($this->reload($r2)->get('retired')->value);
  }

  /**
   * Already-retired facts are not swept or used as neighbors.
   */
  public function testRetiredFactsIgnored(): void {
    $live = $this->fact('Open at nine.');
    $this->fact('Open at nine.', 'site', 1000);
    $this->setScores([1 => 0.02, 2 => 0.03]);

    $this->assertSame([], $this->sweep()['rows']);
    $this->assertEmpty($this->reload($live)->get('retired')->value);
  }

  /**
   * A fact with an end date is never merged with one without.
   */
  public function testDifferentLifetimesNotCompared(): void {
    $lasting = $this->fact('Open Monday to Friday from nine.');
    $dated = $this->fact('Closed for refurbishment until 30 November.', expires: strtotime('2026-11-30 23:59:59'));
    $other = $this->fact('Closed until 30 November for refurbishment.', expires: strtotime('2026-12-31 23:59:59'));
    $this->setScores([$lasting->id() => 0.02, $dated->id() => 0.03, $other->id() => 0.04]);

    $this->assertSame([], $this->sweep()['rows']);
    foreach ([$lasting, $dated, $other] as $fact) {
      $this->assertEmpty($this->reload($fact)->get('retired')->value);
    }
  }

  /**
   * Facts with the same end date are still compared.
   */
  public function testSameEndDateCompared(): void {
    $end = strtotime('2026-11-30 23:59:59');
    $kept = $this->fact('Closed until 30 November.', expires: $end);
    $dupe = $this->fact('Closed until 30 November.', expires: $end);
    $this->setScores([$kept->id() => 0.02, $dupe->id() => 0.03]);

    $rows = $this->sweep()['rows'];
    $this->assertCount(1, $rows);
    $this->assertSame('NOOP', $rows[0][3]);
    $this->assertNotEmpty($this->reload($dupe)->get('retired')->value);
  }

  /**
   * A close but different pair goes to the model, however close it is.
   */
  public function testClosePairGoesToModel(): void {
    $kept = $this->fact('Members can borrow up to 20 items.');
    $correction = $this->fact('Members can borrow up to 10 items.');
    $this->setScores([$kept->id() => 0.01, $correction->id() => 0.016]);

    try {
      $this->sweep();
      $this->fail('the pair was decided without a model call');
    }
    catch (PluginNotFoundException) {
      // The sweep reached for the (missing) model, as it should.
    }
    $this->assertEmpty($this->reload($correction)->get('retired')->value);
  }

  /**
   * An UPDATE's merged text gets the grounded check against both inputs.
   *
   * Shadow mode records the score on the merged fact; off makes no call.
   */
  public function testMergeIsGrounded(): void {
    $this->scriptMerge(self::CORRECTION, 0.95);
    $this->config('aim.settings')->set('grounding_mode', 'shadow')->save();
    [$kept, $correction] = [$this->fact(self::KEPT), $this->fact(self::CORRECTION)];
    $this->setScores([$kept->id() => 0.01, $correction->id() => 0.016]);

    $this->assertSame('UPDATE', $this->sweep()['rows'][0][3]);
    $grounding = array_values(array_filter($this->backend()->log, fn ($call) => $call[0] === 'grounding'));
    $this->assertCount(1, $grounding);
    $this->assertSame(['text' => self::KEPT . "\n" . self::CORRECTION, 'candidate_1' => self::CORRECTION], $grounding[0][1]);
    $merged = $this->reload($kept)->get('superseded_by')->entity;
    $this->assertEqualsWithDelta(0.95, (float) $merged->get('grounding_score')->value, 0.001);
    $this->assertTrue((bool) $merged->get('trusted')->value);

    // Off: the same merge, no grounding call, no score.
    $this->config('aim.settings')->set('grounding_mode', 'off')->save();
    $this->backend()->log = [];
    [$kept, $correction] = [$this->fact(self::KEPT), $this->fact(self::CORRECTION)];
    $this->setScores([$kept->id() => 0.01, $correction->id() => 0.016]);

    $this->assertSame('UPDATE', $this->sweep()['rows'][0][3]);
    $this->assertSame(['consolidation', 'verifier'], array_column($this->backend()->log, 0));
    $this->assertNull($this->reload($kept)->get('superseded_by')->entity->get('grounding_score')->value);
  }

  /**
   * Enforce mode keeps both facts when the merge is not grounded.
   *
   * Like a merge the verifier rejects: downgraded to ADD, nothing retired,
   * no merged fact.
   */
  public function testUngroundedMergeKeepsBoth(): void {
    $this->scriptMerge('Members can borrow up to 10 items, or 15 with a family card.', 0.1);
    $this->config('aim.settings')->set('grounding_mode', 'enforce')->save();
    [$kept, $correction] = [$this->fact(self::KEPT), $this->fact(self::CORRECTION)];
    $this->setScores([$kept->id() => 0.01, $correction->id() => 0.016]);

    $this->assertSame('ADD', $this->sweep()['rows'][0][3]);
    foreach ([$kept, $correction] as $fact) {
      $this->assertEmpty($this->reload($fact)->get('retired')->value);
    }
    $this->assertCount(2, AimFact::loadMultiple());
  }

  /**
   * With no live facts the sweep says so.
   */
  public function testNoFacts(): void {
    $this->assertSame(['rows' => [], 'has_facts' => FALSE], $this->sweep());
  }

  /**
   * Scripts an UPDATE: classifier, merge writer, verifier and grounding.
   *
   * Also creates the facts' owner, since saving the merged fact validates
   * its uid.
   *
   * @param string $mergedText
   *   The merged text the writer returns.
   * @param float $grounding
   *   The merged text's grounding score.
   */
  protected function scriptMerge(string $mergedText, float $grounding): void {
    $model = ['backend' => 'decision', 'provider' => 'fake', 'model' => 'fake-model'];
    $this->config('aim.settings')
      ->set('activities.consolidation', $model)
      ->set('activities.verifier', $model)
      ->set('activities.grounding', $model + ['threshold' => 0.7])
      ->save();
    $this->backend()->answers = ['decision' => 'UPDATE', 'faithful' => 0.9];
    $this->backend()->scores = [$mergedText => $grounding];
    $this->container->get('aim.consolidation')->mergedText = $mergedText;
    User::create(['uid' => 1, 'name' => 'owner', 'status' => 1])->save();
  }

  /**
   * Returns the fake decision backend.
   *
   * @return \Drupal\Tests\aim\Kernel\FakeDecisionBackend
   *   The fake.
   */
  protected function backend(): FakeDecisionBackend {
    return $this->container->get('aim.backend.decision');
  }

  /**
   * Runs the sweep with the shipped comparison distance.
   *
   * @param string|null $scope
   *   The scope to sweep, or NULL for all.
   * @param bool $dryRun
   *   Whether to change nothing.
   *
   * @return array
   *   The consolidate() result.
   */
  protected function sweep(?string $scope = NULL, bool $dryRun = FALSE): array {
    return $this->container->get('aim.consolidation')->consolidate($scope, 'fake', 'fake-model', self::COMPARE, $dryRun);
  }

  /**
   * Saves a trusted fact.
   *
   * @param string $text
   *   The fact text.
   * @param string $scope
   *   The scope.
   * @param int|null $retired
   *   A retired timestamp, or NULL for a live fact.
   * @param int|null $expires
   *   An end date timestamp, or NULL for none.
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The saved fact.
   */
  protected function fact(string $text, string $scope = 'site', ?int $retired = NULL, ?int $expires = NULL): AimFact {
    $fact = AimFact::create([
      'scope' => $scope,
      'text' => $text,
      'trusted' => TRUE,
      'uid' => 1,
      'retired' => $retired,
      'expires' => $expires,
    ]);
    $fact->save();
    return $fact;
  }

  /**
   * Reloads a fact from storage.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact.
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The fresh copy.
   */
  protected function reload(AimFact $fact): AimFact {
    $this->container->get('entity_type.manager')->getStorage('aim_fact')->resetCache([$fact->id()]);
    return AimFact::load($fact->id());
  }

}
