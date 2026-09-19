<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_media_observability_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects PHP-observed media-byte and decoded-lightbox cache reporting semantics.
 *
 * Responsibilities:
 *   - Verify image and thumbnail byte events are emitted only from PHP response paths
 *   - Verify progressive source downloads emit measured download bytes
 *   - Verify the namespaced telemetry service is invoked directly instead of a dead global hook
 *   - Verify reports label partial media coverage and distinguish disabled/no-sample cache states
 *   - Verify decoded-lightbox cache events carry bounded hit/miss/evicted results
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

/** Throw when one server media-observability contract fails. */
function telemetry_media_observability_contract_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$serviceSource = (string) file_get_contents($root . '/app/services/telemetry.php');
$mediaControllerSource = (string) file_get_contents($root . '/app/controllers/public_media.php');
$downloadControllerSource = (string) file_get_contents($root . '/app/controllers/downloads.php');
$adminControllerSource = (string) file_get_contents($root . '/app/controllers/admin_telemetry.php');
$reportViewSource = (string) file_get_contents($root . '/app/views/admin_telemetry.php');
$usageSource = (string) file_get_contents($root . '/public/assets/usage.js');

telemetry_media_observability_contract_assert(
    str_contains($serviceSource, "'media.image.served'")
        && str_contains($serviceSource, "'media.thumbnail.served'")
        && str_contains($serviceSource, "'media.download.served'"),
    'Strict server telemetry allowlist must include each PHP-observed media response class.'
);
telemetry_media_observability_contract_assert(
    str_contains($mediaControllerSource, 'use function Gallery\\Services\\telemetry_record_media_served_event;')
        && !str_contains($mediaControllerSource, "function_exists('telemetry_record_media_served_event')")
        && !str_contains($mediaControllerSource, '\\telemetry_record_media_served_event('),
    'Public media controller must call the real namespaced telemetry service instead of the historical dead global hook.'
);
telemetry_media_observability_contract_assert(
    substr_count($mediaControllerSource, "'media.thumbnail.served'") >= 2
        && substr_count($mediaControllerSource, "'media.image.served'") >= 2
        && substr_count($mediaControllerSource, '$readBytes = readfile($path);') >= 4
        && substr_count($mediaControllerSource, '$readBytes > 0') >= 4,
    'Thumbnail and full-image PHP response branches must record actual positive readfile byte counts after streaming.'
);
telemetry_media_observability_contract_assert(
    str_contains($downloadControllerSource, 'use function Gallery\\Services\\telemetry_record_media_served_event;')
        && str_contains($downloadControllerSource, '$readBytes = readfile((string) $resolved[\'path\']);')
        && str_contains($downloadControllerSource, "'media.download.served'")
        && str_contains($downloadControllerSource, "'download_source'"),
    'Progressive/source download streaming must emit measured media.download.served bytes from its authoritative response path.'
);
telemetry_media_observability_contract_assert(
    str_contains($serviceSource, "'cacheEnabled' => telemetry_setting_enabled('telemetry_cache_enabled', '1')")
        && str_contains($serviceSource, "str_starts_with(\$eventName, 'cache.')")
        && str_contains($usageSource, 'config.cacheEnabled === false')
        && str_contains($usageSource, "event.cache_result = eventName === 'cache.lightbox.hit'")
        && str_contains($usageSource, "eventName === 'cache.lightbox.evicted' ? 'evicted' : 'unknown'"),
    'Decoded-lightbox cache telemetry must honor its dedicated switch and transport only bounded cache-result buckets.'
);
telemetry_media_observability_contract_assert(
    str_contains($adminControllerSource, "telemetry_metric_events('cache.lightbox.hit'")
        && str_contains($adminControllerSource, "telemetry_metric_events('cache.lightbox.miss'")
        && str_contains($adminControllerSource, '$cacheEfficiencyDisplay')
        && str_contains($adminControllerSource, '$cacheTelemetryEnabled')
        && str_contains($adminControllerSource, '$cacheEmptyText')
        && str_contains($adminControllerSource, 'admin.telemetry.export.no_cache_samples'),
    'Cache efficiency must be based on the measured decoded-lightbox cache and distinguish disabled from no-sample states.'
);
telemetry_media_observability_contract_assert(
    str_contains($reportViewSource, 'admin.telemetry.export.media_scope_note')
        && str_contains($reportViewSource, 'admin.telemetry.export.cache_scope_note')
        && str_contains($reportViewSource, '$cacheEfficiencyDisplay'),
    'Telemetry export must disclose partial PHP media coverage and the application-cache scope.'
);

echo "telemetry_media_observability_contract_test: ok\n";
