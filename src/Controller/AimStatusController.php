<?php

declare(strict_types=1);

namespace Drupal\aim\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\aim\AimStatusChecker;

/**
 * Reports the vector search checks AimStatusChecker runs, as an admin page.
 *
 * Deliberately not on /admin/reports/status - these checks (HNSW tuning,
 * the provider shim, the recall cutoff) are aim-specific diagnostics, not
 * general site health, so they don't belong alongside the checks every
 * admin is expected to treat as alarming. Built from the same pieces that
 * page is built from - the '#type' => 'status_report' element
 * (\Drupal\Core\Render\Element\StatusReport) for the grouped/collapsible
 * list, and the 'status_report_counter' theme hook for the colored count
 * chips above it - rather than the full '#type' => 'status_report_page',
 * which would also pull in that page's Drupal-version/PHP/database
 * "General System Information" panel, which has nothing to do with aim.
 */
final class AimStatusController extends ControllerBase {

  use AutowireTrait;

  /**
   * Constructs an AimStatusController object.
   *
   * @param \Drupal\aim\AimStatusChecker $statusChecker
   *   The status checker.
   */
  public function __construct(
    protected AimStatusChecker $statusChecker,
  ) {}

  /**
   * Builds the status page.
   *
   * @return array
   *   A render array.
   */
  public function build(): array {
    // A passing check omits 'severity' (defaults to RequirementSeverity::Info
    // and groups/counts as "Checked") rather than setting
    // RequirementSeverity::OK - the same convention core's own
    // hook_requirements() implementations follow. StatusReportPage's own
    // counter logic never counts an explicit OK, only Info, so setting OK
    // here would silently drop passing checks from the chips below.
    $requirements = array_map(static function (array $check): array {
      $requirement = ['title' => $check['label'], 'value' => $check['detail']];
      if (!$check['ok']) {
        $requirement['severity'] = RequirementSeverity::Error;
      }

      return $requirement;
    }, $this->statusChecker->checkAll());

    return [
      '#attached' => ['library' => ['system/status.report']],
      'counters' => $this->buildCounters($requirements),
      'requirements' => [
        '#type' => 'status_report',
        '#requirements' => $requirements,
      ],
    ];
  }

  /**
   * Builds the colored count chips shown above the requirements list.
   *
   * Only error/warning get a chip - unlike /admin/reports/status, there's
   * no "Checked" chip for passing checks, since with a handful of checks
   * total it adds noise without adding information (the list below already
   * shows each one passed).
   *
   * @param array $requirements
   *   The requirements array passed to the status_report element.
   *
   * @return array
   *   A render array.
   */
  private function buildCounters(array $requirements): array {
    $labels = [
      'error' => [$this->t('Error'), $this->t('Errors')],
      'warning' => [$this->t('Warning'), $this->t('Warnings')],
    ];

    $amounts = ['error' => 0, 'warning' => 0];
    foreach ($requirements as $requirement) {
      $status = ($requirement['severity'] ?? RequirementSeverity::Info)->status();
      if (isset($amounts[$status])) {
        $amounts[$status]++;
      }
    }

    $amounts = array_filter($amounts);
    if (!$amounts) {
      return [];
    }

    $widthClass = count($amounts) === 2 ? 'system-status-report-counters__item--half-width' : NULL;

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['system-status-report-counters']],
    ];

    foreach ($amounts as $severity => $amount) {
      $build[$severity] = [
        '#type' => 'container',
        '#attributes' => ['class' => array_filter(['system-status-report-counters__item', $widthClass])],
        'counter' => [
          '#theme' => 'status_report_counter',
          '#amount' => $amount,
          '#text' => new PluralTranslatableMarkup($amount, $labels[$severity][0], $labels[$severity][1]),
          '#severity' => $severity,
        ],
      ];
    }

    return $build;
  }

}
