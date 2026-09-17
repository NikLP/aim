<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for the aim_fact entity type.
 *
 * Core's generic EntityViewsData does not register a bulk-form field row;
 * only an entity type's own Views data class does that (NodeViewsData's
 * node_bulk_form, MediaViewsData's media_bulk_form). Reuses core's own
 * concrete bulk_form field plugin directly - it is not abstract, see
 * \Drupal\views\Plugin\views\field\BulkForm - since nothing here needs to
 * override its behavior.
 */
class AimFactViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    $data['aim_fact']['aim_fact_bulk_form'] = [
      'title' => $this->t('AIM fact operations bulk form'),
      'help' => $this->t('Add a form element that lets you run operations on multiple facts.'),
      'field' => [
        'id' => 'bulk_form',
      ],
    ];

    return $data;
  }

}
