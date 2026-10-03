<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/mobile_webdav.php
 * Module Type: View
 *
 * Purpose:
 *   Renders mobile WebDAV administration and protocol response bodies.
 *
 * Responsibilities:
 *   - Render mobile upload setup, one-time credentials, and token inventory
 *   - Serialize the minimal PROPFIND XML body after the controller sets HTTP status and headers
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Authentication, token lifecycle, schema policy, upload ingestion, URLs, and CSRF issuance remain outside this view.
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
 * Render the existing standalone mobile connection management page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared admin page state.
 * @return void Emits the page and authorized one-time connection details.
 */
function view_render_admin_mobile_uploads(array $viewModel): void
{
    render_header(t('mobile_webdav.title', 'Mobile uploads'));
    echo '<section class="hero"><h1>' . e(t('mobile_webdav.title', 'Mobile uploads')) . '</h1><p>' . e(t('mobile_webdav.intro', 'Create WebDAV-compatible upload connections for mobile photo-transfer apps such as PhotoSync.')) . '</p></section>';
    if ((string) ($viewModel['notice'] ?? '') !== '') {
        echo '<div class="notice">' . e((string) $viewModel['notice']) . '</div>';
    }
    if (is_array($viewModel['created'] ?? null)) {
        view_render_mobile_webdav_created_credentials((array) $viewModel['created']);
    }
    if (empty($viewModel['ready'])) {
        echo '<section class="panel"><h2>' . e((string) ($viewModel['unavailable_title'] ?? '')) . '</h2><p class="muted">' . e((string) ($viewModel['unavailable_help'] ?? '')) . '</p></section>';
        render_footer();
        return;
    }
    view_render_mobile_webdav_create_form((array) ($viewModel['create_form'] ?? []));
    view_render_mobile_webdav_token_list((array) ($viewModel['tokens'] ?? []), (array) ($viewModel['token_list'] ?? []));
    render_footer();
}

/**
 * Render compact connection management inside the existing upload settings section.
 *
 * @param array<string,mixed> $viewModel Controller-prepared capability, credential and form state.
 * @return void Emits one replaceable fragment with delegated Settings forms.
 */
function view_render_admin_mobile_uploads_settings(array $viewModel): void
{
    echo '<section class="admin-settings-mobile" data-admin-settings-mobile data-failed-label="' . e(t('mobile_webdav.notice_failed_safe', 'Could not update the mobile connection. Please try again.')) . '"><h3>' . e(t('mobile_webdav.title', 'Mobile uploads')) . '</h3>';
    echo '<p class="muted">' . e(t('mobile_webdav.intro', 'Create WebDAV-compatible upload connections for mobile photo-transfer apps such as PhotoSync.')) . '</p>';
    echo '<p class="notice is-error" data-admin-settings-mobile-feedback role="status" hidden></p>';
    if ((string) ($viewModel['notice'] ?? '') !== '') {
        echo '<div class="notice" role="status">' . e((string) $viewModel['notice']) . '</div>';
    }
    if (is_array($viewModel['created'] ?? null)) {
        view_render_mobile_webdav_created_credentials((array) $viewModel['created']);
    }
    if (empty($viewModel['ready'])) {
        echo '<p><strong>' . e((string) ($viewModel['unavailable_title'] ?? '')) . '</strong></p><p class="muted">' . e((string) ($viewModel['unavailable_help'] ?? '')) . '</p></section>';
        return;
    }
    $createForm = (array) ($viewModel['create_form'] ?? []);
    echo '<form method="post" action="' . e((string) ($createForm['action_url'] ?? '')) . '" class="form-grid admin-settings-mobile-create" data-admin-settings-mobile-form>' . (string) ($createForm['csrf_html'] ?? '');
    echo '<input type="hidden" name="return_context" value="settings_uploads"><input type="hidden" name="action" value="create">';
    echo '<label>' . e(t('mobile_webdav.label', 'Label')) . '<input type="text" name="label" value="PhotoSync iPhone" maxlength="190"></label>';
    echo '<label>' . e(t('admin.upload.gallery', 'Gallery')) . '<select name="gallery_id" required>' . (string) ($createForm['gallery_options_html'] ?? '') . '</select></label>';
    echo '<button type="submit">' . e(t('mobile_webdav.create_button', 'Create connection')) . '</button></form>';
    echo '<details class="admin-settings-mobile-help"><summary>' . e(t('mobile_webdav.connection_help', 'Connection help')) . '</summary><p class="muted">' . e(t('mobile_webdav.photosync_hint', 'In the mobile app, use WebDAV as the target and enable HEIC to JPEG conversion before transfer when available.')) . '</p><p class="muted">' . e(t('mobile_webdav.password_once', 'Copy this password now. It is stored hashed and cannot be shown again.')) . '</p></details>';
    $tokens = (array) ($viewModel['tokens'] ?? []);
    if (!$tokens) {
        echo '<p class="muted">' . e(t('mobile_webdav.none', 'No mobile upload connections exist yet.')) . '</p></section>';
        return;
    }
    $tokenForm = (array) ($viewModel['token_list'] ?? []);
    echo '<div class="admin-settings-mobile-connections">';
    foreach ($tokens as $token) {
        echo '<div class="admin-settings-mobile-connection"><div><strong>' . e((string) ($token['label'] ?? '')) . '</strong><span class="muted">' . e((string) ($token['gallery_title'] ?? '')) . '</span></div>';
        echo '<code class="admin-settings-mobile-url">' . e((string) ($token['absolute_url'] ?? '')) . '</code>';
        echo '<span class="muted">' . e(t('mobile_webdav.last_used', 'Last used')) . ': ' . e((string) ($token['last_used_at'] ?? '')) . '</span>';
        echo '<form method="post" action="' . e((string) ($tokenForm['action_url'] ?? '')) . '" data-admin-settings-mobile-form>' . (string) ($tokenForm['csrf_html'] ?? '');
        echo '<input type="hidden" name="return_context" value="settings_uploads"><input type="hidden" name="action" value="delete"><input type="hidden" name="token_id" value="' . (int) ($token['id'] ?? 0) . '"><button type="submit" class="secondary danger">' . e(t('admin.common.delete', 'Delete')) . '</button></form></div>';
    }
    echo '</div></section>';
}

/** @param array<string,mixed> $created Newly created token credentials. */
function view_render_mobile_webdav_created_credentials(array $created): void
{
    echo '<section class="panel"><h2>' . e(t('mobile_webdav.created_title', 'New connection details')) . '</h2><p class="notice">' . e(t('mobile_webdav.password_once', 'Copy this password now. It is stored hashed and cannot be shown again.')) . '</p><dl class="admin-definition-list">';
    echo '<dt>' . e(t('mobile_webdav.server_url', 'Server URL')) . '</dt><dd><code>' . e((string) ($created['url'] ?? '')) . '</code></dd>';
    echo '<dt>' . e(t('mobile_webdav.username', 'Username')) . '</dt><dd><code>' . e((string) ($created['username'] ?? '')) . '</code></dd>';
    echo '<dt>' . e(t('mobile_webdav.password', 'Password')) . '</dt><dd><code>' . e((string) ($created['password'] ?? '')) . '</code></dd></dl></section>';
}

/** @param array<string,mixed> $viewModel Controller-prepared create-form state. */
function view_render_mobile_webdav_create_form(array $viewModel): void
{
    echo '<section class="panel"><h2>' . e(t('mobile_webdav.create_title', 'Create mobile upload connection')) . '</h2><form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="action" value="create"><label>' . e(t('mobile_webdav.label', 'Label')) . '<input type="text" name="label" value="PhotoSync iPhone" maxlength="190"></label>';
    echo '<label>' . e(t('admin.upload.gallery', 'Gallery')) . '<select name="gallery_id" required>' . (string) ($viewModel['gallery_options_html'] ?? '') . '</select></label><button type="submit">' . e(t('mobile_webdav.create_button', 'Create connection')) . '</button></form>';
    echo '<p class="muted">' . e(t('mobile_webdav.photosync_hint', 'In the mobile app, use WebDAV as the target and enable HEIC to JPEG conversion before transfer when available.')) . '</p></section>';
}

/**
 * @param array<int,array<string,mixed>> $tokens Existing tokens enriched with absolute URLs.
 * @param array<string,mixed> $viewModel Shared token-list form state.
 */
function view_render_mobile_webdav_token_list(array $tokens, array $viewModel): void
{
    echo '<section class="panel"><h2>' . e(t('mobile_webdav.existing_title', 'Existing connections')) . '</h2>';
    if (!$tokens) {
        echo '<p class="muted">' . e(t('mobile_webdav.none', 'No mobile upload connections exist yet.')) . '</p></section>';
        return;
    }
    echo '<table><thead><tr><th>' . e(t('mobile_webdav.label', 'Label')) . '</th><th>' . e(t('admin.upload.gallery', 'Gallery')) . '</th><th>' . e(t('mobile_webdav.server_url', 'Server URL')) . '</th><th>' . e(t('mobile_webdav.last_used', 'Last used')) . '</th><th>' . e(t('admin.common.actions', 'Actions')) . '</th></tr></thead><tbody>';
    foreach ($tokens as $token) {
        echo '<tr><td>' . e((string) ($token['label'] ?? '')) . '</td><td>' . e((string) ($token['gallery_title'] ?? '')) . '</td><td><code>' . e((string) ($token['absolute_url'] ?? '')) . '</code></td><td>' . e((string) ($token['last_used_at'] ?? '')) . '</td><td>';
        echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" onsubmit="return confirm(' . e((string) ($viewModel['confirm_json'] ?? '')) . ');">' . (string) ($viewModel['csrf_html'] ?? '');
        echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="token_id" value="' . (int) ($token['id'] ?? 0) . '"><button type="submit" class="secondary danger">' . e(t('admin.common.delete', 'Delete')) . '</button></form></td></tr>';
    }
    echo '</tbody></table></section>';
}

/** Render the minimal WebDAV PROPFIND XML body. */
function view_render_mobile_webdav_propfind_xml(string $href): void
{
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<d:multistatus xmlns:d="DAV:"><d:response><d:href>' . e($href) . '</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
}
