<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/github.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides a single server-side access layer for GitHub REST API calls.
 *
 * Responsibilities:
 *   - Send every GitHub REST API request through one controlled gateway
 *   - Persist rate-limit headers for admin diagnostics
 *   - Respect Retry-After and primary x-ratelimit-reset wait windows
 *   - Store and reuse ETag and Last-Modified validators
 *   - Return cached response bodies when GitHub answers 304 Not Modified
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
 *   2026-05-14
 */

declare(strict_types=1);

/**
 * Return the root directory used for file-backed GitHub API cache metadata.
 *
 * @return string Text result for the caller.
 */
function cms_github_api_cache_dir(): string
{
    return dirname(__DIR__, 2) . '/cache/github-api';
}

/**
 * Return a deterministic file-safe cache key for one GitHub API URL.
 *
 * @param string $url URL used by this workflow.
 * @return string Text result for the caller.
 */
function cms_github_api_cache_key(string $url): string
{
    return sha1($url);
}

/**
 * Return the cache path for one GitHub API URL.
 *
 * @param string $url URL used by this workflow.
 * @return string Text result for the caller.
 */
function cms_github_api_cache_path(string $url): string
{
    return cms_github_api_cache_dir() . '/' . cms_github_api_cache_key($url) . '.json';
}

/**
 * Read cached GitHub API metadata and response body for one URL.
 *
 * @param string $url URL used by this workflow.
 * @return array Structured result data for the caller.
 */
function cms_github_api_read_cache(string $url): array
{
    // $path stores the JSON cache file for this URL.
    $path = cms_github_api_cache_path($url);
    if (!is_file($path)) {
        return [];
    }

    // $json stores the raw cache payload as written after a previous 200 response.
    $json = (string) file_get_contents($path);
    if ($json === '') {
        return [];
    }

    // $data stores the decoded cache payload after basic shape validation.
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Persist cached GitHub API metadata and response body for one URL.
 *
 * @param string $url URL used by this workflow.
 * @param int $status Status value.
 * @param array $headers Headers value.
 * @param string $body Body value.
 */
function cms_github_api_write_cache(string $url, int $status, array $headers, string $body): void
{
    // $dir stores the cache directory that is private behind cache/.htaccess.
    $dir = cms_github_api_cache_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return;
    }

    // $payload stores enough data to serve a future 304 response from local cache.
    $payload = [
        'url' => $url,
        'cached_at' => time(),
        'status' => $status,
        'etag' => (string) ($headers['etag'] ?? ''),
        'last_modified' => (string) ($headers['last-modified'] ?? ''),
        'headers' => $headers,
        'body' => $body,
    ];

    // $json stores a compact cache representation to avoid unnecessary disk churn.
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    @file_put_contents(cms_github_api_cache_path($url), $json, LOCK_EX);
}

/**
 * Return the next safe GitHub request time according to saved policy data.
 *
 * @return array Structured result data for the caller.
 */
function cms_github_api_wait_state(): array
{
    // $now stores one timestamp used for every comparison in this decision.
    $now = time();
    // $retryAfterUntil stores a Retry-After based pause after secondary limits or 429/403 responses.
    $retryAfterUntil = (int) app_setting('application_update_github_retry_after_until', '0');
    // $primaryResetAt stores the x-ratelimit-reset time when GitHub reported zero remaining core quota.
    $primaryResetAt = (int) app_setting('application_update_github_primary_reset_at', '0');
    // $nextAllowedAt stores the strictest known wait target.
    $nextAllowedAt = max($retryAfterUntil, $primaryResetAt);

    return [
        'active' => $nextAllowedAt > $now,
        'now' => $now,
        'next_allowed_at' => $nextAllowedAt,
        'retry_after_until' => $retryAfterUntil,
        'primary_reset_at' => $primaryResetAt,
        'next_allowed_label' => $nextAllowedAt > 0 ? date('Y-m-d H:i:s', $nextAllowedAt) : '',
    ];
}

/**
 * Persist GitHub response headers and calculate safe retry windows.
 *
 * @param string $url URL used by this workflow.
 * @param int $status Status value.
 * @param array $headers Headers value.
 * @param bool $fromCache From cache value.
 */
function cms_github_api_record_response(string $url, int $status, array $headers, bool $fromCache = false): void
{
    // $normalizedHeaders stores only headers relevant to GitHub API diagnostics and backoff.
    $normalizedHeaders = [];
    foreach ($headers as $name => $value) {
        // $lowerName stores the canonical lowercase header key used by GitHub documentation.
        $lowerName = strtolower((string) $name);
        if (in_array($lowerName, ['x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-used', 'x-ratelimit-reset', 'x-ratelimit-resource', 'retry-after', 'etag', 'last-modified'], true)) {
            $normalizedHeaders[$lowerName] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }
    }

    set_app_setting('application_update_github_last_checked_at', (string) time());
    set_app_setting('application_update_github_last_status', (string) $status);
    set_app_setting('application_update_github_last_url', $url);
    set_app_setting('application_update_github_last_from_cache', $fromCache ? '1' : '0');
    set_app_setting('application_update_github_headers_json', json_encode($normalizedHeaders, JSON_UNESCAPED_SLASHES) ?: '{}');

    // $retryAfter stores the server-requested wait in seconds when GitHub sends Retry-After.
    $retryAfter = isset($normalizedHeaders['retry-after']) ? max(0, (int) $normalizedHeaders['retry-after']) : 0;
    if ($retryAfter > 0) {
        set_app_setting('application_update_github_retry_after_until', (string) (time() + $retryAfter));
    }

    // $remaining stores GitHub primary remaining quota from x-ratelimit-remaining.
    $remaining = isset($normalizedHeaders['x-ratelimit-remaining']) ? (int) $normalizedHeaders['x-ratelimit-remaining'] : null;
    // $resetAt stores GitHub primary reset time from x-ratelimit-reset.
    $resetAt = isset($normalizedHeaders['x-ratelimit-reset']) ? (int) $normalizedHeaders['x-ratelimit-reset'] : 0;
    if ($remaining === 0 && $resetAt > time()) {
        set_app_setting('application_update_github_primary_reset_at', (string) $resetAt);
    } elseif ($remaining !== 0) {
        delete_app_settings(['application_update_github_primary_reset_at']);
    }

    if ($status < 400 && $retryAfter === 0) {
        delete_app_settings(['application_update_github_retry_after_until']);
    }
}

/**
 * Return persisted GitHub API diagnostics for the update page.
 *
 * @return array Structured result data for the caller.
 */
function cms_github_api_status(): array
{
    // $headersJson stores the latest parsed rate-limit and cache headers from a GitHub API response.
    $headersJson = (string) app_setting('application_update_github_headers_json', '');
    // $headers stores normalized GitHub headers when they were captured successfully.
    $headers = json_decode($headersJson, true);
    if (!is_array($headers)) {
        $headers = [];
    }

    // $lastCheckedAt stores the most recent attempted GitHub HTTP request timestamp.
    $lastCheckedAt = (int) app_setting('application_update_github_last_checked_at', '0');
    // $waitState stores active policy backoff information.
    $waitState = cms_github_api_wait_state();
    // $resetAt stores the GitHub primary rate-limit reset timestamp when available.
    $resetAt = (int) ($headers['x-ratelimit-reset'] ?? 0);

    return [
        'last_checked_at' => $lastCheckedAt,
        'last_checked_label' => $lastCheckedAt > 0 ? date('Y-m-d H:i:s', $lastCheckedAt) : t('admin.updates.autoupdate_last_check_never', 'never'),
        'last_status' => (string) app_setting('application_update_github_last_status', ''),
        'last_url' => (string) app_setting('application_update_github_last_url', ''),
        'last_from_cache' => app_setting('application_update_github_last_from_cache', '0') === '1',
        'limit' => (string) ($headers['x-ratelimit-limit'] ?? ''),
        'remaining' => (string) ($headers['x-ratelimit-remaining'] ?? ''),
        'used' => (string) ($headers['x-ratelimit-used'] ?? ''),
        'resource' => (string) ($headers['x-ratelimit-resource'] ?? ''),
        'reset_at' => $resetAt,
        'reset_label' => $resetAt > 0 ? date('Y-m-d H:i:s', $resetAt) : '',
        'retry_after' => (string) ($headers['retry-after'] ?? ''),
        'etag' => (string) ($headers['etag'] ?? ''),
        'last_modified' => (string) ($headers['last-modified'] ?? ''),
        'wait' => $waitState,
    ];
}

/**
 * Fetch one GitHub REST API URL through the controlled gateway.
 *
 * @param string $url URL used by this workflow.
 * @param int $timeoutSeconds Timeout seconds value.
 * @param array $headers Headers value.
 * @param bool $allowConditionalRequest Allow conditional request flag.
 * @return array Structured result data for the caller.
 */
function cms_github_api_get(string $url, int $timeoutSeconds, array $headers = [], bool $allowConditionalRequest = true): array
{
    if (strpos($url, 'https://api.github.com/') !== 0) {
        throw new RuntimeException('GitHub API gateway refused a non-GitHub API URL.');
    }

    // $waitState stores saved GitHub policy backoff information from previous responses.
    $waitState = cms_github_api_wait_state();
    if (!empty($waitState['active'])) {
        throw new RuntimeException('GitHub API calls are paused until ' . (string) ($waitState['next_allowed_label'] ?? '') . '.');
    }

    // $cache stores the previous successful response, including ETag and response body.
    $cache = cms_github_api_read_cache($url);
    // $requestHeaders stores caller-provided headers plus conditional validators when available.
    $requestHeaders = array_values(array_filter($headers, static fn ($header): bool => is_string($header) && trim($header) !== ''));
    if ($allowConditionalRequest && !empty($cache['etag'])) {
        $requestHeaders[] = 'If-None-Match: ' . (string) $cache['etag'];
    } elseif ($allowConditionalRequest && !empty($cache['last_modified'])) {
        $requestHeaders[] = 'If-Modified-Since: ' . (string) $cache['last_modified'];
    }

    // $response stores the raw HTTP result from GitHub.
    $response = cms_github_api_raw_get($url, $timeoutSeconds, $requestHeaders);
    // $status stores the final HTTP status code.
    $status = (int) ($response['status'] ?? 0);
    // $responseHeaders stores normalized response headers.
    $responseHeaders = (array) ($response['headers'] ?? []);
    cms_github_api_record_response($url, $status, $responseHeaders, false);

    if ($status === 304) {
        if (isset($cache['body']) && is_string($cache['body'])) {
            return [
                'body' => (string) $cache['body'],
                'status' => 304,
                'headers' => $responseHeaders,
                'from_cache' => true,
                'not_modified' => true,
            ];
        }

        // A 304 without a local body is not useful, so retry once without validators.
        $response = cms_github_api_raw_get($url, $timeoutSeconds, $headers);
        $status = (int) ($response['status'] ?? 0);
        $responseHeaders = (array) ($response['headers'] ?? []);
        cms_github_api_record_response($url, $status, $responseHeaders, false);
    }

    if ($status >= 400 || $status === 0) {
        throw new RuntimeException('GitHub API request failed with status ' . $status . '.');
    }

    // $body stores the response payload returned by GitHub.
    $body = (string) ($response['body'] ?? '');
    if ($status === 200) {
        cms_github_api_write_cache($url, $status, $responseHeaders, $body);
    }

    return [
        'body' => $body,
        'status' => $status,
        'headers' => $responseHeaders,
        'from_cache' => false,
        'not_modified' => false,
    ];
}

/**
 * Perform the low-level HTTP GET used only by the GitHub API gateway.
 *
 * @param string $url URL used by this workflow.
 * @param int $timeoutSeconds Timeout seconds value.
 * @param array $headers Headers value.
 * @return array Structured result data for the caller.
 */
function cms_github_api_raw_get(string $url, int $timeoutSeconds, array $headers): array
{
    // $baseHeaders stores standard headers shared by all GitHub REST API calls.
    $baseHeaders = [
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    // $config stores optional private server-side settings such as a GitHub token.
    $config = function_exists('cms_config') ? cms_config() : [];
    // $token stores an optional Personal Access Token or GitHub App token configured server-side only.
    $token = '';
    if (is_array($config)) {
        $token = trim((string) ($config['github_token'] ?? ($config['updates']['github_token'] ?? '')));
    }
    if ($token !== '') {
        $baseHeaders[] = 'Authorization: Bearer ' . $token;
    }

    // $requestHeaders stores all headers after removing empty values.
    $requestHeaders = array_values(array_filter(array_merge($baseHeaders, $headers), static fn ($header): bool => is_string($header) && trim($header) !== ''));

    if (function_exists('curl_init')) {
        // $handle stores the cURL handle used for a single bounded HTTP request.
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize GitHub API HTTP client.');
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
        if ($body === false) {
            throw new RuntimeException($error !== '' ? $error : 'GitHub API HTTP request failed.');
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
    if ($body === false) {
        throw new RuntimeException('GitHub API HTTP request failed. Enable curl or allow_url_fopen for update checks.');
    }
    return ['body' => (string) $body, 'status' => $status, 'headers' => $responseHeaders];
}
