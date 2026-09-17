<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for aim's tunable numeric thresholds and LLM prompts.
 *
 * Everything here previously lived as hardcoded class constants and heredoc
 * strings on AimMemoryManager (no admin path to change them without a code
 * deploy) - see CLAUDE.md's "Admin settings" section. The structured-output
 * JSON schemas (decision/scope enums) stay code-defined in AimMemoryManager:
 * only the prose instructions around them are editable here, since the
 * schema shape is parsed by PHP downstream and isn't safe to hand to a text
 * field.
 *
 * Each element carries a #config_target, so ConfigFormBase loads the
 * default value, validates the submitted value against aim.schema.yml's
 * constraints (the 0..1 Range on both thresholds), and saves it - no
 * submitForm() of its own. validateForm() only adds the checks the schema
 * can't express: the cross-field ordering of the two thresholds, and the
 * placeholder tokens each prompt must keep.
 */
final class AimSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aim_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['aim.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['thresholds'] = [
      '#type' => 'details',
      '#title' => $this->t('Consolidation thresholds'),
      '#description' => $this->t("Cosine-distance values from the vector index (0 is identical, larger is less similar). These are specific to the current embeddings model and should be re-checked after any change to the vector server's embeddings engine."),
      '#open' => TRUE,
    ];
    $form['thresholds']['auto_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Auto-merge threshold'),
      '#description' => $this->t('Distance at or below which a candidate fact is retired as a duplicate automatically, no LLM call.'),
      '#config_target' => 'aim.settings:auto_threshold',
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.001,
      '#required' => TRUE,
    ];
    $form['thresholds']['ambiguous_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Ambiguous threshold'),
      '#description' => $this->t('Distance at or below which a candidate fact gets a single LLM classification call (ADD/UPDATE/DELETE/NOOP). Above this, facts are left alone at zero cost. Must be greater than the auto-merge threshold.'),
      '#config_target' => 'aim.settings:ambiguous_threshold',
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.001,
      '#required' => TRUE,
    ];

    $form['prompts'] = [
      '#type' => 'details',
      '#title' => $this->t('Prompts'),
      '#description' => $this->t('The instruction text sent to the chat provider for extraction and consolidation. The requested output shape (the ADD/UPDATE/DELETE/NOOP decision enum, the scope enum) is enforced by a structured-output JSON schema in code, not by this text - editing the prose below changes how the model reasons, not what fields it must return.'),
      '#open' => FALSE,
    ];
    $form['prompts']['extraction_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extraction prompt'),
      '#description' => $this->t('Used by <code>aim:extract</code>. Must contain the placeholder <code>{text}</code>, replaced with the source text being extracted from.'),
      '#config_target' => 'aim.settings:extraction_prompt',
      '#rows' => 14,
      '#required' => TRUE,
    ];
    $form['prompts']['consolidation_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Consolidation prompt'),
      '#description' => $this->t('Used by <code>aim:consolidate</code> and the automated consolidation queue worker, for pairs that fall in the ambiguous band. Must contain the placeholders <code>{kept_text}</code> (the established fact) and <code>{candidate_text}</code> (the newer fact being evaluated against it).'),
      '#config_target' => 'aim.settings:consolidation_prompt',
      '#rows' => 14,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $auto = (float) $form_state->getValue('auto_threshold');
    $ambiguous = (float) $form_state->getValue('ambiguous_threshold');
    if ($auto >= $ambiguous) {
      $form_state->setErrorByName('ambiguous_threshold', $this->t('The ambiguous threshold must be greater than the auto-merge threshold, or every ambiguous-band pair would be auto-merged instead of reviewed.'));
    }

    if (!str_contains((string) $form_state->getValue('extraction_prompt'), '{text}')) {
      $form_state->setErrorByName('extraction_prompt', $this->t('The extraction prompt must contain the placeholder %placeholder.', ['%placeholder' => '{text}']));
    }

    $consolidation_prompt = (string) $form_state->getValue('consolidation_prompt');
    foreach (['{kept_text}', '{candidate_text}'] as $placeholder) {
      if (!str_contains($consolidation_prompt, $placeholder)) {
        $form_state->setErrorByName('consolidation_prompt', $this->t('The consolidation prompt must contain the placeholder %placeholder.', ['%placeholder' => $placeholder]));
      }
    }
  }

}
