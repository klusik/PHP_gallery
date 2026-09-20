<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_repair_semantics_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercises telemetry repairs using isolated deterministic fixtures.
 *
 * Responsibilities:
 *   - Execute production behavior with isolated inputs
 *   - Fail on privacy, recovery or reporting regressions
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply the timestamp helper expected by the isolated privacy service.
     * @return string Result of the documented operation.
     */
    function now_sql(): string { return date('Y-m-d H:i:s'); }
    /**
     * Supply a normalized request route to the observer without HTTP globals.
     * @param string $part Request context part; the isolated fixture exposes no HTTP request state.
     * @return array<string,mixed> Result of the documented operation.
     */
    function request_data(string $part): array { return ['page' => 'fixture']; }
}
namespace Gallery\Services {
    /**
     * Return legacy session counters to prove the new helper replaces only activity totals.
     * @param int $days Report lookback or configured retention age in days.
     * @param string $segment Normalized technical traffic segment.
     * @return array<string,mixed> Result of the documented operation.
     */
    function telemetry_report_session_summary(int $days, string $segment): array { return ['sessions' => 10, 'page_views' => 40, 'photo_views' => 90, 'duration_seconds' => 17]; }
    /**
     * Return canonical event counts without mutating legacy rows.
     * @param string $name Canonical metric name.
     * @param int $days Report lookback or configured retention age in days.
     * @param string $segment Normalized technical traffic segment.
     * @return int Result of the documented operation.
     */
    function telemetry_metric_events(string $name, int $days, string $segment): int { return $name === 'public.page_views' ? 20 : 60; }
}
namespace {
    require_once dirname(__DIR__) . '/app/services/telemetry_privacy.php';
    require_once dirname(__DIR__) . '/app/services/telemetry_diagnostics.php';
    require_once dirname(__DIR__) . '/app/services/database_observer.php';
    /**
     * Assert normalized semantics without persisted identities or external services.
     * @param bool $ok Whether the asserted invariant holds.
     * @param string $why Diagnostic message emitted if the invariant fails.
     * @return void No return value; effects are recorded in the owned state.
     */
    function ts_assert(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
    $cases = [
        '' => 'unknown', 'curl/8.0' => 'unknown', 'Googlebot' => 'bot',
        'Mozilla/5.0 HeadlessChrome/120.0' => 'bot',
        'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537.36' => 'desktop',
        'Mozilla/5.0 (iPhone) AppleWebKit Safari Mobile' => 'phone',
        'Mozilla/5.0 (iPad) AppleWebKit Safari Mobile' => 'tablet',
    ];
    foreach ($cases as $ua => $device) {
        $buckets = \Gallery\Services\telemetry_user_agent_buckets($ua);
        ts_assert($buckets['device_type'] === $device, 'Incorrect coarse device bucket: ' . $device);
        ts_assert(count($buckets) === 3 && !isset($buckets['user_agent']), 'Raw UA must never leave the classifier.');
    }
    $context = json_decode((string) \Gallery\Services\telemetry_context_json('cache.lightbox.hit',
        ['source_kind' => 'https://secret.invalid/token', 'lookup_phase' => 'current_preview', 'url' => 'private']), true);
    ts_assert($context === ['source_kind' => 'unknown', 'lookup_phase' => 'current_preview'], 'Cache context must reject unbounded strings and URL fields.');
    $summary = \Gallery\Services\telemetry_report_canonical_summary(30);
    ts_assert($summary['page_views'] === 20 && $summary['photo_views'] === 60 && $summary['avg_pages_per_session'] === 2,
        'Complete Overview must use canonical event counts and matching denominators.');
    ts_assert($summary['legacy_session_page_views'] === 40 && $summary['duration_seconds'] === 17, 'Canonical summary must preserve legacy evidence and duration semantics.');
    ts_assert(\Gallery\Services\telemetry_sql_table_name('CREATE TABLE IF NOT EXISTS galleries (id INT)') === 'galleries', 'IF must not be misclassified as a table.');
    ts_assert(\Gallery\Services\telemetry_sql_table_name('DROP TABLE IF EXISTS images') === 'images', 'DROP IF EXISTS table must be identified.');
    $state = &\Gallery\Services\telemetry_database_observer_state();
    $state['evaluated'] = true; $state['enabled'] = true; $state['shutdown_registered'] = true;
    \Gallery\Services\telemetry_observe_db_query('SHOW COLUMNS FROM galleries', 1.0, true, 50);
    \Gallery\Services\telemetry_observe_db_query('UPDATE images SET width = 42', 1.0, true, 2);
    \Gallery\Services\telemetry_observe_db_query('DELETE FROM images WHERE id = 99', 1.0, false, 10);
    $rows = array_values($state['buffer']);
    ts_assert(array_sum(array_column($rows, 'rows_affected_sum')) === 2, 'Metadata and failed statements must not count as changed data rows.');
    echo "Telemetry repair semantics fixtures passed.\n";
}
