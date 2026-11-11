<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Lets a fact's scope plugin refuse its subject (validateSubject()).
 *
 * Attached to aim_fact's subject field, see AimFact::baseFieldDefinitions().
 * Checks a new fact's subject and an edited one only, so a fact whose
 * subject was valid when written (a role since deleted, say) still saves.
 */
#[Constraint(
  id: 'AimScopeSubject',
  label: new TranslatableMarkup('AIM scope subject', [], ['context' => 'Validation']),
)]
final class AimScopeSubjectConstraint extends SymfonyConstraint {
}
