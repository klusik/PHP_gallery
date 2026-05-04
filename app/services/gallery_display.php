<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_display.php
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
 * Gallery display-preference service layer.
 *
 * This module keeps small public-facing gallery presentation flags together so
 * controllers do not need to repeat optional-column checks or fallback rules.
 */

/**
 * Return whether the database has the gallery filename-display migration applied.
 *
 * Runtime capability checks let the application keep rendering safely on servers
 * where code was uploaded before the administrator ran the newest migration.
 */
function gallery_filename_display_schema_ready(): bool
{
    try {
        // Variable $stmt stores this steps working value.
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'show_filenames'");
        return $stmt && (bool) $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
}

/**
 * Return true when one gallery explicitly allows uploaded file names in captions.
 *
 * The default is intentionally false. Gallery cards and lightbox metadata should
 * not expose raw uploaded filenames unless the admin opts in per gallery.
 */
function gallery_shows_filenames(array $gallery): bool
{
    return gallery_filename_display_schema_ready() && (int) ($gallery['show_filenames'] ?? 0) === 1;
}

/**
 * Return the public-facing image title for one image in one gallery.
 *
 * Manually authored image titles remain visible. Raw file names are used only
 * when the containing gallery has enabled filename display in gallery settings.
 */
function public_image_display_title(array $image, array $gallery): string
{
    // Variable $title stores the manually edited title candidate from the image record.
    $title = trim((string) ($image['title'] ?? ''));
    // Variable $filename stores the raw uploaded filename for gallery-level display decisions.
    $filename = trim((string) ($image['filename'] ?? ''));
    // Variable $filenameStem stores the uploaded filename without its extension.
    $filenameStem = trim((string) pathinfo($filename, PATHINFO_FILENAME));
    // Variable $titleLooksLikeFilename records whether older scans stored a filename-derived value as the image title.
    $titleLooksLikeFilename = $title !== '' && ($title === $filename || $title === $filenameStem);

    if ($title !== '' && !$titleLooksLikeFilename) {
        return $title;
    }
    if (!gallery_shows_filenames($gallery)) {
        return '';
    }
    return $title !== '' ? $title : $filename;
}
