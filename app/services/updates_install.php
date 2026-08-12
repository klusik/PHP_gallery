<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_install.php
 * Module Type: Service
 *
 * Purpose:
 *   Handles automatic-update state, beta/stable install orchestration, backup/restore entrypoints, and update installation.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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

namespace Gallery\Services;

use DateTimeImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;
use const Gallery\Core\CMS_GITHUB_REPOSITORY;
use const Gallery\Core\CMS_UPDATE_BRANCHES;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\e;
use function Gallery\Core\run_migrations;

/**
 * Application update service model.
 *
 * This module owns GitHub version checks, cached update status, release ZIP download,
 * beta install/restore helpers, protected-path rules, filesystem copy logic, and
 * OPcache invalidation for application updates.
 *
 * The functions remain deliberately procedural because the rest of PHP Gallery uses
 * function-based services. Keeping the original public function names avoids route,
 * controller, installer, and admin template changes while allowing the legacy
 * app/services.php file to shrink safely.
 */

/**
 * Refuse active application-file replacement when migration schema metadata cannot be inspected.
 *
 * Confirmed absence of schema_migrations is allowed because the migration runner can
 * bootstrap it after activation. Unknown state is not allowed because replacing code
 * while migration state is indeterminate can leave the installation unrecoverable.
 *
 * @param string $operation Stable updater operation identifier.
 */
function application_update_assert_activation_schema_known(string $operation): void
{
    mutation_schema_assert_known(
        application_update_activation_schema_status(),
        $operation,
        'Application update activation is temporarily unavailable because migration schema state could not be verified. Downloaded update files were left outside the active installation.'
    );
}

/**
 * Return a cached update check for small UI badges.
 *
 * @param int $ttlSeconds Ttl seconds value.
 * @param bool $refreshWhenStale Refresh when stale value.
 * @return array Structured result data for the caller.
 */
function cached_application_update_check(int $ttlSeconds = 3600, bool $refreshWhenStale = false): array
{
    static $requestCache = null;

    // $ttlSeconds stores the shortest acceptable cache lifetime for admin badges.
    $ttlSeconds = max(60, $ttlSeconds);
    if (is_array($requestCache) && time() - (int) ($requestCache['cached_at'] ?? 0) <= $ttlSeconds) {
        return (array) ($requestCache['status'] ?? []);
    }

    // $cachedAt stores the Unix timestamp for the DB-backed update-check cache.
    $cachedAt = (int) app_setting('application_update_check_cached_at', '0');
    // $cachedJson stores the last update-check payload used by admin navigation badges.
    $cachedJson = (string) app_setting('application_update_check_status_json', '');
    if ($cachedAt > 0 && $cachedJson !== '') {
        // $cachedStatus stores the decoded update-check payload when it matches the expected shape.
        $cachedStatus = json_decode($cachedJson, true);
        if (is_array($cachedStatus)) {
            $requestCache = ['cached_at' => $cachedAt, 'status' => $cachedStatus];
            if (time() - $cachedAt <= $ttlSeconds || !$refreshWhenStale) {
                return $cachedStatus;
            }
        }
    }

    if (!$refreshWhenStale) {
        return [];
    }

    // $status stores the fresh remote status only for callers that explicitly allow a refresh.
    $status = check_application_update();
    cache_application_update_check($status);
    $requestCache = ['cached_at' => time(), 'status' => $status];
    return $status;
}

/**
 * Store an update check result for badge rendering.
 */

/**
 * Return a safe placeholder when no local update metadata has been cached yet.
 *
 * @return array Structured result data for the caller.
 */
function application_update_unknown_cached_status(): array
{
    return [
        'current_version' => cms_current_version(),
        'latest_version' => null,
        'branch' => implode(' or ', application_update_branch_candidates()),
        'repository' => CMS_GITHUB_REPOSITORY,
        'update_available' => false,
        'version_sources' => [],
        'version_source' => 'local cache',
        'error' => null,
        'diagnostic' => 'No local GitHub update metadata has been cached yet. Use Force check to query GitHub now.',
        'local_cache_only' => true,
    ];
}

/**
 * Handle cache application update check.
 *
 * Part of the related application service.
 *
 * @param array $status Status value.
 */
function cache_application_update_check(array $status): void
{
    // $json stores the compact update status used by navigation badges.
    $json = json_encode($status, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    set_app_setting('application_update_check_status_json', $json);
    set_app_setting('application_update_check_cached_at', (string) time());
}

/**
 * Return true when an update status really points past the installed version.
 *
 * @param array $status Status value.
 * @return bool True when the condition matches.
 */
function application_update_status_is_pending(array $status): bool
{
    if (!empty($status['error'])) {
        return false;
    }

    // $latestVersion stores the remote version reported by the status payload.
    $latestVersion = trim((string) ($status['latest_version'] ?? ''));
    if ($latestVersion === '') {
        return false;
    }

    return version_compare($latestVersion, cms_current_version(), '>');
}

/**
 * Return true when the cached update check says a newer version is available.
 *
 * @return bool True when the condition matches.
 */
function application_update_pending(): bool
{
    // $status stores an intermediate value used by the surrounding gallery workflow.
    $status = cached_application_update_check(3600);
    return application_update_status_is_pending($status);
}

/**
 * Return true when the application is currently on a beta/manual commit install.
 *
 * @return bool True when the condition matches.
 */
function application_update_beta_active(): bool
{
    return app_setting('application_update_channel', 'stable') === 'beta' && app_setting('application_update_beta_commit', '') !== '';
}

/**
 * Return the currently installed beta code, if any.
 *
 * @return string Text result for the caller.
 */
function application_update_beta_commit(): string
{
    return (string) app_setting('application_update_beta_commit', '');
}

/**
 * Return true when automatic stable updates are enabled by admin settings.
 *
 * @return bool True when the condition matches.
 */
function application_autoupdate_enabled(): bool
{
    return app_setting('application_autoupdate_enabled', '1') === '1';
}

/**
 * Persist the automatic stable update setting from the admin maintenance page.
 *
 * @param bool $enabled Enabled flag.
 */
function set_application_autoupdate_enabled(bool $enabled): void
{
    set_app_setting('application_autoupdate_enabled', $enabled ? '1' : '0');
}

/**
 * Return diagnostic state for the automatic updater card.
 *
 * @return array Structured result data for the caller.
 */
function application_autoupdate_status(): array
{
    // $lastCheckedAt stores the last request-time automatic update check timestamp.
    $lastCheckedAt = (int) app_setting('application_autoupdate_last_checked_at', '0');
    // $lastResult stores the latest readable automatic update result.
    $lastResult = (string) app_setting('application_autoupdate_last_result', '');
    // $enabled stores the raw admin checkbox state, even when beta code makes it ineffective.
    $enabled = application_autoupdate_enabled();
    // $betaActive stores whether autoupdate must stay passive because a manual beta commit is installed.
    $betaActive = application_update_beta_active();
    // $lastCheckedLabel stores the UI-ready timestamp label, including a never-checked fallback.
    $lastCheckedLabel = application_autoupdate_last_checked_label($lastCheckedAt);
    // $lastCheckedRelative stores a concise freshness label such as "2 minutes ago".
    $lastCheckedRelative = application_autoupdate_relative_time_label($lastCheckedAt);

    return [
        'enabled' => $enabled,
        'effective' => $enabled && !$betaActive,
        'beta_active' => $betaActive,
        'last_checked_at' => $lastCheckedAt,
        'last_checked_label' => $lastCheckedLabel,
        'last_checked_relative' => $lastCheckedRelative,
        'last_result' => $lastResult,
    ];
}

/**
 * Return a readable automatic update check timestamp for admin diagnostics.
 *
 * @param int $lastCheckedAt Last checked at value.
 * @return string Text result for the caller.
 */
function application_autoupdate_last_checked_label(int $lastCheckedAt): string
{
    if ($lastCheckedAt <= 0) {
        return t('admin.updates.autoupdate_last_check_never', 'never');
    }

    return date('Y-m-d H:i:s', $lastCheckedAt);
}

/**
 * Return a concise relative automatic update check age for admin diagnostics.
 *
 * @param int $lastCheckedAt Last checked at value.
 * @return string Text result for the caller.
 */
function application_autoupdate_relative_time_label(int $lastCheckedAt): string
{
    if ($lastCheckedAt <= 0) {
        return '';
    }

    // $ageSeconds stores elapsed wall-clock seconds since the last automatic check.
    $ageSeconds = max(0, time() - $lastCheckedAt);
    if ($ageSeconds < 60) {
        return t('admin.updates.autoupdate_relative_seconds', 'just now');
    }

    // $minutes stores rounded-down elapsed minutes for compact labels.
    $minutes = intdiv($ageSeconds, 60);
    if ($minutes < 60) {
        return t('admin.updates.autoupdate_relative_minutes', '{count} minute(s) ago', ['count' => (string) $minutes]);
    }

    // $hours stores rounded-down elapsed hours for same-day and recent checks.
    $hours = intdiv($minutes, 60);
    if ($hours < 48) {
        return t('admin.updates.autoupdate_relative_hours', '{count} hour(s) ago', ['count' => (string) $hours]);
    }

    // $days stores rounded-down elapsed days for stale checks.
    $days = intdiv($hours, 24);
    return t('admin.updates.autoupdate_relative_days', '{count} day(s) ago', ['count' => (string) $days]);
}

/**
 * Check and install a stable release automatically when the request-time timer allows it.
 *
 * This routine is intentionally conservative: it runs only on safe browser reads,
 * never changes the admin checkbox when beta code is active, and throttles remote
 * checks to one attempt per installation per configured interval.
 *
 * @param int $ttlSeconds Ttl seconds value.
 */
function application_autoupdate_maybe_run(int $ttlSeconds = 18000): void
{
    // Finish a previously started background job before considering a new remote check.
    $activeJob = application_update_active_job();
    if ($activeJob !== null) {
        application_update_continue_background_job(3.0);
        return;
    }

    // $ttlSeconds stores the minimum remote check interval. Five hours is the default
    // so shared hosting installations do not burn anonymous GitHub API quota on
    // normal page traffic. Manual dry checks intentionally bypass this throttle.
    $ttlSeconds = max(18000, $ttlSeconds);
    // $method stores the current HTTP verb so uploads, votes, edits, and CSRF flows are not interrupted.
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
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

    if (application_update_beta_active()) {
        application_autoupdate_dry_run(false, $now);
        return;
    }
    application_autoupdate_run_installing_check(false, $now);
}

/**
 * Run the automatic update check without installing anything.
 *
 * Beta installs use this path so the admin can validate GitHub connectivity,
 * update detection, throttling, and the Last automatic check diagnostics without
 * replacing the pinned beta commit. Manual admin dry runs can force the same
 * safe check immediately from the update page.
 *
 * @param bool $force Force value.
 * @param ?int $checkedAt Checked at value.
 * @return array Structured result data for the caller.
 */
function application_autoupdate_dry_run(bool $force = false, ?int $checkedAt = null): array
{
    // $now stores the timestamp written to the shared automatic update diagnostics.
    $now = $checkedAt ?? time();
    // $lockUntil stores a soft process lock so concurrent dry-run checks do not fan out.
    $lockUntil = (int) app_setting('application_autoupdate_lock_until', '0');
    if (!$force && $lockUntil > $now) {
        return application_autoupdate_status();
    }

    set_app_setting('application_autoupdate_last_checked_at', (string) $now);
    set_app_setting('application_autoupdate_lock_until', (string) ($now + 600));

    try {
        // $status stores the same update payload used by the manual update page.
        $status = check_application_update();
        cache_application_update_check($status);
        // $result stores a compact diagnostic value shown in the automatic update card.
        $result = application_autoupdate_dry_run_result_label($status);
        set_app_setting('application_autoupdate_last_result', $result);
        admin_log_event('info', 'update.autoupdate_dry_run_checked', t('admin.updates.log_autoupdate_dry_run_checked', 'Automatic update dry run checked for a newer stable release without installing it.'), [
            'result' => $result,
            'current_version' => cms_current_version(),
            'latest_version' => (string) ($status['latest_version'] ?? ''),
            'update_available' => !empty($status['update_available']),
            'beta_active' => application_update_beta_active(),
            'forced' => $force,
            'version_source' => (string) ($status['version_source'] ?? ''),
            'diagnostic' => (string) ($status['diagnostic'] ?? ''),
        ], ['category' => 'update', 'severity' => 'notice']);
        return application_autoupdate_status();
    } catch (Throwable $exception) {
        $safe = application_update_safe_error($exception);
        set_app_setting('application_autoupdate_last_result', 'dry_run_failed:' . $safe['reference']);
        admin_log_event('warning', 'update.autoupdate_dry_run_failed', t('admin.updates.log_autoupdate_dry_run_failed', 'Automatic update dry run failed.'), [
            'error_reference' => $safe['reference'],
            'current_version' => cms_current_version(),
            'beta_active' => application_update_beta_active(),
            'php_version' => PHP_VERSION,
            'forced' => $force,
        ], ['category' => 'update', 'severity' => 'error']);
        return application_autoupdate_status();
    } finally {
        delete_app_settings(['application_autoupdate_lock_until']);
    }
}

/**
 * Return a compact persisted result label for a dry automatic update check.
 *
 * @param array $status Status value.
 * @return string Text result for the caller.
 */
function application_autoupdate_dry_run_result_label(array $status): string
{
    if (!empty($status['error'])) {
        return 'dry_run_check_failed';
    }

    if (application_update_status_is_pending($status)) {
        return 'dry_run_update_available:' . (string) ($status['latest_version'] ?? 'unknown');
    }

    return 'dry_run_no_update';
}

/**
 * Run the automatic update check and install a stable release when pending.
 *
 * @param bool $force Force value.
 * @param ?int $checkedAt Checked at value.
 * @return array Structured result data for the caller.
 */
function application_autoupdate_run_installing_check(bool $force = false, ?int $checkedAt = null): array
{
    $now = $checkedAt ?? time();
    $lockUntil = (int) app_setting('application_autoupdate_lock_until', '0');
    if (!$force && $lockUntil > $now) {
        return application_autoupdate_status();
    }

    set_app_setting('application_autoupdate_last_checked_at', (string) $now);
    set_app_setting('application_autoupdate_lock_until', (string) ($now + 120));
    try {
        $status = check_application_update();
        cache_application_update_check($status);
        if (!application_update_status_is_pending($status)) {
            set_app_setting('application_autoupdate_last_result', empty($status['error']) ? 'no_update' : 'check_failed');
            return application_autoupdate_status();
        }

        $job = application_update_start_job('stable_update', [
            'branch' => (string) ($status['branch'] ?? ''),
            'target_version' => (string) ($status['latest_version'] ?? ''),
        ], 'background');
        // Do not combine remote discovery and package processing in one request.
        // The next safe page request, Admin poll, or cron invocation advances the job.
        set_app_setting('application_autoupdate_last_result', 'job:' . (string) $job['id'] . ':' . (string) $job['stage']);
        admin_log_event('info', 'update.autoupdate_started', t('admin.updates.log_autoupdate_installed', 'Automatic application update started a resumable stable release job.'), [
            'job_id' => (string) $job['id'],
            'stage' => (string) $job['stage'],
            'status' => (string) $job['status'],
        ], ['category' => 'update', 'severity' => 'notice']);
        return application_autoupdate_status();
    } catch (Throwable $exception) {
        $safe = application_update_safe_error($exception);
        set_app_setting('application_autoupdate_last_result', 'failed:' . $safe['reference']);
        admin_log_event('warning', 'update.autoupdate_failed', t('admin.updates.log_autoupdate_failed', 'Automatic application update failed.'), [
            'error_reference' => $safe['reference'],
            'current_version' => cms_current_version(),
            'beta_active' => application_update_beta_active(),
            'forced' => $force,
        ], ['category' => 'update', 'severity' => 'error']);
        return application_autoupdate_status();
    } finally {
        delete_app_settings(['application_autoupdate_lock_until']);
    }
}

/**
 * Return the stored beta backup archive path.
 *
 * @return string Text result for the caller.
 */
function application_update_beta_backup_path(): string
{
    return (string) app_setting('application_update_beta_backup_path', '');
}

/**
 * Install a beta/manual code archive over the current application files.
 *
 * @param string $commitId Commit id identifier.
 * @return array Structured result data for the caller.
 */
function install_application_beta(string $commitId): array
{
    return application_update_start_job('beta_install', ['commit' => $commitId], 'api');
}

/**
 * Restore the stable release from the GitHub branch head.
 *
 * @return array Structured result data for the caller.
 */
function restore_application_stable_release(): array
{
    return application_update_start_job('stable_restore', [], 'api');
}

/**
 * Backward-compatible wrapper for the stable release restore.
 *
 * @return array Structured result data for the caller.
 */
function restore_application_stable_backup(): array
{
    return restore_application_stable_release();
}


/**
 * Reinstall the stable branch head over the current site and remove unmanaged application files.
 *
 * @return array Structured result data for the caller.
 */
function clean_reinstall_current_application_version(): array
{
    return application_update_start_job('clean_reinstall', [], 'api');
}


/**
 * Return the admin label for links that point to the update screen.
 *
 * @param bool $pending Pending value.
 * @return string Text result for the caller.
 */
function application_update_nav_label(bool $pending): string
{
    return $pending ? t('admin.menu.update_pending', 'Update(1)') : t('admin.menu.updates', 'Updates');
}

/**
 * Install the newest GitHub branch archive over application-managed files.
 *
 * @return array Structured result data for the caller.
 */
function install_application_update(): array
{
    return application_update_start_job('stable_update', [], 'api');
}
