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
    // $status stores an intermediate value used by the surrounding gallery workflow.
    $status = check_application_update();
    // $betaActive stores an intermediate value used by the surrounding gallery workflow.
    $betaActive = application_update_beta_active();
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
