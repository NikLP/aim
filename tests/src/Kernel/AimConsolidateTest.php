<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The consolidation sweep: which pairs it compares and what it changes.
 *
 * Only the paths that need no model are covered (a near-identical pair is
 * retired by distance alone, a distant pair is never compared). The
 * ambiguous middle, where a decision model judges, is measured by the evals
 * in TESTS.md instead.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimConsolidateTest extends KernelTestBase {

  use FakeVectorSearchTrait;

  /**
   * Distance at or below which the newer fact is retired as a duplicate.
   */
  protected const AUTO = 0.09;

  /**
   * Distance above which a pair is not compared at all.
   */
  protected const AMBIGUOUS = 0.45;

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
   * A near-identical pair: the newer fact is retired, pointing at the older.
   */
  public function testNearDuplicateRetired(): void {
    [$kept, $dupe] = [$this->fact('Open at nine.'), $this->fact('Opens at nine.')];
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
    $dupe = $this->fact('Opens at nine.');
    $this->setScores([1 => 0.02, 2 => 0.03]);

    $result = $this->sweep(dryRun: TRUE);
    $this->assertSame('NOOP', $result['rows'][0][3]);
    $this->assertEmpty($this->reload($dupe)->get('retired')->value);
  }

  /**
   * Facts farther apart than the ambiguous threshold are left alone.
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
    $this->fact('Opens at nine.');
    $r1 = $this->fact('Closed Sundays.', 'role');
    $r2 = $this->fact('Shut on Sundays.', 'role');
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
    $this->fact('Opens at nine.', 'site', 1000);
    $this->setScores([1 => 0.02, 2 => 0.03]);

    $this->assertSame([], $this->sweep()['rows']);
    $this->assertEmpty($this->reload($live)->get('retired')->value);
  }

  /**
   * With no live facts the sweep says so.
   */
  public function testNoFacts(): void {
    $this->assertSame(['rows' => [], 'has_facts' => FALSE], $this->sweep());
  }

  /**
   * Runs the sweep with the shipped thresholds.
   *
   * @return array
   *   The consolidate() result.
   */
  protected function sweep(?string $scope = NULL, bool $dryRun = FALSE): array {
    return $this->container->get('aim.consolidation')->consolidate($scope, 'fake', 'fake-model', self::AUTO, self::AMBIGUOUS, $dryRun);
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
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The saved fact.
   */
  protected function fact(string $text, string $scope = 'site', ?int $retired = NULL): AimFact {
    $fact = AimFact::create(['scope' => $scope, 'text' => $text, 'trusted' => TRUE, 'uid' => 1, 'retired' => $retired]);
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
