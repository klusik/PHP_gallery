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
use function Gallery\Services\t;

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
 * @return void Emits tag pills and an optional inline disclosure control.
 */
function view_render_tag_list(array $viewModel): void
{
    $items = (array) ($viewModel['items'] ?? []);
    if ($items === []) {
        return;
    }

    $inlineDisclosure = !empty($viewModel['inline_disclosure']);
    $containerTag = $inlineDisclosure ? 'div' : 'p';
    echo '<' . $containerTag . ' class="tag-list">';
    if (array_key_exists('label', $viewModel) && $viewModel['label'] !== null) {
        $label = (string) $viewModel['label'];
        echo '<span class="tag-list-label" title="' . e($label) . '">' . e($label) . '</span>';
    }
    $visibleLimit = max(1, (int) ($viewModel['visible_limit'] ?? 20));
    $visibleItems = $inlineDisclosure ? array_slice($items, 0, $visibleLimit) : $items;
    $overflowItems = $inlineDisclosure ? array_slice($items, $visibleLimit) : [];
    $hasOverflow = $overflowItems !== [];
    foreach ($visibleItems as $index => $item) {
        if ($hasOverflow && $index === count($visibleItems) - 1) {
            echo '<div class="gallery-card-tag-tail">';
        }
        $href = $item['href'] ?? null;
        $name = (string) ($item['name'] ?? '');
        if (is_string($href) && $href !== '') {
            echo '<a class="tag" href="' . e($href) . '" title="' . e($name) . '">' . e($name) . '</a>';
        } else {
            echo '<span class="tag" title="' . e($name) . '">' . e($name) . '</span>';
        }
    }
    if ($overflowItems !== []) {
        $showAllLabel = e(t('gallery.show_all_tags', 'Display all tags'));
        $showFewerLabel = e(t('gallery.show_fewer_tags', 'Show fewer tags'));
        echo '<details class="hero-tag-disclosure" data-hero-tags-disclosure>';
        echo '<summary class="tag gallery-card-tags-toggle"><span class="hero-tag-summary-collapsed" aria-hidden="true">[...]</span><span class="hero-tag-summary-expanded" aria-hidden="true">[−]</span><span class="visually-hidden"><span class="hero-tag-summary-label-collapsed">' . $showAllLabel . '</span><span class="hero-tag-summary-label-expanded">' . $showFewerLabel . '</span></span></summary>';
        foreach ($overflowItems as $item) {
            $href = $item['href'] ?? null;
            $name = (string) ($item['name'] ?? '');
            if (is_string($href) && $href !== '') {
                echo '<a class="tag" href="' . e($href) . '" title="' . e($name) . '">' . e($name) . '</a>';
            } else {
                echo '<span class="tag" title="' . e($name) . '">' . e($name) . '</span>';
            }
        }
        echo '</details>';
        echo '</div>';
    }
    echo '</' . $containerTag . '>';
}

/**
 * Render the gallery header's globally limited direct and contained tags.
 *
 * The native details element is present in the initial response, so the
 * configured limit and inline disclosure do not depend on JavaScript startup.
 *
 * @param array<string, mixed> $viewModel Prepared groups and Theme settings.
 * @return void Emits one ordered tag list and its native disclosure.
 */
function view_render_public_hero_tags(array $viewModel): void
{
    $groups = (array) ($viewModel['groups'] ?? []);
    $tagCount = max(0, (int) ($viewModel['tag_count'] ?? 0));
    if ($tagCount === 0) {
        return;
    }

    $visibleLimit = max(1, (int) ($viewModel['visible_limit'] ?? 20));
    $displayAll = !empty($viewModel['display_all']);
    $scrollbarEnabled = !empty($viewModel['scrollbar_enabled']);
    $scrollbarRows = max(1, min(12, (int) ($viewModel['scrollbar_rows'] ?? 5)));
    $rowHeight = [];
    for ($row = 0; $row < $scrollbarRows; $row++) {
        $rowHeight[] = '(1.35em + .2rem + 2px)';
        if ($row < $scrollbarRows - 1) {
            $rowHeight[] = '.28rem';
        }
    }
    $scrollbarStyle = $scrollbarEnabled ? ' style="--hero-tag-scrollbar-height: calc(' . implode(' + ', $rowHeight) . ')"' : '';
    echo '<div class="hero-tags" aria-label="' . e(t('gallery.tags', 'Gallery tags')) . '" data-hero-tags data-hero-tags-native="1" data-hero-tag-visible-limit="' . $visibleLimit . '" data-hero-tag-display-all="' . ($displayAll ? '1' : '0') . '" data-hero-tag-scrollbar-enabled="' . ($scrollbarEnabled ? '1' : '0') . '" data-hero-tag-scrollbar-rows="' . $scrollbarRows . '"' . $scrollbarStyle . '>';
    echo '<div class="hero-tags-content" data-hero-tags-content><div class="tag-list">';

    $renderedTags = 0;
    $disclosureOpened = false;
    $tailOpened = false;
    foreach ($groups as $group) {
        $items = (array) ($group['items'] ?? []);
        if ($items === []) {
            continue;
        }
        if ($renderedTags >= $visibleLimit && !$displayAll && !$disclosureOpened) {
            $showAllLabel = e(t('gallery.show_all_tags', 'Display all tags'));
            $showFewerLabel = e(t('gallery.show_fewer_tags', 'Show fewer tags'));
            echo '<details class="hero-tag-disclosure" data-hero-tags-disclosure>';
            echo '<summary class="tag gallery-card-tags-toggle"><span class="hero-tag-summary-collapsed" aria-hidden="true">[...]</span><span class="hero-tag-summary-expanded" aria-hidden="true">[−]</span><span class="visually-hidden"><span class="hero-tag-summary-label-collapsed">' . $showAllLabel . '</span><span class="hero-tag-summary-label-expanded">' . $showFewerLabel . '</span></span></summary>';
            $disclosureOpened = true;
        }
        if (array_key_exists('label', $group) && $group['label'] !== null) {
            $label = (string) $group['label'];
            echo '<span class="tag-list-label" title="' . e($label) . '">' . e($label) . '</span>';
        }
        foreach ($items as $item) {
            if (!$displayAll && !$tailOpened && $renderedTags + 1 === $visibleLimit && $renderedTags + 1 < $tagCount) {
                echo '<div class="gallery-card-tag-tail">';
                $tailOpened = true;
            }
            $href = $item['href'] ?? null;
            $name = (string) ($item['name'] ?? '');
            if (is_string($href) && $href !== '') {
                echo '<a class="tag" href="' . e($href) . '" title="' . e($name) . '">' . e($name) . '</a>';
            } else {
                echo '<span class="tag" title="' . e($name) . '">' . e($name) . '</span>';
            }
            $renderedTags++;
            if (!$displayAll && !$disclosureOpened && $renderedTags === $visibleLimit && $renderedTags < $tagCount) {
                $showAllLabel = e(t('gallery.show_all_tags', 'Display all tags'));
                $showFewerLabel = e(t('gallery.show_fewer_tags', 'Show fewer tags'));
                echo '<details class="hero-tag-disclosure" data-hero-tags-disclosure>';
                echo '<summary class="tag gallery-card-tags-toggle"><span class="hero-tag-summary-collapsed" aria-hidden="true">[...]</span><span class="hero-tag-summary-expanded" aria-hidden="true">[−]</span><span class="visually-hidden"><span class="hero-tag-summary-label-collapsed">' . $showAllLabel . '</span><span class="hero-tag-summary-label-expanded">' . $showFewerLabel . '</span></span></summary>';
                $disclosureOpened = true;
            }
        }
    }
    if ($disclosureOpened) {
        echo '</details>';
    }
    if ($tailOpened) {
        echo '</div>';
    }
    echo '</div></div></div>';
}

/**
 * Render a compact card preview with a native public-information disclosure.
 *
 * The server applies the Theme limit before first paint. Overflow details open a
 * positioned panel that contains the public card summary and every prepared tag.
 *
 * @param array<string, mixed> $viewModel Prepared tags and canonical Theme preferences.
 * @return void Emits tag pills and the optional public-information panel.
 */
function view_render_gallery_card_tags(array $viewModel): void
{
    $items = (array) ($viewModel['items'] ?? []);
    if ($items === []) {
        return;
    }

    $visibleLimit = max(1, (int) ($viewModel['visible_limit'] ?? 20));
    $displayAll = !empty($viewModel['display_all']);
    $scrollbarEnabled = !empty($viewModel['scrollbar_enabled']);
    $scrollbarRows = max(1, min(12, (int) ($viewModel['scrollbar_rows'] ?? 5)));
    $hasInfoPanel = !$displayAll && count($items) > $visibleLimit;
    $visibleItems = $displayAll ? $items : array_slice($items, 0, $visibleLimit);
    $publicTitle = trim((string) ($viewModel['public_title'] ?? ''));
    $publicDescriptionHtml = trim((string) ($viewModel['public_description_html'] ?? ''));
    $publicDateHtml = trim((string) ($viewModel['public_date_html'] ?? ''));
    $rowHeight = [];
    for ($row = 0; $row < $scrollbarRows; $row++) {
        $rowHeight[] = '(1.35em + .2rem + 2px)';
        if ($row < $scrollbarRows - 1) {
            $rowHeight[] = '.28rem';
        }
    }
    $scrollbarStyle = $scrollbarEnabled ? ' style="--hero-tag-scrollbar-height: calc(' . implode(' + ', $rowHeight) . ')"' : '';
    echo '<div class="gallery-card-tags' . ($scrollbarEnabled ? ' has-tag-scrollbar' : '') . '" aria-label="' . e(t('gallery.tags', 'Gallery tags')) . '" data-hero-tags data-hero-tags-native="1" data-hero-tag-visible-limit="' . $visibleLimit . '" data-hero-tag-display-all="' . ($displayAll ? '1' : '0') . '" data-hero-tag-scrollbar-enabled="' . ($scrollbarEnabled ? '1' : '0') . '" data-hero-tag-scrollbar-rows="' . $scrollbarRows . '"' . $scrollbarStyle . '>';
    echo '<div class="gallery-card-tags-content" data-hero-tags-content><div class="tag-list gallery-card-tag-preview">';
    view_render_public_gallery_card_tag_items($visibleItems);
    if ($hasInfoPanel) {
        $infoLabel = e(t('gallery.public_info', 'Gallery information'));
        echo '<details class="hero-tag-disclosure gallery-card-info-disclosure" data-hero-tags-disclosure data-gallery-card-info-disclosure>';
        echo '<summary class="tag gallery-card-tags-toggle gallery-card-info-toggle" aria-label="' . $infoLabel . '" title="' . $infoLabel . '"><span aria-hidden="true">…</span><span class="visually-hidden">' . $infoLabel . '</span></summary>';
        echo '<section class="gallery-card-public-info-panel" role="region" aria-label="' . $infoLabel . '">';
        echo '<h3>' . $infoLabel . '</h3>';
        if ($publicTitle !== '') {
            echo '<h4 class="gallery-card-public-info-title">' . e($publicTitle) . '</h4>';
        }
        if ($publicDescriptionHtml !== '') {
            echo '<div class="gallery-card-public-info-description">' . $publicDescriptionHtml . '</div>';
        }
        if ($publicDateHtml !== '') {
            echo '<div class="gallery-card-public-info-date">' . $publicDateHtml . '</div>';
        }
        echo '<div class="gallery-card-public-info-tags"><h4>' . e(t('gallery.all_tags', 'All tags')) . '</h4><div class="gallery-card-public-info-tag-list">';
        view_render_public_gallery_card_tag_items($items);
        echo '</div></div></section></details>';
    }
    echo '</div></div>';
    echo '</div>';
}

/**
 * Render prepared public gallery tag links or read-only tag pills.
 *
 * @param array<int, array<string, mixed>> $items Ordered prepared tag items.
 * @return void Emits escaped tag pills without changing their order.
 */
function view_render_public_gallery_card_tag_items(array $items): void
{
    foreach ($items as $item) {
        $href = $item['href'] ?? null;
        $name = (string) ($item['name'] ?? '');
        if (is_string($href) && $href !== '') {
            echo '<a class="tag" href="' . e($href) . '" title="' . e($name) . '">' . e($name) . '</a>';
        } else {
            echo '<span class="tag" title="' . e($name) . '">' . e($name) . '</span>';
        }
    }
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
