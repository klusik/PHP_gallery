<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_content_foundations.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Defines dormant viewer content-authorization, plain-text, and quota contracts.
 *
 * Responsibilities:
 *   - Re-evaluate source-image access from authoritative storage for every future reference use
 *   - Prevent administrator identity from becoming transferable viewer media permission
 *   - Validate future viewer-owned labels as bounded UTF-8 plain text without output escaping
 *   - Centralize bounded security/resource quotas for future favourites and collections
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Collections and favourites store references, never permissions.
 *   - No favourite, collection, or share mutation service exists in Phase 0.7.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use Throwable;
use function Gallery\Core\db;

/**
 * Return the centralized bounded quota contract for future viewer content mutations.
 *
 * These are security/resource boundaries. Future atomic mutation transactions must enforce
 * them under the same locks used for the corresponding write; UI-only enforcement is invalid.
 *
 * @return array{max_viewer_favourites_per_account:int,max_viewer_collections_per_account:int,max_viewer_items_per_collection:int,max_active_viewer_collection_shares_per_collection:int}
 */
function viewer_content_quota_config(): array
{
    $config = viewer_accounts_config();
    return [
        'max_viewer_favourites_per_account' => (int) $config['max_viewer_favourites_per_account'],
        'max_viewer_collections_per_account' => (int) $config['max_viewer_collections_per_account'],
        'max_viewer_items_per_collection' => (int) $config['max_viewer_items_per_collection'],
        'max_active_viewer_collection_shares_per_collection' => (int) $config['max_active_viewer_collection_shares_per_collection'],
    ];
}

/**
 * Return the future collection-title plain-text policy.
 *
 * @return array{max_characters:int,max_bytes:int,allow_empty:bool,bidi_controls:string}
 */
function viewer_collection_title_policy(): array
{
    return [
        'max_characters' => 120,
        'max_bytes' => 480,
        'allow_empty' => false,
        'bidi_controls' => 'reject',
    ];
}

/**
 * Validate bounded viewer-controlled plain text without modifying or escaping it.
 *
 * The validator deliberately performs no HTML escaping, Markdown parsing, URL detection, or
 * Unicode normalization. If ext-intl is absent, no normalization is claimed. Bidi formatting
 * controls are rejected to avoid misleading administrator/log presentation.
 *
 * @param string $value Candidate plain text.
 * @param int $maxCharacters Maximum Unicode code points.
 * @param int $maxBytes Maximum UTF-8 bytes.
 * @param bool $allowEmpty Whether an empty string is accepted.
 * @return array{valid:bool,reason:string,characters:int,bytes:int}
 */
function viewer_plain_text_validate(string $value, int $maxCharacters, int $maxBytes, bool $allowEmpty = true): array
{
    if ($maxCharacters < 1 || $maxBytes < 1) {
        throw new InvalidArgumentException('Viewer plain-text limits must be positive.');
    }

    $bytes = strlen($value);
    if ($bytes > $maxBytes) {
        return ['valid' => false, 'reason' => 'byte_limit', 'characters' => 0, 'bytes' => $bytes];
    }
    if (preg_match('//u', $value) !== 1) {
        return ['valid' => false, 'reason' => 'invalid_utf8', 'characters' => 0, 'bytes' => $bytes];
    }
    if (!$allowEmpty && $value === '') {
        return ['valid' => false, 'reason' => 'empty', 'characters' => 0, 'bytes' => 0];
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return ['valid' => false, 'reason' => 'ascii_control', 'characters' => 0, 'bytes' => $bytes];
    }
    if (preg_match('/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 1) {
        return ['valid' => false, 'reason' => 'bidi_control', 'characters' => 0, 'bytes' => $bytes];
    }

    $matches = preg_match_all('/./us', $value, $unused);
    if ($matches === false) {
        return ['valid' => false, 'reason' => 'invalid_utf8', 'characters' => 0, 'bytes' => $bytes];
    }
    $characters = (int) $matches;
    if ($characters > $maxCharacters) {
        return ['valid' => false, 'reason' => 'character_limit', 'characters' => $characters, 'bytes' => $bytes];
    }

    return ['valid' => true, 'reason' => 'valid', 'characters' => $characters, 'bytes' => $bytes];
}

/**
 * Validate one future viewer collection title under the canonical 120-code-point policy.
 *
 * @param string $title Candidate title exactly as it would be stored.
 * @return array{valid:bool,reason:string,characters:int,bytes:int}
 */
function viewer_collection_title_validate(string $title): array
{
    $policy = viewer_collection_title_policy();
    return viewer_plain_text_validate(
        $title,
        $policy['max_characters'],
        $policy['max_bytes'],
        $policy['allow_empty']
    );
}

/**
 * Resolve one source image under the current recipient/request authorization context.
 *
 * The image is loaded directly from authoritative storage and its gallery is force-refreshed.
 * Administrator identity is deliberately excluded from the authorization decision. Password
 * unlocks, current-request gallery share tokens, and NSFW guard state are evaluated through the
 * existing gallery-access mechanisms. A denied result carries no source metadata.
 *
 * @param int $imageId Source image identifier.
 * @return ?array{image:array,gallery:array} Authorized rows, or null when inaccessible.
 */
function viewer_source_image_resolve_authorized(int $imageId): ?array
{
    if ($imageId <= 0) {
        return null;
    }

    try {
        $stmt = db()->prepare('SELECT * FROM images WHERE id = ? LIMIT 1');
        $stmt->execute([$imageId]);
        $image = $stmt->fetch();
        if (!$image || (string) ($image['visibility'] ?? '') !== 'public') {
            return null;
        }

        $galleryId = (int) ($image['gallery_id'] ?? 0);
        if ($galleryId <= 0) {
            return null;
        }
        $gallery = find_gallery($galleryId, true);
        if (!$gallery || !visitor_can_access_gallery_without_admin_bypass($gallery)) {
            return null;
        }
        if (image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content_without_admin_bypass()) {
            return null;
        }

        return ['image' => $image, 'gallery' => $gallery];
    } catch (Throwable) {
        return null;
    }
}

/**
 * Decide whether the current request may save/reference one source image.
 *
 * @param int $imageId Source image identifier.
 * @return bool True only for independently authorized source media.
 */
function viewer_source_image_can_reference(int $imageId): bool
{
    return viewer_source_image_resolve_authorized($imageId) !== null;
}

/**
 * Decide whether the current request may render one previously stored source-image reference.
 *
 * Stored viewer references carry no permission snapshot. Rendering re-evaluates the same current
 * source authorization used when a reference is first created.
 *
 * @param int $imageId Previously stored source image identifier.
 * @return bool True only when the current request independently authorizes the source now.
 */
function viewer_source_image_can_render_reference(int $imageId): bool
{
    return viewer_source_image_resolve_authorized($imageId) !== null;
}

/**
 * Resolve multiple stored source-image references under the current request authorization context.
 *
 * Image rows are loaded in bounded batches and gallery access is evaluated once per referenced
 * gallery where possible. Administrator identity is deliberately excluded exactly as in the
 * single-image resolver. Missing, private, NSFW-restricted, or otherwise unauthorized references
 * are omitted without exposing their metadata.
 *
 * @param array<int,int|string> $imageIds Candidate canonical image identifiers.
 * @return array<int,array{image:array,gallery:array}> Authorized rows keyed by image id.
 */
function viewer_source_images_resolve_authorized(array $imageIds): array
{
    $orderedIds = [];
    foreach ($imageIds as $imageId) {
        $imageId = (int) $imageId;
        if ($imageId > 0 && !isset($orderedIds[$imageId])) {
            $orderedIds[$imageId] = true;
        }
    }
    if ($orderedIds === []) {
        return [];
    }

    try {
        $imagesById = [];
        foreach (array_chunk(array_keys($orderedIds), 200) as $idList) {
            $placeholders = implode(',', array_fill(0, count($idList), '?'));
            $stmt = db()->prepare(
                'SELECT * FROM images WHERE id IN (' . $placeholders . ') AND visibility = ?'
            );
            $stmt->execute(array_merge($idList, ['public']));
            foreach ($stmt->fetchAll() as $image) {
                $imageId = (int) ($image['id'] ?? 0);
                if ($imageId > 0) {
                    $imagesById[$imageId] = $image;
                }
            }
        }

        $galleries = [];
        foreach ($imagesById as $image) {
            $galleryId = (int) ($image['gallery_id'] ?? 0);
            if ($galleryId <= 0 || array_key_exists($galleryId, $galleries)) {
                continue;
            }
            $gallery = find_gallery($galleryId, true);
            if (!$gallery || !visitor_can_access_gallery_without_admin_bypass($gallery)) {
                $galleries[$galleryId] = null;
                continue;
            }
            $galleries[$galleryId] = $gallery;
        }

        $authorized = [];
        foreach (array_keys($orderedIds) as $imageId) {
            $image = $imagesById[$imageId] ?? null;
            if (!is_array($image)) {
                continue;
            }
            $galleryId = (int) ($image['gallery_id'] ?? 0);
            $gallery = $galleries[$galleryId] ?? null;
            if (!is_array($gallery)) {
                continue;
            }
            if (image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content_without_admin_bypass()) {
                continue;
            }
            $authorized[$imageId] = ['image' => $image, 'gallery' => $gallery];
        }
        return $authorized;
    } catch (Throwable) {
        return [];
    }
}
