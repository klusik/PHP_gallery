<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/updates.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use Throwable;
use const Gallery\Core\CMS_GITHUB_REPOSITORY;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Core\render_admin_tabs;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\application_autoupdate_dry_run;
use function Gallery\Services\application_autoupdate_status;
use function Gallery\Services\application_patch_notes_viewer_data;
use function Gallery\Services\application_update_beta_active;
use function Gallery\Services\application_update_beta_commit;
use function Gallery\Services\application_update_cancel_job;
use function Gallery\Services\application_update_active_job;
use function Gallery\Services\application_update_job_public_state;
use function Gallery\Services\application_update_load_job;
use function Gallery\Services\application_update_last_job;
use function Gallery\Services\application_update_process_job;
use function Gallery\Services\application_update_retry_job;
use function Gallery\Services\application_update_safe_error;
use function Gallery\Services\application_update_start_job;
use function Gallery\Services\application_update_start_rollback_job;
use function Gallery\Services\application_update_cleanup_malformed_root_files;
use function Gallery\Services\application_update_github_api_status;
use function Gallery\Services\application_update_normalize_version;
use function Gallery\Services\application_update_status_for_admin;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\clean_reinstall_current_application_version;
use function Gallery\Services\cms_github_project_url;
use function Gallery\Services\install_application_beta;
use function Gallery\Services\install_application_update;
use function Gallery\Services\restore_application_stable_release;
use function Gallery\Services\set_application_autoupdate_enabled;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;
use function Gallery\Views\view_render_admin_update_page;
use function Gallery\Views\view_render_update_job_card;
use function Gallery\Views\view_render_update_patch_notes_fragment;

/**
 * Admin update controller model.
 *
 * This module owns only the update page request handler. It keeps the original
 * cms_admin_update() function name so the existing route dispatcher can continue
 * calling it after app/controllers.php loads this separated controller file.
 */


/**
 * Build the patch notes viewer model for the updates screen.
 *
 * @param array $status Status value.
 * @param ?string $requestedVersion Requested version value.
 * @param int $ttlSeconds Ttl seconds value.
 * @return array Structured result data for the caller.
 */
function cms_update_patch_notes_model(array $status, ?string $requestedVersion = null, int $ttlSeconds = 3600): array
{
    // $patchNotesData stores parsed release notes fetched from GitHub or the bundled fallback file.
    $patchNotesData = application_patch_notes_viewer_data(!empty($status['branch']) ? (string) $status['branch'] : null, $ttlSeconds);
    // $patchNotesVersions stores the release-note sections available to the admin selector.
    $patchNotesVersions = (array) ($patchNotesData['versions'] ?? []);
    // $selectedPatchVersion stores the version selected by the admin or the installed version by default.
    $selectedPatchVersion = application_update_normalize_version((string) ($requestedVersion ?? cms_current_version())) ?? cms_current_version();
    if (!isset($patchNotesVersions[$selectedPatchVersion]) && $patchNotesVersions !== []) {
        $selectedPatchVersion = array_key_exists(cms_current_version(), $patchNotesVersions) ? cms_current_version() : (string) array_key_first($patchNotesVersions);
    }

    return [
        'data' => $patchNotesData,
        'versions' => $patchNotesVersions,
        'selected_version' => $selectedPatchVersion,
    ];
}

/**
 * Render only the currently selected patch notes section.
 *
 * Compatibility wrapper retained for existing updater call sites and tests.
 *
 * @param array $patchNotesModel Patch notes model value.
 * @return string Text result for the caller.
 */
function cms_render_update_patch_notes_fragment(array $patchNotesModel): string
{
    return view_render_update_patch_notes_fragment($patchNotesModel);
}


/**
 * Return true when the update request expects an in-place JSON response.
 *
 * @return bool True for JavaScript continuation requests.
 */
function cms_update_async_request(): bool
{
    return (string) ($_POST['update_async'] ?? $_GET['update_async'] ?? '') === '1'
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

/**
 * Emit one Admin-safe update job JSON response.
 *
 * @param array $job Safe update job state.
 */
function cms_update_json_job_response(array $job): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['ok' => true, 'job' => $job], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Start the resumable job represented by one Admin update action.
 *
 * @param string $action Admin action identifier.
 * @return array Safe update job state.
 */
function cms_update_start_job_for_action(string $action): array
{
    if ($action === 'beta_install') {
        return application_update_start_job('beta_install', ['commit' => (string) ($_POST['beta_commit'] ?? '')], 'admin');
    }
    if ($action === 'beta_revert') {
        return application_update_start_job('stable_restore', [], 'admin');
    }
    if ($action === 'clean_reinstall') {
        if (strtoupper(trim((string) ($_POST['clean_reinstall_confirm'] ?? ''))) !== 'REINSTALL') {
            throw new RuntimeException(t('admin.updates.confirm_reinstall_error'));
        }
        return application_update_start_job('clean_reinstall', [], 'admin');
    }

    $status = application_update_status_for_admin(false);
    return application_update_start_job('stable_update', [
        'branch' => (string) ($status['branch'] ?? ''),
        'target_version' => (string) ($status['latest_version'] ?? ''),
    ], 'admin');
}

/**
 * Return a concise localized stage label for an update job.
 *
 * @param string $stage Stage identifier.
 * @return string Human-readable label.
 */
function cms_update_stage_label(string $stage): string
{
    $labels = [
        'download' => 'Downloading package',
        'archive_validate' => 'Checking archive',
        'extract' => 'Extracting package',
        'package_validate' => 'Verifying integrity',
        'plan' => 'Preparing activation plan',
        'stage_files' => 'Staging files',
        'backup' => 'Preparing rollback data',
        'ready' => 'Ready to activate',
        'activate' => 'Activating prepared release',
        'migrate' => 'Applying migrations',
        'finalize' => 'Finalizing update',
        'cleanup' => 'Cleaning temporary files',
        'completed' => 'Completed',
    ];
    return $labels[$stage] ?? ucfirst(str_replace('_', ' ', $stage));
}

/**
 * Build the presentation-only durable update job model.
 *
 * @param array|null $job Safe job state or null when no job is active.
 * @return array|null Controller-prepared update job view model.
 */
function cms_update_job_view_model(?array $job): ?array
{
    if ($job === null) {
        return null;
    }

    $job['stage_label'] = cms_update_stage_label((string) ($job['stage'] ?? ''));
    return $job;
}

/**
 * Render the durable update-job card used by JavaScript and non-JavaScript flows.
 *
 * Compatibility wrapper retained for existing updater call sites and tests.
 *
 * @param array|null $job Safe job state or null when no job is active.
 */
function cms_render_update_job_card(?array $job, bool $installerEnabled = true): void
{
    view_render_update_job_card(cms_update_job_view_model($job), $installerEnabled, csrf_field());
}

/**
 * Check GitHub for newer application versions and install them on request.
 */
function cms_admin_update(): void
{
    require_admin();
    // $error stores an intermediate value used by the surrounding gallery workflow.
    $error = null;

    if (isset($_GET['update_job_status'])) {
        $requestedJobId = trim((string) ($_GET['job_id'] ?? ''));
        $job = $requestedJobId !== ''
            ? application_update_job_public_state(application_update_load_job($requestedJobId))
            : (($active = application_update_active_job()) !== null ? application_update_job_public_state($active) : []);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(['ok' => true, 'job' => $job], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    if (request_method() === 'POST') {
        verify_csrf();
        try {
            $action = (string) ($_POST['update_action'] ?? 'stable_update');
            $installerMutationActions = ['stable_update', 'beta_install', 'beta_revert', 'clean_reinstall', 'job_continue', 'job_retry', 'job_rollback'];
            if (in_array($action, $installerMutationActions, true) && !feature_capability_effective_enabled('built_in_update_installer')) {
                throw new RuntimeException(t('admin.features.built_in_update_installer.disabled_action', 'The built-in update installer is disabled in Admin > Features. Read-only update checks remain available.'));
            }
            if ($action === 'autoupdate_settings') {
                set_application_autoupdate_enabled(!empty($_POST['application_autoupdate_enabled']));
                $_SESSION['admin_update_notice'] = t('admin.updates.autoupdate_settings_saved', 'Automatic update settings were saved.');
            } elseif ($action === 'autoupdate_dry_run') {
                $dryRunStatus = application_autoupdate_dry_run(true);
                $_SESSION['admin_update_notice'] = t('admin.updates.autoupdate_dry_run_completed', 'Automatic update dry run completed. Last result: {result}', ['result' => (string) ($dryRunStatus['last_result'] ?? '')]);
            } elseif ($action === 'force_check') {
                $forcedStatus = application_update_status_for_admin(true);
                if (empty($forcedStatus['error'])) {
                    $_SESSION['admin_update_notice'] = t('admin.updates.force_check_completed', 'Forced GitHub update check completed.');
                } else {
                    $safe = application_update_safe_error((string) $forcedStatus['error']);
                    $_SESSION['admin_update_notice'] = 'Forced GitHub update check completed with a warning. Reference: ' . $safe['reference'];
                }
            } elseif ($action === 'cleanup_malformed_root_files') {
                $result = application_update_cleanup_malformed_root_files();
                admin_log_event('info', 'update.malformed_root_files_cleaned', t('admin.updates.log_malformed_root_files_cleaned'), $result, ['category' => 'update', 'severity' => 'notice']);
                $_SESSION['admin_update_notice'] = t('admin.updates.notice_malformed_root_files_cleaned', 'Malformed root-file cleanup finished. Removed {removed} file(s). Backup: {backup}', ['removed' => (string) (int) $result['removed_count'], 'backup' => (string) $result['backup']]);
            } elseif ($action === 'job_cancel') {
                $jobId = trim((string) ($_POST['job_id'] ?? ''));
                $job = application_update_cancel_job($jobId);
                admin_log_event('info', 'update.job_cancelled', 'Prepared application update job cancelled before activation.', [
                    'job_id' => (string) $job['id'],
                    'operation' => (string) $job['operation'],
                    'stage' => (string) $job['stage'],
                ], ['category' => 'update', 'severity' => 'notice']);
                if (cms_update_async_request()) {
                    cms_update_json_job_response($job);
                    return;
                }
                $_SESSION['admin_update_notice'] = 'Prepared update cancelled before activation. No application files were changed.';
            } elseif ($action === 'job_rollback') {
                $jobId = trim((string) ($_POST['job_id'] ?? ''));
                $job = application_update_start_rollback_job($jobId, 'admin');
                $job = application_update_process_job((string) $job['id'], 7.0);
                admin_log_event('warning', 'update.rollback_started', 'Application rollback job started from a durable update snapshot.', [
                    'job_id' => (string) $job['id'],
                    'source_job_id' => $jobId,
                    'stage' => (string) $job['stage'],
                ], ['category' => 'update', 'severity' => 'warning']);
                if (cms_update_async_request()) {
                    cms_update_json_job_response($job);
                    return;
                }
                $_SESSION['admin_update_notice'] = 'Rollback job started from the saved pre-update snapshot.';
            } elseif ($action === 'job_continue' || $action === 'job_retry') {
                $jobId = trim((string) ($_POST['job_id'] ?? ''));
                $job = $action === 'job_retry' ? application_update_retry_job($jobId) : application_update_job_public_state(application_update_load_job($jobId));
                if ((string) ($job['status'] ?? '') === 'running') {
                    $job = application_update_process_job((string) $job['id'], 7.0);
                }
                if ((string) ($job['status'] ?? '') === 'completed') {
                    admin_log_event('info', 'update.job_completed', 'Application update job completed.', [
                        'job_id' => (string) $job['id'],
                        'operation' => (string) $job['operation'],
                        'result' => (array) ($job['result'] ?? []),
                    ], ['category' => 'update', 'severity' => 'notice']);
                    $_SESSION['admin_update_notice'] = 'Update job completed successfully.';
                } elseif ((string) ($job['status'] ?? '') === 'failed') {
                    admin_log_event('warning', 'update.job_failed', 'Application update job stopped at a safe recovery checkpoint.', [
                        'job_id' => (string) $job['id'],
                        'operation' => (string) $job['operation'],
                        'stage' => (string) $job['stage'],
                        'error_reference' => (string) ($job['error']['reference'] ?? ''),
                    ], ['category' => 'update', 'severity' => 'error']);
                }
                if (cms_update_async_request()) {
                    cms_update_json_job_response($job);
                    return;
                }
                $_SESSION['admin_update_notice'] = $_SESSION['admin_update_notice'] ?? ('Update job checkpoint saved at stage: ' . cms_update_stage_label((string) ($job['stage'] ?? '')) . '.');
            } elseif (in_array($action, ['stable_update', 'beta_install', 'beta_revert', 'clean_reinstall'], true)) {
                $job = cms_update_start_job_for_action($action);
                $job = application_update_process_job((string) $job['id'], 7.0);
                if ((string) ($job['status'] ?? '') === 'failed') {
                    admin_log_event('warning', 'update.job_failed', 'Application update job stopped at a safe recovery checkpoint.', [
                        'job_id' => (string) $job['id'],
                        'operation' => (string) $job['operation'],
                        'stage' => (string) $job['stage'],
                        'error_reference' => (string) ($job['error']['reference'] ?? ''),
                    ], ['category' => 'update', 'severity' => 'error']);
                } else {
                    admin_log_event('info', 'update.job_started', 'Application update job started.', [
                        'job_id' => (string) $job['id'],
                        'operation' => (string) $job['operation'],
                        'stage' => (string) $job['stage'],
                    ], ['category' => 'update', 'severity' => 'notice']);
                }
                if (cms_update_async_request()) {
                    cms_update_json_job_response($job);
                    return;
                }
                $_SESSION['admin_update_notice'] = 'Update job started. Continue from the saved checkpoint if the host stops this request.';
            } else {
                throw new RuntimeException('Unsupported update action.');
            }
            redirect_to(url_for('admin_update'));
        } catch (Throwable $exception) {
            $safe = application_update_safe_error($exception);
            admin_log_event('warning', 'update.failed', t('admin.updates.log_failed'), [
                'action' => (string) ($_POST['update_action'] ?? 'stable_update'),
                'error_reference' => $safe['reference'],
                'current_version' => cms_current_version(),
                'beta_active' => application_update_beta_active(),
                'php_version' => PHP_VERSION,
            ], ['category' => 'update', 'severity' => 'error']);
            if (cms_update_async_request()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, private');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $safe], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            }
            $error = $safe['message'] . ' Reference: ' . $safe['reference'];
        }
    }

    // $notice stores an intermediate value used by the surrounding gallery workflow.
    $notice = (string) ($_SESSION['admin_update_notice'] ?? '');
    unset($_SESSION['admin_update_notice']);
    // $status stores the passive cached update state used by this page.
    // Normal page rendering must not contact GitHub because even a conditional 304
    // response can still reduce the visible GitHub rate-limit counters.
    $status = application_update_status_for_admin(false);
    // $betaActive stores an intermediate value used by the surrounding gallery workflow.
    $betaActive = application_update_beta_active();
    // $installerEnabled stores the effective Built-in Update Installer master state.
    $installerEnabled = feature_capability_effective_enabled('built_in_update_installer');
    // $autoupdateStatus stores the persisted automatic update setting and runtime state.
    $autoupdateStatus = application_autoupdate_status();
    // $githubApiStatus stores the latest GitHub API headers and policy backoff diagnostics.
    $githubApiStatus = application_update_github_api_status();
    // $activeUpdateJob stores the durable update state rendered for in-place continuation.
    $activeUpdateJobPrivate = application_update_active_job();
    if ($activeUpdateJobPrivate !== null) {
        $activeUpdateJob = application_update_job_public_state($activeUpdateJobPrivate);
    } else {
        $lastUpdateJobPrivate = application_update_last_job();
        $lastUpdateJob = $lastUpdateJobPrivate !== null ? application_update_job_public_state($lastUpdateJobPrivate) : null;
        $activeUpdateJob = $lastUpdateJob !== null && !empty($lastUpdateJob['can_rollback']) ? $lastUpdateJob : null;
    }
    // $patchNotesModel stores the selectable release-note data for full-page and AJAX rendering.
    $patchNotesModel = cms_update_patch_notes_model($status, (string) ($_GET['patch_version'] ?? cms_current_version()));
    // $patchNotesVersions stores the release-note sections available to the admin selector.
    $patchNotesVersions = (array) ($patchNotesModel['versions'] ?? []);
    // $selectedPatchVersion stores the version selected by the admin or the installed version by default.
    $selectedPatchVersion = (string) ($patchNotesModel['selected_version'] ?? cms_current_version());
    if (isset($_GET['patch_notes_fragment'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'version' => $selectedPatchVersion,
            'html' => cms_render_update_patch_notes_fragment($patchNotesModel),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    // Prepare service-derived presentation values before crossing into the view layer.
    $installedVersion = cms_current_version();
    $betaCommit = application_update_beta_commit();
    $statusSafeError = !empty($status['error']) ? application_update_safe_error((string) $status['error']) : null;
    $installedPatchUrl = isset($patchNotesVersions[$installedVersion])
        ? url_for('admin_update', ['patch_version' => $installedVersion])
        : '';
    $pendingPatchUrl = empty($status['error'])
        && !empty($status['update_available'])
        && !empty($status['latest_version'])
        && isset($patchNotesVersions[(string) $status['latest_version']])
        ? url_for('admin_update', ['patch_version' => (string) $status['latest_version']])
        : '';

    render_header(t('admin.updates.title'));
    view_render_admin_update_page([
        'notice' => $notice,
        'error' => $error,
        'status' => $status,
        'beta_active' => $betaActive,
        'installer_enabled' => $installerEnabled,
        'autoupdate_status' => $autoupdateStatus,
        'github_api_status' => $githubApiStatus,
        'active_update_job' => cms_update_job_view_model($activeUpdateJob),
        'patch_notes_model' => $patchNotesModel,
        'installed_version' => $installedVersion,
        'beta_commit' => $betaCommit,
        'repository' => CMS_GITHUB_REPOSITORY,
        'github_project_url' => cms_github_project_url(),
        'csrf_html' => csrf_field(),
        'status_error_reference' => is_array($statusSafeError) ? (string) ($statusSafeError['reference'] ?? '') : '',
        'installed_patch_url' => $installedPatchUrl,
        'pending_patch_url' => $pendingPatchUrl,
        'urls' => [
            'admin' => url_for('admin'),
            'update' => url_for('admin_update'),
            'patch_notes_fragment' => url_for('admin_update', ['patch_notes_fragment' => '1']),
            'features' => url_for('admin_features'),
            'diagnostics' => url_for('admin_diagnostics'),
        ],
    ]);
    render_footer();
}
