<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_paths.php
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
Gallery path service helpers.
 *
 * This module contains only path normalization and filesystem boundary checks.
 * It deliberately does not create, update, or delete galleries, which keeps the
 * extraction low-risk and preserves the original behavior of the legacy loader.
 */

/**
 * Configured filesystem root where gallery folders are stored.
 */
function galleries_root(): string
{
    return rtrim((string) cms_config()['galleries_root'], DIRECTORY_SEPARATOR);
}

/**
 * Resolve a gallery's relative folder path to an absolute filesystem path.
 */
function gallery_abs_path(string $relativePath): string
{
    // Variable $relativePath stores this steps working value.
    $relativePath = normalize_relative_path($relativePath);
    // Variable $path stores this steps working value.
    $path = galleries_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!gallery_filesystem_path_inside_root($path)) {
        throw new RuntimeException(gallery_path_boundary_error_message('Gallery path is outside the configured root.', $path));
    }
    return $path;
}

/**
 * Resolve a future gallery path whose final directory may not exist yet.
 */


/**
 * Build detailed path diagnostics for admin logs.
 *
 * This helper is intentionally read-only. It records both raw and resolved
 * filesystem values so false positives from realpath(), missing folders,
 * path casing, trailing separators, or configured-root mistakes are visible
 * in the admin log context.
 */
function gallery_path_diagnostics(string $relativePath, string $label = 'gallery'): array
{
    // $root stores the configured gallery storage directory exactly as the application sees it.
    $root = galleries_root();
    // $rootReal stores the filesystem-resolved gallery storage directory when it exists.
    $rootReal = realpath($root);
    // $diagnostics stores values that help explain path validation failures.
    $diagnostics = [
        'label' => $label,
        'relative_path_raw' => $relativePath,
        'gallery_root_configured' => $root,
        'gallery_root_realpath' => $rootReal !== false ? $rootReal : null,
        'gallery_root_exists' => file_exists($root),
        'gallery_root_is_dir' => is_dir($root),
    ];

    try {
        // $normalized stores the relative gallery path after traversal cleanup.
        $normalized = normalize_relative_path($relativePath);
        // $absolute stores the absolute path built from the configured gallery root and normalized relative path.
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        // $parent stores the absolute parent directory, which is important when the target directory does not exist yet.
        $parent = dirname($absolute);
        // $pathReal stores the resolved target path when it already exists.
        $pathReal = realpath($absolute);
        // $parentReal stores the resolved parent path when the parent exists.
        $parentReal = realpath($parent);

        $diagnostics += [
            'relative_path_normalized' => $normalized,
            'absolute_path' => $absolute,
            'absolute_parent' => $parent,
            'absolute_path_realpath' => $pathReal !== false ? $pathReal : null,
            'absolute_parent_realpath' => $parentReal !== false ? $parentReal : null,
            'absolute_path_exists' => file_exists($absolute),
            'absolute_path_is_dir' => is_dir($absolute),
            'absolute_parent_exists' => file_exists($parent),
            'absolute_parent_is_dir' => is_dir($parent),
            'inside_root_by_realpath' => $rootReal !== false && $pathReal !== false ? path_inside($root, $absolute) : null,
            'parent_inside_root_by_realpath' => $rootReal !== false && $parentReal !== false ? path_inside($root, $parent) : null,
            'realpath_available_for_target' => $pathReal !== false,
            'realpath_available_for_parent' => $parentReal !== false,
        ];
    } catch (Throwable $exception) {
        $diagnostics['normalization_error'] = $exception->getMessage();
    }

    return $diagnostics;
}

function gallery_target_abs_path(string $relativePath): string
{
    // $relativePath stores an intermediate value used by the surrounding gallery workflow.
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '') {
        throw new RuntimeException('Gallery folder path cannot be empty.');
    }
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = galleries_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    // $parent stores an intermediate value used by the surrounding gallery workflow.
    $parent = dirname($path);
    if (!is_dir($parent) || !gallery_filesystem_path_inside_root($parent)) {
        throw new RuntimeException(gallery_path_boundary_error_message('Gallery target parent is outside the configured root or does not exist.', $parent));
    }
    if (!gallery_filesystem_path_inside_root($path)) {
        throw new RuntimeException(gallery_path_boundary_error_message('Gallery target path is outside the configured root.', $path));
    }
    return $path;
}


/**
 * Check a gallery filesystem path against the configured root, even if the
 * final target directory does not exist yet.
 *
 * path_inside() is intentionally strict and relies on realpath() for both
 * arguments. That is correct for existing files, but gallery moves need to
 * validate the destination before the destination directory exists. This helper
 * keeps realpath() anchoring for the configured gallery root and for existing
 * targets, while safely normalizing future target paths textually.
 */
function gallery_filesystem_path_inside_root(string $path): bool
{
    // $rootReal stores the trusted gallery storage root after symlink resolution.
    $rootReal = realpath(galleries_root());
    if ($rootReal === false || !is_dir($rootReal)) {
        return false;
    }

    // Existing targets are checked with realpath() so symlinks cannot escape the gallery root.
    $pathReal = realpath($path);
    if ($pathReal !== false) {
        return gallery_normalized_path_inside_root($rootReal, $pathReal);
    }

    // Future targets cannot be resolved by realpath(), so validate the existing parent first.
    $parentReal = realpath(dirname($path));
    if ($parentReal === false || !is_dir($parentReal)) {
        return false;
    }
    if (!gallery_normalized_path_inside_root($rootReal, $parentReal)) {
        return false;
    }

    // The candidate itself is normalized textually because it does not exist yet.
    return gallery_normalized_path_inside_root($rootReal, $path);
}

/**
 * Compare two filesystem paths after platform-consistent textual normalization.
 */
function gallery_normalized_path_inside_root(string $root, string $path): bool
{
    // $normalizedRoot stores the trusted root in comparable form.
    $normalizedRoot = gallery_normalize_filesystem_path($root);
    // $normalizedPath stores the candidate in comparable form.
    $normalizedPath = gallery_normalize_filesystem_path($path);

    if (DIRECTORY_SEPARATOR === '\\') {
        $normalizedRoot = strtolower($normalizedRoot);
        $normalizedPath = strtolower($normalizedPath);
    }

    return $normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, rtrim($normalizedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

/**
 * Normalize a filesystem path string without requiring the target to exist.
 */
function gallery_normalize_filesystem_path(string $path): string
{
    // $path uses the current platform separator so later comparisons are consistent.
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    // $isWindowsDrive records whether the path starts with a Windows drive prefix.
    $isWindowsDrive = preg_match('~^[A-Za-z]:\\\\~', $path) === 1;
    // $isAbsolute records whether the normalized result must keep an absolute root prefix.
    $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR) || $isWindowsDrive;
    // $prefix stores the leading root portion for Unix paths or Windows drive paths.
    $prefix = '';

    if ($isWindowsDrive) {
        $prefix = substr($path, 0, 2);
        $path = substr($path, 2);
    } elseif ($isAbsolute) {
        $prefix = DIRECTORY_SEPARATOR;
    }

    // $segments stores path components after duplicate separators and dot segments are resolved.
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
 * Build a path safety exception message with enough diagnostics for admin logs.
 */
function gallery_path_boundary_error_message(string $summary, string $path): string
{
    // $root stores the configured gallery storage root before realpath() normalization.
    $root = galleries_root();
    // $parent stores the target parent used to diagnose future paths.
    $parent = dirname($path);

    return $summary . ' Root: ' . $root
        . '; root realpath: ' . (realpath($root) ?: 'unavailable')
        . '; path: ' . $path
        . '; path realpath: ' . (realpath($path) ?: 'unavailable')
        . '; path exists: ' . (file_exists($path) ? 'yes' : 'no')
        . '; parent: ' . $parent
        . '; parent realpath: ' . (realpath($parent) ?: 'unavailable')
        . '; parent exists: ' . (file_exists($parent) ? 'yes' : 'no') . '.';
}

/**
 * Convert an admin-entered folder name into one safe directory segment.
 */
function gallery_folder_segment(string $value): string
{
    return slugify($value);
}

/**
 * Return the final path segment for a gallery folder path.
 */
function gallery_folder_name_from_path(string $folderPath): string
{
    // $segments stores an intermediate value used by the surrounding gallery workflow.
    $segments = explode('/', normalize_relative_path($folderPath));
    return (string) end($segments);
}

/**
 * Build a relative child folder path under an optional parent gallery.
 */
function gallery_child_folder_path(?array $parent, string $folderName): string
{
    // $segment stores an intermediate value used by the surrounding gallery workflow.
    $segment = gallery_folder_segment($folderName);
    if ($segment === '') {
        throw new RuntimeException('Gallery folder name cannot be empty.');
    }
    if (!$parent) {
        return $segment;
    }
    return normalize_relative_path((string) $parent['folder_path'] . '/' . $segment);
}

/**
 * Return an unused child folder path, appending a numeric suffix when needed.
 */
function unique_gallery_child_folder_path(?array $parent, string $folderName): string
{
    // $segment stores an intermediate value used by the surrounding gallery workflow.
    $segment = gallery_folder_segment($folderName);
    // $candidate stores an intermediate value used by the surrounding gallery workflow.
    $candidate = gallery_child_folder_path($parent, $segment);
    // $counter stores an intermediate value used by the surrounding gallery workflow.
    $counter = 2;
    while (is_dir(gallery_target_abs_path($candidate)) || find_gallery_by_folder_path($candidate)) {
        // $candidate stores an intermediate value used by the surrounding gallery workflow.
        $candidate = gallery_child_folder_path($parent, $segment . '-' . $counter);
        $counter++;
    }
    return $candidate;
}

/**
 * Resolve an image record to its absolute file path inside its gallery folder.
 */
function image_abs_path(array $image, array $gallery): string
{
    // Variable $galleryRoot stores this steps working value.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    // Variable $path stores this steps working value.
    $path = $galleryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, normalize_relative_path((string) $image['relative_path']));
    // Variable $parent stores this steps working value.
    $parent = dirname($path);
    if (!path_inside($galleryRoot, $parent)) {
        throw new RuntimeException('Image path is outside its gallery.');
    }
    return $path;
}
