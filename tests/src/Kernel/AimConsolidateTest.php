<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The consolidation sweep: which pairs it compares and what it changes.
 *
 * Only the paths that need no model are covered (identical text is retired
 * with no call, a distant pair or a pair with different end dates is never
 * compared), plus that any other close pair reaches for the model. The
 * ambiguous middle, where a decision model judges, is measured by the evals
 * in TESTS.md instead.
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
   * With no live facts the sweep says so.
   */
  public function testNoFacts(): void {
    $this->assertSame(['rows' => [], 'has_facts' => FALSE], $this->sweep());
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
