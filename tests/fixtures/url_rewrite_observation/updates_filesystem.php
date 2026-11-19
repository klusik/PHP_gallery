<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/url_rewrite_observation/updates_filesystem.php
 * Module Type: Test Fixture
 * Purpose: Model a marked declaration-only owner and pre-mutation write latch.
 * Responsibilities:
 *   - Advertise the supported writer invalidation contract.
 *   - Latch observation bypass before replacing owned temporary marker files.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

/** Advertise that this fixture invalidates observations before any mutation. */
const UPDATES_FILESYSTEM_INVALIDATES_REWRITE_OBSERVATIONS = true;

/**
 * Replace a temporary marker after permanently latching request writer activity.
 *
 * @param string $path Owned temporary rewrite-marker path.
 * @param string $contents Replacement fixture marker bytes.
 * @return void Writes bytes, or throws after latching when the write fails.
 */
function url_rewrite_fixture_marked_replace(string $path, string $contents): void
{
    $GLOBALS['cms_url_rewrite_writer_active'] = true;
    unset($GLOBALS['cms_url_rewrite_request_observation']);
    if (file_put_contents($path, $contents) === false) {
        throw new \RuntimeException('Unable to replace marked fixture policy.');
    }
    clearstatcache(true, $path);
}
