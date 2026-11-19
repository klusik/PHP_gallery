<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/url_rewrite_observation/update_server_policy_reconciliation.php
 * Module Type: Test Fixture
 * Purpose: Model a late-loaded policy owner during rewrite observation tests.
 * Responsibilities:
 *   - Supply an explicit included-file boundary with the production owner basename.
 *   - Replace only caller-owned temporary marker files through a typed mock.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Replace a fixture marker through the supported writer's entry point.
 *
 * @param string $source Fixture policy bytes to copy.
 * @param string $destination Owned temporary marker path to replace.
 * @return void Copies the bytes or throws when fixture setup fails.
 */
function application_update_server_policy_replace_file(
    string $source,
    string $destination,
): void {
    if (!copy($source, $destination)) {
        throw new \RuntimeException('Unable to replace fixture marker.');
    }
    clearstatcache(true, $destination);
}
