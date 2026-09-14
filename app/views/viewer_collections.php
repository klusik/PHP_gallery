<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/viewer_collections.php
 * Module Type: View
 *
 * Purpose:
 *   Renders private viewer collection screens and collection controls.
 *
 * Responsibilities:
 *   - Render collection index/create/manage screens
 *   - Render authorized collection image cards and reorder controls
 *   - Render reusable add-to-collection controls
 *   - Render bounded collection workflow errors
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
 *   - Ownership, authorization, quotas, mutation services, URL generation,
 *     CSRF issuance, and thumbnail selection remain outside the view.
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

/** @param array<string,mixed> $viewModel Controller-prepared error state. */
function view_render_viewer_collection_error(array $viewModel): void
{
    render_header(t('viewer.collections.title', 'Collections'));
    echo '<section class="panel"><h1>' . e(t('viewer.collections.title', 'Collections')) . '</h1><p>' . e((string) ($viewModel['message'] ?? '')) . '</p>';
    echo '<p><a class="button secondary" href="' . e((string) ($viewModel['back_url'] ?? '')) . '">' . e(t('viewer.collections.back', 'Back to collections')) . '</a></p></section>';
    render_footer();
}

/**
 * Render a compact add-to-collection control.
 *
 * @param array<string,mixed> $viewModel Controller-prepared control state.
 */
function view_render_viewer_collection_add_control_html(array $viewModel): string
{
    ob_start();
    $imageId = (int) ($viewModel['image_id'] ?? 0);
    echo '<details class="' . e((string) ($viewModel['classes'] ?? 'viewer-collection-add')) . '"><summary class="viewer-collection-add-button" title="' . e(t('viewer.collections.add', 'Add to collection')) . '">';
    echo '<span aria-hidden="true">+</span><span class="visually-hidden">' . e(t('viewer.collections.add', 'Add to collection')) . '</span></summary>';
    echo '<div class="viewer-collection-add-menu"><strong>' . e(t('viewer.collections.add', 'Add to collection')) . '</strong>';
    $collections = (array) ($viewModel['collections'] ?? []);
    if ($collections === []) {
        echo '<p>' . e(t('viewer.collections.create_first', 'Create a collection first.')) . '</p>';
        echo '<a class="button secondary" href="' . e((string) ($viewModel['collections_url'] ?? '')) . '">' . e(t('viewer.collections.open', 'Open collections')) . '</a>';
    } else {
        echo '<form method="post" action="' . e((string) ($viewModel['add_url'] ?? '')) . '">';
        echo '<input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '">';
        echo '<input type="hidden" name="image_id" value="' . $imageId . '">';
        echo '<label class="visually-hidden" for="viewer-collection-image-' . $imageId . '">' . e(t('viewer.collections.title', 'Collections')) . '</label>';
        echo '<select id="viewer-collection-image-' . $imageId . '" name="collection_id" required>';
        foreach ($collections as $collection) {
            echo '<option value="' . (int) ($collection['id'] ?? 0) . '">' . e((string) ($collection['title'] ?? '')) . '</option>';
        }
        echo '</select><button type="submit" class="button secondary">' . e(t('viewer.collections.add_submit', 'Add')) . '</button></form>';
    }
    echo '</div></details>';
    return (string) ob_get_clean();
}

/** @param array<string,mixed> $viewModel Controller-prepared reorder form state. */
function view_render_viewer_collection_reorder_form(array $viewModel): void
{
    echo '<form class="' . e((string) ($viewModel['class_name'] ?? '')) . '" method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '">';
    echo '<input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '">';
    echo '<input type="hidden" name="move_image_id" value="' . (int) ($viewModel['image_id'] ?? 0) . '">';
    echo '<input type="hidden" name="move_direction" value="' . e((string) ($viewModel['direction'] ?? '')) . '">';
    echo '<button type="submit" class="button secondary small">' . e((string) ($viewModel['label'] ?? '')) . '</button></form>';
}

/** @param array<string,mixed> $viewModel Controller-prepared collection-index state. */
function view_render_viewer_collections_index(array $viewModel): void
{
    render_header(t('viewer.collections.title', 'Collections'));
    echo '<section class="hero panel"><div class="hero-content"><div><p class="eyebrow">' . e(t('viewer.collections.private_label', 'Private viewer content')) . '</p><h1>' . e(t('viewer.collections.title', 'Collections')) . '</h1>';
    echo '<p>' . e(t('viewer.collections.help', 'Collections are private ordered lists of photo references. They never grant access to source galleries.')) . '</p></div>';
    echo '<div class="hero-meta"><div class="hero-actions"><a class="button secondary" href="' . e((string) ($viewModel['account_url'] ?? '')) . '">' . e(t('viewer.collections.back_to_account', 'Account')) . '</a></div></div></div></section>';

    foreach (['message' => '', 'error' => 'error'] as $key => $className) {
        $message = (string) ($viewModel[$key] ?? '');
        if ($message !== '') {
            echo '<section class="panel"><p' . ($className !== '' ? ' class="' . e($className) . '"' : '') . '>' . e($message) . '</p></section>';
        }
    }

    echo '<section class="panel viewer-collection-create"><h2>' . e(t('viewer.collections.create_title', 'Create collection')) . '</h2>';
    echo '<form method="post" action="' . e((string) ($viewModel['create_url'] ?? '')) . '" class="form-stack">';
    echo '<input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '">';
    echo '<input type="hidden" name="collection_form_nonce" value="' . e((string) ($viewModel['nonce'] ?? '')) . '">';
    echo '<label>' . e(t('viewer.collections.title_label', 'Title')) . '<input type="text" name="title" required maxlength="120" autocomplete="off"></label>';
    echo '<button type="submit" class="button">' . e(t('viewer.collections.create', 'Create collection')) . '</button></form>';
    echo '<p class="muted">' . e(t('viewer.collections.quota_status', '{count} of {limit} collections used.', [
        'count' => (int) ($viewModel['collection_count'] ?? 0),
        'limit' => (int) ($viewModel['collection_limit'] ?? 0),
    ])) . '</p></section>';

    echo '<section class="panel"><h2>' . e(t('viewer.collections.yours', 'Your collections')) . '</h2>';
    $collections = (array) ($viewModel['collections'] ?? []);
    if ($collections === []) {
        echo '<p>' . e(t('viewer.collections.empty', 'You have not created any collections yet.')) . '</p>';
    } else {
        echo '<div class="viewer-collection-list">';
        foreach ($collections as $collection) {
            $collectionId = (int) ($collection['id'] ?? 0);
            echo '<article class="viewer-collection-list-item"><div class="viewer-collection-list-main"><h3><a href="' . e((string) ($collection['open_url'] ?? '')) . '">' . e((string) ($collection['title'] ?? '')) . '</a></h3>';
            echo '<p class="muted">' . e(t('viewer.collections.item_count', '{count} items', ['count' => (int) ($collection['item_count'] ?? 0)])) . '</p></div>';
            echo '<div class="viewer-collection-list-actions"><a class="button secondary" href="' . e((string) ($collection['open_url'] ?? '')) . '">' . e(t('viewer.collections.open_one', 'Open')) . '</a>';
            echo '<form method="post" action="' . e((string) ($collection['rename_url'] ?? '')) . '" class="viewer-collection-inline-form"><input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '"><label class="visually-hidden" for="collection-title-' . $collectionId . '">' . e(t('viewer.collections.rename_title', 'New title')) . '</label><input id="collection-title-' . $collectionId . '" type="text" name="title" value="' . e((string) ($collection['title'] ?? '')) . '" required maxlength="120"><button type="submit" class="button secondary">' . e(t('viewer.collections.rename', 'Rename')) . '</button></form>';
            echo '<form method="post" action="' . e((string) ($collection['delete_url'] ?? '')) . '" class="viewer-collection-delete-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.collections.delete_confirm', 'Delete this collection? The photographs themselves will not be deleted.')) . '"><input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '"><input type="hidden" name="confirm_delete_collection" value="1"><button type="submit" class="button danger">' . e(t('viewer.collections.delete', 'Delete')) . '</button></form></div></article>';
        }
        echo '</div>';
    }
    echo '</section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared collection-detail state. */
function view_render_viewer_collection_detail(array $viewModel): void
{
    render_header((string) ($viewModel['title'] ?? ''));
    echo '<section class="hero panel"><div class="hero-content"><div><p class="eyebrow">' . e(t('viewer.collections.private_label', 'Private viewer content')) . '</p><h1>' . e((string) ($viewModel['title'] ?? '')) . '</h1>';
    echo '<p>' . e(t('viewer.collections.detail_help', 'Only photos currently authorized by their source galleries are shown.')) . '</p></div><div class="hero-meta"><div class="hero-actions"><a class="button secondary" href="' . e((string) ($viewModel['back_url'] ?? '')) . '">' . e(t('viewer.collections.back', 'Back to collections')) . '</a></div></div></div></section>';

    echo '<section class="panel viewer-collection-manage"><div class="viewer-collection-manage-row"><form method="post" action="' . e((string) ($viewModel['rename_url'] ?? '')) . '" class="viewer-collection-inline-form"><input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '"><label>' . e(t('viewer.collections.rename_title', 'New title')) . '<input type="text" name="title" value="' . e((string) ($viewModel['title'] ?? '')) . '" required maxlength="120"></label><button type="submit" class="button secondary">' . e(t('viewer.collections.rename', 'Rename')) . '</button></form>';
    echo '<form method="post" action="' . e((string) ($viewModel['delete_url'] ?? '')) . '" class="viewer-collection-delete-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.collections.delete_confirm', 'Delete this collection? The photographs themselves will not be deleted.')) . '"><input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '"><input type="hidden" name="confirm_delete_collection" value="1"><button type="submit" class="button danger">' . e(t('viewer.collections.delete', 'Delete')) . '</button></form></div></section>';

    echo (string) ($viewModel['share_owner_html'] ?? '');

    $cards = (array) ($viewModel['cards'] ?? []);
    if ($cards === []) {
        echo '<section class="panel"><p>' . e((string) ($viewModel['empty_message'] ?? '')) . '</p></section>';
    } else {
        echo '<section class="grid gallery-image-grid viewer-collection-grid">';
        foreach ($cards as $card) {
            $imageId = (int) ($card['image_id'] ?? 0);
            echo '<article class="image-card viewer-collection-item" data-image-id="' . $imageId . '"><div class="image-stage"><a class="image-preview-link" href="' . e((string) ($card['image_url'] ?? '')) . '">' . (string) ($card['thumbnail_html'] ?? '') . '</a>';
            if ((string) ($card['title'] ?? '') !== '') {
                echo '<div class="image-meta image-meta-overlay"><h2>' . e((string) $card['title']) . '</h2></div>';
            }
            echo '</div><div class="viewer-collection-item-actions">';
            echo '<form method="post" action="' . e((string) ($viewModel['remove_url'] ?? '')) . '"><input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '"><input type="hidden" name="image_id" value="' . $imageId . '"><button type="submit" class="button secondary small">' . e(t('viewer.collections.remove', 'Remove')) . '</button></form>';
            if (is_array($card['move_up'] ?? null)) {
                view_render_viewer_collection_reorder_form((array) $card['move_up']);
            }
            if (is_array($card['move_down'] ?? null)) {
                view_render_viewer_collection_reorder_form((array) $card['move_down']);
            }
            echo '</div></article>';
        }
        echo '</section>';
    }
    if ((int) ($viewModel['hidden_count'] ?? 0) > 0) {
        echo '<p class="muted">' . e(t('viewer.collections.hidden_unavailable', 'Some saved collection items are currently unavailable.')) . '</p>';
    }
    render_footer();
}
