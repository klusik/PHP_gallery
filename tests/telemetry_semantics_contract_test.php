<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_semantics_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects telemetry session/page-view semantics introduced by audit remediation.
 *
 * Responsibilities:
 *   - Verify session lifecycle events do not count as page views
 *   - Verify canonical daily session rows override legacy public.sessions aggregates
 *   - Verify the report reads unique sessions from telemetry_sessions
 *   - Verify corrected telemetry semantics are versioned without destructive history reset
 *   - Verify setup-safe telemetry preference validation is strict and bounded
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

use function Gallery\Services\telemetry_admin_setting_normalize;
use function Gallery\Services\telemetry_merge_daily_trends;
use function Gallery\Services\telemetry_session_page_increment_for_event;

/** Throw when one telemetry semantics contract fails. */
function telemetry_semantics_contract_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/** Assert that strict telemetry preference validation rejects malformed input. */
function telemetry_semantics_contract_assert_invalid(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($label);
}

$root = dirname(__DIR__);
require_once $root . '/app/services/telemetry.php';
require_once $root . '/app/services/telemetry_settings.php';

telemetry_semantics_contract_assert(
    telemetry_session_page_increment_for_event('public.session.started') === 0,
    'Session-start events must not increment the session page-view counter.'
);
telemetry_semantics_contract_assert(
    telemetry_session_page_increment_for_event('public.page.viewed') === 1,
    'Explicit page-view events must increment the session page-view counter.'
);
telemetry_semantics_contract_assert(
    telemetry_session_page_increment_for_event('public.gallery.viewed') === 1,
    'Explicit gallery-view events must increment the session page-view counter.'
);
telemetry_semantics_contract_assert(
    telemetry_session_page_increment_for_event('public.photo.opened') === 0,
    'Photo-open events must not increment the session page-view counter.'
);

telemetry_semantics_contract_assert(
    telemetry_admin_setting_normalize('telemetry_respect_dnt', true) === '1',
    'Safe telemetry checkbox validation must accept canonical boolean values.'
);
telemetry_semantics_contract_assert(
    telemetry_admin_setting_normalize('telemetry_max_photo_view_seconds', '3600') === '3600',
    'Safe telemetry numeric validation must accept the documented upper boundary.'
);
telemetry_semantics_contract_assert_invalid(
    static fn (): string => telemetry_admin_setting_normalize('telemetry_respect_dnt', 'yes'),
    'Safe telemetry checkbox validation must reject non-canonical values.'
);
telemetry_semantics_contract_assert_invalid(
    static fn (): string => telemetry_admin_setting_normalize('telemetry_max_photo_view_seconds', '3601'),
    'Safe telemetry numeric validation must reject values above the documented boundary.'
);
telemetry_semantics_contract_assert_invalid(
    static fn (): string => telemetry_admin_setting_normalize('telemetry_unknown_setting', '1'),
    'Safe telemetry validation must reject unknown setting identifiers.'
);

$merged = telemetry_merge_daily_trends(
    [
        [
            'report_date' => '2026-09-17',
            'page_views' => 8,
            'photo_views' => 12,
            'photo_seconds' => 40,
            'client_errors' => 0,
            'media_bytes' => 100,
        ],
        [
            'report_date' => '2026-09-19',
            'page_views' => 4,
            'photo_views' => 7,
            'photo_seconds' => 20,
            'client_errors' => 1,
            'media_bytes' => 50,
        ],
    ],
    [
        ['report_date' => '2026-09-17', 'sessions' => 3],
        ['report_date' => '2026-09-18', 'sessions' => 2],
        ['report_date' => '2026-09-19', 'sessions' => 1],
    ]
);

telemetry_semantics_contract_assert(count($merged) === 3, 'Daily trend merge must retain dates present in either source.');
telemetry_semantics_contract_assert(($merged[0]['report_date'] ?? '') === '2026-09-17' && (int) ($merged[0]['sessions'] ?? -1) === 3, 'Daily trend merge must use canonical session counts for metric dates.');
telemetry_semantics_contract_assert(($merged[1]['report_date'] ?? '') === '2026-09-18' && (int) ($merged[1]['page_views'] ?? -1) === 0 && (int) ($merged[1]['sessions'] ?? -1) === 2, 'Session-only dates must remain visible with zero event metrics.');
telemetry_semantics_contract_assert(($merged[2]['report_date'] ?? '') === '2026-09-19' && (int) ($merged[2]['sessions'] ?? -1) === 1, 'Merged daily rows must remain date-sorted.');

$modelSource = (string) file_get_contents($root . '/app/models/telemetry.php');
$serviceSource = (string) file_get_contents($root . '/app/services/telemetry.php');
$settingsSource = (string) file_get_contents($root . '/app/services/telemetry_settings.php');
$controllerSource = (string) file_get_contents($root . '/app/controllers/admin_telemetry.php');

telemetry_semantics_contract_assert(
    str_contains($modelSource, "function telemetry_model_report_daily_sessions(int \$days, string \$trafficSegment = 'all'): array")
        && str_contains($modelSource, 'FROM telemetry_sessions')
        && str_contains($modelSource, 'COUNT(*) AS sessions'),
    'Daily session trends must be sourced from telemetry_sessions.'
);
$dailyTrendStart = strpos($modelSource, "function telemetry_model_report_daily_trends(int \$days, string \$trafficSegment = 'all'): array");
$dailyTrendEnd = $dailyTrendStart === false ? false : strpos($modelSource, "function telemetry_model_report_daily_sessions(int \$days, string \$trafficSegment = 'all'): array", $dailyTrendStart);
$dailyTrendSource = ($dailyTrendStart !== false && $dailyTrendEnd !== false)
    ? substr($modelSource, $dailyTrendStart, $dailyTrendEnd - $dailyTrendStart)
    : '';
telemetry_semantics_contract_assert(
    $dailyTrendSource !== '' && !str_contains($dailyTrendSource, 'public.sessions'),
    'Daily aggregate trend query must not use public.sessions as the canonical session total.'
);
telemetry_semantics_contract_assert(
    str_contains($serviceSource, 'telemetry_ensure_current_semantics_marker();')
        && str_contains($settingsSource, "const TELEMETRY_SEMANTICS_VERSION = '2';")
        && str_contains($settingsSource, 'function telemetry_semantics_effective_at(): ?string'),
    'Corrected telemetry semantics must record a non-destructive version boundary.'
);
telemetry_semantics_contract_assert(
    str_contains($controllerSource, "telemetry_metric_events('public.page_views', \$days, \$trafficSegment)")
        && str_contains($controllerSource, "(int) (\$sessionSummary['sessions'] ?? 0)"),
    'Telemetry executive reporting must use canonical session rows and canonical page-view event aggregates.'
);

// The bounce expression remains valid once only explicit page views increment the counter.
telemetry_semantics_contract_assert(
    str_contains($modelSource, 'bounced = IF(page_view_count + VALUES(page_view_count) <= 1, 1, 0)'),
    'Session bounce state must remain based on corrected page-view counts.'
);

echo "telemetry_semantics_contract_test: ok\n";
