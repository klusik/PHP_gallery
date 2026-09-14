<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/viewer_collection_shares.php
 * Module Type: View
 *
 * Purpose:
 *   Renders owner and recipient collection-sharing presentation from controller-prepared state.
 *
 * Responsibilities:
 *   - Render create/replace/revoke owner share controls
 *   - Render one-time newly generated secret URL presentation
 *   - Render clean token-free shared collection cards
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
 *   - Share authorization, token exchange, durable-grant validation, flash consumption,
 *     source-image authorization, URL generation, and CSRF issuance remain outside the view.
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

/** @param array<string,mixed> $viewModel Controller-prepared owner share state. */
function view_render_viewer_collection_share_owner_section(array $viewModel): void
{
    echo '<section class="panel viewer-collection-share"><h2>' . e(t('viewer.collection_share.title', 'Share this collection')) . '</h2>';
    if (empty($viewModel['available'])) {
        echo '<p class="muted">' . e(t('viewer.collection_share.unavailable', 'Collection sharing is temporarily unavailable.')) . '</p></section>';
        return;
    }

    $share = $viewModel['share'] ?? null;
    $newSecretUrl = (string) ($viewModel['new_secret_url'] ?? '');
    if ($newSecretUrl !== '') {
        echo '<p><strong>' . e(t('viewer.collection_share.created', 'Share link created')) . '</strong></p>';
        echo '<label>' . e(t('viewer.collection_share.link_label', 'Share link'))
            . '<input class="viewer-collection-share-url" type="url" readonly value="' . e($newSecretUrl) . '"></label>';
        echo '<p class="muted">' . e(t('viewer.collection_share.shown_once', 'This link is shown only once.')) . '</p>';
    }

    if (!is_array($share)) {
        echo '<p>' . e(t('viewer.collection_share.help', 'Anyone with the link can open this collection. The link does not unlock protected source galleries. The link expires after 30 days.')) . '</p>';
        echo '<form method="post" action="' . e((string) ($viewModel['replace_url'] ?? '')) . '">';
        echo '<input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '">';
        echo '<button type="submit" class="button">' . e(t('viewer.collection_share.create', 'Create share link')) . '</button></form></section>';
        return;
    }

    echo '<p><strong>' . e(t('viewer.collection_share.active', 'Share link active')) . '</strong></p>';
    echo '<p class="muted">' . e(t('viewer.collection_share.created_at', 'Created: {date}', ['date' => (string) ($share['created_at'] ?? '')])) . '<br>';
    echo e(t('viewer.collection_share.expires_at', 'Expires: {date}', ['date' => (string) ($share['expires_at'] ?? '')])) . '</p>';
    if ($newSecretUrl === '') {
        echo '<p class="muted">' . e(t('viewer.collection_share.not_redisplayed', 'For security, the complete link is shown only when it is created or replaced.')) . '</p>';
    }
    echo '<div class="viewer-collection-share-actions">';
    echo '<form method="post" action="' . e((string) ($viewModel['replace_url'] ?? '')) . '" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.collection_share.replace_confirm', 'Replace this share link? The previous link will stop working.')) . '">';
    echo '<input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '">';
    echo '<button type="submit" class="button secondary">' . e(t('viewer.collection_share.replace', 'Replace share link')) . '</button></form>';
    echo '<form method="post" action="' . e((string) ($viewModel['revoke_url'] ?? '')) . '" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.collection_share.revoke_confirm', 'Revoke this share link?')) . '">';
    echo '<input type="hidden" name="viewer_csrf_token" value="' . e((string) ($viewModel['csrf_token'] ?? '')) . '">';
    echo '<button type="submit" class="button danger">' . e(t('viewer.collection_share.revoke', 'Revoke share')) . '</button></form>';
    echo '</div></section>';
}

/** @param array<string,mixed> $viewModel Controller-prepared clean shared-collection state. */
function view_render_viewer_collection_shared(array $viewModel): void
{
    render_header((string) ($viewModel['title'] ?? ''));
    echo '<section class="hero panel"><div class="hero-content"><div><p class="eyebrow">' . e(t('viewer.collection_share.shared_label', 'Shared collection')) . '</p><h1>' . e((string) ($viewModel['title'] ?? '')) . '</h1>';
    echo '<p>' . e(t('viewer.collection_share.shared_help', 'Only photos you are currently allowed to access from their source galleries are shown.')) . '</p></div></div></section>';

    $cards = (array) ($viewModel['cards'] ?? []);
    if ($cards === []) {
        echo '<section class="panel"><p>' . e((string) ($viewModel['empty_message'] ?? '')) . '</p></section>';
    } else {
        echo '<section class="grid gallery-image-grid viewer-collection-grid viewer-shared-collection-grid">';
        foreach ($cards as $card) {
            echo '<article class="image-card viewer-collection-item" data-image-id="' . (int) ($card['image_id'] ?? 0) . '"><div class="image-stage"><a class="image-preview-link" href="' . e((string) ($card['image_url'] ?? '')) . '">' . (string) ($card['thumbnail_html'] ?? '') . '</a>';
            if ((string) ($card['title'] ?? '') !== '') {
                echo '<div class="image-meta image-meta-overlay"><h2>' . e((string) $card['title']) . '</h2></div>';
            }
            echo '</div></article>';
        }
        echo '</section>';
    }
    if ((int) ($viewModel['hidden_count'] ?? 0) > 0) {
        echo '<p class="muted">' . e(t('viewer.collection_share.some_unavailable', 'Some items in this collection are not currently available.')) . '</p>';
    }
    render_footer();
}
