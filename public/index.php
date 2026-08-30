<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/index.php
 * Module Type: Public Entrypoint
 *
 * Purpose:
 *   Routes public web requests into the PHP Gallery application.
 *
 * Responsibilities:
 *   - Initialize the public request pipeline
 *   - Load project bootstrap code
 *   - Keep direct entrypoint logic minimal
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
 *   2026-05-04
 */

declare(strict_types=1);

use function Gallery\Core\cms_run;
use function Gallery\EarlyRuntime\enforce_activation_gate;
use function Gallery\EarlyRuntime\handle_uncaught;
use function Gallery\EarlyRuntime\register_emergency_handler;

require_once __DIR__ . '/../app/early_runtime.php';
register_emergency_handler();
enforce_activation_gate(dirname(__DIR__));

try {
    require_once __DIR__ . '/../app/diagnostics/admin_test_run_early.php';
    \Gallery\Diagnostics\admin_test_run_early_init(dirname(__DIR__));
    \Gallery\Diagnostics\admin_test_run_early_phase_start('app_bootstrap_include');
    try {
        require __DIR__ . '/../app/bootstrap.php';
        \Gallery\Diagnostics\admin_test_run_early_phase_end('app_bootstrap_include');
    } catch (\Throwable $exception) {
        \Gallery\Diagnostics\admin_test_run_early_phase_end('app_bootstrap_include', false, $exception);
        throw $exception;
    }

    cms_run();
} catch (\Throwable $exception) {
    handle_uncaught($exception);
}

