<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_dimension_normalization_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects Stage 6 metric-specific hourly telemetry dimension normalization.
 *
 * Responsibilities:
 *   - Verify report-required dimensions are preserved for each metric family
 *   - Verify irrelevant high-cardinality dimensions collapse to neutral values
 *   - Verify device_type remains available for bot/non-bot report segmentation
 *   - Verify unknown future metrics retain all dimensions until explicitly classified
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

/** Throw when one telemetry normalization contract fails. */
function telemetry_dimension_contract_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$dimensions = [
    'route_name' => 'gallery',
    'page_kind' => 'gallery',
    'gallery_id' => 42,
    'image_id' => 99,
    'browser_family' => 'chrome',
    'os_family' => 'windows',
    'device_type' => 'bot',
    'viewport_class' => 'xxl',
    'country_code' => 'CZ',
    'referrer_category' => 'social',
    'media_variant' => 'thumb_1200',
    'cache_result' => 'hit',
];

$page = telemetry_hourly_metric_dimensions('public.page_views', $dimensions);
telemetry_dimension_contract_assert($page['route_name'] === 'gallery' && $page['page_kind'] === 'gallery' && $page['gallery_id'] === 42, 'Page-view metrics must preserve route, page kind, and gallery dimensions used by reports.');
telemetry_dimension_contract_assert($page['device_type'] === 'bot', 'Every classified hourly metric must preserve device_type for traffic segmentation.');
telemetry_dimension_contract_assert($page['image_id'] === 0 && $page['browser_family'] === 'unknown' && $page['media_variant'] === 'unknown' && $page['cache_result'] === 'unknown', 'Page-view metrics must neutralize unrelated high-cardinality dimensions.');

$photo = telemetry_hourly_metric_dimensions('photo.views', $dimensions);
telemetry_dimension_contract_assert($photo['route_name'] === 'gallery' && $photo['gallery_id'] === 42 && $photo['image_id'] === 99, 'Photo metrics must preserve route, gallery, and image dimensions required by engagement reports.');
telemetry_dimension_contract_assert($photo['page_kind'] === 'unknown' && $photo['referrer_category'] === 'unknown', 'Photo metrics must not multiply hourly rows by unused page/referrer dimensions.');

$media = telemetry_hourly_metric_dimensions('media.thumbnail.bytes', $dimensions);
telemetry_dimension_contract_assert($media['route_name'] === 'gallery' && $media['gallery_id'] === 42 && $media['media_variant'] === 'thumb_1200', 'Media-byte metrics must preserve route, gallery, and variant reporting dimensions.');
telemetry_dimension_contract_assert($media['image_id'] === 0 && $media['cache_result'] === 'unknown' && $media['viewport_class'] === 'unknown', 'Media-byte metrics must neutralize image/cache/viewport dimensions that current reports do not consume.');

$cache = telemetry_hourly_metric_dimensions('cache.lightbox.hit', $dimensions);
telemetry_dimension_contract_assert($cache['device_type'] === 'bot' && $cache['cache_result'] === 'hit', 'Cache metrics must preserve traffic segment and measured cache result.');
telemetry_dimension_contract_assert($cache['route_name'] === '' && $cache['gallery_id'] === 0 && $cache['media_variant'] === 'unknown', 'Cache metrics must collapse unrelated routing/content dimensions.');

$future = telemetry_hourly_metric_dimensions('future.metric', $dimensions);
telemetry_dimension_contract_assert($future === $dimensions, 'Unknown future metrics must retain their dimensions until an explicit normalization contract is defined.');

echo "telemetry_dimension_normalization_contract_test: ok\n";
