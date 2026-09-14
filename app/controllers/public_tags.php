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
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\admin_anonymous_preview_active;
use function Gallery\Core\current_user;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\url_for;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\find_tag_by_slug;
use function Gallery\Services\pagination_current_page;
use function Gallery\Services\pagination_grid_columns_class;
use function Gallery\Services\pagination_model;
use function Gallery\Services\pagination_slice_items;
use function Gallery\Services\public_galleries_for_tag;
use function Gallery\Views\view_render_pagination_controls;
use function Gallery\Services\t;
use function Gallery\Services\tag_description_schema_ready;
use function Gallery\Services\tag_page_gallery_description_layout;
use function Gallery\Services\tag_page_gallery_grid_settings;
use function Gallery\Views\view_render_compact_tag_list;
use function Gallery\Views\view_render_public_tag_admin_actions;
use function Gallery\Views\view_render_public_tag_admin_delete_form;
use function Gallery\Views\view_render_public_tag_page;
use function Gallery\Views\view_render_tag_list;

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
    // $tagGridSettings stores the Theme override dedicated to public tag pages.
    $tagGridSettings = tag_page_gallery_grid_settings();
    // $tagPagination stores page links and slicing bounds for the tagged gallery list.
    $tagPagination = pagination_model(
        count($galleries),
        pagination_current_page('tag_gallery_page'),
        (int) $tagGridSettings['columns'],
        (int) $tagGridSettings['rows'],
        'tag_gallery_page',
        null,
        static function (int $pageNumber) use ($tag): string {
            $baseUrl = url_for('tag', ['slug' => (string) $tag['slug']]);
            return $pageNumber <= 1 ? $baseUrl : $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'tag_gallery_page=' . $pageNumber;
        }
    );
    $visibleGalleries = !empty($tagGridSettings['enabled']) ? pagination_slice_items($galleries, $tagPagination) : $galleries;
    $tagCardLayout = tag_page_gallery_description_layout();
    $pageTitle = t('public.tag.title_value', 'Tag: {tag}', ['tag' => (string) $tag['name']]);
    $pagination = !empty($tagGridSettings['enabled']) ? $tagPagination : [];
    $paginationLabel = t('public.tag.pagination_label', 'Tagged gallery pages');

    ob_start();
    view_render_pagination_controls($pagination, $paginationLabel);
    $paginationHtml = (string) ob_get_clean();

    ob_start();
    foreach ($visibleGalleries as $cardIndex => $gallery) {
        render_gallery_card($gallery, true, false, false, (int) $cardIndex, ['description_layout' => $tagCardLayout]);
    }
    $galleryCardsHtml = (string) ob_get_clean();

    ob_start();
    render_back_to_top_button();
    $backToTopHtml = (string) ob_get_clean();

    $viewModel = [
        'title' => $pageTitle,
        'breadcrumbs_label' => t('public.common.breadcrumbs', 'Breadcrumbs'),
        'home_url' => url_for('home'),
        'galleries_label' => t('public.gallery.galleries', 'Galleries'),
        'tag_id' => (int) $tag['id'],
        'gallery_count' => count($galleries),
        'canonical_url' => url_for('tag', ['slug' => (string) $tag['slug']]),
        'admin_actions' => public_tag_admin_actions_view_model($tag),
        'description_enabled' => tag_description_schema_ready(),
        'description' => (string) ($tag['description'] ?? ''),
        'gallery_count_label' => t('public.tag.gallery_count', '{count} galleries', ['count' => count($galleries)]),
        'has_galleries' => $galleries !== [],
        'pagination_top_html' => $paginationHtml,
        'pagination_bottom_html' => $paginationHtml,
        'grid_class' => pagination_grid_columns_class($tagGridSettings),
        'gallery_cards_html' => $galleryCardsHtml,
        'back_to_top_html' => $backToTopHtml,
    ];

    render_header($pageTitle);
    view_render_public_tag_page($viewModel);
    render_footer();
}

/**
 * Prepare compact public tag Admin actions for the presentation layer.
 *
 * @param array $tag Tag value.
 * @return array<string, mixed> Prepared Admin action presentation model.
 */
function public_tag_admin_actions_view_model(array $tag): array
{
    if (!current_user() || admin_anonymous_preview_active()) {
        return ['enabled' => false];
    }

    $name = trim((string) ($tag['name'] ?? 'tag'));
    return [
        'enabled' => true,
        'tag_id' => (int) ($tag['id'] ?? 0),
        'name' => $name,
        'edit_label' => t('gallery.edit_tag_named', 'Edit tag {name}', ['name' => $name]),
        'delete_label' => t('gallery.remove_tag_named', 'Remove tag {name} from CMS', ['name' => $name]),
        'edit_url' => url_for('admin_tags', ['id' => (int) ($tag['id'] ?? 0)]),
        'panel_url' => url_for('admin_tags', ['id' => (int) ($tag['id'] ?? 0), 'panel' => 1]),
        'panel_kicker' => t('gallery.tag_editor', 'Tag editor'),
        'panel_title' => t('gallery.edit_tag', 'Edit tag'),
        'delete_url' => url_for('admin_tags', ['id' => (int) ($tag['id'] ?? 0)]),
        'return_url' => url_for('home'),
    ];
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
    view_render_public_tag_admin_actions(public_tag_admin_actions_view_model($tag));
}

/**
 * Render the public tag delete action for logged-in admins.
 *
 * @param array $tag Tag value.
 */
function render_public_tag_admin_delete_form(array $tag): void
{
    view_render_public_tag_admin_delete_form(public_tag_admin_actions_view_model($tag));
}

/**
 * Prepare clickable tag pills for the presentation layer.
 *
 * @param array $tags Tags value.
 * @param ?string $label Label value.
 * @return array<string, mixed> Prepared tag-list presentation model.
 */
function tag_list_view_model(array $tags, ?string $label = null): array
{
    $publicTagBrowsingEnabled = feature_capability_effective_enabled('public_tag_browsing');
    $items = [];
    foreach ($tags as $tag) {
        $items[] = [
            'name' => (string) ($tag['name'] ?? ''),
            'href' => $publicTagBrowsingEnabled ? url_for('tag', ['slug' => (string) ($tag['slug'] ?? '')]) : null,
        ];
    }

    return ['items' => $items, 'label' => $label];
}

/**
 * Render clickable tag pills.
 *
 * @param array $tags Tags value.
 * @param ?string $label Label value.
 */
function render_tag_list(array $tags, ?string $label = null): void
{
    view_render_tag_list(tag_list_view_model($tags, $label));
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
    $viewModel = tag_list_view_model($visibleTags);
    $viewModel['hidden_count'] = $hiddenCount;
    $viewModel['more_label'] = $hiddenCount > 0 ? t('gallery.more_tags', '{count} more tags', ['count' => $hiddenCount]) : '';
    view_render_compact_tag_list($viewModel);
}
