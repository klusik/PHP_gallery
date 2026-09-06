<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_run_analysis/browser.php
 * Module Type: Service
 *
 * Purpose:
 *   Analyses the browser-supplied payload and correlates it with PHP records.
 *
 * Responsibilities:
 *   - Summarize browser resource timings and cache outcomes
 *   - Correlate browser observations with server-side request records
 *   - Keep correlation tolerant of a missing or partial browser payload
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
 * Return the most complete Resource Timing list from the browser payload.
 *
 * @param array<string,mixed> $browser Browser payload.
 * @return array<int,array<string,mixed>>
 */
function admin_test_run_browser_resources(array $browser): array
{
    foreach (['after_probes', 'before_probes'] as $key) {
        $snapshot = is_array($browser[$key] ?? null) ? $browser[$key] : [];
        if (is_array($snapshot['resources'] ?? null)) {
            return array_values(array_filter($snapshot['resources'], 'is_array'));
        }
    }
    if (is_array($browser['resources'] ?? null)) {
        return array_values(array_filter($browser['resources'], 'is_array'));
    }
    return [];
}

/**
 * Build a browser cache summary that agrees with the raw Resource Timing rows.
 *
 * @param array<string,mixed> $browser Browser payload.
 * @return array<string,mixed>
 */
function admin_test_run_browser_cache_summary(array $browser): array
{
    $resources = admin_test_run_browser_resources($browser);
    $cache = 0;
    $network = 0;
    $unknown = 0;
    $initiators = [];
    $transfer = 0;
    $encoded = 0;
    $decoded = 0;
    foreach ($resources as $row) {
        $transferSize = max(0, (int) ($row['transfer_size'] ?? $row['transferSize'] ?? 0));
        $encodedSize = max(0, (int) ($row['encoded_body_size'] ?? $row['encodedBodySize'] ?? 0));
        $decodedSize = max(0, (int) ($row['decoded_body_size'] ?? $row['decodedBodySize'] ?? 0));
        $transfer += $transferSize;
        $encoded += $encodedSize;
        $decoded += $decodedSize;
        $probableHit = !empty($row['probable_cache_hit']) || ($transferSize === 0 && $decodedSize > 0);
        if ($probableHit) {
            $cache++;
        } elseif ($transferSize > 0) {
            $network++;
        } else {
            $unknown++;
        }
        $initiator = trim((string) ($row['initiator_type'] ?? $row['initiatorType'] ?? ''));
        if ($initiator === '') {
            $initiator = 'unknown';
        }
        $initiators[$initiator] = ($initiators[$initiator] ?? 0) + 1;
    }
    arsort($initiators);
    $total = count($resources);
    $classification = 'insufficient_data';
    $classificationNote = 'Resource Timing did not contain enough classified resources for a warm/cold inference.';
    if ($total >= 5) {
        $networkRatio = $network / $total;
        $cacheRatio = $cache / $total;
        if ($networkRatio >= 0.80) {
            $classification = 'mostly_network';
            $classificationNote = 'Most measured resources transferred bytes. This is compatible with a relatively cold browser cache but does not prove all cache layers were cold.';
        } elseif ($cacheRatio >= 0.50) {
            $classification = 'cache_heavy';
            $classificationNote = 'At least half of measured resources were probable browser-cache hits.';
        } else {
            $classification = 'mixed';
            $classificationNote = 'The page used a mix of network transfers, probable browser-cache hits, and/or unclassifiable entries.';
        }
    }
    return [
        'resource_count' => $total,
        'browser_cache_count' => $cache,
        'network_count' => $network,
        'unknown_count' => $unknown,
        'transfer_bytes' => $transfer,
        'encoded_bytes' => $encoded,
        'decoded_bytes' => $decoded,
        'initiator_distribution' => $initiators,
        'network_ratio' => $total > 0 ? $network / $total : null,
        'browser_cache_ratio' => $total > 0 ? $cache / $total : null,
        'warm_cold_classification' => $classification,
        'classification_note' => $classificationNote,
    ];
}

/**
 * Return browser-to-PHP correlation rows using explicit diagnostic request IDs.
 *
 * @param array<string,mixed> $browser Browser payload.
 * @param array<int,array<string,mixed>> $requests Request sidecars.
 * @return array<string,mixed>
 */
function admin_test_run_browser_php_correlation(array $browser, array $requests): array
{
    $byId = [];
    foreach ($requests as $request) {
        if (is_array($request) && (string) ($request['request_id'] ?? '') !== '') {
            $byId[(string) $request['request_id']] = $request;
        }
    }
    $rows = [];
    $probeRows = is_array($browser['probes'] ?? null) ? $browser['probes'] : [];
    foreach ($probeRows as $probe) {
        if (!is_array($probe)) {
            continue;
        }
        $requestId = trim((string) ($probe['diagnostic_request_id'] ?? ''));
        if ($requestId === '' || !isset($byId[$requestId])) {
            continue;
        }
        $request = $byId[$requestId];
        $browserTtfb = isset($probe['ttfb_like_ms']) ? max(0.0, (float) $probe['ttfb_like_ms']) : null;
        $requestTime = (float) ($request['request_time_unix'] ?? 0.0);
        $logical = (float) ($request['response_lifecycle']['logical_response_finished_at_unix'] ?? 0.0);
        if ($logical <= 0.0) {
            $logical = (float) ($request['finished_at_unix'] ?? 0.0);
        }
        $phpBeforeResponse = $requestTime > 0.0 && $logical >= $requestTime ? ($logical - $requestTime) * 1000 : null;
        $outsideEstimate = $browserTtfb !== null && $phpBeforeResponse !== null ? max(0.0, $browserTtfb - $phpBeforeResponse) : null;
        $rows[] = [
            'source' => 'verification_probe',
            'probe_name' => (string) ($probe['name'] ?? ''),
            'request_id' => $requestId,
            'browser_ttfb_ms' => $browserTtfb,
            'php_before_response_ms' => $phpBeforeResponse,
            'estimated_outside_php_wait_ms' => $outsideEstimate,
            'estimate_note' => 'Browser and server clocks are not assumed synchronized. The outside-PHP value subtracts server-observed request-to-response PHP time from browser-observed TTFB and therefore also includes network/proxy timing uncertainty.',
            'provider_headers' => is_array($probe['provider_headers'] ?? null) ? admin_test_run_filter_provider_headers($probe['provider_headers']) : [],
        ];
    }

    $page = is_array($browser['page'] ?? null) ? $browser['page'] : [];
    $mainRequestId = trim((string) ($page['diagnostic_request_id'] ?? ''));
    $navigationSnapshot = is_array($browser['before_probes'] ?? null) ? $browser['before_probes'] : [];
    $navigation = is_array($navigationSnapshot['navigation'][0] ?? null) ? $navigationSnapshot['navigation'][0] : null;
    if ($mainRequestId !== '' && isset($byId[$mainRequestId]) && is_array($navigation)) {
        $request = $byId[$mainRequestId];
        $browserTtfb = max(0.0, (float) (($navigation['responseStart'] ?? 0) - ($navigation['requestStart'] ?? $navigation['fetchStart'] ?? 0)));
        $requestTime = (float) ($request['request_time_unix'] ?? 0.0);
        $logical = (float) ($request['response_lifecycle']['logical_response_finished_at_unix'] ?? $request['finished_at_unix'] ?? 0.0);
        $phpBeforeResponse = $requestTime > 0.0 && $logical >= $requestTime ? ($logical - $requestTime) * 1000 : null;
        $rows[] = [
            'source' => 'primary_navigation',
            'request_id' => $mainRequestId,
            'browser_ttfb_ms' => $browserTtfb,
            'php_before_response_ms' => $phpBeforeResponse,
            'estimated_outside_php_wait_ms' => $phpBeforeResponse !== null ? max(0.0, $browserTtfb - $phpBeforeResponse) : null,
            'navigation_redirect_count' => (int) ($navigation['redirectCount'] ?? 0),
            'navigation_redirect_ms' => max(0.0, (float) (($navigation['redirectEnd'] ?? 0) - ($navigation['redirectStart'] ?? 0))),
            'estimate_note' => 'Navigation Timing and PHP request timestamps use different clock domains; only durations are compared. Network transit and proxy/CDN processing remain inside the outside-PHP estimate.',
        ];
    }

    $starterId = trim((string) ($page['starter_request_id'] ?? ''));
    if ($starterId !== '' && isset($byId[$starterId]) && is_array($navigation)) {
        $starter = $byId[$starterId];
        $starterPhp = (float) ($starter['duration_from_request_ms'] ?? 0.0);
        $redirectMs = max(0.0, (float) (($navigation['redirectEnd'] ?? 0) - ($navigation['redirectStart'] ?? 0)));
        $rows[] = [
            'source' => 'starter_redirect',
            'request_id' => $starterId,
            'browser_redirect_phase_ms' => $redirectMs,
            'starter_php_request_ms' => $starterPhp,
            'estimated_outside_php_starter_wait_ms' => $redirectMs > 0.0 ? max(0.0, $redirectMs - $starterPhp) : null,
            'estimate_note' => 'Same-origin Navigation Timing redirect duration can cover the starter request, but browser redirect timing may include connection/proxy/network work around PHP. The difference is an estimate, not synchronized-clock measurement.',
        ];
    }

    return [
        'correlation_method' => 'X-Gallery-Test-Request-ID plus browser Navigation/Resource/fetch timing',
        'clock_synchronization' => 'not_assumed',
        'rows' => $rows,
    ];
}
