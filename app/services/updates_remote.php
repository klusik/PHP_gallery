<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_remote.php
 * Module Type: Service
 *
 * Purpose:
 *   Handles remote version discovery, GitHub content URLs, version parsing, and HTTP transport.
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
 * Return the branch names the updater should try, newest preference first.
 *
 * @return array Structured result data for the caller.
 */
function application_update_branch_candidates(): array
{
    return CMS_UPDATE_BRANCHES;
}

/**
 * Build a GitHub archive URL for one code snapshot.
 *
 * @param string $commitId Commit id identifier.
 * @return string Text result for the caller.
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
 *
 * @param string $branch Branch value.
 * @return string Text result for the caller.
 */
function application_update_zip_url(string $branch): string
{
    application_update_assert_allowed_branch($branch);
    [$owner, $repo] = explode('/', CMS_GITHUB_REPOSITORY, 2);
    return 'https://codeload.github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zip/refs/heads/' . rawurlencode($branch) . '?nocache=' . rawurlencode((string) time());
}

/**
 * Reject update sources outside the stable GitHub branches.
 *
 * @param string $branch Branch value.
 */
function application_update_assert_allowed_branch(string $branch): void
{
    if (!in_array($branch, CMS_UPDATE_BRANCHES, true)) {
        throw new RuntimeException('Updates are allowed only from the main or master GitHub branch.');
    }
}

/**
 * Build a GitHub Contents API URL for one trusted branch file.
 *
 * @param string $branch Branch value.
 * @param string $path Filesystem path.
 * @return string Text result for the caller.
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
 *
 * @param string $branch Branch value.
 * @param string $path Filesystem path.
 * @param int $timeoutSeconds Timeout seconds value.
 * @return string Text result for the caller.
 */
function application_update_fetch_github_content(string $branch, string $path, int $timeoutSeconds): string
{
    // $headers stores the media type that asks GitHub to return raw file contents instead of JSON metadata.
    $headers = [
        'Accept: application/vnd.github.raw+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    // $url stores the exact GitHub API endpoint so diagnostics can show which API call was last made.
    $url = application_update_github_contents_api_url($branch, $path);
    // $response stores body, status, and headers from the central GitHub API gateway.
    $response = cms_github_api_get($url, $timeoutSeconds, $headers, true);
    return (string) $response['body'];
}

/**
 * Read the remote version marker that identifies the newest branch version.
 *
 * @param string $branch Branch value.
 * @return array Structured result data for the caller.
 */
function application_update_remote_version_candidates(string $branch): array
{
    // $result stores the richer branch probe while preserving the legacy return shape for callers.
    $result = application_update_remote_version_result($branch);
    return (array) ($result['candidates'] ?? []);
}

/**
 * Return a safe HTTP timeout that fits inside an optional wall-clock deadline.
 *
 * A zero result means the caller should checkpoint or stop instead of beginning
 * another external request. The deadline is deliberately independent from PHP's
 * execution-time setting because hosting proxies may impose a shorter limit.
 *
 * @param ?float $deadline Absolute microtime deadline, or null for the legacy timeout.
 * @param int $defaultSeconds Maximum timeout when no tighter deadline applies.
 * @return int Timeout seconds, or zero when the budget is exhausted.
 */
function application_update_remote_timeout_seconds(?float $deadline, int $defaultSeconds = 12): int
{
    $defaultSeconds = max(1, min(15, $defaultSeconds));
    if ($deadline === null) {
        return $defaultSeconds;
    }

    $remaining = $deadline - microtime(true) - 0.25;
    if ($remaining < 0.75) {
        return 0;
    }

    return max(1, min($defaultSeconds, (int) floor($remaining)));
}

/**
 * Read remote version markers and keep diagnostics for branches without a marker.
 *
 * @param string $branch Branch value.
 * @param ?float $deadline Absolute microtime deadline for external I/O.
 * @return array Structured result data for the caller.
 */
function application_update_remote_version_result(string $branch, ?float $deadline = null): array
{
    // $versionCandidates stores trusted version markers found in remote files.
    $versionCandidates = [];
    // $diagnostics stores non-fatal parsing details used by the update page and logs.
    $diagnostics = [];
    // $reachable stores whether at least one trusted GitHub file was fetched successfully.
    $reachable = false;

    $bootstrapTimeout = application_update_remote_timeout_seconds($deadline, 12);
    try {
        if ($bootstrapTimeout < 1) {
            throw new RuntimeException('Remote update metadata request budget exhausted before bootstrap fetch.');
        }
        // $bootstrap stores the remote bootstrap file fetched through GitHub Contents API.
        $bootstrap = application_update_fetch_github_content($branch, 'app/bootstrap.php', $bootstrapTimeout);
        $reachable = true;
        // $bootstrapVersion stores the version parsed from the bootstrap constant when present.
        $bootstrapVersion = application_update_version_from_bootstrap($bootstrap);
        if ($bootstrapVersion !== null) {
            $versionCandidates['app/bootstrap.php'] = $bootstrapVersion;
        } else {
            $diagnostics[] = 'No CMS_VERSION marker was found in app/bootstrap.php on branch ' . $branch . '.';
        }
    } catch (Throwable $exception) {
        $diagnostics[] = 'app/bootstrap.php fetch failed. Reference: ' . application_update_safe_error($exception)['reference'];
    }

    if ($versionCandidates === []) {
        $patchTimeout = application_update_remote_timeout_seconds($deadline, 12);
        try {
            if ($patchTimeout < 1) {
                throw new RuntimeException('Remote update metadata request budget exhausted before patch-notes fetch.');
            }
            // $patchNotes stores the remote release notes used as a secondary version signal.
            $patchNotes = application_update_fetch_github_content($branch, 'PATCH_NOTES.md', $patchTimeout);
            $reachable = true;
            // $patchNotesVersion stores the newest heading parsed from the release notes.
            $patchNotesVersion = application_update_version_from_patch_notes($patchNotes);
            if ($patchNotesVersion !== null) {
                $versionCandidates['PATCH_NOTES.md'] = $patchNotesVersion;
            } else {
                $diagnostics[] = 'No version heading was found in PATCH_NOTES.md on branch ' . $branch . '.';
            }
        } catch (Throwable $exception) {
            $diagnostics[] = 'PATCH_NOTES.md fetch failed. Reference: ' . application_update_safe_error($exception)['reference'];
        }
    }

    return [
        'candidates' => array_filter($versionCandidates, static fn ($value): bool => is_string($value) && application_update_normalize_version($value) !== null),
        'reachable' => $reachable,
        'diagnostic' => implode(' ', array_filter($diagnostics)),
        'budget_exhausted' => application_update_remote_timeout_seconds($deadline, 12) < 1,
    ];
}

/**
 * Return the highest semantic version from remote version candidates.
 *
 * @param array $versionCandidates Version candidates value.
 * @return string Text result for the caller.
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
 *
 * @param array $versionCandidates Version candidates value.
 * @param string $latestVersion Latest version value.
 * @return string Text result for the caller.
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
 *
 * @param string $bootstrap Bootstrap value.
 * @return ?string Text result for the caller.
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
 *
 * @param string $markdown Markdown value.
 * @return ?string Text result for the caller.
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
 *
 * @param string $version Version value.
 * @return ?string Text result for the caller.
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
 *
 * @param string $url URL used by this workflow.
 * @param int $timeoutSeconds Timeout seconds value.
 * @return string Text result for the caller.
 */
function http_fetch(string $url, int $timeoutSeconds): string
{
    return http_fetch_with_headers($url, $timeoutSeconds, []);
}

/**
 * Fetch a trusted remote URL with optional request headers and a bounded timeout.
 *
 * @param string $url URL used by this workflow.
 * @param int $timeoutSeconds Timeout seconds value.
 * @param array $headers Headers value.
 * @return string Text result for the caller.
 */
function http_fetch_with_headers(string $url, int $timeoutSeconds, array $headers = []): string
{
    // $response stores the complete HTTP response while this legacy wrapper returns only the body.
    $response = http_fetch_response_with_headers($url, $timeoutSeconds, $headers);
    return (string) $response['body'];
}

/**
 * Fetch a trusted remote URL and return body, status, and response headers.
 *
 * @param string $url URL used by this workflow.
 * @param int $timeoutSeconds Timeout seconds value.
 * @param array $headers Headers value.
 * @return array Structured result data for the caller.
 */
function http_fetch_response_with_headers(string $url, int $timeoutSeconds, array $headers = []): array
{
    if (strpos($url, 'https://api.github.com/') === 0) {
        return cms_github_api_get($url, $timeoutSeconds, $headers, true);
    }

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
