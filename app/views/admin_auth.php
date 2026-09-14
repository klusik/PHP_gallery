<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_auth.php
 * Module Type: View
 *
 * Purpose:
 *   Renders administrator authentication, account, and reset presentation from
 *   controller-prepared view models.
 *
 * Responsibilities:
 *   - Render administrator login and password-reset pages
 *   - Render account profile, recovery mail, Google login, and OpenAI settings
 *   - Render the stable-restore confirmation page
 *   - Avoid request globals, persistence, and domain-service policy lookups
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
 *   - Translation, escaping, and shared layout renderers are presentation helpers.
 *   - The controller owns request parsing, authentication policy, feature policy,
 *     schema inspection, persistence, redirects, and URL preparation.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/**
 * Render administrator login.
 *
 * @param array<string,mixed> $viewModel Controller-prepared login presentation state.
 */
function view_render_admin_login(array $viewModel): void
{
    render_header(t('admin.auth.login_title'));
    foreach ((array) ($viewModel['notices'] ?? []) as $notice) {
        if ((string) $notice !== '') {
            echo '<div class="notice">' . e((string) $notice) . '</div>';
        }
    }
    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }

    echo '<section class="panel"><h1>' . e(t('admin.auth.login_title')) . '</h1><form method="post" class="form-grid">';
    echo (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="return" value="' . e((string) ($viewModel['return_target'] ?? '')) . '">';
    echo '<label>' . e(t('admin.auth.username_or_email')) . '<input name="identifier" required autocomplete="username"></label>';
    echo '<label>' . e(t('admin.auth.password')) . '<input name="password" type="password" required autocomplete="current-password"></label>';
    if (!empty($viewModel['remember_ready'])) {
        echo '<label class="account-settings-toggle account-settings-compact-toggle"><input type="checkbox" name="remember_login" value="1"' . (!empty($viewModel['remember_default_checked']) ? ' checked' : '') . '> <span><strong>' . e(t('admin.auth.keep_signed_in', 'Keep me signed in')) . '</strong><small>' . e(t('admin.auth.keep_signed_in_help', 'Uses a hashed browser token so the admin session can survive normal shared-host PHP session cleanup.')) . '</small></span></label>';
    } elseif (!empty($viewModel['show_remember_schema_unknown'])) {
        echo '<p class="muted">' . e(t('admin.auth.remember_schema_unknown', 'Persistent login is temporarily disabled because its database schema could not be verified. Ordinary session login remains available.')) . '</p>';
    }
    echo '<button type="submit">' . e(t('admin.auth.login_button')) . '</button></form>';
    if (!empty($viewModel['google_ready'])) {
        echo '<div class="admin-google-login-choice"><span>' . e(t('admin.auth.or', 'or')) . '</span><a class="button secondary" href="' . e((string) ($viewModel['google_login_url'] ?? '')) . '">' . e(t('admin.google.continue_with_google', 'Continue with Google')) . '</a></div>';
    } elseif (!empty($viewModel['show_google_schema_unknown'])) {
        echo '<p class="muted">' . e(t('admin.google.schema_unknown', 'Google login is temporarily disabled because its identity-link schema could not be verified.')) . '</p>';
    }
    echo '<p class="muted"><a href="' . e((string) ($viewModel['forgot_password_url'] ?? '')) . '">' . e(t('admin.auth.forgot_password_link')) . '</a></p></section>';
    render_footer();
}

/**
 * Render the administrator password-reset request form.
 *
 * @param array<string,mixed> $viewModel Controller-prepared reset-request state.
 */
function view_render_admin_forgot_password(array $viewModel): void
{
    render_header(t('admin.auth.forgot_password_title'));
    $notice = (string) ($viewModel['notice'] ?? '');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('admin.auth.forgot_password_title')) . '</h1>';
    echo '<p class="muted">' . e(t('admin.auth.forgot_password_help')) . '</p>';
    echo '<form method="post" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<label>' . e(t('admin.auth.username_or_recovery_email')) . '<input name="identifier" required autocomplete="username"></label>';
    echo '<button type="submit">' . e(t('admin.auth.request_reset_link')) . '</button></form>';
    echo '<p class="muted"><a href="' . e((string) ($viewModel['login_url'] ?? '')) . '">' . e(t('admin.auth.back_to_login')) . '</a></p></section>';
    render_footer();
}

/**
 * Render one administrator password-reset page state.
 *
 * @param array<string,mixed> $viewModel Controller-prepared reset state.
 */
function view_render_admin_reset_password(array $viewModel): void
{
    render_header(t('admin.auth.reset_password_title'));
    $state = (string) ($viewModel['state'] ?? 'form');

    if ($state === 'schema_unavailable') {
        echo '<section class="panel"><h1>' . e(t('admin.auth.reset_password_title')) . '</h1><div class="notice">' . e(t('admin.auth.schema_temporarily_unavailable', 'Authentication storage is temporarily unavailable. Try again after the database/schema inspection issue is resolved.')) . '</div></section>';
        render_footer();
        return;
    }

    if ($state === 'invalid') {
        echo '<section class="panel"><h1>' . e(t('admin.auth.reset_password_title')) . '</h1><div class="notice">' . e(t('admin.auth.reset_link_invalid')) . '</div>';
        echo '<p><a class="button secondary" href="' . e((string) ($viewModel['forgot_password_url'] ?? '')) . '">' . e(t('admin.auth.request_new_reset_link')) . '</a></p></section>';
        render_footer();
        return;
    }

    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('admin.auth.reset_password_title')) . '</h1>';
    echo '<p class="muted">' . e(t('admin.auth.set_new_password_for', ['username' => (string) ($viewModel['username'] ?? '')])) . '</p>';
    echo '<form method="post" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="selector" value="' . e((string) ($viewModel['selector'] ?? '')) . '">';
    echo '<input type="hidden" name="token" value="' . e((string) ($viewModel['token'] ?? '')) . '">';
    echo '<label>' . e(t('admin.auth.new_password')) . '<input name="new_password" type="password" required minlength="8" autocomplete="new-password"></label>';
    echo '<label>' . e(t('admin.auth.confirm_new_password')) . '<input name="confirm_password" type="password" required minlength="8" autocomplete="new-password"></label>';
    echo '<button type="submit">' . e(t('admin.auth.save_new_password')) . '</button></form></section>';
    render_footer();
}

/**
 * Render administrator account settings.
 *
 * @param array<string,mixed> $viewModel Controller-prepared account presentation state.
 */
function view_render_admin_account(array $viewModel): void
{
    $user = (array) ($viewModel['user'] ?? []);
    $resetSettings = (array) ($viewModel['reset_settings'] ?? []);
    $googleLinkedAccount = is_array($viewModel['google_linked_account'] ?? null) ? $viewModel['google_linked_account'] : null;
    $openaiModels = (array) ($viewModel['openai_models'] ?? []);
    $csrfHtml = (string) ($viewModel['csrf_html'] ?? '');
    $resetReady = !empty($viewModel['reset_ready']);
    $googleReady = !empty($viewModel['google_ready']);
    $openaiReady = !empty($viewModel['openai_ready']);
    $openaiSchemaReady = !empty($viewModel['openai_schema_ready']);

    render_header(t('admin.account.title'));
    foreach ((array) ($viewModel['notices'] ?? []) as $notice) {
        if ((string) $notice !== '') {
            echo '<div class="notice">' . e((string) $notice) . '</div>';
        }
    }
    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }

    echo '<section class="panel account-settings-page">';
    echo '<div class="account-settings-hero">';
    echo '<div><p class="account-settings-kicker">' . e(t('admin.account.kicker')) . '</p><h1>' . e(t('admin.account.title')) . '</h1><p class="muted">' . e(t('admin.account.description')) . '</p></div>';
    echo '<div class="nav"><a class="button secondary" href="' . e((string) ($viewModel['central_settings_url'] ?? '')) . '">' . e(t('admin.settings.open_centralized', 'Open centralized settings')) . '</a></div>';
    echo '<div class="account-settings-status ' . ($resetReady ? 'is-ready' : 'is-incomplete') . '">';
    echo '<span class="account-settings-status-label">' . e(t('admin.account.password_reset')) . '</span>';
    echo '<strong>' . e($resetReady ? t('admin.account.status_ready') : t('admin.account.status_needs_setup')) . '</strong>';
    echo '<small>' . e($resetReady ? t('admin.account.status_ready_help') : t('admin.account.status_needs_setup_help')) . '</small>';
    echo '</div></div>';
    echo '<div class="account-settings-grid">';

    echo '<article class="account-settings-card">';
    echo '<div class="account-settings-card-header"><div><h2>' . e(t('admin.account.profile_title')) . '</h2><p class="muted">' . e(t('admin.account.profile_description')) . '</p></div></div>';
    echo '<form method="post" class="form-grid account-settings-form">' . $csrfHtml;
    echo '<input type="hidden" name="account_action" value="profile">';
    echo '<label>' . e(t('admin.account.username')) . '<input name="username" required autocomplete="username" value="' . e((string) ($user['username'] ?? '')) . '"></label>';
    echo '<label>' . e(t('admin.account.recovery_email')) . '<input name="email" type="email" autocomplete="email" value="' . e((string) ($viewModel['account_email'] ?? '')) . '" placeholder="admin@example.com"' . (!empty($viewModel['email_schema_available']) ? '' : ' disabled') . '></label>';
    if (!empty($viewModel['email_schema_available'])) {
        echo '<p class="account-settings-help">' . e(t('admin.account.recovery_email_help')) . '</p>';
    } elseif (!empty($viewModel['email_schema_unknown'])) {
        echo '<p class="account-settings-help">' . e(t('admin.auth.schema_temporarily_unavailable', 'Authentication storage is temporarily unavailable. Try again after the database/schema inspection issue is resolved.')) . '</p>';
    } else {
        echo '<p class="account-settings-help">' . e(t('admin.auth.password_reset_migration_required', 'Password reset storage is not installed yet. Apply the pending database migration before using password reset.')) . '</p>';
    }
    echo '<div class="account-settings-callout"><strong>' . e(t('admin.account.before_save')) . '</strong> ' . e(t('admin.account.before_save_help')) . '</div>';
    echo '<label>' . e(t('admin.account.current_password')) . '<input name="current_password" type="password" required autocomplete="current-password"></label>';
    echo '<div class="account-settings-two-column">';
    echo '<label>' . e(t('admin.account.new_password')) . '<input name="new_password" type="password" autocomplete="new-password" placeholder="' . e(t('admin.account.new_password_placeholder')) . '"></label>';
    echo '<label>' . e(t('admin.account.confirm_new_password')) . '<input name="confirm_password" type="password" autocomplete="new-password" placeholder="' . e(t('admin.account.confirm_new_password_placeholder')) . '"></label>';
    echo '</div>';
    echo '<p class="account-settings-help">' . e(t('admin.account.password_optional_help')) . '</p>';
    echo '<div class="account-settings-actions"><button type="submit">' . e(t('admin.account.save_account')) . '</button></div></form></article>';

    echo '<article class="account-settings-card">';
    echo '<div class="account-settings-card-header"><div><h2>' . e(t('admin.account.reset_email_title')) . '</h2><p class="muted">' . e(t('admin.account.reset_email_description')) . '</p></div></div>';
    echo '<div class="account-settings-readiness">';
    echo '<strong>' . e(t('admin.account.recovery_status')) . '</strong> ' . e($resetReady ? t('admin.account.recovery_status_ready') : t('admin.account.recovery_status_incomplete')) . '</div>';
    echo '<form method="post" class="form-grid account-settings-form account-settings-reset-form">' . $csrfHtml;
    echo '<input type="hidden" name="account_action" value="password_reset_settings">';
    echo '<label class="account-settings-toggle"><input type="checkbox" name="password_reset_enabled" value="1"' . (!empty($resetSettings['enabled']) ? ' checked' : '') . '> <span><strong>' . e(t('admin.account.enable_reset_emails')) . '</strong><small>' . e(t('admin.account.enable_reset_emails_help')) . '</small></span></label>';
    echo '<div class="account-settings-two-column">';
    echo '<label>' . e(t('admin.account.mail_transport')) . '<select name="password_reset_transport"><option value="php_mail"' . (($resetSettings['transport'] ?? '') === 'php_mail' ? ' selected' : '') . '>' . e(t('admin.account.transport_php_mail')) . '</option><option value="smtp"' . (($resetSettings['transport'] ?? '') === 'smtp' ? ' selected' : '') . '>' . e(t('admin.account.transport_smtp')) . '</option></select></label>';
    echo '<label>' . e(t('admin.account.reset_link_lifetime')) . '<input name="password_reset_token_lifetime_minutes" type="number" min="15" max="1440" step="1" value="' . e((string) ($resetSettings['token_lifetime_minutes'] ?? '')) . '"></label>';
    echo '</div>';
    echo '<div class="account-settings-two-column">';
    echo '<label>' . e(t('admin.account.sender_email')) . '<input name="password_reset_from_email" type="email" autocomplete="email" value="' . e((string) ($resetSettings['from_email'] ?? '')) . '" placeholder="no-reply@example.com"></label>';
    echo '<label>' . e(t('admin.account.sender_name')) . '<input name="password_reset_from_name" value="' . e((string) ($resetSettings['from_name'] ?? '')) . '" placeholder="' . e((string) ($viewModel['site_name'] ?? '')) . '"></label>';
    echo '</div>';
    echo '<details class="account-settings-details" open><summary>' . e(t('admin.account.smtp_settings')) . '</summary>';
    echo '<p class="account-settings-help">' . e(t('admin.account.smtp_help')) . '</p>';
    echo '<div class="account-settings-two-column">';
    echo '<label>' . e(t('admin.account.smtp_host')) . '<input name="password_reset_smtp_host" value="' . e((string) ($resetSettings['smtp_host'] ?? '')) . '" placeholder="smtp.example.com"></label>';
    echo '<label>' . e(t('admin.account.smtp_port')) . '<input name="password_reset_smtp_port" type="number" min="1" max="65535" step="1" value="' . e((string) ($resetSettings['smtp_port'] ?? '')) . '"></label>';
    echo '</div>';
    echo '<label>' . e(t('admin.account.smtp_encryption')) . '<select name="password_reset_smtp_encryption"><option value="tls"' . (($resetSettings['smtp_encryption'] ?? '') === 'tls' ? ' selected' : '') . '>STARTTLS</option><option value="ssl"' . (($resetSettings['smtp_encryption'] ?? '') === 'ssl' ? ' selected' : '') . '>' . e(t('admin.account.smtp_implicit_tls')) . '</option><option value="none"' . (($resetSettings['smtp_encryption'] ?? '') === 'none' ? ' selected' : '') . '>' . e(t('admin.common.none')) . '</option></select></label>';
    echo '<div class="account-settings-two-column">';
    echo '<label>' . e(t('admin.account.smtp_username')) . '<input name="password_reset_smtp_username" autocomplete="username" value="' . e((string) ($resetSettings['smtp_username'] ?? '')) . '"></label>';
    echo '<label>' . e(t('admin.account.smtp_password')) . '<input name="password_reset_smtp_password" type="password" autocomplete="new-password" placeholder="' . e(!empty($resetSettings['smtp_password']) ? t('admin.account.smtp_password_placeholder_keep') : '') . '"></label>';
    echo '</div>';
    echo '<input type="hidden" name="keep_existing_smtp_password" value="1">';
    echo '<p class="account-settings-help">' . e(t('admin.account.smtp_password_help')) . '</p>';
    echo '</details>';
    echo '<div class="account-settings-actions"><button type="submit">' . e(t('admin.account.save_reset_settings')) . '</button></div></form>';
    echo '<form method="post" class="account-settings-test-form">' . $csrfHtml;
    echo '<input type="hidden" name="account_action" value="password_reset_test_email">';
    echo '<div><strong>' . e(t('admin.account.delivery_test')) . '</strong><p class="muted">' . e(t('admin.account.delivery_test_help')) . '</p></div>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.account.send_test_email')) . '</button></form></article>';

    echo '<article class="account-settings-card account-google-settings-card">';
    echo '<div class="account-settings-card-header"><div><h2>' . e(t('admin.google.profile_title', 'Google login')) . '</h2><p class="muted">' . e(t('admin.google.profile_description', 'Optional Google sign-in for this admin account. Password login remains available.')) . '</p></div></div>';
    echo '<div class="account-settings-readiness ' . ($googleReady ? 'is-ready' : 'is-incomplete') . '">';
    echo '<strong>' . e(t('admin.google.status', 'Status')) . '</strong> ';
    $googleStatus = (string) ($viewModel['google_status'] ?? 'ready_to_link');
    if ($googleStatus === 'schema_unknown') {
        echo e(t('admin.google.schema_unknown', 'Google login storage could not be inspected. Linking and Google sign-in are temporarily disabled until the database/schema inspection issue is resolved.'));
    } elseif ($googleStatus === 'migration_required') {
        echo e(t('admin.google.status_migration_required', 'Database migration required before Google login can be configured.'));
    } elseif ($googleStatus === 'config_required') {
        echo e(t('admin.google.status_config_required', 'Add Google OAuth client ID and secret to config.php before linking accounts.'));
    } elseif ($googleStatus === 'linked') {
        echo e(t('admin.google.status_linked', 'Linked and ready for login.'));
    } else {
        echo e(t('admin.google.status_ready_to_link', 'Configured. Link this profile to a Google account before using Google login.'));
    }
    echo '</div>';
    echo '<p class="account-settings-help"><strong>' . e(t('admin.google.callback_url', 'Authorized redirect URI')) . ':</strong> <code>' . e((string) ($viewModel['google_redirect_uri'] ?? '')) . '</code></p>';
    if ($googleLinkedAccount) {
        echo '<div class="account-settings-callout"><strong>' . e(t('admin.google.linked_account', 'Linked Google account')) . '</strong> ' . e(trim((string) ($googleLinkedAccount['email'] ?? '')) !== '' ? (string) $googleLinkedAccount['email'] : t('admin.google.linked_account_no_email', 'Google account is linked without a stored email.')) . '</div>';
        if (!empty($googleLinkedAccount['name'])) {
            echo '<p class="account-settings-help">' . e(t('admin.google.linked_name', 'Google display name')) . ': ' . e((string) $googleLinkedAccount['name']) . '</p>';
        }
        echo '<form method="post" class="form-grid account-settings-form account-settings-google-form">' . $csrfHtml;
        echo '<input type="hidden" name="account_action" value="google_disconnect">';
        echo '<label>' . e(t('admin.account.current_password')) . '<input name="current_password" type="password" required autocomplete="current-password"></label>';
        echo '<div class="account-settings-actions"><button type="submit" class="button secondary">' . e(t('admin.google.disconnect', 'Disconnect Google account')) . '</button></div></form>';
    } elseif ($googleReady) {
        echo '<p class="account-settings-help">' . e(t('admin.google.link_help', 'Linking must be started while you are logged in with your normal admin password. After that, Google login will accept only this linked Google account.')) . '</p>';
        echo '<div class="account-settings-actions"><a class="button" href="' . e((string) ($viewModel['google_link_url'] ?? '')) . '">' . e(t('admin.google.link_button', 'Link Google account')) . '</a></div>';
    }
    echo '</article>';

    if (!empty($viewModel['openai_feature_enabled'])) {
        echo '<article class="account-settings-card account-openai-settings-card">';
        echo '<div class="account-settings-card-header"><div><h2>' . e(t('admin.openai.profile_title', 'OpenAI text assistance')) . '</h2><p class="muted">' . e(t('admin.openai.profile_description', 'Optional profile-level API access for gallery description drafts and text cleanup.')) . '</p></div></div>';
        echo '<div class="account-settings-readiness ' . ($openaiReady ? 'is-ready' : 'is-incomplete') . '">';
        echo '<strong>' . e(t('admin.openai.status', 'Status')) . '</strong> ';
        if (!$openaiSchemaReady) {
            echo e(t('admin.openai.status_migration_required', 'Database migration required before this optional feature can be configured.'));
        } elseif ($openaiReady) {
            echo e(t('admin.openai.status_ready', 'Enabled and ready for this account.'));
        } else {
            echo e(t('admin.openai.status_disabled', 'Disabled. Gallery editors will not show AI controls.'));
        }
        echo '</div>';
        if ($openaiSchemaReady) {
            $openaiModel = (string) ($viewModel['openai_model'] ?? '');
            $openaiKeyHint = (string) ($viewModel['openai_key_hint'] ?? '');
            echo '<form method="post" class="form-grid account-settings-form account-settings-openai-form">' . $csrfHtml;
            echo '<input type="hidden" name="account_action" value="openai_text_settings">';
            echo '<label class="account-settings-toggle"><input type="checkbox" name="openai_text_enabled" value="1"' . (!empty($viewModel['openai_enabled']) ? ' checked' : '') . '> <span><strong>' . e(t('admin.openai.enable', 'Enable OpenAI text assistance')) . '</strong><small>' . e(t('admin.openai.enable_help', 'When enabled and a key is saved, selected editors can request reviewable AI text suggestions.')) . '</small></span></label>';
            if ($openaiKeyHint !== '') {
                echo '<p class="account-settings-key-status"><strong>' . e(t('admin.openai.saved_key', 'Saved key')) . ':</strong> ' . e($openaiKeyHint) . '</p>';
            }
            echo '<div class="account-settings-two-column">';
            echo '<label>' . e(t('admin.openai.api_key', 'OpenAI API key')) . '<input name="openai_text_api_key" type="password" autocomplete="new-password" placeholder="' . e($openaiKeyHint !== '' ? t('admin.openai.api_key_placeholder_keep', 'Leave blank to keep the saved key') : t('admin.openai.api_key_placeholder_new', 'sk-...')) . '"></label>';
            echo '<label>' . e(t('admin.openai.model', 'Model')) . '<select name="openai_text_model">';
            foreach ($openaiModels as $modelId => $modelInfo) {
                echo '<option value="' . e((string) $modelId) . '"' . ($openaiModel === (string) $modelId ? ' selected' : '') . '>' . e((string) ($modelInfo['label'] ?? $modelId)) . '</option>';
            }
            echo '</select></label>';
            echo '</div>';
            echo '<p class="account-settings-help">' . e(t('admin.openai.api_key_help', 'The key is encrypted before database storage. It is never shown again and is never written to admin logs.')) . '</p>';
            echo '<div class="account-openai-model-list" aria-label="' . e(t('admin.openai.model_choices', 'Available OpenAI models')) . '">';
            foreach ($openaiModels as $modelId => $modelInfo) {
                $isSelected = $openaiModel === (string) $modelId;
                echo '<div class="account-openai-model-card' . ($isSelected ? ' is-selected' : '') . '">';
                echo '<div><strong>' . e((string) ($modelInfo['label'] ?? $modelId)) . '</strong><code>' . e((string) $modelId) . '</code></div>';
                echo '<span>' . e((string) ($modelInfo['badge'] ?? '')) . '</span>';
                echo '<p>' . e((string) ($modelInfo['description'] ?? '')) . '</p>';
                echo '</div>';
            }
            echo '</div>';
            echo '<p class="account-settings-help">' . e(t('admin.openai.model_help', 'Default: GPT-5.4 mini. You can change this later without changing gallery data.')) . '</p>';
            echo '<label class="account-settings-toggle"><input type="checkbox" name="openai_text_allow_image_input" value="1"' . (!empty($viewModel['openai_allow_image_input']) ? ' checked' : '') . (!empty($viewModel['openai_image_input_column_ready']) ? '' : ' disabled') . '> <span><strong>' . e(t('admin.openai.enable_image_input', 'Allow AI tools to send small image thumbnails to OpenAI')) . '</strong><small>' . e(!empty($viewModel['openai_image_input_column_ready']) ? t('admin.openai.enable_image_input_help', 'Default off. When enabled, photo and gallery AI actions may send small generated thumbnails, not originals, to describe visible content.') : t('admin.openai.enable_image_input_help_migration', 'Apply the latest database migration to save this optional thumbnail-consent setting.')) . '</small></span></label>';
            if ($openaiKeyHint !== '') {
                echo '<label class="account-settings-toggle account-settings-compact-toggle"><input type="checkbox" name="openai_text_clear_key" value="1"> <span><strong>' . e(t('admin.openai.clear_key', 'Clear saved API key')) . '</strong><small>' . e(t('admin.openai.clear_key_help', 'This disables OpenAI text assistance unless a new key is saved.')) . '</small></span></label>';
            }
            echo '<div class="account-settings-callout"><strong>' . e(t('admin.account.before_save')) . '</strong> ' . e(t('admin.account.before_save_help')) . '</div>';
            echo '<label>' . e(t('admin.openai.current_password', 'Current password')) . '<input name="current_password" type="password" required autocomplete="current-password"></label>';
            echo '<div class="account-settings-actions"><button type="submit">' . e(t('admin.openai.save_settings', 'Save OpenAI settings')) . '</button></div></form>';
        }
        echo '</article>';
    }

    echo '</div></section>';
    render_footer();
}

/**
 * Render the stable-release restore confirmation page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared reset presentation state.
 */
function view_render_admin_stable_reset(array $viewModel): void
{
    render_header(t('admin.reset.title'));
    echo '<section class="hero"><h1>' . e(t('admin.reset.title')) . '</h1><nav class="nav">';
    echo '<a class="button secondary" href="' . e((string) ($viewModel['dashboard_url'] ?? '')) . '">' . e(t('admin.common.back_to_dashboard')) . '</a>';
    echo '<a class="button secondary" href="' . e((string) ($viewModel['updates_url'] ?? '')) . '">' . e(t('admin.reset.open_updates')) . '</a>';
    echo '</nav></section>';
    $notice = (string) ($viewModel['notice'] ?? '');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e(t('admin.reset.failed_value', ['error' => $error])) . '</div>';
    }
    echo '<section class="panel"><h2>' . e(t('admin.reset.restore_stable_title')) . '</h2>';
    echo '<p>' . e(t('admin.reset.restore_stable_description')) . '</p>';
    echo '<p class="muted">' . e(t('admin.reset.restore_stable_help')) . '</p>';
    echo '<form method="post" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<button type="submit" class="button danger">' . e(t('admin.reset.button')) . '</button>';
    echo '</form></section>';
    render_footer();
}
