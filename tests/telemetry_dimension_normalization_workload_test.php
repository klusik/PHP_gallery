<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_dimension_normalization_workload_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Demonstrates that Stage 6 metric-specific dimension normalization reduces
 *   aggregate-key cardinality on a representative synthetic workload without
 *   changing aggregate totals.
 *
 * Responsibilities:
 *   - Exercise varied irrelevant dimensions for page, performance, and media metrics
 *   - Verify normalized aggregate keys collapse substantially where intended
 *   - Verify dimensions that remain semantically required are not collapsed away
 *   - Verify aggregate event/value totals are unchanged by key normalization
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

require_once dirname(__DIR__) . '/app/services/telemetry.php';

use function Gallery\Services\telemetry_hourly_metric_dimensions;

/** Throw when one representative normalization workload invariant fails. */
function telemetry_dimension_workload_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Return unique raw/normalized key counts and preserved event total for one metric.
 *
 * @param string $metricName Metric identifier.
 * @param array<int,array<string,mixed>> $workload Synthetic dimension rows.
 * @return array{raw_keys:int,normalized_keys:int,total_events:int}
 */
function telemetry_dimension_workload_measure(string $metricName, array $workload): array
{
    $rawKeys = [];
    $normalizedKeys = [];
    $totalEvents = 0;
    foreach ($workload as $dimensions) {
        $rawKeys[json_encode($dimensions, JSON_THROW_ON_ERROR)] = true;
        $normalized = telemetry_hourly_metric_dimensions($metricName, $dimensions);
        $normalizedKeys[json_encode($normalized, JSON_THROW_ON_ERROR)] = true;
        $totalEvents++;
    }
    return [
        'raw_keys' => count($rawKeys),
        'normalized_keys' => count($normalizedKeys),
        'total_events' => $totalEvents,
    ];
}

$workload = [];
for ($i = 0; $i < 120; $i++) {
    $workload[] = [
        'route_name' => 'gallery',
        'page_kind' => 'gallery',
        'gallery_id' => 42,
        'image_id' => 1000 + $i,
        'browser_family' => ['chrome', 'edge', 'firefox', 'safari'][$i % 4],
        'os_family' => ['windows', 'macos', 'linux'][$i % 3],
        'device_type' => $i % 2 === 0 ? 'desktop' : 'bot',
        'viewport_class' => ['md', 'lg', 'xl', 'xxl'][$i % 4],
        'country_code' => ['CZ', 'DE', 'SE'][$i % 3],
        'referrer_category' => ['direct', 'social', 'external'][$i % 3],
        'media_variant' => ['thumb_300', 'thumb_600', 'thumb_960', 'thumb_1200'][intdiv($i, 2) % 4],
        'cache_result' => ['hit', 'miss', 'evicted'][$i % 3],
    ];
}

$page = telemetry_dimension_workload_measure('public.page_views', $workload);
telemetry_dimension_workload_assert($page['raw_keys'] === 120, 'Synthetic page-view workload must begin with 120 distinct wide-dimension keys.');
telemetry_dimension_workload_assert($page['normalized_keys'] === 2, 'Page-view normalization should collapse the representative workload to route/page/gallery/device semantics only.');
telemetry_dimension_workload_assert($page['total_events'] === 120, 'Page-view normalization must not alter the event total represented by the workload.');
telemetry_dimension_workload_assert($page['normalized_keys'] * 10 < $page['raw_keys'], 'Page-view normalization must demonstrate a material cardinality reduction on the representative workload.');

$performance = telemetry_dimension_workload_measure('client.page_load_ms', $workload);
telemetry_dimension_workload_assert($performance['normalized_keys'] === 2, 'Performance normalization should preserve only route/page/device semantics for this workload.');
telemetry_dimension_workload_assert($performance['total_events'] === 120, 'Performance normalization must preserve sample count.');

$media = telemetry_dimension_workload_measure('media.thumbnail.bytes', $workload);
telemetry_dimension_workload_assert($media['normalized_keys'] === 8, 'Media normalization should preserve device and four media variants while collapsing unrelated image/client dimensions.');
telemetry_dimension_workload_assert($media['total_events'] === 120, 'Media normalization must preserve event count.');

$photo = telemetry_dimension_workload_measure('photo.views', $workload);
telemetry_dimension_workload_assert($photo['normalized_keys'] === 120, 'Photo-view normalization must retain image identity because per-image engagement is a report requirement.');

$future = telemetry_dimension_workload_measure('future.metric', $workload);
telemetry_dimension_workload_assert($future['normalized_keys'] === 120, 'Unknown metrics must not receive speculative dimension collapsing.');

echo "telemetry_dimension_normalization_workload_test: ok\n";
