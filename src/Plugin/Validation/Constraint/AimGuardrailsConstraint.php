<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Runs a field's text through aim's write guardrails (decision 7).
 *
 * Attached to aim_fact's text field, see AimFact::baseFieldDefinitions().
 * Replaces the earlier presave-hook approach (AimHooks::factPresave(),
 * removed) - a content entity form already calls $entity->validate()
 * itself (ContentEntityForm::validateForm()) and renders violations as
 * normal field errors, so a guardrail rejection on
 * /admin/content/aim-facts/add/site now shows as a field error instead of
 * crashing with an uncaught EntityStorageException (a 500, since
 * ContentEntityForm::save() does not catch a presave exception). See
 * AimMemoryManager::saveFact() for how a programmatic writer with no form
 * of its own (remember(), createFactsFromCandidates()) gets the same
 * check by calling validate() explicitly before saving.
 */
#[Constraint(
  id: 'AimGuardrails',
  label: new TranslatableMarkup('AIM write guardrails', [], ['context' => 'Validation']),
)]
final class AimGuardrailsConstraint extends SymfonyConstraint {
}
