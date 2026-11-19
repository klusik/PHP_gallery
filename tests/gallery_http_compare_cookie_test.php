<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_http_compare_cookie_test.php
 * Module Type: Offline Regression Bridge
 * Purpose: Qualify actual local comparison session transport without network access.
 * Responsibilities: Use the central Python locator/process helper and preserve child failures.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/audit_lib.php';

/**
 * Bound the offline actual-runner cookie regression process.
 * Type: int. Units: seconds. Scope: one Python fixture process.
 * Consumers: the central run_process() bridge below.
 * Rationale: thirty seconds allows interpreter startup and local temporary I/O
 * while preventing an unexpected child hang from blocking qualification.
 */
const GALLERY_HTTP_COOKIE_CHILD_TIMEOUT_SECONDS = 30;

$python = \PhpGallery\Audit\resolve_python_command();
if ($python === null) {
    fwrite(STDERR, "BLOCKED: Python is required for offline gallery HTTP cookie contracts.\n");
    exit(2);
}
$process = \PhpGallery\Audit\run_process(
    array_merge($python, ['-B', __DIR__ . '/gallery_http_compare_cookie_test.py']),
    dirname(__DIR__),
    GALLERY_HTTP_COOKIE_CHILD_TIMEOUT_SECONDS,
);
echo $process['stdout'];
fwrite(STDERR, $process['stderr']);
if ($process['timed_out'] || $process['exit_code'] !== 0) {
    exit(1);
}
echo "PASS: offline gallery HTTP cookie contracts.\n";
