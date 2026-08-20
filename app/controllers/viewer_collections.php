<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/viewer_collections.php
 * Module Type: Controller Module
 *
 * Purpose:
 *   Exposes the Phase 2.0 private viewer-collection HTTP boundary.
 *
 * Responsibilities:
 *   - Render only collections owned by the authenticated viewer principal
 *   - Accept create, rename, delete, add, remove, and reorder mutations through viewer CSRF
 *   - Re-authorize every stored image reference against current source-gallery policy before rendering
 *   - Keep collection titles plain text and escaped in every HTML context
 *   - Keep collection sharing, anonymous collection routes, and public viewer identity out of Phase 2.0
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Viewer authentication is not gallery authorization.
 *   - Viewer collection membership is not image authorization.
 *   - Administrator identity is never used as viewer collection ownership authority.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\e;
use function Gallery\Core\image_alt_text;
use function Gallery\Core\image_public_url;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Services\current_viewer;
use function Gallery\Services\public_image_display_title;
use function Gallery\Services\public_thumbnail_render_picture_html;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_bundle;
use function Gallery\Services\thumbnail_sizes;
use function Gallery\Services\viewer_collection_create;
use function Gallery\Services\viewer_collection_delete;
use function Gallery\Services\viewer_collection_item_add;
use function Gallery\Services\viewer_collection_item_references;
use function Gallery\Services\viewer_collection_item_remove;
use function Gallery\Services\viewer_collection_owned_get;
use function Gallery\Services\viewer_collection_rename;
use function Gallery\Services\viewer_collection_reorder;
use function Gallery\Services\viewer_collections_for_owner;
use function Gallery\Services\viewer_collections_storage_available;
use function Gallery\Services\viewer_content_quota_config;
use function Gallery\Services\viewer_csrf_token;
use function Gallery\Services\viewer_source_images_resolve_authorized;

/**
 * Parse one untrusted numeric route/form identifier without accepting mixed strings or zero.
 */
function viewer_collection_positive_id(mixed $value): int
{
    if (is_int($value)) {
        return $value > 0 ? $value : 0;
    }
    if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
        return 0;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
    ]);
    return $parsed === false ? 0 : (int) $parsed;
}

/**
 * Issue one bounded single-use form nonce so a browser retry cannot create the same collection twice.
 */
function viewer_collection_create_nonce_issue(): string
{
    $nonce = bin2hex(random_bytes(16));
    $existing = $_SESSION['viewer_collection_create_nonces'] ?? [];
    if (!is_array($existing)) {
        $existing = [];
    }
    $existing[] = $nonce;
    $_SESSION['viewer_collection_create_nonces'] = array_slice($existing, -8);
    return $nonce;
}

/**
 * Consume a collection-creation nonce exactly once.
 */
function viewer_collection_create_nonce_consume(string $candidate): bool
{
    if ($candidate === '' || strlen($candidate) !== 32) {
        return false;
    }
    $existing = $_SESSION['viewer_collection_create_nonces'] ?? [];
    if (!is_array($existing)) {
        return false;
    }
    foreach ($existing as $index => $nonce) {
        if (is_string($nonce) && hash_equals($nonce, $candidate)) {
            unset($existing[$index]);
            $_SESSION['viewer_collection_create_nonces'] = array_values($existing);
            return true;
        }
    }
    return false;
}

/**
 * Return the active viewer or render/redirect through the existing viewer HTTP boundary.
 *
 * @return ?array Authenticated viewer principal, or null only after a response was rendered.
 */
function viewer_collection_require_viewer(): ?array
{
    viewer_http_no_store();
    if (!viewer_http_auth_available() || !viewer_collections_storage_available()) {
        viewer_render_unavailable();
        return null;
    }
    $viewer = current_viewer();
    if ($viewer === null) {
        redirect_to(url_for('viewer_login'));
    }
    return $viewer;
}

/**
 * Render one generic owned-object miss without disclosing collection metadata or ownership.
 */
function viewer_collection_render_not_found(): void
{
    viewer_http_no_store();
    cms_not_found();
}

/**
 * Render one small error panel for an authenticated collection workflow.
 */
function viewer_collection_render_error(string $message, int $status = 400): void
{
    viewer_http_no_store();
    http_response_code($status);
    render_header(t('viewer.collections.title', 'Collections'));
    echo '<section class="panel"><h1>' . e(t('viewer.collections.title', 'Collections')) . '</h1><p>' . e($message) . '</p>';
    echo '<p><a class="button secondary" href="' . e(url_for('viewer_collections')) . '">' . e(t('viewer.collections.back', 'Back to collections')) . '</a></p></section>';
    render_footer();
}

/**
 * Render a compact viewer-only "Add to collection" chooser for an already-authorized source image.
 *
 * The caller is responsible for rendering this only after the source card has passed the normal
 * gallery/image authorization policy. The mutation service checks source authorization again.
 *
 * @param int $imageId Canonical image identifier.
 * @param array<int,array{id:int,title:string}> $collections Current viewer's owned collections.
 * @param string $className Optional presentation class.
 * @return string HTML markup.
 */
function render_viewer_collection_add_control_html(int $imageId, array $collections, string $className = ''): string
{
    if ($imageId <= 0) {
        return '';
    }

    $classes = trim('viewer-collection-add ' . $className);
    $html = '<details class="' . e($classes) . '"><summary class="viewer-collection-add-button" title="' . e(t('viewer.collections.add', 'Add to collection')) . '">';
    $html .= '<span aria-hidden="true">+</span><span class="visually-hidden">' . e(t('viewer.collections.add', 'Add to collection')) . '</span></summary>';
    $html .= '<div class="viewer-collection-add-menu"><strong>' . e(t('viewer.collections.add', 'Add to collection')) . '</strong>';
    if ($collections === []) {
        $html .= '<p>' . e(t('viewer.collections.create_first', 'Create a collection first.')) . '</p>';
        $html .= '<a class="button secondary" href="' . e(url_for('viewer_collections')) . '">' . e(t('viewer.collections.open', 'Open collections')) . '</a>';
    } else {
        $html .= '<form method="post" action="' . e(url_for('viewer_collection_item_add')) . '">';
        $html .= '<input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '">';
        $html .= '<input type="hidden" name="image_id" value="' . $imageId . '">';
        $html .= '<label class="visually-hidden" for="viewer-collection-image-' . $imageId . '">' . e(t('viewer.collections.title', 'Collections')) . '</label>';
        $html .= '<select id="viewer-collection-image-' . $imageId . '" name="collection_id" required>';
        foreach ($collections as $collection) {
            $collectionId = (int) ($collection['id'] ?? 0);
            if ($collectionId <= 0) {
                continue;
            }
            $html .= '<option value="' . $collectionId . '">' . e((string) ($collection['title'] ?? '')) . '</option>';
        }
        $html .= '</select><button type="submit" class="button secondary">' . e(t('viewer.collections.add_submit', 'Add')) . '</button></form>';
    }
    $html .= '</div></details>';
    return $html;
}
/**
 * Resolve one owned collection's current renderable item state without exposing denied metadata.
 *
 * @param int $viewerAccountId Authenticated viewer account identifier.
 * @param int $collectionId Owned collection identifier.
 * @return array{references:array,visible:array,visible_ids:array<int,int>,hidden_count:int}
 */
function viewer_collection_visible_state(int $viewerAccountId, int $collectionId): array
{
    $references = viewer_collection_item_references($viewerAccountId, $collectionId);
    $authorizedById = viewer_source_images_resolve_authorized(array_map(
        static fn (array $row): int => (int) $row['image_id'],
        $references
    ));
    $visible = [];
    foreach ($references as $reference) {
        $imageId = (int) $reference['image_id'];
        if (isset($authorizedById[$imageId])) {
            $visible[] = $authorizedById[$imageId];
        }
    }
    return [
        'references' => $references,
        'visible' => $visible,
        'visible_ids' => array_map(static fn (array $resolved): int => (int) $resolved['image']['id'], $visible),
        'hidden_count' => max(0, count($references) - count($visible)),
    ];
}

/**
 * Render one bounded move-up/down form without serializing the whole collection into every card.
 *
 * @param int $collectionId Owned collection identifier.
 * @param int $imageId Current visible image identifier.
 * @param string $direction Either up or down.
 */
function viewer_collection_render_reorder_form(int $collectionId, int $imageId, string $direction, string $label, string $className): void
{
    echo '<form class="' . e($className) . '" method="post" action="' . e(url_for('viewer_collection_reorder', ['collection_id' => $collectionId])) . '">';
    echo '<input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '">';
    echo '<input type="hidden" name="move_image_id" value="' . $imageId . '">';
    echo '<input type="hidden" name="move_direction" value="' . e($direction) . '">';
    echo '<button type="submit" class="button secondary small">' . e($label) . '</button></form>';
}

/**
 * Render/list private collections and accept collection creation.
 */
function cms_viewer_collections(): void
{
    $viewer = viewer_collection_require_viewer();
    if ($viewer === null) {
        return;
    }

    $message = '';
    $error = '';
    if (request_method() === 'POST') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }
        if (!viewer_collection_create_nonce_consume((string) ($_POST['collection_form_nonce'] ?? ''))) {
            viewer_collection_render_error(t('viewer.common.invalid_request_message', 'The request could not be verified. Please reopen the page and try again.'), 400);
            return;
        }
        $result = viewer_collection_create($viewer, (string) ($_POST['title'] ?? ''));
        if (!empty($result['ok'])) {
            redirect_to(url_for('viewer_collection', ['collection_id' => (int) $result['collection_id']]));
        }
        $reason = (string) ($result['reason'] ?? 'unavailable');
        $error = match ($reason) {
            'invalid_title' => t('viewer.collections.invalid_title', 'Enter a plain-text collection title of at most 120 characters.'),
            'quota' => t('viewer.collections.collection_quota_reached', 'Your collection limit has been reached.'),
            'rate_limited' => t('viewer.collections.rate_limited', 'Too many collections were created recently. Please try again later.'),
            'account_unavailable' => t('viewer.collections.unavailable', 'Collections are temporarily unavailable.'),
            default => t('viewer.collections.unavailable', 'Collections are temporarily unavailable.'),
        };
    } elseif (request_method() !== 'GET') {
        viewer_collection_render_not_found();
        return;
    }

    $collections = viewer_collections_for_owner((int) $viewer['id']);
    $quota = viewer_content_quota_config();
    $nonce = viewer_collection_create_nonce_issue();

    render_header(t('viewer.collections.title', 'Collections'));
    echo '<section class="hero panel"><div class="hero-content"><div><p class="eyebrow">' . e(t('viewer.collections.private_label', 'Private viewer content')) . '</p><h1>' . e(t('viewer.collections.title', 'Collections')) . '</h1>';
    echo '<p>' . e(t('viewer.collections.help', 'Collections are private ordered lists of photo references. They never grant access to source galleries.')) . '</p></div>';
    echo '<div class="hero-meta"><div class="hero-actions"><a class="button secondary" href="' . e(url_for('viewer_account')) . '">' . e(t('viewer.collections.back_to_account', 'Account')) . '</a></div></div></div></section>';

    if ($message !== '') {
        echo '<section class="panel"><p>' . e($message) . '</p></section>';
    }
    if ($error !== '') {
        echo '<section class="panel"><p class="error">' . e($error) . '</p></section>';
    }

    echo '<section class="panel viewer-collection-create"><h2>' . e(t('viewer.collections.create_title', 'Create collection')) . '</h2>';
    echo '<form method="post" action="' . e(url_for('viewer_collections')) . '" class="form-stack">';
    echo '<input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '">';
    echo '<input type="hidden" name="collection_form_nonce" value="' . e($nonce) . '">';
    echo '<label>' . e(t('viewer.collections.title_label', 'Title')) . '<input type="text" name="title" required maxlength="120" autocomplete="off"></label>';
    echo '<button type="submit" class="button">' . e(t('viewer.collections.create', 'Create collection')) . '</button></form>';
    echo '<p class="muted">' . e(t('viewer.collections.quota_status', '{count} of {limit} collections used.', [
        'count' => count($collections),
        'limit' => (int) $quota['max_viewer_collections_per_account'],
    ])) . '</p></section>';

    echo '<section class="panel"><h2>' . e(t('viewer.collections.yours', 'Your collections')) . '</h2>';
    if ($collections === []) {
        echo '<p>' . e(t('viewer.collections.empty', 'You have not created any collections yet.')) . '</p>';
    } else {
        echo '<div class="viewer-collection-list">';
        foreach ($collections as $collection) {
            $collectionId = (int) $collection['id'];
            echo '<article class="viewer-collection-list-item"><div class="viewer-collection-list-main"><h3><a href="' . e(url_for('viewer_collection', ['collection_id' => $collectionId])) . '">' . e((string) $collection['title']) . '</a></h3>';
            echo '<p class="muted">' . e(t('viewer.collections.item_count', '{count} items', ['count' => (int) $collection['item_count']])) . '</p></div>';
            echo '<div class="viewer-collection-list-actions"><a class="button secondary" href="' . e(url_for('viewer_collection', ['collection_id' => $collectionId])) . '">' . e(t('viewer.collections.open_one', 'Open')) . '</a>';
            echo '<form method="post" action="' . e(url_for('viewer_collection_rename', ['collection_id' => $collectionId])) . '" class="viewer-collection-inline-form"><input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '"><label class="visually-hidden" for="collection-title-' . $collectionId . '">' . e(t('viewer.collections.rename_title', 'New title')) . '</label><input id="collection-title-' . $collectionId . '" type="text" name="title" value="' . e((string) $collection['title']) . '" required maxlength="120"><button type="submit" class="button secondary">' . e(t('viewer.collections.rename', 'Rename')) . '</button></form>';
            echo '<form method="post" action="' . e(url_for('viewer_collection_delete', ['collection_id' => $collectionId])) . '" class="viewer-collection-delete-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.collections.delete_confirm', 'Delete this collection? The photographs themselves will not be deleted.')) . '"><input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '"><input type="hidden" name="confirm_delete_collection" value="1"><button type="submit" class="button danger">' . e(t('viewer.collections.delete', 'Delete')) . '</button></form></div></article>';
        }
        echo '</div>';
    }
    echo '</section>';
    render_footer();
}

/**
 * Render one owned private collection with live source authorization for every stored reference.
 */
function cms_viewer_collection(): void
{
    $viewer = viewer_collection_require_viewer();
    if ($viewer === null) {
        return;
    }
    if (request_method() !== 'GET') {
        viewer_collection_render_not_found();
        return;
    }
    $collectionId = viewer_collection_positive_id($_GET['collection_id'] ?? null);
    if ($collectionId <= 0) {
        viewer_collection_render_not_found();
        return;
    }
    $collection = viewer_collection_owned_get((int) $viewer['id'], $collectionId);
    if ($collection === null) {
        viewer_collection_render_not_found();
        return;
    }

    $state = viewer_collection_visible_state((int) $viewer['id'], $collectionId);
    $references = $state['references'];
    $visible = $state['visible'];
    $visibleIds = $state['visible_ids'];
    $hiddenCount = (int) $state['hidden_count'];

    render_header((string) $collection['title']);
    echo '<section class="hero panel"><div class="hero-content"><div><p class="eyebrow">' . e(t('viewer.collections.private_label', 'Private viewer content')) . '</p><h1>' . e((string) $collection['title']) . '</h1>';
    echo '<p>' . e(t('viewer.collections.detail_help', 'Only photos currently authorized by their source galleries are shown.')) . '</p></div><div class="hero-meta"><div class="hero-actions"><a class="button secondary" href="' . e(url_for('viewer_collections')) . '">' . e(t('viewer.collections.back', 'Back to collections')) . '</a></div></div></div></section>';

    echo '<section class="panel viewer-collection-manage"><div class="viewer-collection-manage-row"><form method="post" action="' . e(url_for('viewer_collection_rename', ['collection_id' => $collectionId])) . '" class="viewer-collection-inline-form"><input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '"><label>' . e(t('viewer.collections.rename_title', 'New title')) . '<input type="text" name="title" value="' . e((string) $collection['title']) . '" required maxlength="120"></label><button type="submit" class="button secondary">' . e(t('viewer.collections.rename', 'Rename')) . '</button></form>';
    echo '<form method="post" action="' . e(url_for('viewer_collection_delete', ['collection_id' => $collectionId])) . '" class="viewer-collection-delete-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.collections.delete_confirm', 'Delete this collection? The photographs themselves will not be deleted.')) . '"><input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '"><input type="hidden" name="confirm_delete_collection" value="1"><button type="submit" class="button danger">' . e(t('viewer.collections.delete', 'Delete')) . '</button></form></div></section>';

    if (function_exists(__NAMESPACE__ . '\\render_viewer_collection_share_owner_section')) {
        render_viewer_collection_share_owner_section($viewer, $collectionId);
    }

    if ($visible === []) {
        echo '<section class="panel"><p>' . e(count($references) > 0
            ? t('viewer.collections.none_accessible', 'No items in this collection are currently accessible.')
            : t('viewer.collections.no_items', 'This collection is empty.')) . '</p></section>';
    } else {
        echo '<section class="grid gallery-image-grid viewer-collection-grid">';
        foreach ($visible as $index => $resolved) {
            $image = $resolved['image'];
            $gallery = $resolved['gallery'];
            $imageId = (int) $image['id'];
            $bundle = thumbnail_bundle($image);
            $candidateSizes = array_values(array_filter(thumbnail_sizes(), static fn (int $size): bool => $size <= 960));
            if ($candidateSizes === []) {
                $candidateSizes = [300];
            }
            $title = public_image_display_title($image, $gallery);
            $imageUrl = image_public_url($image, $gallery);
            echo '<article class="image-card viewer-collection-item" data-image-id="' . $imageId . '"><div class="image-stage"><a class="image-preview-link" href="' . e($imageUrl) . '">' . public_thumbnail_render_picture_html($image, 300, $candidateSizes, '(min-width: 1100px) 25vw, (min-width: 700px) 33vw, 50vw', image_alt_text($image, $gallery, $index + 1), $index, $bundle) . '</a>';
            if ($title !== '') {
                echo '<div class="image-meta image-meta-overlay"><h2>' . e($title) . '</h2></div>';
            }
            echo '</div><div class="viewer-collection-item-actions">';
            echo '<form method="post" action="' . e(url_for('viewer_collection_item_remove', ['collection_id' => $collectionId])) . '"><input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '"><input type="hidden" name="image_id" value="' . $imageId . '"><button type="submit" class="button secondary small">' . e(t('viewer.collections.remove', 'Remove')) . '</button></form>';
            if ($index > 0) {
                viewer_collection_render_reorder_form($collectionId, $imageId, 'up', t('viewer.collections.move_up', 'Move up'), 'viewer-collection-order-form');
            }
            if ($index < count($visibleIds) - 1) {
                viewer_collection_render_reorder_form($collectionId, $imageId, 'down', t('viewer.collections.move_down', 'Move down'), 'viewer-collection-order-form');
            }
            echo '</div></article>';
        }
        echo '</section>';
    }
    if ($hiddenCount > 0) {
        echo '<p class="muted">' . e(t('viewer.collections.hidden_unavailable', 'Some saved collection items are currently unavailable.')) . '</p>';
    }
    render_footer();
}

/**
 * Rename one owned private collection.
 */
function cms_viewer_collection_rename(): void
{
    $viewer = viewer_collection_require_viewer();
    if ($viewer === null) return;
    if (request_method() !== 'POST') {
        viewer_collection_render_not_found();
        return;
    }
    if (!viewer_verify_csrf_or_render_error()) return;
    $collectionId = viewer_collection_positive_id($_GET['collection_id'] ?? null);
    if ($collectionId <= 0) {
        viewer_collection_render_not_found();
        return;
    }
    $result = viewer_collection_rename($viewer, $collectionId, (string) ($_POST['title'] ?? ''));
    if (!empty($result['ok'])) {
        redirect_to(url_for('viewer_collection', ['collection_id' => $collectionId]));
    }
    if (($result['reason'] ?? '') === 'not_found') {
        viewer_collection_render_not_found();
        return;
    }
    viewer_collection_render_error(($result['reason'] ?? '') === 'invalid_title'
        ? t('viewer.collections.invalid_title', 'Enter a plain-text collection title of at most 120 characters.')
        : t('viewer.collections.unavailable', 'Collections are temporarily unavailable.'), 400);
}

/**
 * Delete one owned private collection without deleting source media or favourites.
 */
function cms_viewer_collection_delete(): void
{
    $viewer = viewer_collection_require_viewer();
    if ($viewer === null) return;
    if (request_method() !== 'POST') {
        viewer_collection_render_not_found();
        return;
    }
    if (!viewer_verify_csrf_or_render_error()) return;
    $collectionId = viewer_collection_positive_id($_GET['collection_id'] ?? null);
    if ($collectionId <= 0 || (string) ($_POST['confirm_delete_collection'] ?? '') !== '1') {
        viewer_collection_render_error(t('viewer.common.invalid_request_message', 'The request could not be verified. Please reopen the page and try again.'), 400);
        return;
    }
    $result = viewer_collection_delete($viewer, $collectionId);
    if (!empty($result['ok'])) {
        redirect_to(url_for('viewer_collections'));
    }
    if (($result['reason'] ?? '') === 'not_found') {
        viewer_collection_render_not_found();
        return;
    }
    viewer_collection_render_error(t('viewer.collections.unavailable', 'Collections are temporarily unavailable.'), 503);
}

/**
 * Add one currently authorized source image reference to one owned collection.
 */
function cms_viewer_collection_item_add(): void
{
    $viewer = viewer_collection_require_viewer();
    if ($viewer === null) return;
    if (request_method() !== 'POST') {
        viewer_collection_render_not_found();
        return;
    }
    if (!viewer_verify_csrf_or_render_error()) return;
    $collectionId = viewer_collection_positive_id($_GET['collection_id'] ?? ($_POST['collection_id'] ?? null));
    $imageId = viewer_collection_positive_id($_POST['image_id'] ?? null);
    if ($collectionId <= 0 || $imageId <= 0) {
        viewer_collection_render_error(t('viewer.common.invalid_request_message', 'The request could not be verified. Please reopen the page and try again.'), 400);
        return;
    }
    $result = viewer_collection_item_add($viewer, $collectionId, $imageId);
    if (!empty($result['ok'])) {
        redirect_to(url_for('viewer_collection', ['collection_id' => $collectionId]));
    }
    if (($result['reason'] ?? '') === 'not_found') {
        viewer_collection_render_not_found();
        return;
    }
    $reason = (string) ($result['reason'] ?? 'unavailable');
    $message = match ($reason) {
        'source_forbidden' => t('viewer.collections.source_unavailable', 'This photo is not currently available to your viewer session.'),
        'quota' => t('viewer.collections.item_quota_reached', 'This collection has reached its item limit.'),
        default => t('viewer.collections.unavailable', 'Collections are temporarily unavailable.'),
    };
    viewer_collection_render_error($message, $reason === 'source_forbidden' ? 403 : ($reason === 'quota' ? 409 : 503));
}

/**
 * Remove one reference from one owned collection.
 */
function cms_viewer_collection_item_remove(): void
{
    $viewer = viewer_collection_require_viewer();
    if ($viewer === null) return;
    if (request_method() !== 'POST') {
        viewer_collection_render_not_found();
        return;
    }
    if (!viewer_verify_csrf_or_render_error()) return;
    $collectionId = viewer_collection_positive_id($_GET['collection_id'] ?? null);
    $imageId = viewer_collection_positive_id($_POST['image_id'] ?? null);
    if ($collectionId <= 0 || $imageId <= 0) {
        viewer_collection_render_error(t('viewer.common.invalid_request_message', 'The request could not be verified. Please reopen the page and try again.'), 400);
        return;
    }
    $result = viewer_collection_item_remove($viewer, $collectionId, $imageId);
    if (!empty($result['ok'])) {
        redirect_to(url_for('viewer_collection', ['collection_id' => $collectionId]));
    }
    if (($result['reason'] ?? '') === 'not_found') {
        viewer_collection_render_not_found();
        return;
    }
    viewer_collection_render_error(t('viewer.collections.unavailable', 'Collections are temporarily unavailable.'), 503);
}

/**
 * Transactionally reorder submitted references within one owned collection.
 */
function cms_viewer_collection_reorder(): void
{
    $viewer = viewer_collection_require_viewer();
    if ($viewer === null) return;
    if (request_method() !== 'POST') {
        viewer_collection_render_not_found();
        return;
    }
    if (!viewer_verify_csrf_or_render_error()) return;
    $collectionId = viewer_collection_positive_id($_GET['collection_id'] ?? null);
    $maxItems = (int) viewer_content_quota_config()['max_viewer_items_per_collection'];
    if ($collectionId <= 0) {
        viewer_collection_render_error(t('viewer.collections.invalid_order', 'The submitted collection order is invalid.'), 400);
        return;
    }

    $rawOrder = $_POST['image_ids'] ?? null;
    if (is_array($rawOrder)) {
        if (count($rawOrder) > $maxItems) {
            viewer_collection_render_error(t('viewer.collections.invalid_order', 'The submitted collection order is invalid.'), 400);
            return;
        }
    } else {
        $moveImageId = viewer_collection_positive_id($_POST['move_image_id'] ?? null);
        $direction = (string) ($_POST['move_direction'] ?? '');
        if ($moveImageId <= 0 || !in_array($direction, ['up', 'down'], true)) {
            viewer_collection_render_error(t('viewer.collections.invalid_order', 'The submitted collection order is invalid.'), 400);
            return;
        }
        $state = viewer_collection_visible_state((int) $viewer['id'], $collectionId);
        $rawOrder = $state['visible_ids'];
        $index = array_search($moveImageId, $rawOrder, true);
        if ($index === false) {
            viewer_collection_render_error(t('viewer.collections.invalid_order', 'The submitted collection order is invalid.'), 400);
            return;
        }
        $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($targetIndex < 0 || $targetIndex >= count($rawOrder)) {
            redirect_to(url_for('viewer_collection', ['collection_id' => $collectionId]));
        }
        [$rawOrder[$index], $rawOrder[$targetIndex]] = [$rawOrder[$targetIndex], $rawOrder[$index]];
    }

    $result = viewer_collection_reorder($viewer, $collectionId, $rawOrder);
    if (!empty($result['ok'])) {
        redirect_to(url_for('viewer_collection', ['collection_id' => $collectionId]));
    }
    if (($result['reason'] ?? '') === 'not_found') {
        viewer_collection_render_not_found();
        return;
    }
    $reason = (string) ($result['reason'] ?? 'unavailable');
    $status = in_array($reason, ['invalid_order', 'duplicate_item', 'foreign_item', 'oversized', 'quota_state_invalid'], true) ? 400 : 503;
    viewer_collection_render_error($status === 400
        ? t('viewer.collections.invalid_order', 'The submitted collection order is invalid.')
        : t('viewer.collections.unavailable', 'Collections are temporarily unavailable.'), $status);
}
