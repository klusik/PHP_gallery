<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/check_release.php
 * Module Type: Release Consistency CLI
 *
 * Purpose:
 *   Performs read-only consistency validation across PHP Gallery release artifacts.
 *
 * Responsibilities:
 *   - Compare runtime, documentation, manual, metadata, patch-note, and manifest versions
 *   - Reject incomplete release-note scaffolding
 *   - Detect a manual PDF older than its LaTeX source
 *   - Return a stable non-zero exit code for release-blocking inconsistencies
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
 *   - This script never modifies repository files.
 *
 * Last Updated:
 *   2026-09-05
 */

declare(strict_types=1);

use function PhpGallery\Release\collect_consistency_checks;
use function PhpGallery\Release\detect_cms_version;
use function PhpGallery\Release\project_root;
use function PhpGallery\Release\valid_version;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/release_lib.php';

$version = null;
$quiet = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--quiet') {
        $quiet = true;
        continue;
    }
    if ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, "Usage: php scripts/check_release.php [version] [--quiet]\n");
        exit(0);
    }
    if ($version === null) {
        $version = $argument;
        continue;
    }
    fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
    exit(2);
}

$root = project_root();
$version = $version ?? detect_cms_version($root);
if (!valid_version($version)) {
    fwrite(STDERR, "Invalid release version.\n");
    exit(2);
}

try {
    $checks = collect_consistency_checks($root, $version);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release consistency check failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

$failed = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'FAIL'));

if (!$quiet) {
    fwrite(STDOUT, 'PHP Gallery release consistency | Target: ' . $version . "\n");
    fwrite(STDOUT, str_repeat('=', 72) . "\n");
    foreach ($checks as $index => $check) {
        fwrite(STDOUT, '[' . ($index + 1) . '/' . count($checks) . '] ' . str_pad($check['status'], 5) . ' ' . $check['label'] . ' - ' . $check['detail'] . "\n");
    }
    fwrite(STDOUT, str_repeat('-', 72) . "\n");
}

$status = $failed === [] ? 'PASS' : 'FAIL';
fwrite(STDOUT, 'Result: ' . $status . ' | ' . (count($checks) - count($failed)) . ' pass / ' . count($failed) . " fail\n");
exit($failed === [] ? 0 : 1);
