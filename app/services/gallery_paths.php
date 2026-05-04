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
    if (!path_inside(galleries_root(), $path)) {
        throw new RuntimeException('Gallery path is outside the configured root.');
    }
    return $path;
}

/**
 * Resolve a future gallery path whose final directory may not exist yet.
 */
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
    if (!is_dir($parent) || !path_inside(galleries_root(), $parent)) {
        throw new RuntimeException('Gallery target parent is outside the configured root or does not exist.');
    }
    return $path;
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
