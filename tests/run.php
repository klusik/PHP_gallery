<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/run.php
 * Module Type: Test Runner Compatibility Wrapper
 *
 * Purpose:
 *   Preserves the historical PHP regression command while delegating execution to the central audit runner.
 *
 * Responsibilities:
 *   - Keep `php tests/run.php` valid for existing developer workflows
 *   - Delegate PHP test discovery, isolation, timeout, and status handling to scripts/audit.php
 *   - Return the central runner's exit status unchanged
 *   - Avoid maintaining a second independent PHP regression implementation
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
 *   - New automated verification should call scripts/audit.php directly.
 *
 * Last Updated:
 *   2026-09-05
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The test runner is available only from the command line.\n");
    exit(2);
}

$auditPath = dirname(__DIR__) . '/scripts/audit.php';
if (!is_file($auditPath)) {
    fwrite(STDERR, "Central audit runner is missing: scripts/audit.php\n");
    exit(2);
}

$command = escapeshellarg(PHP_BINARY)
    . ' '
    . escapeshellarg($auditPath)
    . ' --suite=php-regression --no-report';
$exitCode = 0;
passthru($command, $exitCode);
exit($exitCode);
