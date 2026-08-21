<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_run_analysis.php
 * Module Type: Diagnostics Analysis Service
 *
 * Purpose:
 *   Provides bounded post-measurement analysis for Admin Test Run v1.1.3.
 *
 * Responsibilities:
 *   - Build a single-pass semantic cache inventory with explicit entry/time limits
 *   - Inventory cron, updater, maintenance, warmup, log archive, and database-maintenance mechanisms without running them
 *   - Aggregate PDO traces into per-request and whole-run SQL hotspots and conservative possible-N+1 candidates
 *   - Correlate browser timing with PHP lifecycle request IDs without claiming unavailable clock precision
 *   - Classify browser cache observations, infrastructure headers, DB write side effects, locks, and OPcache capabilities
 *   - Produce conservative info/warning/critical analysis flags with evidence and thresholds
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
 *   - All filesystem walks are bounded by both entry count and elapsed time.
 *   - Cron/maintenance diagnostics observe state only and never invoke maintenance/update work.
 *
 * Last Updated:
 *   2026-08-21
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;

/**
 * Return a bounded percentile from an already-collected numeric sample.
 *
 * @param array<int,float|int> $values Numeric values.
 */
function admin_test_run_percentile(array $values, float $percentile): ?float
{
    if ($values === []) {
        return null;
    }
    $values = array_values(array_map('floatval', $values));
    sort($values, SORT_NUMERIC);
    if (count($values) === 1) {
        return $values[0];
    }
    $position = max(0.0, min(1.0, $percentile)) * (count($values) - 1);
    $lower = (int) floor($position);
    $upper = (int) ceil($position);
    if ($lower === $upper) {
        return $values[$lower];
    }
    $weight = $position - $lower;
    return $values[$lower] + (($values[$upper] - $values[$lower]) * $weight);
}

/**
 * Return true when a privacy-safe SQL shape is a schema-inspection query.
 */
function admin_test_run_sql_is_schema_inspection(string $shape): bool
{
    $shape = strtolower(preg_replace('/\s+/', ' ', trim($shape)) ?: trim($shape));
    return str_contains($shape, 'information_schema.columns')
        || str_contains($shape, 'information_schema.tables')
        || str_contains($shape, 'information_schema.statistics')
        || str_starts_with($shape, 'show columns')
        || str_starts_with($shape, 'show full columns')
        || str_starts_with($shape, 'show tables')
        || str_starts_with($shape, 'describe ')
        || str_starts_with($shape, 'desc ')
        || str_contains($shape, 'pragma table_info');
}

/**
 * Return a stable privacy-safe key for one recorded callsite.
 *
 * @param array<string,mixed> $callsite Callsite fields.
 */
function admin_test_run_callsite_key(array $callsite): string
{
    $file = (string) ($callsite['file'] ?? '');
    $line = (int) ($callsite['line'] ?? 0);
    $function = (string) ($callsite['function'] ?? '');
    return ($file !== '' ? $file : 'unknown') . ':' . $line . ($function !== '' ? ':' . $function : '');
}

/**
 * Return rendered entity counts associated with one traced request.
 *
 * @param array<string,mixed> $request Request sidecar.
 * @return array{rendered_images:int,rendered_subgalleries:int,smart_gallery_results:int}
 */
function admin_test_run_render_counts(array $request): array
{
    $profile = is_array($request['components']['public_render_profile'] ?? null)
        ? $request['components']['public_render_profile']
        : [];
    $counters = is_array($profile['counters'] ?? null) ? $profile['counters'] : [];
    $smart = is_array($request['components']['smart_gallery'] ?? null)
        ? $request['components']['smart_gallery']
        : [];
    return [
        'rendered_images' => max(0, (int) ($counters['rendered_images'] ?? 0)),
        'rendered_subgalleries' => max(0, (int) ($counters['rendered_subgalleries'] ?? 0)),
        'smart_gallery_results' => max(0, (int) ($smart['page_images'] ?? 0)),
    ];
}

/**
 * Classify a recorded DB write without hiding it from the report.
 *
 * @param array<string,mixed> $query Recorded query.
 * @param array<string,mixed> $request Owning request.
 * @return array<string,mixed>
 */
function admin_test_run_classify_db_write(array $query, array $request): array
{
    $operation = strtolower((string) ($query['operation'] ?? ''));
    if (!in_array($operation, ['insert', 'update', 'delete', 'replace'], true)) {
        return ['classification' => 'not_a_write', 'reason' => 'read_or_transaction_operation'];
    }
    $shape = strtolower((string) ($query['shape'] ?? ''));
    $table = strtolower((string) ($query['table'] ?? ''));
    $uri = strtolower((string) ($request['request']['uri'] ?? ''));
    $isThumbnailRoute = str_contains($uri, 'page=thumb')
        || str_contains($uri, 'page=public_thumb')
        || str_contains($uri, 'page=thumbnail_warmup')
        || str_contains($uri, '/thumb-');

    if (($table === 'image_thumbnail_variants' || str_contains($shape, 'image_thumbnail_variants')) && $isThumbnailRoute) {
        return [
            'classification' => 'expected_diagnostic_or_application_side_effect',
            'reason' => 'idempotent_thumbnail_variant_metadata_write_during_thumbnail_request',
        ];
    }
    if (str_contains($table, 'telemetry') || str_contains($shape, ' telemetry_')) {
        return [
            'classification' => 'expected_diagnostic_or_application_side_effect',
            'reason' => 'telemetry_or_observability_write_from_normal_application_behavior',
        ];
    }
    if (in_array($table, ['galleries', 'images', 'users', 'viewer_users', 'image_votes', 'tags', 'gallery_tags', 'picture_game_votes'], true)) {
        return [
            'classification' => 'unexpected_write',
            'reason' => 'content_identity_permission_vote_or_tag_table_changed_during_test_run',
        ];
    }
    return [
        'classification' => 'unknown',
        'reason' => 'write_was_observed_but_no_safe_v1_1_2_allowlist_rule_applies',
    ];
}

/**
 * Aggregate SQL activity for one request or whole run.
 *
 * @param array<int,array<string,mixed>> $requests Request sidecars.
 * @return array<string,mixed>
 */
function admin_test_run_sql_hotspot_analysis(array $requests): array
{
    $durations = [];
    $fingerprints = [];
    $callsites = [];
    $operationDistribution = [
        'select' => 0,
        'insert' => 0,
        'update' => 0,
        'delete' => 0,
        'replace' => 0,
        'schema_inspection' => 0,
        'other' => 0,
        'transaction' => 0,
    ];
    $writeSideEffects = [];
    $perRequest = [];
    $totalRecorded = 0;
    $totalDeclared = 0;
    $totalMs = 0.0;
    $failedCount = 0;

    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        $requestId = (string) ($request['request_id'] ?? '');
        $db = is_array($request['db'] ?? null) ? $request['db'] : [];
        $requestDurations = [];
        $requestFingerprints = [];
        $requestSchemaCount = 0;
        $requestWrites = [];
        $requestDeclared = max(0, (int) ($db['query_count_total'] ?? 0));
        $totalDeclared += $requestDeclared;
        $failedCount += max(0, (int) ($db['failed_count'] ?? 0));

        foreach ((array) ($db['queries'] ?? []) as $query) {
            if (!is_array($query)) {
                continue;
            }
            $elapsed = max(0.0, (float) ($query['elapsed_ms'] ?? 0.0));
            $durations[] = $elapsed;
            $requestDurations[] = $elapsed;
            $totalMs += $elapsed;
            $totalRecorded++;
            $shape = (string) ($query['shape'] ?? '');
            $fingerprint = (string) ($query['fingerprint'] ?? '');
            if ($fingerprint === '') {
                $fingerprint = substr(hash('sha256', $shape), 0, 16);
            }
            $operation = strtolower((string) ($query['operation'] ?? 'other'));
            $schemaInspection = admin_test_run_sql_is_schema_inspection($shape);
            if ($schemaInspection) {
                $operationDistribution['schema_inspection']++;
                $requestSchemaCount++;
            } elseif (isset($operationDistribution[$operation])) {
                $operationDistribution[$operation]++;
            } else {
                $operationDistribution['other']++;
            }
            $callsite = is_array($query['callsite'] ?? null) ? $query['callsite'] : [];
            $callsiteKey = admin_test_run_callsite_key($callsite);
            if (!isset($callsites[$callsiteKey])) {
                $callsites[$callsiteKey] = ['callsite' => $callsite, 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0];
            }
            $callsites[$callsiteKey]['count']++;
            $callsites[$callsiteKey]['total_ms'] += $elapsed;
            $callsites[$callsiteKey]['max_ms'] = max((float) $callsites[$callsiteKey]['max_ms'], $elapsed);

            if (!isset($fingerprints[$fingerprint])) {
                $fingerprints[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'shape' => $shape,
                    'operation' => $operation,
                    'schema_inspection' => $schemaInspection,
                    'count' => 0,
                    'total_ms' => 0.0,
                    'max_ms' => 0.0,
                    'callsites' => [],
                    'request_ids' => [],
                ];
            }
            $fingerprints[$fingerprint]['count']++;
            $fingerprints[$fingerprint]['total_ms'] += $elapsed;
            $fingerprints[$fingerprint]['max_ms'] = max((float) $fingerprints[$fingerprint]['max_ms'], $elapsed);
            $fingerprints[$fingerprint]['callsites'][$callsiteKey] = ($fingerprints[$fingerprint]['callsites'][$callsiteKey] ?? 0) + 1;
            if ($requestId !== '') {
                $fingerprints[$fingerprint]['request_ids'][$requestId] = true;
            }
            if (!isset($requestFingerprints[$fingerprint])) {
                $requestFingerprints[$fingerprint] = [
                    'fingerprint' => $fingerprint,
                    'shape' => $shape,
                    'operation' => $operation,
                    'schema_inspection' => $schemaInspection,
                    'count' => 0,
                    'total_ms' => 0.0,
                    'max_ms' => 0.0,
                    'callsites' => [],
                ];
            }
            $requestFingerprints[$fingerprint]['count']++;
            $requestFingerprints[$fingerprint]['total_ms'] += $elapsed;
            $requestFingerprints[$fingerprint]['max_ms'] = max((float) $requestFingerprints[$fingerprint]['max_ms'], $elapsed);
            $requestFingerprints[$fingerprint]['callsites'][$callsiteKey] = ($requestFingerprints[$fingerprint]['callsites'][$callsiteKey] ?? 0) + 1;

            $writeClass = admin_test_run_classify_db_write($query, $request);
            if (($writeClass['classification'] ?? '') !== 'not_a_write') {
                $write = [
                    'request_id' => $requestId,
                    'sequence' => (int) ($query['sequence'] ?? 0),
                    'operation' => $operation,
                    'table' => (string) ($query['table'] ?? ''),
                    'fingerprint' => $fingerprint,
                    'shape' => $shape,
                    'elapsed_ms' => $elapsed,
                    'classification' => (string) ($writeClass['classification'] ?? 'unknown'),
                    'reason' => (string) ($writeClass['reason'] ?? ''),
                    'callsite' => $callsite,
                ];
                $writeSideEffects[] = $write;
                $requestWrites[] = $write;
            }
        }
        foreach ((array) ($db['transaction_events'] ?? []) as $transaction) {
            if (is_array($transaction)) {
                $operationDistribution['transaction']++;
            }
        }

        $requestFingerprintRows = array_values($requestFingerprints);
        foreach ($requestFingerprintRows as &$requestFingerprintRow) {
            $requestFingerprintRow['callsites'] = array_map(
                static fn (string $key, int $count): array => ['callsite' => $key, 'count' => $count],
                array_keys((array) ($requestFingerprintRow['callsites'] ?? [])),
                array_values((array) ($requestFingerprintRow['callsites'] ?? []))
            );
            usort($requestFingerprintRow['callsites'], static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        }
        unset($requestFingerprintRow);
        usort($requestFingerprintRows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $renderCounts = admin_test_run_render_counts($request);
        $requestNPlusOne = [];
        foreach ($requestFingerprintRows as $requestFingerprintRow) {
            if (!empty($requestFingerprintRow['schema_inspection']) || strtolower((string) ($requestFingerprintRow['operation'] ?? '')) !== 'select') {
                continue;
            }
            $repeatCount = (int) ($requestFingerprintRow['count'] ?? 0);
            $topCallsiteCount = isset($requestFingerprintRow['callsites'][0]['count']) ? (int) $requestFingerprintRow['callsites'][0]['count'] : 0;
            if ($repeatCount < 10 || $topCallsiteCount < 8) {
                continue;
            }
            $requestNPlusOne[] = [
                'classification' => 'repeated_fingerprint_possible_n_plus_one',
                'request_id' => $requestId,
                'fingerprint' => (string) ($requestFingerprintRow['fingerprint'] ?? ''),
                'shape' => (string) ($requestFingerprintRow['shape'] ?? ''),
                'count_in_request' => $repeatCount,
                'total_ms_in_request' => (float) ($requestFingerprintRow['total_ms'] ?? 0.0),
                'max_ms_in_request' => (float) ($requestFingerprintRow['max_ms'] ?? 0.0),
                'top_callsite_count_in_request' => $topCallsiteCount,
                'render_counts' => $renderCounts,
                'evidence_note' => 'Repeated SELECT fingerprint is concentrated inside one PHP request. Parameter values are intentionally not recorded, so this remains possible N+1 rather than a definitive finding.',
            ];
        }
        $perRequestKey = $requestId !== '' ? $requestId : 'unknown-' . count($perRequest);
        $perRequest[$perRequestKey] = [
            'query_count_declared' => $requestDeclared,
            'query_count_recorded' => count($requestDurations),
            'query_total_ms_recorded' => array_sum($requestDurations),
            'p50_ms' => admin_test_run_percentile($requestDurations, 0.50),
            'p95_ms' => count($requestDurations) >= 5 ? admin_test_run_percentile($requestDurations, 0.95) : null,
            'max_ms' => $requestDurations !== [] ? max($requestDurations) : 0.0,
            'schema_inspection_count' => $requestSchemaCount,
            'render_counts' => $renderCounts,
            'query_count_per_rendered_image' => $renderCounts['rendered_images'] > 0 ? $requestDeclared / $renderCounts['rendered_images'] : null,
            'query_count_per_rendered_subgallery' => $renderCounts['rendered_subgalleries'] > 0 ? $requestDeclared / $renderCounts['rendered_subgalleries'] : null,
            'query_count_per_smart_gallery_result' => $renderCounts['smart_gallery_results'] > 0 ? $requestDeclared / $renderCounts['smart_gallery_results'] : null,
            'top_fingerprints_by_count' => array_slice($requestFingerprintRows, 0, 20),
            'possible_n_plus_one_candidates' => array_slice($requestNPlusOne, 0, 20),
            'write_side_effects' => $requestWrites,
        ];
    }

    $fingerprintRows = [];
    foreach ($fingerprints as $row) {
        $row['callsites'] = array_map(
            static fn (string $key, int $count): array => ['callsite' => $key, 'count' => $count],
            array_keys($row['callsites']),
            array_values($row['callsites'])
        );
        usort($row['callsites'], static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $row['request_count'] = count($row['request_ids']);
        unset($row['request_ids']);
        $fingerprintRows[] = $row;
    }
    $byCount = $fingerprintRows;
    usort($byCount, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: ($b['total_ms'] <=> $a['total_ms']));
    $byTotal = $fingerprintRows;
    usort($byTotal, static fn (array $a, array $b): int => ($b['total_ms'] <=> $a['total_ms']) ?: ($b['count'] <=> $a['count']));
    $byMax = $fingerprintRows;
    usort($byMax, static fn (array $a, array $b): int => $b['max_ms'] <=> $a['max_ms']);

    $callsiteRows = array_values($callsites);
    usort($callsiteRows, static fn (array $a, array $b): int => ($b['total_ms'] <=> $a['total_ms']) ?: ($b['count'] <=> $a['count']));

    $possibleNPlusOne = [];
    foreach ($perRequest as $requestSummary) {
        foreach ((array) ($requestSummary['possible_n_plus_one_candidates'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                $possibleNPlusOne[] = $candidate;
            }
        }
    }
    usort($possibleNPlusOne, static fn (array $a, array $b): int => ((int) ($b['count_in_request'] ?? 0)) <=> ((int) ($a['count_in_request'] ?? 0)));

    $repeatedFingerprints = [];
    $repeatedSchema = [];
    foreach ($byCount as $row) {
        if ((int) ($row['count'] ?? 0) >= 5) {
            $repeatedFingerprints[] = $row;
        }
        if (!empty($row['schema_inspection']) && (int) ($row['count'] ?? 0) >= 5) {
            $repeatedSchema[] = $row;
        }
    }

    return [
        'query_count_declared' => $totalDeclared,
        'query_count_recorded' => $totalRecorded,
        'query_events_truncated_somewhere' => $totalRecorded < $totalDeclared,
        'failed_count' => $failedCount,
        'total_query_ms_recorded' => $totalMs,
        'p50_query_ms' => admin_test_run_percentile($durations, 0.50),
        'p95_query_ms' => count($durations) >= 5 ? admin_test_run_percentile($durations, 0.95) : null,
        'max_query_ms' => $durations !== [] ? max($durations) : 0.0,
        'operation_distribution' => $operationDistribution,
        'top_fingerprints_by_count' => array_slice($byCount, 0, 50),
        'top_fingerprints_by_total_time' => array_slice($byTotal, 0, 50),
        'top_fingerprints_by_max_time' => array_slice($byMax, 0, 50),
        'top_callsites' => array_slice($callsiteRows, 0, 50),
        'repeated_fingerprints' => array_slice($repeatedFingerprints, 0, 100),
        'repeated_schema_inspection' => array_slice($repeatedSchema, 0, 100),
        'possible_n_plus_one_candidates' => array_slice($possibleNPlusOne, 0, 100),
        'write_side_effects' => $writeSideEffects,
        'per_request' => $perRequest,
    ];
}

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
        foreach (array_keys($query) as $key) {
            $lower = strtolower((string) $key);
            if (preg_match('/(^|_)(token|csrf|password|passwd|secret|api[_-]?key|authorization|session)($|_)/', $lower) === 1) {
                $query[$key] = '[REDACTED]';
            }
        }
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
 * Redact credential-like query parameters embedded in arbitrary diagnostic text.
 */
function admin_test_run_sanitize_text(string $value): string
{
    $value = preg_replace_callback(
        '/([?&](?:[^=&#\s]*?(?:token|csrf|password|passwd|secret|api[_-]?key|authorization|session)[^=&#\s]*)=)([^&#\s]*)/i',
        static fn (array $match): string => (string) $match[1] . '[REDACTED]',
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

/**
 * Return OPcache diagnostic capability without causing a restrict_api warning when it is knowably forbidden.
 *
 * @return array<string,mixed>
 */
function admin_test_run_opcache_capability(): array
{
    $extensionLoaded = extension_loaded('Zend OPcache') || function_exists('opcache_get_status');
    $enabled = filter_var((string) ini_get('opcache.enable'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    $restrictApi = trim((string) ini_get('opcache.restrict_api'));
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
    $normalizedRestriction = str_replace('\\', '/', $restrictApi);
    $restricted = false;
    if ($restrictApi !== '') {
        $restricted = !str_starts_with($script, $normalizedRestriction);
    }
    $result = [
        'extension_loaded' => $extensionLoaded,
        'enabled' => $enabled,
        'status_access' => !$extensionLoaded ? 'unavailable' : ($restricted ? 'restricted' : 'unavailable'),
        'restrict_api_configured' => $restrictApi !== '',
        'status' => null,
    ];
    if (!$extensionLoaded || !function_exists('opcache_get_status')) {
        return $result;
    }
    if ($restricted) {
        $result['status_access'] = 'restricted';
        $result['note'] = 'opcache_get_status() was not called because opcache.restrict_api does not allow this script path.';
        return $result;
    }
    $status = @opcache_get_status(false);
    if (!is_array($status)) {
        $lastError = error_get_last();
        if (is_array($lastError) && str_contains(strtolower((string) ($lastError['message'] ?? '')), 'restrict_api')) {
            $result['status_access'] = 'restricted';
            $result['note'] = 'Host runtime rejected OPcache status access through restrict_api.';
        } else {
            $result['status_access'] = 'unavailable';
        }
        return $result;
    }
    $result['status_access'] = 'available';
    $result['status'] = [
        'opcache_enabled' => !empty($status['opcache_enabled']),
        'cache_full' => !empty($status['cache_full']),
        'restart_pending' => !empty($status['restart_pending']),
        'restart_in_progress' => !empty($status['restart_in_progress']),
        'memory_usage' => $status['memory_usage'] ?? null,
        'interned_strings_usage' => $status['interned_strings_usage'] ?? null,
        'opcache_statistics' => $status['opcache_statistics'] ?? null,
    ];
    return $result;
}

/**
 * Return a very light cache preflight with no recursive traversal.
 *
 * @return array<string,mixed>
 */
function admin_test_run_cache_preflight(?string $root = null): array
{
    $started = microtime(true);
    $root = $root ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache';
    $known = ['updates', 'admin-test-runs', 'github-api', 'thumbnail-warmup', 'site-maintenance', 'zips', 'gallery-migrations'];
    $families = [];
    foreach ($known as $name) {
        $path = $root . DIRECTORY_SEPARATOR . $name;
        $families[$name] = [
            'exists' => is_dir($path),
            'mtime' => is_dir($path) ? (@filemtime($path) ?: null) : null,
        ];
    }
    return [
        'mode' => 'non_recursive_preflight',
        'root_exists' => is_dir($root),
        'root_mtime' => is_dir($root) ? (@filemtime($root) ?: null) : null,
        'families' => $families,
        'elapsed_ms' => (microtime(true) - $started) * 1000,
        'recursive_entries_visited' => 0,
    ];
}

/**
 * Return semantic cache-family metadata for one first-level cache directory name.
 *
 * @return array<string,string>
 */
function admin_test_run_cache_family_policy(string $family): array
{
    $policies = [
        'updates' => [
            'semantic_name' => 'cache/updates',
            'retention_policy' => 'Updater-owned durable jobs/artifacts. Successful updates use the updater state machine for logical invalidation, generation advance, stale marking/moving, and bounded physical cleanup.',
            'reclaimability' => 'Do not infer deletability from age alone. An update that installed the cleanup feature may itself have run under the previous updater lifecycle.',
        ],
        'admin-test-runs' => [
            'semantic_name' => 'cache/admin-test-runs',
            'retention_policy' => 'Final reports are bounded by Admin Test Run count/size retention. Successful finalization removes intermediate sidecars with bounded cleanup.',
            'reclaimability' => 'Completed runs beyond retention are reclaimable by the Test Run retention policy; failed/incomplete runs keep forensic sidecars.',
        ],
        'github-api' => [
            'semantic_name' => 'cache/github-api',
            'retention_policy' => 'GitHub API response cache managed by the GitHub/update subsystem.',
            'reclaimability' => 'Unknown files are not assumed deletable by diagnostics.',
        ],
        'thumbnail-warmup' => [
            'semantic_name' => 'cache/thumbnail-warmup',
            'retention_policy' => 'Warmup lock/cooldown state is application-managed and intentionally small.',
            'reclaimability' => 'Diagnostics do not delete warmup state.',
        ],
        'site-maintenance' => [
            'semantic_name' => 'cache/site-maintenance',
            'retention_policy' => 'Site-maintenance lock/request-trigger state is application-managed.',
            'reclaimability' => 'Diagnostics do not delete maintenance state.',
        ],
        'zips' => [
            'semantic_name' => 'cache/zips',
            'retention_policy' => 'Generated gallery download archives use the existing ZIP-cache lifecycle.',
            'reclaimability' => 'Diagnostics do not infer arbitrary ZIP cache files are stale.',
        ],
        'gallery-migrations' => [
            'semantic_name' => 'cache/gallery-migrations',
            'retention_policy' => 'Migration state/artifacts are managed by the gallery migration subsystem.',
            'reclaimability' => 'Diagnostics do not delete migration state.',
        ],
        'other' => [
            'semantic_name' => 'cache/other',
            'retention_policy' => 'Unknown or uncategorized application cache data.',
            'reclaimability' => 'No automatic reclaimability assumption is made.',
        ],
    ];
    return $policies[$family] ?? $policies['other'];
}

/**
 * Initialize one cache-family accumulator.
 *
 * @return array<string,mixed>
 */
function admin_test_run_cache_family_accumulator(string $family): array
{
    $policy = admin_test_run_cache_family_policy($family);
    return [
        'semantic_name' => $policy['semantic_name'],
        'files' => 0,
        'directories' => 0,
        'bytes' => 0,
        'oldest_artifact' => null,
        'newest_artifact' => null,
        'retention_policy' => $policy['retention_policy'],
        'probable_reclaimable_or_stale' => $policy['reclaimability'],
    ];
}

/**
 * Add one file/directory observation to a cache-family accumulator.
 *
 * @param array<string,mixed> $family Family accumulator.
 */
function admin_test_run_cache_family_add(array &$family, bool $isDirectory, int $bytes, int $mtime, string $relativePath): void
{
    if ($isDirectory) {
        $family['directories']++;
        return;
    }
    $family['files']++;
    $family['bytes'] += max(0, $bytes);
    if ($mtime <= 0) {
        return;
    }
    $artifact = ['relative_path' => substr(str_replace('\\', '/', $relativePath), 0, 1000), 'mtime' => $mtime, 'at' => gmdate('c', $mtime)];
    if (!is_array($family['oldest_artifact']) || $mtime < (int) ($family['oldest_artifact']['mtime'] ?? PHP_INT_MAX)) {
        $family['oldest_artifact'] = $artifact;
    }
    if (!is_array($family['newest_artifact']) || $mtime > (int) ($family['newest_artifact']['mtime'] ?? 0)) {
        $family['newest_artifact'] = $artifact;
    }
}

/**
 * Traverse cache exactly once and derive root plus subtree totals from that one walk.
 *
 * @return array<string,mixed>
 */
function admin_test_run_cache_inventory_single_pass(?string $root = null, ?int $entryCap = null, ?float $timeBudgetMs = null): array
{
    $root = $root ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache';
    $entryCap = $entryCap ?? (defined(__NAMESPACE__ . '\\ADMIN_TEST_RUN_CACHE_SCAN_ENTRY_CAP') ? ADMIN_TEST_RUN_CACHE_SCAN_ENTRY_CAP : 25000);
    $timeBudgetMs = $timeBudgetMs ?? (defined(__NAMESPACE__ . '\\ADMIN_TEST_RUN_CACHE_SCAN_TIME_BUDGET_MS') ? ADMIN_TEST_RUN_CACHE_SCAN_TIME_BUDGET_MS : 250.0);
    $entryCap = max(100, $entryCap);
    $timeBudgetMs = max(10.0, $timeBudgetMs);
    $started = microtime(true);
    $deadline = $started + ($timeBudgetMs / 1000.0);
    $known = ['updates', 'admin-test-runs', 'github-api', 'thumbnail-warmup', 'site-maintenance', 'zips', 'gallery-migrations'];
    $families = ['application_cache' => admin_test_run_cache_family_accumulator('other')];
    $families['application_cache']['semantic_name'] = 'cache/';
    $families['application_cache']['retention_policy'] = 'Aggregate application cache total derived from this same single traversal; it is not scanned separately.';
    $families['application_cache']['probable_reclaimable_or_stale'] = 'Use per-family semantics; aggregate cache bytes are not assumed reclaimable.';
    foreach ($known as $name) {
        $families[$name] = admin_test_run_cache_family_accumulator($name);
    }
    $families['other'] = admin_test_run_cache_family_accumulator('other');

    $result = [
        'mode' => 'single_pass_bounded_recursive_inventory',
        'root' => str_replace('\\', '/', $root),
        'exists' => is_dir($root),
        'traversal_count' => is_dir($root) ? 1 : 0,
        'entry_cap' => $entryCap,
        'time_budget_ms' => $timeBudgetMs,
        'entries_visited' => 0,
        'truncated' => false,
        'truncation_reason' => '',
        'families' => $families,
        'scan_elapsed_ms' => 0.0,
    ];
    if (!is_dir($root)) {
        $result['scan_elapsed_ms'] = (microtime(true) - $started) * 1000;
        return $result;
    }

    $stack = [[$root, '']];
    while ($stack !== []) {
        if (microtime(true) >= $deadline) {
            $result['truncated'] = true;
            $result['truncation_reason'] = 'time_budget';
            break;
        }
        [$directory, $relativeDirectory] = array_pop($stack);
        $items = @scandir($directory);
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $result['entries_visited']++;
            if ((int) $result['entries_visited'] > $entryCap) {
                $result['truncated'] = true;
                $result['truncation_reason'] = 'entry_cap';
                break 2;
            }
            if (((int) $result['entries_visited'] & 63) === 0 && microtime(true) >= $deadline) {
                $result['truncated'] = true;
                $result['truncation_reason'] = 'time_budget';
                break 2;
            }
            $relative = $relativeDirectory === '' ? $name : $relativeDirectory . '/' . $name;
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $isDirectory = is_dir($path) && !is_link($path);
            $topLevel = explode('/', $relative, 2)[0];
            $familyKey = in_array($topLevel, $known, true) ? $topLevel : 'other';
            $bytes = $isDirectory ? 0 : max(0, (int) (@filesize($path) ?: 0));
            $mtime = max(0, (int) (@filemtime($path) ?: 0));
            admin_test_run_cache_family_add($result['families']['application_cache'], $isDirectory, $bytes, $mtime, $relative);
            admin_test_run_cache_family_add($result['families'][$familyKey], $isDirectory, $bytes, $mtime, $relative);
            if ($isDirectory) {
                $stack[] = [$path, $relative];
            }
        }
    }
    $result['scan_elapsed_ms'] = (microtime(true) - $started) * 1000;
    return $result;
}

/**
 * Return a stale-lock assessment from one non-blocking lock snapshot.
 *
 * @param array<string,mixed> $lock Lock snapshot.
 * @return array<string,mixed>
 */
function admin_test_run_lock_assessment(array $lock, int $staleAfterSeconds): array
{
    $mtime = max(0, (int) ($lock['mtime'] ?? 0));
    $age = $mtime > 0 ? max(0, time() - $mtime) : null;
    $busy = $lock['busy'] ?? null;
    $metadata = is_array($lock['metadata'] ?? null) ? $lock['metadata'] : [];
    $expiresAt = max(0, (int) ($metadata['expires_at'] ?? 0));
    $expiredMetadata = $expiresAt > 0 && $expiresAt < time();
    $possibleStale = $busy === true && (($age !== null && $age > max(30, $staleAfterSeconds)) || $expiredMetadata);
    return [
        'age_seconds' => $age,
        'stale_after_seconds' => max(30, $staleAfterSeconds),
        'metadata_expired' => $expiredMetadata,
        'possible_stale' => $possibleStale,
        'assessment' => $possibleStale
            ? 'Lock is still kernel-busy beyond the conservative expected budget/lease. Investigate a long-running or stuck worker.'
            : ($busy === true ? 'Lock is active and not old enough to classify as stale.' : 'No active kernel lock is observed.'),
    ];
}

/**
 * Parse a SQL/ISO timestamp into epoch seconds without throwing.
 */
function admin_test_run_timestamp(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $parsed = strtotime($value . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $value) ? '' : ' UTC'));
    return $parsed === false ? 0 : $parsed;
}

/**
 * Resolve site-maintenance due/active semantics against the completed cycle marker.
 *
 * @param array<string,mixed> $schedule Schedule state.
 * @param array<string,mixed> $state Persisted maintenance state.
 * @return array<string,mixed> Diagnostic schedule interpretation.
 */
function admin_test_run_site_maintenance_due_analysis(
    array $schedule,
    array $state,
    ?bool $enabled,
    string $lastCompletedDate,
    string $lastCompletedAt,
    int $now
): array {
    $lastCompletedDate = trim($lastCompletedDate);
    $lastCompletedEpoch = admin_test_run_timestamp($lastCompletedAt);
    $scheduleDate = trim((string) ($schedule['date'] ?? ''));
    $scheduleDueRaw = !empty($schedule['due']);
    $alreadyCompletedCurrentCycle = $scheduleDate !== '' && $lastCompletedDate !== '' && hash_equals($scheduleDate, $lastCompletedDate);
    $isRunning = (string) ($state['status'] ?? '') === 'running';
    $currentlyDue = $enabled === true && $scheduleDueRaw && !$alreadyCompletedCurrentCycle;
    $scheduledEpoch = admin_test_run_timestamp((string) ($schedule['scheduled_at'] ?? ''));
    $nextExpected = (string) ($schedule['scheduled_at'] ?? '');
    if ($alreadyCompletedCurrentCycle && $scheduledEpoch > 0) {
        $nextExpected = gmdate('c', $scheduledEpoch + 86400);
    }
    $overdue = $currentlyDue && empty($schedule['within_window'])
        && $lastCompletedEpoch > 0 && ($now - $lastCompletedEpoch) > 36 * 3600;

    return [
        'schedule_cycle_date' => $scheduleDate,
        'schedule_due_raw' => $scheduleDueRaw,
        'last_completed_cycle_date' => $lastCompletedDate,
        'already_completed_current_cycle' => $alreadyCompletedCurrentCycle,
        'is_running' => $isRunning,
        'currently_due' => $currentlyDue,
        'overdue' => $overdue,
        'next_expected_or_due_time' => $nextExpected,
        'last_completed_epoch' => $lastCompletedEpoch,
    ];
}

/**
 * Return true when one persisted job/state looks excessively old for its configured budget.
 */
function admin_test_run_state_looks_stuck(array $state, int $budgetSeconds, int $minimumSeconds = 300): bool
{
    $status = strtolower((string) ($state['status'] ?? ''));
    if (!in_array($status, ['running', 'active', 'processing'], true)) {
        return false;
    }
    $updated = max(
        (int) ($state['updated_at'] ?? 0),
        admin_test_run_timestamp((string) ($state['updated_at'] ?? '')),
        admin_test_run_timestamp((string) ($state['last_step_at'] ?? ''))
    );
    if ($updated <= 0) {
        return false;
    }
    return time() - $updated > max($minimumSeconds, max(1, $budgetSeconds) * 5);
}

/**
 * Read one updater job pointer and durable state without invoking updater pointer cleanup.
 *
 * @return array<string,mixed>|null
 */
function admin_test_run_update_job_pointer_read_only(string $pointerName): ?array
{
    if (!function_exists(__NAMESPACE__ . '\\application_update_jobs_root')) {
        return null;
    }
    $pointerName = $pointerName === 'last-job.json' ? 'last-job.json' : 'active-job.json';
    $root = application_update_jobs_root();
    $pointer = admin_test_run_read_json($root . DIRECTORY_SEPARATOR . $pointerName);
    $jobId = (string) ($pointer['job_id'] ?? '');
    if (preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $jobId) !== 1) {
        return null;
    }
    $job = admin_test_run_read_json($root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId . DIRECTORY_SEPARATOR . 'job.json');
    if (!$job || (string) ($job['id'] ?? '') !== $jobId) {
        return null;
    }
    return $job;
}

/**
 * Return whether a maintenance task represents work that is actually active now.
 *
 * @param array<string,mixed> $task Task snapshot.
 */
function admin_test_run_maintenance_task_active(array $task): bool
{
    if (($task['active_lock']['busy'] ?? false) === true) {
        return true;
    }
    foreach ((array) ($task['active_locks'] ?? []) as $lock) {
        if (is_array($lock) && ($lock['busy'] ?? false) === true) {
            return true;
        }
    }
    $job = $task['currently_active_job'] ?? null;
    if (is_bool($job)) {
        return $job;
    }
    if (!is_array($job) || $job === []) {
        return false;
    }
    $status = strtolower(trim((string) ($job['status'] ?? '')));
    if (in_array($status, ['complete', 'completed', 'done', 'failed', 'cancelled', 'canceled'], true)) {
        return false;
    }
    return true;
}

/**
 * Inventory every known scheduled/background/maintenance mechanism without executing it.
 *
 * @param array<int,array<string,mixed>> $requests Request records already observed in this run.
 * @return array<string,mixed>
 */
function admin_test_run_cron_and_maintenance_snapshot(array $requests = []): array
{
    $now = time();
    $tasks = [];
    $warnings = [];
    $eventsBySubsystem = [];
    foreach ($requests as $request) {
        foreach ((array) ($request['maintenance_events'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $subsystem = (string) ($event['subsystem'] ?? 'unknown');
            $eventsBySubsystem[$subsystem][] = $event;
        }
    }

    try {
        $enabled = function_exists(__NAMESPACE__ . '\\site_maintenance_enabled') ? site_maintenance_enabled() : null;
        $schedule = function_exists(__NAMESPACE__ . '\\site_maintenance_schedule_due_state') ? site_maintenance_schedule_due_state($now) : [];
        $state = function_exists(__NAMESPACE__ . '\\site_maintenance_state') ? site_maintenance_state() : [];
        $lastResult = function_exists(__NAMESPACE__ . '\\site_maintenance_last_result') ? site_maintenance_last_result() : [];
        $budget = function_exists(__NAMESPACE__ . '\\site_maintenance_time_budget_seconds') ? site_maintenance_time_budget_seconds() : null;
        $batchSize = function_exists(__NAMESPACE__ . '\\site_maintenance_batch_size') ? site_maintenance_batch_size() : null;
        $triggerEnabled = function_exists(__NAMESPACE__ . '\\site_maintenance_request_trigger_enabled') ? site_maintenance_request_trigger_enabled() : false;
        $detachSupported = function_exists(__NAMESPACE__ . '\\site_maintenance_request_trigger_can_detach_response') ? site_maintenance_request_trigger_can_detach_response() : false;
        $lastCompletedAt = function_exists(__NAMESPACE__ . '\\app_setting') ? (string) app_setting('site_maintenance_last_completed_at', '') : '';
        $lastCompletedDate = function_exists(__NAMESPACE__ . '\\app_setting') ? trim((string) app_setting('site_maintenance_last_completed_date', '')) : '';
        $maintenanceDue = admin_test_run_site_maintenance_due_analysis($schedule, $state, $enabled, $lastCompletedDate, $lastCompletedAt, $now);
        $lastCompletedEpoch = (int) ($maintenanceDue['last_completed_epoch'] ?? 0);
        $scheduleDate = (string) ($maintenanceDue['schedule_cycle_date'] ?? '');
        $scheduleDueRaw = !empty($maintenanceDue['schedule_due_raw']);
        $alreadyCompletedCurrentCycle = !empty($maintenanceDue['already_completed_current_cycle']);
        $isRunning = !empty($maintenanceDue['is_running']);
        $currentlyDue = !empty($maintenanceDue['currently_due']);
        $nextExpected = (string) ($maintenanceDue['next_expected_or_due_time'] ?? '');
        $lock = function_exists(__NAMESPACE__ . '\\site_maintenance_lock_path') ? admin_test_run_lock_snapshot(site_maintenance_lock_path()) : [];
        $lockAssessment = admin_test_run_lock_assessment($lock, is_int($budget) ? max(120, $budget * 5) : 600);
        $overdue = !empty($maintenanceDue['overdue']);
        $tasks['site_maintenance'] = [
            'subsystem' => 'site_maintenance',
            'enabled' => $enabled,
            'configured_schedule_or_cadence' => function_exists(__NAMESPACE__ . '\\site_maintenance_utc_time')
                ? 'daily at ' . site_maintenance_utc_time() . ' UTC within ' . (function_exists(__NAMESPACE__ . '\\site_maintenance_window_minutes') ? site_maintenance_window_minutes() : 0) . ' minute window'
                : 'unknown',
            'external_cron_or_cli_available' => is_file(dirname(__DIR__, 2) . '/scripts/site_maintenance.php'),
            'external_cron_configured' => 'not_observable_from_php',
            'next_expected_or_due_time' => $nextExpected,
            'schedule_cycle_date' => $scheduleDate,
            'schedule_due_raw' => $scheduleDueRaw,
            'last_completed_cycle_date' => $lastCompletedDate,
            'already_completed_current_cycle' => $alreadyCompletedCurrentCycle,
            'last_attempted_time' => (string) ($state['last_step_at'] ?? $state['updated_at'] ?? ''),
            'last_successful_time' => $lastCompletedAt,
            'last_failure' => !empty($lastResult['ok']) ? null : ($lastResult !== [] ? $lastResult : null),
            'time_since_last_run_seconds' => $lastCompletedEpoch > 0 ? max(0, $now - $lastCompletedEpoch) : null,
            'currently_due' => $currentlyDue,
            'overdue' => $overdue,
            'trigger_sources' => ['cron_web_endpoint', 'CLI', 'explicit_Admin_action', 'authenticated_Admin_request_trigger'],
            'active_lock' => $lock,
            'stale_lock_assessment' => $lockAssessment,
            'currently_active_job' => $isRunning ? $state : null,
            'resumable_job_state' => $isRunning,
            'previous_execution_duration_seconds' => isset($lastResult['elapsed_ms']) ? ((float) $lastResult['elapsed_ms'] / 1000.0) : null,
            'configured_execution_budget_seconds' => $budget,
            'configured_work_or_item_budget' => $batchSize,
            'can_execute_after_response' => $triggerEnabled && $detachSupported,
            'response_detach_primitive_available' => $detachSupported,
            'php_worker_remains_occupied_after_response' => $triggerEnabled && $detachSupported,
            'current_test_run_attempted_or_scheduled_it' => $eventsBySubsystem['site_maintenance'] ?? [],
            'public_anonymous_traffic_can_trigger' => false,
            'authenticated_admin_traffic_can_trigger' => $triggerEnabled,
            'self_loopback_chain_capable' => function_exists(__NAMESPACE__ . '\\site_maintenance_fire_web_cron_slice'),
            'warnings' => [],
        ];
        if ($lockAssessment['possible_stale']) {
            $tasks['site_maintenance']['warnings'][] = 'Possible stale/long-running site-maintenance lock.';
        }
        if ($overdue) {
            $tasks['site_maintenance']['warnings'][] = 'Site maintenance appears overdue relative to its daily cadence.';
        }
        if (admin_test_run_state_looks_stuck($state, is_int($budget) ? $budget : 20)) {
            $tasks['site_maintenance']['warnings'][] = 'Resumable maintenance state has not advanced within a conservative multiple of its execution budget.';
        }
    } catch (Throwable $exception) {
        $tasks['site_maintenance'] = ['subsystem' => 'site_maintenance', 'error' => $exception->getMessage(), 'observation_only' => true];
    }

    try {
        $status = function_exists(__NAMESPACE__ . '\\application_autoupdate_status') ? application_autoupdate_status() : [];
        $activeJob = admin_test_run_update_job_pointer_read_only('active-job.json');
        $lastJob = admin_test_run_update_job_pointer_read_only('last-job.json');
        $activePublic = is_array($activeJob) && function_exists(__NAMESPACE__ . '\\application_update_job_public_state') ? application_update_job_public_state($activeJob) : $activeJob;
        $lastPublic = is_array($lastJob) && function_exists(__NAMESPACE__ . '\\application_update_job_public_state') ? application_update_job_public_state($lastJob) : $lastJob;
        $lastChecked = max(0, (int) ($status['last_checked_at'] ?? 0));
        $due = !empty($status['effective']) && ($lastChecked <= 0 || $now - $lastChecked >= 3600);
        $jobsRoot = function_exists(__NAMESPACE__ . '\\application_update_jobs_root') ? application_update_jobs_root() : '';
        $startLock = $jobsRoot !== '' ? admin_test_run_lock_snapshot($jobsRoot . DIRECTORY_SEPARATOR . 'start.lock') : [];
        $workerLock = [];
        if (is_array($activeJob) && !empty($activeJob['id']) && function_exists(__NAMESPACE__ . '\\application_update_job_dir')) {
            $workerLock = admin_test_run_lock_snapshot(application_update_job_dir((string) $activeJob['id']) . DIRECTORY_SEPARATOR . 'worker.lock');
        }
        $workerAssessment = admin_test_run_lock_assessment($workerLock, 120);
        $tasks['automatic_updater'] = [
            'subsystem' => 'automatic_updater',
            'enabled' => (bool) ($status['enabled'] ?? false),
            'effective' => (bool) ($status['effective'] ?? false),
            'configured_schedule_or_cadence' => 'request-time stable update check, minimum 3600 seconds; durable jobs advance in bounded slices',
            'external_cron_or_cli_available' => is_file(dirname(__DIR__, 2) . '/scripts/application_update.php'),
            'external_cron_configured' => 'not_observable_from_php',
            'next_expected_or_due_time' => $lastChecked > 0 ? gmdate('c', $lastChecked + 3600) : null,
            'last_attempted_time' => $lastChecked > 0 ? gmdate('c', $lastChecked) : null,
            'last_successful_time' => is_array($lastPublic) && (int) ($lastPublic['finished_at'] ?? 0) > 0 ? gmdate('c', (int) $lastPublic['finished_at']) : null,
            'last_failure' => is_array($lastPublic) ? ($lastPublic['error'] ?? null) : null,
            'time_since_last_run_seconds' => $lastChecked > 0 ? max(0, $now - $lastChecked) : null,
            'currently_due' => $due,
            'overdue' => $due && $lastChecked > 0 && $now - $lastChecked > 6 * 3600,
            'trigger_sources' => ['normal_GET_or_HEAD_request', 'Admin_update_page', 'CLI_or_cron'],
            'active_locks' => ['start' => $startLock, 'worker' => $workerLock],
            'stale_lock_assessment' => $workerAssessment,
            'currently_active_job' => $activePublic,
            'resumable_job_state' => is_array($activePublic) ? !empty($activePublic['can_resume']) : false,
            'previous_execution_duration_seconds' => is_array($lastPublic) && (int) ($lastPublic['started_at'] ?? 0) > 0 && (int) ($lastPublic['finished_at'] ?? 0) >= (int) ($lastPublic['started_at'] ?? 0)
                ? (int) $lastPublic['finished_at'] - (int) $lastPublic['started_at'] : null,
            'configured_execution_budget_seconds' => ['normal_request_continue' => 3.0, 'admin_continue_or_retry' => 7.0],
            'configured_work_or_item_budget' => is_array($activePublic) ? ($activePublic['runtime_limits'] ?? []) : [],
            'can_execute_after_response' => false,
            'response_detach_primitive_used' => false,
            'php_worker_remains_occupied_after_response' => false,
            'php_worker_can_be_occupied_before_response' => true,
            'current_test_run_attempted_or_scheduled_it' => $eventsBySubsystem['automatic_updater'] ?? [],
            'public_anonymous_traffic_can_trigger' => true,
            'authenticated_admin_traffic_can_trigger' => true,
            'warnings' => [],
        ];
        if (is_array($activePublic) && $activePublic !== []) {
            $tasks['automatic_updater']['warnings'][] = 'An active updater job can consume a bounded PHP slice on an otherwise normal GET/HEAD request before its response.';
        }
        if ($workerAssessment['possible_stale']) {
            $tasks['automatic_updater']['warnings'][] = 'Active updater worker lock is older than the conservative diagnostic threshold.';
        }
    } catch (Throwable $exception) {
        $tasks['automatic_updater'] = ['subsystem' => 'automatic_updater', 'error' => $exception->getMessage(), 'observation_only' => true];
    }

    try {
        $status = function_exists(__NAMESPACE__ . '\\admin_log_archive_status') ? admin_log_archive_status() : [];
        $nextRun = max(0, (int) ($status['next_run_at'] ?? 0));
        $lastSuccessEpoch = admin_test_run_timestamp((string) ($status['last_success_at'] ?? ''));
        $lockPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin-log-archive-maintenance.lock';
        $lock = admin_test_run_lock_snapshot($lockPath);
        $lockAssessment = admin_test_run_lock_assessment($lock, 600);
        $tasks['admin_log_archive_maintenance'] = [
            'subsystem' => 'admin_log_archive_maintenance',
            'enabled' => !empty($status['enabled']),
            'configured_schedule_or_cadence' => '24-hour counter; short retry while backlog remains',
            'external_cron_or_cli_available' => is_file(dirname(__DIR__, 2) . '/scripts/telemetry_maintenance.php') || is_file(dirname(__DIR__, 2) . '/scripts/site_maintenance.php'),
            'external_cron_configured' => 'not_observable_from_php',
            'next_expected_or_due_time' => $nextRun > 0 ? gmdate('c', $nextRun) : null,
            'last_attempted_time' => (string) ($status['last_attempt_at'] ?? ''),
            'last_successful_time' => (string) ($status['last_success_at'] ?? ''),
            'last_failure' => is_array($status['last_result'] ?? null) && empty($status['last_result']['ok']) ? $status['last_result'] : null,
            'time_since_last_run_seconds' => $lastSuccessEpoch > 0 ? max(0, $now - $lastSuccessEpoch) : null,
            'currently_due' => !empty($status['enabled']) && ($nextRun <= 0 || $nextRun <= $now),
            'overdue' => !empty($status['enabled']) && $nextRun > 0 && $now - $nextRun > 6 * 3600,
            'trigger_sources' => ['authenticated_Admin_request', 'explicit_Admin_action', 'maintenance_or_CLI'],
            'active_lock' => $lock,
            'stale_lock_assessment' => $lockAssessment,
            'currently_active_job' => is_array($status['last_result'] ?? null) && !empty($status['last_result']['has_more']) ? $status['last_result'] : null,
            'resumable_job_state' => is_array($status['last_result'] ?? null) && !empty($status['last_result']['has_more']),
            'previous_execution_duration_seconds' => isset($status['last_result']['elapsed_ms']) ? ((float) $status['last_result']['elapsed_ms'] / 1000.0) : null,
            'configured_execution_budget_seconds' => 'one historical day per invocation; optional caller deadline',
            'configured_work_or_item_budget' => ['row_batch' => 200, 'delete_batch' => 2000],
            'can_execute_after_response' => function_exists(__NAMESPACE__ . '\\admin_log_archive_request_trigger_can_detach_response') ? admin_log_archive_request_trigger_can_detach_response() : false,
            'php_worker_remains_occupied_after_response' => function_exists(__NAMESPACE__ . '\\admin_log_archive_request_trigger_can_detach_response') ? admin_log_archive_request_trigger_can_detach_response() : false,
            'current_test_run_attempted_or_scheduled_it' => $eventsBySubsystem['admin_log_archive'] ?? [],
            'public_anonymous_traffic_can_trigger' => false,
            'authenticated_admin_traffic_can_trigger' => true,
            'warnings' => $lockAssessment['possible_stale'] ? ['Possible stale/long-running Admin log archive lock.'] : [],
        ];
    } catch (Throwable $exception) {
        $tasks['admin_log_archive_maintenance'] = ['subsystem' => 'admin_log_archive_maintenance', 'error' => $exception->getMessage(), 'observation_only' => true];
    }

    try {
        $enabled = function_exists(__NAMESPACE__ . '\\thumbnail_warmup_enabled') ? thumbnail_warmup_enabled() : null;
        $lock = function_exists(__NAMESPACE__ . '\\thumbnail_warmup_lock_path') ? admin_test_run_lock_snapshot(thumbnail_warmup_lock_path()) : [];
        $lockAssessment = admin_test_run_lock_assessment($lock, 300);
        $tasks['thumbnail_warmup'] = [
            'subsystem' => 'thumbnail_warmup',
            'enabled' => $enabled,
            'configured_schedule_or_cadence' => 'on-demand browser repair only; no periodic cron schedule',
            'external_cron_or_cli_available' => false,
            'external_cron_configured' => false,
            'next_expected_or_due_time' => null,
            'last_attempted_time' => null,
            'last_successful_time' => null,
            'last_failure' => null,
            'time_since_last_run_seconds' => null,
            'currently_due' => false,
            'overdue' => false,
            'trigger_sources' => ['signed_browser_warmup_request'],
            'active_lock' => $lock,
            'stale_lock_assessment' => $lockAssessment,
            'currently_active_job' => $lock['busy'] ?? false,
            'resumable_job_state' => false,
            'previous_execution_duration_seconds' => null,
            'configured_execution_budget_seconds' => null,
            'configured_work_or_item_budget' => ['max_items_per_request' => 24],
            'can_execute_after_response' => false,
            'php_worker_remains_occupied_after_response' => false,
            'current_test_run_attempted_or_scheduled_it' => $eventsBySubsystem['thumbnail_warmup'] ?? [],
            'public_anonymous_traffic_can_trigger' => true,
            'public_trigger_guard' => 'requires server-rendered HMAC token and visitor authorization; bounded to 24 unique images',
            'authenticated_admin_traffic_can_trigger' => true,
            'warnings' => $lockAssessment['possible_stale'] ? ['Thumbnail warmup lock appears unexpectedly long-running.'] : [],
        ];
    } catch (Throwable $exception) {
        $tasks['thumbnail_warmup'] = ['subsystem' => 'thumbnail_warmup', 'error' => $exception->getMessage(), 'observation_only' => true];
    }

    $tasks['database_maintenance'] = [
        'subsystem' => 'database_maintenance',
        'enabled' => true,
        'configured_schedule_or_cadence' => 'manual Admin operation only',
        'external_cron_or_cli_available' => false,
        'external_cron_configured' => false,
        'next_expected_or_due_time' => null,
        'last_attempted_time' => null,
        'last_successful_time' => null,
        'last_failure' => null,
        'time_since_last_run_seconds' => null,
        'currently_due' => false,
        'overdue' => false,
        'trigger_sources' => ['explicit_authenticated_Admin_action'],
        'active_lock' => null,
        'stale_lock_assessment' => null,
        'currently_active_job' => null,
        'resumable_job_state' => function_exists(__NAMESPACE__ . '\\database_maintenance_cleanup_state') ? database_maintenance_cleanup_state() : [],
        'previous_execution_duration_seconds' => null,
        'configured_execution_budget_seconds' => null,
        'configured_work_or_item_budget' => 'explicit bounded cleanup step selected by Admin',
        'can_execute_after_response' => false,
        'php_worker_remains_occupied_after_response' => false,
        'current_test_run_attempted_or_scheduled_it' => $eventsBySubsystem['database_maintenance'] ?? [],
        'public_anonymous_traffic_can_trigger' => false,
        'authenticated_admin_traffic_can_trigger' => false,
        'warnings' => [],
    ];

    $activeBackground = 0;
    foreach ($tasks as $task) {
        if (!is_array($task)) {
            continue;
        }
        if (admin_test_run_maintenance_task_active($task)) {
            $activeBackground++;
        }
        foreach ((array) ($task['warnings'] ?? []) as $warning) {
            $warnings[] = (string) ($task['subsystem'] ?? 'unknown') . ': ' . (string) $warning;
        }
    }
    if ($activeBackground > 1) {
        $warnings[] = 'Multiple background/maintenance subsystems appear active at the same time.';
    }

    return [
        'captured_at' => gmdate('c'),
        'observation_only' => true,
        'destructive_or_heavy_tasks_executed_by_diagnostics' => false,
        'task_count' => count($tasks),
        'tasks' => $tasks,
        'active_background_subsystem_count' => $activeBackground,
        'warnings' => array_values(array_unique($warnings)),
    ];
}

/**
 * Return request lifecycle response-tail metrics.
 *
 * @param array<int,array<string,mixed>> $requests Request sidecars.
 * @return array<string,mixed>
 */
function admin_test_run_post_response_summary(array $requests): array
{
    $rows = [];
    $max = 0.0;
    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        $logical = (float) ($request['response_lifecycle']['logical_response_finished_at_unix'] ?? 0.0);
        $detach = (float) ($request['response_lifecycle']['detach_called_at_unix'] ?? 0.0);
        $basis = $logical > 0.0 ? $logical : $detach;
        $shutdown = (float) ($request['finished_at_unix'] ?? $request['response_lifecycle']['shutdown_at_unix'] ?? 0.0);
        $recordedTail = $request['response_lifecycle']['response_to_shutdown_ms'] ?? null;
        $tail = is_numeric($recordedTail)
            ? max(0.0, (float) $recordedTail)
            : ($basis > 0.0 && $shutdown >= $basis ? ($shutdown - $basis) * 1000 : null);
        if ($tail !== null) {
            $max = max($max, $tail);
        }
        $rows[] = [
            'request_id' => (string) ($request['request_id'] ?? ''),
            'uri' => (string) ($request['request']['uri'] ?? ''),
            'logical_response_finished_at_unix' => $logical > 0.0 ? $logical : null,
            'fastcgi_or_litespeed_detach_called_at_unix' => $detach > 0.0 ? $detach : null,
            'shutdown_at_unix' => $shutdown > 0.0 ? $shutdown : null,
            'response_to_shutdown_ms' => $tail,
            'post_response_work_observed' => $tail !== null && $tail >= 25.0,
            'detach_mechanism' => (string) ($request['response_lifecycle']['detach_mechanism'] ?? ''),
        ];
    }
    usort($rows, static fn (array $a, array $b): int => (float) ($b['response_to_shutdown_ms'] ?? -1) <=> (float) ($a['response_to_shutdown_ms'] ?? -1));
    return [
        'request_count' => count($rows),
        'max_response_to_shutdown_ms' => $max,
        'requests' => $rows,
    ];
}

/**
 * Summarize PHP session-start latency and early release coverage for media fanout.
 *
 * session_start duration includes lock acquisition, session-file I/O, and decode
 * work, so the report deliberately calls this probable contention rather than
 * claiming exact lock-wait time. With the files save handler, many overlapping
 * thumbnail requests showing large session_start durations are nevertheless a
 * strong shared-host contention signal.
 *
 * @param array<int,array<string,mixed>> $requests Request sidecars.
 * @return array<string,mixed>
 */
function admin_test_run_session_lock_contention_summary(array $requests): array
{
    $allDurations = [];
    $mediaDurations = [];
    $mediaHoldDurations = [];
    $mediaCount = 0;
    $thumbnailCount = 0;
    $releaseEligible = 0;
    $releaseObserved = 0;
    $over50 = 0;
    $over200 = 0;
    $saveHandlers = [];
    $slowest = [];
    $mediaPages = ['thumb', 'public_thumb', 'media', 'public_media', 'gallery_cover_asset', 'gallery_branding_asset'];

    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        $sessionBegin = null;
        $sessionEnd = null;
        $releaseEnd = null;
        $page = '';
        $saveHandler = '';
        foreach ((array) ($request['marks'] ?? []) as $mark) {
            if (!is_array($mark)) {
                continue;
            }
            $name = (string) ($mark['name'] ?? '');
            $at = isset($mark['at_unix']) && is_numeric($mark['at_unix']) ? (float) $mark['at_unix'] : null;
            $context = is_array($mark['context'] ?? null) ? $mark['context'] : [];
            if ($name === 'session_start_begin') {
                $sessionBegin = $at;
                $saveHandler = (string) ($context['save_handler'] ?? '');
            } elseif ($name === 'session_start_end') {
                $sessionEnd = $at;
            } elseif ($name === 'request_initialize_end') {
                $page = (string) ($context['page'] ?? $page);
            } elseif ($name === 'read_only_media_session_release_end') {
                $releaseEnd = $at;
                if ($page === '') {
                    $page = (string) ($context['page'] ?? '');
                }
            }
        }
        if ($page === '') {
            $uri = strtolower((string) ($request['request']['uri'] ?? ''));
            if (str_contains($uri, 'page=public_thumb') || str_contains($uri, '/thumb-')) {
                $page = 'public_thumb';
            } elseif (str_contains($uri, 'page=thumb')) {
                $page = 'thumb';
            } elseif (str_contains($uri, 'page=public_media') || str_ends_with(parse_url($uri, PHP_URL_PATH) ?: '', '/media')) {
                $page = 'public_media';
            } elseif (str_contains($uri, 'page=media')) {
                $page = 'media';
            }
        }
        if ($sessionBegin === null || $sessionEnd === null || $sessionEnd < $sessionBegin) {
            continue;
        }

        $durationMs = max(0.0, ($sessionEnd - $sessionBegin) * 1000.0);
        $allDurations[] = $durationMs;
        if ($saveHandler !== '') {
            $saveHandlers[$saveHandler] = ($saveHandlers[$saveHandler] ?? 0) + 1;
        }
        if (!in_array($page, $mediaPages, true)) {
            continue;
        }

        $mediaCount++;
        if (in_array($page, ['thumb', 'public_thumb'], true)) {
            $thumbnailCount++;
        }
        $mediaDurations[] = $durationMs;
        $releaseEligible++;
        if ($durationMs >= 50.0) {
            $over50++;
        }
        if ($durationMs >= 200.0) {
            $over200++;
        }
        if ($releaseEnd !== null && $releaseEnd >= $sessionEnd) {
            $releaseObserved++;
            $mediaHoldDurations[] = max(0.0, ($releaseEnd - $sessionEnd) * 1000.0);
        }
        $slowest[] = [
            'request_id' => (string) ($request['request_id'] ?? ''),
            'page' => $page,
            'session_start_ms' => $durationMs,
            'session_held_after_start_ms' => $releaseEnd !== null && $releaseEnd >= $sessionEnd
                ? max(0.0, ($releaseEnd - $sessionEnd) * 1000.0)
                : null,
        ];
    }

    usort($slowest, static fn (array $a, array $b): int => (float) $b['session_start_ms'] <=> (float) $a['session_start_ms']);
    $probableContentionThreshold = max(4, (int) ceil($mediaCount * 0.10));
    $filesHandlerObserved = isset($saveHandlers['files']);
    $probableContention = $mediaCount >= 8 && $filesHandlerObserved && $over50 >= $probableContentionThreshold;

    return [
        'measurement_note' => 'session_start_ms includes session-lock acquisition, session storage I/O, and decode time; exact lock-wait time is not exposed by PHP.',
        'request_count_with_session_timing' => count($allDurations),
        'high_fanout_media_request_count' => $mediaCount,
        'thumbnail_request_count' => $thumbnailCount,
        'save_handler_distribution' => $saveHandlers,
        'session_start_ms' => [
            'p50' => admin_test_run_percentile($mediaDurations, 0.50),
            'p95' => admin_test_run_percentile($mediaDurations, 0.95),
            'max' => $mediaDurations !== [] ? max($mediaDurations) : null,
            'aggregate' => array_sum($mediaDurations),
            'requests_at_or_above_50_ms' => $over50,
            'requests_at_or_above_200_ms' => $over200,
        ],
        'early_session_release' => [
            'eligible_media_requests' => $releaseEligible,
            'observed_media_requests' => $releaseObserved,
            'coverage_ratio' => $releaseEligible > 0 ? $releaseObserved / $releaseEligible : null,
            'session_held_after_start_ms_p50' => admin_test_run_percentile($mediaHoldDurations, 0.50),
            'session_held_after_start_ms_p95' => admin_test_run_percentile($mediaHoldDurations, 0.95),
            'session_held_after_start_ms_max' => $mediaHoldDurations !== [] ? max($mediaHoldDurations) : null,
        ],
        'probable_contention' => $probableContention,
        'probable_contention_threshold_requests' => $probableContentionThreshold,
        'slowest_media_requests' => array_slice($slowest, 0, 12),
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

/**
 * Analyze Cache-Control values from PHP header lines or browser provider-header maps.
 *
 * @param array<mixed> $headers Header lines or name/value map.
 * @return array{conflict:bool,values:array<int,string>,directives:array<string,array<int,string>>,reasons:array<int,string>}
 */
function admin_test_run_cache_control_analysis(array $headers): array
{
    $values = [];
    foreach ($headers as $name => $header) {
        if (is_string($name) && strtolower(trim($name)) === 'cache-control' && is_scalar($header)) {
            $values[] = trim((string) $header);
            continue;
        }
        if (!is_string($header) || stripos(ltrim($header), 'cache-control:') !== 0) {
            continue;
        }
        $values[] = trim(substr(ltrim($header), strlen('cache-control:')));
    }

    $directives = [];
    foreach ($values as $value) {
        foreach (explode(',', strtolower($value)) as $directive) {
            $directive = trim($directive);
            if ($directive === '') {
                continue;
            }
            [$directiveName, $directiveValue] = array_pad(explode('=', $directive, 2), 2, '');
            $directiveName = trim($directiveName);
            $directiveValue = trim($directiveValue, " \t\n\r\0\x0B\"");
            if ($directiveName === '') {
                continue;
            }
            $directives[$directiveName][] = $directiveValue;
        }
    }

    $reasons = [];
    if (isset($directives['public'], $directives['private'])) {
        $reasons[] = 'public_and_private';
    }
    if (isset($directives['no-store']) && (isset($directives['public']) || isset($directives['immutable']))) {
        $reasons[] = 'no_store_with_cacheable_directive';
    }
    foreach ($directives as $directiveName => $directiveValues) {
        $distinct = array_values(array_unique($directiveValues));
        if (count($distinct) > 1) {
            $reasons[] = 'conflicting_duplicate_' . $directiveName;
        }
    }

    return [
        'conflict' => $reasons !== [],
        'values' => $values,
        'directives' => $directives,
        'reasons' => array_values(array_unique($reasons)),
    ];
}

/**
 * Return true when response headers contain contradictory cache directives.
 *
 * @param array<mixed> $headers Header lines or name/value map.
 */
function admin_test_run_cache_control_conflict(array $headers): bool
{
    return !empty(admin_test_run_cache_control_analysis($headers)['conflict']);
}

/**
 * Add one structured analysis flag.
 *
 * @param array<int,array<string,mixed>> $flags Flag accumulator.
 * @param array<string,mixed> $evidence Evidence payload.
 */
function admin_test_run_add_analysis_flag(array &$flags, string $severity, string $code, string $message, array $evidence = [], string $rationale = ''): void
{
    $flags[] = [
        'severity' => in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info',
        'code' => $code,
        'message' => $message,
        'evidence' => $evidence,
        'rationale' => $rationale,
    ];
}

/**
 * Build conservative automatic analysis flags from a nearly-final report.
 *
 * @param array<string,mixed> $report Report payload.
 * @return array<int,array<string,mixed>>
 */
function admin_test_run_analysis_flags(array $report): array
{
    $flags = [];
    $correlation = is_array($report['browser_php_correlation']['rows'] ?? null) ? $report['browser_php_correlation']['rows'] : [];
    foreach ($correlation as $row) {
        if (!is_array($row)) continue;
        $outside = $row['estimated_outside_php_wait_ms'] ?? $row['estimated_outside_php_starter_wait_ms'] ?? null;
        $php = $row['php_before_response_ms'] ?? $row['starter_php_request_ms'] ?? null;
        if (is_numeric($outside) && (float) $outside >= 5000.0) {
            admin_test_run_add_analysis_flag($flags, 'critical', 'outside_php_wait_very_large', 'Browser-observed latency contains a very large interval not explained by measured PHP execution.', [
                'source' => $row['source'] ?? '', 'estimated_outside_php_wait_ms' => (float) $outside, 'php_ms' => is_numeric($php) ? (float) $php : null,
            ], '5 seconds outside measured PHP is far beyond normal application execution jitter and is consistent with origin/proxy/CDN/network queueing or connection delay.');
        } elseif (is_numeric($outside) && (float) $outside >= 1000.0 && (!is_numeric($php) || (float) $outside > (float) $php)) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'outside_php_wait_large', 'Browser TTFB contains at least about one second not explained by measured PHP execution.', [
                'source' => $row['source'] ?? '', 'estimated_outside_php_wait_ms' => (float) $outside, 'php_ms' => is_numeric($php) ? (float) $php : null,
            ], 'The estimate is intentionally conservative and includes network/proxy clock-domain uncertainty.');
        }
    }

    $probes = is_array($report['browser']['probes'] ?? null) ? $report['browser']['probes'] : [];
    $staticTtfb = null;
    $phpTtfb = null;
    foreach ($probes as $probe) {
        if (!is_array($probe)) continue;
        if (($probe['name'] ?? '') === 'static_asset_probe') $staticTtfb = (float) ($probe['ttfb_like_ms'] ?? 0.0);
        if (($probe['name'] ?? '') === 'php_probe') $phpTtfb = (float) ($probe['ttfb_like_ms'] ?? 0.0);
    }
    if ($staticTtfb !== null && $phpTtfb !== null && $staticTtfb >= 1000.0 && $staticTtfb > max($phpTtfb * 2.0, $phpTtfb + 750.0)) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'static_resource_slower_than_php', 'A cache-busted static resource was substantially slower than the minimal PHP probe.', ['static_ttfb_ms' => $staticTtfb, 'php_ttfb_ms' => $phpTtfb], 'Static-file latency that materially exceeds minimal PHP latency points away from Gallery controller/database execution as the sole cause.');
    }

    $starter = null;
    foreach ($correlation as $row) {
        if (is_array($row) && ($row['source'] ?? '') === 'starter_redirect') {
            $starter = $row;
            break;
        }
    }
    if (is_array($starter) && (float) ($starter['browser_redirect_phase_ms'] ?? 0.0) >= 2000.0) {
        admin_test_run_add_analysis_flag($flags, (float) ($starter['browser_redirect_phase_ms'] ?? 0.0) >= 5000.0 ? 'critical' : 'warning', 'starter_redirect_slow', 'The Test Run starter/redirect phase was slow.', $starter, 'The starter is now traced separately so cache preparation, PHP execution, and outside-PHP delay can be distinguished.');
    }
    $starterPhpMs = is_array($starter) ? (float) ($starter['starter_php_request_ms'] ?? 0.0) : 0.0;
    if ($starterPhpMs >= 750.0) {
        $clearResult = is_array($report['cache']['clear_result'] ?? null) ? $report['cache']['clear_result'] : [];
        admin_test_run_add_analysis_flag(
            $flags,
            $starterPhpMs >= 2000.0 ? 'critical' : 'warning',
            'starter_php_slow',
            'The Test Run starter itself spent substantial time inside measured PHP execution.',
            [
                'starter_php_request_ms' => $starterPhpMs,
                'starter_preparation_ms' => (float) ($report['starter']['preparation']['duration_ms'] ?? 0.0),
                'safe_cache_invalidation_ms' => (float) ($clearResult['total_ms'] ?? 0.0),
                'safe_cache_invalidation_actions' => $clearResult['actions'] ?? [],
            ],
            '750 ms is a conservative warning threshold because the starter intentionally performs only bounded context creation, light cache preflight, and safe metadata-cache invalidation before redirect. Nested invalidation timings identify which existing cache-clear operation consumed the time.'
        );
    }

    $lifecycle = is_array($report['request_lifecycle'] ?? null) ? $report['request_lifecycle'] : [];
    if ((int) ($lifecycle['active_unfinished_count'] ?? 0) > 0) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'unfinished_php_request', 'One or more traced PHP requests had not closed when the report was finalized.', ['count' => (int) $lifecycle['active_unfinished_count']], 'A finalized diagnostic run should normally have no active sidecars.');
    }

    $tail = is_array($report['post_response_worker_tail'] ?? null) ? $report['post_response_worker_tail'] : [];
    $maxTail = (float) ($tail['max_response_to_shutdown_ms'] ?? 0.0);
    if ($maxTail >= 3000.0) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'post_response_worker_tail_large', 'A PHP worker remained occupied for multiple seconds after the response was logically finished/detached.', ['max_response_to_shutdown_ms' => $maxTail], 'fastcgi_finish_request()/LiteSpeed detachment can release the browser while the PHP/FPM worker remains busy until shutdown work ends.');
    } elseif ($maxTail >= 1000.0) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'post_response_worker_tail', 'A PHP worker remained occupied for at least one second after the response was logically finished/detached.', ['max_response_to_shutdown_ms' => $maxTail], 'Long post-response tails can reduce shared-host worker availability even when browser TTFB appears good.');
    } elseif ($maxTail >= 250.0) {
        admin_test_run_add_analysis_flag($flags, 'info', 'post_response_worker_tail_noticeable', 'Noticeable post-response PHP work was observed.', ['max_response_to_shutdown_ms' => $maxTail], 'This is not necessarily harmful, but it is useful when diagnosing shared-host worker contention.');
    }

    $concurrency = (int) ($report['request_concurrency']['peak_concurrent_php_requests'] ?? 0);
    if ($concurrency >= 16) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'php_concurrency_very_high', 'Very high overlapping PHP request concurrency was observed.', ['peak' => $concurrency], 'The Test Run probe runner itself remains sequential, so high PHP overlap comes from real page/resource behavior rather than the verification probe loop.');
    } elseif ($concurrency >= 8) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'php_concurrency_high', 'High overlapping PHP request concurrency was observed.', ['peak' => $concurrency], 'A peak of 8+ PHP requests is conservative for shared hosting and materially above previously observed normal Test Run behavior.');
    }

    $sessionContention = is_array($report['session_lock_contention'] ?? null) ? $report['session_lock_contention'] : [];
    if (!empty($sessionContention['probable_contention'])) {
        $sessionTiming = is_array($sessionContention['session_start_ms'] ?? null) ? $sessionContention['session_start_ms'] : [];
        $severity = (int) ($sessionTiming['requests_at_or_above_200_ms'] ?? 0) >= 10 && $concurrency >= 16 ? 'critical' : 'warning';
        admin_test_run_add_analysis_flag(
            $flags,
            $severity,
            'php_session_lock_contention',
            'High-fanout media requests show probable PHP session-lock or session-storage contention.',
            [
                'media_request_count' => (int) ($sessionContention['high_fanout_media_request_count'] ?? 0),
                'thumbnail_request_count' => (int) ($sessionContention['thumbnail_request_count'] ?? 0),
                'session_start_ms' => $sessionTiming,
                'early_session_release' => $sessionContention['early_session_release'] ?? [],
                'peak_php_concurrency' => $concurrency,
                'save_handler_distribution' => $sessionContention['save_handler_distribution'] ?? [],
            ],
            'PHP does not expose pure session-lock wait time, but many overlapping media requests using the files handler with clustered slow session_start durations are a strong contention signal. Early session-release coverage shows whether read-only media requests stop holding the lock before authorization/path/derivative work.'
        );
    }
    $thumbnailFanout = (int) ($sessionContention['thumbnail_request_count'] ?? 0);
    if ($thumbnailFanout >= 32 && $concurrency >= 8) {
        admin_test_run_add_analysis_flag(
            $flags,
            $concurrency >= 16 ? 'warning' : 'info',
            'thumbnail_php_fanout',
            'One gallery load generated a large fanout of PHP-routed thumbnail requests.',
            [
                'thumbnail_request_count' => $thumbnailFanout,
                'peak_php_concurrency' => $concurrency,
                'session_release_coverage' => $sessionContention['early_session_release']['coverage_ratio'] ?? null,
            ],
            'Protected thumbnails intentionally remain PHP-authorized, but large browser fanout can consume scarce shared-host PHP workers. Early session release and reduced per-thumbnail metadata work limit the impact without bypassing access policy.'
        );
    }

    $db = is_array($report['database_summary'] ?? null) ? $report['database_summary'] : [];
    if ((int) ($db['failed_count'] ?? 0) > 0 || (int) ($db['prepare_failed_count'] ?? 0) > 0 || (int) ($db['transaction_failed_count'] ?? 0) > 0) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'database_failure', 'One or more traced database operations failed.', [
            'query_failures' => (int) ($db['failed_count'] ?? 0), 'prepare_failures' => (int) ($db['prepare_failed_count'] ?? 0), 'transaction_failures' => (int) ($db['transaction_failed_count'] ?? 0),
        ], 'Database failures are never treated as normal performance variation.');
    }
    if (isset($db['all_traced_transactions_closed']) && !$db['all_traced_transactions_closed']) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'unclosed_transaction', 'At least one traced database transaction did not record a matching commit or rollback.', ['balances' => $db['unclosed_transaction_balance_by_request'] ?? []], 'Open transactions can retain locks/resources and indicate interrupted request logic.');
    }

    $sql = is_array($report['sql_hotspots'] ?? null) ? $report['sql_hotspots'] : [];
    $highQueryRequests = [];
    $schemaHeavyRequests = [];
    foreach ((array) ($sql['per_request'] ?? []) as $requestId => $requestSql) {
        if (!is_array($requestSql)) {
            continue;
        }
        $requestQueryCount = (int) ($requestSql['query_count_declared'] ?? 0);
        if ($requestQueryCount >= 300) {
            $highQueryRequests[] = ['request_id' => (string) $requestId, 'query_count' => $requestQueryCount];
        }
        $requestSchemaCount = (int) ($requestSql['schema_inspection_count'] ?? 0);
        if ($requestSchemaCount >= 50) {
            $schemaHeavyRequests[] = ['request_id' => (string) $requestId, 'schema_inspection_count' => $requestSchemaCount];
        }
    }
    if ($highQueryRequests !== []) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'sql_query_count_high', 'At least one PHP request executed a high number of SQL statements.', ['requests' => array_slice($highQueryRequests, 0, 10)], 'The threshold is applied per request, not to the multi-request Test Run aggregate. 300 queries is deliberately above the recent optimized 150-200 query Gallery range.');
    }
    if ($schemaHeavyRequests !== []) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'schema_inspection_repeated', 'Schema-inspection SQL remains heavily repeated inside at least one PHP request.', ['requests' => array_slice($schemaHeavyRequests, 0, 10)], 'Per-request analysis avoids falsely inflating the finding merely because a Test Run contains several healthy requests.');
    }
    if (!empty($sql['possible_n_plus_one_candidates'])) {
        admin_test_run_add_analysis_flag($flags, 'info', 'possible_n_plus_one', 'A repeated SELECT fingerprint is concentrated inside one PHP request and may represent N+1 behavior.', ['candidate_count' => count((array) $sql['possible_n_plus_one_candidates']), 'top_candidates' => array_slice((array) $sql['possible_n_plus_one_candidates'], 0, 5)], 'Candidates are derived per request. Parameter values are not stored, so this remains a conservative possible-N+1 classification rather than a definitive error.');
    }
    $unexpectedWrites = array_values(array_filter((array) ($sql['write_side_effects'] ?? []), static fn ($row): bool => is_array($row) && ($row['classification'] ?? '') === 'unexpected_write'));
    if ($unexpectedWrites !== []) {
        admin_test_run_add_analysis_flag($flags, 'critical', 'unexpected_database_write', 'Test Run observed a write to content/identity/permission/vote/tag data.', ['count' => count($unexpectedWrites), 'writes' => array_slice($unexpectedWrites, 0, 20)], 'A diagnostic run must not intentionally mutate Gallery content, users, permissions, votes, tags, or galleries.');
    }

    $cache = is_array($report['cache']['after_run']['families']['application_cache'] ?? null) ? $report['cache']['after_run']['families']['application_cache'] : [];
    $cacheBytes = (int) ($cache['bytes'] ?? 0);
    if ($cacheBytes >= 1024 * 1024 * 1024) {
        admin_test_run_add_analysis_flag($flags, 'info', 'cache_large', 'Application cache is at least about 1 GiB.', ['bytes' => $cacheBytes], 'Large cache size is not automatically an error; updater artifacts may legitimately dominate. Semantic family totals should be reviewed before cleanup decisions.');
    }
    if (!empty($report['cache']['after_run']['truncated'])) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'cache_inventory_truncated', 'Detailed cache inventory hit its explicit entry/time bound.', [
            'reason' => $report['cache']['after_run']['truncation_reason'] ?? '',
            'entries_visited' => $report['cache']['after_run']['entries_visited'] ?? 0,
            'scan_elapsed_ms' => $report['cache']['after_run']['scan_elapsed_ms'] ?? 0,
        ], 'The diagnostic intentionally stops rather than recursively scanning an unbounded cache tree.');
    }

    $maintenance = is_array($report['cron_and_maintenance'] ?? null) ? $report['cron_and_maintenance'] : [];
    foreach ((array) ($maintenance['tasks'] ?? []) as $name => $task) {
        if (!is_array($task)) continue;
        if (!empty($task['stale_lock_assessment']['possible_stale'])) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'maintenance_stale_lock', 'A maintenance/background lock may be stale or abnormally long-running.', ['subsystem' => $name, 'assessment' => $task['stale_lock_assessment']], 'Kernel-busy locks are only called possibly stale after exceeding a conservative budget/lease threshold.');
        }
        if (!empty($task['overdue'])) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'maintenance_overdue', 'A scheduled/background subsystem appears substantially overdue.', ['subsystem' => $name], 'Overdue classification uses the subsystem cadence and a conservative grace interval.');
        }
        if ($name === 'site_maintenance' && !empty($task['self_loopback_chain_capable'])) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'maintenance_self_loopback_capable', 'Site maintenance still exposes a self-loopback web-cron continuation capability.', ['subsystem' => $name, 'can_execute_after_response' => $task['can_execute_after_response'] ?? null], 'The capability is reported even when it did not run. Self-HTTP continuation can consume scarce shared-host PHP workers and was previously identified as a hosting-risk pattern.');
        }
        if (!empty($task['public_anonymous_traffic_can_trigger']) && !empty($task['currently_active_job']) && $name === 'automatic_updater') {
            admin_test_run_add_analysis_flag($flags, 'warning', 'public_request_can_progress_active_updater', 'An active updater job can be progressed by normal public GET/HEAD traffic before response completion.', ['subsystem' => $name, 'budget' => $task['configured_execution_budget_seconds'] ?? null], 'This is bounded existing updater behavior, but on constrained shared hosting it can add worker occupancy to an otherwise normal public request.');
        }
        if (!empty($task['current_test_run_attempted_or_scheduled_it'])) {
            admin_test_run_add_analysis_flag($flags, 'info', 'maintenance_activity_during_test_run', 'A maintenance/background subsystem was actually considered, scheduled, or run during this Test Run.', ['subsystem' => $name, 'events' => $task['current_test_run_attempted_or_scheduled_it']], 'The diagnostic does not force maintenance; this records normal application behavior that happened to coincide with the run.');
        }
    }
    if ((int) ($maintenance['active_background_subsystem_count'] ?? 0) > 1) {
        admin_test_run_add_analysis_flag($flags, 'warning', 'multiple_background_subsystems_active', 'Multiple maintenance/background subsystems appear active concurrently.', ['count' => (int) $maintenance['active_background_subsystem_count']], 'Overlapping bounded jobs may still contend for a small shared-host PHP worker pool.');
    }

    foreach ((array) ($report['browser']['probes'] ?? []) as $probe) {
        if (!is_array($probe)) {
            continue;
        }
        $browserCacheControl = admin_test_run_cache_control_analysis((array) ($probe['provider_headers'] ?? []));
        if (!empty($browserCacheControl['conflict'])) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'conflicting_cache_control', 'A browser-observed response contained conflicting Cache-Control directives.', [
                'source' => 'browser_probe',
                'probe' => (string) ($probe['name'] ?? ''),
                'url' => (string) ($probe['url'] ?? ''),
                'cache_control' => $browserCacheControl,
            ], 'Browser-observed headers include provider/CDN/proxy mutations that are not necessarily visible in PHP headers. Conflicting duplicate directive values, such as two different max-age values, are flagged.');
            break;
        }
    }

    foreach ((array) ($report['requests'] ?? []) as $request) {
        if (!is_array($request)) continue;
        $phpCacheControl = admin_test_run_cache_control_analysis((array) ($request['response']['headers'] ?? []));
        if (!empty($phpCacheControl['conflict'])) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'conflicting_cache_control', 'A traced PHP response emitted conflicting Cache-Control semantics.', ['source' => 'php_headers', 'request_id' => $request['request_id'] ?? '', 'uri' => $request['request']['uri'] ?? '', 'cache_control' => $phpCacheControl], 'Conflicting public/private, no-store/cacheable, or duplicate directive values make cache behavior difficult to reason about.');
            break;
        }
        $startMemory = (int) ($request['process']['memory_usage_bytes'] ?? 0);
        $endMemory = (int) ($request['process_end']['memory_usage_bytes'] ?? 0);
        $delta = $endMemory - $startMemory;
        if ($delta >= 64 * 1024 * 1024) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'request_memory_delta_large', 'A traced request retained/allocated an unusually large amount of PHP memory between instrumentation start and shutdown.', ['request_id' => $request['request_id'] ?? '', 'memory_delta_bytes' => $delta], '64 MiB is deliberately conservative relative to recent stable Gallery request memory around 20-22 MiB.');
        }
        $duration = (float) ($request['duration_from_request_ms'] ?? 0.0);
        if ($duration >= 5000.0) {
            admin_test_run_add_analysis_flag($flags, 'warning', 'php_request_duration_abnormal', 'A traced PHP request itself took at least five seconds from server request timestamp to shutdown.', ['request_id' => $request['request_id'] ?? '', 'duration_ms' => $duration, 'uri' => $request['request']['uri'] ?? ''], 'This flag applies to measured PHP request lifetime, unlike outside-PHP TTFB flags.');
        }
    }

    $opcache = is_array($report['runtime_finalizer']['opcache'] ?? null) ? $report['runtime_finalizer']['opcache'] : [];
    if (($opcache['status_access'] ?? '') === 'restricted') {
        admin_test_run_add_analysis_flag($flags, 'info', 'opcache_status_restricted', 'Zend OPcache is present but host policy restricts status introspection.', [], 'This is a diagnostics capability limitation, not an application PHP last_error.');
    }

    usort($flags, static function (array $a, array $b): int {
        $order = ['critical' => 3, 'warning' => 2, 'info' => 1];
        return ($order[$b['severity']] ?? 0) <=> ($order[$a['severity']] ?? 0);
    });
    return $flags;
}
