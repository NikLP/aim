<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * List builder for aim_scope.
 *
 * Core's own EntityListBuilder::buildHeader()/buildRow() only add an
 * Operations column - every core bundle list builder (NodeTypeListBuilder,
 * MediaTypeListBuilder, RoleListBuilder) adds its own Label column on top.
 * aim_scope had none, so /admin/config/aim/scopes rendered as a table of
 * bare Edit/Delete dropbuttons with no way to tell which row was which
 * scope. Mirrors RoleListBuilder (label only, no description field to
 * show) rather than NodeTypeListBuilder/MediaTypeListBuilder, since
 * aim_scope's payload is deliberately id/label only (see AimScope's
 * docblock).
 */
class AimScopeListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Name');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['label'] = $entity->label();
    return $row + parent::buildRow($entity);
  }

}
