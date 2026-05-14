<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
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
 */
function cms_github_project_url(): string
{
    return 'https://github.com/' . CMS_GITHUB_REPOSITORY;
}

/**
 * Check GitHub release metadata for the newest published application version.
 */
function check_application_update(bool $force = false): array
{
    // $force bypasses only PHP Gallery's local metadata cache. It never bypasses
    // GitHub Retry-After or x-ratelimit-reset wait windows.
    // $waitState stores GitHub policy backoff data from previous responses.
    $waitState = application_update_github_wait_state();
    if (!empty($waitState['active'])) {
        return application_update_rate_limited_status($waitState);
    }
    // $lastError stores the newest transport or parsing error from the remote checks.
    $lastError = null;
    // $latestStatus stores the newest valid remote version payload found across allowed branches.
    $latestStatus = null;
    // $reachableBranch stores the first branch where GitHub answered, even if no version marker was present.
    $reachableBranch = null;
    // $markerDiagnostics stores human-readable marker failures for admin diagnostics and dry-run logs.
    $markerDiagnostics = [];

    foreach (application_update_branch_candidates() as $branch) {
        try {
            // $versionResult stores valid version candidates plus fetch diagnostics for the current branch.
            $versionResult = application_update_remote_version_result($branch);
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
        } catch (Throwable $exception) {
            $lastError = $exception->getMessage();
            $markerDiagnostics[$branch] = $exception->getMessage();
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
 */
function application_update_status_for_admin(bool $force = false, int $ttlSeconds = 18000): array
{
    if ($force) {
        // $status stores a fresh GitHub probe explicitly requested by the administrator.
        $status = check_application_update(true);
        cache_application_update_check($status);
        return $status;
    }

    // $cachedStatus stores the newest persisted GitHub metadata for passive page rendering.
    $cachedStatus = application_update_read_cached_status(false, $ttlSeconds);
    if ($cachedStatus !== []) {
        return $cachedStatus;
    }

    return application_update_passive_uncached_status();
}

/**
 * Return a local status when the update page has no cached GitHub metadata yet.
 */
function application_update_passive_uncached_status(): array
{
    return [
        'current_version' => cms_current_version(),
        'latest_version' => null,
        'branch' => implode(' or ', application_update_branch_candidates()),
        'repository' => CMS_GITHUB_REPOSITORY,
        'update_available' => false,
        'version_sources' => [],
        'version_source' => '',
        'error' => null,
        'diagnostic' => 'No cached GitHub update status exists yet. Use Force check to contact GitHub explicitly.',
        'passive_uncached' => true,
    ];
}

/**
 * Return the next safe GitHub request time according to saved rate-limit policy data.
 */
function application_update_github_wait_state(): array
{
    // $now stores a single timestamp used for every comparison in this decision.
    $now = time();
    // $retryAfterUntil stores a Retry-After based pause after secondary limits or 429/403 responses.
    $retryAfterUntil = (int) app_setting('application_update_github_retry_after_until', '0');
    // $primaryResetAt stores the x-ratelimit-reset time when GitHub reported zero remaining core quota.
    $primaryResetAt = (int) app_setting('application_update_github_primary_reset_at', '0');
    // $nextAllowedAt stores the strictest known wait target.
    $nextAllowedAt = max($retryAfterUntil, $primaryResetAt);
    // $reason stores which saved policy guard selected the current wait.
    $reason = '';
    if ($nextAllowedAt > 0 && $nextAllowedAt === $primaryResetAt) {
        $reason = 'primary_rate_limit_reset';
    }
    if ($nextAllowedAt > 0 && $nextAllowedAt === $retryAfterUntil && $retryAfterUntil >= $primaryResetAt) {
        $reason = 'retry_after_or_secondary_backoff';
    }

    return [
        'active' => $nextAllowedAt > $now,
        'now' => $now,
        'next_allowed_at' => $nextAllowedAt,
        'retry_after_until' => $retryAfterUntil,
        'primary_reset_at' => $primaryResetAt,
        'reason' => $reason,
        'next_allowed_label' => $nextAllowedAt > 0 ? date('Y-m-d H:i:s', $nextAllowedAt) : '',
    ];
}

/**
 * Build a non-network update status when GitHub asked this installation to wait.
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
 */
function application_update_github_api_status(): array
{
    // $headersJson stores the latest parsed rate-limit headers from a GitHub API response.
    $headersJson = (string) app_setting('application_update_github_headers_json', '');
    // $headers stores normalized GitHub headers when they were captured successfully.
    $headers = json_decode($headersJson, true);
    if (!is_array($headers)) {
        $headers = [];
    }

    // $lastCheckedAt stores the most recent attempted GitHub HTTP request timestamp.
    $lastCheckedAt = (int) app_setting('application_update_github_last_checked_at', '0');
    // $waitState stores active policy backoff information.
    $waitState = application_update_github_wait_state();
    // $resetAt stores the GitHub primary rate-limit reset timestamp when available.
    $resetAt = (int) ($headers['x-ratelimit-reset'] ?? 0);

    return [
        'last_checked_at' => $lastCheckedAt,
        'last_checked_label' => $lastCheckedAt > 0 ? date('Y-m-d H:i:s', $lastCheckedAt) : t('admin.updates.autoupdate_last_check_never', 'never'),
        'last_status' => (string) app_setting('application_update_github_last_status', ''),
        'last_url' => (string) app_setting('application_update_github_last_url', ''),
        'limit' => (string) ($headers['x-ratelimit-limit'] ?? ''),
        'remaining' => (string) ($headers['x-ratelimit-remaining'] ?? ''),
        'used' => (string) ($headers['x-ratelimit-used'] ?? ''),
        'resource' => (string) ($headers['x-ratelimit-resource'] ?? ''),
        'reset_at' => $resetAt,
        'reset_label' => $resetAt > 0 ? date('Y-m-d H:i:s', $resetAt) : '',
        'retry_after' => (string) ($headers['retry-after'] ?? ''),
        'secondary_backoff_seconds' => (string) app_setting('application_update_github_secondary_backoff_seconds', ''),
        'wait' => $waitState,
    ];
}

/**
 * Persist GitHub API headers and calculate safe retry windows from official response headers.
 */
function application_update_record_github_response(string $url, int $status, array $headers): void
{
    // $normalizedHeaders stores only headers relevant to GitHub API diagnostics and backoff.
    $normalizedHeaders = [];
    foreach ($headers as $name => $value) {
        // $lowerName stores the canonical lowercase header key used in GitHub documentation.
        $lowerName = strtolower((string) $name);
        if (in_array($lowerName, ['x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-used', 'x-ratelimit-reset', 'x-ratelimit-resource', 'retry-after'], true)) {
            $normalizedHeaders[$lowerName] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }
    }

    // $now stores the response handling timestamp used by every persisted policy guard.
    $now = time();
    set_app_setting('application_update_github_last_checked_at', (string) $now);
    set_app_setting('application_update_github_last_status', (string) $status);
    set_app_setting('application_update_github_last_url', $url);
    set_app_setting('application_update_github_headers_json', json_encode($normalizedHeaders, JSON_UNESCAPED_SLASHES) ?: '{}');

    // $retryAfter stores the server-requested wait in seconds when GitHub sends Retry-After.
    $retryAfter = isset($normalizedHeaders['retry-after']) ? max(0, (int) $normalizedHeaders['retry-after']) : 0;
    if ($retryAfter > 0) {
        set_app_setting('application_update_github_retry_after_until', (string) ($now + $retryAfter));
    }

    // $remaining stores GitHub primary remaining quota from x-ratelimit-remaining.
    $remaining = isset($normalizedHeaders['x-ratelimit-remaining']) ? (int) $normalizedHeaders['x-ratelimit-remaining'] : null;
    // $resetAt stores GitHub primary reset time from x-ratelimit-reset.
    $resetAt = isset($normalizedHeaders['x-ratelimit-reset']) ? (int) $normalizedHeaders['x-ratelimit-reset'] : 0;
    if ($remaining === 0 && $resetAt > $now) {
        set_app_setting('application_update_github_primary_reset_at', (string) $resetAt);
    } elseif ($remaining !== 0) {
        delete_app_settings(['application_update_github_primary_reset_at']);
    }

    // $isRateLimitedStatus stores whether GitHub returned a status commonly used for primary or secondary limits.
    $isRateLimitedStatus = in_array($status, [403, 429], true);
    // $primaryLimitActive stores whether x-ratelimit-reset already describes the wait target.
    $primaryLimitActive = $remaining === 0 && $resetAt > $now;
    if ($isRateLimitedStatus && $retryAfter === 0 && !$primaryLimitActive) {
        // $backoffSeconds stores the exponential wait used when GitHub reports a secondary limit without Retry-After.
        $backoffSeconds = (int) app_setting('application_update_github_secondary_backoff_seconds', '60');
        $backoffSeconds = max(60, min(3600, $backoffSeconds));
        set_app_setting('application_update_github_retry_after_until', (string) ($now + $backoffSeconds));
        set_app_setting('application_update_github_secondary_backoff_seconds', (string) min(3600, $backoffSeconds * 2));
    }

    if ($status < 400 && $retryAfter === 0) {
        delete_app_settings(['application_update_github_retry_after_until', 'application_update_github_secondary_backoff_seconds']);
    }
}


/**
 * Return parsed remote patch notes for the update page viewer.
 */
function application_patch_notes_viewer_data(?string $preferredBranch = null, int $ttlSeconds = 1800, bool $allowRemote = true): array
{
    // $branch stores the trusted branch selected by the update checker or fallback candidates.
    $branch = in_array($preferredBranch, application_update_branch_candidates(), true) ? (string) $preferredBranch : (string) application_update_branch_candidates()[0];
    if ($ttlSeconds > 0) {
        // $cachedData stores the file-backed payload when it is still fresh enough for admin viewing.
        $cachedData = application_patch_notes_read_cache($branch, $ttlSeconds);
        if ($cachedData !== null) {
            return $cachedData;
        }
    }

    if (!$allowRemote) {
        // $staleCachedData stores the newest local patch-notes cache when passive rendering must avoid GitHub.
        $staleCachedData = application_patch_notes_read_cache($branch, 0);
        if ($staleCachedData !== null) {
            $staleCachedData['stale'] = true;
            return $staleCachedData;
        }
        return application_patch_notes_local_fallback($branch, 'Remote patch notes were not requested during passive page rendering.');
    }

    try {
        // $markdown stores the remote PATCH_NOTES.md text fetched from GitHub Contents API.
        $markdown = application_update_fetch_github_content($branch, 'PATCH_NOTES.md', 15);
        // $versions stores the parsed version sections keyed by normalized version number.
        $versions = application_patch_notes_parse_versions($markdown);
        // $data stores the viewer payload cached for subsequent page views.
        $data = [
            'ok' => true,
            'branch' => $branch,
            'cached_at' => time(),
            'source' => 'github-api',
            'versions' => $versions,
            'error' => '',
        ];
        application_patch_notes_write_cache($branch, $data);
        return $data;
    } catch (Throwable $exception) {
        return application_patch_notes_local_fallback($branch, $exception->getMessage());
    }
}

/**
 * Return bundled patch notes when remote patch notes are unavailable or intentionally passive.
 */
function application_patch_notes_local_fallback(string $branch, string $error): array
{
    // $localPath stores the bundled patch notes file used when GitHub is unavailable.
    $localPath = application_update_project_root() . '/PATCH_NOTES.md';
    // $localMarkdown stores the bundled patch notes text when it can be read safely.
    $localMarkdown = is_file($localPath) ? (string) file_get_contents($localPath) : '';
    return [
        'ok' => $localMarkdown !== '',
        'branch' => $branch,
        'cached_at' => time(),
        'source' => 'local',
        'versions' => $localMarkdown !== '' ? application_patch_notes_parse_versions($localMarkdown) : [],
        'error' => $error,
    ];
}

/**
 * Return the writable file-cache directory for remote patch notes payloads.
 */
function application_patch_notes_cache_dir(): string
{
    // $path stores the generated metadata cache directory outside the public asset path.
    $path = application_update_project_root() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'patch-notes';
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    return rtrim($path, DIRECTORY_SEPARATOR);
}

/**
 * Return the cache file path for a trusted update branch.
 */
function application_patch_notes_cache_path(string $branch): string
{
    // $safeBranch stores a filesystem-safe representation of the trusted branch name.
    $safeBranch = preg_replace('/[^a-z0-9_.-]+/i', '_', $branch) ?: 'main';
    return application_patch_notes_cache_dir() . DIRECTORY_SEPARATOR . $safeBranch . '.json';
}

/**
 * Read a fresh file-backed patch notes payload when available.
 */
function application_patch_notes_read_cache(string $branch, int $ttlSeconds): ?array
{
    // $path stores the cache file selected for the current GitHub branch.
    $path = application_patch_notes_cache_path($branch);
    if (!is_file($path)) {
        return null;
    }

    // $modifiedAt stores the cache write timestamp reported by the filesystem.
    $modifiedAt = filemtime($path);
    if ($modifiedAt === false) {
        return null;
    }
    if ($ttlSeconds > 0 && time() - $modifiedAt > $ttlSeconds) {
        return null;
    }

    // $json stores the cached JSON payload.
    $json = (string) file_get_contents($path);
    // $data stores the decoded payload when it matches the expected shape.
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['versions']) || !is_array($data['versions'])) {
        return null;
    }

    return $data;
}

/**
 * Return cache diagnostics for remote patch notes shown in the update page.
 */
function application_patch_notes_cache_status(string $branch, int $ttlSeconds = 18000): array
{
    // $ttlSeconds stores the intended five-hour patch notes cache lifetime.
    $ttlSeconds = max(60, $ttlSeconds);
    // $path stores the cache file selected for the current GitHub branch.
    $path = application_patch_notes_cache_path($branch);
    // $modifiedAt stores the filesystem timestamp when cached notes are available.
    $modifiedAt = is_file($path) ? (int) filemtime($path) : 0;
    // $now stores a single timestamp for all derived labels.
    $now = time();
    // $expiresAt stores when patch notes become eligible for a remote refresh.
    $expiresAt = $modifiedAt > 0 ? $modifiedAt + $ttlSeconds : 0;

    return [
        'has_cache' => $modifiedAt > 0,
        'cached_at' => $modifiedAt,
        'cached_at_label' => $modifiedAt > 0 ? date('Y-m-d H:i:s', $modifiedAt) : t('admin.updates.autoupdate_last_check_never', 'never'),
        'expires_at' => $expiresAt,
        'expires_at_label' => $expiresAt > 0 ? date('Y-m-d H:i:s', $expiresAt) : '',
        'fresh' => $modifiedAt > 0 && $now <= $expiresAt,
        'age_label' => $modifiedAt > 0 ? application_update_elapsed_label($now - $modifiedAt) : '',
        'ttl_seconds' => $ttlSeconds,
    ];
}

/**
 * Store a patch notes payload in the filesystem cache.
 */
function application_patch_notes_write_cache(string $branch, array $data): void
{
    // $path stores the cache file selected for the current GitHub branch.
    $path = application_patch_notes_cache_path($branch);
    // $json stores the payload without database column length constraints.
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    @file_put_contents($path, $json, LOCK_EX);
}

/**
 * Parse PATCH_NOTES.md into normalized version sections.
 */
function application_patch_notes_parse_versions(string $markdown): array
{
    // $lines stores the source text split into individual Markdown lines.
    $lines = preg_split('/\R/u', $markdown) ?: [];
    // $versions stores parsed release-note sections keyed by version number.
    $versions = [];
    // $currentVersion stores the version currently being collected.
    $currentVersion = null;
    // $currentTitle stores the raw heading text for the current version.
    $currentTitle = '';
    // $buffer stores Markdown lines belonging to the current version section.
    $buffer = [];

    foreach ($lines as $line) {
        if (preg_match('/^##\s+(?:Version\s+)?v?([0-9]+(?:\.[0-9]+){1,2})\b(.*)$/i', (string) $line, $match)) {
            if ($currentVersion !== null) {
                $versions[$currentVersion] = [
                    'version' => $currentVersion,
                    'title' => trim($currentTitle) !== '' ? trim($currentTitle) : 'Version ' . $currentVersion,
                    'markdown' => trim(implode("\n", $buffer)),
                    'html' => application_patch_notes_markdown_to_html(trim(implode("\n", $buffer))),
                ];
            }
            $currentVersion = application_update_normalize_version((string) $match[1]);
            $currentTitle = trim((string) preg_replace('/^##\s+/', '', (string) $line));
            $buffer = [];
            continue;
        }

        if ($currentVersion !== null) {
            $buffer[] = (string) $line;
        }
    }

    if ($currentVersion !== null) {
        $versions[$currentVersion] = [
            'version' => $currentVersion,
            'title' => trim($currentTitle) !== '' ? trim($currentTitle) : 'Version ' . $currentVersion,
            'markdown' => trim(implode("\n", $buffer)),
            'html' => application_patch_notes_markdown_to_html(trim(implode("\n", $buffer))),
        ];
    }

    uksort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
    return $versions;
}

/**
 * Convert the limited PATCH_NOTES.md syntax into safe admin HTML.
 */
function application_patch_notes_markdown_to_html(string $markdown): string
{
    if ($markdown === '') {
        return '<p class="muted">' . e(t('admin.updates.patch_notes_empty', 'No patch notes were found for this version.')) . '</p>';
    }

    // $html stores the generated safe HTML fragments.
    $html = [];
    // $inList tracks whether a Markdown list is currently open.
    $inList = false;
    // $inCode tracks whether a fenced code section is currently open.
    $inCode = false;
    // $codeLines stores raw lines inside a fenced code section.
    $codeLines = [];

    foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
        // $rawLine stores the unmodified Markdown line for code fences.
        $rawLine = (string) $line;
        // $trimmed stores a whitespace-trimmed copy for syntax checks.
        $trimmed = trim($rawLine);

        if (str_starts_with($trimmed, '```')) {
            if ($inCode) {
                $html[] = '<pre><code>' . e(implode("\n", $codeLines)) . '</code></pre>';
                $codeLines = [];
                $inCode = false;
            } else {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $inCode = true;
            }
            continue;
        }

        if ($inCode) {
            $codeLines[] = $rawLine;
            continue;
        }

        if ($trimmed === '') {
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            continue;
        }

        if (preg_match('/^(#{3,6})\s+(.+)$/', $trimmed, $headingMatch)) {
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            // $level stores a bounded heading level suitable inside the update panel.
            $level = min(5, max(3, strlen((string) $headingMatch[1])));
            $html[] = '<h' . $level . '>' . application_patch_notes_inline_markdown((string) $headingMatch[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $listMatch)) {
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }
            $html[] = '<li>' . application_patch_notes_inline_markdown((string) $listMatch[1]) . '</li>';
            continue;
        }

        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
        $html[] = '<p>' . application_patch_notes_inline_markdown($trimmed) . '</p>';
    }

    if ($inCode) {
        $html[] = '<pre><code>' . e(implode("\n", $codeLines)) . '</code></pre>';
    }
    if ($inList) {
        $html[] = '</ul>';
    }

    return implode("\n", $html);
}

/**
 * Convert safe inline Markdown emphasis and code spans for patch notes.
 */
function application_patch_notes_inline_markdown(string $text): string
{
    // $escaped stores HTML-safe text before tiny Markdown replacements are applied.
    $escaped = e($text);
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    return $escaped;
}

/**
 * Return a cached update check for small UI badges.
 */
function cached_application_update_check(int $ttlSeconds = 3600): array
{
    static $requestCache = [];

    // $ttlSeconds stores the shortest acceptable cache lifetime for admin badges.
    $ttlSeconds = max(60, $ttlSeconds);
    // $cacheKey stores a per-lifetime in-request cache so callers can ask for different freshness windows safely.
    $cacheKey = (string) $ttlSeconds;
    if (isset($requestCache[$cacheKey]) && is_array($requestCache[$cacheKey])) {
        return (array) $requestCache[$cacheKey];
    }

    // $cachedStatus stores the persisted update-check payload only when it is still fresh enough.
    $cachedStatus = application_update_read_cached_status(true, $ttlSeconds);
    if ($cachedStatus !== []) {
        $requestCache[$cacheKey] = $cachedStatus;
    }
    return $cachedStatus;
}

/**
 * Read the persisted update status without contacting GitHub.
 */
function application_update_read_cached_status(bool $freshOnly = true, int $ttlSeconds = 3600): array
{
    // $ttlSeconds stores the freshness window used when stale cache entries should be ignored.
    $ttlSeconds = max(60, $ttlSeconds);
    // $cachedAt stores the Unix timestamp for the DB-backed update-check cache.
    $cachedAt = (int) app_setting('application_update_check_cached_at', '0');
    // $cachedJson stores the last update-check payload used by admin navigation badges and the update page.
    $cachedJson = (string) app_setting('application_update_check_status_json', '');
    if ($cachedAt <= 0 || $cachedJson === '') {
        return [];
    }
    if ($freshOnly && time() - $cachedAt > $ttlSeconds) {
        return [];
    }

    // $cachedStatus stores the decoded update-check payload when it matches the expected shape.
    $cachedStatus = json_decode($cachedJson, true);
    if (!is_array($cachedStatus)) {
        return [];
    }

    return $cachedStatus;
}

/**
 * Return cache diagnostics for update metadata shown in the update page.
 */
function application_update_check_cache_status(int $ttlSeconds = 18000): array
{
    // $ttlSeconds stores the intended five-hour metadata cache lifetime.
    $ttlSeconds = max(60, $ttlSeconds);
    // $cachedAt stores the timestamp written when the latest metadata check was cached.
    $cachedAt = (int) app_setting('application_update_check_cached_at', '0');
    // $now stores a single timestamp for all derived labels.
    $now = time();
    // $expiresAt stores when automatic metadata refreshes become eligible again.
    $expiresAt = $cachedAt > 0 ? $cachedAt + $ttlSeconds : 0;

    return [
        'has_cache' => $cachedAt > 0 && (string) app_setting('application_update_check_status_json', '') !== '',
        'cached_at' => $cachedAt,
        'cached_at_label' => $cachedAt > 0 ? date('Y-m-d H:i:s', $cachedAt) : t('admin.updates.autoupdate_last_check_never', 'never'),
        'expires_at' => $expiresAt,
        'expires_at_label' => $expiresAt > 0 ? date('Y-m-d H:i:s', $expiresAt) : '',
        'fresh' => $cachedAt > 0 && $now <= $expiresAt,
        'age_label' => $cachedAt > 0 ? application_update_elapsed_label($now - $cachedAt) : '',
        'ttl_seconds' => $ttlSeconds,
    ];
}

/**
 * Return a concise elapsed-time label for update diagnostics.
 */
function application_update_elapsed_label(int $seconds): string
{
    // $seconds stores the clamped elapsed value used for display only.
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return t('admin.updates.autoupdate_relative_seconds', 'just now');
    }

    // $minutes stores rounded-down elapsed minutes.
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return t('admin.updates.autoupdate_relative_minutes', '{count} minute(s) ago', ['count' => (string) $minutes]);
    }

    // $hours stores rounded-down elapsed hours.
    $hours = intdiv($minutes, 60);
    if ($hours < 48) {
        return t('admin.updates.autoupdate_relative_hours', '{count} hour(s) ago', ['count' => (string) $hours]);
    }

    // $days stores rounded-down elapsed days.
    $days = intdiv($hours, 24);
    return t('admin.updates.autoupdate_relative_days', '{count} day(s) ago', ['count' => (string) $days]);
}

/**
 * Store an update check result for badge rendering.
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
 */
function application_update_pending(): bool
{
    // $status stores the last known metadata only. Navigation labels must never contact GitHub.
    $status = application_update_read_cached_status(false, 3600);
    return application_update_status_is_pending($status);
}

/**
 * Return true when the application is currently on a beta/manual commit install.
 */
function application_update_beta_active(): bool
{
    return app_setting('application_update_channel', 'stable') === 'beta' && app_setting('application_update_beta_commit', '') !== '';
}

/**
 * Return the currently installed beta code, if any.
 */
function application_update_beta_commit(): string
{
    return (string) app_setting('application_update_beta_commit', '');
}

/**
 * Return true when automatic stable updates are enabled by admin settings.
 */
function application_autoupdate_enabled(): bool
{
    return app_setting('application_autoupdate_enabled', '1') === '1';
}

/**
 * Persist the automatic stable update setting from the admin maintenance page.
 */
function set_application_autoupdate_enabled(bool $enabled): void
{
    set_app_setting('application_autoupdate_enabled', $enabled ? '1' : '0');
}

/**
 * Return diagnostic state for the automatic updater card.
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
    // $nextEligibleAt stores when request-time automatic checks may contact GitHub again.
    $nextEligibleAt = $lastCheckedAt > 0 ? $lastCheckedAt + 18000 : 0;

    return [
        'enabled' => $enabled,
        'effective' => $enabled && !$betaActive,
        'beta_active' => $betaActive,
        'last_checked_at' => $lastCheckedAt,
        'last_checked_label' => $lastCheckedLabel,
        'last_checked_relative' => $lastCheckedRelative,
        'next_eligible_at' => $nextEligibleAt,
        'next_eligible_label' => $nextEligibleAt > 0 ? date('Y-m-d H:i:s', $nextEligibleAt) : t('admin.updates.autoupdate_last_check_never', 'never'),
        'last_result' => $lastResult,
    ];
}

/**
 * Return a readable automatic update check timestamp for admin diagnostics.
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
 */
function application_autoupdate_maybe_run(int $ttlSeconds = 18000): void
{
    // $ttlSeconds stores the minimum remote check interval. Five hours is the default
    // so shared hosting installations do not burn anonymous GitHub API quota on
    // normal page traffic. Manual dry checks intentionally bypass this throttle.
    $ttlSeconds = max(18000, $ttlSeconds);
    // $method stores the current HTTP verb so uploads, votes, edits, and CSRF flows are not interrupted.
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }
    if (!application_autoupdate_enabled()) {
        return;
    }

    // $now stores one timestamp used consistently for throttle and lock state.
    $now = time();
    // $lastCheckedAt stores the latest automatic update check timestamp.
    $lastCheckedAt = (int) app_setting('application_autoupdate_last_checked_at', '0');
    if ($lastCheckedAt > 0 && $now - $lastCheckedAt < $ttlSeconds) {
        return;
    }

    // $lockUntil stores a soft process lock so concurrent requests do not all update at once.
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
        set_app_setting('application_autoupdate_last_result', 'dry_run_failed:' . $exception->getMessage());
        admin_log_event('warning', 'update.autoupdate_dry_run_failed', t('admin.updates.log_autoupdate_dry_run_failed', 'Automatic update dry run failed.'), [
            'error' => $exception->getMessage(),
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
 */
function application_autoupdate_run_installing_check(bool $force = false, ?int $checkedAt = null): array
{
    // $now stores the timestamp written to the shared automatic update diagnostics.
    $now = $checkedAt ?? time();
    // $lockUntil stores a soft process lock so concurrent requests do not all update at once.
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
        if (!application_update_status_is_pending($status)) {
            set_app_setting('application_autoupdate_last_result', empty($status['error']) ? 'no_update' : 'check_failed');
            return application_autoupdate_status();
        }

        // $result stores manual-updater diagnostics for the automatic update log entry.
        $result = install_application_update();
        set_app_setting('application_autoupdate_last_result', 'updated:' . (string) ($result['version'] ?? 'unknown'));
        admin_log_event('info', 'update.autoupdate_installed', t('admin.updates.log_autoupdate_installed', 'Automatic application update installed a newer stable release.'), $result, ['category' => 'update', 'severity' => 'notice']);
        return application_autoupdate_status();
    } catch (Throwable $exception) {
        set_app_setting('application_autoupdate_last_result', 'failed:' . $exception->getMessage());
        admin_log_event('warning', 'update.autoupdate_failed', t('admin.updates.log_autoupdate_failed', 'Automatic application update failed.'), [
            'error' => $exception->getMessage(),
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
 * Return the stored beta backup archive path.
 */
function application_update_beta_backup_path(): string
{
    return (string) app_setting('application_update_beta_backup_path', '');
}

/**
 * Install a beta/manual code archive over the current application files.
 */
function install_application_beta(string $commitId): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP ZipArchive extension is required for one-button updates.');
    }
    // $commitId stores an intermediate value used by the surrounding gallery workflow.
    $commitId = strtolower(trim($commitId));
    if (!preg_match('/^[0-9a-f]{7,40}$/', $commitId)) {
        throw new RuntimeException('Enter a valid beta code.');
    }

    // $root stores an intermediate value used by the surrounding gallery workflow.
    $root = application_update_project_root();
    // $updateDir stores an intermediate value used by the surrounding gallery workflow.
    $updateDir = $root . '/cache/updates';
    // $backupDir stores an intermediate value used by the surrounding gallery workflow.
    $backupDir = $updateDir . '/backups';
    application_update_ensure_dir($updateDir);
    application_update_ensure_dir($backupDir);

    // $stamp stores an intermediate value used by the surrounding gallery workflow.
    $stamp = date('Ymd-His');
    // $zipPath stores an intermediate value used by the surrounding gallery workflow.
    $zipPath = $updateDir . '/beta-' . $stamp . '.zip';
    // $extractDir stores an intermediate value used by the surrounding gallery workflow.
    $extractDir = $updateDir . '/beta-extract-' . $stamp;
    application_update_ensure_dir($extractDir);

    // $archive stores an intermediate value used by the surrounding gallery workflow.
    $archive = http_fetch(application_update_commit_zip_url($commitId), 60);
    if (file_put_contents($zipPath, $archive, LOCK_EX) === false) {
        throw new RuntimeException('Could not write beta archive into cache/updates.');
    }

    // $zip stores an intermediate value used by the surrounding gallery workflow.
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Downloaded beta archive could not be opened.');
    }
    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        throw new RuntimeException('Downloaded beta archive could not be extracted.');
    }
    $zip->close();

    // $sourceRoot stores an intermediate value used by the surrounding gallery workflow.
    $sourceRoot = application_update_extracted_root($extractDir);
    application_update_assert_source_root($sourceRoot);
    application_update_cleanup_transient_extracts($updateDir, $extractDir);
    // $backupPath stores an intermediate value used by the surrounding gallery workflow.
    $backupPath = $backupDir . '/before-beta-' . $stamp . '.zip';
    // $copyResult stores copy and cleanup diagnostics for this updater run.
    $copyResult = application_update_copy_files($sourceRoot, $root, $backupPath);
    // $copied stores the number of files copied from the downloaded snapshot.
    $copied = (int) $copyResult['files_copied'];
    // $migrations stores an intermediate value used by the surrounding gallery workflow.
    $migrations = run_migrations();
    application_update_invalidate_opcache($root, $sourceRoot);
    cache_application_update_check(check_application_update());
    set_app_setting('application_update_channel', 'beta');
    set_app_setting('application_update_beta_commit', $commitId);
    set_app_setting('application_update_beta_backup_path', str_replace('\\', '/', substr($backupPath, strlen($root) + 1)));
    delete_app_settings(['application_update_check_cache', 'application_update_check_status_json', 'application_update_check_cached_at']);

    return [
        'version' => $commitId,
        'branch' => 'beta',
        'files_copied' => $copied,
        'removed_paths' => $copyResult['removed_paths'],
        'removed_count' => $copyResult['removed_count'],
        'backup' => str_replace('\\', '/', substr($backupPath, strlen($root) + 1)),
        'migrations' => $migrations,
    ];
}

/**
 * Restore the stable release from the GitHub branch head.
 */
function restore_application_stable_release(): array
{
    // $root stores an intermediate value used by the surrounding gallery workflow.
    $root = application_update_project_root();
    // $branch stores an intermediate value used by the surrounding gallery workflow.
    $branch = application_update_branch_candidates()[0] ?? '';
    if ($branch === '') {
        throw new RuntimeException('No stable release branch is configured.');
    }
    // $updateDir stores an intermediate value used by the surrounding gallery workflow.
    $updateDir = $root . '/cache/updates';
    // $stamp stores an intermediate value used by the surrounding gallery workflow.
    $stamp = date('Ymd-His');
    // $restoreDir stores an intermediate value used by the surrounding gallery workflow.
    $restoreDir = $updateDir . '/stable-restore-' . $stamp;
    application_update_ensure_dir($updateDir);
    application_update_ensure_dir($restoreDir);

    // $archive stores an intermediate value used by the surrounding gallery workflow.
    $archive = http_fetch(application_update_zip_url($branch), 60);
    // $zipPath stores an intermediate value used by the surrounding gallery workflow.
    $zipPath = $updateDir . '/stable-restore-' . $stamp . '.zip';
    if (file_put_contents($zipPath, $archive, LOCK_EX) === false) {
        throw new RuntimeException('Could not write stable restore archive into cache/updates.');
    }

    // $zip stores an intermediate value used by the surrounding gallery workflow.
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Downloaded stable restore archive could not be opened.');
    }
    if (!$zip->extractTo($restoreDir)) {
        $zip->close();
        throw new RuntimeException('Downloaded stable restore archive could not be extracted.');
    }
    $zip->close();

    // $sourceRoot stores an intermediate value used by the surrounding gallery workflow.
    $sourceRoot = application_update_extracted_root($restoreDir);
    application_update_assert_source_root($sourceRoot);
    application_update_cleanup_transient_extracts($updateDir, $restoreDir);
    // $backupPath stores the rollback archive created before stable files are restored.
    $backupPath = $root . '/cache/updates/rollback-' . date('Ymd-His') . '.zip';
    // $copyResult stores copy and cleanup diagnostics for this updater run.
    $copyResult = application_update_copy_files($sourceRoot, $root, $backupPath);
    // $copied stores the number of files copied from the downloaded snapshot.
    $copied = (int) $copyResult['files_copied'];
    application_update_invalidate_opcache($root, $sourceRoot);
    delete_app_settings([
        'application_update_channel',
        'application_update_beta_commit',
        'application_update_beta_backup_path',
        'application_update_check_cache',
    ]);

    // $restoredVersion stores an intermediate value used by the surrounding gallery workflow.
    $restoredVersion = application_update_version_from_local_bootstrap($root . '/app/bootstrap.php') ?? cms_current_version();

    return [
        'version' => $restoredVersion,
        'branch' => 'stable',
        'files_copied' => $copied,
        'removed_paths' => $copyResult['removed_paths'],
        'removed_count' => $copyResult['removed_count'],
        'backup' => str_replace('\\', '/', substr($backupPath, strlen($root) + 1)),
        'archive' => str_replace('\\', '/', substr($zipPath, strlen($root) + 1)),
        'migrations' => [],
    ];
}

/**
 * Backward-compatible wrapper for the stable release restore.
 */
function restore_application_stable_backup(): array
{
    return restore_application_stable_release();
}


/**
 * Reinstall the stable branch head over the current site and remove unmanaged application files.
 */
function clean_reinstall_current_application_version(): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP ZipArchive extension is required for clean reinstall.');
    }
    // $root stores the verified project root that will be repaired.
    $root = application_update_project_root();
    // $branch stores the stable branch used as the clean source of truth.
    $branch = application_update_branch_candidates()[0] ?? '';
    if ($branch === '') {
        throw new RuntimeException('No stable release branch is configured.');
    }
    // $updateDir stores the local updater workspace.
    $updateDir = $root . '/cache/updates';
    // $backupDir stores rollback archives for files removed or replaced by this reinstall.
    $backupDir = $updateDir . '/backups';
    application_update_ensure_dir($updateDir);
    application_update_ensure_dir($backupDir);

    // $stamp stores a deterministic timestamp used by all artifacts from this run.
    $stamp = date('Ymd-His');
    // $zipPath stores the downloaded GitHub archive.
    $zipPath = $updateDir . '/clean-reinstall-' . $stamp . '.zip';
    // $extractDir stores the temporary extraction directory.
    $extractDir = $updateDir . '/stable-restore-' . $stamp;
    application_update_ensure_dir($extractDir);

    // $archive stores the downloaded repository snapshot bytes.
    $archive = http_fetch(application_update_zip_url($branch), 60);
    if (file_put_contents($zipPath, $archive, LOCK_EX) === false) {
        throw new RuntimeException('Could not write clean reinstall archive into cache/updates.');
    }

    // $zip stores the extracted repository archive.
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Downloaded clean reinstall archive could not be opened.');
    }
    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        throw new RuntimeException('Downloaded clean reinstall archive could not be extracted.');
    }
    $zip->close();

    // $sourceRoot stores the clean repository root from the extracted GitHub archive.
    $sourceRoot = application_update_extracted_root($extractDir);
    application_update_assert_source_root($sourceRoot);
    application_update_cleanup_transient_extracts($updateDir, $extractDir);
    // $backupPath stores the rollback archive for replaced and removed application files.
    $backupPath = $backupDir . '/before-clean-reinstall-' . $stamp . '.zip';
    // $copyResult stores copy and full cleanup diagnostics for this reinstall.
    $copyResult = application_update_copy_files($sourceRoot, $root, $backupPath, true);
    // $migrations stores database migrations applied after the clean file reinstall.
    $migrations = run_migrations();
    application_update_invalidate_opcache($root, $sourceRoot);
    delete_app_settings([
        'application_update_channel',
        'application_update_beta_commit',
        'application_update_beta_backup_path',
        'application_update_check_cache',
    ]);
    // $cacheCleanup stores generated ZIP and temporary cache cleanup diagnostics.
    $cacheCleanup = application_update_clean_cache_artifacts($root, $backupPath);

    // $installedVersion stores the version now visible from the reinstalled bootstrap.
    $installedVersion = application_update_version_from_local_bootstrap($root . '/app/bootstrap.php') ?? cms_current_version();
    return [
        'version' => $installedVersion,
        'branch' => $branch,
        'files_copied' => (int) $copyResult['files_copied'],
        'removed_paths' => $copyResult['removed_paths'],
        'removed_count' => $copyResult['removed_count'],
        'cache_cleanup' => $cacheCleanup,
        'backup' => str_replace('\\', '/', substr($backupPath, strlen($root) + 1)),
        'archive' => str_replace('\\', '/', substr($zipPath, strlen($root) + 1)),
        'migrations' => $migrations,
    ];
}


/**
 * Return the admin label for links that point to the update screen.
 */
function application_update_nav_label(bool $pending): string
{
    return $pending ? t('admin.menu.update_pending', 'Update(1)') : t('admin.menu.updates', 'Updates');
}

/**
 * Install the newest GitHub branch archive over application-managed files.
 */
function install_application_update(): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP ZipArchive extension is required for one-button updates.');
    }

    // $status stores an intermediate value used by the surrounding gallery workflow.
    $status = check_application_update();
    if (!empty($status['error'])) {
        throw new RuntimeException((string) $status['error']);
    }
    if (empty($status['update_available'])) {
        throw new RuntimeException('No newer version is available.');
    }

    // $root stores an intermediate value used by the surrounding gallery workflow.
    $root = application_update_project_root();
    // $updateDir stores an intermediate value used by the surrounding gallery workflow.
    $updateDir = $root . '/cache/updates';
    // $backupDir stores an intermediate value used by the surrounding gallery workflow.
    $backupDir = $updateDir . '/backups';
    application_update_ensure_dir($updateDir);
    application_update_ensure_dir($backupDir);

    // $stamp stores an intermediate value used by the surrounding gallery workflow.
    $stamp = date('Ymd-His');
    // $zipPath stores an intermediate value used by the surrounding gallery workflow.
    $zipPath = $updateDir . '/update-' . $stamp . '.zip';
    // $extractDir stores an intermediate value used by the surrounding gallery workflow.
    $extractDir = $updateDir . '/extract-' . $stamp;
    application_update_ensure_dir($extractDir);

    // $archive stores an intermediate value used by the surrounding gallery workflow.
    $archive = http_fetch(application_update_zip_url((string) $status['branch']), 60);
    if (file_put_contents($zipPath, $archive, LOCK_EX) === false) {
        throw new RuntimeException('Could not write update archive into cache/updates.');
    }

    // $zip stores an intermediate value used by the surrounding gallery workflow.
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Downloaded update archive could not be opened.');
    }
    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        throw new RuntimeException('Downloaded update archive could not be extracted.');
    }
    $zip->close();

    // $sourceRoot stores an intermediate value used by the surrounding gallery workflow.
    $sourceRoot = application_update_extracted_root($extractDir);
    application_update_assert_source_root($sourceRoot);
    application_update_cleanup_transient_extracts($updateDir, $extractDir);
    // $backupPath stores an intermediate value used by the surrounding gallery workflow.
    $backupPath = $backupDir . '/before-update-' . $stamp . '.zip';
    // $copyResult stores copy and cleanup diagnostics for this updater run.
    $copyResult = application_update_copy_files($sourceRoot, $root, $backupPath);
    // $copied stores the number of files copied from the downloaded snapshot.
    $copied = (int) $copyResult['files_copied'];
    // $migrations stores an intermediate value used by the surrounding gallery workflow.
    $migrations = run_migrations();
    application_update_invalidate_opcache($root, $sourceRoot);
    delete_app_settings(['application_update_check_cache', 'application_update_check_status_json', 'application_update_check_cached_at']);

    return [
        'version' => (string) $status['latest_version'],
        'branch' => (string) $status['branch'],
        'files_copied' => $copied,
        'removed_paths' => $copyResult['removed_paths'],
        'removed_count' => $copyResult['removed_count'],
        'backup' => str_replace('\\', '/', substr($backupPath, strlen($root) + 1)),
        'migrations' => $migrations,
    ];
}

/**
 * Return the branch names the updater should try, newest preference first.
 */
function application_update_branch_candidates(): array
{
    return CMS_UPDATE_BRANCHES;
}

/**
 * Build a GitHub archive URL for one code snapshot.
 */
function application_update_commit_zip_url(string $commitId): string
{
    if (!preg_match('/^[0-9a-f]{7,40}$/', $commitId)) {
        throw new RuntimeException('Enter a valid beta code.');
    }
    [$owner, $repo] = explode('/', CMS_GITHUB_REPOSITORY, 2);
    return 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/archive/' . rawurlencode($commitId) . '.zip';
}

/**
 * Build a GitHub branch zip URL.
 */
function application_update_zip_url(string $branch): string
{
    application_update_assert_allowed_branch($branch);
    [$owner, $repo] = explode('/', CMS_GITHUB_REPOSITORY, 2);
    return 'https://codeload.github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zip/refs/heads/' . rawurlencode($branch) . '?nocache=' . rawurlencode((string) time());
}

/**
 * Reject update sources outside the stable GitHub branches.
 */
function application_update_assert_allowed_branch(string $branch): void
{
    if (!in_array($branch, CMS_UPDATE_BRANCHES, true)) {
        throw new RuntimeException('Updates are allowed only from the main or master GitHub branch.');
    }
}

/**
 * Build a GitHub Contents API URL for one trusted branch file.
 */
function application_update_github_contents_api_url(string $branch, string $path): string
{
    application_update_assert_allowed_branch($branch);
    [$owner, $repo] = explode('/', CMS_GITHUB_REPOSITORY, 2);
    // $cleanPath stores the repository-relative file path accepted by the Contents API.
    $cleanPath = ltrim(str_replace('\\', '/', $path), '/');
    return 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/' . str_replace('%2F', '/', rawurlencode($cleanPath)) . '?ref=' . rawurlencode($branch);
}

/**
 * Fetch one small repository file through GitHub Contents API as raw text.
 */
function application_update_fetch_github_content(string $branch, string $path, int $timeoutSeconds): string
{
    // $waitState stores any saved GitHub policy pause from a previous API response.
    $waitState = application_update_github_wait_state();
    if (!empty($waitState['active'])) {
        throw new RuntimeException('GitHub API calls are paused until ' . (string) ($waitState['next_allowed_label'] ?? '') . ' because the previous response asked this installation to wait.');
    }

    // $headers stores the media type that asks GitHub to return raw file contents instead of JSON metadata.
    $headers = [
        'Accept: application/vnd.github.raw+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    // $url stores the exact GitHub API endpoint so diagnostics can show which API call was last made.
    $url = application_update_github_contents_api_url($branch, $path);
    // $response stores body, status, and headers for GitHub rate-limit accounting.
    $response = http_fetch_response_with_headers($url, $timeoutSeconds, $headers);
    application_update_record_github_response($url, (int) $response['status'], (array) $response['headers']);
    return (string) $response['body'];
}

/**
 * Read the remote version marker that identifies the newest branch version.
 */
function application_update_remote_version_candidates(string $branch): array
{
    // $result stores the richer branch probe while preserving the legacy return shape for callers.
    $result = application_update_remote_version_result($branch);
    return (array) ($result['candidates'] ?? []);
}

/**
 * Read remote version markers and keep diagnostics for branches without a marker.
 */
function application_update_remote_version_result(string $branch): array
{
    // $versionCandidates stores trusted version markers found in remote files.
    $versionCandidates = [];
    // $diagnostics stores non-fatal parsing details used by the update page and logs.
    $diagnostics = [];
    // $reachable stores whether at least one trusted GitHub file was fetched successfully.
    $reachable = false;

    try {
        // $bootstrap stores the remote bootstrap file fetched through GitHub Contents API.
        $bootstrap = application_update_fetch_github_content($branch, 'app/bootstrap.php', 12);
        $reachable = true;
        // $bootstrapVersion stores the version parsed from the bootstrap constant when present.
        $bootstrapVersion = application_update_version_from_bootstrap($bootstrap);
        if ($bootstrapVersion !== null) {
            $versionCandidates['app/bootstrap.php'] = $bootstrapVersion;
        } else {
            $diagnostics[] = 'No CMS_VERSION marker was found in app/bootstrap.php on branch ' . $branch . '.';
        }
    } catch (Throwable $exception) {
        $diagnostics[] = 'app/bootstrap.php: ' . $exception->getMessage();
    }

    if ($versionCandidates === []) {
        try {
            // $patchNotes stores the remote release notes used as a secondary version signal.
            $patchNotes = application_update_fetch_github_content($branch, 'PATCH_NOTES.md', 12);
            $reachable = true;
            // $patchNotesVersion stores the newest heading parsed from the release notes.
            $patchNotesVersion = application_update_version_from_patch_notes($patchNotes);
            if ($patchNotesVersion !== null) {
                $versionCandidates['PATCH_NOTES.md'] = $patchNotesVersion;
            } else {
                $diagnostics[] = 'No version heading was found in PATCH_NOTES.md on branch ' . $branch . '.';
            }
        } catch (Throwable $exception) {
            $diagnostics[] = 'PATCH_NOTES.md: ' . $exception->getMessage();
        }
    }

    return [
        'candidates' => array_filter($versionCandidates, static fn ($value): bool => is_string($value) && application_update_normalize_version($value) !== null),
        'reachable' => $reachable,
        'diagnostic' => implode(' ', array_filter($diagnostics)),
    ];
}

/**
 * Return the highest semantic version from remote version candidates.
 */
function application_update_highest_version(array $versionCandidates): string
{
    // $highestVersion stores an intermediate value used by the surrounding gallery workflow.
    $highestVersion = null;
    foreach ($versionCandidates as $version) {
        // $normalizedVersion stores an intermediate value used by the surrounding gallery workflow.
        $normalizedVersion = application_update_normalize_version((string) $version);
        if ($normalizedVersion === null) {
            continue;
        }
        if ($highestVersion === null || version_compare($normalizedVersion, $highestVersion, '>')) {
            // $highestVersion stores an intermediate value used by the surrounding gallery workflow.
            $highestVersion = $normalizedVersion;
        }
    }

    if ($highestVersion === null) {
        throw new RuntimeException('Remote version candidates did not contain a valid version number.');
    }

    return $highestVersion;
}

/**
 * Return a readable label for the remote source that provided the selected version.
 */
function application_update_version_source_label(array $versionCandidates, string $latestVersion): string
{
    // $labels stores an intermediate value used by the surrounding gallery workflow.
    $labels = [];
    foreach ($versionCandidates as $source => $version) {
        // $normalizedVersion stores an intermediate value used by the surrounding gallery workflow.
        $normalizedVersion = application_update_normalize_version((string) $version);
        if ($normalizedVersion === $latestVersion) {
            $labels[] = (string) $source;
        }
    }
    return implode(', ', $labels);
}

/**
 * Parse the CMS_VERSION constant from a remote bootstrap file.
 */
function application_update_version_from_bootstrap(string $bootstrap): ?string
{
    if (preg_match("/const\s+CMS_VERSION\s*=\s*['\"]([^'\"]+)['\"]\s*;/i", $bootstrap, $match)) {
        return application_update_normalize_version((string) $match[1]);
    }
    return null;
}

/**
 * Parse the newest release version from PATCH_NOTES.md headings.
 */
function application_update_version_from_patch_notes(string $markdown): ?string
{
    if (preg_match_all('/^##\s+Version\s+([^\r\n]+)/mi', $markdown, $matches) !== false && !empty($matches[1])) {
        foreach ($matches[1] as $candidate) {
            // $version stores the normalized heading version when it follows the project release format.
            $version = application_update_normalize_version((string) $candidate);
            if ($version !== null) {
                return $version;
            }
        }
    }
    return null;
}

/**
 * Normalize version strings used in notes, tags, and constants.
 */
function application_update_normalize_version(string $version): ?string
{
    // $version stores an intermediate value used by the surrounding gallery workflow.
    $version = trim($version);
    // $version stores an intermediate value used by the surrounding gallery workflow.
    $version = preg_replace('/^v[_-]?/i', '', $version) ?? $version;
    if (preg_match('/^[0-9]+(?:\.[0-9]+){1,2}$/', $version)) {
        return $version;
    }
    return null;
}

/**
 * Fetch a small trusted remote URL with a bounded timeout.
 */
function http_fetch(string $url, int $timeoutSeconds): string
{
    return http_fetch_with_headers($url, $timeoutSeconds, []);
}

/**
 * Fetch a trusted remote URL with optional request headers and a bounded timeout.
 */
function http_fetch_with_headers(string $url, int $timeoutSeconds, array $headers = []): string
{
    // $response stores the complete HTTP response while this legacy wrapper returns only the body.
    $response = http_fetch_response_with_headers($url, $timeoutSeconds, $headers);
    return (string) $response['body'];
}

/**
 * Fetch a trusted remote URL and return body, status, and response headers.
 */
function http_fetch_response_with_headers(string $url, int $timeoutSeconds, array $headers = []): array
{
    // $baseHeaders stores cache-control and identity headers shared by all update HTTP calls.
    $baseHeaders = [
        'Cache-Control: no-cache',
        'Pragma: no-cache',
    ];
    // $requestHeaders stores caller-provided headers after shared cache-control headers.
    $requestHeaders = array_values(array_filter(array_merge($baseHeaders, $headers), static fn ($header): bool => is_string($header) && trim($header) !== ''));

    if (function_exists('curl_init')) {
        // $handle stores the cURL handle used for a single bounded HTTP request.
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }
        // $responseHeaders stores normalized response headers captured by cURL.
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 15),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'PHP-Gallery-CMS/' . cms_current_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                // $length stores the raw header-line length cURL expects this callback to return.
                $length = strlen($headerLine);
                // $parts stores the parsed header name and value when the line is a normal response header.
                $parts = explode(':', trim($headerLine), 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ]);
        // $body stores the response body returned by cURL.
        $body = curl_exec($handle);
        // $status stores the final response code after redirects.
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // $error stores the cURL transport error when the request failed before a valid response body.
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status >= 400) {
            if (strpos($url, 'https://api.github.com/') === 0) {
                application_update_record_github_response($url, $status, $responseHeaders);
            }
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed with status ' . $status . '.');
        }
        return ['body' => (string) $body, 'status' => $status, 'headers' => $responseHeaders];
    }

    // $headerText stores HTTP headers formatted for the stream wrapper fallback.
    $headerText = "User-Agent: PHP-Gallery-CMS/" . cms_current_version() . "\r\n" . implode("\r\n", $requestHeaders) . "\r\n";
    // $context stores stream options for a single bounded GET request.
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => $headerText,
            'ignore_errors' => true,
        ],
    ]);
    // $body stores the response body returned by the stream wrapper.
    $body = @file_get_contents($url, false, $context);
    // $responseHeaders stores normalized headers from the stream wrapper metadata variable.
    $responseHeaders = [];
    // $status stores the parsed response code when stream metadata is available.
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $match)) {
            $status = (int) $match[1];
            continue;
        }
        $parts = explode(':', trim($line), 2);
        if (count($parts) === 2) {
            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    if ($body === false || $status >= 400) {
        if (strpos($url, 'https://api.github.com/') === 0) {
            application_update_record_github_response($url, $status, $responseHeaders);
        }
        throw new RuntimeException($status >= 400 ? 'HTTP request failed with status ' . $status . '.' : 'HTTP request failed. Enable curl or allow_url_fopen for update checks.');
    }
    return ['body' => (string) $body, 'status' => $status, 'headers' => $responseHeaders];
}

/**
 * Create an updater working directory when needed.
 */
function application_update_ensure_dir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true)) {
        throw new RuntimeException('Could not create update directory: ' . $path);
    }
    if (!is_writable($path)) {
        throw new RuntimeException('Update directory is not writable: ' . $path);
    }
}


/**
 * Return the application project root that contains index.php, app, public, and cache.
 */
function application_update_project_root(): string
{
    // $root stores an intermediate value used by the surrounding gallery workflow.
    $root = dirname(__DIR__, 2);
    application_update_assert_project_root($root);
    return $root;
}

/**
 * Reject dangerous updater destinations before any files are copied or removed.
 */
function application_update_assert_project_root(string $root): void
{
    // $normalizedRoot stores an intermediate value used by the surrounding gallery workflow.
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    if ($normalizedRoot === '' || basename($normalizedRoot) === 'app') {
        throw new RuntimeException('Updater refused to run because the destination root resolved to the app directory instead of the project root.');
    }

    // $requiredPaths stores an intermediate value used by the surrounding gallery workflow.
    $requiredPaths = [
        'index.php',
        'app/bootstrap.php',
        'app/services/updates.php',
        'public/assets/styles.css',
    ];
    foreach ($requiredPaths as $requiredPath) {
        // $absolutePath stores an intermediate value used by the surrounding gallery workflow.
        $absolutePath = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $requiredPath);
        if (!is_file($absolutePath)) {
            throw new RuntimeException('Updater refused to run because the project root is missing: ' . $requiredPath);
        }
    }

    foreach (['app', 'public', 'cache'] as $requiredDirectory) {
        // $absoluteDirectory stores an intermediate value used by the surrounding gallery workflow.
        $absoluteDirectory = $root . '/' . $requiredDirectory;
        if (!is_dir($absoluteDirectory)) {
            throw new RuntimeException('Updater refused to run because the project root is missing directory: ' . $requiredDirectory);
        }
    }
}

/**
 * Validate that the extracted archive looks like a PHP Gallery repository snapshot.
 */
function application_update_assert_source_root(string $sourceRoot): void
{
    // $requiredPaths stores an intermediate value used by the surrounding gallery workflow.
    $requiredPaths = [
        'index.php',
        'app/bootstrap.php',
        'app/services/updates.php',
        'public/assets/styles.css',
    ];
    foreach ($requiredPaths as $requiredPath) {
        // $absolutePath stores an intermediate value used by the surrounding gallery workflow.
        $absolutePath = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $requiredPath);
        if (!is_file($absolutePath)) {
            throw new RuntimeException('Downloaded update archive is not a valid PHP Gallery repository snapshot. Missing: ' . $requiredPath);
        }
    }
}

/**
 * Find the single root directory produced by GitHub zip extraction.
 */
function application_update_extracted_root(string $extractDir): string
{
    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = array_values(array_filter(scandir($extractDir) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
    foreach ($entries as $entry) {
        // $path stores an intermediate value used by the surrounding gallery workflow.
        $path = $extractDir . '/' . $entry;
        if (is_dir($path)) {
            return $path;
        }
    }
    throw new RuntimeException('Extracted update archive did not contain an application directory.');
}

/**
 * Copy update files, backing up overwritten files and preserving local data.
 */
function application_update_copy_files(string $sourceRoot, string $destinationRoot, string $backupPath, bool $cleanUnexpectedFiles = false): array
{
    // $backup stores an intermediate value used by the surrounding gallery workflow.
    $backup = new ZipArchive();
    if ($backup->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create update backup archive.');
    }

    application_update_assert_project_root($destinationRoot);
    application_update_backup_and_remove_misplaced_project_copy($destinationRoot, $backup);
    // $removed stores files and directories that were backed up and deleted because they are not present in the incoming release snapshot.
    $removed = application_update_remove_obsolete_managed_paths($sourceRoot, $destinationRoot, $backup, $cleanUnexpectedFiles);

    // $copied stores an intermediate value used by the surrounding gallery workflow.
    $copied = 0;
    // $iterator stores an intermediate value used by the surrounding gallery workflow.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            continue;
        }
        // $relativePath stores an intermediate value used by the surrounding gallery workflow.
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
        if (application_update_path_is_protected($relativePath)) {
            continue;
        }

        // $destination stores an intermediate value used by the surrounding gallery workflow.
        $destination = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if ($item->isDir()) {
            application_update_ensure_dir($destination);
            continue;
        }
        if (is_dir($destination)) {
            throw new RuntimeException('Cannot replace directory with file during update: ' . $relativePath);
        }
        // $parent stores an intermediate value used by the surrounding gallery workflow.
        $parent = dirname($destination);
        application_update_ensure_dir($parent);
        if (is_file($destination)) {
            $backup->addFile($destination, $relativePath);
        }
        if (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException('Could not copy update file: ' . $relativePath);
        }
        application_update_invalidate_opcache_for_path($destination);
        $copied++;
    }

    $backup->close();
    return [
        'files_copied' => $copied,
        'removed_paths' => $removed,
        'removed_count' => count($removed),
    ];
}


/**
 * Return true when a path is within a directory that the updater owns.
 */
function application_update_path_is_managed_by_updater(string $relativePath, bool $cleanUnexpectedFiles): bool
{
    // $relativePath stores the normalized project-relative path tested against managed areas.
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || application_update_path_is_protected($relativePath)) {
        return false;
    }
    if ($cleanUnexpectedFiles) {
        return true;
    }
    foreach (['app', 'public', 'database/migrations', 'scripts'] as $managedDirectory) {
        if ($relativePath === $managedDirectory || str_starts_with($relativePath, $managedDirectory . '/')) {
            return true;
        }
    }
    foreach (['index.php', 'install.php', 'reset.php', 'setup-gallery.php', 'deploy.bat', 'README.md', 'PATCH_NOTES.md', 'ARCHITECTURE.md', 'config.example.php'] as $managedFile) {
        if ($relativePath === $managedFile) {
            return true;
        }
    }
    return false;
}

/**
 * Remove local application files that are not present in the incoming release snapshot.
 */
function application_update_remove_obsolete_managed_paths(string $sourceRoot, string $destinationRoot, ZipArchive $backup, bool $cleanUnexpectedFiles): array
{
    // $removed stores normalized relative paths removed from the installation.
    $removed = [];
    // $iterator stores destination entries checked from deepest to shallowest so directories can be removed safely.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($destinationRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        // $relativePath stores the candidate path relative to the project root.
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($destinationRoot) + 1));
        if ($relativePath === '' || $item->isLink() || application_update_path_is_protected($relativePath)) {
            continue;
        }
        if (!application_update_path_is_managed_by_updater($relativePath, $cleanUnexpectedFiles)) {
            continue;
        }
        // $sourcePath stores the corresponding path in the downloaded release snapshot.
        $sourcePath = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (file_exists($sourcePath)) {
            continue;
        }
        application_update_add_path_to_backup($backup, $item->getPathname(), 'removed-before-update/' . $relativePath);
        application_update_remove_path($item->getPathname());
        $removed[] = $relativePath;
    }
    sort($removed);
    return $removed;
}

/**
 * Remove stale ZIP files and temporary update extraction folders from cache.
 */
function application_update_clean_cache_artifacts(string $root, string $activeBackupPath = ''): array
{
    // $cacheRoot stores the cache directory whose generated archives can be safely cleaned.
    $cacheRoot = $root . '/cache';
    if (!is_dir($cacheRoot)) {
        return ['zip_files_removed' => 0, 'temporary_paths_removed' => []];
    }
    // $activeBackupRealPath stores the current rollback backup, which must survive the reinstall.
    $activeBackupRealPath = $activeBackupPath !== '' ? realpath($activeBackupPath) : false;
    // $zipFilesRemoved stores how many generated ZIP files were deleted.
    $zipFilesRemoved = 0;
    // $temporaryPathsRemoved stores cache/update working directories removed after extraction.
    $temporaryPathsRemoved = [];
    // $iterator stores all cache entries, deepest first for safe directory cleanup.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        // $path stores the absolute cache item path.
        $path = $item->getPathname();
        // $pathRealPath stores a canonical path used to avoid deleting the active backup.
        $pathRealPath = realpath($path);
        if ($activeBackupRealPath !== false && $pathRealPath !== false && $activeBackupRealPath === $pathRealPath) {
            continue;
        }
        if ($item->isFile() && preg_match('/\.zip$/i', $item->getFilename())) {
            if (!unlink($path)) {
                throw new RuntimeException('Could not remove cached ZIP file: ' . $path);
            }
            $zipFilesRemoved++;
            continue;
        }
        if ($item->isDir() && preg_match('/^(extract|beta-extract|stable-restore)-[0-9]{8}-[0-9]{6}$/', $item->getFilename())) {
            application_update_remove_path($path);
            $temporaryPathsRemoved[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
        }
    }
    sort($temporaryPathsRemoved);
    return [
        'zip_files_removed' => $zipFilesRemoved,
        'temporary_paths_removed' => $temporaryPathsRemoved,
    ];
}


/**
 * Back up and remove a full project copy that was accidentally written inside app.
 */
function application_update_backup_and_remove_misplaced_project_copy(string $root, ZipArchive $backup): void
{
    // $appDirectory stores an intermediate value used by the surrounding gallery workflow.
    $appDirectory = $root . '/app';
    if (!is_dir($appDirectory)) {
        return;
    }

    // $misplacedPaths stores an intermediate value used by the surrounding gallery workflow.
    $misplacedPaths = application_update_misplaced_project_paths($root);
    foreach ($misplacedPaths as $relativePath) {
        // $absolutePath stores an intermediate value used by the surrounding gallery workflow.
        $absolutePath = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!file_exists($absolutePath)) {
            continue;
        }
        application_update_add_path_to_backup($backup, $absolutePath, 'misplaced-before-update/' . $relativePath);
        application_update_remove_path($absolutePath);
    }
}

/**
 * Return known wrong locations created when the updater used app as the project root.
 */
function application_update_misplaced_project_paths(string $root): array
{
    // $knownMisplacedPaths stores an intermediate value used by the surrounding gallery workflow.
    $knownMisplacedPaths = [
        'app/app',
        'app/public',
        'app/database',
        'app/galleries',
        'app/cache',
        'app/custom_css',
        'app/scripts',
        'app/_for_codex',
        'app/.git',
        'app/.github',
        'app/.htaccess',
        'app/index.php',
        'app/install.php',
        'app/reset.php',
        'app/setup-gallery.php',
        'app/deploy.bat',
        'app/config.php',
        'app/config.example.php',
        'app/README.md',
        'app/PATCH_NOTES.md',
        'app/ARCHITECTURE.md',
    ];

    // $appDirectory stores an intermediate value used by the surrounding gallery workflow.
    $appDirectory = $root . '/app';
    if (!is_dir($appDirectory)) {
        return $knownMisplacedPaths;
    }

    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = scandir($appDirectory) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        // $relativePath stores an intermediate value used by the surrounding gallery workflow.
        $relativePath = 'app/' . $entry;
        if (application_update_app_entry_is_expected($entry)) {
            continue;
        }
        if (!in_array($relativePath, $knownMisplacedPaths, true)) {
            $knownMisplacedPaths[] = $relativePath;
        }
    }

    return $knownMisplacedPaths;
}

/**
 * Return true for normal entries that belong directly inside the app directory.
 */
function application_update_app_entry_is_expected(string $entry): bool
{
    // $expectedEntries stores an intermediate value used by the surrounding gallery workflow.
    $expectedEntries = [
        'bootstrap.php',
        'controllers.php',
        'controllers',
        'core-manifest.json',
        'database.php',
        'helpers.php',
        'integrity.php',
        'migrations.php',
        'security.php',
        'services.php',
        'services',
    ];
    return in_array($entry, $expectedEntries, true);
}

/**
 * Add one file or directory tree to the updater backup archive.
 */
function application_update_add_path_to_backup(ZipArchive $backup, string $path, string $archivePath): void
{
    // $archivePath stores an intermediate value used by the surrounding gallery workflow.
    $archivePath = ltrim(str_replace('\\', '/', $archivePath), '/');
    if (is_file($path)) {
        $backup->addFile($path, $archivePath);
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    // $iterator stores an intermediate value used by the surrounding gallery workflow.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isDir()) {
            continue;
        }
        // $relativePath stores an intermediate value used by the surrounding gallery workflow.
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($path) + 1));
        $backup->addFile($item->getPathname(), rtrim($archivePath, '/') . '/' . $relativePath);
    }
}

/**
 * Remove a file or directory tree after it has been captured in the updater backup.
 */
function application_update_remove_path(string $path): void
{
    if (is_file($path) || is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Could not remove misplaced updater artifact: ' . $path);
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    // $iterator stores an intermediate value used by the surrounding gallery workflow.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            if (!rmdir($item->getPathname())) {
                throw new RuntimeException('Could not remove misplaced updater directory: ' . $item->getPathname());
            }
            continue;
        }
        if (!unlink($item->getPathname())) {
            throw new RuntimeException('Could not remove misplaced updater file: ' . $item->getPathname());
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Could not remove misplaced updater directory: ' . $path);
    }
}

/**
 * Remove stale temporary extraction directories from cache/updates.
 */
function application_update_cleanup_transient_extracts(string $updateDir, string $activeExtractDir = ''): void
{
    if (!is_dir($updateDir)) {
        return;
    }

    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = scandir($updateDir) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'backups') {
            continue;
        }
        if (!preg_match('/^(extract|beta-extract|stable-restore)-[0-9]{8}-[0-9]{6}$/', $entry)) {
            continue;
        }
        // $path stores an intermediate value used by the surrounding gallery workflow.
        $path = $updateDir . '/' . $entry;
        // $activeExtractRealPath stores an intermediate value used by the surrounding gallery workflow.
        $activeExtractRealPath = $activeExtractDir !== '' ? realpath($activeExtractDir) : false;
        // $pathRealPath stores an intermediate value used by the surrounding gallery workflow.
        $pathRealPath = realpath($path);
        if ($activeExtractRealPath !== false && $pathRealPath !== false && $activeExtractRealPath === $pathRealPath) {
            continue;
        }
        if (is_dir($path)) {
            application_update_remove_path($path);
        }
    }
}

/**
 * Invalidate cached PHP bytecode for a freshly copied file when opcache is enabled.
 */
function application_update_invalidate_opcache_for_path(string $path): void
{
    if (!function_exists('opcache_invalidate')) {
        return;
    }
    if (is_file($path) && preg_match('/\.php$/i', $path)) {
        @opcache_invalidate($path, true);
    }
}

/**
 * Invalidate cached PHP bytecode for restored application files under a source tree.
 */
function application_update_invalidate_opcache(string $destinationRoot, string $sourceRoot): void
{
    if (!function_exists('opcache_invalidate')) {
        return;
    }
    // $iterator stores an intermediate value used by the surrounding gallery workflow.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() || $item->isLink()) {
            continue;
        }
        // $relativePath stores an intermediate value used by the surrounding gallery workflow.
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
        // $destination stores an intermediate value used by the surrounding gallery workflow.
        $destination = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        application_update_invalidate_opcache_for_path($destination);
    }
}

/**
 * Read the CMS version from a local bootstrap file.
 */
function application_update_version_from_local_bootstrap(string $bootstrapPath): ?string
{
    if (!is_file($bootstrapPath)) {
        return null;
    }
    // $bootstrap stores an intermediate value used by the surrounding gallery workflow.
    $bootstrap = (string) file_get_contents($bootstrapPath);
    return application_update_version_from_bootstrap($bootstrap);
}

/**
 * Keep local-only files and directories out of automated updates.
 */
function application_update_path_is_protected(string $relativePath): bool
{
    // $relativePath stores an intermediate value used by the surrounding gallery workflow.
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    // $protectedFiles stores an intermediate value used by the surrounding gallery workflow.
    $protectedFiles = [
        'config.php',
        'public/assets/custom.css',
        '.user.ini',
        'php.ini',
        'robots.txt',
    ];
    if (in_array($relativePath, $protectedFiles, true)) {
        return true;
    }
    if ($relativePath === 'galleries/.htaccess') {
        return false;
    }
    foreach (['.git', '.well-known', 'cache', 'galleries', 'custom_css', '_for_codex'] as $directory) {
        if ($relativePath === $directory || str_starts_with($relativePath, $directory . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * Return whether the admin log table exists.
 */
/**
 * Ensure the admin log table has the workflow columns used by the log UI.
 */
/**
 * Store one admin-visible log entry for operational failures or notices.
 */
/**
 * Allowed workflow states for admin log entries.
 */
/**
 * Human label for one admin log status.
 */
/**
 * Return recent admin log entries for the dashboard.
 */
/**
 * Return admin log entries with optional status filtering.
 */
/**
 * Update the workflow status for one admin log entry.
 */
