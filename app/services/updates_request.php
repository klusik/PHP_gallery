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
    return (
        app_setting('application_update_channel', 'stable') === 'beta'
            && app_setting('application_update_beta_commit', '') !== ''
    );
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
 * Default stable-release metadata discovery cadence for an installation.
 *
 * @var int
 * Units: seconds between eligible GitHub discovery attempts.
 * Scope: new and upgraded CMS installations lacking a valid saved preference.
 * Consumers: automatic updater interval reader and request-time default argument.
 * Rationale: five-minute discovery is the product default while existing due gates,
 *   shared locks and GitHub API rate-limit backoff prevent unbounded polling.
 */
const APPLICATION_AUTOUPDATE_DEFAULT_INTERVAL_SECONDS = 300;

/**
 * Exact allowlist for the saved stable-release discovery preference.
 *
 * @var array<int,int>
 * Units: seconds for keys and whole minutes for display values.
 * Scope: per-installation automatic stable-release discovery scheduling.
 * Consumers: updater setting validator, request and CLI gates, and Admin selector.
 * Rationale: prevent unsupported sub-five-minute polling and preserve validated
 *   5, 15, 30 and 60 minute operator choices without a schema migration.
 */
const APPLICATION_AUTOUPDATE_INTERVAL_OPTIONS = [
    300 => 5,
    900 => 15,
    1800 => 30,
    3600 => 60,
];

/**
 * Validate an interval without accepting numeric coercion, whitespace, or extra values.
 *
 * @param string $raw Decimal seconds as saved in app_settings or submitted by Admin.
 * @return int|null Allowed interval or null if not allowed.
 */
function application_autoupdate_validate_interval_seconds(string $raw): ?int
{
    foreach (APPLICATION_AUTOUPDATE_INTERVAL_OPTIONS as $seconds => $minutes) {
        if ($raw === (string) $seconds) {
            return (int) $seconds;
        }
    }
    return null;
}

/**
 * Return the effective remote stable-release discovery cadence in seconds.
 *
 * Missing, obsolete, and corrupted stored values resolve to five minutes.
 *
 * @return int Valid 300, 900, 1800, or 3600 seconds.
 */
function application_autoupdate_interval_seconds(): int
{
    $stored = (string) app_setting(
        'application_autoupdate_interval_seconds',
        (string) APPLICATION_AUTOUPDATE_DEFAULT_INTERVAL_SECONDS,
    );
    return (
        application_autoupdate_validate_interval_seconds($stored)
            ?? APPLICATION_AUTOUPDATE_DEFAULT_INTERVAL_SECONDS
    );
}

/**
 * Persist an explicitly selected interval without modifying automatic-update enablement.
 *
 * @param string $seconds Exact decimal seconds from an authenticated Admin setting.
 * @throws \InvalidArgumentException For a value outside the allowlist.
 * @return void Persists one allowlisted interval without changing automatic-update enablement.
 */
function set_application_autoupdate_interval_seconds(string $seconds): void
{
    if (application_autoupdate_validate_interval_seconds($seconds) === null) {
        throw new \InvalidArgumentException(
            'Invalid automatic update check interval.',
        );
    }
    set_app_setting('application_autoupdate_interval_seconds', $seconds);
}

/**
 * Return the newest persisted discovery timestamp across manual and automatic checks.
 *
 * @return int Last attempted discovery time, or zero when never checked.
 */
function application_update_discovery_last_attempt_at(): int
{
    return max(
        0,
        (int) app_setting('application_autoupdate_last_checked_at', '0'),
        (int) app_setting('application_update_check_cached_at', '0'),
    );
}

/**
 * Evaluate the elapsed-time discovery gate without using a wall-clock sleep.
 *
 * @param int $now Current Unix timestamp (explicit for deterministic tests).
 * @param int $lastAttemptAt Most recent persisted attempt timestamp.
 * @param int|null $intervalSeconds Already-normalized interval, or null for saved preference.
 * @return bool Whether remote discovery is eligible at this instant.
 */
function application_autoupdate_discovery_due(
    int $now,
    int $lastAttemptAt,
    ?int $intervalSeconds = null,
): bool {
    $interval = $intervalSeconds ?? application_autoupdate_interval_seconds();
    return $lastAttemptAt <= 0 || ($now - $lastAttemptAt) >= $interval;
}

/**
 * Check and install a stable release automatically when the request-time timer allows it.
 *
 * This routine is intentionally conservative: it runs only on safe browser reads,
 * never changes the admin checkbox when beta code is active, and throttles remote
 * checks to one attempt per installation per configured interval. Running jobs
 * keep continuation priority; stopped jobs allow separate due discovery.
 * The optional loader lets the runtime
 * kernel provide the heavy updater module only when an active job or due check needs it.
 *
 * @param int $ttlSeconds Legacy caller argument kept for compatibility; saved preference is authoritative.
 * @param string $requestMethod HTTP method used to decide whether a new check is safe.
 * @param callable(string):mixed|null $loadDependencies Optional loader for heavy updater dependencies.
 * @return void Runs eligible active-job or automatic-check work.
 */
function application_autoupdate_maybe_run(
    int $ttlSeconds = APPLICATION_AUTOUPDATE_DEFAULT_INTERVAL_SECONDS,
    string $requestMethod = 'GET',
    ?callable $loadDependencies = null,
): void {
    if (
        function_exists(__NAMESPACE__
            . '\\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('built_in_update_installer')
    ) {
        return;
    }

    // Continue persisted work before checking method and preference, preserving legacy behavior.
    $activeJob = application_update_active_job();
    if ($activeJob !== null) {
        if (($activeJob['status'] ?? '') === 'failed') {
            if ($loadDependencies !== null) {
                $loadDependencies('updater-work');
            }
            if (application_update_failed_recovery_due($activeJob, time())) {
                application_update_continue_background_job(3.0);
                return;
            }
        }
        if (($activeJob['status'] ?? '') === 'failed'
            && in_array(strtoupper(trim($requestMethod)), ['GET', 'HEAD'], true)
            && application_autoupdate_enabled()
            && application_autoupdate_discovery_due(
                time(), application_update_discovery_last_attempt_at(),
            )) {
            if ($loadDependencies !== null) {
                $loadDependencies('updater-work');
            }
            // Do not spend a worker slice and a discovery budget together.
            if (application_update_beta_active()) {
                application_autoupdate_dry_run(false, time());
            } else {
                application_autoupdate_run_installing_check(false, time());
            }
            return;
        }
        // A live server chain already owns this job's next slice. Public visits
        // keep rendering; an expired/lost lease restores the legacy fallback.
        if (
            (($activeJob['trigger'] ?? '') === 'background'
                || !empty($activeJob['automatic_repair_continuation']))
            && application_update_background_chain_pending(
                (string) $activeJob['id'],
            )
        ) {
            return;
        }
        if ($loadDependencies !== null) {
            $loadDependencies('updater-work');
        }
        if (function_exists(__NAMESPACE__
            . '\\admin_test_run_record_maintenance_event')) {
            admin_test_run_record_maintenance_event(
                'automatic_updater',
                'active_job_continue_begin',
                ['budget_seconds' => 3.0],
            );
        }
        application_update_continue_background_job(3.0);
        if (function_exists(__NAMESPACE__
            . '\\admin_test_run_record_maintenance_event')) {
            admin_test_run_record_maintenance_event(
                'automatic_updater',
                'active_job_continue_end',
                ['budget_seconds' => 3.0],
            );
        }
        return;
    }

    // Saved preference overrides legacy caller arguments. Only metadata discovery
    // is throttled, not active resumable update-job execution.
    $ttlSeconds = application_autoupdate_interval_seconds();
    // $method stores the current HTTP verb so uploads, votes, edits, and CSRF flows are not interrupted.
    $method = strtoupper(trim($requestMethod));
    if (
        !in_array($method, ['GET', 'HEAD'], true)
        || !application_autoupdate_enabled()
    ) {
        return;
    }

    $now = time();
    $lastCheckedAt = application_update_discovery_last_attempt_at();
    if (!application_autoupdate_discovery_due(
        $now,
        $lastCheckedAt,
        $ttlSeconds,
    )) {
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
        if (function_exists(__NAMESPACE__
            . '\\admin_test_run_record_maintenance_event')) {
            admin_test_run_record_maintenance_event(
                'automatic_updater',
                'due_check_begin',
                ['mode' => 'beta_dry_run'],
            );
        }
        application_autoupdate_dry_run(false, $now);
        if (function_exists(__NAMESPACE__
            . '\\admin_test_run_record_maintenance_event')) {
            admin_test_run_record_maintenance_event(
                'automatic_updater',
                'due_check_end',
                ['mode' => 'beta_dry_run'],
            );
        }
        return;
    }

    if (function_exists(__NAMESPACE__
        . '\\admin_test_run_record_maintenance_event')) {
        admin_test_run_record_maintenance_event(
            'automatic_updater',
            'due_check_begin',
            ['mode' => 'stable_installing_check'],
        );
    }
    application_autoupdate_run_installing_check(false, $now);
    if (function_exists(__NAMESPACE__
        . '\\admin_test_run_record_maintenance_event')) {
        admin_test_run_record_maintenance_event(
            'automatic_updater',
            'due_check_end',
            ['mode' => 'stable_installing_check'],
        );
    }
}
