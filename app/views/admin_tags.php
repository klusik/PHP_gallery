<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_tags.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the admin tag management surface from controller-prepared state.
 *
 * Responsibilities:
 *   - Render the tag list, selection state, usage references, and editor form
 *   - Render side-panel and full-page variants without owning request state
 *   - Keep tag-management markup out of the controller
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Tag persistence, normalization, feature policy, URL generation, and CSRF issuance stay in the controller.
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

/** @param array<string,mixed> $viewModel Controller-prepared tag editor state. */
function view_render_admin_tags(array $viewModel): void
{
    $selectedId = (int) ($viewModel['selected_id'] ?? 0);
    $sortMode = (string) ($viewModel['sort_mode'] ?? 'usage');
    $notice = (string) ($viewModel['notice'] ?? '');
    $error = (string) ($viewModel['error'] ?? '');
    $selectedTag = is_array($viewModel['selected_tag'] ?? null) ? $viewModel['selected_tag'] : null;

    if (!empty($viewModel['panel'])) {
        echo '<div class="admin-side-panel-stack admin-tags-panel-stack" data-admin-tag-edit-panel>';
        if ($notice !== '') {
            echo '<p class="success">' . e($notice) . '</p>';
        }
        if ($error !== '') {
            echo '<p class="error">' . e($error) . '</p>';
        }
        if (!$selectedTag) {
            echo '<div class="admin-side-panel-copy"><p class="admin-kicker">' . e(t('admin.tags.kicker', 'Metadata')) . '</p><h2>' . e(t('admin.tags.no_selection', 'No tag selected')) . '</h2><p class="muted">' . e(t('admin.tags.no_selection_help', 'Select a tag ' . 'from the list to edit it.')) . '</p></div>';
        } else {
            view_render_admin_tag_form((array) ($viewModel['form'] ?? []));
        }
        echo '</div>';
        return;
    }

    render_header(t('admin.tags.title', 'Edit tags'));
    echo '<section class="panel admin-tags-hero">';
    echo '<p class="admin-kicker">' . e(t('admin.tags.kicker', 'Metadata')) . '</p>';
    echo '<h1>' . e(t('admin.tags.title', 'Edit tags')) . '</h1>';
    echo '<p class="muted">' . e(t('admin.tags.description', 'Rename reusable tags, adjust their clean URL slug, and add public text for tag landing pages. Tags are always stored as safe lowercase values.')) . '</p>';
    echo '<nav class="nav"><a class="button secondary" href="' . e((string) ($viewModel['settings_url'] ?? '')) . '">' . e(t('admin.settings.open_centralized', 'Open centralized settings')) . '</a></nav>';
    echo '</section>';

    if ($notice !== '') {
        echo '<p class="success">' . e($notice) . '</p>';
    }
    if ($error !== '') {
        echo '<p class="error">' . e($error) . '</p>';
    }

    echo '<section class="admin-tags-layout">';
    echo '<div class="panel admin-tags-list-panel">';
    echo '<div class="admin-tags-list-head">';
    echo '<h2>' . e(t('admin.tags.existing_tags', 'Existing tags')) . '</h2>';
    echo '<div class="admin-tags-sort-form">';
    echo '<label><span>' . e(t('admin.tags.sort_label', 'Sort')) . '</span><select name="sort" data-admin-tags-sort data-admin-tags-sort-url="' . e((string) ($viewModel['sort_template_url'] ?? '')) . '" onchange="window.location.href=this.dataset.adminTagsSortUrl.replace(\'__SORT__\', encodeURIComponent(this.value));">';
    echo '<option value="usage"' . ($sortMode === 'usage' ? ' selected' : '') . '>' . e(t('admin.tags.sort_usage', 'Most used')) . '</option>';
    echo '<option value="name"' . ($sortMode === 'name' ? ' selected' : '') . '>' . e(t('admin.tags.sort_name', 'Alphabetical')) . '</option>';
    echo '</select></label>';
    echo '<noscript><a class="button secondary" href="' . e((string) ($viewModel['sort_url'] ?? '')) . '">' . e(t('admin.tags.sort_apply', 'Apply')) . '</a></noscript>';
    echo '</div></div>';

    $tags = (array) ($viewModel['tags'] ?? []);
    if (!$tags) {
        echo '<p class="muted">' . e(t('admin.tags.empty', 'No tags exist yet. Add tags from a gallery or image editor first.')) . '</p>';
    } else {
        echo '<div class="admin-tags-list" role="list">';
        foreach ($tags as $tag) {
            $active = (int) ($tag['id'] ?? 0) === $selectedId;
            $usage = (int) ($tag['gallery_count'] ?? 0) + (int) ($tag['image_count'] ?? 0);
            echo '<a class="admin-tag-row' . ($active ? ' is-active' : '') . '" role="listitem" href="' . e((string) ($tag['edit_url'] ?? '')) . '">';
            echo '<span><strong>' . e((string) ($tag['name'] ?? '')) . '</strong><small>/' . e((string) ($tag['slug'] ?? '')) . '</small></span>';
            echo '<em>' . e(t('admin.tags.usage_count', '{count} uses', ['count' => $usage])) . '</em></a>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="panel admin-tags-edit-panel">';
    if (!$selectedTag) {
        echo '<h2>' . e(t('admin.tags.no_selection', 'No tag selected')) . '</h2>';
        echo '<p class="muted">' . e(t('admin.tags.no_selection_help', 'Select a tag ' . 'from the list to edit it.')) . '</p>';
    } else {
        view_render_admin_tag_form((array) ($viewModel['form'] ?? []));
        echo '<section class="admin-tags-usage-panel"><h3>' . e(t('admin.tags.used_where', 'Used in')) . '</h3>';
        $usage = (array) ($viewModel['usage'] ?? []);
        $galleries = (array) ($usage['galleries'] ?? []);
        $images = (array) ($usage['images'] ?? []);
        if (!$galleries && !$images) {
            echo '<p class="muted">' . e(t('admin.tags.used_where_empty', 'This tag is not attached to any galleries or images yet.')) . '</p>';
        } else {
            if ($galleries) {
                echo '<div class="admin-tags-usage-group"><h4>' . e(t('admin.tags.used_in_galleries', 'Galleries')) . '</h4><ul class="admin-tags-usage-list">';
                foreach ($galleries as $gallery) {
                    echo '<li><a href="' . e((string) ($gallery['public_url'] ?? '')) . '" target="_blank" rel="noopener">' . e((string) ($gallery['title'] ?? '')) . '</a></li>';
                }
                echo '</ul></div>';
            }
            if ($images) {
                echo '<div class="admin-tags-usage-group"><h4>' . e(t('admin.tags.used_in_images', 'Images')) . '</h4><ul class="admin-tags-usage-list">';
                foreach ($images as $image) {
                    echo '<li><a href="' . e((string) ($image['edit_url'] ?? '')) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="' . e(t('gallery.photo_editor', 'Photo editor')) . '" data-admin-side-panel-title="' . e(t('admin.gallery_editor.edit_photo', 'Edit photo')) . '" data-gallery-side-panel-url="' . e((string) ($image['side_panel_url'] ?? '')) . '">' . e((string) ($image['relative_path'] ?? '')) . '</a><small>' . e((string) ($image['gallery_title'] ?? '')) . '</small></li>';
                }
                echo '</ul></div>';
            }
        }
        echo '</section>';
    }
    echo '</div></section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared selected-tag form state. */
function view_render_admin_tag_form(array $viewModel): void
{
    $tag = (array) ($viewModel['tag'] ?? []);
    echo '<h2>' . e(t('admin.tags.edit_heading', 'Edit tag')) . '</h2>';
    echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" class="admin-tags-form">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="tag_id" value="' . (int) ($tag['id'] ?? 0) . '"><input type="hidden" name="action" value="save"><input type="hidden" name="sort" value="' . e((string) ($viewModel['sort_mode'] ?? 'usage')) . '">';
    echo '<label>' . e(t('admin.tags.name', 'Tag name')) . '<input name="name" value="' . e((string) ($tag['name'] ?? '')) . '" required maxlength="100" autocomplete="off"><span class="muted">' . e(t('admin.tags.name_help', 'Use lowercase letters, numbers, and hyphens only. Other input is normalized automatically when saved.')) . '</span></label>';
    echo '<label>' . e(t('admin.tags.slug', 'URL slug')) . '<input name="slug" value="' . e((string) ($tag['slug'] ?? '')) . '" required maxlength="120" autocomplete="off"><span class="muted">' . e(t('admin.tags.slug_help', 'This controls the public tag URL. Keep it short and stable when possible.')) . '</span></label>';
    echo '<label>' . e(t('admin.tags.public_description', 'Public description')) . '<textarea name="description" rows="6">' . e((string) ($viewModel['description'] ?? '')) . '</textarea><span class="muted">' . e(t('admin.tags.description_help', 'Optional text shown on the public tag landing page.')) . '</span></label>';
    echo '<div class="bulk-row"><button type="submit">' . e(t('admin.tags.save', 'Save tag')) . '</button>';
    if (!empty($viewModel['public_url'])) {
        echo '<a class="button secondary" href="' . e((string) $viewModel['public_url']) . '">' . e(t('admin.tags.view_public', 'View public tag')) . '</a>';
    }
    echo '<a class="button secondary" href="' . e((string) ($viewModel['theme_url'] ?? '')) . '">' . e(t('admin.tags.configure_display', 'Configure tag display')) . '</a></div></form>';
}
