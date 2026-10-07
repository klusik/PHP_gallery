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
 * Convert the limited PATCH_NOTES.md syntax into safe admin HTML.
 *
 * @param string $markdown Markdown value.
 * @return string Text result for the caller.
 */
function view_update_patch_notes_markdown_html(string $markdown): string
{
    if ($markdown === '') {
        return '<p class="muted">' . e(t('admin.updates.patch_notes_empty', 'No patch notes were found for this version.')) . '</p>';
    }

    // $html stores the generated safe HTML fragments.
    $html = [];
    // $inList tracks whether a Markdown list is currently open.
    $inList = false;
    // $inCode tracks whether a fenced code section is currently open.
    $inCode = false;
    // $codeLines stores raw lines inside a fenced code section.
    $codeLines = [];

    foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
        // $rawLine stores the unmodified Markdown line for code fences.
        $rawLine = (string) $line;
        // $trimmed stores a whitespace-trimmed copy for syntax checks.
        $trimmed = trim($rawLine);

        if (str_starts_with($trimmed, '```')) {
            if ($inCode) {
                $html[] = '<pre><code>' . e(implode("\n", $codeLines)) . '</code></pre>';
                $codeLines = [];
                $inCode = false;
            } else {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $inCode = true;
            }
            continue;
        }

        if ($inCode) {
            $codeLines[] = $rawLine;
            continue;
        }

        if ($trimmed === '') {
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            continue;
        }

        if (preg_match('/^(#{3,6})\s+(.+)$/', $trimmed, $headingMatch)) {
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            // $level stores a bounded heading level suitable inside the update panel.
            $level = min(5, max(3, strlen((string) $headingMatch[1])));
            $html[] = '<h' . $level . '>' . view_update_patch_notes_inline_markdown((string) $headingMatch[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $listMatch)) {
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }
            $html[] = '<li>' . view_update_patch_notes_inline_markdown((string) $listMatch[1]) . '</li>';
            continue;
        }

        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
        $html[] = '<p>' . view_update_patch_notes_inline_markdown($trimmed) . '</p>';
    }

    if ($inCode) {
        $html[] = '<pre><code>' . e(implode("\n", $codeLines)) . '</code></pre>';
    }
    if ($inList) {
        $html[] = '</ul>';
    }

    return implode("\n", $html);
}

/**
 * Protect one safe HTTP(S) anchor or escaped rejected markup from later formatting.
 *
 * @param string $url HTML-escaped URL captured from the source Markdown.
 * @param string $label Escaped caption, possibly containing protected code tokens.
 * @param string $fallback Escaped original markup for a rejected target.
 * @param array<string,string> $linkTokens Mutable map of protected inline fragments.
 * @param string $prefix Unique prefix for the current inline render.
 * @return string Opaque fragment token restored after emphasis formatting.
 */
function view_update_patch_notes_link_token(string $url, string $label, string $fallback, array &$linkTokens, string $prefix): string
{
    // Undo the renderer's escape, then resolve entities written in the source URL.
    $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parts = parse_url($url);
    $html = $fallback;
    if (preg_match('~^https?://~i', $url) === 1
        && preg_match('/[\x00-\x20<>]/u', $url) !== 1
        && strpbrk($url, "\"'") === false
        && !str_contains($url, $prefix)
        && is_array($parts) && !empty($parts['host'])) {
        $label = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $label) ?? $label;
        $html = '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
    }
    $token = $prefix . 'L' . count($linkTokens) . 'Z';
    $linkTokens[$token] = $html;
    return $token;
}

/**
 * Render safe inline patch-note links, emphasis and literal code spans.
 *
 * @param string $text Raw inline Markdown from a release-note section.
 * @return string Escaped HTML with HTTP(S) links opened in a new tab.
 */
function view_update_patch_notes_inline_markdown(string $text): string
{
    // Protect code and complete anchors before emphasis can modify URL bytes.
    $prefix = 'PATCHNOTES' . bin2hex(random_bytes(8)) . 'X';
    $codeTokens = [];
    $linkTokens = [];
    $escaped = e($text);
    $escaped = preg_replace_callback('/`([^`]+)`/u', static function (array $match) use (&$codeTokens, $prefix): string {
        $token = $prefix . 'C' . count($codeTokens) . 'Z';
        $codeTokens[$token] = '<code>' . $match[1] . '</code>';
        return $token;
    }, $escaped) ?? $escaped;
    // Support Markdown references and one level of balanced URL parentheses.
    $escaped = preg_replace_callback('~\[([^\]\r\n]+)\]\(((?:[^()\s]|\([^()\s]*\))+)\)~u',
        static function (array $match) use (&$linkTokens, $prefix): string {
            return view_update_patch_notes_link_token($match[2], $match[1], $match[0], $linkTokens, $prefix);
        }, $escaped) ?? $escaped;
    $escaped = preg_replace_callback('~&lt;(https?://[^\s<>]+?)&gt;~iu',
        static function (array $match) use (&$linkTokens, $prefix): string {
            return view_update_patch_notes_link_token($match[1], $match[1], $match[0], $linkTokens, $prefix);
        }, $escaped) ?? $escaped;
    // Stop at escaped HTML delimiters and protected code, and retain prose punctuation.
    $barePattern = '~https?://(?:(?!' . preg_quote($prefix, '~') . '|&(?:lt|gt|quot);|&#(?:0*39|x0*27);)[^\s<>])+~iu';
    $escaped = preg_replace_callback($barePattern, static function (array $match) use (&$linkTokens, $prefix): string {
        $url = rtrim($match[0], '.,!');
        foreach ([')' => '(', ']' => '[', '}' => '{'] as $closing => $opening) {
            while (str_ends_with($url, $closing) && substr_count($url, $closing) > substr_count($url, $opening)) {
                $url = substr($url, 0, -1);
            }
        }
        return view_update_patch_notes_link_token($url, $url, $url, $linkTokens, $prefix) . substr($match[0], strlen($url));
    }, $escaped) ?? $escaped;
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    // Restore captions before their code tokens; never parse generated HTML again.
    return strtr(strtr($escaped, $linkTokens), $codeTokens);
}

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
        echo view_update_patch_notes_markdown_html((string) ($selectedEntry['markdown'] ?? ''));
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
 * @return void Emits synchronized progress and recovery markup.
 */
function view_render_update_job_card(?array $job, bool $installerEnabled, string $csrfHtml): void
{
    echo '<section class="admin-update-job" data-update-job-scope';
    if ($job === null) {
        echo ' hidden></section>';
        return;
    }

    echo ' data-update-job-id="' . e((string) ($job['id'] ?? '')) . '" data-update-job-status="' . e((string) ($job['status'] ?? '')) . '" data-update-job-auto-resume="' . (!array_key_exists('auto_resume', $job) || !empty($job['auto_resume']) ? '1' : '0') . '">';
    $progress = (array) ($job['progress'] ?? []);
    $percent = isset($progress['percent']) ? (int) $progress['percent'] : (int) ($job['stage_percent'] ?? 0);
    echo '<div class="admin-update-job-heading"><div><p class="admin-kicker">Resumable update job</p><h3 data-update-job-title>' . e((string) ($job['display_title'] ?? $job['stage_label'] ?? '')) . '</h3></div><strong data-update-job-percent>' . e((string) max(0, min(100, $percent))) . '%</strong></div>';
    echo '<div class="admin-update-job-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . e((string) $percent) . '"><span data-update-job-progress style="width:' . e((string) max(0, min(100, $percent))) . '%"></span></div>';
    echo '<p class="muted" data-update-job-message>' . e((string) ($progress['message'] ?? 'Update job is ready to continue.')) . '</p>';
    echo '<details class="admin-update-job-details"><summary>' . e(t('admin.updates.status', 'Status')) . ' · <code data-update-job-code>' . e((string) ($job['id'] ?? '')) . '</code></summary><p class="muted"><strong>Stage:</strong> <span data-update-job-stage>' . e((string) ($job['stage_label'] ?? '')) . '</span> · <strong>Attempts:</strong> <span data-update-job-attempts>' . e((string) (int) ($job['attempts'] ?? 0)) . '</span></p></details>';
    if (!empty($job['error']) && is_array($job['error'])) {
        echo '<div class="notice" data-update-job-error>' . e((string) ($job['error']['message'] ?? 'Update failed.')) . ' Reference: <code>' . e((string) ($job['error']['reference'] ?? '')) . '</code></div>';
    } else {
        echo '<div class="notice" data-update-job-error hidden></div>';
    }
    echo '<div class="notice" data-update-job-complete' . ((string) ($job['status'] ?? '') === 'completed' ? '' : ' hidden') . '>Update completed successfully. The saved pre-update snapshot remains available for rollback.</div>';
    echo '<div class="notice" data-update-job-cancelled' . ((string) ($job['status'] ?? '') === 'cancelled' ? '' : ' hidden') . '>Prepared update cancelled before activation. No application files were changed.</div>';
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
 * Render the compact release summary shared by the page and passive AJAX refresh.
 *
 * @param array<string, mixed> $viewModel Controller-prepared release presentation state.
 * @return void Emits release state and primary controls.
 */
function view_render_update_release_summary(array $viewModel): void
{
    $status = (array) ($viewModel['status'] ?? []);
    $betaActive = !empty($viewModel['beta_active']);
    $installerEnabled = !empty($viewModel['installer_enabled']);
    $csrfHtml = (string) ($viewModel['csrf_html'] ?? '');
    $updateUrl = (string) ($viewModel['urls']['update'] ?? '');
    // $latestVersion stores the readable latest release value for the status summary.
    $latestVersion = !empty($status['latest_version']) ? (string) $status['latest_version'] : t('admin.common.unknown', 'Unknown');
    // $channelLabel stores the readable channel currently installed on this instance.
    $channelLabel = $betaActive ? t('admin.updates.channel_beta') : t('admin.updates.channel_stable');
    // $updateStateLabel stores the high-level update state displayed in the summary.
    $updateStateLabel = !empty($status['error']) ? t('admin.updates.check_failed', 'Check failed') : (!empty($status['update_available']) ? t('admin.updates.update_available', 'Update available') : t('admin.updates.current'));
    // $updateStateClass stores a neutral class name for update state styling.
    $updateStateClass = !empty($status['error']) ? 'is-warning' : (!empty($status['update_available']) ? 'is-attention' : 'is-ok');

    echo '<div class="admin-update-release-heading">';
    echo '<div class="admin-update-state ' . e($updateStateClass) . '"><p class="admin-kicker">' . e(t('admin.updates.status_kicker', 'Release status')) . '</p><h2>' . e($updateStateLabel) . '</h2></div>';
    echo '<div class="admin-update-release-actions">';
    echo '<form method="post" action="' . e($updateUrl) . '" class="inline-action-form" data-update-check-form>' . $csrfHtml . '<input type="hidden" name="update_action" value="force_check"><button type="submit" class="button secondary" title="' . e(t('admin.updates.force_check_hint', 'Bypass the local one-hour cache and ask GitHub now. GitHub rate-limit headers are still recorded and respected after the response.')) . '">' . e(t('admin.updates.force_check_button', 'Force check')) . '</button></form>';
    if (empty($status['error']) && !empty($status['update_available']) && $installerEnabled) {
        echo '<form method="post" action="' . e($updateUrl) . '" class="inline-action-form" data-update-job-form>' . $csrfHtml . '<input type="hidden" name="update_action" value="stable_update"><button type="submit" class="is-update-pending">' . e(t('admin.updates.update_button')) . '</button></form>';
    }
    echo '<a class="button secondary" href="#admin-update-tab-notes">' . e(t('admin.updates.patch_notes_title', 'Patch notes')) . '</a>';
    echo '</div></div>';
    echo '<dl class="admin-update-versions">';
    echo '<div><dt>' . e(t('admin.updates.installed_version')) . '</dt><dd>' . e((string) ($viewModel['installed_version'] ?? '')) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.updates.latest_version')) . '</dt><dd>' . e($latestVersion) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.updates.active_channel')) . '</dt><dd>' . e($channelLabel) . ($betaActive ? ' · <code>' . e((string) ($viewModel['beta_commit'] ?? '')) . '</code>' : '') . '</dd></div>';
    echo '</dl>';
    if (!empty($status['error'])) {
        echo '<p class="muted">Update metadata check failed. Reference: <code>' . e((string) ($viewModel['status_error_reference'] ?? '')) . '</code></p>';
    } elseif (!empty($status['update_available']) && !$installerEnabled) {
        echo '<p class="muted">' . e(t('admin.features.built_in_update_installer.disabled_action', 'The built-in update installer is disabled in Admin > Features. Read-only update checks remain available.')) . '</p>';
    }
}

/**
 * Render the replaceable patch-notes picker and selected release together.
 *
 * @param array<string,mixed> $viewModel Prepared installed and discovery presentation.
 * @return string Viewer markup without executable inline scripts.
 */
function view_render_update_patch_notes_viewer(array $viewModel): string
{
    $status = (array) ($viewModel['status'] ?? []);
    $patchNotesModel = (array) ($viewModel['patch_notes_model'] ?? []);
    $patchNotesVersions = (array) ($patchNotesModel['versions'] ?? []);
    $selectedPatchVersion = (string) ($patchNotesModel['selected_version'] ?? '');
    $installedVersion = (string) ($viewModel['installed_version'] ?? '');
    $urls = (array) ($viewModel['urls'] ?? []);
    ob_start();
    echo '<details class="patch-notes-viewer" data-patch-notes-viewer data-error-text="' . e(t('admin.updates.patch_notes_load_failed')) . '" data-fragment-url="' . e((string) ($urls['patch_notes_fragment'] ?? '')) . '" open>';
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
    return (string) ob_get_clean();
}

/** Render the Updates workspace. @param array<string,mixed> $viewModel Prepared page state. @return void Emits HTML. */
/**
 * Render the replaceable GitHub diagnostics from observed response headers.
 * @param array<string,mixed> $viewModel Prepared repository and gateway status.
 * @return string Diagnostics markup without any remote request.
 */
function view_render_update_api_status(array $viewModel): string
{
    $githubApiStatus = (array) ($viewModel['github_api_status'] ?? []);
    $repository = (string) ($viewModel['repository'] ?? '');
    $githubProjectUrl = (string) ($viewModel['github_project_url'] ?? '');
    ob_start();
    echo '<details class="admin-update-diagnostics"><summary>' . e(t('admin.updates.github_api_title', 'Rate-limit status')) . ' · ' . e($repository) . '</summary><div class="admin-update-diagnostics-content">';
    echo '<article class="admin-update-card admin-update-repository">';
    echo '<div><p class="admin-kicker">' . e(t('admin.updates.repository')) . '</p><h3>' . e($repository) . '</h3></div>';
    echo '<p class="muted">' . e(t('admin.updates.repository_hint', 'The updater uses this repository for release metadata and ZIP downloads.')) . '</p>';
    echo '<a class="button secondary" href="' . e($githubProjectUrl) . '" target="_blank" rel="noopener noreferrer">' . e(t('admin.updates.open_github')) . '</a>';
    echo '</article>';
    echo '<article class="admin-update-card">';
    echo '<div><p class="admin-kicker">' . e(t('admin.updates.github_api_kicker', 'GitHub API policy')) . '</p><h3>' . e(t('admin.updates.github_api_title', 'Rate-limit status')) . '</h3></div>';
    echo '<p class="muted">' . e(t('admin.updates.github_api_hint', 'The updater uses response headers from normal GitHub API calls. It does not call /rate_limit just to inspect limits.')) . '</p>';
    echo '<div class="admin-update-source-notes"><p class="muted">' . e(empty($status['branch']) ? t('admin.updates.branch_unknown', 'Branch not available') : t('admin.updates.checked_branch_value', ['branch' => (string) $status['branch']])) . '</p>';
    echo '<p class="muted">' . e(!empty($status['version_source']) ? t('admin.updates.version_source_value', ['source' => (string) $status['version_source']]) : t('admin.updates.version_source_unknown', 'Version source not reported.')) . '</p></div>';
    echo '<dl class="admin-update-diagnostics-grid">';
    echo '<div><dt>' . e(t('admin.updates.github_api_last_checked', 'Last GitHub API response')) . '</dt><dd>' . e((string) ($githubApiStatus['last_checked_label'] ?? '')) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.updates.github_api_remaining', 'Remaining quota')) . '</dt><dd>' . e((string) ($githubApiStatus['remaining'] ?? '')) . ' / ' . e((string) ($githubApiStatus['limit'] ?? '')) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.updates.github_api_used', 'Used quota')) . '</dt><dd>' . e((string) ($githubApiStatus['used'] ?? '')) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.updates.github_api_resource', 'Resource')) . '</dt><dd>' . e((string) ($githubApiStatus['resource'] ?? '')) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.updates.github_api_status_code', 'Last HTTP status')) . '</dt><dd>' . e((string) ($githubApiStatus['last_status'] ?? '')) . (!empty($githubApiStatus['last_from_cache']) ? ' <span class="tag">' . e(t('admin.updates.github_api_cache_hit', 'served from local ETag cache')) . '</span>' : '') . '</dd></div>';
    echo '<div><dt>' . e(t('admin.updates.github_api_etag', 'ETag')) . '</dt><dd>' . e((string) ($githubApiStatus['etag'] ?? '')) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.updates.github_api_reset', 'Primary reset')) . '</dt><dd>' . e((string) ($githubApiStatus['reset_label'] ?? '')) . '</dd></div>';
    echo '</dl>';
    echo '</article>';
    echo '</div></details>';
    return (string) ob_get_clean();
}

/** Render Updates. @param array<string,mixed> $viewModel Prepared workspace. @return void Emits HTML. */
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

    echo '<div class="admin-updates-page" data-update-job-failed-label="' . e(t('admin.updates.job_failed_title', 'Update stopped')) . '" data-update-job-completed-label="' . e(t('admin.updates.job_completed_title', 'Update completed')) . '" data-update-job-cancelled-label="' . e(t('admin.updates.job_cancelled_title', 'Update cancelled')) . '">';
    echo '<section class="hero admin-update-hero"><div><p class="admin-kicker">' . e(t('admin.updates.kicker', 'Application maintenance')) . '</p><h1>' . e(t('admin.updates.title')) . '</h1><p class="muted">' . e(t('admin.updates.page_hint', 'Check releases, review patch notes, install updates, and use advanced recovery tools from one place.')) . '</p></div><nav class="nav">';
    echo '<a class="button secondary" href="' . e((string) ($urls['admin'] ?? '')) . '">' . e(t('admin.common.back_to_dashboard')) . '</a>';
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
    echo '<div class="admin-update-workspace">';
    echo '<div data-update-release-summary data-update-status-url="' . e((string) ($urls['status_fragment'] ?? '')) . '">';
    view_render_update_release_summary($viewModel);
    echo '</div>';
    echo '<p class="muted" data-update-status-error hidden>' . e(t('admin.updates.refresh_status_failed', 'Could not refresh the release status.')) . ' <button type="button" class="button secondary" data-update-status-refresh>' . e(t('admin.updates.refresh_status_retry', 'Refresh status')) . '</button></p>';
    view_render_update_job_card($activeUpdateJob, $installerEnabled, $csrfHtml);
    echo '</div>';
    if (!empty($githubApiStatus['wait']['active'])) {
        echo '<p class="notice"><strong>' . e(t('admin.updates.github_api_waiting', 'Waiting')) . ':</strong> ' . e(t('admin.updates.github_api_next_allowed', 'Next allowed check: {time}', ['time' => (string) ($githubApiStatus['wait']['next_allowed_label'] ?? '')])) . '</p>';
    }
    echo '<article class="admin-update-card admin-update-automatic">';
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
    echo '<div class="admin-update-automatic-actions"><form method="post" class="admin-update-inline-form">' . $csrfHtml;
    echo '<input type="hidden" name="update_action" value="autoupdate_settings">';
    echo '<label class="checkbox-row"><input type="checkbox" name="application_autoupdate_enabled" value="1"' . (!empty($autoupdateStatus['enabled']) ? ' checked' : '') . '> <span>' . e(t('admin.updates.autoupdate_enable_label', 'Enable automatic stable updates')) . '</span></label>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.common.save', 'Save')) . '</button>';
    echo '</form>';
    echo '<form method="post" class="admin-update-inline-form">' . $csrfHtml;
    echo '<input type="hidden" name="update_action" value="autoupdate_dry_run">';
    echo '<button type="submit" class="button secondary" title="' . e(t('admin.updates.autoupdate_dry_run_hint', 'Run a metadata-only check now. This updates the last check diagnostics but never installs files.')) . '">' . e(t('admin.updates.autoupdate_dry_run_button', 'Run dry check now')) . '</button>';
    echo '</form></div></article>';
    echo '<div data-update-api-status>' . view_render_update_api_status($viewModel) . '</div>';
    $statusHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-update-tab-status', $statusHtml, true);

    ob_start();
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.updates.patch_notes_kicker', 'Release notes')) . '</p><h2>' . e(t('admin.updates.patch_notes_title', 'Patch notes')) . '</h2></div><p class="muted">' . e(t('admin.updates.patch_notes_page_hint', 'Select an installed, latest, or older version without reloading the full admin page.')) . '</p></div>';
    echo '<div data-update-patch-notes>' . view_render_update_patch_notes_viewer($viewModel) . '</div>';
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
    echo '</div>';
}
