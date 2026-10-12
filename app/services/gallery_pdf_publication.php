<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_pdf_publication.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Keep browser-converted PDF child galleries private until conversion is complete.
 *
 * Responsibilities:
 *   - Identify only reserved physical child galleries bound to a source gallery
 *   - Read the small lifecycle identity fields without loading upload or rendering services
 *   - Reject visibility changes that would publish active, cancelled or unreconciled children
 *
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Conversion pages are public only after their source-side marker reaches complete.
 * Last Updated: 2026-10-12
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * Report whether a reserved PDF child must remain private under its source marker.
 *
 * A reserved child path with no readable matching marker fails closed. Ordinary
 * galleries outside the exact source-relative reserved path are not affected.
 *
 * @param array<string,mixed> $gallery Persisted gallery row being considered for public visibility.
 * @return bool True when the row belongs to an incomplete, cancelled or unreconciled PDF child.
 */
function gallery_pdf_publication_forces_private(array $gallery): bool
{
    $galleryId = (int) ($gallery['id'] ?? 0);
    $sourceGalleryId = (int) ($gallery['parent_id'] ?? 0);
    $folderPath = str_replace('\\', '/', trim((string) ($gallery['folder_path'] ?? ''), "\\/"));
    $childName = basename($folderPath);
    if ($galleryId < 1 || $sourceGalleryId < 1 || preg_match('/^pdf-pages-([a-f0-9]{64})$/D', $childName, $match) !== 1) {
        return false;
    }

    $sourceGallery = function_exists(__NAMESPACE__ . '\\find_gallery') ? find_gallery($sourceGalleryId, true) : null;
    if (!is_array($sourceGallery)) {
        return true;
    }
    $sourceFolder = str_replace('\\', '/', trim((string) ($sourceGallery['folder_path'] ?? ''), "\\/"));
    $expectedFolder = $sourceFolder !== '' ? $sourceFolder . '/' . $childName : $childName;
    $folderMatches = PHP_OS_FAMILY === 'Windows'
        ? strcasecmp($folderPath, $expectedFolder) === 0
        : $folderPath === $expectedFolder;
    if (!$folderMatches) {
        return false;
    }

    $sourcePath = gallery_abs_path((string) ($sourceGallery['folder_path'] ?? ''));
    $sourceRoot = realpath($sourcePath);
    $stateDirectory = $sourcePath . DIRECTORY_SEPARATOR . '.gallery-pdf-conversions';
    $statePath = $stateDirectory . DIRECTORY_SEPARATOR . $match[1] . '.json';
    // Match the conversion reader's per-marker bound while keeping this access-path guard independent of its upload and render services.
    // Type: int. Units: bytes. Scope: one source-side conversion marker read for a gallery visibility decision.
    // Consumers: gallery_pdf_publication_forces_private.
    // Rationale: large page acknowledgment maps need room, while a corrupted sidecar must not force an unbounded public-request read.
    $maximumMarkerBytes = \Gallery\Core\GALLERY_PDF_CONVERSION_MAX_MARKER_BYTES;
    if ($sourceRoot === false || !is_dir($sourceRoot) || is_link($sourcePath) || is_link($stateDirectory)
        || realpath($stateDirectory) !== $sourceRoot . DIRECTORY_SEPARATOR . '.gallery-pdf-conversions'
        || !is_file($statePath) || is_link($statePath) || (int) @filesize($statePath) > $maximumMarkerBytes) {
        return true;
    }

    $decoded = json_decode((string) @file_get_contents($statePath), true);
    if (!is_array($decoded) || ($decoded['format'] ?? '') !== 'php_gallery_pdf_conversion_v1'
        || (string) ($decoded['conversion_id'] ?? '') !== $match[1]
        || (int) ($decoded['source_gallery_id'] ?? 0) !== $sourceGalleryId
        || (int) ($decoded['gallery_id'] ?? 0) !== $galleryId
        || (int) ($decoded['child_gallery_id'] ?? 0) !== $galleryId
        || ($decoded['target_mode'] ?? '') !== 'child') {
        return true;
    }

    return !in_array((string) ($decoded['status'] ?? ''), ['complete'], true);
}

/**
 * Reject a requested non-private visibility for an incomplete PDF conversion child.
 *
 * @param array<string,mixed> $gallery Persisted gallery row receiving a visibility mutation.
 * @param string $visibility Requested storage visibility value, including legacy draft values.
 * @return void No value when the requested visibility is private or the gallery is unrestricted.
 * @throws RuntimeException When an incomplete or unreconciled conversion child would become non-private.
 */
function gallery_pdf_publication_assert_visibility_change(array $gallery, string $visibility): void
{
    if ($visibility !== 'private' && gallery_pdf_publication_forces_private($gallery)) {
        throw new RuntimeException(t('pdf.publication_incomplete', 'PDF page galleries must remain private until their browser conversion is complete and reconciled.'));
    }
}
