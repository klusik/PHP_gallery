<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_request.php
 * Module Type: Service
 *
 * Purpose:
 *   Owns lightweight request-time automatic update policy and its persisted preference.
 *
 * Responsibilities:
 *   - Keep request eligibility and due checks available to the runtime kernel
 *   - Defer update worker dependencies until an active or due update needs work
 *   - Preserve the procedural updater API used by legacy callers
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
 *   - Heavy update work is loaded only through the optional injected module loader.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-10-05
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Return whether the application is currently on a beta/manual commit install.
 *
 * @return bool True when a beta channel and commit are both configured.
 */
function application_update_beta_active(): bool
{
    return app_setting('application_update_channel', 'stable') === 'beta'
        && app_setting('application_update_beta_commit', '') !== '';
}

/**
 * Return true when automatic stable updates are enabled by the administrator.
 *
 * @return bool True when automatic stable update checks are enabled.
 */
function application_autoupdate_enabled(): bool
{
    return app_setting('application_autoupdate_enabled', '1') === '1';
}

/**
 * Persist the automatic stable update preference from the Admin maintenance page.
 *
 * @param bool $enabled Whether automatic stable updates should be enabled.
 * @return void Persists the normalized preference.
 */
function set_application_autoupdate_enabled(bool $enabled): void
{
    set_app_setting('application_autoupdate_enabled', $enabled ? '1' : '0');
}

/**
 * Check and install a stable release automatically when the request-time timer allows it.
 *
 * This routine is intentionally conservative: it runs only on safe browser reads,
 * never changes the admin checkbox when beta code is active, and throttles remote
 * checks to one attempt per installation per configured interval. Active jobs are
 * continued before method and preference checks. The optional loader lets the runtime
 * kernel provide the heavy updater module only when an active job or due check needs it.
 *
 * @param int $ttlSeconds Minimum interval in seconds between automatic remote checks.
 * @param string $requestMethod HTTP method used to decide whether a new check is safe.
 * @param callable(string):mixed|null $loadDependencies Optional loader for heavy updater dependencies.
 * @return void Runs eligible active-job or automatic-check work.
 */
function application_autoupdate_maybe_run(
    int $ttlSeconds = 3600,
    string $requestMethod = 'GET',
    ?callable $loadDependencies = null
): void {
    if (function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('built_in_update_installer')) {
        return;
    }

    // Continue persisted work before checking method and preference, preserving legacy behavior.
    $activeJob = application_update_active_job();
    if ($activeJob !== null) {
        if ($loadDependencies !== null) {
            $loadDependencies('updater-work');
        }
        if (function_exists(__NAMESPACE__ . '\\admin_test_run_record_maintenance_event')) {
            admin_test_run_record_maintenance_event('automatic_updater', 'active_job_continue_begin', ['budget_seconds' => 3.0]);
        }
        application_update_continue_background_job(3.0);
        if (function_exists(__NAMESPACE__ . '\\admin_test_run_record_maintenance_event')) {
            admin_test_run_record_maintenance_event('automatic_updater', 'active_job_continue_end', ['budget_seconds' => 3.0]);
        }
        return;
    }

    // $ttlSeconds stores the minimum remote check interval. One hour is the default
    // so shared hosting installations do not burn anonymous GitHub API quota on
    // normal page traffic. Manual dry checks intentionally bypass this throttle.
    $ttlSeconds = max(3600, $ttlSeconds);
    // $method stores the current HTTP verb so uploads, votes, edits, and CSRF flows are not interrupted.
    $method = strtoupper(trim($requestMethod));
    if (!in_array($method, ['GET', 'HEAD'], true) || !application_autoupdate_enabled()) {
        return;
    }

    $now = time();
    $lastCheckedAt = (int) app_setting('application_autoupdate_last_checked_at', '0');
    if ($lastCheckedAt > 0 && $now - $lastCheckedAt < $ttlSeconds) {
        return;
    }
    $lockUntil = (int) app_setting('application_autoupdate_lock_until', '0');
    if ($lockUntil > $now) {
        return;
    }

    if ($loadDependencies !== null) {
        $loadDependencies('updater-work');
    }

    if (application_update_beta_active()) {
        if (function_exists(__NAMESPACE__ . '\\admin_test_run_record_maintenance_event')) {
            admin_test_run_record_maintenance_event('automatic_updater', 'due_check_begin', ['mode' => 'beta_dry_run']);
        }
        application_autoupdate_dry_run(false, $now);
        if (function_exists(__NAMESPACE__ . '\\admin_test_run_record_maintenance_event')) {
            admin_test_run_record_maintenance_event('automatic_updater', 'due_check_end', ['mode' => 'beta_dry_run']);
        }
        return;
    }

    if (function_exists(__NAMESPACE__ . '\\admin_test_run_record_maintenance_event')) {
        admin_test_run_record_maintenance_event('automatic_updater', 'due_check_begin', ['mode' => 'stable_installing_check']);
    }
    application_autoupdate_run_installing_check(false, $now);
    if (function_exists(__NAMESPACE__ . '\\admin_test_run_record_maintenance_event')) {
        admin_test_run_record_maintenance_event('automatic_updater', 'due_check_end', ['mode' => 'stable_installing_check']);
    }
}
