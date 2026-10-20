<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_maintenance_center.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the dedicated central Maintenance Center orchestration surface.
 *
 * Responsibilities:
 *   - Render durable job status, read-only analysis/review, and progress containers
 *   - Expose only server-prepared endpoint/token/presentation data to the browser module
 *   - Keep maintenance policy, task execution, persistence, and authorization outside the view
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\format_bytes;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/** Safely encode browser bootstrap data without executable HTML sequences.
 *
 * @param array<string, mixed>|null $value Persisted job state, localized browser strings, or null for no job.
 * @return string Encoded JSON or an empty object when encoding fails.
 */
function view_maintenance_center_json(?array $value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT,
    );
    return is_string($json) ? $json : '{}';
}

/** Render the dedicated Maintenance Center page from server-prepared job state.
 *
 * @param array{
 *   job?: array<string, mixed>|null,
 *   endpoints?: array<string, string>,
 *   dashboard?: array<string, mixed>,
 *   available?: bool,
 *   blocked_by_other_job?: bool,
 *   installation_blocker?: array<string, mixed>|null,
 *   csrf_token?: string,
 *   storage_maintenance_url?: string,
 *   dashboard_url?: string
 * } $model Prepared workflow state, navigation URLs, CSRF token, and endpoints.
 * @return void Outputs the Maintenance Center page.
 */
function view_render_admin_maintenance_center_page(array $model): void
{
    $job = is_array($model['job'] ?? null) ? $model['job'] : null;
    $endpoints = is_array($model['endpoints'] ?? null)
        ? $model['endpoints']
        : [];
    $dashboard = is_array($model['dashboard'] ?? null)
        ? $model['dashboard']
        : [];
    $available = !empty($model['available']);
    $blockedByOtherJob = !empty($model['blocked_by_other_job']);
    $installationBlocker = is_array($model['installation_blocker'] ?? null)
        ? $model['installation_blocker']
        : null;
    $csrf = (string) ($model['csrf_token'] ?? '');

    $strings = [
        'analysis_read_only' => t(
            'admin.maintenance_center.analysis_read_only',
            'Analysis is read-only. No changes have been made.',
        ),
        'analyzing' => t(
            'admin.maintenance_center.status.analyzing',
            'Analyzing installation',
        ),
        'ready' => t(
            'admin.maintenance_center.status.ready',
            'Analysis ready for review',
        ),
        'running' => t(
            'admin.maintenance_center.status.running',
            'Maintenance running',
        ),
        'paused' => t(
            'admin.maintenance_center.status.paused',
            'Maintenance paused',
        ),
        'completed' => t(
            'admin.maintenance_center.status.completed',
            'Maintenance completed',
        ),
        'failed' => t(
            'admin.maintenance_center.status.failed',
            'Maintenance failed',
        ),
        'cancelled' => t(
            'admin.maintenance_center.status.cancelled',
            'Maintenance cancelled',
        ),
        'idle' => t('admin.maintenance_center.status.idle', 'Ready to analyze'),
        'analyze' => t(
            'admin.maintenance_center.analyze',
            'Analyze & optimize',
        ),
        'analyze_again' => t(
            'admin.maintenance_center.analyze_again',
            'Analyze again',
        ),
        'run' => t('admin.maintenance_center.run', 'Run maintenance'),
        'resume' => t('admin.maintenance_center.resume', 'Resume maintenance'),
        'pause' => t('admin.maintenance_center.pause', 'Pause'),
        'cancel' => t('admin.maintenance_center.cancel', 'Cancel'),
        'retry' => t(
            'admin.maintenance_center.retry',
            'Continue from saved checkpoint',
        ),
        'network_error' => t(
            'admin.maintenance_center.network_error',
            'The request did not complete. The saved checkpoint is intact; continue to retry safely.',
        ),
        'request_failed' => t(
            'admin.maintenance_center.request_failed',
            'The maintenance request failed.',
        ),
        'current' => t('admin.maintenance_center.current', 'Current'),
        'work' => t('admin.maintenance_center.work', 'Work'),
        'unavailable' => t(
            'admin.maintenance_center.unavailable',
            'Unavailable',
        ),
        'required' => t('admin.maintenance_center.required', 'Required'),
        'required_automatic' => t(
            'admin.maintenance_center.required_automatic',
            'Required · automatic',
        ),
        'no_work_count' => t(
            'admin.maintenance_center.no_work_count',
            'No work detected ({count})',
        ),
        'estimated_rows' => t(
            'admin.maintenance_center.estimated_rows',
            'Estimated affected rows',
        ),
        'estimated_bytes' => t(
            'admin.maintenance_center.estimated_bytes',
            'Estimated reclaimable bytes',
        ),
        'estimated_units' => t(
            'admin.maintenance_center.estimated_units',
            'Estimated work units',
        ),
        'full_optimize' => t(
            'admin.maintenance_center.full_optimize',
            'Also optimize eligible tables with no estimated reclaimable space',
        ),
        'large_table_warning' => t(
            'admin.maintenance_center.large_table_warning',
            'Large tables remain excluded from normal browser maintenance.',
        ),
        'recent_activity' => t(
            'admin.maintenance_center.recent_activity',
            'Recent activity',
        ),
        'warnings' => t('admin.maintenance_center.warnings', 'Warnings'),
        'errors' => t('admin.maintenance_center.errors', 'Errors'),
        'review_title' => t(
            'admin.maintenance_center.review_title',
            'Review maintenance plan',
        ),
        'result_title' => t(
            'admin.maintenance_center.result_title',
            'Before / after result',
        ),
        'rows_removed' => t(
            'admin.maintenance_center.rows_removed',
            'Rows removed',
        ),
        'files_removed' => t(
            'admin.maintenance_center.files_removed',
            'Files removed',
        ),
        'filesystem_bytes_removed' => t(
            'admin.maintenance_center.filesystem_bytes_removed',
            'Filesystem bytes removed',
        ),
        'rows_removed_breakdown' => t(
            'admin.maintenance_center.rows_removed_breakdown',
            'Rows removed by subsystem',
        ),
        'analyzed_tables_detail' => t(
            'admin.maintenance_center.analyzed_tables_detail',
            'Analyzed tables',
        ),
        'optimized_tables_detail' => t(
            'admin.maintenance_center.optimized_tables_detail',
            'Optimized tables',
        ),
        'skipped_detail' => t(
            'admin.maintenance_center.skipped_detail',
            'Skipped tasks/tables',
        ),
        'tables_analyzed' => t(
            'admin.maintenance_center.tables_analyzed',
            'Tables analyzed',
        ),
        'tables_optimized' => t(
            'admin.maintenance_center.tables_optimized',
            'Tables optimized',
        ),
        'tasks_skipped' => t(
            'admin.maintenance_center.tasks_skipped',
            'Tasks/tables skipped',
        ),
        'database_before' => t(
            'admin.maintenance_center.database_before',
            'Database before',
        ),
        'database_after' => t(
            'admin.maintenance_center.database_after',
            'Database after',
        ),
        'database_delta' => t(
            'admin.maintenance_center.database_delta',
            'Measured database change',
        ),
        'duration' => t('admin.maintenance_center.duration', 'Duration'),
        'unknown' => t('admin.common.unknown', 'Unknown'),
        'none' => t('admin.common.none', 'None'),
        'job' => t('admin.maintenance_center.job', 'Job'),
        'no_activity' => t(
            'admin.maintenance_center.no_activity',
            'No maintenance activity yet.',
        ),
        'rollup_days' => t(
            'admin.maintenance_center.fact.rollup_days',
            'Rollup days',
        ),
        'eligible_days' => t(
            'admin.maintenance_center.fact.eligible_days',
            'Eligible days',
        ),
        'candidates' => t(
            'admin.maintenance_center.fact.candidates',
            'Candidates',
        ),
        'cleanup_rules' => t(
            'admin.maintenance_center.fact.cleanup_rules',
            'Cleanup rules',
        ),
        'tables' => t('admin.maintenance_center.fact.tables', 'Tables'),
        'database_size' => t(
            'admin.maintenance_center.fact.database_size',
            'Database size',
        ),
        'data_free' => t(
            'admin.maintenance_center.fact.data_free',
            'Estimated DATA_FREE',
        ),
        'recommended_tables' => t(
            'admin.maintenance_center.fact.recommended_tables',
            'Recommended tables',
        ),
        'eligible_tables' => t(
            'admin.maintenance_center.fact.eligible_tables',
            'Eligible tables',
        ),
        'large_tables_skipped' => t(
            'admin.maintenance_center.fact.large_tables_skipped',
            'Large tables skipped',
        ),
        'pending_migrations' => t(
            'admin.maintenance_center.fact.pending_migrations',
            'Pending migrations',
        ),
        'images_sampled' => t(
            'admin.maintenance_center.fact.images_sampled',
            'Images sampled',
        ),
        'images_with_missing' => t(
            'admin.maintenance_center.fact.images_with_missing',
            'Images with missing variants',
        ),
        'missing_variants' => t(
            'admin.maintenance_center.fact.missing_variants',
            'Missing variants',
        ),
        'units' => t('admin.maintenance_center.fact.units', 'units'),
        'group_system' => t('admin.maintenance_center.group.system', 'System'),
        'group_telemetry' => t(
            'admin.maintenance_center.group.telemetry',
            'Telemetry',
        ),
        'group_logs' => t('admin.maintenance_center.group.logs', 'Admin logs'),
        'group_trash' => t(
            'admin.maintenance_center.group.trash',
            'Gallery Trash',
        ),
        'group_security' => t(
            'admin.maintenance_center.group.security',
            'Security and sessions',
        ),
        'group_downloads' => t(
            'admin.maintenance_center.group.downloads',
            'Downloads and cache',
        ),
        'group_media' => t(
            'admin.maintenance_center.group.media',
            'Media metadata',
        ),
        'group_database' => t(
            'admin.maintenance_center.group.database',
            'Database',
        ),
        'stale_plan' => t(
            'admin.maintenance_center.stale_plan',
            'This plan is stale. Analyze again before executing it.',
        ),
        'cancel_note' => t(
            'admin.maintenance_center.cancel_note',
            'Cancellation takes effect between operations. Completed cleanup is not rolled back.',
        ),
        'other_job_active' => t(
            'admin.maintenance_center.other_job_active',
            'Another administrator owns the active central maintenance job.',
        ),
        'automatic_checks' => t(
            'admin.maintenance_center.automatic_checks',
            'Automatic safety checks',
        ),
        'selection_help' => t(
            'admin.maintenance_center.selection_help',
            'Choose the maintenance work to run. Safety checks and final verification run automatically.',
        ),
        'tab_cleanup' => t(
            'admin.maintenance_center.tab.cleanup',
            'Cleanup',
        ),
        'tab_media' => t('admin.maintenance_center.tab.media', 'Media'),
        'tab_database' => t(
            'admin.maintenance_center.tab.database',
            'Database',
        ),
        'analyze_short' => t(
            'admin.maintenance_center.analyze_short',
            'Analyze',
        ),
        'analyze_again_short' => t(
            'admin.maintenance_center.analyze_again_short',
            'Reanalyze',
        ),
        'run_short' => t('admin.maintenance_center.run_short', 'Run'),
        'resume_short' => t(
            'admin.maintenance_center.resume_short',
            'Resume',
        ),
        'retry_short' => t(
            'admin.maintenance_center.retry_short',
            'Retry',
        ),
        'full_optimize_short' => t(
            'admin.maintenance_center.full_optimize_short',
            'Include other tables',
        ),
        'no_work' => t(
            'admin.maintenance_center.no_work',
            'No work detected',
        ),
        'task_short_labels' => [
            'trash.reconcile' => t(
                'admin.maintenance_center.task.short.trash_reconcile',
                'Reconcile Trash',
            ),
            'telemetry.retention' => t(
                'admin.maintenance_center.task.short.telemetry',
                'Telemetry retention',
            ),
            'logs.retention' => t(
                'admin.maintenance_center.task.short.logs',
                'Admin log cleanup',
            ),
            'trash.retention' => t(
                'admin.maintenance_center.task.short.trash_retention',
                'Trash retention',
            ),
            'security.cleanup' => t(
                'admin.maintenance_center.task.short.security',
                'Session cleanup',
            ),
            'downloads.cache' => t(
                'admin.maintenance_center.task.short.downloads',
                'Cache cleanup',
            ),
            'thumbnail.metadata' => t(
                'admin.maintenance_center.task.short.thumbnail_metadata',
                'Thumbnail metadata',
            ),
            'music.repository' => t(
                'admin.maintenance_center.task.short.music_repository',
                'Move audio files',
            ),
            'media.deep_verify' => t(
                'admin.maintenance_center.task.short.media_deep',
                'Check thumbnails',
            ),
            'database.logical' => t(
                'admin.maintenance_center.task.short.database_logical',
                'Database cleanup',
            ),
            'maintenance.history' => t(
                'admin.maintenance_center.task.short.history',
                'History cleanup',
            ),
            'database.inventory' => t(
                'admin.maintenance_center.task.short.database_inventory',
                'Refresh inventory',
            ),
            'database.analyze' => t(
                'admin.maintenance_center.task.short.database_analyze',
                'Analyze tables',
            ),
            'database.optimize' => t(
                'admin.maintenance_center.task.short.database_optimize',
                'Optimize tables',
            ),
        ],
    ];

    $pageTitle = t('admin.maintenance_center.title', 'Maintenance Center');
    $pageHelp = t(
        'admin.maintenance_center.description',
        'Analyze the installation first, review a concrete plan, then execute safe maintenance in bounded resumable steps.',
    );
    render_header($pageTitle);
    echo
        '<section class="hero admin-maintenance-center-hero"><div><div class="maintenance-center-title-row"><h1>'
            . e($pageTitle)
            . '</h1><details class="admin-inline-help"><summary aria-label="'
            . e($pageTitle)
            . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">'
            . e($pageHelp)
            . '</div></details></div></div>';
    echo
        '<nav class="nav maintenance-center-navigation"><a class="button secondary" title="'
            . e(t('admin.storage.tab_database_maintenance', 'DB maintenance'))
            . '" aria-label="'
            . e(t('admin.storage.tab_database_maintenance', 'DB maintenance'))
            . '" href="'
            . e((string) ($model['storage_maintenance_url'] ?? ''))
            . '">'
            . view_admin_menu_icon('maintenance')
            . '</a><a class="button secondary" title="'
            . e(t('admin.common.dashboard', 'Dashboard'))
            . '" aria-label="'
            . e(t('admin.common.dashboard', 'Dashboard'))
            . '" href="'
            . e((string) ($model['dashboard_url'] ?? ''))
            . '">'
            . view_admin_menu_icon('overview')
            . '</a></nav></section>';

    if (!$available) {
        echo
            '<section class="panel notice warning"><h2>'
                . e(t(
                    'admin.maintenance_center.unavailable_title',
                    'Maintenance Center storage is not ready',
                ))
                . '</h2><p>'
                . e(t(
                    'admin.maintenance_center.unavailable_help',
                    'Apply the pending Maintenance Center database migration before using this workflow. No maintenance action has been attempted.',
                ))
                . '</p></section>';
        render_footer();
        return;
    }

    if ($blockedByOtherJob && is_array($installationBlocker)) {
        echo
            '<section class="panel notice warning"><h2>'
                . e($strings['other_job_active'])
                . '</h2><p>'
                . e(t(
                    'admin.maintenance_center.other_job_active_detail',
                    'Central job #{id} is {status} at {percent}%. This page cannot mutate a job owned by another administrator.',
                    [
                        'id' => (string) (int) (
                            $installationBlocker['job_id'] ?? 0
                        ),
                        'status' => (string) (
                            $installationBlocker['status'] ?? ''
                        ),
                        'percent' => (string) round(
                            (float) (
                                $installationBlocker['progress_percent'] ?? 0
                            ),
                            1,
                        ),
                    ],
                ))
                . '</p></section>';
    }

    $rootAttrs = [
        'data-maintenance-center' => '1',
        'data-csrf-token' => $csrf,
        'data-status-endpoint' => (string) ($endpoints['status'] ?? ''),
        'data-analyze-start-endpoint' => (string) (
            $endpoints['analyze_start'] ?? ''
        ),
        'data-analyze-step-endpoint' => (string) (
            $endpoints['analyze_step'] ?? ''
        ),
        'data-execute-start-endpoint' => (string) (
            $endpoints['execute_start'] ?? ''
        ),
        'data-execute-step-endpoint' => (string) (
            $endpoints['execute_step'] ?? ''
        ),
        'data-pause-endpoint' => (string) ($endpoints['pause'] ?? ''),
        'data-cancel-endpoint' => (string) ($endpoints['cancel'] ?? ''),
        'data-blocked-by-other-job' => $blockedByOtherJob ? '1' : '0',
    ];
    $attrHtml = '';
    foreach ($rootAttrs as $name => $value) {
        $attrHtml .= ' ' . $name . '="' . e($value) . '"';
    }

    echo '<div class="admin-maintenance-center"' . $attrHtml . '>';
    echo
        '<script type="application/json" data-maintenance-center-initial>'
            . view_maintenance_center_json($job)
            . '</script>';
    echo
        '<script type="application/json" data-maintenance-center-i18n>'
            . view_maintenance_center_json($strings)
            . '</script>';

    echo '<section class="panel maintenance-center-status-card">';
    echo
        '<div class="maintenance-center-status-heading"><div><h2 data-maintenance-status-title>'
            . e(
                $job !== null
                    ? (string) ($job['status'] ?? '')
                    : $strings['idle'],
            )
            . '</h2></div><span class="maintenance-center-status-badge" data-maintenance-status-badge>'
            . e($job !== null ? (string) ($job['status'] ?? '') : 'idle')
            . '</span><div class="nav maintenance-center-actions" data-maintenance-actions></div></div>';
    echo
        '<div class="maintenance-center-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-maintenance-progress-shell><span data-maintenance-progress-bar></span></div>';
    echo
        '<div class="maintenance-center-progress-meta"><strong data-maintenance-progress-percent>0%</strong><span data-maintenance-progress-label></span></div>';
    echo
        '<div class="maintenance-center-current"><span>'
            . e(t('admin.maintenance_center.current_phase', 'Current phase'))
            . ': <strong data-maintenance-current-phase>—</strong></span><span>'
            . e(t('admin.maintenance_center.current_task', 'Current task'))
            . ': <strong data-maintenance-current-task>—</strong></span><span data-maintenance-current-subtask-wrap hidden>'
            . e(t('admin.maintenance_center.current_subtask', 'Subtask'))
            . ': <strong data-maintenance-current-subtask></strong></span></div>';
    echo
        '<p class="muted maintenance-center-cancel-note" data-maintenance-cancel-note hidden>'
            . e($strings['cancel_note'])
            . '</p>';
    echo
        '<div class="notice warning" data-maintenance-browser-error hidden></div>';

    echo
        '<section class="panel" data-maintenance-report hidden><div class="maintenance-center-section-heading"><div><h2>'
            . e($strings['result_title'])
            . '</h2></div></div><div class="metric-grid" data-maintenance-report-metrics></div><div data-maintenance-report-details></div></section>';

    echo
        '<details class="maintenance-center-review" data-maintenance-review hidden><summary><strong>'
            . e($strings['review_title'])
            . '</strong><span class="maintenance-center-review-summary" data-maintenance-selection-summary></span></summary><div class="maintenance-center-review-content"><div class="maintenance-center-plan-toolbar"><details class="admin-inline-help"><summary aria-label="'
            . e($strings['selection_help'])
            . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">'
            . e($strings['selection_help'])
            . '<div class="maintenance-center-plan-totals" data-maintenance-plan-totals></div><details class="maintenance-center-automatic" data-maintenance-automatic hidden><summary>'
            . e($strings['automatic_checks'])
            . '</summary><div data-maintenance-automatic-tasks></div></details></div></details></div><div data-maintenance-plan-warnings></div><div class="maintenance-center-task-tabs" data-maintenance-task-tabs role="tablist" aria-label="'
            . e($strings['review_title'])
            . '"></div><div class="maintenance-center-task-panels" data-maintenance-task-panels></div><div class="maintenance-center-full-optimize" data-maintenance-full-optimize-wrap hidden><label><input type="checkbox" role="switch" data-maintenance-full-optimize> <span>'
            . e($strings['full_optimize_short'])
            . '</span></label><details class="admin-inline-help"><summary aria-label="'
            . e($strings['full_optimize'])
            . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content"><p>'
            . e($strings['full_optimize'])
            . '</p><p>'
            . e($strings['large_table_warning'])
            . '</p></div></details></div></div></details>';
    echo '</section>';

    echo '<section class="maintenance-center-lower-grid">';
    echo
        '<details class="panel maintenance-center-activity-panel"><summary><h2>'
            . e($strings['recent_activity'])
            . '</h2></summary><ol class="maintenance-center-activity" data-maintenance-activity><li class="muted">'
            . e(t(
                'admin.maintenance_center.no_activity',
                'No maintenance activity yet.',
            ))
            . '</li></ol></details>';
    echo
        '<article class="panel"><h2>'
            . e(t(
                'admin.maintenance_center.diagnostics',
                'Warnings and errors',
            ))
            . '</h2><div data-maintenance-diagnostics><p class="muted">'
            . e(t(
                'admin.maintenance_center.no_diagnostics',
                'No warnings or errors.',
            ))
            . '</p></div></article>';
    echo '</section>';

    echo '</div>';

    if ((string) ($dashboard['last_completed_at'] ?? '') !== '') {
        echo
            '<p class="muted maintenance-center-history-note">'
                . e(t(
                    'admin.maintenance_center.last_completed',
                    'Last completed central maintenance:',
                ))
                . ' '
                . e((string) $dashboard['last_completed_at'])
                . '</p>';
    }
    render_footer();
}
