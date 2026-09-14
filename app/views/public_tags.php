<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/public_tags.php
 * Module Type: View
 *
 * Purpose:
 *   Renders public tag pages and reusable tag presentation fragments.
 *
 * Responsibilities:
 *   - Render tag landing-page structure from prepared presentation data
 *   - Render tag chips without feature-policy or request lookups
 *   - Render Admin tag controls from controller-prepared URLs and labels
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
 *   - Do not call controllers, models, or domain services from this view.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;

/**
 * Render the public tag landing page body.
 *
 * @param array<string, mixed> $viewModel Prepared tag-page presentation model.
 */
function view_render_public_tag_page(array $viewModel): void
{
    echo '<nav class="breadcrumbs" aria-label="' . e((string) ($viewModel['breadcrumbs_label'] ?? '')) . '"><a href="' . e((string) ($viewModel['home_url'] ?? '')) . '">' . e((string) ($viewModel['galleries_label'] ?? '')) . '</a><span aria-hidden="true">/</span><span>' . e((string) ($viewModel['title'] ?? '')) . '</span></nav>';
    echo '<section class="hero" data-public-tag-page data-tag-id="' . (int) ($viewModel['tag_id'] ?? 0) . '" data-public-tag-gallery-count="' . (int) ($viewModel['gallery_count'] ?? 0) . '" data-admin-mutation-canonical-url="' . e((string) ($viewModel['canonical_url'] ?? '')) . '"><div class="hero-title-row"><div><h1>' . e((string) ($viewModel['title'] ?? '')) . '</h1></div>';
    view_render_public_tag_admin_actions((array) ($viewModel['admin_actions'] ?? []));
    echo '</div>';
    if (!empty($viewModel['description_enabled']) && trim((string) ($viewModel['description'] ?? '')) !== '') {
        echo '<p>' . nl2br(e((string) $viewModel['description'])) . '</p>';
    }
    echo '<p class="muted">' . e((string) ($viewModel['gallery_count_label'] ?? '')) . '</p></section>';

    if (empty($viewModel['has_galleries'])) {
        return;
    }

    echo '<div class="gallery-list-frame" data-back-to-top-scope>';
    echo '<div class="gallery-list-content" data-back-to-top-list>';
    echo (string) ($viewModel['pagination_top_html'] ?? '');
    echo '<section class="grid' . e((string) ($viewModel['grid_class'] ?? '')) . '">';
    echo (string) ($viewModel['gallery_cards_html'] ?? '');
    echo '</section>';
    echo (string) ($viewModel['pagination_bottom_html'] ?? '');
    echo '</div>';
    echo (string) ($viewModel['back_to_top_html'] ?? '');
    echo '</div>';
}

/**
 * Render compact public tag Admin controls.
 *
 * @param array<string, mixed> $viewModel Prepared Admin action presentation model.
 */
function view_render_public_tag_admin_actions(array $viewModel): void
{
    if (empty($viewModel['enabled'])) {
        return;
    }

    echo '<div class="hero-actions public-tag-admin-actions">';
    echo '<a class="public-admin-edit-button public-admin-edit-button-hero public-admin-edit-button-tag" href="' . e((string) ($viewModel['edit_url'] ?? '')) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="tag-edit" data-admin-side-panel-kicker="' . e((string) ($viewModel['panel_kicker'] ?? '')) . '" data-admin-side-panel-title="' . e((string) ($viewModel['panel_title'] ?? '')) . '" data-gallery-side-panel-url="' . e((string) ($viewModel['panel_url'] ?? '')) . '" aria-label="' . e((string) ($viewModel['edit_label'] ?? '')) . '" title="' . e((string) ($viewModel['edit_label'] ?? '')) . '"><span aria-hidden="true">&#9998;</span><span class="visually-hidden">' . e((string) ($viewModel['edit_label'] ?? '')) . '</span></a>';
    view_render_public_tag_admin_delete_form($viewModel);
    echo '</div>';
}

/**
 * Render the public tag delete action.
 *
 * @param array<string, mixed> $viewModel Prepared Admin action presentation model.
 */
function view_render_public_tag_admin_delete_form(array $viewModel): void
{
    if (empty($viewModel['enabled'])) {
        return;
    }

    echo '<form class="public-admin-delete-form public-admin-delete-form-hero public-admin-delete-form-tag" method="post" action="' . e((string) ($viewModel['delete_url'] ?? '')) . '" data-public-admin-card-action data-public-admin-delete-form data-public-admin-delete-name="' . e((string) ($viewModel['name'] ?? '')) . '" data-public-admin-delete-kind="tag">';
    echo csrf_field();
    echo '<input type="hidden" name="tag_id" value="' . (int) ($viewModel['tag_id'] ?? 0) . '">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<input type="hidden" name="return_url" value="' . e((string) ($viewModel['return_url'] ?? '')) . '">';
    echo '<button type="submit" class="public-admin-card-action-button public-admin-delete-button" aria-label="' . e((string) ($viewModel['delete_label'] ?? '')) . '" title="' . e((string) ($viewModel['delete_label'] ?? '')) . '"><span aria-hidden="true">&#128465;</span><span class="visually-hidden">' . e((string) ($viewModel['delete_label'] ?? '')) . '</span></button>';
    echo '</form>';
}

/**
 * Render clickable or read-only tag pills.
 *
 * @param array<string, mixed> $viewModel Prepared tag-list presentation model.
 */
function view_render_tag_list(array $viewModel): void
{
    $items = (array) ($viewModel['items'] ?? []);
    if ($items === []) {
        return;
    }

    echo '<p class="tag-list">';
    if (array_key_exists('label', $viewModel) && $viewModel['label'] !== null) {
        echo '<span class="tag-list-label">' . e((string) $viewModel['label']) . '</span>';
    }
    foreach ($items as $item) {
        $href = $item['href'] ?? null;
        if (is_string($href) && $href !== '') {
            echo '<a class="tag" href="' . e($href) . '">' . e((string) ($item['name'] ?? '')) . '</a>';
        } else {
            echo '<span class="tag">' . e((string) ($item['name'] ?? '')) . '</span>';
        }
    }
    echo '</p>';
}

/**
 * Render compact gallery-card tag pills.
 *
 * @param array<string, mixed> $viewModel Prepared compact tag-list presentation model.
 */
function view_render_compact_tag_list(array $viewModel): void
{
    $items = (array) ($viewModel['items'] ?? []);
    if ($items === []) {
        return;
    }

    echo '<p class="tag-list tag-list-compact">';
    foreach ($items as $item) {
        $href = $item['href'] ?? null;
        if (is_string($href) && $href !== '') {
            echo '<a class="tag" href="' . e($href) . '">' . e((string) ($item['name'] ?? '')) . '</a>';
        } else {
            echo '<span class="tag">' . e((string) ($item['name'] ?? '')) . '</span>';
        }
    }
    if ((int) ($viewModel['hidden_count'] ?? 0) > 0) {
        $moreLabel = (string) ($viewModel['more_label'] ?? '');
        echo '<span class="tag tag-more" title="' . e($moreLabel) . '" aria-label="' . e($moreLabel) . '">...</span>';
    }
    echo '</p>';
}
