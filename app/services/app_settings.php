<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/app_settings.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   2026-05-04
 */

declare(strict_types=1);

/**
 * Application settings service.
 *
 * This module contains the small DB-backed settings helpers that were previously
 * defined in app/services.php. Keeping them in their own file makes dependencies
 * explicit for theme, favicon, updater, and admin UI code while preserving all
 * original public function names.
 */

/**
 * Return all DB gallery rows represented by one filesystem subtree.
 */


/**
 * Delete selected gallery folder subtrees from disk and the database.
 */


/**
 * Remove one directory tree while refusing to operate outside the configured root.
 */


/**
 * Physically move one gallery folder subtree and then make DB paths follow it.
 */


/**
 * Ensure all filesystem ancestors of one gallery folder exist as gallery rows.
 *
 * This prevents a third-level gallery from becoming a top-level gallery when it
 * was imported before its intermediate parent. The hierarchy is filesystem-first:
 * galleries/A/B/C should always be represented as A -> B -> C when the folders
 * exist under the configured gallery root.
 */


/**
 * Create gallery rows for selected discovered folders.
 */


/**
 * Import and scan selected folders, returning imported gallery IDs for follow-up work.
 */


/**
 * Import/update image rows for images directly inside one gallery folder.
 *
 * Child-folder images are intentionally ignored here because child folders are
 * represented as subgalleries with their own scans.
 */


/**
 * Normalize a PHP multi-upload array into validated image upload entries.
 */


/**
 * Return whether the server can decode HEIC/HEIF images for conversion.
 */


/**
 * Return whether the server can decode RAW/DNG images for conversion.
 */


/**
 * Human-readable upload error for admin notices and JSON responses.
 */


/**
 * Build a safe stored filename while keeping the original image extension.
 */


/**
 * Return an unused filename and absolute target path inside one gallery folder.
 */


/**
 * Move validated uploaded images into the gallery folder and scan the result.
 */


/**
 * Return database image ids for uploaded direct gallery filenames.
 */


/**
 * Store one uploaded gallery thumbnail outside the indexed image set.
 */


/**
 * Scan every imported gallery folder for new or changed direct images.
 */


/**
 * Thumbnail variants generated for web views.
 */


/**
 * Build a responsive srcset for the supported thumbnail sizes.
 */


/**
 * Build a responsive WebP srcset for thumbnails when WebP variants exist.
 */


/**
 * Build a responsive srcset for one thumbnail format.
 */


/**
 * Resolve the thumbs folder for a gallery and create it when requested.
 */


/**
 * Build the generated thumbnail filename for an image, size, and format.
 */


/**
 * Resolve one generated thumbnail path.
 */


/**
 * Return true when a thumbnail may be safely served directly as a static file.
 */


/**
 * Return the public URL for a gallery file when the configured gallery root is web-visible.
 */


/**
 * Return the best public URL for an image thumbnail, falling back to the source.
 */


/**
 * Return the URL that serves an already existing thumbnail without creating files.
 */


/**
 * Find an existing thumbnail to use when the requested variant has not been generated yet.
 */

/**
 * Build image markup with WebP source when the WebP thumbnails exist.
 */


/**
 * Return true when the server can create WebP thumbnails for this source without losing required EXIF data.
 */


/**
 * Return missing or stale thumbnail variant counts for one image without creating files.
 */


/**
 * Summarize pending thumbnail maintenance for the admin area without generating thumbnails.
 */

/**
 * Generate all configured thumbnails for direct images in one gallery.
 */


/**
 * Generate all configured thumbnails for every imported image.
 */


/**
 * Rebuild web-optimized thumbnails for one source image.
 */


/**
 * Rebuild missing or stale thumbnails and report created/skipped variants.
 */



/**
 * Return image IDs directly owned by the selected galleries.
 */


/**
 * Return every imported direct image ID in stable dashboard order.
 */


/**
 * Load a GD image resource from the supported source MIME types.
 */


/**
 * Resize an image to a maximum longer side and write a progressive JPEG.
 */


/**
 * Return true when the source contains EXIF that must survive WebP conversion.
 */


/**
 * Resize an image to WebP, preserving EXIF when the source has EXIF metadata.
 */


/**
 * Resize an image to WebP with GD for sources that do not need EXIF copying.
 */


/**
 * Resize a JPEG to WebP with Imagick while copying the EXIF profile.
 */


/**
 * Pick the first direct image as cover when the gallery has no explicit cover.
 */


/**
 * Return the gallery thumbnail asset path, if one was uploaded.
 */


/**
 * Return true when the uploaded gallery thumbnail column is available.
 */


/**
 * Gallery and theme background helpers are loaded from app/services/gallery_backgrounds.php.
 */

/**
 * Update a gallery's uploaded thumbnail asset path.
 */


/**
 * Rebuild parent_id links from filesystem folder nesting.
 */


/**
 * Return one gallery ID plus all descendant gallery IDs.
 */


/**
 * Return whether the admin features added after the initial schema are ready.
 */


/**
 * Return whether password-protected gallery columns are available.
 */


/**
 * Return true when one gallery has its own password policy.
 */


/**
 * Return the protected gallery that controls public access to this gallery.
 */


/**
 * Build the session key that records a public gallery unlock.
 */


/**
 * Return how long a public gallery unlock should last in this browser session.
 */


/**
 * Store a successful public unlock for this browser session.
 */


/**
 * Return true while a public gallery unlock is still fresh.
 */


/**
 * Return true when the current request token unlocks the controlling gallery.
 */


/**
 * Return whether an anonymous visitor may view one gallery branch now.
 */


/**
 * Return true when a public listing may include this gallery.
 */


/**
 * Create a share token, store its lookup hash, and keep an encrypted admin copy.
 */


/**
 * Revoke the share token for one gallery.
 */


/**
 * Return whether the encrypted share token display column exists.
 */


/**
 * Return the current share token for admin display, upgrading legacy plaintext rows.
 */


/**
 * Encrypt one share token using the local config secret.
 */


/**
 * Decrypt one stored share token, or null when it is not an encrypted value.
 */


/**
 * Derive a stable encryption key from the local application secret.
 */


/**
 * Public wrapper for the preferred direct cover image.
 */


/**
 * Return the explicit cover image or first direct image for one gallery.
 */


/**
 * Return a gallery thumbnail URL from an uploaded asset, if present.
 */


/**
 * Function `gallery_cover_collage_images` handles this scoped operation.
 */


/**
 * Build visual cover choices from the gallery subtree.
 */


/**
 * Apply a cover image path from gallery.json after images have been scanned.
 */



/**
 * Read one application setting with a fallback.
 */
function app_setting(string $key, ?string $default = null): ?string
{
    try {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        // Variable $value stores this steps working value.
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    } catch (PDOException) {
        return $default;
    }
}

/**
 * Upsert one application setting.
 */
function set_app_setting(string $key, string $value): void
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $stmt->execute([$key, $value, now_sql()]);
}

/**
 * Remove one or more application settings.
 */
function delete_app_settings(array $keys): void
{
    // $keys stores an intermediate value used by the surrounding gallery workflow.
    $keys = array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
    if ($keys === []) {
        return;
    }
    // $placeholders stores an intermediate value used by the surrounding gallery workflow.
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('DELETE FROM app_settings WHERE setting_key IN (' . $placeholders . ')');
    $stmt->execute($keys);
}

/**
 * Public site name shown in the header and browser title.
 */
function site_name(): string
{
    // Variable $name stores this steps working value.
    $name = trim((string) app_setting('site_name', 'Gallery CMS'));
    return $name !== '' ? $name : 'Gallery CMS';
}

/**
 * Return true when admin-only JavaScript diagnostics should be rendered.
 */
function dev_mode_enabled(): bool
{
    return app_setting('dev_mode_enabled', '0') === '1';
}

/**
 * Persist the admin-only JavaScript diagnostics switch.
 */
function set_dev_mode_enabled(bool $enabled): void
{
    set_app_setting('dev_mode_enabled', $enabled ? '1' : '0');
}

/**
 * Return gallery IDs whose admin tree rows should start collapsed.
 */
function collapsed_gallery_ids(): array
{
    // Variable $decoded stores this steps working value.
    $decoded = json_decode((string) app_setting('admin_collapsed_gallery_ids', '[]'), true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_unique(array_map('intval', $decoded)));
}

/**
 * Persist the admin gallery tree collapse state.
 */
function set_collapsed_gallery_ids(array $ids): void
{
    // Variable $ids stores this steps working value.
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    set_app_setting('admin_collapsed_gallery_ids', json_encode($ids, JSON_THROW_ON_ERROR));
}
