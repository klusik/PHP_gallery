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

/**
 * Admin update controller model.
 *
 * This module owns only the update page request handler. It keeps the original
 * cms_admin_update() function name so the existing route dispatcher can continue
 * calling it after app/controllers.php loads this separated controller file.
 */


/**
 * Build the patch notes viewer model for the updates screen.
 */
function cms_update_patch_notes_model(array $status, ?string $requestedVersion = null, int $ttlSeconds = 0): array
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
            if ($action === 'beta_install') {
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
    // $status stores the fresh update check used by the page and reused by navigation badges.
    $status = check_application_update();
    cache_application_update_check($status);
    // $betaActive stores an intermediate value used by the surrounding gallery workflow.
    $betaActive = application_update_beta_active();
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
    echo '<section class="hero"><h1>' . e(t('admin.updates.title')) . '</h1><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.common.back_to_dashboard')) . '</a>';
    echo '<a class="button secondary" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">' . e(t('admin.updates.open_github')) . '</a>';
    echo '</nav></section>';
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="notice">' . e(t('admin.updates.failed_value', ['error' => $error])) . '</div>';
    }
    echo '<section class="panel"><h2>' . e(t('admin.updates.status')) . '</h2>';
    echo '<p>' . e(t('admin.updates.installed_version')) . ': <strong>' . e(cms_current_version()) . '</strong></p>';
    if ($betaActive) {
        echo '<p>' . e(t('admin.updates.active_channel')) . ': <strong>' . e(t('admin.updates.channel_beta')) . '</strong></p>';
        echo '<p>' . e(t('admin.updates.installed_beta_code')) . ': <code>' . e(application_update_beta_commit()) . '</code></p>';
    } else {
        echo '<p>' . e(t('admin.updates.active_channel')) . ': <strong>' . e(t('admin.updates.channel_stable')) . '</strong></p>';
    }
    echo '<p>' . e(t('admin.updates.repository')) . ': <a href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">' . e(CMS_GITHUB_REPOSITORY) . '</a></p>';
    if (!empty($status['error'])) {
        echo '<p class="muted">' . e(t('admin.updates.check_failed_value', ['error' => (string) $status['error']])) . '</p>';
    } else {
        echo '<p>' . e(t('admin.updates.latest_version')) . ': <strong>' . e((string) $status['latest_version']) . '</strong></p>';
        echo '<p class="muted">' . e(t('admin.updates.checked_branch_value', ['branch' => (string) $status['branch']])) . '</p>';
        if (!empty($status['version_source'])) {
            echo '<p class="muted">' . e(t('admin.updates.version_source_value', ['source' => (string) $status['version_source']])) . '</p>';
        }
        if (!empty($status['update_available'])) {
            echo '<form method="post" class="form-grid">' . csrf_field();
            echo '<input type="hidden" name="update_action" value="stable_update">';
            echo '<p>' . t('admin.updates.newer_available_description') . '</p>';
            echo '<button type="submit" class="is-update-pending">' . e(t('admin.updates.update_button')) . '</button></form>';
        } else {
            echo '<p class="muted">' . e(t('admin.updates.current')) . '</p>';
        }
    }
    echo '<details class="patch-notes-viewer" data-patch-notes-viewer data-fragment-url="' . e(url_for('admin_update', ['patch_notes_fragment' => '1'])) . '">';
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
        echo '<span>Version ' . e((string) $groupVersion) . '</span>';
        echo '<small>' . e(t($groupCount === 1 ? 'admin.updates.patch_notes_one_release' : 'admin.updates.patch_notes_release_count', $groupCount === 1 ? '1 release' : '{count} releases', ['count' => (string) $groupCount])) . '</small>';
        echo '</div>';
        foreach ($groupEntries as $version => $entry) {
            $isSelected = (string) $version === $selectedPatchVersion;
            $isInstalled = (string) $version === cms_current_version();
            $isLatest = empty($status['error']) && !empty($status['latest_version']) && (string) $version === (string) $status['latest_version'];
            $itemClass = 'patch-notes-version-option' . ($isSelected ? ' is-selected' : '') . ($isInstalled ? ' is-installed' : '') . ($isLatest ? ' is-latest' : '');
            echo '<button type="button" class="' . e($itemClass) . '" role="option" aria-selected="' . ($isSelected ? 'true' : 'false') . '" data-patch-version="' . e((string) $version) . '" data-patch-label="' . e((string) ($entry['title'] ?? ('Version ' . $version))) . '">';
            echo '<span class="patch-notes-version-number">' . e((string) $version) . '</span>';
            echo '<span class="patch-notes-version-meta">';
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

    echo '<hr><h3>' . e(t('admin.updates.beta_build')) . '</h3>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="update_action" value="beta_install">';
    echo '<label>' . e(t('admin.updates.beta_code')) . '<input name="beta_commit" value="' . e(application_update_beta_commit()) . '" placeholder="abcdef1234567890"></label>';
    echo '<p class="muted">' . e(t('admin.updates.beta_code_help')) . '</p>';
    echo '<button type="submit">' . e(t('admin.updates.install_beta')) . '</button>';
    echo '</form>';
    if ($betaActive) {
        echo '<form method="post" class="form-grid form-grid-spaced">' . csrf_field();
        echo '<input type="hidden" name="update_action" value="beta_revert">';
        echo '<p class="muted">' . e(t('admin.updates.restore_stable_help')) . '</p>';
        echo '<button type="submit" class="button secondary">' . e(t('admin.updates.restore_stable')) . '</button>';
        echo '</form>';
    }
    echo '<hr><h3>' . e(t('admin.updates.clean_reinstall_title')) . '</h3>';
    echo '<form method="post" class="form-grid form-grid-spaced danger-zone">' . csrf_field();
    echo '<input type="hidden" name="update_action" value="clean_reinstall">';
    echo '<p>' . e(t('admin.updates.clean_reinstall_description')) . '</p>';
    echo '<p class="muted">' . t('admin.updates.clean_reinstall_protected') . '</p>';
    echo '<label>' . e(t('admin.updates.confirm_reinstall_label')) . '<input name="clean_reinstall_confirm" autocomplete="off" placeholder="REINSTALL"></label>';
    echo '<button type="submit" class="button danger">' . e(t('admin.updates.clean_reinstall_button')) . '</button>';
    echo '</form>';
    echo '</section>';
    render_footer();
}
