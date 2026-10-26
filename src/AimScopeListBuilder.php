<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists the scopes, dragged into the order models see them in.
 *
 * Same shape as core's VocabularyListBuilder: the weight sets the order of
 * the scope guide, the tools' scope lists and aim_brief's groups.
 */
class AimScopeListBuilder extends DraggableListBuilder {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'aim_scope_list';
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Name');
    $header['status'] = $this->t('Enabled');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\aim\Entity\AimScope $entity */
    $row['label'] = $entity->label();
    $row['status'] = ['#markup' => $entity->status() ? $this->t('Yes') : $this->t('No')];
    return $row + parent::buildRow($entity);
  }

}
