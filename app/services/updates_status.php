<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_status.php
 * Module Type: Service
 *
 * Purpose:
 *   Handles update discovery status, GitHub policy state, and cached update checks.
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
 * Return the configured upstream project URL.
 *
 * @return string Text result for the caller.
 */
function cms_github_project_url(): string
{
    return 'https://github.com/' . CMS_GITHUB_REPOSITORY;
}

/**
 * Check GitHub release metadata for the newest published application version.
 *
 * @param bool $force Force value.
 * @param float $requestedBudgetSeconds Preferred wall-clock budget for remote metadata I/O.
 * @return array<string,mixed> Structured result data for the caller.
 */
function check_application_update(bool $force = false, float $requestedBudgetSeconds = 8.0): array
{
    // $force bypasses only PHP Gallery's local metadata cache. It never bypasses
    // GitHub Retry-After or x-ratelimit-reset wait windows.
    // $waitState stores GitHub policy backoff data from previous responses.
    $waitState = application_update_github_wait_state();
    if (!empty($waitState['active'])) {
        return application_update_rate_limited_status($waitState);
    }

    // Keep remote metadata discovery bounded too. This is separate from package
    // processing, but it still runs inside browser/background PHP requests.
    $requestedBudgetSeconds = max(2.0, min(10.0, $requestedBudgetSeconds));
    $phpMax = (int) ini_get('max_execution_time');
    if ($phpMax > 0) {
        $requestedBudgetSeconds = min($requestedBudgetSeconds, max(1.0, (float) $phpMax - 5.0));
    }
    $deadline = microtime(true) + $requestedBudgetSeconds;
    // $lastError stores the newest transport or parsing error from the remote checks.
    $lastError = null;
    // $latestStatus stores the newest valid remote version payload found across allowed branches.
    $latestStatus = null;
    // $reachableBranch stores the first branch where GitHub answered, even if no version marker was present.
    $reachableBranch = null;
    // $markerDiagnostics stores human-readable marker failures for admin diagnostics and dry-run logs.
    $markerDiagnostics = [];

    $branches = array_values(application_update_branch_candidates());
    foreach ($branches as $branchIndex => $branch) {
        $remaining = $deadline - microtime(true);
        if ($remaining < 0.75) {
            $markerDiagnostics['budget'] = 'Remote update check stopped at its per-request wall-clock budget.';
            break;
        }

        // Share the remaining budget across unprobed branches so a dead preferred
        // branch cannot consume the complete request and starve the fallback branch.
        $branchesRemaining = max(1, count($branches) - $branchIndex);
        $branchDeadline = min($deadline, microtime(true) + max(0.75, $remaining / $branchesRemaining));
        try {
            // $versionResult stores valid version candidates plus fetch diagnostics for the current branch.
            $versionResult = application_update_remote_version_result($branch, $branchDeadline);
            if (!empty($versionResult['reachable']) && $reachableBranch === null) {
                $reachableBranch = $branch;
            }
            // $versionCandidates stores the valid remote version candidates found for this branch.
            $versionCandidates = (array) ($versionResult['candidates'] ?? []);
            if ($versionCandidates === []) {
                $markerDiagnostics[$branch] = (string) ($versionResult['diagnostic'] ?? ('No version marker was found on branch ' . $branch . '.'));
                continue;
            }

            // $latestVersion stores the highest valid version advertised by this branch.
            $latestVersion = application_update_highest_version($versionCandidates);
            // $currentVersion stores the installed release so stale remote branches never look like a downgrade target.
            $currentVersion = cms_current_version();
            // $displayVersion stores the version shown in admin cards and cached diagnostics.
            $displayVersion = version_compare($latestVersion, $currentVersion, '<') ? $currentVersion : $latestVersion;
            // $statusDiagnostic stores a non-fatal note when GitHub reports an older marker than this install.
            $statusDiagnostic = version_compare($latestVersion, $currentVersion, '<') ? ('GitHub branch ' . $branch . ' reports version ' . $latestVersion . ', which is older than installed version ' . $currentVersion . '.') : '';
            // $status stores the normalized update state used by the admin UI and automatic updater.
            $status = [
                'current_version' => $currentVersion,
                'latest_version' => $displayVersion,
                'branch' => $branch,
                'repository' => CMS_GITHUB_REPOSITORY,
                'update_available' => version_compare($latestVersion, $currentVersion, '>'),
                'version_sources' => $versionCandidates,
                'version_source' => $statusDiagnostic !== '' ? 'installed fallback' : application_update_version_source_label($versionCandidates, $latestVersion),
                'error' => null,
                'diagnostic' => $statusDiagnostic,
                'remote_older_than_installed' => $statusDiagnostic !== '',
            ];
            if ($latestStatus === null || version_compare($latestVersion, (string) $latestStatus['latest_version'], '>')) {
                $latestStatus = $status;
            }
            // main/master are ordered compatibility fallbacks. A valid preferred
            // marker owns discovery; probing a second branch adds quota and may
            // select an unrelated historical branch.
            application_patch_notes_refresh_pending($branch, $latestVersion, $deadline);
            break;
        } catch (Throwable $exception) {
            $safe = application_update_safe_error($exception);
            $lastError = $safe['message'] . ' Reference: ' . $safe['reference'];
            $markerDiagnostics[$branch] = 'Remote update check failed. Reference: ' . $safe['reference'];
        }
    }

    if ($latestStatus !== null) {
        return $latestStatus;
    }

    if ($reachableBranch !== null) {
        return [
            'current_version' => cms_current_version(),
            'latest_version' => cms_current_version(),
            'branch' => $reachableBranch,
            'repository' => CMS_GITHUB_REPOSITORY,
            'update_available' => false,
            'version_sources' => ['installed fallback' => cms_current_version()],
            'version_source' => 'installed fallback',
            'error' => null,
            'diagnostic' => implode(' ', array_filter($markerDiagnostics)),
            'remote_marker_missing' => true,
        ];
    }

    return [
        'current_version' => cms_current_version(),
        'latest_version' => null,
        'branch' => implode(' or ', application_update_branch_candidates()),
        'repository' => CMS_GITHUB_REPOSITORY,
        'update_available' => false,
        'version_sources' => [],
        'version_source' => '',
        'error' => $lastError ?? 'Could not contact GitHub.',
        'diagnostic' => implode(' ', array_filter($markerDiagnostics)),
    ];
}


/**
 * Return a cache-aware update status for the admin page.
 *
 * @param bool $force Force value.
 * @param int $ttlSeconds Cache lifetime in seconds.
 * @param bool $onlyIfDue Whether an hourly check should skip a recent concurrent discovery.
 * @return array<string,mixed> Structured result data for the caller.
 */
function application_update_status_for_admin(bool $force = false, int $ttlSeconds = 3600, bool $onlyIfDue = false): array
{
    if (!$force) {
        // $cachedStatus stores GitHub metadata already fetched by automatic or manual checks.
        // The admin update page is intentionally passive on GET renders: it may show stale
        // local metadata, but it must not spend GitHub quota merely because the admin
        // refreshed the browser. Fresh checks are owned by the Force check button and
        // by the automatic updater timer.
        $cachedStatus = cached_application_update_check($ttlSeconds, false);
        if ($cachedStatus !== []) {
            return $cachedStatus;
        }

        return application_update_unknown_cached_status();
    }

    // Serialize explicit checks across browser tabs without waiting on another
    // network request. Page reloads keep their passive cached presentation.
    $lock = @fopen(application_update_project_root() . '/cache/update-discovery.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) { fclose($lock); }
        return application_update_status_for_admin(false, $ttlSeconds);
    }
    try {
        rewind($lock);
        if ($onlyIfDue && time() - (int) stream_get_contents($lock) < $ttlSeconds) {
            return application_update_status_for_admin(false, $ttlSeconds);
        }
        $status = check_application_update($force);
        cache_application_update_check($status);
        ftruncate($lock, 0);
        rewind($lock);
        fwrite($lock, (string) time());
        return $status;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Refresh metadata at most hourly when the Updates page opens.
 *
 * @return array<string,mixed> Cached or refreshed release metadata; never installs a package.
 */
function application_update_check_if_due(): array
{
    if (time() - (int) app_setting('application_update_check_cached_at', '0') < 3600) {
        return application_update_status_for_admin(false);
    }
    return application_update_status_for_admin(true, 3600, true);
}

/**
 * Reconcile cached discovery with a verified installed package without HTTP.
 *
 * Preserve a known newer release for rollback/beta operations while ensuring a
 * successful installation cannot leave its former Update button pending.
 *
 * @param string $installedVersion Version read from the activated bootstrap.
 * @param string $branch Installed branch when known.
 * @return array<string,mixed> Locally reconciled release metadata.
 */
function application_update_installed_status(string $installedVersion, string $branch): array
{
    $installedVersion = application_update_normalize_version($installedVersion) ?? throw new RuntimeException('Installed package version is unavailable.');
    $status = cached_application_update_check(3600, false);
    $latest = application_update_normalize_version((string) ($status['latest_version'] ?? ''));
    $status['current_version'] = $installedVersion;
    $status['latest_version'] = $latest !== null && version_compare($latest, $installedVersion, '>') ? $latest : $installedVersion;
    $status['update_available'] = version_compare($status['latest_version'], $installedVersion, '>');
    $status['repository'] = CMS_GITHUB_REPOSITORY;
    $status['branch'] = in_array($branch, application_update_branch_candidates(), true) ? $branch : ($status['branch'] ?? application_update_branch_candidates()[0]);
    $status['error'] = null;
    $status['diagnostic'] = '';
    unset($status['local_cache_only'], $status['remote_marker_missing'], $status['remote_older_than_installed'], $status['github_policy_wait']);
    return $status;
}

/**
 * Return the next safe GitHub request time according to saved rate-limit policy data.
 *
 * @return array Structured result data for the caller.
 */
function application_update_github_wait_state(): array
{
    return cms_github_api_wait_state();
}

/**
 * Build a non-network update status when GitHub asked this installation to wait.
 *
 * @param array $waitState Wait state value.
 * @return array Structured result data for the caller.
 */
function application_update_rate_limited_status(array $waitState): array
{
    return [
        'current_version' => cms_current_version(),
        'latest_version' => null,
        'branch' => implode(' or ', application_update_branch_candidates()),
        'repository' => CMS_GITHUB_REPOSITORY,
        'update_available' => false,
        'version_sources' => [],
        'version_source' => '',
        'error' => 'GitHub update checks are paused until ' . (string) ($waitState['next_allowed_label'] ?? '') . ' because the previous response asked this installation to wait.',
        'diagnostic' => 'The updater is respecting GitHub rate-limit headers and did not make a new request.',
        'github_policy_wait' => $waitState,
    ];
}

/**
 * Return persisted GitHub API diagnostics for the update page.
 *
 * @return array Structured result data for the caller.
 */
function application_update_github_api_status(): array
{
    return cms_github_api_status();
}

/**
 * Persist GitHub API headers and calculate safe retry windows from official response headers.
 *
 * @param string $url URL used by this workflow.
 * @param int $status Status value.
 * @param array $headers Headers value.
 */
function application_update_record_github_response(string $url, int $status, array $headers): void
{
    cms_github_api_record_response($url, $status, $headers);
}
