<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Hands aim a document without a shell (ADR-0016).
 *
 * The upload is read into memory and never kept: no managed File entity is
 * created.
 */
final class AimIngestForm extends FormBase {

  /**
   * Largest accepted upload, in bytes. Extraction sends the file whole.
   */
  const MAX_BYTES = 102400;

  /**
   * Constructs an AimIngestForm object.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected AimMemoryManager $memoryManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('aim.memory_manager'), $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aim_ingest_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['enctype'] = 'multipart/form-data';

    if ($result = $form_state->get('result')) {
      $form['result'] = $this->buildResult($result);
    }

    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Input'),
      '#default_value' => 'extract',
      '#options' => [
        'extract' => $this->t('Extract with a model (prose)'),
        'lines' => $this->t('One fact per line (no model)'),
        'json' => $this->t('JSON (advanced, no model)'),
      ],
      '#description' => $this->t('One per line: each non-empty line is saved as a fact, with the scope and subject chosen below. Extract: a model reads the text and decides which facts are worth keeping, and their scope. JSON: an array of fact objects as for <code>drush aim:remember --file</code>, for mixed scopes, case IDs or entity targets.'),
    ];

    $form['upload'] = [
      '#type' => 'file',
      '#title' => $this->t('File'),
      '#description' => $this->t('A .txt file (.json in JSON mode), up to @size KB. The file is read and not kept. Extraction sends the whole file to the model in one request, so there is no chunking yet.', ['@size' => self::MAX_BYTES / 1024]),
    ];

    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Or paste text'),
      '#rows' => 8,
      '#description' => $this->t('Used when no file is chosen.'),
    ];

    $scopes = [];
    foreach ($this->entityTypeManager->getStorage('aim_scope')->loadMultiple($this->memoryManager->allowedScopes()) as $id => $scope) {
      $scopes[$id] = $scope->label();
    }
    $form['lines'] = [
      '#type' => 'details',
      '#title' => $this->t('Scope for one-fact-per-line'),
      '#open' => TRUE,
      '#states' => ['visible' => [':input[name="mode"]' => ['value' => 'lines']]],
    ];
    $form['lines']['scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Scope'),
      '#options' => $scopes,
      '#empty_option' => $this->t('- Select -'),
      '#states' => ['required' => [':input[name="mode"]' => ['value' => 'lines']]],
    ];
    $form['lines']['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#description' => $this->t('For the user scope, a uid or username. Leave empty for site scope. For the case scope, empty mints a new case ID per line.'),
      '#states' => ['invisible' => [':input[name="scope"]' => ['value' => 'entity']]],
    ];
    $form['lines']['target_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Target entity type'),
      '#description' => $this->t('Entity scope only, for example node.'),
      '#states' => ['visible' => [':input[name="scope"]' => ['value' => 'entity']]],
    ];
    $form['lines']['target_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Target entity ID'),
      '#description' => $this->t('Entity scope only.'),
      '#states' => ['visible' => [':input[name="scope"]' => ['value' => 'entity']]],
    ];

    $form['subject_uid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User for user-scope facts'),
      '#description' => $this->t('A uid of a real account. Facts the model files under the user scope attach to it; without one they are skipped.'),
      '#states' => ['visible' => [':input[name="mode"]' => ['value' => 'extract']]],
    ];

    $form['source'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Source'),
      '#description' => $this->t('Provenance tag stored on each fact. Defaults to <code>upload:&lt;filename&gt;</code>, or <code>paste</code> for pasted text. JSON entries that name their own source keep it.'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ingest'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $mode = $form_state->getValue('mode');
    $file = $this->getUpload();
    if ($file) {
      if (!$file->isValid()) {
        $form_state->setErrorByName('upload', $this->t('The upload failed: @error', ['@error' => $file->getErrorMessage()]));
        return;
      }
      if ($file->getSize() > self::MAX_BYTES) {
        $form_state->setErrorByName('upload', $this->t('The file is larger than @size KB.', ['@size' => self::MAX_BYTES / 1024]));
        return;
      }
      $ext = strtolower($file->getClientOriginalExtension());
      if (!in_array($ext, $mode === 'json' ? ['json'] : ['txt'], TRUE)) {
        $form_state->setErrorByName('upload', $this->t('Upload a @type file for this input.', ['@type' => $mode === 'json' ? '.json' : '.txt']));
        return;
      }
    }
    elseif (trim((string) $form_state->getValue('text')) === '') {
      $form_state->setErrorByName('upload', $this->t('Choose a file or paste some text.'));
      return;
    }
    elseif (strlen((string) $form_state->getValue('text')) > self::MAX_BYTES) {
      $form_state->setErrorByName('text', $this->t('The text is longer than @size KB.', ['@size' => self::MAX_BYTES / 1024]));
      return;
    }

    if ($mode === 'lines' && !$form_state->getValue('scope')) {
      $form_state->setErrorByName('scope', $this->t('Choose a scope for one-fact-per-line input.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mode = $form_state->getValue('mode');
    $file = $this->getUpload();
    $text = $file ? (string) file_get_contents($file->getPathname()) : (string) $form_state->getValue('text');
    $source = trim((string) $form_state->getValue('source'))
      ?: ($file ? 'upload:' . $file->getClientOriginalName() : 'paste');

    $result = ['created' => [], 'errors' => [], 'skipped' => 0, 'blocked' => 0, 'mode' => $mode];
    try {
      if ($mode === 'extract') {
        $r = $this->memoryManager->ingestText($text, $source, trim((string) $form_state->getValue('subject_uid')) ?: NULL);
        $result = $r + $result;
        if ($r['extracted'] === 0) {
          $this->messenger()->addWarning($this->t('The model returned no facts worth remembering.'));
        }
      }
      else {
        if ($mode === 'json') {
          $entries = json_decode($text, TRUE);
          if (!is_array($entries)) {
            throw new \InvalidArgumentException('Expected a JSON array of fact objects.');
          }
        }
        else {
          $entries = [];
          foreach (preg_split('/\R/', $text) as $line) {
            if (trim($line) !== '') {
              $entries[] = [
                'text' => trim($line),
                'scope' => $form_state->getValue('scope'),
                'subject' => trim((string) $form_state->getValue('subject')) ?: NULL,
                'target_type' => trim((string) $form_state->getValue('target_type')) ?: NULL,
                'target_id' => trim((string) $form_state->getValue('target_id')) ?: NULL,
              ];
            }
          }
        }
        $r = $this->memoryManager->rememberBatch($entries, $source);
        $result['created'] = $r['created'];
        $result['errors'] = $r['errors'];
        $result['total'] = count($entries);
      }
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    catch (\Throwable $e) {
      $this->logger('aim')->error('Ingest failed: @class: @message', [
        '@class' => $e::class,
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Ingest failed. See the log for details.'));
    }

    $this->memoryManager->logAudit('Ingest form run by uid @uid (mode @mode): @created created, @errors errors, @skipped skipped, @blocked blocked.', [
      '@uid' => $this->currentUser()->id(),
      '@mode' => $mode,
      '@created' => count($result['created']),
      '@errors' => count($result['errors']),
      '@skipped' => $result['skipped'],
      '@blocked' => $result['blocked'],
    ]);

    $form_state->set('result', $result);
    $form_state->setRebuild();
  }

  /**
   * Returns the uploaded file, if one was sent.
   *
   * @return \Symfony\Component\HttpFoundation\File\UploadedFile|null
   *   The upload, or NULL.
   */
  protected function getUpload(): ?UploadedFile {
    $files = $this->getRequest()->files->get('files', []);
    $file = $files['upload'] ?? NULL;
    return $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE ? $file : NULL;
  }

  /**
   * Builds the result summary shown after a submit.
   *
   * @param array $result
   *   The result array stored by submitForm().
   *
   * @return array
   *   A render array.
   */
  protected function buildResult(array $result): array {
    $build = ['#type' => 'details', '#title' => $this->t('Result'), '#open' => TRUE];

    $summary = [$this->formatPlural(count($result['created']), '1 fact created.', '@count facts created.')];
    if (!empty($result['errors'])) {
      $summary[] = $this->formatPlural(count($result['errors']), '1 entry failed.', '@count entries failed.');
    }
    if (!empty($result['skipped'])) {
      $summary[] = $this->t('@count user-scope fact(s) skipped: no user was given to attach them to.', ['@count' => $result['skipped']]);
    }
    if (!empty($result['blocked'])) {
      $summary[] = $this->t('@count fact(s) blocked by a guardrail check.', ['@count' => $result['blocked']]);
    }
    $summary[] = $this->t('Consolidation of these facts runs later, via the queue.');
    $build['summary'] = ['#theme' => 'item_list', '#items' => $summary];

    if (!empty($result['errors'])) {
      $items = [];
      foreach ($result['errors'] as $i => $message) {
        $items[] = $this->t('Entry @n: @message', ['@n' => $i + 1, '@message' => $message]);
      }
      $build['errors'] = ['#theme' => 'item_list', '#title' => $this->t('Errors'), '#items' => $items];
    }

    if (!empty($result['created'])) {
      $rows = array_map(fn (AimFact $fact): array => [
        $fact->id(),
        $fact->bundle(),
        $this->memoryManager->scopeRequiresAccount($fact->bundle()) ? $fact->get('user')->target_id : $fact->get('subject')->value,
        $fact->get('text')->value,
      ], array_values($result['created']));
      $build['table'] = [
        '#type' => 'table',
        '#header' => [$this->t('ID'), $this->t('Scope'), $this->t('Subject'), $this->t('Text')],
        '#rows' => $rows,
      ];
    }
    return $build;
  }

}
