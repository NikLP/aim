<?php

declare(strict_types=1);

namespace Drupal\aim_eca\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim_eca\AccountResolverTrait;
use Drupal\eca\Plugin\Action\ConfigurableActionBase;
use Drupal\eca\Plugin\ECA\PluginFormTrait;
use Drupal\search_api\IndexInterface;

/**
 * Runs a semantic query against aim's vector index from an ECA model.
 *
 * Read-side complement to FactWrite: runs the same
 * $index->query()->keys(...)->execute() call proven by drush aim:recall,
 * for a model that needs to branch on, or reuse, memory it already has.
 * Don't use this for something already available for free from the
 * current request or entity context - that's what FactWrite's own design
 * boundary already warns against on the write side, and it applies just as
 * much here.
 */
#[Action(
  id: 'aim_fact_query',
  label: new TranslatableMarkup('AIM: fact query'),
  category: new TranslatableMarkup('AIM'),
)]
final class FactQuery extends ConfigurableActionBase {

  use AccountResolverTrait;
  use PluginFormTrait;

  private const SCOPE_TOKEN = '_eca_token';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'token_name' => '',
      'text' => '',
      'scope' => '',
      'subject' => '',
      'limit' => 10,
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
      '#description' => $this->t('Provide the name of a token to hold the matching facts.'),
      '#required' => TRUE,
      '#weight' => -60,
      '#eca_token_reference' => TRUE,
    ];
    $form['text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search text'),
      '#default_value' => $this->configuration['text'],
      '#description' => $this->t('The text to semantically search memory for.'),
      '#required' => TRUE,
      '#weight' => -50,
      '#eca_token_replacement' => TRUE,
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
      '#description' => $this->t('Optional. Restrict results to one scope.'),
      '#weight' => -40,
      '#eca_token_select_option' => TRUE,
    ];
    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#default_value' => $this->configuration['subject'],
      '#description' => $this->t('Optional. Restrict results to one subject. For user scope, a uid or username of a real account.'),
      '#weight' => -30,
      '#eca_token_replacement' => TRUE,
    ];
    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Result limit'),
      '#default_value' => $this->configuration['limit'],
      '#min' => 1,
      '#required' => TRUE,
      '#weight' => -20,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['token_name'] = $form_state->getValue('token_name');
    $this->configuration['text'] = $form_state->getValue('text');
    $this->configuration['scope'] = $form_state->getValue('scope');
    $this->configuration['subject'] = $form_state->getValue('subject');
    $this->configuration['limit'] = (int) $form_state->getValue('limit');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $text = $this->tokenService->replaceClear($this->configuration['text']);
    if ($text === '') {
      throw new \InvalidArgumentException('The search text is empty.');
    }

    $scope = $this->configuration['scope'];
    if ($scope === self::SCOPE_TOKEN) {
      $scope = $this->getTokenValue('scope', '');
    }

    $subject = $this->tokenService->replaceClear($this->configuration['subject']);

    // Subject only ever means a uid/username filter when paired with user
    // scope (CLAUDE.md, "User-scope facts now require a real account") -
    // resolve it up front so an unresolvable value fails loudly instead of
    // silently matching nothing.
    $filter_account = NULL;
    if ($scope === 'user' && $subject !== '') {
      $filter_account = $this->resolveAccount($subject);
      if (!$filter_account) {
        throw new \InvalidArgumentException("No user account found for subject '$subject'.");
      }
    }

    $index = $this->entityTypeManager->getStorage('search_api_index')->load('aim_vector_index');
    if (!$index instanceof IndexInterface) {
      throw new \RuntimeException('The aim_vector_index search index does not exist.');
    }

    $limit = (int) $this->configuration['limit'];
    $query = $index->query()->keys($text);
    if ($scope !== '') {
      $query->addCondition('scope', $scope);
    }
    if ($subject !== '' && $scope !== 'user') {
      $query->addCondition('subject', $subject);
    }
    // subject_uid and expires are not indexed attributes, so a user-scope
    // subject filter and excluding retired facts both happen below as a
    // post-filter instead of a query condition; over-fetch to compensate.
    $query->range(0, $filter_account ? $limit * 5 : $limit);

    $facts = [];
    foreach ($query->execute() as $result) {
      $fact = $result->getOriginalObject()->getValue();
      if (!$fact->get('expires')->isEmpty()) {
        continue;
      }
      if ($filter_account && (int) $fact->get('subject_uid')->target_id !== (int) $filter_account->id()) {
        continue;
      }
      $facts[] = $fact;
      if (count($facts) >= $limit) {
        break;
      }
    }

    // A plain array doesn't resolve to a known token type (only entities and
    // DataTransferObjects do - see TokenDecoratorTrait::getTokenType()), so
    // addTokenData() wraps it in a DataTransferObject automatically. That is
    // the same mechanism eca's own ListOperationBase relies on to hand a
    // model a list token, confirmed by reading addTokenData()'s source
    // rather than assumed: an array with no known token type falls through
    // to $dto->setValue($data), where $dto is the DataTransferObject just
    // registered under this key.
    $this->tokenService->addTokenData($this->configuration['token_name'], $facts);
  }

}
