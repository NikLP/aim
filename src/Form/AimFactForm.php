<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\aim\AimScopeTypePluginManagerInterface;
use Drupal\aim\Service\AimRememberService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add and edit form for a fact.
 *
 * Core's form with no "Create new revision" checkbox: a text change always
 * saves a new revision and nothing else does (AimHooks::factPresave(),
 * ADR-0064). The edit form says when to revise and when to add a new fact
 * instead (decision 2), and offers the revision log message. Which subject
 * fields show is the scope plugin's (AimHooks::entityFormDisplayAlter());
 * on the edit form they are read-only, since a fact about someone or
 * something else is a new fact. Expires and asserted are days, not times
 * (the last and first day the fact is true, in the site timezone,
 * ADR-0057), so they are plain date elements here, read and written
 * through AimRememberService like every other write path, rather than
 * core's date-and-time widget. Laid out like the node form: the fact on
 * the left; status, dates and revision information in the sidebar
 * (rendered by Gin for the routes in AimHooks::ginContentFormRoutes()).
 */
class AimFactForm extends ContentEntityForm {

  /**
   * The scope type plugin manager, for the subject field names.
   */
  protected AimScopeTypePluginManagerInterface $scopeTypeManager;

  /**
   * The remember service, for expires as a day in the site timezone.
   */
  protected AimRememberService $rememberService;

  /**
   * The date formatter, for the sidebar's created and saved times.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->scopeTypeManager = $container->get('plugin.manager.aim_scope_type');
    $form->rememberService = $container->get('aim.remember');
    $form->dateFormatter = $container->get('date.formatter');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareEntity() {
    parent::prepareEntity();
    // Like core's revision UI: the log starts empty for the next revision.
    // factPresave() puts the current one back when the text is unchanged.
    if (!$this->entity->isNew()) {
      $this->entity->setRevisionLogMessage(NULL);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $this->buildSidebar($form);
    if ($this->entity->isNew()) {
      $form['revision_log_message']['#access'] = FALSE;
      return $form;
    }

    $fixed = FALSE;
    foreach ($this->scopeTypeManager->getSubjectFieldNames() as $name) {
      if (isset($form[$name])) {
        $form[$name]['#disabled'] = TRUE;
        $fixed = TRUE;
      }
    }

    if ($fixed) {
      $form['subject_fixed'] = [
        '#type' => 'item',
        '#description' => $this->t('Who or what this fact is about is fixed once it is saved. For someone or something else, add a new fact.'),
        '#weight' => 25,
      ];
    }

    if (isset($form['text']['widget'][0]['value'])) {
      $form['text']['widget'][0]['value']['#description'] = $this->t('Correct the wording of the same fact here: the old wording is kept as a revision. If what is true has changed, add a new fact instead and retire this one.');
    }

    if (isset($form['revision_log_message']['widget'][0]['value'])) {
      $form['revision_log_message']['widget'][0]['value']['#description'] = $this->t('Why the wording changed. Saved only with a change to the text: other changes do not make a revision.');
    }

    return $form;
  }

  /**
   * Builds the sidebar: status, dates and revision information.
   *
   * The dates sit in the status group, not a section of their own. The group
   * is #tree like NodeForm's (so its read-only items, e.g. changed, cannot
   * overwrite entity values); the dates set #parents to stay top-level.
   *
   * Vertical tabs without Gin, the node-style sidebar with it.
   *
   * @param array $form
   *   The form, as parent::form() built it.
   */
  protected function buildSidebar(array &$form): void {
    /** @var \Drupal\aim\Entity\AimFact $fact */
    $fact = $this->entity;
    $form['advanced'] = [
      '#type' => 'vertical_tabs',
      '#weight' => 99,
    ];
    $form['meta'] = [
      '#type' => 'container',
      '#group' => 'advanced',
      '#weight' => -10,
      '#title' => $this->t('Status'),
      '#attributes' => ['class' => ['entity-meta__header']],
      '#tree' => TRUE,
    ];
    $form['meta']['scope'] = [
      '#type' => 'item',
      '#title' => $this->t('Scope'),
      '#plain_text' => $fact->get('scope')->entity?->label() ?? $fact->bundle(),
    ];
    if (!$fact->isNew()) {
      $form['meta']['author'] = [
        '#type' => 'item',
        '#title' => $fact->getFieldDefinition('uid')->getLabel(),
        '#plain_text' => $fact->getOwner()?->getDisplayName() ?? $this->t('Unknown'),
      ];
      $form['meta']['created'] = [
        '#type' => 'item',
        '#title' => $this->t('Created'),
        '#markup' => $this->dateFormatter->format((int) $fact->get('created')->value, 'short'),
      ];
      $form['meta']['changed'] = [
        '#type' => 'item',
        '#title' => $this->t('Last saved'),
        '#markup' => $this->dateFormatter->format((int) $fact->get('changed')->value, 'short'),
        '#wrapper_attributes' => ['class' => ['entity-meta__last-saved']],
      ];
      $score = $fact->get('grounding_score')->value;
      $form['meta']['grounding_score'] = [
        '#type' => 'item',
        '#title' => $this->t('Grounding score'),
        '#markup' => $score === NULL ? $this->t('Not checked') : number_format((float) $score, 2),
      ];
    }
    if (isset($form['trusted'])) {
      $form['trusted']['#group'] = 'meta';
      $form['trusted']['#weight'] = -10;
    }

    $asserted = $fact->get('asserted')->value;
    $form['meta']['asserted_day'] = [
      '#type' => 'date',
      '#title' => $fact->getFieldDefinition('asserted')->getLabel(),
      '#description' => $fact->getFieldDefinition('asserted')->getDescription(),
      '#default_value' => $asserted ? $this->rememberService->formatDay((int) $asserted) : NULL,
      '#parents' => ['asserted_day'],
      '#weight' => 100,
    ];
    $expires = $fact->get('expires')->value;
    $form['meta']['expires_day'] = [
      '#type' => 'date',
      '#title' => $fact->getFieldDefinition('expires')->getLabel(),
      '#description' => $fact->getFieldDefinition('expires')->getDescription(),
      '#default_value' => $expires ? $this->rememberService->formatDay((int) $expires) : NULL,
      '#parents' => ['expires_day'],
      '#weight' => 110,
    ];

    if (!$fact->isNew() && isset($form['revision_log_message'])) {
      $form['revision_information'] = [
        '#type' => 'details',
        '#title' => $this->t('Revision information'),
        '#group' => 'advanced',
        '#weight' => 10,
      ];
      $form['revision_log_message']['#group'] = 'revision_information';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $day = (string) $form_state->getValue('expires_day');
    $expires = $this->rememberService->parseExpires(['expires' => $day]);
    // A day already past is refused only as a new value: an expired fact
    // the sweep has not reached yet can still be edited.
    if ($expires !== NULL && $day !== $this->currentDay() && $this->rememberService->isPast($expires)) {
      $form_state->setErrorByName('expires_day', $this->t('Expires is already past (today is @today).', [
        '@today' => $this->rememberService->today(),
      ]));
    }
    $since = (string) $form_state->getValue('asserted_day');
    $today = $this->rememberService->formatDay($this->time->getRequestTime());
    if ($since !== '' && $since > $today) {
      $form_state->setErrorByName('asserted_day', $this->t('Asserted cannot be after today (@today).', [
        '@today' => $today,
      ]));
    }
    elseif ($since !== '' && $day !== '' && $since > $day) {
      $form_state->setErrorByName('asserted_day', $this->t('Asserted cannot be after Expires.'));
    }
    return parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $entity = parent::buildEntity($form, $form_state);
    $entity->set('expires', $this->rememberService->parseExpires(['expires' => (string) $form_state->getValue('expires_day')]));
    // Only a changed day is written: a tool may have set a time of day,
    // which an untouched form should keep.
    $since = (string) $form_state->getValue('asserted_day');
    $current = $this->entity->get('asserted')->value;
    if ($since !== ($current ? $this->rememberService->formatDay((int) $current) : '')) {
      $entity->set('asserted', $this->rememberService->parseDayStart($since));
    }
    return $entity;
  }

  /**
   * Returns the saved fact's expires day.
   *
   * @return string|null
   *   The day, YYYY-MM-DD, or NULL for a new fact or one with no end.
   */
  protected function currentDay(): ?string {
    $value = $this->entity->isNew() ? NULL : $this->entity->get('expires')->value;
    return $value ? $this->rememberService->formatDay((int) $value) : NULL;
  }

}
