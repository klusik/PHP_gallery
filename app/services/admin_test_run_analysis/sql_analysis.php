<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_run_analysis/sql_analysis.php
 * Module Type: Service
 *
 * Purpose:
 *   Analyses recorded SQL activity into hotspots and write classifications.
 *
 * Responsibilities:
 *   - Group recorded queries by shape and callsite into hotspots
 *   - Classify database writes and separate schema inspection traffic
 *   - Compute percentile timings over recorded query durations
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
