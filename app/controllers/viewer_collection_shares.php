<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/viewer_collection_shares.php
 * Module Type: Controller Module
 *
 * Purpose:
 *   Exposes the Phase 3.0 owner and anonymous HTTP boundaries for unlisted read-only collection sharing.
 *
 * Responsibilities:
 *   - Render share create/replace/revoke controls inside the existing owned collection detail page
 *   - Carry a newly generated secret URL through one collection-scoped one-time session flash only
 *   - Exchange a reusable bearer token into a narrow collection-share session grant and 303 redirect
 *   - Render a clean token-free shared collection page after durable grant revalidation
 *   - Re-authorize every source image in recipient context without administrator bypass
 *   - Apply no-store, no-referrer, and noindex/nofollow policy to all share consumption pages
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Collection-share authority is read-only and never substitutes for current_viewer() or current_user().
 *   - The raw bearer token must never appear in application logs or post-exchange HTML.
 *
 * Last Updated:
 *   2026-08-19
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\absolute_public_url;
use function Gallery\Core\append_cms_head_extras;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\image_alt_text;
use function Gallery\Core\image_public_url;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Services\public_image_display_title;
use function Gallery\Services\public_thumbnail_render_picture_html;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_bundle;
use function Gallery\Services\thumbnail_sizes;
use function Gallery\Services\viewer_collection_share_exchange;
use function Gallery\Services\viewer_collection_share_replace;
use function Gallery\Services\viewer_collection_share_revoke;
use function Gallery\Services\viewer_collection_share_state;
use function Gallery\Services\viewer_collection_shared_read;
use function Gallery\Services\viewer_collection_shares_storage_available;
use function Gallery\Services\viewer_csrf_token;
use function Gallery\Services\viewer_source_images_resolve_authorized;

/**
 * Emit strict headers for both secret-bearing exchange and clean shared collection responses.
 */
function viewer_collection_share_public_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
}

/**
 * Redirect with 303 See Other after a successful bearer-token exchange or owner POST mutation.
 */
function viewer_collection_share_redirect_see_other(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/**
 * Return the collection-scoped flash key that may hold one newly generated secret URL.
 */
function viewer_collection_share_secret_flash_key(int $collectionId): string
{
    return 'viewer_collection_share_secret_' . $collectionId;
}

/**
 * Render the Share section inside one existing private owner collection page.
 *
 * The complete secret is consumed from a one-time flash only. Normal share state contains no
 * plaintext token and therefore can show only creation/expiry metadata plus replace/revoke controls.
 */
function render_viewer_collection_share_owner_section(array $viewer, int $collectionId): void
{
    $viewerAccountId = (int) ($viewer['id'] ?? 0);
    if ($viewerAccountId <= 0 || $collectionId <= 0) {
        return;
    }

    echo '<section class="panel viewer-collection-share"><h2>' . e(t('viewer.collection_share.title', 'Share this collection')) . '</h2>';
    if (!viewer_collection_shares_storage_available()) {
        echo '<p class="muted">' . e(t('viewer.collection_share.unavailable', 'Collection sharing is temporarily unavailable.')) . '</p></section>';
        return;
    }

    $share = viewer_collection_share_state($viewerAccountId, $collectionId);
    $newSecretUrl = flash_message(viewer_collection_share_secret_flash_key($collectionId));
    if ($newSecretUrl !== null && $newSecretUrl !== '') {
        echo '<p><strong>' . e(t('viewer.collection_share.created', 'Share link created')) . '</strong></p>';
        echo '<label>' . e(t('viewer.collection_share.link_label', 'Share link'))
            . '<input class="viewer-collection-share-url" type="url" readonly value="' . e($newSecretUrl) . '"></label>';
        echo '<p class="muted">' . e(t('viewer.collection_share.shown_once', 'This link is shown only once.')) . '</p>';
    }

    if ($share === null) {
        echo '<p>' . e(t('viewer.collection_share.help', 'Anyone with the link can open this collection. The link does not unlock protected source galleries. The link expires after 30 days.')) . '</p>';
        echo '<form method="post" action="' . e(url_for('viewer_collection_share_replace', ['collection_id' => $collectionId])) . '">';
        echo '<input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '">';
        echo '<button type="submit" class="button">' . e(t('viewer.collection_share.create', 'Create share link')) . '</button></form></section>';
        return;
    }

    echo '<p><strong>' . e(t('viewer.collection_share.active', 'Share link active')) . '</strong></p>';
    echo '<p class="muted">' . e(t('viewer.collection_share.created_at', 'Created: {date}', ['date' => (string) $share['created_at']])) . '<br>';
    echo e(t('viewer.collection_share.expires_at', 'Expires: {date}', ['date' => (string) $share['expires_at']])) . '</p>';
    if ($newSecretUrl === null || $newSecretUrl === '') {
        echo '<p class="muted">' . e(t('viewer.collection_share.not_redisplayed', 'For security, the complete link is shown only when it is created or replaced.')) . '</p>';
    }
    echo '<div class="viewer-collection-share-actions">';
    echo '<form method="post" action="' . e(url_for('viewer_collection_share_replace', ['collection_id' => $collectionId])) . '" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.collection_share.replace_confirm', 'Replace this share link? The previous link will stop working.')) . '">';
    echo '<input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '">';
    echo '<button type="submit" class="button secondary">' . e(t('viewer.collection_share.replace', 'Replace share link')) . '</button></form>';
    echo '<form method="post" action="' . e(url_for('viewer_collection_share_revoke', ['collection_id' => $collectionId])) . '" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.collection_share.revoke_confirm', 'Revoke this share link?')) . '">';
    echo '<input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '">';
    echo '<button type="submit" class="button danger">' . e(t('viewer.collection_share.revoke', 'Revoke share')) . '</button></form>';
    echo '</div></section>';
}

/**
 * Create or replace the current viewer's one collection share.
 */
function cms_viewer_collection_share_replace(): void
{
    $viewer = viewer_collection_require_viewer();
    if ($viewer === null) {
        return;
    }
    if (request_method() !== 'POST') {
        viewer_collection_render_not_found();
        return;
    }
    if (!viewer_verify_csrf_or_render_error()) {
        return;
    }
    $collectionId = viewer_collection_positive_id($_GET['collection_id'] ?? null);
    if ($collectionId <= 0) {
        viewer_collection_render_not_found();
        return;
    }

    $result = viewer_collection_share_replace($viewer, $collectionId);
    if (!empty($result['ok'])) {
        $secretUrl = absolute_public_url(url_for('viewer_collection_share_exchange', [
            'token' => (string) $result['token'],
        ]));
        flash_message(viewer_collection_share_secret_flash_key($collectionId), $secretUrl);
        viewer_collection_share_redirect_see_other(url_for('viewer_collection', ['collection_id' => $collectionId]));
    }
    $reason = (string) ($result['reason'] ?? 'unavailable');
    if ($reason === 'not_found') {
        viewer_collection_render_not_found();
        return;
    }
    if ($reason === 'rate_limited') {
        viewer_collection_render_error(t('viewer.collection_share.rate_limited', 'Too many share links were created recently. Please try again later.'), 429);
        return;
    }
    viewer_collection_render_error(t('viewer.collection_share.unavailable', 'Collection sharing is temporarily unavailable.'), 503);
}

/**
 * Revoke the current viewer's active share without requiring the secret token in the browser.
 */
function cms_viewer_collection_share_revoke(): void
{
    $viewer = viewer_collection_require_viewer();
    if ($viewer === null) {
        return;
    }
    if (request_method() !== 'POST') {
        viewer_collection_render_not_found();
        return;
    }
    if (!viewer_verify_csrf_or_render_error()) {
        return;
    }
    $collectionId = viewer_collection_positive_id($_GET['collection_id'] ?? null);
    if ($collectionId <= 0) {
        viewer_collection_render_not_found();
        return;
    }

    $result = viewer_collection_share_revoke($viewer, $collectionId);
    if (!empty($result['ok'])) {
        viewer_collection_share_redirect_see_other(url_for('viewer_collection', ['collection_id' => $collectionId]));
    }
    if (($result['reason'] ?? '') === 'not_found') {
        viewer_collection_render_not_found();
        return;
    }
    viewer_collection_render_error(t('viewer.collection_share.unavailable', 'Collection sharing is temporarily unavailable.'), 503);
}

/**
 * Exchange one raw reusable bearer URL into a narrow session grant, then remove the secret by 303 redirect.
 */
function cms_viewer_collection_share_exchange(): void
{
    viewer_collection_share_public_headers();
    if (request_method() !== 'GET') {
        cms_not_found();
        return;
    }
    $token = (string) ($_GET['token'] ?? '');
    $grant = viewer_collection_share_exchange($token);
    if ($grant === null) {
        cms_not_found();
        return;
    }
    viewer_collection_share_redirect_see_other(url_for('viewer_collection_shared', [
        'collection_id' => (int) $grant['collection_id'],
    ]));
}

/**
 * Render one clean token-free shared collection using live recipient-context source authorization.
 */
function cms_viewer_collection_shared(): void
{
    viewer_collection_share_public_headers();
    append_cms_head_extras('<meta name="robots" content="noindex,nofollow">');
    if (request_method() !== 'GET') {
        cms_not_found();
        return;
    }
    $collectionId = viewer_collection_positive_id($_GET['collection_id'] ?? null);
    if ($collectionId <= 0) {
        cms_not_found();
        return;
    }
    $shared = viewer_collection_shared_read($collectionId);
    if ($shared === null) {
        cms_not_found();
        return;
    }

    $collection = $shared['collection'];
    $references = $shared['references'];
    $authorizedById = viewer_source_images_resolve_authorized(array_map(
        static fn (array $reference): int => (int) $reference['image_id'],
        $references
    ));
    $visible = [];
    foreach ($references as $reference) {
        $imageId = (int) $reference['image_id'];
        if (isset($authorizedById[$imageId])) {
            $visible[] = $authorizedById[$imageId];
        }
    }
    $hiddenCount = max(0, count($references) - count($visible));

    render_header((string) $collection['title']);
    echo '<section class="hero panel"><div class="hero-content"><div><p class="eyebrow">' . e(t('viewer.collection_share.shared_label', 'Shared collection')) . '</p><h1>' . e((string) $collection['title']) . '</h1>';
    echo '<p>' . e(t('viewer.collection_share.shared_help', 'Only photos you are currently allowed to access from their source galleries are shown.')) . '</p></div></div></section>';

    if ($visible === []) {
        echo '<section class="panel"><p>' . e(count($references) > 0
            ? t('viewer.collection_share.none_available', 'No items in this shared collection are currently available to you.')
            : t('viewer.collection_share.empty', 'This shared collection is empty.')) . '</p></section>';
    } else {
        echo '<section class="grid gallery-image-grid viewer-collection-grid viewer-shared-collection-grid">';
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
            echo '</div></article>';
        }
        echo '</section>';
    }
    if ($hiddenCount > 0) {
        echo '<p class="muted">' . e(t('viewer.collection_share.some_unavailable', 'Some items in this collection are not currently available.')) . '</p>';
    }
    render_footer();
}
