<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/simbrief_ofp_conversion.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Convert a saved SimBrief OFP PDF into a separate private physical subgallery.
 *
 * Responsibilities:
 *   - Require an explicit administrator action and a supported PDF rasterizer
 *   - Stage all PDF pages with bounded size, page count, resolution and duration
 *   - Reuse the existing gallery creation and image indexing services
 *   - Preserve pre-existing child galleries and avoid duplicate regenerations
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 * Last Updated:
 *   2026-10-07
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;

const SIMBRIEF_OFP_CONVERSION_MAX_PAGES = 40;
const SIMBRIEF_OFP_CONVERSION_MAX_BYTES = 67108864;
const SIMBRIEF_OFP_CONVERSION_MAX_PIXELS = 9000000;
const SIMBRIEF_OFP_CONVERSION_MAX_SECONDS = 60;

/**
 * Check that a server-side PDF renderer is available without spawning commands.
 *
 * @return bool Whether this host can attempt optional OFP conversion.
 */
function simbrief_ofp_conversion_supported(): bool
{
    if (!class_exists(\Imagick::class)) {
        return false;
    }
    try {
        return in_array('PDF', \Imagick::queryFormats('PDF'), true)
            && in_array('JPEG', \Imagick::queryFormats('JPEG'), true);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Build the owned idempotency marker location for a generated child gallery.
 *
 * @param array<string,mixed> $gallery Generated gallery row.
 * @return string Absolute marker path.
 */
function simbrief_ofp_conversion_marker_path(array $gallery): string
{
    return gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . 'simbrief-ofp-pages.json';
}

/**
 * Return the existing generated subgallery without overwriting edited content.
 *
 * @param array<string,mixed> $parent Source gallery row.
 * @param string $childPath Exact reserved child folder path.
 * @return ?array<string,mixed> Existing generated child or null when not yet created.
 */
function simbrief_ofp_existing_generated_gallery(array $parent, string $childPath): ?array
{
    $existing = find_gallery_by_folder_path($childPath, true);
    if (!$existing) {
        if (file_exists(gallery_abs_path($childPath))) {
            throw new RuntimeException('The OFP subgallery folder already exists outside the gallery catalog. Reconcile it before conversion.');
        }
        return null;
    }

    $path = simbrief_ofp_conversion_marker_path($existing);
    $marker = is_file($path) && !is_link($path) && (int) filesize($path) < 8192
        ? json_decode((string) @file_get_contents($path), true)
        : null;
    if (!is_array($marker)
        || ($marker['format'] ?? '') !== 'php_gallery_simbrief_pages_v1'
        || (int) ($marker['source_gallery_id'] ?? 0) !== (int) $parent['id']
        || (int) ($existing['parent_id'] ?? 0) !== (int) $parent['id']) {
        throw new RuntimeException('The reserved OFP subgallery path is already occupied by unrelated gallery content. Existing data was not changed.');
    }
    return $existing;
}

/**
 * Rasterize a bounded original PDF into a private staging directory.
 *
 * @param string $pdfPath Validated original PDF path.
 * @param string $workRoot Temporary working folder in the protected cache.
 * @return list<string> Ordered JPEG page filenames inside the work directory.
 */
function simbrief_ofp_render_pdf_pages(string $pdfPath, string $workRoot): array
{
    if (!simbrief_ofp_conversion_supported()) {
        throw new RuntimeException('Server-side PDF conversion requires Imagick with PDF/JPEG delegate support. Original PDF viewing is still available.');
    }
    $startedAt = microtime(true);
    $probe = new \Imagick();
    try {
        $probe->pingImage($pdfPath);
        $pageCount = $probe->getNumberImages();
    } finally {
        $probe->clear();
        $probe->destroy();
    }
    if ($pageCount < 1 || $pageCount > SIMBRIEF_OFP_CONVERSION_MAX_PAGES) {
        throw new RuntimeException('The OFP PDF exceeds the supported page limit of ' . SIMBRIEF_OFP_CONVERSION_MAX_PAGES . ' pages.');
    }
    $paths = [];
    $totalBytes = 0;
    for ($page = 0; $page < $pageCount; ++$page) {
        if (microtime(true) - $startedAt > SIMBRIEF_OFP_CONVERSION_MAX_SECONDS) {
            throw new RuntimeException('OFP conversion exceeded the time budget.');
        }
        $image = new \Imagick();
        try {
            $image->setResolution(120, 120);
            $image->readImage($pdfPath . '[' . $page . ']');
            $image->setImageBackgroundColor('white');
            $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(82);
            $width = (int) $image->getImageWidth();
            $height = (int) $image->getImageHeight();
            if ($width < 1 || $height < 1 || $width * $height > SIMBRIEF_OFP_CONVERSION_MAX_PIXELS) {
                throw new RuntimeException('An OFP page exceeds the maximum supported resolution.');
            }
            $filename = sprintf('ofp-page-%03d.jpg', $page + 1);
            $path = $workRoot . DIRECTORY_SEPARATOR . $filename;
            if (!$image->writeImage($path) || !is_file($path)) {
                throw new RuntimeException('Could not render an OFP page.');
            }
            $size = (int) filesize($path);
            $totalBytes += $size;
            if ($size < 100 || $totalBytes > SIMBRIEF_OFP_CONVERSION_MAX_BYTES) {
                throw new RuntimeException('OFP conversion exceeded the image storage limit.');
            }
            $paths[] = $path;
        } finally {
            $image->clear();
            $image->destroy();
        }
    }
    return $paths;
}

/**
 * Create the private child gallery from the saved original PDF on explicit request.
 *
 * Repeated attempts return the existing marked child unchanged. This is deliberate:
 * publishing, changing or manually editing generated pages must never be undone by
 * a new SimBrief import or a repeated conversion request.
 *
 * @param array<string,mixed> $parent Source gallery.
 * @return array{created:bool,gallery_id:int,pages:int,url:string} Result and child gallery URL.
 */
function simbrief_ofp_create_private_subgallery(array $parent): array
{
    $writer = gallery_edit_writer_begin();
    $workRoot = '';
    $createdGalleryId = 0;
    try {
        $parent = find_gallery((int) ($parent['id'] ?? 0), true);
        if (!$parent) {
            throw new RuntimeException('The selected parent gallery does not exist.');
        }
        $pdfPath = simbrief_ofp_local_pdf_path($parent);
        if ($pdfPath === null) {
            throw new RuntimeException('This gallery has no saved, valid SimBrief PDF.');
        }

        $childPath = gallery_child_folder_path($parent, 'ofp-pages');
        $existing = simbrief_ofp_existing_generated_gallery($parent, $childPath);
        if ($existing !== null) {
            return [
                'created' => false,
                'gallery_id' => (int) $existing['id'],
                'pages' => count(glob(gallery_abs_path((string) $existing['folder_path']) . '/ofp-page-*.jpg') ?: []),
                'url' => \Gallery\Core\gallery_public_url($existing),
            ];
        }
        if (!simbrief_ofp_conversion_supported()) {
            throw new RuntimeException('PDF conversion is not supported by this hosting. Install Imagick with a PDF delegate.');
        }

        // Keep partial rasterization outside the served gallery tree.
        $cache = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($cache) || !is_writable($cache)) {
            throw new RuntimeException('The private cache is unavailable for OFP conversion.');
        }
        $workRoot = $cache . DIRECTORY_SEPARATOR . 'simbrief-ofp-' . bin2hex(random_bytes(12));
        if (!mkdir($workRoot, 0700)) {
            throw new RuntimeException('Could not prepare a private OFP working directory.');
        }
        $staged = simbrief_ofp_render_pdf_pages($pdfPath, $workRoot);

        // A private gallery cannot be accessed by guessing its slug, even before
        // the files are installed and scanned. The admin publishes it explicitly.
        $created = create_empty_gallery([
            'title' => 'Flight plan (OFP)',
            'folder_name' => 'ofp-pages',
            'description' => 'Pages generated from the saved SimBrief OFP. The original PDF remains attached to the parent gallery.',
            'visibility' => 'private',
            'parent_id' => (int) $parent['id'],
            'voting_enabled' => false,
            'show_filenames' => false,
        ]);
        $createdGalleryId = (int) ($created['id'] ?? 0);
        if ($createdGalleryId <= 0
            || (string) ($created['folder_path'] ?? '') !== $childPath
            || gallery_effective_visibility($created) !== 'private') {
            throw new RuntimeException('Could not guarantee a private OFP subgallery at the expected folder.');
        }

        $targetRoot = gallery_abs_path($childPath);
        foreach ($staged as $source) {
            $target = $targetRoot . DIRECTORY_SEPARATOR . basename($source);
            if (!rename($source, $target)) {
                throw new RuntimeException('Could not transfer a rendered OFP page into the new gallery.');
            }
        }
        $scanned = scan_gallery_images($createdGalleryId);
        if ($scanned !== count($staged)) {
            throw new RuntimeException('Not all OFP pages were indexed as gallery photos.');
        }
        $marker = [
            'format' => 'php_gallery_simbrief_pages_v1',
            'source_gallery_id' => (int) $parent['id'],
            'pdf_sha256' => hash_file('sha256', $pdfPath),
            'page_count' => count($staged),
            'generated_at' => gmdate('c'),
        ];
        if (@file_put_contents(simbrief_ofp_conversion_marker_path($created), json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not store the OFP generation marker.');
        }
        return [
            'created' => true,
            'gallery_id' => $createdGalleryId,
            'pages' => count($staged),
            'url' => \Gallery\Core\gallery_public_url($created),
        ];
    } catch (Throwable $exception) {
        if ($createdGalleryId > 0) {
            try {
                delete_gallery_subtrees([$createdGalleryId]);
            } catch (Throwable) {
                // Never attempt deletion of arbitrary paths; the owner can repair
                // a failed private child through standard maintenance controls.
            }
        }
        throw $exception;
    } finally {
        if ($workRoot !== '' && is_dir($workRoot)) {
            foreach (new \DirectoryIterator($workRoot) as $entry) {
                if ($entry->isFile() && !$entry->isLink()) {
                    @unlink($entry->getPathname());
                }
            }
            @rmdir($workRoot);
        }
        gallery_edit_writer_end($writer);
    }
}
