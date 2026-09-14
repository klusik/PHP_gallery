<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/telemetry.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\request_data;

use Throwable;
use function Gallery\Core\append_cms_footer_html;
use function Gallery\Core\append_cms_footer_script;
use function Gallery\Core\asset_url;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\now_sql;
use function Gallery\Core\url_for;
use function Gallery\Models\telemetry_model_browser_mix;
use function Gallery\Models\telemetry_model_cache_mix;
use function Gallery\Models\telemetry_model_insert_event;
use function Gallery\Models\telemetry_model_longest_viewed_photos;
use function Gallery\Models\telemetry_model_metric_events;
use function Gallery\Models\telemetry_model_metric_sum;
use function Gallery\Models\telemetry_model_report_client_errors;
use function Gallery\Models\telemetry_model_report_daily_trends;
use function Gallery\Models\telemetry_model_report_database_fingerprints;
use function Gallery\Models\telemetry_model_report_database_summary;
use function Gallery\Models\telemetry_model_report_database_totals;
use function Gallery\Models\telemetry_model_report_job_runs;
use function Gallery\Models\telemetry_model_report_metric_distribution;
use function Gallery\Models\telemetry_model_report_performance_metrics;
use function Gallery\Models\telemetry_model_report_recent_events;
use function Gallery\Models\telemetry_model_report_session_distribution;
use function Gallery\Models\telemetry_model_report_session_summary;
use function Gallery\Models\telemetry_model_report_table_count;
use function Gallery\Models\telemetry_model_report_top_galleries;
use function Gallery\Models\telemetry_model_report_top_routes;
use function Gallery\Models\telemetry_model_top_photos;
use function Gallery\Models\telemetry_model_upsert_hourly_metric;
use function Gallery\Models\telemetry_model_upsert_session;

/**
 * Anonymous telemetry service.
 *
 * Public telemetry is local-only, disabled by default, and stores only normalized
 * technical buckets. The service rejects unknown events and never stores raw IP
 * addresses, raw user-agent strings, raw referrer URLs, or request bodies.
 */

/**
 * Return whether the core telemetry event table exists.
 *
 * @return bool True when the condition matches.
 */
function telemetry_schema_ready(): bool
{
    return presentation_schema_render_available(presentation_telemetry_schema_status(), 'telemetry_runtime');
}

/**
 * Return a stable request id for this PHP request.
 *
 * @return string Text result for the caller.
 */
function telemetry_request_id(): string
{
    if (!isset($GLOBALS['cms_request_id'])) {
        // $randomPart stores entropy for correlating local logs inside one request only.
        $randomPart = bin2hex(random_bytes(8));
        $GLOBALS['cms_request_id'] = substr(date('ymdHis') . $randomPart, 0, 26);
    }
    return (string) $GLOBALS['cms_request_id'];
}

/**
 * Return whether the current request should be excluded from public telemetry.
 *
 * @return bool True when the condition matches.
 */
function telemetry_request_excluded(): bool
{
    if (!telemetry_public_usage_enabled() || !telemetry_schema_ready()) {
        return true;
    }
    if (telemetry_setting_enabled('telemetry_respect_dnt', '1') && (string) (request_data('server')['HTTP_DNT'] ?? '') === '1') {
        return true;
    }
    if (telemetry_setting_enabled('telemetry_admin_excluded', '1') && current_user()) {
        return true;
    }
    return false;
}

/**
 * Return a normalized event name or null when the event is not supported.
 *
 * @param mixed $eventName Event name value.
 * @return ?string Text result for the caller.
 */
function telemetry_event_name(mixed $eventName): ?string
{
    if (!is_scalar($eventName)) {
        return null;
    }
    // $eventName stores the candidate event key supplied by the client or server.
    $eventName = (string) $eventName;
    // $allowedEventNames stores the public telemetry allowlist.
    $allowedEventNames = [
        'public.session.started',
        'public.page.viewed',
        'public.gallery.viewed',
        'public.photo.opened',
        'public.photo.closed',
        'public.photo.visible_time',
        'client.performance.web_vital',
        'client.performance.page_load',
        'client.performance.image_decode',
        'client.performance.image_display',
        'client.error.javascript',
        'media.image.served',
        'media.download.served',
        'cache.thumbnail.hit',
        'cache.thumbnail.miss',
        'cache.thumbnail.evicted',
        'cache.lightbox.hit',
        'cache.lightbox.miss',
        'cache.lightbox.evicted',
    ];
    return in_array($eventName, $allowedEventNames, true) ? $eventName : null;
}

/**
 * Return the aggregate metric name used for one raw event.
 *
 * @param string $eventName Event name value.
 * @param array $event Browser or application event.
 * @return ?string Text result for the caller.
 */
function telemetry_metric_name_for_event(string $eventName, array $event): ?string
{
    if ($eventName === 'public.session.started') {
        return 'public.sessions';
    }
    if ($eventName === 'public.page.viewed' || $eventName === 'public.gallery.viewed') {
        return 'public.page_views';
    }
    if ($eventName === 'public.photo.opened') {
        return 'photo.views';
    }
    if ($eventName === 'public.photo.visible_time') {
        return 'photo.view_seconds';
    }
    if ($eventName === 'client.performance.web_vital') {
        // $metric stores the web vital metric name sent by the client.
        $metric = strtolower((string) ($event['metric'] ?? 'unknown'));
        return in_array($metric, ['lcp', 'cls', 'inp', 'fcp', 'ttfb'], true) ? 'web_vital.' . $metric : null;
    }
    if (str_starts_with($eventName, 'client.performance.image_')) {
        return str_replace('client.performance.', 'client.', $eventName) . '_ms';
    }
    if ($eventName === 'client.error.javascript') {
        return 'client.errors';
    }
    if (str_starts_with($eventName, 'media.')) {
        return str_replace('served', 'bytes', $eventName);
    }
    if (str_starts_with($eventName, 'cache.')) {
        return $eventName;
    }
    return null;
}

/**
 * Record one telemetry event after strict normalization.
 *
 * @param array $event Browser or application event.
 */
function telemetry_record_event(array $event): void
{
    if (telemetry_request_excluded()) {
        return;
    }
    // $eventName stores the normalized event name from the allowlist.
    $eventName = telemetry_event_name($event['event_name'] ?? null);
    if ($eventName === null) {
        return;
    }
    // $sessionHash stores the anonymized browser session hash.
    $sessionHash = telemetry_session_hash(isset($event['session_id']) ? (string) $event['session_id'] : null);
    // $durationMs stores capped visible-time style duration.
    $durationMs = isset($event['duration_ms']) ? min(max(0, (int) $event['duration_ms']), telemetry_max_photo_view_ms()) : null;
    // $browserFamily stores the normalized browser family bucket.
    $browserFamily = telemetry_enum($event['browser_family'] ?? 'unknown', ['chrome', 'edge', 'firefox', 'safari', 'opera', 'other', 'unknown'], 'unknown');
    // $osFamily stores the normalized operating system family bucket.
    $osFamily = telemetry_enum($event['os_family'] ?? 'unknown', ['windows', 'macos', 'ios', 'android', 'linux', 'chromeos', 'other', 'unknown'], 'unknown');
    // $deviceType stores the normalized device type bucket.
    $deviceType = telemetry_enum($event['device_type'] ?? 'unknown', ['desktop', 'tablet', 'phone', 'bot', 'unknown'], 'unknown');
    // $viewportClass stores a coarse viewport width bucket.
    $viewportClass = telemetry_viewport_class(isset($event['viewport_width']) ? (int) $event['viewport_width'] : null);
    // $galleryId stores a nullable local gallery identifier.
    $galleryId = telemetry_nullable_positive_int($event['gallery_id'] ?? null);
    // $imageId stores a nullable local image identifier.
    $imageId = telemetry_nullable_positive_int($event['image_id'] ?? null);
    // $referrerCategory stores a normalized referrer category.
    $referrerCategory = telemetry_enum($event['referrer_category'] ?? telemetry_referrer_category(request_data('server')['HTTP_REFERER'] ?? null), ['direct', 'internal', 'search', 'social', 'external', 'unknown'], 'unknown');

    try {
        telemetry_model_insert_event([
            telemetry_datetime_from_event($event['occurred_at'] ?? null),
            now_sql(),
            $eventName,
            telemetry_enum($event['source'] ?? 'client', ['client', 'server', 'job'], 'client'),
            $sessionHash,
            telemetry_request_id(),
            telemetry_short_identifier($event['route_name'] ?? (request_data('query')['page'] ?? 'unknown'), 80),
            telemetry_enum($event['page_kind'] ?? 'unknown', ['home', 'gallery', 'subgallery', 'photo', 'media', 'admin', 'download', 'api', 'other', 'unknown'], 'unknown'),
            $galleryId,
            $imageId,
            $referrerCategory,
            $browserFamily,
            telemetry_nullable_positive_int($event['browser_major_bucket'] ?? null),
            $osFamily,
            $deviceType,
            $viewportClass,
            telemetry_locale_bucket(isset($event['locale']) ? (string) $event['locale'] : null),
            telemetry_setting_enabled('telemetry_geo_enabled') ? telemetry_short_identifier($event['country_code'] ?? null, 2) : null,
            $durationMs,
            telemetry_nullable_positive_int($event['value_count'] ?? null),
            telemetry_nullable_positive_int($event['value_bytes'] ?? null),
            telemetry_nullable_positive_int($event['value_ms'] ?? null),
            telemetry_value_bucket($event['value_bucket'] ?? null),
            telemetry_enum($event['cache_result'] ?? 'unknown', ['hit', 'miss', 'bypass', 'stale', 'evicted', 'discarded', 'unknown'], 'unknown'),
            telemetry_enum($event['media_variant'] ?? 'unknown', ['original', 'thumb_300', 'thumb_600', 'thumb_800', 'thumb_960', 'thumb_1200', 'thumb_1280', 'thumb_1600', 'webp', 'jpg', 'unknown'], 'unknown'),
            telemetry_nullable_positive_int($event['http_status'] ?? null),
            telemetry_error_kind($event['error_kind'] ?? null),
            telemetry_sample_rate($event['sampled_rate'] ?? 1),
            telemetry_context_json($eventName, $event['context'] ?? []),
        ]);
        telemetry_touch_session($sessionHash, $eventName, $event, $galleryId, $imageId, $referrerCategory, $browserFamily, $osFamily, $deviceType, $viewportClass);
        telemetry_record_hourly_metric($eventName, $event, $galleryId, $imageId, $referrerCategory, $browserFamily, $osFamily, $deviceType, $viewportClass, $durationMs);
    } catch (Throwable $exception) {
        admin_log_event('warning', 'telemetry.ingest_failed', 'Anonymous telemetry event could not be stored.', [
            'event_name' => $eventName,
            'error' => $exception->getMessage(),
        ], [
            'category' => 'telemetry',
            'severity' => 'warning',
            'route_name' => 'telemetry_ingest',
        ]);
    }
}

/**
 * Create or update one anonymous session summary row.
 *
 * @param ?string $sessionHash Session hash value.
 * @param string $eventName Event name value.
 * @param array $event Browser or application event.
 * @param ?int $galleryId Gallery identifier.
 * @param ?int $imageId Image identifier.
 * @param string $referrerCategory Referrer category value.
 * @param string $browserFamily Browser family value.
 * @param string $osFamily Os family value.
 * @param string $deviceType Device type value.
 * @param string $viewportClass Viewport class value.
 */
function telemetry_touch_session(?string $sessionHash, string $eventName, array $event, ?int $galleryId, ?int $imageId, string $referrerCategory, string $browserFamily, string $osFamily, string $deviceType, string $viewportClass): void
{
    if ($sessionHash === null) {
        return;
    }
    // $routeName stores the normalized current route.
    $routeName = telemetry_short_identifier($event['route_name'] ?? (request_data('query')['page'] ?? 'unknown'), 80);
    // $pageIncrement stores whether this event should count as a page view.
    $pageIncrement = in_array($eventName, ['public.session.started', 'public.page.viewed', 'public.gallery.viewed'], true) ? 1 : 0;
    // $photoIncrement stores whether this event should count as a photo view.
    $photoIncrement = $eventName === 'public.photo.opened' ? 1 : 0;
    // $durationSeconds stores capped visible seconds for session totals.
    $durationSeconds = $eventName === 'public.photo.visible_time' ? (int) floor(min(max(0, (int) ($event['duration_ms'] ?? 0)), telemetry_max_photo_view_ms()) / 1000) : 0;
    $now = now_sql();
    telemetry_model_upsert_session([
        $sessionHash,
        $now,
        $now,
        $routeName,
        $routeName,
        $galleryId,
        $galleryId,
        $imageId,
        $imageId,
        $referrerCategory,
        $browserFamily,
        telemetry_nullable_positive_int($event['browser_major_bucket'] ?? null),
        $osFamily,
        $deviceType,
        $viewportClass,
        telemetry_locale_bucket(isset($event['locale']) ? (string) $event['locale'] : null),
        telemetry_setting_enabled('telemetry_geo_enabled') ? telemetry_short_identifier($event['country_code'] ?? null, 2) : null,
        $pageIncrement,
        $photoIncrement,
        $durationSeconds,
        1,
        $routeName,
        $now,
        $now,
    ]);
}

/**
 * Record one immediate hourly metric for dashboard responsiveness.
 *
 * @param string $eventName Event name value.
 * @param array $event Browser or application event.
 * @param ?int $galleryId Gallery identifier.
 * @param ?int $imageId Image identifier.
 * @param string $referrerCategory Referrer category value.
 * @param string $browserFamily Browser family value.
 * @param string $osFamily Os family value.
 * @param string $deviceType Device type value.
 * @param string $viewportClass Viewport class value.
 * @param ?int $durationMs Duration ms value.
 */
function telemetry_record_hourly_metric(string $eventName, array $event, ?int $galleryId, ?int $imageId, string $referrerCategory, string $browserFamily, string $osFamily, string $deviceType, string $viewportClass, ?int $durationMs): void
{
    // $metricName stores the aggregate metric name derived from the event.
    $metricName = telemetry_metric_name_for_event($eventName, $event);
    if ($metricName === null) {
        return;
    }
    // $value stores the numeric value to aggregate.
    $value = 1.0;
    if ($eventName === 'public.photo.visible_time' && $durationMs !== null) {
        $value = round($durationMs / 1000, 4);
    } elseif (str_contains($metricName, '_ms') || str_starts_with($metricName, 'web_vital.')) {
        $value = (float) ($event['value_ms'] ?? $event['value'] ?? 0);
    } elseif (str_starts_with($metricName, 'media.')) {
        $value = (float) ($event['value_bytes'] ?? 0);
    }
    // $bucketStart stores the current hour boundary for aggregate writes.
    $bucketStart = date('Y-m-d H:00:00');
    telemetry_model_upsert_hourly_metric([
        $bucketStart,
        $metricName,
        telemetry_short_identifier($event['route_name'] ?? (request_data('query')['page'] ?? ''), 80) ?? '',
        telemetry_enum($event['page_kind'] ?? 'unknown', ['home', 'gallery', 'subgallery', 'photo', 'media', 'admin', 'download', 'api', 'other', 'unknown'], 'unknown'),
        $galleryId ?? 0,
        $imageId ?? 0,
        $browserFamily,
        $osFamily,
        $deviceType,
        $viewportClass,
        '',
        $referrerCategory,
        telemetry_enum($event['media_variant'] ?? 'unknown', ['original', 'thumb_300', 'thumb_600', 'thumb_800', 'thumb_960', 'thumb_1200', 'thumb_1280', 'thumb_1600', 'webp', 'jpg', 'unknown'], 'unknown'),
        telemetry_enum($event['cache_result'] ?? 'unknown', ['hit', 'miss', 'bypass', 'stale', 'evicted', 'discarded', 'unknown'], 'unknown'),
        1,
        1,
        $value,
        $value,
        $value,
        now_sql(),
    ]);
}


/**
 * Record one served public media response for anonymous telemetry.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param string $eventName Event name value.
 * @param int $bytes Bytes value.
 * @param string $mediaVariant Media variant value.
 * @param string $cacheResult Cache result value.
 */
function telemetry_record_media_served_event(
    array $image,
    array $gallery,
    string $eventName,
    int $bytes,
    string $mediaVariant,
    string $cacheResult = 'miss',
    string $routeName = 'media',
    ?string $referrer = null,
    int $httpStatus = 200
): void
{
    if ($bytes <= 0 || telemetry_request_excluded()) {
        return;
    }
    telemetry_record_event([
        'event_name' => $eventName,
        'source' => 'server',
        'occurred_at' => gmdate('c'),
        'route_name' => $routeName !== '' ? $routeName : 'media',
        'page_kind' => 'media',
        'gallery_id' => (int) $gallery['id'],
        'image_id' => (int) $image['id'],
        'referrer_category' => telemetry_referrer_category($referrer),
        'value_bytes' => $bytes,
        'media_variant' => $mediaVariant,
        'cache_result' => $cacheResult,
        'http_status' => max(100, min(599, $httpStatus)),
    ]);
}

/**
 * Format one byte count using 1024-based units.
 *
 * The telemetry dashboard uses binary units so large media totals stay readable
 * without implying decimal SI scaling.
 *
 * @param int|float $bytes Bytes value.
 * @param int $precision Precision value.
 * @return string Text result for the caller.
 */
function telemetry_format_bytes(int|float $bytes, int $precision = 1): string
{
    return \Gallery\Core\format_bytes($bytes, $precision);
}

/**
 * Return public telemetry bootstrap config for the rendered page.
 *
 * @param array $context Context value.
 * @return array Structured result data for the caller.
 */
function telemetry_public_config(array $context = []): array
{
    if (telemetry_request_excluded()) {
        return ['enabled' => false];
    }
    return [
        'enabled' => true,
        'endpoint' => url_for('usage_collect'),
        'sampleRate' => (float) telemetry_setting('telemetry_client_sample_rate', '1.0'),
        'performanceSampleRate' => (float) telemetry_setting('telemetry_performance_sample_rate', '0.25'),
        'maxPhotoViewSeconds' => (int) telemetry_setting('telemetry_max_photo_view_seconds', '900'),
        'respectDnt' => telemetry_setting_enabled('telemetry_respect_dnt', '1'),
        'routeName' => telemetry_short_identifier($context['route_name'] ?? (request_data('query')['page'] ?? 'unknown'), 80) ?? 'unknown',
        'pageKind' => telemetry_enum($context['page_kind'] ?? 'unknown', ['home', 'gallery', 'subgallery', 'photo', 'media', 'admin', 'download', 'api', 'other', 'unknown'], 'unknown'),
        'galleryId' => telemetry_nullable_positive_int($context['gallery_id'] ?? null),
        'imageId' => telemetry_nullable_positive_int($context['image_id'] ?? null),
        'referrerCategory' => telemetry_referrer_category(request_data('server')['HTTP_REFERER'] ?? null),
    ];
}

/**
 * Append the public telemetry script config to the current page footer.
 *
 * @param array $context Context value.
 */
function telemetry_append_public_script(array $context = []): void
{
    // $config stores the minimized browser-side telemetry configuration.
    $config = telemetry_public_config($context);
    if (empty($config['enabled'])) {
        return;
    }
    append_cms_footer_script('window.PHPGalleryTelemetry = ' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';');
    // $scriptPath stores the anonymous usage asset path used for cache busting.
    $scriptPath = dirname(__DIR__, 2) . '/public/assets/usage.js';
    append_cms_footer_html('<script src="' . e(asset_url('assets/usage.js')) . '?v=' . (is_file($scriptPath) ? filemtime($scriptPath) : time()) . '"></script>');
}

/**
 * Admin telemetry reporting query helpers.
 *
 * These functions keep anonymous telemetry report reads in the service layer so
 * controllers can focus on request handling and HTML response composition.
 */

/**
 * Return one aggregate metric sum from hourly metrics.
 *
 * @param string $metricName Metric name value.
 * @param int $days Days value.
 * @return float Numeric result for the caller.
 */
function telemetry_metric_sum(string $metricName, int $days = 30): float
{
    if (!telemetry_schema_ready()) {
        return 0.0;
    }
    return telemetry_model_metric_sum($metricName, $days);
}

/**
 * Return one aggregate event count from hourly metrics.
 *
 * @param string $metricName Metric name value.
 * @param int $days Days value.
 * @return int Integer result for the caller.
 */
function telemetry_metric_events(string $metricName, int $days = 30): int
{
    if (!telemetry_schema_ready()) {
        return 0;
    }
    return telemetry_model_metric_events($metricName, $days);
}

/**
 * Return top viewed photos using hourly aggregates.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_top_photos(int $days = 30, int $limit = 15): array
{
    if (!telemetry_schema_ready()) {
        return [];
    }
    return telemetry_model_top_photos($days, $limit);
}

/**
 * Return longest viewed photos using capped view-time aggregates.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_longest_viewed_photos(int $days = 30, int $limit = 15): array
{
    if (!telemetry_schema_ready()) {
        return [];
    }
    return telemetry_model_longest_viewed_photos($days, $limit);
}

/**
 * Return browser family mix using anonymous session aggregates.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_browser_mix(int $days = 30): array
{
    if (!telemetry_schema_ready()) {
        return [];
    }
    return telemetry_model_browser_mix($days);
}

/**
 * Return cache result distribution from hourly metrics.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_cache_mix(int $days = 30): array
{
    if (!telemetry_schema_ready()) {
        return [];
    }
    return telemetry_model_cache_mix($days);
}

/**
 * Return a bounded integer for report query limits and day windows.
 *
 * @param int $value Value to process.
 * @param int $min Min value.
 * @param int $max Max value.
 * @return int Integer result for the caller.
 */
function telemetry_report_bound_int(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

/**
 * Return the table row count when a telemetry table exists.
 *
 * @param string $tableName Table name value.
 * @return int Integer result for the caller.
 */
function telemetry_report_table_count(string $tableName): int
{
    try {
        return telemetry_model_report_table_count($tableName);
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Return the session quality summary for the report window.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_session_summary(int $days): array
{
    try {
        return telemetry_model_report_session_summary($days);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return daily trend rows for common report metrics.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_daily_trends(int $days): array
{
    try {
        return telemetry_model_report_daily_trends($days);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return top gallery engagement rows for the report window.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_report_top_galleries(int $days, int $limit = 25): array
{
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_top_galleries($days, $limit);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return top route rows for the report window.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_report_top_routes(int $days, int $limit = 25): array
{
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_top_routes($days, $limit);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return a distribution from hourly aggregate dimensions.
 *
 * @param string $dimension Dimension value.
 * @param int $days Days value.
 * @param string $metricName Metric name value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_report_metric_distribution(string $dimension, int $days, string $metricName, int $limit = 20): array
{
    $allowed = ['page_kind', 'browser_family', 'os_family', 'device_type', 'viewport_class', 'referrer_category', 'media_variant', 'cache_result'];
    if (!in_array($dimension, $allowed, true)) {
        return [];
    }
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_metric_distribution($dimension, $days, $metricName, $limit);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return session distribution rows from the session table.
 *
 * @param string $dimension Dimension value.
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_report_session_distribution(string $dimension, int $days, int $limit = 20): array
{
    $allowed = ['entry_referrer_category', 'browser_family', 'os_family', 'device_type', 'viewport_class', 'first_route_name', 'last_route_name', 'exit_route_name'];
    if (!in_array($dimension, $allowed, true)) {
        return [];
    }
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_session_distribution($dimension, $days, $limit);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return web vital and browser performance aggregates.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_performance_metrics(int $days): array
{
    try {
        return telemetry_model_report_performance_metrics($days);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return client error distribution rows.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_report_client_errors(int $days, int $limit = 25): array
{
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_client_errors($days, $limit);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return recent anonymized telemetry events for the access log section.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_report_recent_events(int $days, int $limit = 80): array
{
    $limit = telemetry_report_bound_int($limit, 1, 200);
    try {
        return telemetry_model_report_recent_events($days, $limit);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return database telemetry summary rows.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_report_database_summary(int $days, int $limit = 40): array
{
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_database_summary($days, $limit);
    } catch (Throwable) {
        return [];
    }
}


/**
 * Return total database telemetry counters for the report window.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_database_totals(int $days): array
{
    try {
        return telemetry_model_report_database_totals($days);
    } catch (Throwable) {
        return ['query_count' => 0.0, 'slow_count' => 0.0, 'failed_count' => 0.0];
    }
}

/**
 * Return database fingerprint hot spots.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_report_database_fingerprints(int $days, int $limit = 30): array
{
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_database_fingerprints($days, $limit);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return recent telemetry job runs.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_report_job_runs(int $days, int $limit = 40): array
{
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_job_runs($days, $limit);
    } catch (Throwable) {
        return [];
    }
}
