<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Entity\EntityListBuilder;

/**
 * List builder for aim_fact.
 *
 * Only used for its getOperations() (the Views "Operations links" field on
 * views.view.aim_facts reads through this), not for a collection page - see
 * CLAUDE.md, no collection link is defined for aim_fact.
 */
class AimFactListBuilder extends EntityListBuilder {

}
