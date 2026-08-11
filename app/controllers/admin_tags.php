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
 *   2026-05-12
 */

declare(strict_types=1);

namespace Gallery\Controllers;

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
                    admin_tags_json_response(['ok' => false, 'error' => admin_tags_error_message((string) ($result['error'] ?? 'delete_failed'))], 422);
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
                admin_tags_json_response([
                    'ok' => true,
                    'message' => t('admin.tags.deleted', 'Tag deleted.'),
                    'return_url' => url_for('home'),
                ]);
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
                admin_tags_json_response(['ok' => false, 'error' => admin_tags_error_message((string) ($result['error'] ?? 'save_failed'))], 422);
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
            admin_tags_json_response([
                'ok' => true,
                'message' => t('admin.tags.saved', 'Tag saved.'),
                'tag_id' => $tagId,
                'tag_name' => (string) ($updatedTag['name'] ?? ''),
                'tag_slug' => (string) ($updatedTag['slug'] ?? ''),
                'edit_url' => url_for('admin_tags', ['id' => $tagId, 'panel' => 1]),
                'public_url' => url_for('tag', ['slug' => (string) ($updatedTag['slug'] ?? '')]),
            ]);
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

    if (isset($_GET['panel'])) {
        echo '<div class="admin-side-panel-stack admin-tags-panel-stack" data-admin-tag-edit-panel>';
        if ($notice) {
            echo '<p class="success">' . e($notice) . '</p>';
        }
        if ($error) {
            echo '<p class="error">' . e($error) . '</p>';
        }
        if (!$selectedTag) {
            echo '<div class="admin-side-panel-copy"><p class="admin-kicker">' . e(t('admin.tags.kicker', 'Metadata')) . '</p><h2>' . e(t('admin.tags.no_selection', 'No tag selected')) . '</h2><p class="muted">' . e(t('admin.tags.no_selection_help', 'Select a tag from the list to edit it.')) . '</p></div>';
        } else {
            render_admin_tag_form($selectedTag, $sortMode);
        }
        echo '</div>';
        return;
    }

    render_header(t('admin.tags.title', 'Edit tags'));
    echo '<section class="panel admin-tags-hero">';
    echo '<p class="admin-kicker">' . e(t('admin.tags.kicker', 'Metadata')) . '</p>';
    echo '<h1>' . e(t('admin.tags.title', 'Edit tags')) . '</h1>';
    echo '<p class="muted">' . e(t('admin.tags.description', 'Rename reusable tags, adjust their clean URL slug, and add public text for tag landing pages. Tags are always stored as safe lowercase values.')) . '</p>';
    echo '<nav class="nav"><a class="button secondary" href="' . e(admin_settings_url('content')) . '">' . e(t('admin.settings.open_centralized', 'Open centralized settings')) . '</a></nav>';
    echo '</section>';

    if ($notice) {
        echo '<p class="success">' . e($notice) . '</p>';
    }
    if ($error) {
        echo '<p class="error">' . e($error) . '</p>';
    }

    echo '<section class="admin-tags-layout">';
    echo '<div class="panel admin-tags-list-panel">';
    echo '<div class="admin-tags-list-head">';
    echo '<h2>' . e(t('admin.tags.existing_tags', 'Existing tags')) . '</h2>';
    $sortUrl = url_for('admin_tags', ['id' => $selectedId > 0 ? $selectedId : null, 'sort' => $sortMode]);
    echo '<div class="admin-tags-sort-form">';
    echo '<label><span>' . e(t('admin.tags.sort_label', 'Sort')) . '</span><select name="sort" data-admin-tags-sort data-admin-tags-sort-url="' . e(url_for('admin_tags', ['id' => $selectedId > 0 ? $selectedId : null, 'sort' => '__SORT__'])) . '" onchange="window.location.href=this.dataset.adminTagsSortUrl.replace(\'__SORT__\', encodeURIComponent(this.value));">';
    echo '<option value="usage"' . ($sortMode === 'usage' ? ' selected' : '') . '>' . e(t('admin.tags.sort_usage', 'Most used')) . '</option>';
    echo '<option value="name"' . ($sortMode === 'name' ? ' selected' : '') . '>' . e(t('admin.tags.sort_name', 'Alphabetical')) . '</option>';
    echo '</select></label>';
    echo '<noscript><a class="button secondary" href="' . e($sortUrl) . '">' . e(t('admin.tags.sort_apply', 'Apply')) . '</a></noscript>';
    echo '</div>';
    echo '</div>';
    if (!$tags) {
        echo '<p class="muted">' . e(t('admin.tags.empty', 'No tags exist yet. Add tags from a gallery or image editor first.')) . '</p>';
    } else {
        echo '<div class="admin-tags-list" role="list">';
        foreach ($tags as $tag) {
            // Variable $active stores this steps working value.
            $active = (int) $tag['id'] === $selectedId;
            // Variable $usage stores this steps working value.
            $usage = (int) $tag['gallery_count'] + (int) $tag['image_count'];
            echo '<a class="admin-tag-row' . ($active ? ' is-active' : '') . '" role="listitem" href="' . e(url_for('admin_tags', ['id' => (int) $tag['id'], 'sort' => $sortMode])) . '">';
            echo '<span><strong>' . e((string) $tag['name']) . '</strong><small>/' . e((string) $tag['slug']) . '</small></span>';
            echo '<em>' . e(t('admin.tags.usage_count', '{count} uses', ['count' => $usage])) . '</em>';
            echo '</a>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="panel admin-tags-edit-panel">';
    if (!$selectedTag) {
        echo '<h2>' . e(t('admin.tags.no_selection', 'No tag selected')) . '</h2>';
        echo '<p class="muted">' . e(t('admin.tags.no_selection_help', 'Select a tag from the list to edit it.')) . '</p>';
    } else {
        render_admin_tag_form($selectedTag, $sortMode);
        echo '<section class="admin-tags-usage-panel">';
        echo '<h3>' . e(t('admin.tags.used_where', 'Used in')) . '</h3>';
        if (!$selectedTagUsage['galleries'] && !$selectedTagUsage['images']) {
            echo '<p class="muted">' . e(t('admin.tags.used_where_empty', 'This tag is not attached to any galleries or images yet.')) . '</p>';
        } else {
            if ($selectedTagUsage['galleries']) {
                echo '<div class="admin-tags-usage-group">';
                echo '<h4>' . e(t('admin.tags.used_in_galleries', 'Galleries')) . '</h4>';
                echo '<ul class="admin-tags-usage-list">';
                foreach ($selectedTagUsage['galleries'] as $gallery) {
                    echo '<li><a href="' . e((string) $gallery['public_url']) . '" target="_blank" rel="noopener">';
                    echo e((string) $gallery['title']);
                    echo '</a></li>';
                }
                echo '</ul>';
                echo '</div>';
            }
            if ($selectedTagUsage['images']) {
                echo '<div class="admin-tags-usage-group">';
                echo '<h4>' . e(t('admin.tags.used_in_images', 'Images')) . '</h4>';
                echo '<ul class="admin-tags-usage-list">';
                foreach ($selectedTagUsage['images'] as $image) {
                    echo '<li><a href="' . e((string) $image['edit_url']) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="' . e(t('gallery.photo_editor', 'Photo editor')) . '" data-admin-side-panel-title="' . e(t('admin.gallery_editor.edit_photo', 'Edit photo')) . '" data-gallery-side-panel-url="' . e(url_for('admin_edit_image', ['id' => (int) $image['id'], 'panel' => 1])) . '">';
                    echo e((string) $image['relative_path']);
                    echo '</a><small>' . e((string) $image['gallery_title']) . '</small></li>';
                }
                echo '</ul>';
                echo '</div>';
            }
        }
        echo '</section>';
    }
    echo '</div>';
    echo '</section>';
    render_footer();
}

/**
 * Render the selected tag edit form.
 *
 * @param array $tag Tag value.
 * @param string $sortMode Sort mode value.
 */
function render_admin_tag_form(array $tag, string $sortMode = 'usage'): void
{
    // Variable $description stores this steps working value.
    $description = tag_description_schema_ready() ? (string) ($tag['description'] ?? '') : '';
    echo '<h2>' . e(t('admin.tags.edit_heading', 'Edit tag')) . '</h2>';
    echo '<form method="post" action="' . e(url_for('admin_tags', ['id' => (int) $tag['id']])) . '" class="admin-tags-form">';
    echo csrf_field();
    echo '<input type="hidden" name="tag_id" value="' . (int) $tag['id'] . '">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<input type="hidden" name="sort" value="' . e(in_array($sortMode, ['name', 'usage'], true) ? $sortMode : 'usage') . '">';
    echo '<label>' . e(t('admin.tags.name', 'Tag name')) . '<input name="name" value="' . e((string) $tag['name']) . '" required maxlength="100" autocomplete="off"><span class="muted">' . e(t('admin.tags.name_help', 'Use lowercase letters, numbers, and hyphens only. Other input is normalized automatically when saved.')) . '</span></label>';
    echo '<label>' . e(t('admin.tags.slug', 'URL slug')) . '<input name="slug" value="' . e((string) $tag['slug']) . '" required maxlength="120" autocomplete="off"><span class="muted">' . e(t('admin.tags.slug_help', 'This controls the public tag URL. Keep it short and stable when possible.')) . '</span></label>';
    echo '<label>' . e(t('admin.tags.public_description', 'Public description')) . '<textarea name="description" rows="6">' . e($description) . '</textarea><span class="muted">' . e(t('admin.tags.description_help', 'Optional text shown on the public tag landing page.')) . '</span></label>';
    echo '<div class="bulk-row">';
    echo '<button type="submit">' . e(t('admin.tags.save', 'Save tag')) . '</button>';
    echo '<a class="button secondary" href="' . e(url_for('tag', ['slug' => (string) $tag['slug']])) . '">' . e(t('admin.tags.view_public', 'View public tag')) . '</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags']) . '#admin-theme-tab-appearance') . '">' . e(t('admin.tags.configure_display', 'Configure tag display')) . '</a>';
    echo '</div>';
    echo '</form>';
}


/**
 * Return whether the current admin tag request expects JSON.
 *
 * @return bool True when the condition matches.
 */
function admin_tags_request_wants_json(): bool
{
    return isset($_POST['ajax']) || isset($_POST['panel']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
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
