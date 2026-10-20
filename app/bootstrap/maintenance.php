<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap/maintenance.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Owns update and maintenance request triggers that run after request initialization.
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

use function Gallery\Services\application_autoupdate_maybe_run;
use function Gallery\Services\application_autoupdate_interval_seconds;
use function Gallery\Services\admin_log_archive_register_request_trigger;
use function Gallery\Services\site_maintenance_register_request_trigger;

/**
 * Run automatic update and maintenance request triggers in the legacy startup order.
 *
 * @param string $page Resolved page identifier.
 * @param Kernel|null $kernel Optional selective runtime coordinator; legacy full-load consumers may omit it.
 * @return void Executes eligible triggers in the established order.
 */
function cms_run_request_maintenance(string $page, ?Kernel $kernel = null): void
{
    $cacheAction = is_string($_POST['site_maintenance_action'] ?? null)
        ? $_POST['site_maintenance_action']
        : '';
    // These explicit Admin transports own their work. A passive checkpoint read
    // or stop request must never advance an updater before the controller runs.
    // Route authentication and POST CSRF checks remain in the dispatcher/controllers.
    if (cms_request_owns_cache_transport(
        $page,
        request_method(),
        isset($_GET['update_job_status']),
        $cacheAction,
    )) {
        return;
    }
    if (cms_route_is_read_only_media_asset($page)) {
        if (function_exists('Gallery\\Services\\admin_test_run_mark')) {
            \Gallery\Services\admin_test_run_mark('maintenance.skipped_read_only_media_asset', [
                'page' => $page,
            ]);
        }
        return;
    }

    // Load the lightweight updater policy before inspecting its interval.
    $kernel?->load('request-maintenance');
    $intervalSeconds = application_autoupdate_interval_seconds();
    if (function_exists('Gallery\\Services\\admin_test_run_mark')) {
        \Gallery\Services\admin_test_run_mark('maintenance.autoupdate.begin', [
            'configured_check_ttl_seconds' => $intervalSeconds,
            'background_continue_budget_seconds' => 3.0,
        ]);
    }
    application_autoupdate_maybe_run(
        $intervalSeconds,
        request_method(),
        $kernel !== null
            ? static fn(string $module): mixed => $kernel->load($module) : null,
    );
    if (function_exists('Gallery\\Services\\admin_test_run_mark')) {
        \Gallery\Services\admin_test_run_mark('maintenance.autoupdate.end');
    }
    $kernel?->load('archive-request-trigger');
    if (function_exists(
        'Gallery\\Services\\admin_log_archive_register_request_trigger',
    )) {
        if (function_exists('Gallery\\Services\\admin_test_run_mark')) {
            \Gallery\Services\admin_test_run_mark('maintenance.admin_log_archive_trigger.begin', [
                'page' => $page,
            ]);
        }
        admin_log_archive_register_request_trigger(
            $page,
            $kernel !== null
                ? static fn(string $module): mixed => $kernel->load(
                    $module,
                ) : null,
        );
        if (function_exists('Gallery\\Services\\admin_test_run_mark')) {
            \Gallery\Services\admin_test_run_mark('maintenance.admin_log_archive_trigger.end', [
                'page' => $page,
            ]);
        }
    }
    $kernel?->load('site-request-trigger');
    if (function_exists(
        'Gallery\\Services\\site_maintenance_register_request_trigger',
    )) {
        if (function_exists('Gallery\\Services\\admin_test_run_mark')) {
            \Gallery\Services\admin_test_run_mark('maintenance.site_maintenance_trigger.begin', [
                'page' => $page,
            ]);
        }
        site_maintenance_register_request_trigger(
            $page,
            $kernel !== null
                ? static fn(string $module): mixed => $kernel->load(
                    $module,
                ) : null,
        );
        if (function_exists('Gallery\\Services\\admin_test_run_mark')) {
            \Gallery\Services\admin_test_run_mark('maintenance.site_maintenance_trigger.end', [
                'page' => $page,
            ]);
        }
    }
    cms_register_update_background_continuation($kernel);
}

/**
 * Hand a running automatic stable update to independent workers after a visit.
 * @param Kernel|null $kernel Optional selective loader; full-bootstrap callers already own worker dependencies.
 * @return void Registers one successful-response handoff, excluding CLI, manual jobs, failed responses and fatal requests.
 */
function cms_register_update_background_continuation(?Kernel $kernel = null): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $job = \Gallery\Services\application_update_active_job();
    if (
        $job === null
        || ($job['trigger'] ?? '') !== 'background'
        || ($job['operation'] ?? '') !== 'stable_update'
        || ($job['status'] ?? '') !== 'running'
        || \Gallery\Services\application_update_background_chain_pending(
            (string) $job['id'],
        )
    ) {
        return;
    }
    register_shutdown_function(static function () use ($kernel): void {
        $error = error_get_last();
        if (
            response_status_code() < 200
            || response_status_code() >= 400
            || $error !== null
            && in_array(
                $error['type'],
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                true,
            )
        ) {
            return;
        }
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ignore_user_abort(true);
            \Gallery\Services\site_maintenance_finish_response_before_background_work();
            $kernel?->load('updater-work');
            \Gallery\Services\application_update_background_chain_start(
                static fn(string $ticket): bool => \Gallery\Services\site_maintenance_fire_background_worker(
                    false,
                    '',
                    $ticket,
                ),
            );
        } catch (\Throwable) {
            // Preserve the visitor response and durable job. Another admitted
            // visit can recover a lost handoff through the bounded lease.
        }
    });
}

/**
 * Identify explicit cache actions and passive updater reads that own their work.
 * @param string $page Canonical route identifier selected by the runtime.
 * @param string $method Normalized HTTP request method.
 * @param bool $jobStatusRead Whether the query requests the existing updater status response.
 * @param string $cacheAction Scalar site-maintenance action, empty for unrelated requests.
 * @return bool True for private maintenance workers, GET updater checkpoint reads or explicit POST cache transports; security checks still run at dispatch.
 */
function cms_request_owns_cache_transport(
    string $page,
    string $method,
    bool $jobStatusRead,
    string $cacheAction,
): bool {
    return (
        $page === 'site_maintenance_cron'
            || ($page === 'admin_update' && $method === 'GET' && $jobStatusRead)
            || (
                $page === 'admin_site_maintenance_settings'
                && $method === 'POST'
                && in_array(
                    $cacheAction,
                    [
                        'cache_cleanup',
                        'cache_diagnostic',
                        'cache_job_stop',
                        'cache_inventory_start',
                        'cache_inventory_step',
                        'cache_cleanup_start',
                        'cache_cleanup_step',
                    ],
                    true,
                )
            )
    );
}
