<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\aim\Service\AimMemoryManager;

/**
 * Builds the notice both aim_fact delete confirmation forms show.
 *
 * Points to Retire, which keeps the fact and its history, and lists the
 * retired facts that point at the ones being deleted: deleting a fact
 * that replaced others leaves them retired with nothing in recall saying
 * what they said, unless they are un-retired first.
 */
final class AimFactDeleteNotice {

  use StringTranslationTrait;

  /**
   * Constructs the notice builder.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager, to find the retired facts.
   */
  public function __construct(
    private readonly AimMemoryManager $memoryManager,
  ) {}

  /**
   * Adds the notice to a confirmation form, just above its buttons.
   *
   * @param array $form
   *   The form.
   * @param int[] $ids
   *   The IDs of the facts being deleted.
   */
  public function attach(array &$form, array $ids): void {
    $actions = $form['actions'] ?? NULL;
    unset($form['actions']);
    $form['aim_delete_notice'] = $this->build($ids);
    if ($actions !== NULL) {
      $form['actions'] = $actions;
    }
  }

  /**
   * Builds the notice.
   *
   * @param int[] $ids
   *   The IDs of the facts being deleted.
   *
   * @return array
   *   A render array.
   */
  public function build(array $ids): array {
    $build = [
      'retire' => [
        '#markup' => '<p>' . $this->formatPlural(
          count($ids),
          'To stop recall using this fact, retire it instead: retiring keeps it and its history, and can be undone. Delete erases it and its revisions permanently.',
          'To stop recall using these facts, retire them instead: retiring keeps them and their history, and can be undone. Delete erases them and their revisions permanently.',
        ) . '</p>',
      ],
    ];

    $predecessors = $this->memoryManager->supersededBy($ids);
    if (!$predecessors) {
      return $build;
    }
    $items = [];
    foreach ($predecessors as $fact) {
      $items[] = $fact->toLink($fact->label() ?: $this->t('Fact @id', ['@id' => $fact->id()]))->toRenderable();
    }
    $build['predecessors'] = [
      '#theme' => 'item_list',
      '#prefix' => '<p>' . $this->formatPlural(
        count($predecessors),
        '1 retired fact was merged into or replaced by what you are deleting. Deleting leaves it retired with nothing replacing it. <a href=":list">Un-retire it</a> first if what it said should stay in recall.',
        '@count retired facts were merged into or replaced by what you are deleting. Deleting leaves them retired with nothing replacing them. <a href=":list">Un-retire them</a> first if what they said should stay in recall.',
        [':list' => Url::fromRoute('view.aim_facts.page_1')->toString()],
      ) . '</p>',
      '#items' => $items,
    ];
    return $build;
  }

}
