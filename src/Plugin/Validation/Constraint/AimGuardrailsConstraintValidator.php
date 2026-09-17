<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\aim\Service\AimMemoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates AimGuardrailsConstraint against a field's current text.
 */
final class AimGuardrailsConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs an AimGuardrailsConstraintValidator object.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager, used to run the shipped guardrail set.
   */
  public function __construct(
    protected AimMemoryManager $memoryManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('aim.memory_manager'));
  }

  /**
   * {@inheritdoc}
   *
   * $value is the whole FieldItemListInterface being validated (text is
   * single-value, so only delta 0 matters) - the same shape
   * ValidReferenceConstraintValidator (core) receives for a field-level
   * constraint.
   */
  public function validate($value, Constraint $constraint): void {
    if (!$value instanceof FieldItemListInterface || $value->isEmpty()) {
      return;
    }

    $text = (string) $value->value;

    try {
      $checked = $this->memoryManager->runGuardrails($text);
    }
    catch (\InvalidArgumentException $e) {
      $this->context->addViolation($e->getMessage());
      return;
    }

    if ($checked !== $text) {
      // A RewriteInputResult guardrail changed the text - write the
      // rewrite back so it is what actually gets saved. This only
      // survives to the saved value for a programmatic caller
      // (AimMemoryManager::saveFact() validates and saves the same
      // $entity object) - the entity add/edit form rebuilds a fresh
      // entity from raw form input at submit time
      // (ContentEntityForm::submitForm()), independent of the one
      // validateForm() ran this against, so a rewrite made here would not
      // survive to a form-driven save. No shipped guardrail actually
      // rewrites today (both aim_max_length and aim_no_markup are
      // Stop-only - see
      // config/install/ai.ai_guardrail_set.aim_write_guardrails.yml), so
      // this is forward-compatible dead code today, not a known live gap.
      $value->setValue($checked);
    }
  }

}
