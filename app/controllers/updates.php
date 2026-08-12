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
use function Gallery\Services\application_update_github_api_status;
use function Gallery\Services\application_update_normalize_version;
use function Gallery\Services\application_update_status_for_admin;
use function Gallery\Services\clean_reinstall_current_application_version;
use function Gallery\Services\cms_github_project_url;
use function Gallery\Services\install_application_beta;
use function Gallery\Services\install_application_update;
use function Gallery\Services\restore_application_stable_release;
use function Gallery\Services\set_application_autoupdate_enabled;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;

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
function cms_update_patch_notes_model(array $status, ?string $requestedVersion = null, int $ttlSeconds = 18000): array
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
 * @param array $patchNotesModel Patch notes model value.
 * @return string Text result for the caller.
 */
function cms_render_update_patch_notes_fragment(array $patchNotesModel): string
{
    // $patchNotesData stores source diagnostics displayed above the rendered notes.
    $patchNotesData = (array) ($patchNotesModel['data'] ?? []);
    // $patchNotesVersions stores parsed release-note entries keyed by version.
    $patchNotesVersions = (array) ($patchNotesModel['versions'] ?? []);
    // $selectedPatchVersion stores the selected release-note key.
    $selectedPatchVersion = (string) ($patchNotesModel['selected_version'] ?? cms_current_version());

    ob_start();
    echo '<div class="patch-notes-fragment-inner">';
    if (!empty($patchNotesData['error'])) {
        echo '<p class="muted patch-notes-source-note">' . e(t('admin.updates.patch_notes_remote_failed', 'GitHub patch notes could not be loaded, showing bundled notes if available. Error: {error}', ['error' => (string) $patchNotesData['error']])) . '</p>';
    } else {
        echo '<p class="muted patch-notes-source-note">' . e(t('admin.updates.patch_notes_source_value', 'Source: {source}, branch: {branch}', ['source' => (string) ($patchNotesData['source'] ?? 'github'), 'branch' => (string) ($patchNotesData['branch'] ?? '')])) . '</p>';
    }
    if (isset($patchNotesVersions[$selectedPatchVersion])) {
        // $selectedEntry stores the parsed release notes for the currently displayed version.
        $selectedEntry = (array) $patchNotesVersions[$selectedPatchVersion];
        echo '<article class="patch-notes-content">';
        echo '<h3>' . e((string) ($selectedEntry['title'] ?? ('Version ' . $selectedPatchVersion))) . '</h3>';
        if (!empty($selectedEntry['released_label'])) {
            echo '<p class="muted patch-notes-release-label">' . e(t('admin.updates.patch_notes_released', 'Released: {date}', ['date' => (string) $selectedEntry['released_label']])) . '</p>';
        }
        echo (string) ($selectedEntry['html'] ?? '');
        echo '</article>';
    } else {
        echo '<p class="muted patch-notes-source-note">' . e(t('admin.updates.patch_notes_unavailable', 'No patch notes are available yet.')) . '</p>';
    }
    echo '</div>';
    return (string) ob_get_clean();
}

/**
 * Check GitHub for newer application versions and install them on request.
 */
function cms_admin_update(): void
{
    require_admin();
    // $error stores an intermediate value used by the surrounding gallery workflow.
    $error = null;

    if (request_method() === 'POST') {
        verify_csrf();
        try {
            // $action stores an intermediate value used by the surrounding gallery workflow.
            $action = (string) ($_POST['update_action'] ?? 'stable_update');
            if ($action === 'autoupdate_settings') {
                set_application_autoupdate_enabled(!empty($_POST['application_autoupdate_enabled']));
                $_SESSION['admin_update_notice'] = t('admin.updates.autoupdate_settings_saved', 'Automatic update settings were saved.');
            } elseif ($action === 'autoupdate_dry_run') {
                // $dryRunStatus stores the refreshed automatic update diagnostics after a safe metadata-only check.
                $dryRunStatus = application_autoupdate_dry_run(true);
                $_SESSION['admin_update_notice'] = t('admin.updates.autoupdate_dry_run_completed', 'Automatic update dry run completed. Last result: {result}', ['result' => (string) ($dryRunStatus['last_result'] ?? '')]);
            } elseif ($action === 'force_check') {
                // $forcedStatus stores a manual administrator check that bypasses the local five-hour cache but still records GitHub headers.
                $forcedStatus = application_update_status_for_admin(true);
                $_SESSION['admin_update_notice'] = empty($forcedStatus['error'])
                    ? t('admin.updates.force_check_completed', 'Forced GitHub update check completed.')
                    : t('admin.updates.force_check_completed_with_error', 'Forced GitHub update check completed with a warning: {error}', ['error' => (string) $forcedStatus['error']]);
            } elseif ($action === 'beta_install') {
                // $result stores an intermediate value used by the surrounding gallery workflow.
                $result = install_application_beta((string) ($_POST['beta_commit'] ?? ''));
                admin_log_event('info', 'update.beta_installed', t('admin.updates.log_beta_installed'), $result, ['category' => 'update', 'severity' => 'notice']);
                $_SESSION['admin_update_notice'] = t('admin.updates.notice_beta_installed', 'Installed beta code {version}. Copied {files} files, removed {removed} obsolete path(s), and applied {migrations} migrations.', ['version' => (string) $result['version'], 'files' => (string) (int) $result['files_copied'], 'removed' => (string) (int) ($result['removed_count'] ?? 0), 'migrations' => (string) count((array) $result['migrations'])]);
            } elseif ($action === 'beta_revert') {
                // $result stores an intermediate value used by the surrounding gallery workflow.
                $result = restore_application_stable_release();
                admin_log_event('info', 'update.beta_reverted', t('admin.updates.log_beta_reverted'), $result, ['category' => 'update', 'severity' => 'notice']);
                $_SESSION['admin_update_notice'] = t('admin.updates.notice_beta_reverted', 'Restored the stable release from the GitHub branch head. Copied {files} files and removed {removed} obsolete path(s).', ['files' => (string) (int) $result['files_copied'], 'removed' => (string) (int) ($result['removed_count'] ?? 0)]);
            } elseif ($action === 'clean_reinstall') {
                if (strtoupper(trim((string) ($_POST['clean_reinstall_confirm'] ?? ''))) !== 'REINSTALL') {
                    throw new RuntimeException(t('admin.updates.confirm_reinstall_error'));
                }
                // $result stores clean reinstall diagnostics for the admin log and user-facing notice.
                $result = clean_reinstall_current_application_version();
                admin_log_event('info', 'update.clean_reinstalled', t('admin.updates.log_clean_reinstalled'), $result, ['category' => 'update', 'severity' => 'warning']);
                $_SESSION['admin_update_notice'] = t('admin.updates.notice_clean_reinstalled', 'Clean reinstall finished. Copied {files} files, removed {removed} unexpected path(s), removed {zips} cached ZIP file(s), and applied {migrations} migrations.', ['files' => (string) (int) $result['files_copied'], 'removed' => (string) (int) ($result['removed_count'] ?? 0), 'zips' => (string) (int) ($result['cache_cleanup']['zip_files_removed'] ?? 0), 'migrations' => (string) count((array) $result['migrations'])]);
            } else {
                // $result stores an intermediate value used by the surrounding gallery workflow.
                $result = install_application_update();
                admin_log_event('info', 'update.installed', t('admin.updates.log_installed'), $result, ['category' => 'update', 'severity' => 'notice']);
                $_SESSION['admin_update_notice'] = t('admin.updates.notice_updated', 'Updated to version {version}. Copied {files} files, removed {removed} obsolete path(s), and applied {migrations} migrations.', ['version' => (string) $result['version'], 'files' => (string) (int) $result['files_copied'], 'removed' => (string) (int) ($result['removed_count'] ?? 0), 'migrations' => (string) count((array) $result['migrations'])]);
            }
            redirect_to(url_for('admin_update'));
        } catch (Throwable $exception) {
            admin_log_event('warning', 'update.failed', t('admin.updates.log_failed'), [
                'action' => (string) ($_POST['update_action'] ?? 'stable_update'),
                'error' => $exception->getMessage(),
                'current_version' => cms_current_version(),
                'beta_active' => application_update_beta_active(),
                'php_version' => PHP_VERSION,
            ], ['category' => 'update', 'severity' => 'error']);
            // $error stores an intermediate value used by the surrounding gallery workflow.
            $error = $exception->getMessage();
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
    // $autoupdateStatus stores the persisted automatic update setting and runtime state.
    $autoupdateStatus = application_autoupdate_status();
    // $githubApiStatus stores the latest GitHub API headers and policy backoff diagnostics.
    $githubApiStatus = application_update_github_api_status();
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
    render_header(t('admin.updates.title'));
    // $latestVersion stores the readable latest release value for the status summary.
    $latestVersion = !empty($status['latest_version']) ? (string) $status['latest_version'] : t('admin.common.unknown', 'Unknown');
    // $channelLabel stores the readable channel currently installed on this instance.
    $channelLabel = $betaActive ? t('admin.updates.channel_beta') : t('admin.updates.channel_stable');
    // $updateStateLabel stores the high-level update state displayed in the summary cards.
    $updateStateLabel = !empty($status['error']) ? t('admin.updates.check_failed', 'Check failed') : (!empty($status['update_available']) ? t('admin.updates.update_available', 'Update available') : t('admin.updates.current'));
    // $updateStateClass stores a neutral class name for update state styling.
    $updateStateClass = !empty($status['error']) ? 'is-warning' : (!empty($status['update_available']) ? 'is-attention' : 'is-ok');

    echo '<section class="hero admin-update-hero"><div><p class="admin-kicker">' . e(t('admin.updates.kicker', 'Application maintenance')) . '</p><h1>' . e(t('admin.updates.title')) . '</h1><p class="muted">' . e(t('admin.updates.page_hint', 'Check releases, review patch notes, install updates, and use advanced recovery tools from one place.')) . '</p></div><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.common.back_to_dashboard')) . '</a>';
    echo '<a class="button secondary" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">' . e(t('admin.updates.open_github')) . '</a>';
    echo '<form method="post" class="inline-action-form">' . csrf_field() . '<input type="hidden" name="update_action" value="force_check"><button type="submit" class="button secondary">' . e(t('admin.updates.force_check_button', 'Force check')) . '</button></form>';
    echo '</nav></section>';

    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="notice">' . e(t('admin.updates.failed_value', ['error' => $error])) . '</div>';
    }

    render_admin_tabs([
        ['id' => 'admin-update-tab-status', 'label' => t('admin.updates.status', 'Status'), 'active' => true],
        ['id' => 'admin-update-tab-notes', 'label' => t('admin.updates.patch_notes_title', 'Patch notes'), 'badge' => count($patchNotesVersions)],
        ['id' => 'admin-update-tab-advanced', 'label' => t('admin.updates.advanced_tools', 'Advanced tools')],
    ], 'admin-update-tab-status');

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.updates.status_kicker', 'Release status')) . '</p><h2>' . e(t('admin.updates.status')) . '</h2></div><p class="muted">' . e(t('admin.updates.status_hint', 'The updater checks GitHub metadata through the service layer and keeps the install action on the existing ZIP based workflow.')) . '</p></div>';
    echo '<div class="admin-metric-grid admin-update-metric-grid">';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.updates.installed_version')) . '</span><strong>' . e(cms_current_version()) . '</strong><small>' . e(t('admin.updates.installed_version_hint', 'Version currently running on this installation.')) . '</small></article>';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.updates.latest_version')) . '</span><strong>' . e($latestVersion) . '</strong><small>' . e(empty($status['branch']) ? t('admin.updates.branch_unknown', 'Branch not available') : t('admin.updates.checked_branch_value', ['branch' => (string) $status['branch']])) . '</small></article>';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.updates.active_channel')) . '</span><strong>' . e($channelLabel) . '</strong><small>' . ($betaActive ? e(t('admin.updates.installed_beta_code')) . ': <code>' . e(application_update_beta_commit()) . '</code>' : e(t('admin.updates.stable_channel_hint', 'Stable release channel is active.'))) . '</small></article>';
    echo '<article class="admin-metric-card admin-update-state-card ' . e($updateStateClass) . '"><span>' . e(t('admin.updates.update_state', 'Update state')) . '</span><strong>' . e($updateStateLabel) . '</strong><small>' . e(!empty($status['version_source']) ? t('admin.updates.version_source_value', ['source' => (string) $status['version_source']]) : t('admin.updates.version_source_unknown', 'Version source not reported.')) . '</small></article>';
    echo '</div>';

    echo '<div class="admin-update-status-layout">';
    echo '<article class="admin-update-card">';
    echo '<div><p class="admin-kicker">' . e(t('admin.updates.repository')) . '</p><h3>' . e(CMS_GITHUB_REPOSITORY) . '</h3></div>';
    echo '<p class="muted">' . e(t('admin.updates.repository_hint', 'The updater uses this repository for release metadata and ZIP downloads.')) . '</p>';
    echo '<a class="button secondary" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">' . e(t('admin.updates.open_github')) . '</a>';
    echo '</article>';
    echo '<article class="admin-update-card">';
    echo '<div><p class="admin-kicker">' . e(t('admin.updates.github_api_kicker', 'GitHub API policy')) . '</p><h3>' . e(t('admin.updates.github_api_title', 'Rate-limit status')) . '</h3></div>';
    echo '<p class="muted">' . e(t('admin.updates.github_api_hint', 'The updater uses response headers from normal GitHub API calls. It does not call /rate_limit just to inspect limits.')) . '</p>';
    echo '<p class="muted"><strong>' . e(t('admin.updates.github_api_last_checked', 'Last GitHub API response')) . ':</strong> ' . e((string) ($githubApiStatus['last_checked_label'] ?? '')) . '</p>';
    echo '<p class="muted"><strong>' . e(t('admin.updates.github_api_remaining', 'Remaining quota')) . ':</strong> ' . e((string) ($githubApiStatus['remaining'] ?? '')) . ' / ' . e((string) ($githubApiStatus['limit'] ?? '')) . '</p>';
    echo '<p class="muted"><strong>' . e(t('admin.updates.github_api_used', 'Used quota')) . ':</strong> ' . e((string) ($githubApiStatus['used'] ?? '')) . '</p>';
    echo '<p class="muted"><strong>' . e(t('admin.updates.github_api_resource', 'Resource')) . ':</strong> ' . e((string) ($githubApiStatus['resource'] ?? '')) . '</p>';
    echo '<p class="muted"><strong>' . e(t('admin.updates.github_api_status_code', 'Last HTTP status')) . ':</strong> ' . e((string) ($githubApiStatus['last_status'] ?? '')) . (!empty($githubApiStatus['last_from_cache']) ? ' <span class="tag">' . e(t('admin.updates.github_api_cache_hit', 'served from local ETag cache')) . '</span>' : '') . '</p>';
    echo '<p class="muted"><strong>' . e(t('admin.updates.github_api_etag', 'ETag')) . ':</strong> ' . e((string) ($githubApiStatus['etag'] ?? '')) . '</p>';
    echo '<p class="muted"><strong>' . e(t('admin.updates.github_api_reset', 'Primary reset')) . ':</strong> ' . e((string) ($githubApiStatus['reset_label'] ?? '')) . '</p>';
    if (!empty($githubApiStatus['wait']['active'])) {
        echo '<p class="notice"><strong>' . e(t('admin.updates.github_api_waiting', 'Waiting')) . ':</strong> ' . e(t('admin.updates.github_api_next_allowed', 'Next allowed check: {time}', ['time' => (string) ($githubApiStatus['wait']['next_allowed_label'] ?? '')])) . '</p>';
    }
    echo '<form method="post" class="form-grid admin-update-action-form">' . csrf_field();
    echo '<input type="hidden" name="update_action" value="force_check">';
    echo '<p class="muted">' . e(t('admin.updates.force_check_hint', 'Bypass the local five-hour cache and ask GitHub now. GitHub rate-limit headers are still recorded and respected after the response.')) . '</p>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.updates.force_check_button', 'Force check')) . '</button>';
    echo '</form>';
    echo '</article>';
    echo '<article class="admin-update-card">';
    echo '<div><p class="admin-kicker">' . e(t('admin.updates.autoupdate_kicker', 'Automatic updates')) . '</p><h3>' . e(!empty($autoupdateStatus['enabled']) ? t('admin.common.enabled', 'Enabled') : t('admin.common.disabled', 'Disabled')) . '</h3></div>';
    if (!empty($autoupdateStatus['beta_active'])) {
        echo '<p class="muted">' . e(t('admin.updates.autoupdate_beta_disabled_hint', 'Automatic updates are checked in settings, but ignored while beta code is installed. The setting is not changed.')) . '</p>';
    } else {
        echo '<p class="muted">' . e(t('admin.updates.autoupdate_hint', 'When enabled, normal page requests check for a stable update at most once every five hours and install it automatically when available. The dry check button forces a fresh metadata-only check immediately.')) . '</p>';
    }
    // $autoupdateLastCheckedLabel stores either a formatted timestamp or a localized never-checked fallback.
    $autoupdateLastCheckedLabel = (string) ($autoupdateStatus['last_checked_label'] ?? t('admin.updates.autoupdate_last_check_never', 'never'));
    // $autoupdateLastCheckedRelative stores a freshness label when a previous check exists.
    $autoupdateLastCheckedRelative = (string) ($autoupdateStatus['last_checked_relative'] ?? '');
    if ($autoupdateLastCheckedRelative !== '') {
        echo '<p class="muted"><strong>' . e(t('admin.updates.autoupdate_last_check_label', 'Last automatic check')) . ':</strong> ' . e(t('admin.updates.autoupdate_last_check_with_relative', '{time} ({relative})', ['time' => $autoupdateLastCheckedLabel, 'relative' => $autoupdateLastCheckedRelative])) . '</p>';
    } else {
        echo '<p class="muted"><strong>' . e(t('admin.updates.autoupdate_last_check_label', 'Last automatic check')) . ':</strong> ' . e($autoupdateLastCheckedLabel) . '</p>';
    }
    // $autoupdateLastResult stores the last persisted automatic updater result, if any.
    $autoupdateLastResult = (string) ($autoupdateStatus['last_result'] ?? '');
    echo '<p class="muted"><strong>' . e(t('admin.updates.autoupdate_last_result_label', 'Last result')) . ':</strong> ' . e($autoupdateLastResult !== '' ? $autoupdateLastResult : t('admin.updates.autoupdate_last_result_none', 'not recorded yet')) . '</p>';
    echo '<form method="post" class="form-grid admin-update-action-form">' . csrf_field();
    echo '<input type="hidden" name="update_action" value="autoupdate_settings">';
    echo '<label class="checkbox-row"><input type="checkbox" name="application_autoupdate_enabled" value="1"' . (!empty($autoupdateStatus['enabled']) ? ' checked' : '') . '> <span>' . e(t('admin.updates.autoupdate_enable_label', 'Enable automatic stable updates')) . '</span></label>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.common.save', 'Save')) . '</button>';
    echo '</form>';
    echo '<form method="post" class="form-grid admin-update-action-form">' . csrf_field();
    echo '<input type="hidden" name="update_action" value="autoupdate_dry_run">';
    echo '<p class="muted">' . e(t('admin.updates.autoupdate_dry_run_hint', 'Run a metadata-only check now. This updates the last check diagnostics but never installs files.')) . '</p>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.updates.autoupdate_dry_run_button', 'Run dry check now')) . '</button>';
    echo '</form></article>';
    echo '<article class="admin-update-card ' . (!empty($status['update_available']) ? 'is-attention' : '') . '">';
    echo '<div><p class="admin-kicker">' . e(t('admin.updates.primary_action', 'Primary action')) . '</p><h3>' . e($updateStateLabel) . '</h3></div>';
    if (!empty($status['error'])) {
        echo '<p class="muted">' . e(t('admin.updates.check_failed_value', ['error' => (string) $status['error']])) . '</p>';
    } elseif (!empty($status['update_available'])) {
        echo '<form method="post" class="form-grid admin-update-action-form">' . csrf_field();
        echo '<input type="hidden" name="update_action" value="stable_update">';
        echo '<p>' . t('admin.updates.newer_available_description') . '</p>';
        echo '<button type="submit" class="is-update-pending">' . e(t('admin.updates.update_button')) . '</button></form>';
    } else {
        echo '<p class="muted">' . e(t('admin.updates.current')) . '</p>';
        echo '<a class="button secondary" href="#admin-update-tab-notes">' . e(t('admin.updates.patch_notes_title', 'Patch notes')) . '</a>';
    }
    echo '</article>';
    echo '</div>';
    $statusHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-update-tab-status', $statusHtml, true);

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.updates.patch_notes_kicker', 'Release notes')) . '</p><h2>' . e(t('admin.updates.patch_notes_title', 'Patch notes')) . '</h2></div><p class="muted">' . e(t('admin.updates.patch_notes_page_hint', 'Select an installed, latest, or older version without reloading the full admin page.')) . '</p></div>';
    echo '<details class="patch-notes-viewer" data-patch-notes-viewer data-fragment-url="' . e(url_for('admin_update', ['patch_notes_fragment' => '1'])) . '" open>';
    echo '<summary><span>' . e(t('admin.updates.patch_notes_title', 'Patch notes')) . '</span><small>' . e(t('admin.updates.patch_notes_summary', 'Show release notes from GitHub')) . '</small></summary>';
    // $patchVersionGroups stores release-note versions grouped by the main minor stream.
    $patchVersionGroups = [];
    foreach ($patchNotesVersions as $version => $entry) {
        $versionString = (string) $version;
        $groupKey = $versionString;
        if (preg_match('/^(\d+\.\d+)(?:\.\d+)?(?:[-+].*)?$/', $versionString, $match)) {
            $groupKey = (string) $match[1];
        }
        if (!isset($patchVersionGroups[$groupKey])) {
            $patchVersionGroups[$groupKey] = [];
        }
        $patchVersionGroups[$groupKey][$versionString] = (array) $entry;
    }
    $selectedPatchEntry = (array) ($patchNotesVersions[$selectedPatchVersion] ?? []);
    $selectedPatchLabel = (string) ($selectedPatchEntry['title'] ?? ('Version ' . $selectedPatchVersion));
    if (!empty($selectedPatchEntry['released_label'])) {
        $selectedPatchLabel .= ' · ' . (string) $selectedPatchEntry['released_label'];
    }
    echo '<div class="patch-notes-toolbar">';
    echo '<form method="get" class="patch-notes-select-form" data-patch-notes-form>';
    echo '<input type="hidden" name="page" value="admin_update">';
    echo '<input type="hidden" name="patch_version" value="' . e($selectedPatchVersion) . '" data-patch-notes-input>';
    echo '<label class="patch-notes-picker-label" for="patch-notes-picker-button">' . e(t('admin.updates.patch_notes_version_label', 'Displayed version')) . '</label>';
    echo '<div class="patch-notes-picker" data-patch-notes-picker>';
    echo '<button type="button" id="patch-notes-picker-button" class="patch-notes-picker-button" data-patch-notes-picker-button aria-haspopup="listbox" aria-expanded="false">';
    echo '<span data-patch-notes-picker-text>' . e($selectedPatchLabel) . '</span>';
    echo '<span class="patch-notes-picker-chevron" aria-hidden="true">&#9662;</span>';
    echo '</button>';
    echo '<div class="patch-notes-picker-menu" data-patch-notes-picker-menu role="listbox" aria-label="' . e(t('admin.updates.patch_notes_version_label', 'Displayed version')) . '">';
    foreach ($patchVersionGroups as $groupVersion => $groupEntries) {
        $groupCount = count($groupEntries);
        echo '<div class="patch-notes-version-group">';
        echo '<div class="patch-notes-version-heading">';
        echo '<span>' . e(t('admin.updates.version_label', 'Version {version}', ['version' => (string) $groupVersion])) . '</span>';
        echo '<small>' . e(t($groupCount === 1 ? 'admin.updates.patch_notes_one_release' : 'admin.updates.patch_notes_release_count', $groupCount === 1 ? '1 release' : '{count} releases', ['count' => (string) $groupCount])) . '</small>';
        echo '</div>';
        foreach ($groupEntries as $version => $entry) {
            $isSelected = (string) $version === $selectedPatchVersion;
            $isInstalled = (string) $version === cms_current_version();
            $isLatest = empty($status['error']) && !empty($status['latest_version']) && (string) $version === (string) $status['latest_version'];
            $itemClass = 'patch-notes-version-option' . ($isSelected ? ' is-selected' : '') . ($isInstalled ? ' is-installed' : '') . ($isLatest ? ' is-latest' : '');
            $optionLabel = (string) ($entry['title'] ?? t('admin.updates.version_label', 'Version {version}', ['version' => (string) $version]));
            if (!empty($entry['released_label'])) {
                $optionLabel .= ' · ' . (string) $entry['released_label'];
            }
            echo '<button type="button" class="' . e($itemClass) . '" role="option" aria-selected="' . ($isSelected ? 'true' : 'false') . '" data-patch-version="' . e((string) $version) . '" data-patch-label="' . e($optionLabel) . '">';
            echo '<span class="patch-notes-version-number">' . e((string) $version) . '</span>';
            echo '<span class="patch-notes-version-meta">';
            if (!empty($entry['released_label'])) {
                echo '<small>' . e((string) $entry['released_label']) . '</small>';
            }
            if ($isInstalled) {
                echo '<small>' . e(t('admin.updates.patch_notes_installed_badge', 'Installed')) . '</small>';
            }
            if ($isLatest) {
                echo '<small>' . e(t('admin.updates.patch_notes_latest_badge', 'Latest')) . '</small>';
            }
            echo '</span>';
            echo '</button>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';
    echo '<select class="patch-notes-native-select" data-patch-notes-select aria-hidden="true" tabindex="-1">';
    foreach ($patchNotesVersions as $version => $entry) {
        // $selected stores the native select state for the currently displayed patch notes version.
        $selected = (string) $version === $selectedPatchVersion ? ' selected' : '';
        echo '<option value="' . e((string) $version) . '"' . $selected . '>' . e((string) ($entry['title'] ?? ('Version ' . $version))) . '</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.updates.patch_notes_show_button', 'Show')) . '</button>';
    echo '</form>';
    echo '<div class="patch-notes-shortcuts">';
    if (isset($patchNotesVersions[cms_current_version()])) {
        echo '<a class="button secondary" href="' . e(url_for('admin_update', ['patch_version' => cms_current_version()])) . '" data-patch-version="' . e(cms_current_version()) . '">' . e(t('admin.updates.patch_notes_current_button', 'Installed version')) . '</a>';
    }
    if (empty($status['error']) && !empty($status['update_available']) && !empty($status['latest_version']) && isset($patchNotesVersions[(string) $status['latest_version']])) {
        echo '<a class="button is-update-pending" href="' . e(url_for('admin_update', ['patch_version' => (string) $status['latest_version']])) . '" data-patch-version="' . e((string) $status['latest_version']) . '">' . e(t('admin.updates.patch_notes_pending_button', 'Pending update notes')) . '</a>';
    }
    echo '</div>';
    echo '</div>';
    echo '<div class="patch-notes-fragment" data-patch-notes-fragment aria-live="polite">';
    echo cms_render_update_patch_notes_fragment($patchNotesModel);
    echo '</div>';
    echo '</details>';
    echo '<script>';
    echo '(function(){';
    echo 'var viewer=document.querySelector("[data-patch-notes-viewer]");if(!viewer||!window.fetch){return;}';
    echo 'var form=viewer.querySelector("[data-patch-notes-form]");var select=viewer.querySelector("[data-patch-notes-select]");var input=viewer.querySelector("[data-patch-notes-input]");var target=viewer.querySelector("[data-patch-notes-fragment]");';
    echo 'var picker=viewer.querySelector("[data-patch-notes-picker]");var pickerButton=viewer.querySelector("[data-patch-notes-picker-button]");var pickerMenu=viewer.querySelector("[data-patch-notes-picker-menu]");var pickerText=viewer.querySelector("[data-patch-notes-picker-text]");';
    echo 'var endpoint=viewer.getAttribute("data-fragment-url")||"";';
    echo 'function setLoading(active){viewer.classList.toggle("is-loading",!!active);if(target){target.setAttribute("aria-busy",active?"true":"false");}}';
    echo 'function setPickerOpen(open){if(!picker||!pickerButton){return;}picker.classList.toggle("is-open",!!open);pickerButton.setAttribute("aria-expanded",open?"true":"false");}';
    echo 'function syncSelection(version,label){if(!version){return;}if(select){select.value=version;}if(input){input.value=version;}if(pickerText&&label){pickerText.textContent=label;}viewer.querySelectorAll(".patch-notes-version-option").forEach(function(option){var selected=option.getAttribute("data-patch-version")===version;option.classList.toggle("is-selected",selected);option.setAttribute("aria-selected",selected?"true":"false");});}';
    echo 'function loadVersion(version,pushState,label){if(!endpoint||!target||!version){return;}syncSelection(version,label||"");var url=new URL(endpoint,window.location.href);url.searchParams.set("patch_notes_fragment","1");url.searchParams.set("patch_version",version);setLoading(true);fetch(url.toString(),{headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"}}).then(function(response){if(!response.ok){throw new Error("HTTP "+response.status);}return response.json();}).then(function(payload){if(!payload||!payload.ok){throw new Error("Invalid response");}target.innerHTML=payload.html||"";if(payload.version){var active=viewer.querySelector("[data-patch-version=\""+CSS.escape(payload.version)+"\"]");syncSelection(payload.version,active?active.getAttribute("data-patch-label")||"":"");}if(pushState&&window.history&&window.history.replaceState){var pageUrl=new URL(window.location.href);pageUrl.searchParams.set("patch_version",payload.version||version);window.history.replaceState(null,"",pageUrl.toString());}}).catch(function(){var fallbackUrl=new URL(' . json_encode(url_for('admin_update')) . ',window.location.href);fallbackUrl.searchParams.set("patch_version",version);window.location.href=fallbackUrl.toString();}).finally(function(){setLoading(false);});}';
    echo 'if(pickerButton){pickerButton.addEventListener("click",function(){setPickerOpen(!picker.classList.contains("is-open"));});}';
    echo 'if(pickerMenu){pickerMenu.addEventListener("click",function(event){var option=event.target.closest("[data-patch-version]");if(!option){return;}event.preventDefault();viewer.open=true;setPickerOpen(false);loadVersion(option.getAttribute("data-patch-version")||"",true,option.getAttribute("data-patch-label")||"");});}';
    echo 'document.addEventListener("click",function(event){if(picker&&!picker.contains(event.target)){setPickerOpen(false);}});';
    echo 'document.addEventListener("keydown",function(event){if(event.key==="Escape"){setPickerOpen(false);}});';
    echo 'if(form){form.addEventListener("submit",function(event){event.preventDefault();viewer.open=true;loadVersion(input?input.value:(select?select.value:""),true);});}';
    echo 'if(select){select.addEventListener("change",function(){viewer.open=true;loadVersion(select.value,true,select.options[select.selectedIndex]?select.options[select.selectedIndex].textContent:"");});}';
    echo 'viewer.querySelectorAll(".patch-notes-shortcuts [data-patch-version]").forEach(function(link){link.addEventListener("click",function(event){event.preventDefault();viewer.open=true;loadVersion(link.getAttribute("data-patch-version")||"",true);});});';
    echo '})();';
    echo '</script>';
    $patchNotesHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-update-tab-notes', $patchNotesHtml, false);

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.updates.advanced_kicker', 'Recovery and testing')) . '</p><h2>' . e(t('admin.updates.advanced_tools', 'Advanced tools')) . '</h2></div><p class="muted">' . e(t('admin.updates.advanced_hint', 'Use beta installs and clean reinstall only when you intentionally need to test or repair the deployed code.')) . '</p></div>';
    echo '<div class="admin-maintenance-grid admin-update-tools-grid">';
    echo '<article class="admin-maintenance-card admin-update-tool-card"><strong>' . e(t('admin.updates.beta_build')) . '</strong><span>' . e(t('admin.updates.beta_code_help')) . '</span>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="update_action" value="beta_install">';
    echo '<label>' . e(t('admin.updates.beta_code')) . '<input name="beta_commit" value="' . e(application_update_beta_commit()) . '" placeholder="abcdef1234567890"></label>';
    echo '<button type="submit">' . e(t('admin.updates.install_beta')) . '</button>';
    echo '</form></article>';
    if ($betaActive) {
        echo '<article class="admin-maintenance-card admin-update-tool-card"><strong>' . e(t('admin.updates.restore_stable')) . '</strong><span>' . e(t('admin.updates.restore_stable_help')) . '</span>';
        echo '<form method="post" class="form-grid">' . csrf_field();
        echo '<input type="hidden" name="update_action" value="beta_revert">';
        echo '<button type="submit" class="button secondary">' . e(t('admin.updates.restore_stable')) . '</button>';
        echo '</form></article>';
    }
    echo '<article class="admin-maintenance-card admin-update-tool-card is-danger"><strong>' . e(t('admin.updates.clean_reinstall_title')) . '</strong><span>' . e(t('admin.updates.clean_reinstall_description')) . '</span>';
    echo '<form method="post" class="form-grid danger-zone">' . csrf_field();
    echo '<input type="hidden" name="update_action" value="clean_reinstall">';
    echo '<p class="muted">' . t('admin.updates.clean_reinstall_protected') . '</p>';
    echo '<label>' . e(t('admin.updates.confirm_reinstall_label')) . '<input name="clean_reinstall_confirm" autocomplete="off" placeholder="REINSTALL"></label>';
    echo '<button type="submit" class="button danger">' . e(t('admin.updates.clean_reinstall_button')) . '</button>';
    echo '</form></article>';
    echo '<article class="admin-maintenance-card admin-update-tool-card"><strong>' . e(t('admin.updates.runtime_diagnostics_card_title', 'Runtime diagnostics')) . '</strong><span>' . e(t('admin.updates.runtime_diagnostics_card_help', 'Inspect PHP, GD, Imagick, and image format support on this host.')) . '</span><a class="button secondary" href="' . e(url_for('admin_diagnostics')) . '">' . e(t('admin.updates.open_diagnostics', 'Open diagnostics')) . '</a></article>';
    echo '</div>';
    $advancedHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-update-tab-advanced', $advancedHtml, false);

    render_footer();
}
