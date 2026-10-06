<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/updates_path_safety.php
 * Module Type: Service Path Guard
 * Purpose: Keep updater filesystem operations inside the canonical installation root.
 * Responsibilities: Validate relative targets and every existing ancestor before updater reads or writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use function Gallery\Core\release_file_policy_is_safe_relative_path;

/**
 * Validate an updater target and each existing ancestor beneath the installation root.
 *
 * Missing descendants are allowed only when the caller is preparing a new target.
 * Existing symbolic links, junction redirects, and non-directory parents are rejected.
 *
 * @param string $root Installation root that bounds the operation.
 * @param string $relativePath Safe project-relative path to inspect.
 * @param bool $allowMissing Whether the first missing segment may end validation successfully.
 * @return void No value is returned when the target stays within the installation root.
 * @throws RuntimeException When the root or target is missing, unsafe, or redirected.
 */
function application_update_assert_safe_target(string $root, string $relativePath, bool $allowMissing = false): void
{
    $canonicalRoot = realpath($root);
    if ($canonicalRoot === false || !is_dir($canonicalRoot)) {
        throw new RuntimeException('Updater filesystem root is unavailable.');
    }
    if (!release_file_policy_is_safe_relative_path($relativePath)) {
        throw new RuntimeException('Updater filesystem target is not a safe relative path.');
    }

    $segments = explode('/', $relativePath);
    $current = $canonicalRoot;
    foreach ($segments as $index => $segment) {
        $current .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($current)) {
            throw new RuntimeException('Updater refuses a symbolic link or redirected path in a managed target.');
        }
        if (!file_exists($current)) {
            if ($allowMissing) {
                return;
            }
            throw new RuntimeException('Updater target or one of its parent directories is missing.');
        }

        $resolved = realpath($current);
        $expected = $canonicalRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($segments, 0, $index + 1));
        if ($resolved === false || !application_update_paths_match($resolved, $expected)) {
            throw new RuntimeException('Updater refuses a redirected path outside its canonical installation location.');
        }

        $isFinalSegment = $index === count($segments) - 1;
        if (!$isFinalSegment && !is_dir($current)) {
            throw new RuntimeException('Updater target has a non-directory parent.');
        }
        if ($isFinalSegment && !is_file($current) && !is_dir($current)) {
            throw new RuntimeException('Updater target is not a regular file or directory.');
        }
    }
}

/**
 * Compare canonical filesystem paths using the host's path case rules.
 *
 * @param string $actual Canonical path returned by realpath.
 * @param string $expected Canonical path assembled from the installation root.
 * @return bool True when both paths identify the same lexical location.
 */
function application_update_paths_match(string $actual, string $expected): bool
{
    return DIRECTORY_SEPARATOR === '\\'
        ? strcasecmp($actual, $expected) === 0
        : $actual === $expected;
}
