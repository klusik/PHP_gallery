<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_tags.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Renders public tag pages and tag UI fragments.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one feature responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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

/**
 * Tag and voting controllers.
 *
 * This module contains public tag landing pages, public vote handling, and the
 * shared rendering helpers for tag chips and image vote forms. It is separated
 * from the main controller file so gallery page rendering can stay focused on
 * layout while the interaction metadata routes remain easy to review.
 */

/**
 * Public tag-filter page listing galleries associated with a tag.
 */
function cms_tag(): void
{
    // Variable $tag stores this steps working value.
    $tag = find_tag_by_slug((string) ($_GET['slug'] ?? ''));
    if (!$tag) {
        cms_not_found();
        return;
    }
    // Variable $galleries stores this steps working value.
    $galleries = public_galleries_for_tag((int) $tag['id']);
    render_header(t('public.tag.title_value', 'Tag: {tag}', ['tag' => (string) $tag['name']]));
    echo '<nav class="breadcrumbs" aria-label="' . e(t('public.common.breadcrumbs', 'Breadcrumbs')) . '"><a href="' . e(url_for('home')) . '">' . e(t('public.gallery.galleries', 'Galleries')) . '</a><span aria-hidden="true">/</span><span>' . e(t('public.tag.title_value', 'Tag: {tag}', ['tag' => (string) $tag['name']])) . '</span></nav>';
    echo '<section class="hero" data-public-tag-page data-tag-id="' . (int) $tag['id'] . '"><div class="hero-title-row"><div><h1>' . e(t('public.tag.title_value', 'Tag: {tag}', ['tag' => (string) $tag['name']])) . '</h1></div>';
    render_public_tag_admin_actions($tag);
    echo '</div>';
    if (tag_description_schema_ready() && trim((string) ($tag['description'] ?? '')) !== '') {
        echo '<p>' . nl2br(e((string) $tag['description'])) . '</p>';
    }
    echo '<p class="muted">' . e(t('public.tag.gallery_count', '{count} galleries', ['count' => count($galleries)])) . '</p></section>';
    if ($galleries) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<section class="grid gallery-list-content" data-back-to-top-list>';
        foreach ($galleries as $gallery) {
            render_gallery_card($gallery, true);
        }
        echo '</section>';
        render_back_to_top_button();
        echo '</div>';
    }
    render_footer();
}

/**
 * Render compact public tag admin actions for logged-in admins.
 *
 * The edit action uses the reusable right-side admin panel. The delete action
 * remains a CSRF-protected form with a normal POST fallback, matching gallery
 * and photo contextual controls on public pages.
 *
 * @param array $tag Tag value.
 */
function render_public_tag_admin_actions(array $tag): void
{
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $name = trim((string) ($tag['name'] ?? 'tag'));
    $label = t('gallery.edit_tag_named', 'Edit tag {name}', ['name' => $name]);
    echo '<div class="hero-actions public-tag-admin-actions">';
    echo '<a class="public-admin-edit-button public-admin-edit-button-hero public-admin-edit-button-tag" href="' . e(url_for('admin_tags', ['id' => (int) $tag['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="tag-edit" data-admin-side-panel-kicker="' . e(t('gallery.tag_editor', 'Tag editor')) . '" data-admin-side-panel-title="' . e(t('gallery.edit_tag', 'Edit tag')) . '" data-gallery-side-panel-url="' . e(url_for('admin_tags', ['id' => (int) $tag['id'], 'panel' => 1])) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#9998;</span><span class="visually-hidden">' . e($label) . '</span></a>';
    render_public_tag_admin_delete_form($tag);
    echo '</div>';
}

/**
 * Render the public tag delete action for logged-in admins.
 *
 * @param array $tag Tag value.
 */
function render_public_tag_admin_delete_form(array $tag): void
{
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $name = trim((string) ($tag['name'] ?? 'tag'));
    $label = t('gallery.remove_tag_named', 'Remove tag {name} from CMS', ['name' => $name]);
    echo '<form class="public-admin-delete-form public-admin-delete-form-hero public-admin-delete-form-tag" method="post" action="' . e(url_for('admin_tags', ['id' => (int) $tag['id']])) . '" data-public-admin-card-action data-public-admin-delete-form data-public-admin-delete-name="' . e($name) . '" data-public-admin-delete-kind="tag">';
    echo csrf_field();
    echo '<input type="hidden" name="tag_id" value="' . (int) $tag['id'] . '">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<input type="hidden" name="return_url" value="' . e(url_for('home')) . '">';
    echo '<button type="submit" class="public-admin-card-action-button public-admin-delete-button" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#128465;</span><span class="visually-hidden">' . e($label) . '</span></button>';
    echo '</form>';
}

/**
 * Render clickable tag pills.
 *
 * @param array $tags Tags value.
 * @param ?string $label Label value.
 */
function render_tag_list(array $tags, ?string $label = null): void
{
    if (!$tags) {
        return;
    }
    echo '<p class="tag-list">';
    if ($label !== null) {
        echo '<span class="tag-list-label">' . e($label) . '</span>';
    }
    foreach ($tags as $tag) {
        echo '<a class="tag" href="' . e(url_for('tag', ['slug' => $tag['slug']])) . '">' . e($tag['name']) . '</a>';
    }
    echo '</p>';
}

/**
 * Render a one-line tag preview for horizontal gallery cards.
 *
 * Full gallery pages still render every tag through render_tag_list(). This
 * helper keeps card metadata visually stable beside the optional manual date
 * by showing the first tags inline and replacing the remaining tags with a
 * compact ellipsis indicator.
 *
 * @param array $tags Tags value.
 * @param int $visibleLimit Visible limit value.
 */
function render_compact_tag_list(array $tags, int $visibleLimit = 3): void
{
    if (!$tags) {
        return;
    }

    $visibleLimit = max(1, $visibleLimit);
    $visibleTags = array_slice($tags, 0, $visibleLimit);
    $hiddenCount = max(0, count($tags) - count($visibleTags));

    echo '<p class="tag-list tag-list-compact">';
    foreach ($visibleTags as $tag) {
        echo '<a class="tag" href="' . e(url_for('tag', ['slug' => $tag['slug']])) . '">' . e($tag['name']) . '</a>';
    }
    if ($hiddenCount > 0) {
        echo '<span class="tag tag-more" title="' . e(t('gallery.more_tags', '{count} more tags', ['count' => $hiddenCount])) . '" aria-label="' . e(t('gallery.more_tags', '{count} more tags', ['count' => $hiddenCount])) . '">...</span>';
    }
    echo '</p>';
}
