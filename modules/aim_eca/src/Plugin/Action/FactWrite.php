<?php

declare(strict_types=1);

namespace Drupal\aim_eca\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim_eca\AccountResolverTrait;
use Drupal\eca\Plugin\Action\ConfigurableActionBase;
use Drupal\eca\Plugin\ECA\PluginFormTrait;

/**
 * Writes an aim_fact entity from values supplied by an ECA model.
 *
 * This is a write path only: it stores whatever the model hands it, exactly
 * like drush aim:extract does. It does not itself decide what is worth
 * remembering. Don't wire a model to write a fact for something already
 * available from the current request (the logged-in user's roles, the node
 * being viewed, the current path) - that's redundant with, and can drift
 * out of sync with, data Drupal already gives you for free. This action is
 * for things that would otherwise be lost once the triggering event passes:
 * a value from a submitted webform, a decision made mid-workflow, something
 * pulled from an external system.
 */
#[Action(
  id: 'aim_fact_write',
  label: new TranslatableMarkup('AIM: fact write'),
  category: new TranslatableMarkup('AIM'),
)]
final class FactWrite extends ConfigurableActionBase {

  use AccountResolverTrait;
  use PluginFormTrait;

  private const STATE_UNSET = 'unset';
  private const STATE_TRUE = 'true';
  private const STATE_FALSE = 'false';
  private const STATE_TOKEN = '_eca_token';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'token_name' => '',
      'scope' => 'site',
      'subject' => '',
      'text' => '',
      'source' => '',
      'state' => self::STATE_UNSET,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['token_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name of token'),
      '#default_value' => $this->configuration['token_name'],
      '#description' => $this->t('Optional. Provide the name of a token to hold the created fact.'),
      '#weight' => -60,
      '#eca_token_reference' => TRUE,
    ];
    $form['scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Scope'),
      '#default_value' => $this->configuration['scope'],
      '#options' => [
        'user' => $this->t('User'),
        'role' => $this->t('Role'),
        'site' => $this->t('Site'),
        'case' => $this->t('Case'),
      ],
      '#required' => TRUE,
      '#weight' => -50,
      '#eca_token_select_option' => TRUE,
    ];
    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $this->configuration['subject'],
      '#description' => $this->t('Who or what the fact is about: for user scope, a uid or username of a real account; otherwise a role machine name or a case ID. Leave empty for site scope.'),
      '#weight' => -40,
      '#eca_token_replacement' => TRUE,
    ];
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Text'),
      '#default_value' => $this->configuration['text'],
      '#description' => $this->t('The fact itself, as one short, self-contained statement.'),
      '#required' => TRUE,
      '#weight' => -30,
      '#eca_token_replacement' => TRUE,
    ];
    $form['source'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Source'),
      '#default_value' => $this->configuration['source'],
      '#description' => $this->t('Provenance reference. Leave empty to default to the ECA model ID.'),
      '#weight' => -20,
      '#eca_token_replacement' => TRUE,
    ];
    $form['state'] = [
      '#type' => 'select',
      '#title' => $this->t('State'),
      '#default_value' => $this->configuration['state'],
      '#options' => [
        self::STATE_UNSET => $this->t('Not set (this fact is not a flag)'),
        self::STATE_TRUE => $this->t('True'),
        self::STATE_FALSE => $this->t('False'),
      ],
      '#description' => $this->t('Only set this when the fact is itself an on/off assertion, e.g. "opted out of marketing email".'),
      '#required' => TRUE,
      '#weight' => -10,
      '#eca_token_select_option' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['token_name'] = $form_state->getValue('token_name');
    $this->configuration['scope'] = $form_state->getValue('scope');
    $this->configuration['subject'] = $form_state->getValue('subject');
    $this->configuration['text'] = $form_state->getValue('text');
    $this->configuration['source'] = $form_state->getValue('source');
    $this->configuration['state'] = $form_state->getValue('state');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $scope = $this->configuration['scope'];
    if ($scope === self::STATE_TOKEN) {
      $scope = $this->getTokenValue('scope', '');
    }
    if (!in_array($scope, ['user', 'role', 'site', 'case'], TRUE)) {
      throw new \InvalidArgumentException("Invalid or unresolved scope: '$scope'. Must be one of user, role, site, case.");
    }

    $text = $this->tokenService->replaceClear($this->configuration['text']);
    if ($text === '') {
      throw new \InvalidArgumentException('The fact text is empty.');
    }

    // Same guardrail check drush aim:remember and the chatbot's aim_remember
    // action run before creating a fact (CLAUDE.md decision 7) - a value an
    // ECA model hands this action is proposed content the same way an
    // extracted or chatbot-written fact is, not inherently more trusted.
    \Drupal::service('aim.memory_manager')->runGuardrails($text);

    $subject = $this->tokenService->replaceClear($this->configuration['subject']);

    $source = $this->tokenService->replaceClear($this->configuration['source']);
    if ($source === '') {
      $source = 'eca:' . $this->ecaModelId;
    }

    $values = [
      'scope' => $scope,
      'text' => $text,
      'source' => $source,
    ];

    if ($scope === 'user') {
      // A user-scope fact has to be about a real account: subject_uid is a
      // genuine entity_reference, not a free-text string that merely
      // happens to hold a uid (CLAUDE.md, "User-scope facts now require a
      // real account").
      if ($subject === '') {
        throw new \InvalidArgumentException('Subject is required for user scope: a uid or username of a real account on this site.');
      }
      $account = $this->resolveAccount($subject);
      if (!$account) {
        throw new \InvalidArgumentException("No user account found for subject '$subject'. A user-scope fact must be about a real account.");
      }
      $values['subject_uid'] = $account->id();
      $values['subject'] = '';
    }
    else {
      $values['subject'] = $subject;
    }

    $state = $this->configuration['state'];
    if ($state === self::STATE_TOKEN) {
      $state = $this->getTokenValue('state', self::STATE_UNSET);
    }
    if ($state === self::STATE_TRUE) {
      $values['state'] = TRUE;
    }
    elseif ($state === self::STATE_FALSE) {
      $values['state'] = FALSE;
    }

    $fact = $this->entityTypeManager->getStorage('aim_fact')->create($values);
    $fact->save();
    \Drupal::service('aim.memory_manager')->enqueueForConsolidation((int) $fact->id());

    if ($this->configuration['token_name'] !== '') {
      $this->tokenService->addTokenData($this->configuration['token_name'], $fact);
    }
  }

}
