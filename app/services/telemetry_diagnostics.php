<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/telemetry_diagnostics.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides canonical telemetry summaries and bounded diagnostic semantics.
 *
 * Responsibilities:
 *   - Keep bounded telemetry operations in their owning MVC layer
 *   - Preserve visitor privacy and explicit diagnostic outcomes
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

namespace Gallery\Services;

use Throwable;
use function Gallery\Models\telemetry_model_report_cache_phases;

/**
 * Prepare event-window totals without mutating historical session counters.
 *
 * Session duration, bounce and entry/exit counts remain session-cohort metrics;
 * only page/photo activity is replaced by the canonical event aggregates. The
 * legacy count remains explicit for consistency diagnostics and old-session UI.
 * @param int $days Report lookback in days.
 * @param string $trafficSegment Normalized reporting segment.
 * @return array<string,mixed> Prepared summary with canonical activity totals.
 */
function telemetry_report_canonical_summary(int $days, string $trafficSegment = 'all'): array
{
    $row = telemetry_report_session_summary($days, $trafficSegment);
    $row['legacy_session_page_views'] = (int) ($row['page_views'] ?? 0);
    $row['page_views'] = telemetry_metric_events('public.page_views', $days, $trafficSegment);
    $row['photo_views'] = telemetry_metric_events('photo.views', $days, $trafficSegment);
    $sessions = (int) ($row['sessions'] ?? 0);
    $row['avg_pages_per_session'] = $sessions > 0 ? $row['page_views'] / $sessions : 0.0;
    $row['avg_photos_per_session'] = $sessions > 0 ? $row['photo_views'] / $sessions : 0.0;
    $row['legacy_page_counter_difference'] = $row['legacy_session_page_views'] - $row['page_views'];
    return $row;
}

/**
 * Prepare phase-specific cache ratios from the raw-event retention window.
 * @param int $days Requested diagnostic window.
 * @param string $trafficSegment Requested technical segment.
 * @return array{available:bool,days:int,rows:array} Availability and prepared counts.
 */
function telemetry_report_cache_phases(int $days, string $trafficSegment = 'all'): array
{
    $days = max(1, min($days, telemetry_retention_days('telemetry_raw_retention_days', 7, 1, 90)));
    try {
        $rows = telemetry_model_report_cache_phases($days, telemetry_traffic_segment($trafficSegment));
        foreach ($rows as &$row) {
            $hits = max(0, (int) ($row['hits'] ?? 0));
            $misses = max(0, (int) ($row['misses'] ?? 0));
            $row['hit_percent'] = $hits + $misses > 0 ? 100 * $hits / ($hits + $misses) : 0.0;
        }
        unset($row);
        return ['available' => true, 'days' => $days, 'rows' => $rows];
    } catch (Throwable) {
        return ['available' => false, 'days' => $days, 'rows' => []];
    }
}
