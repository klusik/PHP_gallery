<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_tags.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for admin tag management.
 *
 * Responsibilities:
 *   - List existing reusable tags with usage counts
 *   - Allow admins to rename a tag, edit its slug, and maintain public text
 *   - Keep tag values safe, lowercase, and compatible with clean public URLs
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
 *   2026-09-02
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_postcondition;
use function Gallery\Core\admin_mutation_public_tag_context;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_wants_json;
use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_settings_url;
use function Gallery\Services\admin_tag_rows;
use function Gallery\Services\admin_tag_usage_rows;
use function Gallery\Services\app_setting;
use function Gallery\Services\delete_tag_by_id;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\find_tag_by_id;
use function Gallery\Services\normalize_existing_tags;
use function Gallery\Services\normalize_gallery_sidecar_tags_recursively;
use function Gallery\Services\set_app_setting;
use function Gallery\Services\t;
use function Gallery\Services\tag_description_schema_ready;
use function Gallery\Services\update_tag_metadata;
use function Gallery\Services\admin_log_event;

/**
 * Render and process the admin tag editor.
 */
function cms_admin_tags(): void
{
    require_admin();

    // Existing installs may contain legacy mixed-case tag rows or sidecar tag
    // text. This self-heal keeps the admin tag page authoritative after update.
    $normalizedRows = normalize_existing_tags();
    // Variable $normalizationKey stores this steps working value.
    $normalizationKey = 'tags_safe_lowercase_sidecars_v1';
    // Variable $normalizedSidecars stores this steps working value.
    $normalizedSidecars = app_setting($normalizationKey, '') === 'done' ? 0 : normalize_gallery_sidecar_tags_recursively();
    if (app_setting($normalizationKey, '') !== 'done') {
        set_app_setting($normalizationKey, 'done');
    }
    if ($normalizedRows > 0 || $normalizedSidecars > 0) {
        admin_log_event('info', 'tags.normalized', 'Admin tag page normalized existing tags.', [
            'database_rows' => $normalizedRows,
            'sidecar_files' => $normalizedSidecars,
        ]);
    }

    // Variable $selectedId stores this steps working value.
    $selectedId = max(0, (int) ($_GET['id'] ?? 0));
    // Variable $sortMode stores this steps working value.
    $sortMode = strtolower((string) ($_GET['sort'] ?? 'usage'));
    if (!in_array($sortMode, ['name', 'usage'], true)) {
        $sortMode = 'usage';
    }
    // Variable $sortDirection stores this steps working value.
    $sortDirection = $sortMode === 'name' ? 'asc' : 'desc';
    // Variable $notice stores this steps working value.
    $notice = flash_message('admin_tags_notice');
    // Variable $error stores this steps working value.
    $error = flash_message('admin_tags_error');

    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $tagId stores this steps working value.
        $tagId = max(0, (int) ($_POST['tag_id'] ?? 0));
        // Variable $action stores this steps working value.
        $action = (string) ($_POST['action'] ?? 'save');
        // Variable $postedSort stores this steps working value.
        $postedSort = strtolower((string) ($_POST['sort'] ?? $sortMode));
        if (!in_array($postedSort, ['name', 'usage'], true)) {
            $postedSort = $sortMode;
        }
        // Variable $wantsJson stores this steps working value.
        $wantsJson = admin_tags_request_wants_json();

        if ($action === 'delete') {
            // Variable $deletedTag stores this steps working value.
            $deletedTag = find_tag_by_id($tagId);
            // Variable $result stores this steps working value.
            $result = delete_tag_by_id($tagId);
            if (!($result['ok'] ?? false)) {
                if ($wantsJson) {
                    $message = admin_tags_error_message((string) ($result['error'] ?? 'delete_failed'));
                    admin_tags_json_response(admin_mutation_error_envelope(
                        $message,
                        'tag_delete_failed',
                        admin_mutation_descriptor('tag.delete', 'tag', 'delete', [$tagId])
                    ), 422);
                }
                flash_message('admin_tags_error', admin_tags_error_message((string) ($result['error'] ?? 'delete_failed')));
                redirect_to(url_for('admin_tags', ['id' => $tagId, 'sort' => $postedSort]));
            }
            admin_log_event('info', 'tags.deleted', 'Admin deleted tag.', [
                'tag_id' => $tagId,
                'name' => (string) ($deletedTag['name'] ?? ''),
                'slug' => (string) ($deletedTag['slug'] ?? ''),
            ]);
            if ($wantsJson) {
                $returnUrl = url_for('home');
                $payload = admin_mutation_success_envelope(
                    t('admin.tags.deleted', 'Tag deleted.'),
                    admin_mutation_descriptor('tag.delete', 'tag', 'delete', [$tagId]),
                    admin_mutation_panel_metadata('tag-edit', url_for('admin_tags', ['panel' => 1]), true),
                    [],
                    ['redirect_url' => $returnUrl]
                );
                admin_tags_json_response(array_merge($payload, ['return_url' => $returnUrl]));
            }
            flash_message('admin_tags_notice', t('admin.tags.deleted', 'Tag deleted.'));
            redirect_to(admin_tags_safe_return_url((string) ($_POST['return_url'] ?? url_for('admin_tags', ['sort' => $postedSort]))));
        }

        // Variable $result stores this steps working value.
        $result = update_tag_metadata(
            $tagId,
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['slug'] ?? ''),
            (string) ($_POST['description'] ?? '')
        );
        if (!($result['ok'] ?? false)) {
            if ($wantsJson) {
                $message = admin_tags_error_message((string) ($result['error'] ?? 'save_failed'));
                admin_tags_json_response(admin_mutation_error_envelope(
                    $message,
                    'tag_update_failed',
                    admin_mutation_descriptor('tag.update', 'tag', 'update', [$tagId])
                ), 422);
            }
            flash_message('admin_tags_error', admin_tags_error_message((string) ($result['error'] ?? 'save_failed')));
            redirect_to(url_for('admin_tags', ['id' => $tagId, 'sort' => $postedSort]));
        }
        // Variable $updatedTag stores this steps working value.
        $updatedTag = (array) ($result['tag'] ?? []);
        admin_log_event('info', 'tags.updated', 'Admin updated tag metadata.', [
            'tag_id' => $tagId,
            'name' => (string) ($updatedTag['name'] ?? ''),
            'slug' => (string) ($updatedTag['slug'] ?? ''),
        ]);
        if ($wantsJson) {
            // $editUrl refreshes the mounted editor without changing the browser URL.
            $editUrl = url_for('admin_tags', ['id' => $tagId, 'panel' => 1]);
            // $publicUrl is canonical after a slug rename only while public tag browsing is enabled.
            $publicTagBrowsingEnabled = feature_capability_effective_enabled('public_tag_browsing');
            $publicUrl = $publicTagBrowsingEnabled ? url_for('tag', ['slug' => (string) ($updatedTag['slug'] ?? '')]) : '';
            $payload = admin_mutation_success_envelope(
                t('admin.tags.saved', 'Tag saved.'),
                admin_mutation_descriptor('tag.update', 'tag', 'update', [$tagId]),
                admin_mutation_panel_metadata('tag-edit', $editUrl, true),
                $publicTagBrowsingEnabled ? [
                    admin_mutation_public_tag_context(
                        $tagId,
                        $publicUrl,
                        admin_mutation_postcondition('tag_identity', ['tag_id' => $tagId]),
                        'canonical'
                    ),
                ] : [],
                ['redirect_url' => url_for('admin_tags', ['id' => $tagId, 'sort' => $postedSort])]
            );
            admin_tags_json_response(array_merge($payload, [
                'tag_id' => $tagId,
                'tag_name' => (string) ($updatedTag['name'] ?? ''),
                'tag_slug' => (string) ($updatedTag['slug'] ?? ''),
                'edit_url' => $editUrl,
                'public_url' => $publicUrl !== '' ? $publicUrl : null,
            ]));
        }
        flash_message('admin_tags_notice', t('admin.tags.saved', 'Tag saved.'));
        redirect_to(url_for('admin_tags', ['id' => $tagId, 'sort' => $postedSort]));
    }

    // Variable $tags stores this steps working value.
    $tags = admin_tag_rows($sortMode, $sortDirection);
    if ($selectedId <= 0 && $tags) {
        $selectedId = (int) $tags[0]['id'];
    }
    // Variable $selectedTag stores this steps working value.
    $selectedTag = $selectedId > 0 ? find_tag_by_id($selectedId) : null;
    // Variable $selectedTagUsage stores this steps working value.
    $selectedTagUsage = $selectedTag ? admin_tag_usage_rows((int) $selectedTag['id']) : ['galleries' => [], 'images' => []];

    foreach ($tags as &$tagRow) {
        $tagRow['edit_url'] = url_for('admin_tags', ['id' => (int) $tagRow['id'], 'sort' => $sortMode]);
    }
    unset($tagRow);
    foreach ((array) ($selectedTagUsage['images'] ?? []) as $index => $imageRow) {
        $selectedTagUsage['images'][$index]['side_panel_url'] = url_for('admin_edit_image', ['id' => (int) $imageRow['id'], 'panel' => 1]);
    }

    $formViewModel = $selectedTag ? admin_tag_form_view_model($selectedTag, $sortMode) : [];
    \Gallery\Views\view_render_admin_tags([
        'panel' => isset($_GET['panel']),
        'selected_id' => $selectedId,
        'sort_mode' => $sortMode,
        'notice' => (string) ($notice ?? ''),
        'error' => (string) ($error ?? ''),
        'selected_tag' => $selectedTag,
        'tags' => $tags,
        'usage' => $selectedTagUsage,
        'form' => $formViewModel,
        'settings_url' => admin_settings_url('content'),
        'sort_url' => url_for('admin_tags', ['id' => $selectedId > 0 ? $selectedId : null, 'sort' => $sortMode]),
        'sort_template_url' => url_for('admin_tags', ['id' => $selectedId > 0 ? $selectedId : null, 'sort' => '__SORT__']),
    ]);
}

/**
 * Render the selected tag edit form.
 *
 * @param array $tag Tag value.
 * @param string $sortMode Sort mode value.
 */
function render_admin_tag_form(array $tag, string $sortMode = 'usage'): void
{
    \Gallery\Views\view_render_admin_tag_form(admin_tag_form_view_model($tag, $sortMode));
}

/**
 * Prepare presentation state for the selected tag form.
 *
 * @param array $tag Tag value.
 * @param string $sortMode Sort mode value.
 * @return array<string,mixed> View model.
 */
function admin_tag_form_view_model(array $tag, string $sortMode = 'usage'): array
{
    $description = tag_description_schema_ready() ? (string) ($tag['description'] ?? '') : '';
    $publicEnabled = feature_capability_effective_enabled('public_tag_browsing');
    return [
        'tag' => $tag,
        'description' => $description,
        'sort_mode' => in_array($sortMode, ['name', 'usage'], true) ? $sortMode : 'usage',
        'action_url' => url_for('admin_tags', ['id' => (int) $tag['id']]),
        'csrf_html' => csrf_field(),
        'public_url' => $publicEnabled ? url_for('tag', ['slug' => (string) $tag['slug']]) : '',
        'theme_url' => url_for('admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags']) . '#admin-theme-tab-appearance',
    ];
}



/**
 * Return whether the current admin tag request expects JSON.
 *
 * @return bool True when the condition matches.
 */
function admin_tags_request_wants_json(): bool
{
    return admin_wants_json();
}

/**
 * Send a JSON response for the admin tag editor and stop execution.
 *
 * @param array $payload Payload value.
 * @param int $status Status value.
 */
function admin_tags_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Keep delete fallbacks on same-origin relative URLs only.
 *
 * @param string $url URL used by this workflow.
 * @return string Text result for the caller.
 */
function admin_tags_safe_return_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return url_for('admin_tags');
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $url;
    }
    return url_for('admin_tags');
}

/**
 * Return localized validation errors for the admin tag form.
 *
 * @param string $code Code value.
 * @return string Text result for the caller.
 */
function admin_tags_error_message(string $code): string
{
    return match ($code) {
        'not_found' => t('admin.tags.error_not_found', 'The selected tag no longer exists.'),
        'invalid_name' => t('admin.tags.error_invalid_name', 'Enter a safe tag name using letters, numbers, or hyphens.'),
        'invalid_slug' => t('admin.tags.error_invalid_slug', 'Enter a safe URL slug using letters, numbers, or hyphens.'),
        'slug_taken' => t('admin.tags.error_slug_taken', 'That tag slug is already used by another tag.'),
        'delete_failed' => t('admin.tags.error_delete_failed', 'Tag could not be deleted.'),
        default => t('admin.tags.error_save_failed', 'Tag could not be saved.'),
    };
}
