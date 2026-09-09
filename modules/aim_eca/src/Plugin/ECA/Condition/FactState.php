<?php

declare(strict_types=1);

namespace Drupal\aim_eca\Plugin\ECA\Condition;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim_eca\AccountResolverTrait;
use Drupal\eca\Attribute\EcaCondition;
use Drupal\eca\Plugin\ECA\Condition\ConditionBase;

/**
 * Checks whether an aim_fact with a given boolean state exists.
 *
 * A direct loadByProperties() lookup against aim_fact's own table, not a
 * vector query: a flag-shaped fact doesn't need semantic similarity to
 * retrieve, just an indexed lookup by scope and subject.
 */
#[EcaCondition(
  id: 'aim_fact_state',
  label: new TranslatableMarkup('AIM: fact state'),
  category: new TranslatableMarkup('AIM'),
)]
final class FactState extends ConditionBase {

  use AccountResolverTrait;

  private const STATE_TOKEN = '_eca_token';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'scope' => 'user',
      'subject' => '',
      'state' => 'true',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
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
    $form['state'] = [
      '#type' => 'select',
      '#title' => $this->t('Expected state'),
      '#default_value' => $this->configuration['state'],
      '#options' => [
        'true' => $this->t('True'),
        'false' => $this->t('False'),
      ],
      '#required' => TRUE,
      '#weight' => -30,
      '#eca_token_select_option' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['scope'] = $form_state->getValue('scope');
    $this->configuration['subject'] = $form_state->getValue('subject');
    $this->configuration['state'] = $form_state->getValue('state');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(): bool {
    $scope = $this->configuration['scope'];
    if ($scope === self::STATE_TOKEN) {
      $scope = $this->getTokenValue('scope', 'user');
    }

    $subject = $this->tokenService->replaceClear($this->configuration['subject']);

    $state = $this->configuration['state'];
    if ($state === self::STATE_TOKEN) {
      $state = $this->getTokenValue('state', 'true');
    }

    $properties = [
      'scope' => $scope,
      'state' => $state === 'true',
    ];

    if ($scope === 'user') {
      // A user-scope fact has to be about a real account (CLAUDE.md, "User-
      // scope facts now require a real account") - match against subject_uid,
      // not the string subject field, which is left empty for user scope.
      $account = $subject !== '' ? $this->resolveAccount($subject) : NULL;
      if (!$account) {
        // No such account: this condition cannot possibly match.
        return $this->negationCheck(FALSE);
      }
      $properties['subject_uid'] = $account->id();
    }
    else {
      $properties['subject'] = $subject;
    }

    $facts = $this->entityTypeManager->getStorage('aim_fact')->loadByProperties($properties);

    return $this->negationCheck(!empty($facts));
  }

}
