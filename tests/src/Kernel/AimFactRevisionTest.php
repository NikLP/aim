<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Fact revisions: only a text change makes one (ADR-0064).
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimFactRevisionTest extends AimKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    // Uid 1 bypasses every access check; keep it out of the way.
    $this->createUser([], 'root');
  }

  /**
   * A new fact's first revision is its writer's.
   */
  public function testNewFactRevisionIsTheWriters(): void {
    $writer = $this->createUser();
    $fact = $this->fact(['uid' => $writer->id()]);
    $this->assertSame((int) $writer->id(), (int) $fact->getRevisionUserId());
    $this->assertCount(1, $this->revisionIds($fact));
  }

  /**
   * Trust, retirement and a score save in place and keep the metadata.
   */
  public function testStateChangesMakeNoRevision(): void {
    $fact = $this->fact();
    $revision = $fact->getRevisionId();
    $created = $fact->getRevisionCreationTime();

    $this->setCurrentUser($this->createUser());
    $fact = $this->reload($fact);
    $fact->set('trusted', FALSE)->set('retired', 1000)->set('grounding_score', 0.5)->save();

    $fact = $this->reload($fact);
    $this->assertSame($revision, $fact->getRevisionId());
    $this->assertEquals($created, $fact->getRevisionCreationTime());
    $this->assertSame('first', $fact->getRevisionLogMessage());
    $this->assertEquals(0.5, $fact->get('grounding_score')->value);
  }

  /**
   * A text edit makes a revision, by the editor, and clears the score.
   */
  public function testTextEditMakesRevision(): void {
    $fact = $this->fact();
    $old = $fact->getRevisionId();
    $editor = $this->createUser();
    $this->setCurrentUser($editor);

    $fact = $this->reload($fact);
    $fact->set('text', 'The library opens at 9.');
    $fact->setRevisionLogMessage('Typo.');
    $fact->save();

    $fact = $this->reload($fact);
    $this->assertNotSame($old, $fact->getRevisionId());
    $this->assertSame((int) $editor->id(), (int) $fact->getRevisionUserId());
    $this->assertSame('Typo.', $fact->getRevisionLogMessage());
    $this->assertNull($fact->get('grounding_score')->value);

    // The same object as created, whose score is a float, not a string.
    $other = $this->fact();
    $other->set('text', 'Reworded.')->save();
    $this->assertNull($this->reload($other)->get('grounding_score')->value);

    /** @var \Drupal\aim\Entity\AimFact $previous */
    $previous = $this->storage()->loadRevision($old);
    $this->assertSame('The library opens at 9', $previous->get('text')->value);
    $this->assertEquals(0.9, $previous->get('grounding_score')->value);
    $this->assertSame('first', $previous->getRevisionLogMessage());
  }

  /**
   * A text edit that sets its own score keeps it; an unset log is cleared.
   */
  public function testTextEditKeepsNewScore(): void {
    $fact = $this->reload($this->fact());
    $fact->set('text', 'The library opens at 10.')->set('grounding_score', 0.7)->save();
    $fact = $this->reload($fact);
    $this->assertEquals(0.7, $fact->get('grounding_score')->value);
    $this->assertEmpty($fact->getRevisionLogMessage());
  }

  /**
   * Reverting to an old revision restores its wording and its score.
   */
  public function testRevertRestoresWordingAndScore(): void {
    $fact = $this->fact();
    $old = $fact->getRevisionId();
    $this->reload($fact)->set('text', 'The library opens at 10.')->save();

    /** @var \Drupal\aim\Entity\AimFact $revision */
    $revision = $this->storage()->loadRevision($old);
    $revision->setNewRevision();
    $revision->isDefaultRevision(TRUE);
    $revision->save();

    $fact = $this->reload($fact);
    $this->assertSame('The library opens at 9', $fact->get('text')->value);
    $this->assertEquals(0.9, $fact->get('grounding_score')->value);
    $this->assertCount(3, $this->revisionIds($fact));
  }

  /**
   * The revision pages are for administer aim only.
   */
  public function testRevisionAccess(): void {
    $fact = $this->fact();
    $this->reload($fact)->set('text', 'The library opens at 10.')->save();
    $fact = $this->reload($fact);
    $old = $this->storage()->loadRevision($this->revisionIds($fact)[0]);

    $reviewer = $this->createUser(['view site aim facts', 'review aim facts']);
    $admin = $this->createUser(['administer aim']);
    foreach (['view all revisions', 'view revision', 'revert', 'delete revision'] as $operation) {
      $this->assertFalse($old->access($operation, $reviewer), $operation . ': reviewer');
      $this->assertTrue($old->access($operation, $admin), $operation . ': admin');
    }
    $this->assertFalse($fact->access('revert', $admin), 'the current revision cannot be reverted to');
  }

  /**
   * Saves a trusted site fact with a score and a log message.
   *
   * @param array $values
   *   Values to override.
   */
  protected function fact(array $values = []): AimFact {
    $fact = AimFact::create($values + [
      'scope' => 'site',
      'text' => 'The library opens at 9',
      'trusted' => TRUE,
      'grounding_score' => 0.9,
      'revision_log_message' => 'first',
      'uid' => 1,
    ]);
    $fact->save();
    return $fact;
  }

  /**
   * The fact's revision IDs, oldest first.
   *
   * @return int[]
   *   The revision IDs.
   */
  protected function revisionIds(AimFact $fact): array {
    return array_map('intval', array_keys($this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $fact->id())
      ->sort('revision_id')
      ->execute()));
  }

  /**
   * The fact storage.
   *
   * @return \Drupal\Core\Entity\RevisionableStorageInterface
   *   The storage.
   */
  protected function storage() {
    return $this->container->get('entity_type.manager')->getStorage('aim_fact');
  }

  /**
   * Reloads a fact from storage.
   */
  protected function reload(AimFact $fact): AimFact {
    $this->storage()->resetCache([$fact->id()]);
    return AimFact::load($fact->id());
  }

}
