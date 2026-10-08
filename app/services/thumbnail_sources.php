<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_sources.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Provides thumbnail size, path, URL, and srcset helpers.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one admin or thumbnail responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   2026-05-12
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\base_url;
use function Gallery\Core\image_public_asset_url_with_version;
use function Gallery\Core\image_public_media_url;
use function Gallery\Core\image_public_thumbnail_url;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\url_for;
use function Gallery\Models\image_model_thumbnail_identity_candidates;

/** Bound the number of images sent in one ownership lookup batch.
 * Type: int. Units: images. Scope: one gallery ownership batch.
 * Consumers: thumbnail_source_identity_preload. Rationale: bound indexed query parameter counts.
 */
const THUMBNAIL_IDENTITY_BATCH_IMAGES = 128;
/** Limit candidate observations before an overloaded ownership batch is refused.
 * Type: int. Units: rows. Scope: one candidate query including overflow sentinel.
 * Consumers: thumbnail_source_identity_preload. Rationale: refuse dense collision sets without a gallery scan.
 */
const THUMBNAIL_IDENTITY_CANDIDATE_LIMIT = 4097;
/** Bound competing identities observed for an isolated thumbnail request.
 * Type: int. Units: rows. Scope: one isolated media ownership query.
 * Consumers: thumbnail_source_identity_preload. Rationale: cap single-photo competitor observations.
 */
const THUMBNAIL_IDENTITY_SINGLE_LIMIT = 129;

/**
 * Thumbnail generation model.
 *
 * This module owns thumbnail naming, thumbnail URLs, srcset generation, maintenance status, and image resize/write operations. It does not change gallery theme, favicon, or custom CSS settings.
 *
 * @return array Structured result data for the caller.
 */
function thumbnail_sizes(): array
{
    return [300, 600, 800, 960, 1280, 1600];
}

/**
 * Handles thumbnail srcset logic for the gallery application.
 *
 * @param mixed $image Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @return mixed Result produced by this operation.
 * @param mixed 600 Input used by this operation.
 * @param mixed 800] Input used by this operation.
 */
function thumbnail_srcset(array $image, array $sizes = [300, 600, 800]): string
{
    $format = function_exists('Gallery\\Services\\thumbnail_preferred_browser_format') ? thumbnail_preferred_browser_format() : 'jpg';
    return thumbnail_srcset_for_format($image, $sizes, $format);
}

/**
 * Handles thumbnail webp srcset logic for the gallery application.
 *
 * @param mixed $image Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @return mixed Result produced by this operation.
 * @param mixed 600 Input used by this operation.
 * @param mixed 800] Input used by this operation.
 */
function thumbnail_webp_srcset(array $image, array $sizes = [300, 600, 800]): string
{
    return thumbnail_srcset_for_format($image, $sizes, 'webp');
}

/**
 * Select authorized derivative candidates for one browser format.
 *
 * @param array<string,mixed> $image image input for this operation.
 * @param array<int,int> $sizes sizes input for this operation.
 * @param string $format format input for this operation.
 * @return string Result produced by this operation.
 */
function thumbnail_srcset_for_format(array $image, array $sizes, string $format): string
{
    if (!thumbnail_legacy_identity_owned($image)) {
        return '';
    }
    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = [];
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || !in_array($format, ['jpg', 'webp'], true)
        || (function_exists('Gallery\\Services\\thumbnail_policy_format_allowed') && !thumbnail_policy_format_allowed($format))) {
        return '';
    }
    if (function_exists('Gallery\\Services\\thumbnail_bound_filter_sizes')) {
        $sizes = thumbnail_bound_filter_sizes($sizes, $image, $gallery);
    }
    if (function_exists('Gallery\\Services\\thumbnail_metadata_schema_ready') && thumbnail_metadata_schema_ready()) {
        // $metadataRows stores renderable variants known from DB without touching thumbnail files.
        $metadataRows = thumbnail_metadata_renderable_rows($image, $sizes);
        foreach ($sizes as $size) {
            $size = (int) $size;
            if (isset($metadataRows[$format][$size])) {
                $entries[] = thumbnail_serving_url($image, $gallery, $size, $format) . ' ' . $size . 'w';
            }
        }
        return implode(', ', $entries);
    }

    // $sourceGeometry stores source dimensions used to reject wrong-ratio legacy candidates.
    $sourceGeometry = null;
    if (function_exists('Gallery\\Services\\thumbnail_source_geometry_dimensions')) {
        try {
            // $sourcePath stores the original file path used only for geometry validation.
            $sourcePath = image_abs_path($image, $gallery);
            if (is_file($sourcePath)) {
                $sourceGeometry = thumbnail_source_geometry_dimensions($sourcePath, $image);
            }
        } catch (Throwable) {
            $sourceGeometry = null;
        }
    }

    foreach ($sizes as $size) {
        // $size stores an intermediate value used by the surrounding gallery workflow.
        $size = (int) $size;
        if (!in_array($size, thumbnail_sizes(), true)) {
            continue;
        }
        try {
            // $targetPath stores one concrete generated thumbnail candidate.
            $targetPath = thumbnail_abs_path($image, $gallery, $size, $format);
            if (!is_file($targetPath)) {
                continue;
            }
            if (is_array($sourceGeometry) && function_exists('Gallery\\Services\\thumbnail_file_geometry_status')) {
                // $geometryStatus stores whether this candidate preserves the source aspect ratio.
                $geometryStatus = thumbnail_file_geometry_status($targetPath, (int) $sourceGeometry['width'], (int) $sourceGeometry['height'], $size);
                if (empty($geometryStatus['valid'])) {
                    continue;
                }
            }
        } catch (RuntimeException) {
            continue;
        }
        $entries[] = thumbnail_serving_url($image, $gallery, $size, $format) . ' ' . $size . 'w';
    }
    return implode(', ', $entries);
}

/**
 * Handles gallery thumbs dir logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $create Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_thumbs_dir(array $gallery, bool $create = false): string
{
    // $galleryRoot stores the absolute gallery directory once so all later checks compare against the same base path.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    // $path stores the direct thumbnail cache directory used by generated responsive thumbnails.
    $path = $galleryRoot . DIRECTORY_SEPARATOR . 'thumbs';

    if ($create && !is_dir($path)) {
        mkdir($path, 0775, true);
    }

    // The old check used path_inside(), which requires the checked path to already exist.
    // That was correct for existing thumbnail folders, but it broke safe maintenance
    // workflows that need to inspect a future/non-existing thumbs directory first.
    if (!thumbnail_path_inside_existing_gallery($galleryRoot, $path)) {
        throw new RuntimeException(t('thumbnails.error_path_outside_gallery'));
    }

    return $path;
}

/**
 * Check whether a thumbnail path is safely contained by an existing gallery path.
 *
 * path_inside() intentionally uses realpath() for both arguments, which is very
 * strict and only works when both paths already exist. Thumbnail maintenance also
 * needs to reason about a `thumbs` directory that may not exist yet, especially
 * before deciding there is nothing to delete. This helper keeps the realpath()
 * protection for the gallery root, then normalizes the candidate path manually
 * so non-existing thumbnail directories can still be validated safely.
 *
 * @param string $galleryRoot Gallery root value.
 * @param string $thumbnailPath Thumbnail path filesystem path.
 * @return bool True when the condition matches.
 */
function thumbnail_path_inside_existing_gallery(string $galleryRoot, string $thumbnailPath): bool
{
    // $galleryRootReal is the trusted existing directory boundary.
    $galleryRootReal = realpath($galleryRoot);
    if ($galleryRootReal === false || !is_dir($galleryRootReal)) {
        return false;
    }

    // First enforce lexical ownership, including for destinations not yet created.
    $candidatePath = normalize_filesystem_path($thumbnailPath);
    $lexicalRoot = normalize_filesystem_path($galleryRoot);
    if ($candidatePath !== $lexicalRoot
        && !str_starts_with($candidatePath, rtrim($lexicalRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return false;
    }

    // Existing targets and their nearest existing ancestors need realpath() so
    // a symlinked thumbs directory or image cannot redirect I/O outside this gallery.
    // Walk only the candidate's ancestors; never create directories as a side effect.
    $existing = $thumbnailPath;
    while (!file_exists($existing) && !is_link($existing)) {
        $parent = dirname($existing);
        if ($parent === $existing) {
            return false;
        }
        $existing = $parent;
    }
    $resolved = realpath($existing);
    if ($resolved === false || ($existing !== $thumbnailPath && !is_dir($resolved))) {
        return false;
    }
    $canonicalPath = normalize_filesystem_path($resolved);
    $canonicalRoot = normalize_filesystem_path($galleryRootReal);
    return $canonicalPath === $canonicalRoot
        || str_starts_with($canonicalPath, rtrim($canonicalRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

/**
 * Normalize a filesystem path string without requiring the target to exist.
 *
 * This is deliberately small and local to thumbnail path safety. It resolves
 * duplicate separators, `.` segments, and `..` segments in the supplied string,
 * but it does not dereference symlinks. The trusted gallery root is still based
 * on realpath(), so symlink boundary protection remains anchored at the root.
 *
 * @param string $path Filesystem path.
 * @return string Text result for the caller.
 */
function normalize_filesystem_path(string $path): string
{
    // $path uses the current platform separator so later comparisons are consistent.
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    // $isAbsolute records whether the normalized result must keep an absolute root prefix.
    $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('~^[A-Za-z]:\\\\~', $path) === 1;
    // $prefix stores the leading root portion for Unix paths or Windows drive paths.
    $prefix = '';

    if (preg_match('~^[A-Za-z]:\\\\~', $path) === 1) {
        $prefix = substr($path, 0, 2);
        $path = substr($path, 2);
    } elseif ($isAbsolute) {
        $prefix = DIRECTORY_SEPARATOR;
    }

    // $segments stores the safe path components after resolving dot navigation.
    $segments = [];
    foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    // $normalized stores the path without duplicate separators or dot segments.
    $normalized = implode(DIRECTORY_SEPARATOR, $segments);
    if ($prefix === DIRECTORY_SEPARATOR) {
        return DIRECTORY_SEPARATOR . $normalized;
    }
    if ($prefix !== '') {
        return $prefix . DIRECTORY_SEPARATOR . $normalized;
    }
    return $normalized;
}

/**
 * Build a stable derivative filename for the selected source naming identity.
 *
 * @param array<string,mixed> $image image input for this operation.
 * @param int $size size input for this operation.
 * @param string $format format input for this operation.
 * @return string Result produced by this operation.
 */
function thumbnail_filename(array $image, int $size, string $format = 'jpg'): string
{
    if (!in_array($format, ['jpg', 'webp'], true)) {
        throw new RuntimeException(t('thumbnails.error_unsupported_format'));
    }
    return thumbnail_filename_stem($image) . '_thumb' . $size . '.' . $format;
}

/**
 * Return the selected derivative identity without asserting filesystem ownership.
 *
 * The readable prefix is bounded and filesystem-safe. The full path digest includes
 * the original extension and parent directories, so sibling names and nested sources
 * never share canonical derivatives. Existing rows retain their historical stem;
 * read and mutation boundaries separately verify that stem's exclusive ownership.
 * @param array<string,mixed> $image Source row or provisional rename row with its persisted naming marker.
 * @return string Selected legacy or canonical stem; this pure constructor grants no I/O permission.
 */
function thumbnail_filename_stem(array $image): string
{
    $version = thumbnail_source_identity_version($image);
    if ($version === 0) {
        return pathinfo((string) $image['filename'], PATHINFO_FILENAME);
    }
    if ($version !== 1) {
        throw new RuntimeException('Thumbnail source identity could not be verified.');
    }
    return thumbnail_canonical_filename_stem($image);
}

/** Build the bounded canonical stem without storage access or ownership recursion.
 * @param array<string,mixed> $image Source filename and complete gallery-relative path.
 * @return string Bounded readable stem followed by the full normalized-path SHA-256 digest.
 */
function thumbnail_canonical_filename_stem(array $image): string
{
    $relativePath = normalize_relative_path((string) ($image['relative_path'] ?? $image['filename'] ?? ''));
    if ($relativePath === '') {
        throw new RuntimeException('Missing thumbnail source identity.');
    }
    $readableStem = pathinfo(basename($relativePath), PATHINFO_FILENAME);
    $readableStem = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $readableStem), '_-');
    $readableStem = substr($readableStem !== '' ? $readableStem : 'image', 0, 48);
    return $readableStem . '_' . hash('sha256', $relativePath);
}

/** Return a persisted naming version, or the confirmed pre-migration legacy state.
 * @param array<string,mixed> $image Persisted image identity or provisional row with an explicit marker.
 * @return int|null Legacy zero, canonical one, or null when persisted identity cannot be verified.
 */
function thumbnail_source_identity_version(array $image): ?int
{
    if (array_key_exists('thumbnail_source_identity_version', $image)) {
        $version = (int) $image['thumbnail_source_identity_version'];
        return in_array($version, [0, 1], true) ? $version : null;
    }
    if (!function_exists(__NAMESPACE__ . '\\schema_inspection_column')) {
        return 0; // Pure naming compatibility only; I/O still requires verified ownership.
    }
    if ((int) ($image['id'] ?? 0) <= 0) {
        return 1;
    }
    $galleryId = (int) ($image['gallery_id'] ?? 0);
    $cache = &thumbnail_legacy_identity_request_cache();
    $imageId = (int) ($image['id'] ?? 0);
    if (!isset($cache['ids:' . $galleryId][$imageId])) {
        thumbnail_identity_candidates($galleryId, [$imageId], [], 2);
    }
    $cache = &thumbnail_legacy_identity_request_cache();
    $row = $cache['ids:' . $galleryId][(int) ($image['id'] ?? 0)] ?? null;
    $version = is_array($row) ? (int) $row['thumbnail_source_identity_version'] : -1;
    return in_array($version, [0, 1], true) ? $version : null;
}

/** Request-local bounded ownership observations; invalidate after image mutations.
 * @return array<int|string,mixed> Selected source rows and verified/refused ownership results for this request.
 */
function &thumbnail_legacy_identity_request_cache(): array
{
    static $cache = [];
    return $cache;
}

/** Clear ownership observations after source insert, rename, move, or deletion.
 * @return void
 */
function thumbnail_legacy_identity_cache_clear(): void
{
    $cache = &thumbnail_legacy_identity_request_cache();
    $cache = [];
}

/** Observe naming-marker availability without treating unknown as legacy.
 * @return bool|null Verified marker availability, confirmed legacy schema, or unknown.
 */
function thumbnail_identity_marker_available(): ?bool
{
    try {
        $status = schema_inspection_column('images', 'thumbnail_source_identity_version');
        return schema_inspection_is_unknown($status) ? null : schema_inspection_is_available($status);
    } catch (Throwable) {
        return null;
    }
}

/** Read a bounded indexed candidate set, retaining the cap sentinel for refusal.
 * @param int $galleryId Owning gallery identifier.
 * @param array<int,int> $imageIds Requested persisted source identifiers.
 * @param array<int,string> $stems Effective derivative stems whose competitors are required.
 * @param int $limit Maximum observed candidates including the overflow sentinel.
 * @return array<int,array<string,mixed>>|null Candidate rows, or null on failed observation.
 */
function thumbnail_identity_candidates(int $galleryId, array $imageIds, array $stems, int $limit): ?array
{
    $available = thumbnail_identity_marker_available();
    if ($available === null) {
        return null;
    }
    $hashes = [];
    foreach ($stems as $stem) {
        if (preg_match('/_([a-f0-9]{64})$/i', $stem, $match) === 1) {
            $hashes[] = strtolower($match[1]);
        }
    }
    try {
        $rows = image_model_thumbnail_identity_candidates($galleryId, $imageIds, $stems, array_values(array_unique($hashes)), $available, $limit);
        if (count($rows) >= $limit) {
            return null;
        }
        $cache = &thumbnail_legacy_identity_request_cache();
        foreach ($rows as $row) {
            $cache['ids:' . $galleryId][(int) $row['id']] = $row;
        }
        return $rows;
    } catch (Throwable) {
        return null;
    }
}

/** Key ownership by source identity so provisional and stale rows cannot reuse permission.
 * @param array<string,mixed> $image Persisted source identity with its naming version.
 * @return string Request-local ownership key.
 */
function thumbnail_identity_ownership_key(array $image): string
{
    return (int) ($image['gallery_id'] ?? 0) . ':' . (int) ($image['id'] ?? 0) . ':'
        . (string) ($image['thumbnail_source_identity_version'] ?? 'unresolved') . ':'
        . hash('sha256', (string) ($image['relative_path'] ?? $image['filename'] ?? '') . '|' . (string) ($image['filename'] ?? ''));
}

/** Preload exclusive derivative ownership in bounded batches rather than scanning galleries.
 * @param array<int,array<string,mixed>> $images Persisted sources about to render or mutate derivatives.
 * @return void Stores verified and refused results in the request-local ownership cache.
 */
function thumbnail_source_identity_preload(array $images): void
{
    $cache = &thumbnail_legacy_identity_request_cache();
    $groups = [];
    foreach ($images as $image) {
        $key = thumbnail_identity_ownership_key($image);
        if (array_key_exists($key, $cache['owned'] ?? [])) {
            continue;
        }
        $galleryId = (int) ($image['gallery_id'] ?? 0);
        $imageId = (int) ($image['id'] ?? 0);
        $cache['owned'][$key] = false;
        if ($galleryId > 0 && $imageId > 0) {
            $groups[$galleryId][$imageId] = $image;
        }
    }
    foreach ($groups as $galleryId => $group) {
        foreach (array_chunk($group, THUMBNAIL_IDENTITY_BATCH_IMAGES, true) as $batch) {
            $missingIds = [];
            foreach ($batch as $imageId => $image) {
                if (!array_key_exists('thumbnail_source_identity_version', $image)
                    && !isset($cache['ids:' . $galleryId][$imageId])) {
                    $missingIds[] = $imageId;
                }
            }
            if ($missingIds !== [] && thumbnail_identity_candidates($galleryId, $missingIds, [], count($missingIds) + 1) === null) {
                continue;
            }
            $stems = [];
            $prepared = [];
            foreach ($batch as $imageId => $image) {
                $ownershipKey = thumbnail_identity_ownership_key($image);
                $version = thumbnail_source_identity_version($image);
                if ($version === null) { continue; }
                $image['thumbnail_source_identity_version'] = $version;
                try {
                    $stem = thumbnail_filename_stem($image);
                } catch (RuntimeException) {
                    continue;
                }
                if ($stem === '' || basename($stem) !== $stem || str_contains($stem, '\\') || thumbnail_identity_case_key($stem) === null) {
                    continue;
                }
                $stems[] = $stem;
                $prepared[$imageId] = ['image' => $image, 'stem' => $stem, 'key' => $ownershipKey];
            }
            if ($prepared === []) { continue; }
            $limit = min(THUMBNAIL_IDENTITY_CANDIDATE_LIMIT, max(THUMBNAIL_IDENTITY_SINGLE_LIMIT, count($prepared) * 16 + 1));
            $rows = thumbnail_identity_candidates($galleryId, array_keys($prepared), array_values(array_unique($stems)), $limit);
            if ($rows === null) { continue; }
            foreach ($prepared as $imageId => $entry) {
                $matches = [];
                $complete = true;
                foreach ($rows as $row) {
                    $version = (int) ($row['thumbnail_source_identity_version'] ?? -1);
                    if (!in_array($version, [0, 1], true)) { $complete = false; break; }
                    $rowStem = $version === 1 ? thumbnail_canonical_filename_stem($row) : pathinfo((string) $row['filename'], PATHINFO_FILENAME);
                    $match = preg_match('/^' . preg_quote($rowStem, '/') . '$/iuD', $entry['stem']);
                    if ($match === false) { $complete = false; break; }
                    if ($match === 1) { $matches[] = $row; }
                }
                $memberVerified = false;
                foreach ($matches as $match) {
                    if ((int) $match['id'] === $imageId
                        && (int) $match['thumbnail_source_identity_version'] === $entry['image']['thumbnail_source_identity_version']
                        && (string) $match['filename'] === (string) ($entry['image']['filename'] ?? '')
                        && normalize_relative_path((string) $match['relative_path']) === normalize_relative_path((string) ($entry['image']['relative_path'] ?? $entry['image']['filename'] ?? ''))) {
                        $memberVerified = true;
                    }
                }
                if ($complete && $memberVerified) {
                    $cache['invalidate'][$entry['key']] = $entry['stem'];
                }
                $cache['owned'][$entry['key']] = $complete && $memberVerified && count($matches) === 1;
            }
        }
    }
}

/** Verify effective derivative ownership across every indexed image and access class.
 * @param array<string,mixed> $image Current source row whose derivative access is being authorized.
 * @return bool Whether bounded storage evidence proves exclusive ownership of this source path.
 */
function thumbnail_legacy_identity_owned(array $image): bool
{
    thumbnail_source_identity_preload([$image]);
    $cache = &thumbnail_legacy_identity_request_cache();
    return $cache['owned'][thumbnail_identity_ownership_key($image)] ?? false;
}

/** Verify source membership for targeted invalidation, including confirmed shared artifacts.
 *
 * This permission never grants read or generation access. Removing a shared artifact
 * before a competing row disappears prevents its pixels acquiring a new unique owner.
 * @param array<string,mixed> $image Current source row selected for an explicit destructive action.
 * @return string Verified effective derivative stem, even when confirmed shared.
 */
function thumbnail_source_identity_invalidation_stem(array $image): string
{
    thumbnail_source_identity_preload([$image]);
    $cache = &thumbnail_legacy_identity_request_cache();
    $stem = $cache['invalidate'][thumbnail_identity_ownership_key($image)] ?? null;
    if (!is_string($stem)) {
        throw new RuntimeException('Thumbnail invalidation membership could not be verified.');
    }
    return $stem;
}

/** Unicode case folding protects case-insensitive storage; ASCII needs no extension.
 * @param string $stem Effective derivative stem to validate and fold for ownership indexing.
 * @return string|null Valid UTF-8 case key, or null when the stem cannot be safely compared.
 */
function thumbnail_identity_case_key(string $stem): ?string
{
    if (preg_match('//u', $stem) !== 1) {
        return null;
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($stem, 'UTF-8');
    }
    return strtolower($stem); // PCRE /iu resolves Unicode equivalence when mbstring is absent.
}

/** Assert ownership at derivative I/O boundaries, never during provisional naming.
 * @param array<string,mixed> $image Current persisted source row about to cross a derivative I/O boundary.
 * @return void Throws when exclusive ownership cannot be established.
 */
function thumbnail_assert_source_identity_owned(array $image): void
{
    if (!thumbnail_legacy_identity_owned($image)) {
        throw new RuntimeException('Thumbnail source ownership could not be verified.');
    }
}

/**
 * Resolve an allowed thumbnail path and reject existing out-of-gallery symlinks.
 *
 * A missing generated file is allowed so writers may create thumbnails later.
 *
 * @param array<string,mixed> $image Persisted source image naming identity.
 * @param array{folder_path:string} $gallery Physical gallery owning the source image.
 * @param int $size Supported thumbnail width.
 * @param string $format Supported output image format.
 * @return string Existing safe thumbnail file or a safe future destination path.
 */
function thumbnail_abs_path(array $image, array $gallery, int $size, string $format = 'jpg'): string
{
    if (!in_array($size, thumbnail_sizes(), true)) {
        throw new RuntimeException(t('thumbnails.error_unsupported_size'));
    }
    $thumbsDir = gallery_thumbs_dir($gallery, false);
    $path = $thumbsDir . DIRECTORY_SEPARATOR . thumbnail_filename($image, $size, $format);
    if ((file_exists($path) || is_link($path))
        && !thumbnail_path_inside_existing_gallery(gallery_abs_path((string) $gallery['folder_path']), $path)) {
        throw new RuntimeException(t('thumbnails.error_path_outside_gallery'));
    }
    return $path;
}

/**
 * Handles thumbnail can use static public url logic for the gallery application.
 *
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_can_use_static_public_url(array $image, array $gallery): bool
{
    return false;
}

/**
 * Handles gallery static file url logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $relativeFilePath Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_static_file_url(array $gallery, string $relativeFilePath): string
{
    // $galleryPath stores an intermediate value used by the surrounding gallery workflow.
    $galleryPath = normalize_relative_path((string) $gallery['folder_path']);
    // $filePath stores an intermediate value used by the surrounding gallery workflow.
    $filePath = normalize_relative_path($relativeFilePath);
    // $segments stores an intermediate value used by the surrounding gallery workflow.
    $segments = array_filter(explode('/', trim($galleryPath . '/' . $filePath, '/')), static fn (string $segment): bool => $segment !== '');
    // $encoded stores an intermediate value used by the surrounding gallery workflow.
    $encoded = array_map('rawurlencode', $segments);
    return base_url('galleries/' . implode('/', $encoded));
}

/**
 * Resolve an authorized thumbnail URL or source-media fallback.
 *
 * @param array<string,mixed> $image image input for this operation.
 * @param int $size size input for this operation.
 * @param string $format format input for this operation.
 * @return string Result produced by this operation.
 */
function thumbnail_url(array $image, int $size, string $format = ''): string
{
    static $cache = [];
    // $cacheKey stores repeated thumbnail URL lookups inside one request.
    $format = $format !== '' ? $format : (function_exists('Gallery\\Services\\thumbnail_preferred_browser_format') ? thumbnail_preferred_browser_format() : 'jpg');
    $normalizedFormat = $format === 'webp' ? 'webp' : 'jpg';
    $format = $normalizedFormat;
    if (function_exists('Gallery\\Services\\thumbnail_policy_format_allowed') && !thumbnail_policy_format_allowed($format)) {
        $format = function_exists('Gallery\\Services\\thumbnail_preferred_browser_format') ? thumbnail_preferred_browser_format() : 'webp';
        $normalizedFormat = $format === 'jpg' ? 'jpg' : 'webp';
    }
    $purpose = function_exists('Gallery\\Services\\public_render_profile_thumbnail_purpose') ? public_render_profile_thumbnail_purpose() : 'unprofiled';
    $cacheKey = (int) ($image['id'] ?? 0) . ':' . (int) $size . ':' . $normalizedFormat;
    if (array_key_exists($cacheKey, $cache)) {
        public_render_profile_count('thumbnail_lookup_cache_hits');
        public_render_profile_record_thumbnail_purpose($purpose, $size, $normalizedFormat, 'cache_hit');
        return $cache[$cacheKey];
    }
    public_render_profile_count('thumbnail_lookups');
    $startedAt = microtime(true);
    try {
        return $cache[$cacheKey] = public_render_profile_span('thumbnail_lookup', /** Return the selected authorized thumbnail URL. @return string */ static function () use ($image, $size, $format): string {
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if ($gallery) {
        if (!thumbnail_legacy_identity_owned($image)) {
            return public_path_schema_ready() ? image_public_media_url($image, $gallery) : image_public_asset_url_with_version(url_for('media', ['id' => $image['id']]), $image);
        }
        if (function_exists('Gallery\\Services\\thumbnail_bound_fallback_size')) {
            $size = thumbnail_bound_fallback_size($image, $size, $gallery);
        }
        if (function_exists('Gallery\\Services\\thumbnail_metadata_schema_ready') && thumbnail_metadata_schema_ready()) {
            // $selected stores the best DB-known thumbnail variant without probing thumbnail files.
            $selected = thumbnail_metadata_select_renderable_variant($image, thumbnail_sizes(), $size, $format, true);
            if ($selected !== null) {
                if (!empty($selected['is_exact'])) {
                    public_render_profile_count('thumbnail_direct_hits');
                } else {
                    public_render_profile_count('thumbnail_db_fallback_hits');
                }
                return thumbnail_serving_url($image, $gallery, (int) $selected['size'], (string) $selected['format']);
            }
            public_render_profile_count('thumbnail_media_fallbacks');
            return public_path_schema_ready() ? image_public_media_url($image, $gallery) : image_public_asset_url_with_version(url_for('media', ['id' => $image['id']]), $image);
        }
        // $sourceGeometry stores source dimensions used to reject invalid stale thumbnails before serving them.
        $sourceGeometry = null;
        if (function_exists('Gallery\\Services\\thumbnail_source_geometry_dimensions')) {
            try {
                // $sourcePath stores the original file path used only for geometry validation.
                $sourcePath = image_abs_path($image, $gallery);
                if (is_file($sourcePath)) {
                    $sourceGeometry = thumbnail_source_geometry_dimensions($sourcePath, $image);
                }
            } catch (Throwable) {
                $sourceGeometry = null;
            }
        }
        try {
            // $path stores an intermediate value used by the surrounding gallery workflow.
            $path = thumbnail_abs_path($image, $gallery, $size, $format);
            if (public_render_profile_is_file($path)) {
                if (is_array($sourceGeometry) && function_exists('Gallery\\Services\\thumbnail_file_geometry_status')) {
                    // $geometryStatus stores whether this cache file still matches the source aspect ratio.
                    $geometryStatus = thumbnail_file_geometry_status($path, (int) $sourceGeometry['width'], (int) $sourceGeometry['height'], $size);
                    if (empty($geometryStatus['valid'])) {
                        public_render_profile_count('thumbnail_invalid_geometry_provisional_hits');
                    } else {
                        public_render_profile_count('thumbnail_direct_hits');
                        return thumbnail_serving_url($image, $gallery, $size, $format);
                    }
                } else {
                    public_render_profile_count('thumbnail_direct_hits');
                    return thumbnail_serving_url($image, $gallery, $size, $format);
                }
            }
            // $fallback stores an intermediate value used by the surrounding gallery workflow.
            $fallback = thumbnail_existing_fallback($image, $gallery, $size, $format);
            if ($fallback !== null) {
                return thumbnail_serving_url($image, $gallery, $fallback['size'], $fallback['format']);
            }
        } catch (RuntimeException) {
            return public_path_schema_ready() ? image_public_media_url($image, $gallery) : image_public_asset_url_with_version(url_for('media', ['id' => $image['id']]), $image);
        }
        public_render_profile_count('thumbnail_media_fallbacks');
        return public_path_schema_ready() ? image_public_media_url($image, $gallery) : image_public_asset_url_with_version(url_for('media', ['id' => $image['id']]), $image);
    }
    public_render_profile_count('thumbnail_media_fallbacks');
    return image_public_asset_url_with_version(url_for('media', ['id' => $image['id']]), $image);
        });
    } finally {
        public_render_profile_record_thumbnail_purpose($purpose, $size, $normalizedFormat, 'lookup', (microtime(true) - $startedAt) * 1000);
    }
}

/**
 * Handles thumbnail serving url logic for the gallery application.
 *
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $size Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_serving_url(array $image, array $gallery, int $size, string $format = 'jpg'): string
{
    if (public_path_schema_ready()) {
        return image_public_thumbnail_url($image, $gallery, $size, $format);
    }
    if (thumbnail_can_use_static_public_url($image, $gallery)) {
        return image_public_asset_url_with_version(
            gallery_static_file_url($gallery, 'thumbs/' . thumbnail_filename($image, $size, $format)),
            $image
        );
    }
    return image_public_asset_url_with_version(url_for('thumb', ['id' => $image['id'], 'size' => $size, 'format' => $format]), $image);
}

/**
 * Find the closest derivative whose source ownership is verified.
 *
 * @param array<string,mixed> $image image input for this operation.
 * @param array<string,mixed> $gallery gallery input for this operation.
 * @param int $preferredSize preferredSize input for this operation.
 * @param string $preferredFormat preferredFormat input for this operation.
 * @return array<int,array<string,mixed>>|null Result produced by this operation.
 */
function thumbnail_existing_fallback(array $image, array $gallery, int $preferredSize, string $preferredFormat = 'jpg'): ?array
{
    if (!thumbnail_legacy_identity_owned($image)) {
        return null;
    }
    // Variable $sizes stores this steps working value.
    $sizes = thumbnail_sizes();
    if (function_exists('Gallery\\Services\\thumbnail_bound_filter_sizes')) {
        $sizes = thumbnail_bound_filter_sizes($sizes, $image, $gallery);
    }

    if (function_exists('Gallery\\Services\\thumbnail_metadata_schema_ready') && thumbnail_metadata_schema_ready()) {
        // $selected stores the closest renderable fallback known from DB metadata only.
        $selected = thumbnail_metadata_select_renderable_variant($image, $sizes, $preferredSize, $preferredFormat, true);
        if ($selected !== null) {
            public_render_profile_count('thumbnail_db_fallback_hits');
            return ['size' => (int) $selected['size'], 'format' => (string) $selected['format']];
        }
        return null;
    }

    public_render_profile_count('thumbnail_fallback_searches');
    return public_render_profile_span('thumbnail_fallback_search', static function () use ($image, $gallery, $preferredSize, $preferredFormat, $sizes): ?array {
        usort($sizes, static function (int $left, int $right) use ($preferredSize): int {
            return abs($left - $preferredSize) <=> abs($right - $preferredSize);
        });
        // Variable $formats stores this steps working value.
        $allowedFormats = function_exists('Gallery\\Services\\thumbnail_policy_requested_formats') ? thumbnail_policy_requested_formats() : ['webp'];
        $formats = array_values(array_unique(array_merge([$preferredFormat], $allowedFormats)));
        $formats = array_values(array_intersect($formats, $allowedFormats));

        // $sourceGeometry stores source dimensions used to reject invalid stale thumbnails before serving them.
        $sourceGeometry = null;
        if (function_exists('Gallery\\Services\\thumbnail_source_geometry_dimensions')) {
            try {
                // $sourcePath stores the original file path used only for geometry validation.
                $sourcePath = image_abs_path($image, $gallery);
                if (is_file($sourcePath)) {
                    $sourceGeometry = thumbnail_source_geometry_dimensions($sourcePath, $image);
                }
            } catch (Throwable) {
                $sourceGeometry = null;
            }
        }

        foreach ($sizes as $size) {
            foreach ($formats as $format) {
                if (!in_array($format, ['jpg', 'webp'], true)) {
                    continue;
                }
                public_render_profile_count('thumbnail_fallback_checks');
                try {
                    // $targetPath stores one existing generated thumbnail candidate.
                    $targetPath = thumbnail_abs_path($image, $gallery, (int) $size, $format);
                } catch (RuntimeException) {
                    continue;
                }
                if (public_render_profile_is_file($targetPath)) {
                    if (is_array($sourceGeometry) && function_exists('Gallery\\Services\\thumbnail_file_geometry_status')) {
                        // $geometryStatus stores whether this fallback candidate keeps the source aspect ratio.
                        $geometryStatus = thumbnail_file_geometry_status($targetPath, (int) $sourceGeometry['width'], (int) $sourceGeometry['height'], (int) $size);
                        if (empty($geometryStatus['valid'])) {
                            public_render_profile_count('thumbnail_fallback_invalid_geometry_provisional_hits');
                            continue;
                        }
                    }
                    public_render_profile_count('thumbnail_fallback_hits');
                    return ['size' => (int) $size, 'format' => $format];
                }
            }
        }
        return null;
    });
}
