<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/upload_automation.php
 * Module Type: View
 *
 * Purpose:
 *   Renders gallery-scoped and global Admin upload-automation key management.
 *
 * Responsibilities:
 *   - Render endpoint/key creation state for one gallery
 *   - Render active key tables and revoke controls
 *   - Render the global API manager shell
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Schema inspection, token reads/writes, one-time secret consumption, URL generation,
 *     CSRF issuance, authorization, and request handling remain outside this view.
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

/** @param array<string,mixed> $viewModel Controller-prepared gallery API-key panel. */
function view_render_admin_gallery_upload_automation_panel(array $viewModel): void
{
    echo '<section class="panel admin-upload-automation-panel">';
    echo '<div class="admin-upload-automation-head"><div><p class="admin-kicker">' . e(t('upload_automation.kicker', 'Automation')) . '</p><h3>' . e(t('upload_automation.title', 'Watched-folder upload API')) . '</h3><p class="muted">' . e(t('upload_automation.help', 'Generate a gallery-scoped API key for the Windows companion app. The key can upload only into this gallery and can be revoked at any time.')) . '</p></div></div>';

    if (empty($viewModel['schema_ready'])) {
        echo '<div class="notice">' . e((string) ($viewModel['schema_message'] ?? '')) . '</div></section>';
        return;
    }

    echo '<div class="admin-upload-automation-grid"><div class="admin-upload-automation-copy">';
    echo '<label><span>' . e(t('upload_automation.endpoint', 'Upload endpoint')) . '</span><input type="text" readonly value="' . e((string) ($viewModel['endpoint'] ?? '')) . '"></label>';
    echo '<p class="muted">' . e(t('upload_automation.endpoint_help', 'Use this exact endpoint with the generated API key in the Windows uploader. The query-string front-controller form is the portable canonical API URL; clean /api/upload routing is treated only as a compatibility fallback.')) . '</p>';
    if ((string) ($viewModel['new_token'] ?? '') !== '') {
        echo '<label><span>' . e(t('upload_automation.new_key', 'New API key')) . '</span><textarea readonly rows="3" class="admin-upload-automation-key">' . e((string) $viewModel['new_token']) . '</textarea></label>';
        echo '<div class="notice">' . e(t('upload_automation.copy_now', 'Copy this API key now. For security, only its hash is stored and the raw value will not be shown again.')) . '</div>';
    }
    echo '<form method="post" action="' . e((string) ($viewModel['token_action_url'] ?? '')) . '" class="admin-upload-automation-form" data-admin-upload-automation-token-form="1">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="ajax" value="1"><input type="hidden" name="panel" value="1"><input type="hidden" name="gallery_id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '">';
    echo '<input type="hidden" name="return_tab" value="' . e((string) ($viewModel['return_tab'] ?? '')) . '"><input type="hidden" name="return_url" value="' . e((string) ($viewModel['return_url'] ?? '')) . '"><input type="hidden" name="action" value="create">';
    echo '<label><span>' . e(t('upload_automation.label', 'Label')) . '</span><input type="text" name="label" value="' . e(t('upload_automation.folder_watcher', 'Folder watcher')) . '" maxlength="190"></label>';
    echo '<button type="submit" class="button secondary">' . e(t('upload_automation.generate_key', 'Generate API key')) . '</button></form></div>';

    echo '<div class="admin-upload-automation-list"><h4>' . e(t('upload_automation.active_keys', 'Active API keys')) . '</h4>';
    $tokens = (array) ($viewModel['tokens'] ?? []);
    if ($tokens === []) {
        echo '<p class="muted">' . e(t('upload_automation.no_keys', 'No active upload automation keys exist for this gallery.')) . '</p>';
    } else {
        echo '<table><thead><tr><th>' . e(t('upload_automation.label', 'Label')) . '</th><th>' . e(t('upload_automation.created', 'Created')) . '</th><th>' . e(t('upload_automation.last_used', 'Last used')) . '</th><th>' . e(t('upload_automation.action', 'Action')) . '</th></tr></thead><tbody>';
        foreach ($tokens as $token) {
            echo '<tr><td>' . e((string) ($token['label'] ?? t('upload_automation.folder_watcher', 'Folder watcher'))) . '</td><td>' . e((string) ($token['created_at'] ?? '')) . '</td><td>' . e((string) ($token['last_used_at'] ?? t('upload_automation.never', 'Never'))) . '</td>';
            echo '<td><form method="post" action="' . e((string) ($viewModel['token_action_url'] ?? '')) . '" class="inline-admin-form" data-admin-upload-automation-token-form="1">' . (string) ($viewModel['csrf_html'] ?? '');
            echo '<input type="hidden" name="ajax" value="1"><input type="hidden" name="panel" value="1"><input type="hidden" name="gallery_id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '">';
            echo '<input type="hidden" name="return_tab" value="' . e((string) ($viewModel['return_tab'] ?? '')) . '"><input type="hidden" name="return_url" value="' . e((string) ($viewModel['return_url'] ?? '')) . '"><input type="hidden" name="action" value="revoke"><input type="hidden" name="token_id" value="' . (int) ($token['id'] ?? 0) . '">';
            echo '<button type="submit" class="secondary danger inline-admin-action">' . e(t('upload_automation.revoke', 'Revoke')) . '</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div></div></section>';
}

/** @param array<string,mixed> $viewModel Controller-prepared global API manager. */
function view_render_admin_api_manager(array $viewModel): void
{
    $title = (string) ($viewModel['title'] ?? '');
    render_header($title);
    echo '<section class="hero admin-dashboard-hero"><div><p class="admin-kicker">' . e(t('upload_automation.kicker', 'Automation')) . '</p><h1>' . e($title) . '</h1><p class="muted">' . e(t('admin.upload_automation.manager_intro', 'Review every active upload automation API key across galleries. Gallery-scoped keys stay available in each gallery editor, and this page gives you a global view for auditing and revocation.')) . '</p></div><nav class="admin-hero-actions"><a class="button secondary" href="' . e((string) ($viewModel['dashboard_url'] ?? '')) . '">' . e(t('admin.upload_automation.back_to_dashboard', 'Back to dashboard')) . '</a></nav></section>';
    if ((string) ($viewModel['notice'] ?? '') !== '') {
        echo '<div class="notice">' . e((string) $viewModel['notice']) . '</div>';
    }
    echo '<section class="panel admin-upload-automation-manager"><div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.upload_automation.active_keys_kicker', 'Active keys')) . '</p><h2>' . e(t('admin.upload_automation.active_keys_title', 'Active upload API keys')) . '</h2></div><p class="muted">' . e(t('admin.upload_automation.active_keys_help', 'Keys remain scoped to one gallery. Revoke a key here to disable upload access immediately.')) . '</p></div>';
    if (empty($viewModel['schema_ready'])) {
        echo '<div class="notice">' . e((string) ($viewModel['schema_message'] ?? '')) . '</div></section>';
        render_footer();
        return;
    }

    $tokens = (array) ($viewModel['tokens'] ?? []);
    if ($tokens === []) {
        echo '<p class="muted">' . e(t('upload_automation.no_keys', 'No active upload automation keys exist for this gallery.')) . '</p>';
    } else {
        echo '<table class="admin-upload-automation-table"><thead><tr><th>' . e(t('upload_automation.gallery', 'Gallery')) . '</th><th>' . e(t('upload_automation.label', 'Label')) . '</th><th>' . e(t('upload_automation.created', 'Created')) . '</th><th>' . e(t('upload_automation.last_used', 'Last used')) . '</th><th>' . e(t('upload_automation.action', 'Action')) . '</th></tr></thead><tbody>';
        foreach ($tokens as $token) {
            echo '<tr><td><a href="' . e((string) ($token['gallery_url'] ?? '')) . '">' . e((string) ($token['gallery_label'] ?? '')) . '</a></td>';
            echo '<td>' . e((string) ($token['label'] ?? t('upload_automation.folder_watcher', 'Folder watcher'))) . '</td><td>' . e((string) ($token['created_at'] ?? '')) . '</td><td>' . e((string) ($token['last_used_at'] ?? t('upload_automation.never', 'Never'))) . '</td>';
            echo '<td><form method="post" action="' . e((string) ($viewModel['token_action_url'] ?? '')) . '" class="inline-admin-form" data-admin-upload-automation-token-form="1">' . (string) ($viewModel['csrf_html'] ?? '');
            echo '<input type="hidden" name="gallery_id" value="' . (int) ($token['gallery_id'] ?? 0) . '"><input type="hidden" name="return_context" value="api_manager"><input type="hidden" name="return_url" value="' . e((string) ($viewModel['return_url'] ?? '')) . '"><input type="hidden" name="action" value="revoke"><input type="hidden" name="token_id" value="' . (int) ($token['id'] ?? 0) . '">';
            echo '<button type="submit" class="secondary danger inline-admin-action">' . e(t('upload_automation.revoke', 'Revoke')) . '</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</section>';
    render_footer();
}
