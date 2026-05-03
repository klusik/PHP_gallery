<?php

declare(strict_types=1);

/**
 * Admin authentication controller model.
 * 
 * This module handles login, logout, account updates, and reset workflows. It does not touch theme configuration or visual customization.
 */

function cms_admin_login(): void
{
    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([(string) ($_POST['username'] ?? '')]);
        // Variable $user stores this steps working value.
        $user = $stmt->fetch();
        if ($user && password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            redirect_to(url_for('admin'));
        }
        // Variable $error stores this steps working value.
        $error = 'Invalid username or password.';
    }
    render_header('Admin login');
    if (isset($error)) {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>Admin login</h1><form method="post" class="form-grid">';
    echo csrf_field();
    echo '<label>Username<input name="username" required autocomplete="username"></label>';
    echo '<label>Password<input name="password" type="password" required autocomplete="current-password"></label>';
    echo '<button type="submit">Log in</button></form></section>';
    render_footer();
}

function cms_admin_logout(): void
{
    unset($_SESSION['user_id']);
    redirect_to(url_for('home'));
}

function cms_admin_account(): void
{
    require_admin();
    // Variable $user stores this steps working value.
    $user = current_user();
    if (!$user) {
        redirect_to(url_for('admin_login'));
    }
    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $currentPassword stores this steps working value.
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        // Variable $newUsername stores this steps working value.
        $newUsername = trim((string) ($_POST['username'] ?? ''));
        // Variable $newPassword stores this steps working value.
        $newPassword = (string) ($_POST['new_password'] ?? '');
        // Variable $confirmPassword stores this steps working value.
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        // Variable $errors stores this steps working value.
        $errors = [];

        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT username, password_hash FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        // Variable $account stores this steps working value.
        $account = $stmt->fetch();
        if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        }
        if ($newUsername === '') {
            $errors[] = 'Username is required.';
        }
        if ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $errors[] = 'New password confirmation does not match.';
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        }
        if ($newUsername !== '') {
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $stmt->execute([$newUsername, (int) $user['id']]);
            if ($stmt->fetch()) {
                $errors[] = 'That username is already in use.';
            }
        }
        if (!$errors) {
            $sql = 'UPDATE users SET username = ?, updated_at = ?';
            // Variable $params stores this steps working value.
            $params = [$newUsername, now_sql()];
            if ($newPassword !== '') {
                $sql .= ', password_hash = ?';
                $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = ?';
            $params[] = (int) $user['id'];
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            redirect_to(url_for('admin_account', ['saved' => 1]));
        }
        // Variable $error stores this steps working value.
        $error = implode(' ', $errors);
    }
    render_header('Account');
    if (isset($_GET['saved'])) {
        echo '<div class="notice">Account updated.</div>';
    }
    if (isset($error)) {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>Account</h1><form method="post" class="form-grid">';
    echo csrf_field();
    echo '<label>Username<input name="username" required autocomplete="username" value="' . e((string) $user['username']) . '"></label>';
    echo '<label>Current password<input name="current_password" type="password" required autocomplete="current-password"></label>';
    echo '<label>New password<input name="new_password" type="password" autocomplete="new-password"></label>';
    echo '<label>Confirm new password<input name="confirm_password" type="password" autocomplete="new-password"></label>';
    echo '<p class="muted">Leave the new password fields empty to keep the current password.</p>';
    echo '<button type="submit">Save account</button></form></section>';
    render_footer();
}

function cms_admin_reset(): void
{
    require_admin();
    $error = null;
    $notice = '';

    if (request_method() === 'POST') {
        verify_csrf();
        try {
            $result = restore_application_stable_release();
            admin_log_event('info', 'update.stable_restored', 'Admin restored the stable branch head from the reset page.', $result);
            $notice = 'Restored the stable branch head. Copied ' . (int) $result['files_copied'] . ' files.';
        } catch (Throwable $exception) {
            admin_log_event('warning', 'update.reset_failed', 'Stable branch reset failed.', ['error' => $exception->getMessage()]);
            $error = $exception->getMessage();
        }
    }

    render_header('Reset application');
    echo '<section class="hero"><h1>Reset application</h1><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_update')) . '">Open updates</a>';
    echo '</nav></section>';
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="notice">Reset failed: ' . e($error) . '</div>';
    }
    echo '<section class="panel"><h2>Restore stable branch head</h2>';
    echo '<p>This replaces the application files with the current `main` branch head from GitHub, which is useful if a beta build broke the site.</p>';
    echo '<p class="muted">You must be logged in as an administrator. The action uses the same restore logic as the admin update screen.</p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<button type="submit" class="button danger">Reset to stable branch head</button>';
    echo '</form></section>';
    render_footer();
}

