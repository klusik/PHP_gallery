<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/viewer_favourites.php
 * Module Type: View
 *
 * Purpose:
 *   Renders private viewer-favourites controls, mutation feedback, and the favourites page.
 *
 * Responsibilities:
 *   - Render card and lightbox favourite mutation forms from controller-prepared state
 *   - Render bounded mutation error pages
 *   - Render the private favourites page, cards, hidden-item notice, and pagination
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Authentication, CSRF validation, source authorization, storage availability, pagination
 *     reads, thumbnail preparation, collection state, URLs, and mutation policy stay outside the view.
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

/** @param array<string,mixed> $viewModel Controller-prepared favourite-form state. */
function view_render_viewer_favourite_form_html(array $viewModel): string
{
    $imageId = (int) ($viewModel['image_id'] ?? 0);
    $isFavourite = !empty($viewModel['is_favourite']);
    $label = (string) ($viewModel['label'] ?? '');
    $action = (string) ($viewModel['action'] ?? 'add');
    $classes = (string) ($viewModel['classes'] ?? 'viewer-favourite-form');
    return '<form class="' . e($classes) . '" method="post" action="' . e((string) ($viewModel['url'] ?? '')) . '" data-viewer-favourite-form data-image-id="' . $imageId . '" data-favourite="' . ($isFavourite ? '1' : '0') . '">'
        . '<input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '">'
        . '<input type="hidden" name="image_id" value="' . $imageId . '" data-viewer-favourite-image-id>'
        . '<input type="hidden" name="action" value="' . e($action) . '" data-viewer-favourite-action>'
        . '<button type="submit" class="viewer-favourite-button" aria-pressed="' . ($isFavourite ? 'true' : 'false') . '" aria-label="' . e($label) . '" title="' . e($label) . '" data-viewer-favourite-button>'
        . '<span aria-hidden="true" data-viewer-favourite-icon>' . ($isFavourite ? '&#9829;' : '&#9825;') . '</span>'
        . '<span class="visually-hidden" data-viewer-favourite-label>' . e($label) . '</span></button></form>';
}

/** @param array<string,mixed> $viewModel Controller-prepared lightbox favourite-form state. */
function view_render_viewer_favourite_lightbox_form_html(array $viewModel): string
{
    $label = (string) ($viewModel['label'] ?? '');
    return '<form class="viewer-favourite-form viewer-favourite-lightbox-form" method="post" action="' . e((string) ($viewModel['url'] ?? '')) . '" data-viewer-favourite-form data-viewer-favourite-lightbox-form hidden>'
        . '<input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '">'
        . '<input type="hidden" name="image_id" value="0" data-viewer-favourite-image-id>'
        . '<input type="hidden" name="action" value="add" data-viewer-favourite-action>'
        . '<button type="submit" class="viewer-favourite-button" aria-pressed="false" aria-label="' . e($label) . '" title="' . e($label) . '" data-viewer-favourite-button>'
        . '<span aria-hidden="true" data-viewer-favourite-icon>&#9825;</span>'
        . '<span class="visually-hidden" data-viewer-favourite-label>' . e($label) . '</span></button></form>';
}

/** @param array<string,mixed> $viewModel Controller-prepared mutation error state. */
function view_render_viewer_favourites_error(array $viewModel): void
{
    $title = (string) ($viewModel['title'] ?? '');
    render_header($title);
    echo '<section class="panel"><h1>' . e($title) . '</h1><p>' . e((string) ($viewModel['message'] ?? '')) . '</p>';
    $backUrl = (string) ($viewModel['back_url'] ?? '');
    if ($backUrl !== '') {
        echo '<p><a class="button secondary" href="' . e($backUrl) . '">' . e((string) ($viewModel['back_label'] ?? '')) . '</a></p>';
    }
    echo '</section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared favourites-page state. */
function view_render_viewer_favourites_page(array $viewModel): void
{
    $title = (string) ($viewModel['title'] ?? '');
    render_header($title);
    echo '<section class="hero"><div class="hero-topbar"><div class="hero-primary"><div><h1>' . e($title) . '</h1><p>' . e((string) ($viewModel['help'] ?? '')) . '</p></div></div><div class="hero-meta"><div class="hero-actions"><a class="button secondary" href="' . e((string) ($viewModel['account_url'] ?? '')) . '">' . e((string) ($viewModel['account_label'] ?? '')) . '</a></div></div></div></section>';

    if (empty($viewModel['storage_available'])) {
        echo '<section class="panel"><p>' . e((string) ($viewModel['unavailable_message'] ?? '')) . '</p></section>';
        render_footer();
        return;
    }

    $cards = (array) ($viewModel['cards'] ?? []);
    if ($cards === []) {
        echo '<section class="panel"><p>' . e((string) ($viewModel['empty_message'] ?? '')) . '</p></section>';
    } else {
        echo '<section class="grid gallery-image-grid viewer-favourites-grid">';
        foreach ($cards as $card) {
            echo '<article class="image-card" data-image-id="' . (int) ($card['image_id'] ?? 0) . '" data-viewer-favourite="1"><div class="image-stage">';
            echo '<a class="image-preview-link" href="' . e((string) ($card['image_url'] ?? '')) . '">' . (string) ($card['thumbnail_html'] ?? '') . '</a>';
            echo (string) ($card['favourite_control_html'] ?? '');
            echo (string) ($card['collection_control_html'] ?? '');
            if ((string) ($card['title'] ?? '') !== '') {
                echo '<div class="image-meta image-meta-overlay"><h2>' . e((string) $card['title']) . '</h2></div>';
            }
            echo '</div></article>';
        }
        echo '</section>';
    }

    if (!empty($viewModel['hidden_unavailable'])) {
        echo '<p class="muted">' . e((string) ($viewModel['hidden_message'] ?? '')) . '</p>';
    }

    $pagination = (array) ($viewModel['pagination'] ?? []);
    if (!empty($pagination['visible'])) {
        echo '<nav class="pagination" aria-label="' . e((string) ($pagination['aria_label'] ?? '')) . '">';
        if ((string) ($pagination['previous_url'] ?? '') !== '') {
            echo '<a class="button secondary" href="' . e((string) $pagination['previous_url']) . '">' . e((string) ($pagination['previous_label'] ?? '')) . '</a>';
        }
        echo '<span>' . e((string) ($pagination['page_label'] ?? '')) . '</span>';
        if ((string) ($pagination['next_url'] ?? '') !== '') {
            echo '<a class="button secondary" href="' . e((string) $pagination['next_url']) . '">' . e((string) ($pagination['next_label'] ?? '')) . '</a>';
        }
        echo '</nav>';
    }

    render_footer();
}
