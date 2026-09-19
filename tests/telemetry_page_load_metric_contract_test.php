<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_page_load_metric_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects page-load performance aggregation introduced by audit remediation.
 *
 * Responsibilities:
 *   - Map the browser page-load event to an explicit hourly metric
 *   - Keep the existing performance sample-rate producer intact
 *   - Include page-load data in browser-performance reporting
 *   - Keep sample count, label, and unit visible in the standalone report
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

use function Gallery\Services\telemetry_metric_name_for_event;

/** Throw when one page-load telemetry contract fails. */
function telemetry_page_load_metric_contract_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
require_once $root . '/app/services/telemetry.php';

telemetry_page_load_metric_contract_assert(
    telemetry_metric_name_for_event('client.performance.page_load', ['value_ms' => 1234]) === 'client.page_load_ms',
    'Page-load events must aggregate under client.page_load_ms.'
);

$modelSource = (string) file_get_contents($root . '/app/models/telemetry.php');
$usageSource = (string) file_get_contents($root . '/public/assets/usage.js');
$compatibilitySource = (string) file_get_contents($root . '/public/assets/telemetry.js');
$viewSource = (string) file_get_contents($root . '/app/views/admin_telemetry.php');

telemetry_page_load_metric_contract_assert(
    str_contains($modelSource, 'client.page_load_ms')
        && str_contains($modelSource, 'function telemetry_model_report_performance_metrics'),
    'Browser-performance report queries must include client.page_load_ms.'
);
telemetry_page_load_metric_contract_assert(
    str_contains($usageSource, "baseEvent('client.performance.page_load')")
        && str_contains($usageSource, 'config.performanceSampleRate')
        && str_contains($usageSource, 'function performanceTelemetrySampled()')
        && str_contains($usageSource, 'event.sampled_rate = performanceSamplingRate();')
        && str_contains($usageSource, 'event.value_ms ='),
    'The browser page-load producer must preserve sampled value_ms collection.'
);
telemetry_page_load_metric_contract_assert(
    hash('sha256', $usageSource) === hash('sha256', $compatibilitySource),
    'usage.js and telemetry.js must remain byte-identical compatibility assets.'
);
telemetry_page_load_metric_contract_assert(
    str_contains($viewSource, "'client.page_load_ms' => t('admin.telemetry.performance.page_load'")
        && str_contains($viewSource, "view_telemetry_performance_metric_unit")
        && str_contains($viewSource, "['key' => 'samples'")
        && str_contains($viewSource, "['key' => 'avg_value'")
        && str_contains($viewSource, "['key' => 'min_value'")
        && str_contains($viewSource, "['key' => 'max_value'"),
    'Performance output must expose a human label, unit, sample count, average, minimum, and maximum.'
);

foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $translations = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
    telemetry_page_load_metric_contract_assert(
        isset($translations['admin.telemetry.performance.page_load'])
            && isset($translations['admin.telemetry.export.unit'])
            && isset($translations['admin.telemetry.export.average_value']),
        $language . ' performance report translations are incomplete.'
    );
}

echo "telemetry_page_load_metric_contract_test: ok\n";
