<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Lets a fact's scope plugin refuse its subject (validateSubject()).
 *
 * An entity-level constraint on aim_fact (its #[ContentEntityType]
 * attribute), since a subject can span fields (target_type/target_id).
 * Checks a new fact, and a saved one only when a subject field changed, so
 * a fact whose subject was valid when written (a role since deleted, say)
 * still saves.
 */
#[Constraint(
  id: 'AimScopeSubject',
  label: new TranslatableMarkup('AIM scope subject', [], ['context' => 'Validation']),
)]
final class AimScopeSubjectConstraint extends SymfonyConstraint {
}
