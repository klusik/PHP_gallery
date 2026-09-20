<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_traffic_segment_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects bot/non-bot telemetry report segmentation introduced by audit remediation.
 *
 * Responsibilities:
 *   - Keep the public report filter constrained to an explicit semantic enum
 *   - Keep SQL traffic predicates owned by the telemetry model
 *   - Verify all visitor-derived export datasets receive the selected segment
 *   - Keep operational database/job telemetry outside visitor traffic segmentation
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

use function Gallery\Models\telemetry_model_traffic_segment_condition;
use function Gallery\Services\telemetry_traffic_segment;

/** Throw when one traffic-segmentation contract fails. */
function telemetry_traffic_segment_contract_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
require_once $root . '/app/models/telemetry.php';
require_once $root . '/app/services/telemetry.php';

telemetry_traffic_segment_contract_assert(telemetry_traffic_segment('all') === 'all', 'All-traffic segment must remain available.');
telemetry_traffic_segment_contract_assert(telemetry_traffic_segment('non_bot') === 'non_bot', 'Non-bot-classified segment must remain available.');
telemetry_traffic_segment_contract_assert(telemetry_traffic_segment('bot') === 'bot', 'Bot-classified segment must remain available.');
telemetry_traffic_segment_contract_assert(telemetry_traffic_segment('unknown') === 'unknown', 'Unclassified traffic must be a distinct segment.');
telemetry_traffic_segment_contract_assert(telemetry_model_traffic_segment_condition('unknown') === " AND device_type = 'unknown'", 'Unknown must not be folded into non-bot traffic.');
telemetry_traffic_segment_contract_assert(telemetry_traffic_segment('HUMAN') === 'all', 'Unsupported identity-like segment names must fall back to all traffic.');
telemetry_traffic_segment_contract_assert(telemetry_traffic_segment(['bot']) === 'all', 'Non-scalar segment input must fall back to all traffic.');

telemetry_traffic_segment_contract_assert(telemetry_model_traffic_segment_condition('all') === '', 'All traffic must not add a device predicate.');
telemetry_traffic_segment_contract_assert(
    telemetry_model_traffic_segment_condition('non_bot') === " AND device_type IN ('desktop', 'tablet', 'phone')",
    'Non-bot-classified traffic must exclude bots AND unclassified historical media.'
);
telemetry_traffic_segment_contract_assert(
    telemetry_model_traffic_segment_condition('bot', 'm.device_type') === " AND m.device_type = 'bot'",
    'Bot-classified traffic must support the constrained hourly-metric alias.'
);

$invalidSegmentRejected = false;
try {
    telemetry_model_traffic_segment_condition('human');
} catch (InvalidArgumentException) {
    $invalidSegmentRejected = true;
}
telemetry_traffic_segment_contract_assert($invalidSegmentRejected, 'The model must reject unsupported traffic segments.');

$invalidColumnRejected = false;
try {
    telemetry_model_traffic_segment_condition('bot', 'events.device_type');
} catch (InvalidArgumentException) {
    $invalidColumnRejected = true;
}
telemetry_traffic_segment_contract_assert($invalidColumnRejected, 'The model must reject arbitrary SQL column identifiers.');

$controllerSource = (string) file_get_contents($root . '/app/controllers/admin_telemetry.php');
$serviceSource = (string) file_get_contents($root . '/app/services/telemetry.php');
$viewSource = (string) file_get_contents($root . '/app/views/admin_telemetry.php');

foreach ([
    'telemetry_report_session_summary($days, $trafficSegment)',
    'telemetry_report_daily_trends($days, $trafficSegment)',
    'telemetry_report_top_galleries($days, 25, $trafficSegment)',
    "telemetry_report_session_distribution('browser_family', \$days, 12, \$trafficSegment)",
    "telemetry_report_metric_distribution('page_kind', \$days, 'public.page_views', 12, \$trafficSegment)",
    'telemetry_report_performance_metrics($days, $trafficSegment)',
    'telemetry_report_client_errors($days, 25, $trafficSegment)',
    'telemetry_report_recent_events($days, 80, $trafficSegment)',
    "telemetry_metric_events('public.page_views', \$days, \$trafficSegment)",
    'telemetry_top_photos($days, 25, $trafficSegment)',
] as $requiredCall) {
    telemetry_traffic_segment_contract_assert(
        str_contains($controllerSource, $requiredCall),
        'Telemetry export must consistently segment visitor-derived dataset: ' . $requiredCall
    );
}

telemetry_traffic_segment_contract_assert(
    str_contains($controllerSource, "telemetry_report_database_totals(\$days)")
        && !str_contains($controllerSource, "telemetry_report_database_totals(\$days, \$trafficSegment)"),
    'Operational database telemetry must remain independent of visitor traffic segmentation.'
);
telemetry_traffic_segment_contract_assert(
    str_contains($serviceSource, "['all', 'non_bot', 'bot', 'unknown']")
        && str_contains($viewSource, "traffic_segment_note")
        && str_contains($viewSource, "export_non_bot_traffic"),
    'Service and presentation layers must expose the constrained segmentation contract.'
);

foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $translations = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ([
        'admin.telemetry.traffic_segment_all',
        'admin.telemetry.traffic_segment_non_bot',
        'admin.telemetry.traffic_segment_bot',
        'admin.telemetry.export.traffic_segment_note',
    ] as $key) {
        telemetry_traffic_segment_contract_assert(isset($translations[$key]) && trim((string) $translations[$key]) !== '', $language . ' translation missing: ' . $key);
    }
}

echo "telemetry_traffic_segment_contract_test: ok\n";
