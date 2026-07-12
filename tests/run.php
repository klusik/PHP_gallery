<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/run.php
 * Module Type: Test Runner
 *
 * Purpose:
 *   Runs every current standalone PHP regression test in an isolated process.
 *
 * Responsibilities:
 *   - Discover current test scripts by the *_test.php naming convention
 *   - Preserve process isolation between tests and their helper shims
 *   - Return a non-zero exit code when any test fails
 *   - Print one compact final pass or failure summary
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
 *   2026-07-12
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The test runner is available only from the command line.\n");
    exit(2);
}

$testFiles = glob(__DIR__ . '/*_test.php') ?: [];
sort($testFiles, SORT_STRING);

if ($testFiles === []) {
    fwrite(STDERR, "No test scripts were found.\n");
    exit(2);
}

$passed = 0;
$failed = [];

foreach ($testFiles as $testFile) {
    $relativeName = basename($testFile);
    fwrite(STDOUT, "\n=== {$relativeName} ===\n");

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testFile);
    $exitCode = 0;
    passthru($command, $exitCode);

    if ($exitCode === 0) {
        $passed++;
        continue;
    }

    $failed[] = $relativeName;
}

fwrite(STDOUT, "\nPassed: {$passed}/" . count($testFiles) . "\n");
if ($failed === []) {
    fwrite(STDOUT, "All current PHP regression tests passed.\n");
    exit(0);
}

fwrite(STDERR, "Failed: " . implode(', ', $failed) . "\n");
exit(1);
