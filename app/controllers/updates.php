<?php

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
    $error = null;

    if (request_method() === 'POST') {
        verify_csrf();
        try {
            $action = (string) ($_POST['update_action'] ?? 'stable_update');
            if ($action === 'beta_install') {
                $result = install_application_beta((string) ($_POST['beta_commit'] ?? ''));
                admin_log_event('info', 'update.beta_installed', 'Admin installed a beta application build.', $result);
                $_SESSION['admin_update_notice'] = 'Installed beta code ' . (string) $result['version'] . '. Copied ' . (int) $result['files_copied'] . ' files and applied ' . count((array) $result['migrations']) . ' migrations.';
            } elseif ($action === 'beta_revert') {
                $result = restore_application_stable_release();
                admin_log_event('info', 'update.beta_reverted', 'Admin restored beta application build from the stable branch head.', $result);
                $_SESSION['admin_update_notice'] = 'Restored the stable release from the GitHub branch head.';
            } else {
                $result = install_application_update();
                admin_log_event('info', 'update.installed', 'Admin installed an application update.', $result);
                $_SESSION['admin_update_notice'] = 'Updated to version ' . (string) $result['version'] . '. Copied ' . (int) $result['files_copied'] . ' files and applied ' . count((array) $result['migrations']) . ' migrations.';
            }
            redirect_to(url_for('admin_update'));
        } catch (Throwable $exception) {
            admin_log_event('warning', 'update.failed', 'Application update failed.', ['error' => $exception->getMessage()]);
            $error = $exception->getMessage();
        }
    }

    $notice = (string) ($_SESSION['admin_update_notice'] ?? '');
    unset($_SESSION['admin_update_notice']);
    $status = check_application_update();
    $betaActive = application_update_beta_active();
    render_header('Application updates');
    echo '<section class="hero"><h1>Application updates</h1><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a>';
    echo '<a class="button secondary" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">Open GitHub</a>';
    echo '</nav></section>';
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="notice">Update failed: ' . e($error) . '</div>';
    }
    echo '<section class="panel"><h2>Status</h2>';
    echo '<p>Installed version: <strong>' . e(cms_current_version()) . '</strong></p>';
    if ($betaActive) {
        echo '<p>Active channel: <strong>beta</strong></p>';
        echo '<p>Installed beta code: <code>' . e(application_update_beta_commit()) . '</code></p>';
    } else {
        echo '<p>Active channel: <strong>stable</strong></p>';
    }
    echo '<p>Repository: <a href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">' . e(CMS_GITHUB_REPOSITORY) . '</a></p>';
    if (!empty($status['error'])) {
        echo '<p class="muted">Could not check for updates: ' . e((string) $status['error']) . '</p>';
    } else {
        echo '<p>Latest version on GitHub: <strong>' . e((string) $status['latest_version']) . '</strong></p>';
        echo '<p class="muted">Checked branch: ' . e((string) $status['branch']) . '</p>';
        if (!empty($status['version_source'])) {
            echo '<p class="muted">Version source: ' . e((string) $status['version_source']) . '</p>';
        }
        if (!empty($status['update_available'])) {
            echo '<form method="post" class="form-grid">' . csrf_field();
            echo '<input type="hidden" name="update_action" value="stable_update">';
            echo '<p>A newer version is available. The updater will download the GitHub branch archive, back up overwritten files under <code>cache/updates/backups</code>, and keep local config, galleries, cache, and custom CSS untouched.</p>';
            echo '<button type="submit" class="is-update-pending">Update(1)</button></form>';
        } else {
            echo '<p class="muted">This installation is current.</p>';
        }
    }
    echo '<hr><h3>Beta build</h3>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="update_action" value="beta_install">';
    echo '<label>Beta code<input name="beta_commit" value="' . e(application_update_beta_commit()) . '" placeholder="abcdef1234567890"></label>';
    echo '<p class="muted">Enter the beta code for the snapshot you want to install.</p>';
    echo '<button type="submit">Install beta snapshot</button>';
    echo '</form>';
    if ($betaActive) {
        echo '<form method="post" class="form-grid form-grid-spaced">' . csrf_field();
        echo '<input type="hidden" name="update_action" value="beta_revert">';
        echo '<p class="muted">This downloads the stable branch head from GitHub and restores application files from that release. Database changes from the beta are not rolled back automatically.</p>';
        echo '<button type="submit" class="button secondary">Restore stable release</button>';
        echo '</form>';
    }
    echo '</section>';
    render_footer();
}
