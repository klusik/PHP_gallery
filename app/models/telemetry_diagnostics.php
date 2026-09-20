<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/telemetry_diagnostics.php
 * Module Type: Model
 *
 * Purpose:
 *   Reads bounded cache-phase diagnostics without exposing visitor identifiers.
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

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Count actual decoded-cache outcomes by normalized lookup phase.
 * @param int $days Window already bounded to raw-event retention by the service.
 * @param string $trafficSegment Validated technical device segment.
 * @return array<int,array<string,mixed>> Phase-level counts, never session IDs.
 */
function telemetry_model_report_cache_phases(int $days, string $trafficSegment = 'all'): array
{
    $phase = "CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.lookup_phase')) IN ('current_preview', 'current_full')"
        . " THEN JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.lookup_phase')) ELSE 'legacy_unclassified' END";
    $stmt = db()->prepare("SELECT " . $phase . " AS phase,"
        . " SUM(CASE WHEN event_name = 'cache.lightbox.hit' THEN 1 ELSE 0 END) AS hits,"
        . " SUM(CASE WHEN event_name = 'cache.lightbox.miss' THEN 1 ELSE 0 END) AS misses"
        . " FROM telemetry_events WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
        . " AND event_name IN ('cache.lightbox.hit', 'cache.lightbox.miss')"
        . telemetry_model_traffic_segment_condition($trafficSegment)
        . " GROUP BY " . $phase . " ORDER BY phase");
    telemetry_model_profiled_report_execute($stmt, [max(1, min(90, $days))], 'cache_phases');
    return $stmt->fetchAll();
}
