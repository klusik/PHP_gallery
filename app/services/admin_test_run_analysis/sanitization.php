<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_run_analysis/sanitization.php
 * Module Type: Service
 *
 * Purpose:
 *   Redacts secrets and volatile identifiers before a report is stored.
 *
 * Responsibilities:
 *   - Strip credentials, tokens, and query secrets from recorded URLs and text
 *   - Redact run identifiers so stored reports stay comparable
 *   - Filter provider headers down to a safe, bounded set
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
 *   - Loaded by app/services/admin_test_run_analysis.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_test_run_analysis.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;

/**
 * Return the allowlisted provider/CDN/proxy response headers from a normalized map.
 *
 * @param array<string,mixed> $headers Header-name/value map.
 * @return array<string,string>
 */
function admin_test_run_filter_provider_headers(array $headers): array
{
    $allowlist = [
        'x-location', 'x-cdn-cache-status', 'x-rate-limit', 'age', 'server', 'via', 'alt-svc',
        'x-cacheable', 'x-filter-info', 'cf-cache-status', 'x-cache', 'x-served-by', 'x-timer',
        'cache-control', 'server-timing', 'x-gallery-test-request-id',
    ];
    $allowed = array_fill_keys($allowlist, true);
    $result = [];
    foreach ($headers as $name => $value) {
        $normalized = strtolower(trim((string) $name));
        if (!isset($allowed[$normalized])) {
            continue;
        }
        if (in_array($normalized, ['set-cookie', 'authorization', 'proxy-authorization', 'cookie'], true)) {
            continue;
        }
        $result[$normalized] = substr(trim((string) $value), 0, 2000);
    }
    ksort($result);
    return $result;
}

/**
 * Sanitize a diagnostic URL so report artifacts do not preserve credentials or opaque run tokens.
 * @param string $url Recorded absolute or relative URL.
 * @return string Bounded URL with credential values redacted.
 */
function admin_test_run_sanitize_url(string $url): string
{
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if ($parts === false) {
        return substr($url, 0, 4000);
    }
    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
        $query = admin_test_run_sanitize_query($query);
    }
    $result = '';
    if (isset($parts['scheme'], $parts['host'])) {
        $result .= (string) $parts['scheme'] . '://' . (string) $parts['host'];
        if (isset($parts['port'])) {
            $result .= ':' . (int) $parts['port'];
        }
    }
    $result .= (string) ($parts['path'] ?? '');
    if ($query !== []) {
        $result .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    if (isset($parts['fragment'])) {
        $result .= '#' . substr((string) $parts['fragment'], 0, 200);
    }
    return substr($result, 0, 4000);
}

/**
 * Identify authority-bearing query keys, including nested and encoded spellings.
 *
 * @param string $key Query parameter name or bracketed nested name.
 * @return bool Whether the parameter conveys credential authority.
 */
function admin_test_run_sensitive_query_key(string $key): bool
{
    return preg_match('/(^|[_\-\[\]])(token|ticket|csrf|password|passwd|secret|api[_-]?key|authorization|session)($|[_\-\[\]])/i', urldecode($key)) === 1;
}

/**
 * Recursively redact query authority while preserving ordinary identifiers.
 *
 * @param array<string|int,mixed> $query Parsed diagnostic query parameters.
 * @return array<string|int,mixed> Parameters with credential values redacted.
 */
function admin_test_run_sanitize_query(array $query): array
{
    foreach ($query as $key => $value) {
        if (admin_test_run_sensitive_query_key((string) $key)) {
            $query[$key] = '[REDACTED]';
        } elseif (is_array($value)) {
            $query[$key] = admin_test_run_sanitize_query($value);
        }
    }
    return $query;
}

/**
 * Redact credential-like query parameters embedded in arbitrary diagnostic text.
 * @param string $value Recorded diagnostic text.
 * @return string Bounded text with credential query values redacted.
 */
function admin_test_run_sanitize_text(string $value): string
{
    $value = preg_replace_callback(
        '/([?&]([^=&#\s]+)=)([^&#\s]*)/i',
        /** Redact the credential value in one recorded query parameter.
         * @param array<int,string> $match Full regex match, parameter prefix, key and value.
         * @return string Original safe parameter or redacted credential parameter.
         */
        static fn (array $match): string => admin_test_run_sensitive_query_key((string) $match[2])
            ? (string) $match[1] . '[REDACTED]' : (string) $match[0],
        $value
    ) ?? $value;
    $value = preg_replace(
        '~((?:cache[\\\\/])?admin-test-runs[\\\\/])[a-f0-9]{32}(?=([\\\\/]|$))~i',
        '$1[REDACTED]',
        $value
    ) ?? $value;
    return substr($value, 0, 16000);
}

/**
 * Replace one exact opaque Test Run token anywhere in a report value.
 *
 * @param mixed $value Diagnostic value.
 * @return mixed
 */
function admin_test_run_redact_exact_token(mixed $value, string $token): mixed
{
    if ($token === '') {
        return $value;
    }
    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $child) {
            $result[$key] = admin_test_run_redact_exact_token($child, $token);
        }
        return $result;
    }
    if (is_string($value)) {
        return str_replace($token, '[REDACTED]', $value);
    }
    return $value;
}

/**
 * Redact opaque Test Run directory identifiers, including retained historical pre-v1.1.2 runs.
 *
 * @param mixed $value Diagnostic value.
 * @return mixed
 */
function admin_test_run_redact_storage_run_ids(mixed $value): mixed
{
    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $child) {
            $result[$key] = admin_test_run_redact_storage_run_ids($child);
        }
        return $result;
    }
    if (is_string($value)) {
        return preg_replace(
            '~((?:cache[\\\\/])?admin-test-runs[\\\\/])[a-f0-9]{32}(?=([\\\\/]|$))~i',
            '$1[REDACTED]',
            $value
        ) ?? $value;
    }
    return $value;
}

/**
 * Recursively sanitize the browser payload as a defense in depth layer.
 *
 * @param mixed $value Browser-supplied value.
 * @param string $key Current key.
 * @return mixed
 */
function admin_test_run_sanitize_browser_value(mixed $value, string $key = ''): mixed
{
    $lowerKey = strtolower($key);
    if ($key !== '' && preg_match('/(^|_)(cookie|authorization|password|passwd|secret|csrf|api[_-]?key|session|test[_-]?run[_-]?token|access[_-]?token|refresh[_-]?token)($|_)/', $lowerKey) === 1) {
        return '[REDACTED]';
    }
    if (is_array($value)) {
        $result = [];
        $count = 0;
        foreach ($value as $childKey => $childValue) {
            if (++$count > 5000) {
                $result['_truncated'] = true;
                break;
            }
            $result[$childKey] = admin_test_run_sanitize_browser_value($childValue, (string) $childKey);
        }
        return $result;
    }
    if (is_string($value)) {
        if ($lowerKey === 'url' || str_ends_with($lowerKey, '_url') || $lowerKey === 'uri' || str_ends_with($lowerKey, '_uri') || $lowerKey === 'name') {
            return admin_test_run_sanitize_url($value);
        }
        return admin_test_run_sanitize_text($value);
    }
    return $value;
}
