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
 * constraints (the 0..1 Range on each distance threshold), and saves it - no
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

    $form['thresholds']['merge_verify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verify merged text with a model'),
      '#description' => $this->t('Before an UPDATE merge is accepted, a model confirms every detail of both facts survives. A failing merge is downgraded to ADD (keep both). Checks faithfulness to the inputs, not real-world truth.'),
      '#config_target' => 'aim.settings:merge_verify',
    ];
    $form['thresholds']['merge_verifier_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Verifier model'),
      '#description' => $this->t('Provider and model as <code>provider__model</code>, so a different model can check the first (e.g. a local Ollama model). Empty uses the classification model.'),
      '#config_target' => 'aim.settings:merge_verifier_model',
    ];
    $form['thresholds']['merge_max_distance'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum merged-to-input distance'),
      '#description' => $this->t('Cosine distance within which the merged text must sit from each input embedding, else the merge is downgraded to ADD. 0 disables. Unmeasured: leave at 0 until calibrated.'),
      '#config_target' => 'aim.settings:merge_max_distance',
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.001,
    ];

    $form['recall'] = [
      '#type' => 'details',
      '#title' => $this->t('Recall'),
      '#open' => TRUE,
    ];
    $form['recall']['recall_max_distance'] = [
      '#type' => 'number',
      '#title' => $this->t('Chatbot recall maximum distance'),
      '#description' => $this->t("Cosine distance above which a match is dropped from the chatbot's recall tool, so a poor match becomes an honest \"no relevant facts\" instead of being presented as relevant. Question-to-fact distances run larger than the fact-to-fact ones above, and are specific to the current embeddings model: re-check after any change to it."),
      '#config_target' => 'aim.settings:recall_max_distance',
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.001,
      '#required' => TRUE,
    ];

    $form['governance'] = [
      '#type' => 'details',
      '#title' => $this->t('Governance'),
      '#description' => $this->t('The initial value a newly created fact gets for its own Trusted field (ADR-0002) - not a moderation gate, and editable per-fact afterward on the add/edit form.'),
      '#open' => TRUE,
    ];
    $form['governance']['default_trusted'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Trust new facts by default'),
      '#description' => $this->t('When off (recommended until the extraction/consolidation pipeline is vetted), a newly created fact starts untrusted and is excluded from recall() until someone reviews it at <code>/admin/content/aim-facts</code> and checks its Trusted box.'),
      '#config_target' => 'aim.settings:default_trusted',
    ];

    $form['logging'] = [
      '#type' => 'details',
      '#title' => $this->t('Logging'),
      '#description' => $this->t('Warnings and errors (rejected writes, blocked merges, a suspended queue) are always logged to the <em>aim</em> channel. The two options below add info and debug entries. Fact text and recall query text are never logged.'),
      '#open' => FALSE,
    ];
    $form['logging']['log_audit'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log audit events'),
      '#description' => $this->t('Writes, consolidation decisions, and trust, untrust, retire and unretire actions, as IDs, scope, user and decision.'),
      '#config_target' => 'aim.settings:log_audit',
    ];
    $form['logging']['log_verbose'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log verbose detail'),
      '#description' => $this->t('Recall, embedding cache and extraction detail. Noisy on a busy site.'),
      '#config_target' => 'aim.settings:log_verbose',
    ];
    $form['logging']['log_query_text'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include recall query text in verbose logs'),
      '#description' => $this->t('Adds the query to each recall entry, for diagnosing a poor or empty match. Queries can contain personal data and will be stored in the log: enable only while investigating, then turn off. Has no effect unless verbose logging is on. Fact text is never logged.'),
      '#config_target' => 'aim.settings:log_query_text',
      '#states' => ['disabled' => [':input[name="log_verbose"]' => ['checked' => FALSE]]],
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
    // Query text logging rides on the verbose tier; enforce that here
    // rather than trusting #states, which is client-side only.
    if (!$form_state->getValue('log_verbose')) {
      $form_state->setValue('log_query_text', FALSE);
    }

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
