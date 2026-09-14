<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage11_view_request_independence_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the Stage 11 rule that views render from explicit input rather
 *   than reading request/session state or persistence directly.
 *
 * Responsibilities:
 *   - Reject request and session globals from app/views
 *   - Reject direct database/PDO/SQL access from app/views
 *   - Reject HTTP response and filesystem mutation side effects from views
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
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/scripts/check_mvc_boundaries.php';

$blockedRules = [
    'views.request_global',
    'views.direct_db',
    'views.pdo_method',
    'views.sql_literal',
    'views.http_response',
    'views.filesystem_mutation',
    'views.model_dependency',
];
$violations = [];
foreach (\PhpGallery\MvcBoundary\scan_project($root) as $violation) {
    if (!in_array((string) ($violation['rule'] ?? ''), $blockedRules, true)) {
        continue;
    }
    $violations[] = sprintf(
        '%s:%d %s',
        (string) ($violation['path'] ?? 'unknown'),
        (int) ($violation['line'] ?? 0),
        (string) ($violation['rule'] ?? 'unknown')
    );
}

if ($violations !== []) {
    throw new RuntimeException("Stage 11 view request-independence violations:\n  - " . implode("\n  - ", $violations));
}

fwrite(STDOUT, "Stage 11 view request-independence checks passed.\n");
