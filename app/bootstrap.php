<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides core bootstrap, configuration, helper, security, database, or routing functionality.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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

namespace Gallery\Core;

const CMS_VERSION = '0.94.4';
const CMS_GITHUB_REPOSITORY = 'klusik/PHP_gallery';
const CMS_UPDATE_BRANCHES = ['main', 'master'];

function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('configuration'); require __DIR__ . '/bootstrap/configuration.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('configuration');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('helpers'); require __DIR__ . '/helpers.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('helpers');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('database'); require __DIR__ . '/database.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('database');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('security'); require __DIR__ . '/security.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('security');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('migrations'); require __DIR__ . '/migrations.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('migrations');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('services'); require __DIR__ . '/services.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('services');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('views'); require __DIR__ . '/views.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('views');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('integrity'); require __DIR__ . '/integrity.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('integrity');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('controllers'); require __DIR__ . '/controllers.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('controllers');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('routing_bootstrap'); require __DIR__ . '/bootstrap/routing.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('routing_bootstrap');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('session_bootstrap'); require __DIR__ . '/bootstrap/session.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('session_bootstrap');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('request_bootstrap'); require __DIR__ . '/bootstrap/request.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('request_bootstrap');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('maintenance_bootstrap'); require __DIR__ . '/bootstrap/maintenance.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('maintenance_bootstrap');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('dispatch_bootstrap'); require __DIR__ . '/bootstrap/dispatch.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('dispatch_bootstrap');

/**
 * Start the session, resolve the requested route, and dispatch to a controller.
 *
 * The project intentionally uses a small route table instead of a framework so
 * it remains easy to run on shared hosting.
 */
function cms_run(): void
{
    cms_request_trace_begin();
    if (!cms_has_config()) {
        cms_redirect_to_installer();
    }

    // Variable $config stores this steps working value.
    cms_request_trace_mark('config_load_start');
    $config = cms_config();
    cms_request_trace_mark('config_load_end');
    cms_start_session($config);
    cms_request_trace_mark('request_initialize_start');
    $page = cms_initialize_request();
    cms_request_trace_mark('request_initialize_end', ['page' => $page]);
    cms_release_read_only_media_session_lock($page);
    cms_prime_read_only_media_schema_cache($page);
    cms_request_trace_mark('request_maintenance_start', ['page' => $page]);
    cms_run_request_maintenance($page);
    cms_request_trace_mark('request_maintenance_end', ['page' => $page]);
    if (function_exists('Gallery\Services\admin_test_run_register_final_shutdown_observer')) {
        \Gallery\Services\admin_test_run_register_final_shutdown_observer();
    }
    cms_request_trace_mark('dispatch_start', ['page' => $page]);
    cms_dispatch_page($page);
    cms_request_trace_mark('dispatch_end', ['page' => $page]);
    if (function_exists('Gallery\Services\admin_test_run_response_logical_finish')) {
        \Gallery\Services\admin_test_run_response_logical_finish('cms_dispatch_returned');
    }
    if (function_exists('Gallery\\Services\\gallery_benchmark_record_request_completion')) {
        \Gallery\Services\gallery_benchmark_record_request_completion($page);
    }
}
