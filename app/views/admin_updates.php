<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_updates.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the Admin application updater from controller-prepared state.
 *
 * Responsibilities:
 *   - Render release status, patch notes, and advanced updater tabs
 *   - Render durable update-job progress and recovery controls
 *   - Render patch-note fragments used by full-page and AJAX flows
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
 *   - Do not read request globals from this view.
 *   - Do not call models or updater/domain services from this view.
 *   - Service-derived state, URLs, CSRF markup, and safe error references must be prepared by the controller.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Core\render_admin_tabs;
use function Gallery\Services\t;

/**
 * Render only the currently selected patch notes section.
 *
 * @param array<string, mixed> $patchNotesModel Controller-prepared patch notes model.
 * @return string Rendered patch notes fragment.
 */
function view_render_update_patch_notes_fragment(array $patchNotesModel): string
{
    // $patchNotesData stores source diagnostics displayed above the rendered notes.
    $patchNotesData = (array) ($patchNotesModel['data'] ?? []);
    // $patchNotesVersions stores parsed release-note entries keyed by version.
    $patchNotesVersions = (array) ($patchNotesModel['versions'] ?? []);
    // $selectedPatchVersion stores the selected release-note key.
    $selectedPatchVersion = (string) ($patchNotesModel['selected_version'] ?? '');

    ob_start();
    echo '<div class="patch-notes-fragment-inner">';
    if (!empty($patchNotesData['error'])) {
        echo '<p class="muted patch-notes-source-note">' . e(t('admin.updates.patch_notes_remote_failed', 'GitHub patch notes could not be loaded, showing bundled notes if available. Error: {error}', ['error' => (string) $patchNotesData['error']])) . '</p>';
    } else {
        echo '<p class="muted patch-notes-source-note">' . e(t('admin.updates.patch_notes_source_value', 'Source: {source}, branch: {branch}', ['source' => (string) ($patchNotesData['source'] ?? 'github'), 'branch' => (string) ($patchNotesData['branch'] ?? '')])) . '</p>';
    }
    if ($selectedPatchVersion !== '' && isset($patchNotesVersions[$selectedPatchVersion])) {
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
 * Render the durable update-job card used by JavaScript and non-JavaScript flows.
 *
 * @param array<string, mixed>|null $job Controller-prepared update job view model.
 * @param bool $installerEnabled Whether installer mutation controls are available.
 * @param string $csrfHtml Trusted CSRF field markup prepared by the controller.
 */
function view_render_update_job_card(?array $job, bool $installerEnabled, string $csrfHtml): void
{
    echo '<section class="admin-update-job" data-update-job-scope';
    if ($job === null) {
        echo ' hidden></section>';
        return;
    }

    echo ' data-update-job-id="' . e((string) ($job['id'] ?? '')) . '" data-update-job-status="' . e((string) ($job['status'] ?? '')) . '">';
    echo '<div class="admin-update-job-heading"><div><p class="admin-kicker">Resumable update job</p><h3 data-update-job-title>' . e((string) ($job['stage_label'] ?? '')) . '</h3></div><code>' . e((string) ($job['id'] ?? '')) . '</code></div>';
    $progress = (array) ($job['progress'] ?? []);
    $percent = isset($progress['percent']) ? (int) $progress['percent'] : (int) ($job['stage_percent'] ?? 0);
    echo '<div class="admin-update-job-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . e((string) $percent) . '"><span data-update-job-progress style="width:' . e((string) max(0, min(100, $percent))) . '%"></span></div>';
    echo '<p class="muted" data-update-job-message>' . e((string) ($progress['message'] ?? 'Update job is ready to continue.')) . '</p>';
    echo '<p class="muted"><strong>Stage:</strong> <span data-update-job-stage>' . e((string) ($job['stage_label'] ?? '')) . '</span> · <strong>Attempts:</strong> <span data-update-job-attempts>' . e((string) (int) ($job['attempts'] ?? 0)) . '</span></p>';
    if (!empty($job['error']) && is_array($job['error'])) {
        echo '<div class="notice" data-update-job-error>' . e((string) ($job['error']['message'] ?? 'Update failed.')) . ' Reference: <code>' . e((string) ($job['error']['reference'] ?? '')) . '</code></div>';
    } else {
        echo '<div class="notice" data-update-job-error hidden></div>';
    }
    if ((string) ($job['status'] ?? '') === 'completed') {
        echo '<div class="notice" data-update-job-complete>Update completed successfully. The saved pre-update snapshot remains available for rollback.</div>';
    } elseif ((string) ($job['status'] ?? '') === 'cancelled') {
        echo '<div class="notice" data-update-job-cancelled>Prepared update cancelled before activation. No application files were changed.</div>';
    }
    echo '<div data-update-job-actions>';
    if ($installerEnabled && !empty($job['can_resume'])) {
        echo '<form method="post" class="inline-action-form" data-update-job-control>' . $csrfHtml;
        echo '<input type="hidden" name="update_action" value="' . ((string) ($job['status'] ?? '') === 'failed' ? 'job_retry' : 'job_continue') . '">';
        echo '<input type="hidden" name="job_id" value="' . e((string) ($job['id'] ?? '')) . '">';
        echo '<button type="submit" class="button secondary">' . e((string) ($job['status'] ?? '') === 'failed' ? 'Retry from checkpoint' : 'Continue update') . '</button>';
        echo '</form>';
    }
    if (!empty($job['can_cancel'])) {
        echo '<form method="post" class="inline-action-form" data-update-job-control onsubmit="return confirm(\'Cancel this prepared update? Active application files have not been changed.\');">' . $csrfHtml;
        echo '<input type="hidden" name="update_action" value="job_cancel">';
        echo '<input type="hidden" name="job_id" value="' . e((string) ($job['id'] ?? '')) . '">';
        echo '<button type="submit" class="button secondary">Cancel prepared update</button>';
        echo '</form>';
    }
    if ($installerEnabled && !empty($job['can_rollback'])) {
        echo '<form method="post" class="inline-action-form" data-update-job-control onsubmit="return confirm(\'Restore the application files from the pre-update snapshot? Database migrations are not reversed.\');">' . $csrfHtml;
        echo '<input type="hidden" name="update_action" value="job_rollback">';
        echo '<input type="hidden" name="job_id" value="' . e((string) ($job['id'] ?? '')) . '">';
        echo '<button type="submit" class="button secondary">Rollback application files</button>';
        echo '</form>';
    }
    echo '</div>';
    echo '</section>';
}

/**
 * Render the Admin application update page body.
 *
 * @param array<string, mixed> $viewModel Controller-prepared updater presentation state.
 */
function view_render_admin_update_page(array $viewModel): void
{
    $status = (array) ($viewModel['status'] ?? []);
    $autoupdateStatus = (array) ($viewModel['autoupdate_status'] ?? []);
    $githubApiStatus = (array) ($viewModel['github_api_status'] ?? []);
    $activeUpdateJob = isset($viewModel['active_update_job']) && is_array($viewModel['active_update_job']) ? $viewModel['active_update_job'] : null;
    $patchNotesModel = (array) ($viewModel['patch_notes_model'] ?? []);
    $patchNotesVersions = (array) ($patchNotesModel['versions'] ?? []);
    $selectedPatchVersion = (string) ($patchNotesModel['selected_version'] ?? '');
    $betaActive = !empty($viewModel['beta_active']);
    $installerEnabled = !empty($viewModel['installer_enabled']);
    $notice = (string) ($viewModel['notice'] ?? '');
    $error = $viewModel['error'] ?? null;
    $installedVersion = (string) ($viewModel['installed_version'] ?? '');
    $betaCommit = (string) ($viewModel['beta_commit'] ?? '');
    $repository = (string) ($viewModel['repository'] ?? '');
    $githubProjectUrl = (string) ($viewModel['github_project_url'] ?? '');
    $csrfHtml = (string) ($viewModel['csrf_html'] ?? '');
    $urls = (array) ($viewModel['urls'] ?? []);
    $statusErrorReference = (string) ($viewModel['status_error_reference'] ?? '');

    // $latestVersion stores the readable latest release value for the status summary.
    $latestVersion = !empty($status['latest_version']) ? (string) $status['latest_version'] : t('admin.common.unknown', 'Unknown');
    // $channelLabel stores the readable channel currently installed on this instance.
    $channelLabel = $betaActive ? t('admin.updates.channel_beta') : t('admin.updates.channel_stable');
    // $updateStateLabel stores the high-level update state displayed in the summary cards.
    $updateStateLabel = !empty($status['error']) ? t('admin.updates.check_failed', 'Check failed') : (!empty($status['update_available']) ? t('admin.updates.update_available', 'Update available') : t('admin.updates.current'));
    // $updateStateClass stores a neutral class name for update state styling.
    $updateStateClass = !empty($status['error']) ? 'is-warning' : (!empty($status['update_available']) ? 'is-attention' : 'is-ok');

    echo '<section class="hero admin-update-hero"><div><p class="admin-kicker">' . e(t('admin.updates.kicker', 'Application maintenance')) . '</p><h1>' . e(t('admin.updates.title')) . '</h1><p class="muted">' . e(t('admin.updates.page_hint', 'Check releases, review patch notes, install updates, and use advanced recovery tools from one place.')) . '</p></div><nav class="nav">';
    echo '<a class="button secondary" href="' . e((string) ($urls['admin'] ?? '')) . '">' . e(t('admin.common.back_to_dashboard')) . '</a>';
    echo '<a class="button secondary" href="' . e($githubProjectUrl) . '" target="_blank" rel="noopener noreferrer">' . e(t('admin.updates.open_github')) . '</a>';
    echo '<form method="post" class="inline-action-form">' . $csrfHtml . '<input type="hidden" name="update_action" value="force_check"><button type="submit" class="button secondary">' . e(t('admin.updates.force_check_button', 'Force check')) . '</button></form>';
    echo '</nav></section>';

    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="notice">' . e(t('admin.updates.failed_value', ['error' => (string) $error])) . '</div>';
    }

    render_admin_tabs([
        ['id' => 'admin-update-tab-status', 'label' => t('admin.updates.status', 'Status'), 'active' => true],
        ['id' => 'admin-update-tab-notes', 'label' => t('admin.updates.patch_notes_title', 'Patch notes'), 'badge' => count($patchNotesVersions)],
        ['id' => 'admin-update-tab-advanced', 'label' => t('admin.updates.advanced_tools', 'Advanced tools')],
    ], 'admin-update-tab-status');

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.updates.status_kicker', 'Release status')) . '</p><h2>' . e(t('admin.updates.status')) . '</h2></div><p class="muted">' . e(t('admin.updates.status_hint', 'The updater checks GitHub metadata through the service layer and runs installs as durable, resumable jobs with bounded request-time slices.')) . '</p></div>';
    view_render_update_job_card($activeUpdateJob, $installerEnabled, $csrfHtml);
    echo '<div class="admin-metric-grid admin-update-metric-grid">';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.updates.installed_version')) . '</span><strong>' . e($installedVersion) . '</strong><small>' . e(t('admin.updates.installed_version_hint', 'Version currently running on this installation.')) . '</small></article>';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.updates.latest_version')) . '</span><strong>' . e($latestVersion) . '</strong><small>' . e(empty($status['branch']) ? t('admin.updates.branch_unknown', 'Branch not available') : t('admin.updates.checked_branch_value', ['branch' => (string) $status['branch']])) . '</small></article>';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.updates.active_channel')) . '</span><strong>' . e($channelLabel) . '</strong><small>' . ($betaActive ? e(t('admin.updates.installed_beta_code')) . ': <code>' . e($betaCommit) . '</code>' : e(t('admin.updates.stable_channel_hint', 'Stable release channel is active.'))) . '</small></article>';
    echo '<article class="admin-metric-card admin-update-state-card ' . e($updateStateClass) . '"><span>' . e(t('admin.updates.update_state', 'Update state')) . '</span><strong>' . e($updateStateLabel) . '</strong><small>' . e(!empty($status['version_source']) ? t('admin.updates.version_source_value', ['source' => (string) $status['version_source']]) : t('admin.updates.version_source_unknown', 'Version source not reported.')) . '</small></article>';
    echo '</div>';

    echo '<div class="admin-update-status-layout">';
    echo '<article class="admin-update-card">';
    echo '<div><p class="admin-kicker">' . e(t('admin.updates.repository')) . '</p><h3>' . e($repository) . '</h3></div>';
    echo '<p class="muted">' . e(t('admin.updates.repository_hint', 'The updater uses this repository for release metadata and ZIP downloads.')) . '</p>';
    echo '<a class="button secondary" href="' . e($githubProjectUrl) . '" target="_blank" rel="noopener noreferrer">' . e(t('admin.updates.open_github')) . '</a>';
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
    echo '<form method="post" class="form-grid admin-update-action-form">' . $csrfHtml;
    echo '<input type="hidden" name="update_action" value="force_check">';
    echo '<p class="muted">' . e(t('admin.updates.force_check_hint', 'Bypass the local one-hour cache and ask GitHub now. GitHub rate-limit headers are still recorded and respected after the response.')) . '</p>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.updates.force_check_button', 'Force check')) . '</button>';
    echo '</form>';
    echo '</article>';
    echo '<article class="admin-update-card">';
    echo '<div><p class="admin-kicker">' . e(t('admin.updates.autoupdate_kicker', 'Automatic updates')) . '</p><h3>' . e(!empty($autoupdateStatus['enabled']) ? t('admin.common.enabled', 'Enabled') : t('admin.common.disabled', 'Disabled')) . '</h3></div>';
    if (!$installerEnabled) {
        echo '<p class="muted">' . e(t('admin.features.built_in_update_installer.autoupdate_inactive', 'The automatic-update preference is preserved, but installation is inactive while Built-in Update Installer is disabled.')) . '</p>';
    } elseif (!empty($autoupdateStatus['beta_active'])) {
        echo '<p class="muted">' . e(t('admin.updates.autoupdate_beta_disabled_hint', 'Automatic updates are checked in settings, but ignored while beta code is installed. The setting is not changed.')) . '</p>';
    } else {
        echo '<p class="muted">' . e(t('admin.updates.autoupdate_hint', 'When enabled, normal page requests check for a stable update at most once every hour and install it automatically when available. The dry check button forces a fresh metadata-only check immediately.')) . '</p>';
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
    echo '<form method="post" class="form-grid admin-update-action-form">' . $csrfHtml;
    echo '<input type="hidden" name="update_action" value="autoupdate_settings">';
    echo '<label class="checkbox-row"><input type="checkbox" name="application_autoupdate_enabled" value="1"' . (!empty($autoupdateStatus['enabled']) ? ' checked' : '') . '> <span>' . e(t('admin.updates.autoupdate_enable_label', 'Enable automatic stable updates')) . '</span></label>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.common.save', 'Save')) . '</button>';
    echo '</form>';
    echo '<form method="post" class="form-grid admin-update-action-form">' . $csrfHtml;
    echo '<input type="hidden" name="update_action" value="autoupdate_dry_run">';
    echo '<p class="muted">' . e(t('admin.updates.autoupdate_dry_run_hint', 'Run a metadata-only check now. This updates the last check diagnostics but never installs files.')) . '</p>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.updates.autoupdate_dry_run_button', 'Run dry check now')) . '</button>';
    echo '</form></article>';
    echo '<article class="admin-update-card ' . (!empty($status['update_available']) ? 'is-attention' : '') . '">';
    echo '<div><p class="admin-kicker">' . e(t('admin.updates.primary_action', 'Primary action')) . '</p><h3>' . e($updateStateLabel) . '</h3></div>';
    if (!empty($status['error'])) {
        echo '<p class="muted">Update metadata check failed. Reference: <code>' . e($statusErrorReference) . '</code></p>';
    } elseif (!empty($status['update_available'])) {
        echo '<p>' . t('admin.updates.newer_available_description') . '</p>';
        if ($installerEnabled) {
            echo '<form method="post" class="form-grid admin-update-action-form" data-update-job-form>' . $csrfHtml;
            echo '<input type="hidden" name="update_action" value="stable_update">';
            echo '<button type="submit" class="is-update-pending">' . e(t('admin.updates.update_button')) . '</button></form>';
        } else {
            echo '<p class="muted">' . e(t('admin.features.built_in_update_installer.disabled_action', 'The built-in update installer is disabled in Admin > Features. Read-only update checks remain available.')) . '</p>';
        }
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
    echo '<details class="patch-notes-viewer" data-patch-notes-viewer data-fragment-url="' . e((string) ($urls['patch_notes_fragment'] ?? '')) . '" open>';
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
            $isInstalled = (string) $version === $installedVersion;
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
    if (!empty($viewModel['installed_patch_url'])) {
        echo '<a class="button secondary" href="' . e((string) $viewModel['installed_patch_url']) . '" data-patch-version="' . e($installedVersion) . '">' . e(t('admin.updates.patch_notes_current_button', 'Installed version')) . '</a>';
    }
    if (!empty($viewModel['pending_patch_url']) && !empty($status['latest_version'])) {
        echo '<a class="button is-update-pending" href="' . e((string) $viewModel['pending_patch_url']) . '" data-patch-version="' . e((string) $status['latest_version']) . '">' . e(t('admin.updates.patch_notes_pending_button', 'Pending update notes')) . '</a>';
    }
    echo '</div>';
    echo '</div>';
    echo '<div class="patch-notes-fragment" data-patch-notes-fragment aria-live="polite">';
    echo view_render_update_patch_notes_fragment($patchNotesModel);
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
    echo 'function loadVersion(version,pushState,label){if(!endpoint||!target||!version){return;}syncSelection(version,label||"");var url=new URL(endpoint,window.location.href);url.searchParams.set("patch_notes_fragment","1");url.searchParams.set("patch_version",version);setLoading(true);fetch(url.toString(),{headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"}}).then(function(response){if(!response.ok){throw new Error("HTTP "+response.status);}return response.json();}).then(function(payload){if(!payload||!payload.ok){throw new Error("Invalid response");}target.innerHTML=payload.html||"";if(payload.version){var active=viewer.querySelector("[data-patch-version=\\\""+CSS.escape(payload.version)+"\\\"]");syncSelection(payload.version,active?active.getAttribute("data-patch-label")||"":"");}if(pushState&&window.history&&window.history.replaceState){var pageUrl=new URL(window.location.href);pageUrl.searchParams.set("patch_version",payload.version||version);window.history.replaceState(null,"",pageUrl.toString());}}).catch(function(){var fallbackUrl=new URL(' . json_encode((string) ($urls['update'] ?? '')) . ',window.location.href);fallbackUrl.searchParams.set("patch_version",version);window.location.href=fallbackUrl.toString();}).finally(function(){setLoading(false);});}';
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
    echo '<p class="muted admin-update-progress-location">' . e(t('admin.updates.advanced_progress_hint', 'Update progress stays visible here after an advanced operation starts. You can leave and reopen this page; the saved job resumes from its last safe checkpoint.')) . '</p>';
    view_render_update_job_card($activeUpdateJob, $installerEnabled, $csrfHtml);
    echo '<div class="admin-maintenance-grid admin-update-tools-grid">';
    if ($installerEnabled) {
        echo '<article class="admin-maintenance-card admin-update-tool-card"><strong>' . e(t('admin.updates.beta_build')) . '</strong><span>' . e(t('admin.updates.beta_code_help')) . '</span>';
        echo '<form method="post" class="form-grid" data-update-job-form>' . $csrfHtml;
        echo '<input type="hidden" name="update_action" value="beta_install">';
        echo '<label>' . e(t('admin.updates.beta_code')) . '<input name="beta_commit" value="' . e($betaCommit) . '" placeholder="abcdef1234567890"></label>';
        echo '<button type="submit">' . e(t('admin.updates.install_beta')) . '</button>';
        echo '</form></article>';
        if ($betaActive) {
            echo '<article class="admin-maintenance-card admin-update-tool-card"><strong>' . e(t('admin.updates.restore_stable')) . '</strong><span>' . e(t('admin.updates.restore_stable_help')) . '</span>';
            echo '<form method="post" class="form-grid" data-update-job-form>' . $csrfHtml;
            echo '<input type="hidden" name="update_action" value="beta_revert">';
            echo '<button type="submit" class="button secondary">' . e(t('admin.updates.restore_stable')) . '</button>';
            echo '</form></article>';
        }
    } else {
        echo '<article class="admin-maintenance-card admin-update-tool-card"><strong>' . e(t('admin.features.built_in_update_installer.label', 'Built-in Update Installer')) . '</strong><span>' . e(t('admin.features.built_in_update_installer.disabled_action', 'The built-in update installer is disabled in Admin > Features. Read-only update checks remain available.')) . '</span><a class="button secondary" href="' . e((string) ($urls['features'] ?? '')) . '">' . e(t('admin.features.title', 'Features')) . '</a></article>';
    }
    echo '<article class="admin-maintenance-card admin-update-tool-card"><strong>' . e(t('admin.updates.malformed_root_cleanup_title', 'Clean misplaced deployment files')) . '</strong><span>' . e(t('admin.updates.malformed_root_cleanup_description', 'Back up and remove root files with literal backslashes and known application modules that belong under app/. Unrelated files are preserved. No reinstall or migrations are performed.')) . '</span>';
    echo '<form method="post" class="form-grid">' . $csrfHtml;
    echo '<input type="hidden" name="update_action" value="cleanup_malformed_root_files">';
    echo '<button type="submit" class="button secondary">' . e(t('admin.updates.malformed_root_cleanup_button', 'Run misplaced file cleanup')) . '</button>';
    echo '</form></article>';
    if ($installerEnabled) {
        echo '<article class="admin-maintenance-card admin-update-tool-card is-danger"><strong>' . e(t('admin.updates.clean_reinstall_title')) . '</strong><span>' . e(t('admin.updates.clean_reinstall_description')) . '</span>';
        echo '<form method="post" class="form-grid danger-zone" data-update-job-form>' . $csrfHtml;
        echo '<input type="hidden" name="update_action" value="clean_reinstall">';
        echo '<p class="muted">' . t('admin.updates.clean_reinstall_protected') . '</p>';
        echo '<label>' . e(t('admin.updates.confirm_reinstall_label')) . '<input name="clean_reinstall_confirm" autocomplete="off" placeholder="REINSTALL"></label>';
        echo '<button type="submit" class="button danger">' . e(t('admin.updates.clean_reinstall_button')) . '</button>';
        echo '</form></article>';
    }
    echo '<article class="admin-maintenance-card admin-update-tool-card"><strong>' . e(t('admin.updates.runtime_diagnostics_card_title', 'Runtime diagnostics')) . '</strong><span>' . e(t('admin.updates.runtime_diagnostics_card_help', 'Inspect PHP, GD, Imagick, and image format support on this host.')) . '</span><a class="button secondary" href="' . e((string) ($urls['diagnostics'] ?? '')) . '">' . e(t('admin.updates.open_diagnostics', 'Open diagnostics')) . '</a></article>';
    echo '</div>';
    $advancedHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-update-tab-advanced', $advancedHtml, false);
}
