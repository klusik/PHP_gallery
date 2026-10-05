<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_performance_registry.php
 * Module Type: Audit Configuration
 * Purpose: Declare reproducible application include phases and conservative regression ceilings.
 * Responsibilities: Centralize probe entry sequences, include baselines and memory ceilings.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

return [
    // These are actual front-controller include phases, before cms_run() starts
    // sessions, loads installation configuration or dispatches database-backed routes.
    // Wall time is observational: CI load and PHP opcode caches vary between hosts.
    'early-runtime' => [
        'entry_files' => ['app/early_runtime.php'],
        'baseline_included_php_files' => 1,
        'max_included_php_files' => 1,
        'max_peak_memory_bytes' => 16777216,
    ],
    'application-bootstrap' => [
        'entry_files' => ['app/early_runtime.php', 'app/diagnostics/admin_test_run_early.php', 'app/bootstrap.php'],
        'baseline_included_php_files' => 533,
        'max_included_php_files' => 560,
        'max_peak_memory_bytes' => 67108864,
    ],
];
