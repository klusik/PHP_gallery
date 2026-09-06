<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_runs/paths.php
 * Module Type: Service
 *
 * Purpose:
 *   Resolves and validates every filesystem path owned by a test run.
 *
 * Responsibilities:
 *   - Validate run tokens before any path is derived from them
 *   - Resolve the run root, metadata, report, ZIP, and request directories
 *   - Expose the bounded public run identifier used in Admin output
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
 *   - Path note: this file lives one directory deeper than the module entry file,
 *     so project-root paths must use dirname(__DIR__, 3), not dirname(__DIR__, 2).
 *   - Loaded by app/services/admin_test_runs.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_test_runs.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\cms_config;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\url_for;

/**
 * Return the storage root for detailed Admin test runs.
 */
function admin_test_run_root(): string
{
    $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin-test-runs';
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create Admin test-run cache directory.');
    }
    return $root;
}

/**
 * Validate one opaque test-run token.
 */
function admin_test_run_token_valid(string $token): bool
{
    return preg_match('/^[a-f0-9]{32}$/D', strtolower($token)) === 1;
}

/**
 * Return a non-secret short identifier for display/report filenames.
 */
function admin_test_run_public_run_id(string $token): string
{
    return substr(hash('sha256', 'admin-test-run-id:' . $token), 0, 8);
}

/**
 * Return the directory for one test-run token.
 */
function admin_test_run_directory(string $token, bool $create = false): string
{
    $token = strtolower(trim($token));
    if (!admin_test_run_token_valid($token)) {
        throw new RuntimeException('Invalid Admin test-run token.');
    }
    $directory = admin_test_run_root() . DIRECTORY_SEPARATOR . $token;
    if ($create && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create Admin test-run directory.');
    }
    return $directory;
}

/**
 * Return one test-run metadata path.
 */
function admin_test_run_meta_path(string $token): string
{
    return admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'meta.json';
}

/**
 * Return one final JSON report path.
 */
function admin_test_run_report_path(string $token): string
{
    return admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'report.json';
}

/**
 * Return one optional ZIP artifact path.
 */
function admin_test_run_zip_path(string $token): string
{
    return admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'report.zip';
}

/**
 * Return the request-sidecar directory for one run.
 */
function admin_test_run_requests_directory(string $token, bool $create = false): string
{
    $directory = admin_test_run_directory($token, $create) . DIRECTORY_SEPARATOR . 'requests';
    if ($create && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create Admin test-run request directory.');
    }
    return $directory;
}
