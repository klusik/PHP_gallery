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
use function Gallery\Models\telemetry_model_report_daily_sessions;
use function Gallery\Models\telemetry_model_report_daily_trends;
use function Gallery\Models\telemetry_model_report_daily_rollup_consistency;
use function Gallery\Models\telemetry_model_report_database_fingerprints;
use function Gallery\Models\telemetry_model_report_database_summary;
use function Gallery\Models\telemetry_model_report_database_totals;
use function Gallery\Models\telemetry_model_report_job_runs;
use function Gallery\Models\telemetry_model_report_metric_distribution;
use function Gallery\Models\telemetry_model_report_gallery_photo_opens_per_session;
use function Gallery\Models\telemetry_model_report_photo_open_session_buckets;
use function Gallery\Models\telemetry_model_report_photo_open_origins;
use function Gallery\Models\telemetry_model_report_photo_open_anomaly_summary;
use function Gallery\Models\telemetry_model_report_performance_metrics;
use function Gallery\Models\telemetry_model_report_query_profile;
use function Gallery\Models\telemetry_model_report_query_plans;
use function Gallery\Models\telemetry_model_reset_report_query_profile;
use function Gallery\Models\telemetry_model_report_recent_events;
use function Gallery\Models\telemetry_model_report_session_distribution;
use function Gallery\Models\telemetry_model_report_session_summary;
use function Gallery\Models\telemetry_model_report_hourly_metric_cardinality;
use function Gallery\Models\telemetry_model_report_storage_diagnostics;
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
        'media.thumbnail.served',
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
 * Return the page-view increment contributed by one telemetry event.
 *
 * Session lifecycle events deliberately do not count as page views. Only the
 * explicit public page/gallery view events advance the session page counter.
 *
 * @param string $eventName Event name value.
 * @return int Either zero or one.
 */
function telemetry_session_page_increment_for_event(string $eventName): int
{
    return in_array($eventName, ['public.page.viewed', 'public.gallery.viewed'], true) ? 1 : 0;
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
    if ($eventName === 'client.performance.page_load') {
        return 'client.page_load_ms';
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
 * @param array<string,mixed> $event Browser or application event.
 * @return void No return value; effects are recorded in the owned state.
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
    if ($eventName === 'client.performance.page_load'
        && (!is_numeric($event['value_ms'] ?? null) || !is_finite((float) $event['value_ms']) || (float) $event['value_ms'] <= 0)) {
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

    telemetry_ensure_current_semantics_marker();

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
    $pageIncrement = telemetry_session_page_increment_for_event($eventName);
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
 * Normalize hourly aggregate dimensions to those that are meaningful for one metric.
 *
 * The hourly table has one wide composite key shared by unrelated metric families.
 * Persisting every request dimension for every metric creates high-cardinality rows
 * without improving the reports that consume them. This helper keeps only dimensions
 * used by the current reporting contract and always preserves device_type so the
 * all/non-bot-classified/bot-classified segment remains available. Unknown future
 * metrics deliberately retain every supplied dimension until they receive an explicit
 * contract here.
 *
 * @param string $metricName Aggregate metric name.
 * @param array<string,mixed> $dimensions Normalized candidate dimensions.
 * @return array<string,mixed> Dimensions safe for the shared hourly aggregate key.
 */
function telemetry_hourly_metric_dimensions(string $metricName, array $dimensions): array
{
    $defaults = [
        'route_name' => '',
        'page_kind' => 'unknown',
        'gallery_id' => 0,
        'image_id' => 0,
        'browser_family' => 'unknown',
        'os_family' => 'unknown',
        'device_type' => 'unknown',
        'viewport_class' => 'unknown',
        'country_code' => '',
        'referrer_category' => 'unknown',
        'media_variant' => 'unknown',
        'cache_result' => 'unknown',
    ];
    $normalized = array_merge($defaults, $dimensions);

    if ($metricName === 'public.sessions' || $metricName === 'public.page_views') {
        $allowed = ['route_name', 'page_kind', 'gallery_id', 'device_type'];
    } elseif ($metricName === 'photo.views' || $metricName === 'photo.view_seconds') {
        $allowed = ['route_name', 'gallery_id', 'image_id', 'device_type'];
    } elseif ($metricName === 'client.errors') {
        $allowed = ['route_name', 'device_type'];
    } elseif (str_starts_with($metricName, 'web_vital.') || in_array($metricName, ['client.page_load_ms', 'client.image_decode_ms', 'client.image_display_ms'], true)) {
        $allowed = ['route_name', 'page_kind', 'device_type'];
    } elseif (str_starts_with($metricName, 'media.')) {
        $allowed = ['route_name', 'gallery_id', 'device_type', 'media_variant'];
    } elseif (str_starts_with($metricName, 'cache.')) {
        $allowed = ['device_type', 'cache_result'];
    } else {
        return $normalized;
    }

    foreach (array_keys($defaults) as $name) {
        if (!in_array($name, $allowed, true)) {
            $normalized[$name] = $defaults[$name];
        }
    }
    return $normalized;
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
    // $dimensions removes dimensions that have no reporting meaning for this metric family.
    $dimensions = telemetry_hourly_metric_dimensions($metricName, [
        'route_name' => telemetry_short_identifier($event['route_name'] ?? (request_data('query')['page'] ?? ''), 80) ?? '',
        'page_kind' => telemetry_enum($event['page_kind'] ?? 'unknown', ['home', 'gallery', 'subgallery', 'photo', 'media', 'admin', 'download', 'api', 'other', 'unknown'], 'unknown'),
        'gallery_id' => $galleryId ?? 0,
        'image_id' => $imageId ?? 0,
        'browser_family' => $browserFamily,
        'os_family' => $osFamily,
        'device_type' => $deviceType,
        'viewport_class' => $viewportClass,
        'country_code' => '',
        'referrer_category' => $referrerCategory,
        'media_variant' => telemetry_enum($event['media_variant'] ?? 'unknown', ['original', 'thumb_300', 'thumb_600', 'thumb_800', 'thumb_960', 'thumb_1200', 'thumb_1280', 'thumb_1600', 'webp', 'jpg', 'unknown'], 'unknown'),
        'cache_result' => telemetry_enum($event['cache_result'] ?? 'unknown', ['hit', 'miss', 'bypass', 'stale', 'evicted', 'discarded', 'unknown'], 'unknown'),
    ]);
    telemetry_model_upsert_hourly_metric([
        $bucketStart,
        $metricName,
        (string) $dimensions['route_name'],
        (string) $dimensions['page_kind'],
        (int) $dimensions['gallery_id'],
        (int) $dimensions['image_id'],
        (string) $dimensions['browser_family'],
        (string) $dimensions['os_family'],
        (string) $dimensions['device_type'],
        (string) $dimensions['viewport_class'],
        (string) $dimensions['country_code'],
        (string) $dimensions['referrer_category'],
        (string) $dimensions['media_variant'],
        (string) $dimensions['cache_result'],
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
 * @param array<string,mixed> $image Image row or image data.
 * @param array<string,mixed> $gallery Gallery row or gallery data.
 * @param string $eventName Event name value.
 * @param int $bytes Bytes value.
 * @param string $mediaVariant Media variant value.
 * @param string $cacheResult Cache result value.
 * @param string $routeName Normalized route owning this response.
 * @param ?string $referrer Transient referrer reduced to a coarse category.
 * @param int $httpStatus HTTP status of the observed response.
 * @return void No return value; effects are recorded in the owned state.
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
    ] + telemetry_user_agent_buckets((string) (request_data('server')['HTTP_USER_AGENT'] ?? '')));
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
        'performanceEnabled' => telemetry_setting_enabled('telemetry_performance_enabled', '1'),
        'cacheEnabled' => telemetry_setting_enabled('telemetry_cache_enabled', '1'),
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
 * Normalize one analytics traffic segment for report queries.
 *
 * The value is intentionally a small semantic enum. Model helpers own the SQL
 * predicate and reject unsupported identifiers.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $value Candidate traffic segment.
 * @return string One of all, non_bot, bot, or unknown.
 */
function telemetry_traffic_segment(mixed $value): string
{
    if (!is_scalar($value)) {
        return 'all';
    }
    $segment = strtolower(trim((string) $value));
    return in_array($segment, ['all', 'non_bot', 'bot', 'unknown'], true) ? $segment : 'all';
}

/**
 * Return one aggregate metric sum from hourly metrics.
 *
 * @param string $metricName Metric name value.
 * @param int $days Days value.
 * @return float Numeric result for the caller.
 */
function telemetry_metric_sum(string $metricName, int $days = 30, string $trafficSegment = 'all'): float
{
    if (!telemetry_schema_ready()) {
        return 0.0;
    }
    return telemetry_model_metric_sum($metricName, $days, telemetry_traffic_segment($trafficSegment));
}

/**
 * Return one aggregate event count from hourly metrics.
 *
 * @param string $metricName Metric name value.
 * @param int $days Days value.
 * @return int Integer result for the caller.
 */
function telemetry_metric_events(string $metricName, int $days = 30, string $trafficSegment = 'all'): int
{
    if (!telemetry_schema_ready()) {
        return 0;
    }
    return telemetry_model_metric_events($metricName, $days, telemetry_traffic_segment($trafficSegment));
}

/**
 * Return top viewed photos using hourly aggregates.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_top_photos(int $days = 30, int $limit = 15, string $trafficSegment = 'all'): array
{
    if (!telemetry_schema_ready()) {
        return [];
    }
    return telemetry_model_top_photos($days, $limit, telemetry_traffic_segment($trafficSegment));
}

/**
 * Return longest viewed photos using capped view-time aggregates.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_longest_viewed_photos(int $days = 30, int $limit = 15, string $trafficSegment = 'all'): array
{
    if (!telemetry_schema_ready()) {
        return [];
    }
    return telemetry_model_longest_viewed_photos($days, $limit, telemetry_traffic_segment($trafficSegment));
}


/**
 * Return a bounded distribution of photo opens per anonymous session.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_photo_open_session_buckets(int $days, string $trafficSegment = 'all'): array
{
    try {
        return telemetry_model_report_photo_open_session_buckets($days, telemetry_traffic_segment($trafficSegment));
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return aggregate diagnostics for unusually high photo-open session counts.
 *
 * @param int $days Days value.
 * @param int $threshold Strict threshold above which a session is diagnostic.
 * @return array<string,mixed> Structured result data for the caller.
 * @param string $trafficSegment Normalized technical traffic segment, not proof of a human visitor.
 */
function telemetry_report_photo_open_anomaly_summary(int $days, int $threshold = 50, string $trafficSegment = 'all'): array
{
    try {
        $row = telemetry_model_report_photo_open_anomaly_summary($days, $threshold, telemetry_traffic_segment($trafficSegment));
        $total = max(0, (int) ($row['photo_opens'] ?? 0));
        $maximum = max(0, (int) ($row['max_opens_per_session'] ?? 0));
        $row['largest_session_share_percent'] = $total > 0 ? 100 * $maximum / $total : 0.0;
        return $row;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return aggregate galleries ranked by retained raw-event opens per session.
 *
 * @param int $days Days value bounded by the raw-event retention window.
 * @param int $minSessions Minimum sessions required before a gallery is shown.
 * @param int $limit Maximum number of galleries.
 * @return array Structured result data for the caller.
 */
function telemetry_report_gallery_photo_opens_per_session(int $days, int $minSessions = 3, int $limit = 20, string $trafficSegment = 'all'): array
{
    try {
        return telemetry_model_report_gallery_photo_opens_per_session($days, $minSessions, telemetry_report_bound_int($limit, 1, 100), telemetry_traffic_segment($trafficSegment));
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return normalized activation-origin counts from retained raw photo-open events.
 *
 * @param int $days Days value bounded by the raw-event retention window.
 * @return list<array{label:string,events:int}> Structured result data for the caller.
 * @param string $trafficSegment Normalized technical traffic segment, not proof of a human visitor.
 * @param ?bool $available Set false on query failure and true only after successful execution.
 */
function telemetry_report_photo_open_origins(int $days, string $trafficSegment = 'all', ?bool &$available = null): array
{
    $available = false;
    try {
        $rows = telemetry_model_report_photo_open_origins($days, telemetry_traffic_segment($trafficSegment));
    } catch (Throwable) {
        return [];
    }
    $available = true;
    $allowed = ['click', 'keyboard', 'swipe', 'slideshow', 'history', 'direct', 'fallback', 'unknown', 'legacy_unclassified'];
    $normalized = [];
    foreach ($rows as $row) {
        $label = (string) ($row['label'] ?? 'legacy_unclassified');
        if (!in_array($label, $allowed, true)) {
            $label = 'unknown';
        }
        if (!isset($normalized[$label])) {
            $normalized[$label] = ['label' => $label, 'events' => 0];
        }
        $normalized[$label]['events'] += (int) ($row['events'] ?? 0);
    }
    usort($normalized, static fn(array $a, array $b): int => ($b['events'] <=> $a['events']) ?: strcmp($a['label'], $b['label']));
    return array_values($normalized);
}

/**
 * Return browser family mix using anonymous session aggregates.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_browser_mix(int $days = 30, string $trafficSegment = 'all'): array
{
    if (!telemetry_schema_ready()) {
        return [];
    }
    return telemetry_model_browser_mix($days, telemetry_traffic_segment($trafficSegment));
}

/**
 * Return cache result distribution from hourly metrics.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_cache_mix(int $days = 30, string $trafficSegment = 'all'): array
{
    if (!telemetry_schema_ready()) {
        return [];
    }
    return telemetry_model_cache_mix($days, telemetry_traffic_segment($trafficSegment));
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
 * Return a bounded operator-facing snapshot of telemetry storage and cardinality.
 *
 * This is diagnostic evidence only. It does not mutate retention, indexes, or
 * table layout. Approximate row growth is derived from the most recent seven-day
 * row count and is intentionally labelled as an estimate rather than a capacity
 * prediction.
 *
 * @param int $reportDays Selected report window in days.
 * @return array<string,mixed> Storage, retention, and hourly metric cardinality diagnostics.
 */
function telemetry_report_storage_diagnostics(int $reportDays = 30): array
{
    $reportDays = telemetry_report_bound_int($reportDays, 1, 3650);
    $recentDays = 7;
    $retentionByTable = [
        'telemetry_events' => telemetry_retention_days('telemetry_raw_retention_days', 7, 1, 90),
        'telemetry_sessions' => telemetry_retention_days('telemetry_session_retention_days', 30, 1, 365),
        'telemetry_hourly_metrics' => telemetry_retention_days('telemetry_hourly_retention_days', 90, 7, 730),
        'telemetry_daily_metrics' => telemetry_retention_days('telemetry_daily_retention_days', 730, 30, 3650),
        'telemetry_db_query_metrics' => telemetry_retention_days('telemetry_hourly_retention_days', 90, 7, 730),
        'telemetry_job_runs' => 180,
    ];
    try {
        $tables = telemetry_model_report_storage_diagnostics($recentDays, $retentionByTable);
        foreach ($tables as &$row) {
            $tableName = (string) ($row['table_name'] ?? '');
            $row['retention_days'] = (int) ($retentionByTable[$tableName] ?? 0);
            $row['approx_rows_per_day'] = ((int) ($row['recent_rows'] ?? 0)) / $recentDays;
        }
        unset($row);

        $hourlyRetentionDays = (int) $retentionByTable['telemetry_hourly_metrics'];
        return [
            'available' => true,
            'recent_days' => $recentDays,
            'report_days' => $reportDays,
            'hourly_retention_days' => $hourlyRetentionDays,
            'daily_retention_days' => (int) $retentionByTable['telemetry_daily_metrics'],
            'aggregate_source_state' => $reportDays <= $hourlyRetentionDays ? 'hourly_within_retention' : 'daily_required_for_full_window',
            'tables' => $tables,
            'hourly_metric_cardinality' => telemetry_model_report_hourly_metric_cardinality($reportDays, 30),
        ];
    } catch (Throwable) {
        return [
            'available' => false,
            'recent_days' => $recentDays,
            'report_days' => $reportDays,
            'hourly_retention_days' => (int) $retentionByTable['telemetry_hourly_metrics'],
            'daily_retention_days' => (int) $retentionByTable['telemetry_daily_metrics'],
            'aggregate_source_state' => 'unavailable',
            'tables' => [],
            'hourly_metric_cardinality' => [],
        ];
    }
}


/**
 * Compare recent completed-day hourly aggregates with persisted daily rollups.
 *
 * This diagnostic validates the semantic prerequisite for a future long-window
 * report switch. It is read-only and does not select the daily table for current
 * reports. The current partial day is excluded so ordinary rollup lag does not
 * create a false mismatch.
 *
 * @param int $completedDays Number of completed days to compare.
 * @param string $trafficSegment Traffic segment selector.
 * @return array<string,mixed> Rollup readiness summary and per-metric comparisons.
 */
function telemetry_report_daily_rollup_consistency(int $completedDays = 7, string $trafficSegment = 'all'): array
{
    $completedDays = telemetry_report_bound_int($completedDays, 1, 30);
    try {
        $sourceRows = telemetry_model_report_daily_rollup_consistency(
            $completedDays,
            telemetry_traffic_segment($trafficSegment)
        );
    } catch (Throwable) {
        return [
            'available' => false,
            'completed_days' => $completedDays,
            'state' => 'unavailable',
            'mismatch_count' => 0,
            'metrics' => [],
        ];
    }

    $hourlyByMetric = [];
    foreach ((array) ($sourceRows['hourly'] ?? []) as $row) {
        $metricName = (string) ($row['metric_name'] ?? '');
        if ($metricName !== '') {
            $hourlyByMetric[$metricName] = $row;
        }
    }
    $dailyByMetric = [];
    foreach ((array) ($sourceRows['daily'] ?? []) as $row) {
        $metricName = (string) ($row['metric_name'] ?? '');
        if ($metricName !== '') {
            $dailyByMetric[$metricName] = $row;
        }
    }

    $metricNames = array_values(array_unique(array_merge(array_keys($hourlyByMetric), array_keys($dailyByMetric))));
    sort($metricNames, SORT_STRING);
    $metrics = [];
    $mismatchCount = 0;
    $sampledMetricCount = 0;
    foreach ($metricNames as $metricName) {
        $hourly = (array) ($hourlyByMetric[$metricName] ?? []);
        $daily = (array) ($dailyByMetric[$metricName] ?? []);
        $hourlySamples = max(0, (int) ($hourly['sample_count'] ?? 0));
        $dailySamples = max(0, (int) ($daily['sample_count'] ?? 0));
        $hourlyEvents = max(0, (int) ($hourly['event_count'] ?? 0));
        $dailyEvents = max(0, (int) ($daily['event_count'] ?? 0));
        $hourlyValue = (float) ($hourly['value_sum'] ?? 0.0);
        $dailyValue = (float) ($daily['value_sum'] ?? 0.0);
        $sampleDifference = $hourlySamples - $dailySamples;
        $eventDifference = $hourlyEvents - $dailyEvents;
        $valueDifference = $hourlyValue - $dailyValue;
        $valueTolerance = max(0.0001, abs($hourlyValue) * 0.000000001);

        if ($hourlySamples === 0 && $dailySamples === 0 && $hourlyEvents === 0 && $dailyEvents === 0 && abs($hourlyValue) <= $valueTolerance && abs($dailyValue) <= $valueTolerance) {
            $status = 'no_samples';
        } elseif (($hourlySamples > 0 || $hourlyEvents > 0 || abs($hourlyValue) > $valueTolerance)
            && $dailySamples === 0 && $dailyEvents === 0 && abs($dailyValue) <= $valueTolerance) {
            $status = 'daily_missing';
            $sampledMetricCount++;
            $mismatchCount++;
        } else {
            $sampledMetricCount++;
            $matches = $sampleDifference === 0
                && $eventDifference === 0
                && abs($valueDifference) <= $valueTolerance;
            $status = $matches ? 'match' : 'mismatch';
            if (!$matches) {
                $mismatchCount++;
            }
        }

        $metrics[] = [
            'metric_name' => $metricName,
            'status' => $status,
            'hourly_samples' => $hourlySamples,
            'daily_samples' => $dailySamples,
            'sample_difference' => $sampleDifference,
            'hourly_events' => $hourlyEvents,
            'daily_events' => $dailyEvents,
            'event_difference' => $eventDifference,
            'hourly_value_sum' => $hourlyValue,
            'daily_value_sum' => $dailyValue,
            'value_difference' => $valueDifference,
        ];
    }

    $state = $sampledMetricCount === 0
        ? 'no_samples'
        : ($mismatchCount === 0 ? 'match' : 'mismatch');

    return [
        'available' => true,
        'completed_days' => $completedDays,
        'state' => $state,
        'mismatch_count' => $mismatchCount,
        'metrics' => $metrics,
    ];
}

/** Reset request-local SQL runtime evidence before building one telemetry export. */
function telemetry_reset_report_query_profile(): void
{
    telemetry_model_reset_report_query_profile();
}

/**
 * Return request-local SQL runtime evidence for telemetry report queries.
 *
 * The model profiler stores timings in memory only. No SQL text, bound values,
 * result data, or database error messages are persisted or returned here.
 *
 * @return array<int,array<string,mixed>> Bounded report-query timing rows.
 */
function telemetry_report_query_profile(): array
{
    try {
        return telemetry_model_report_query_profile();
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return sanitized optimizer plans for fixed telemetry report query families.
 *
 * @param int $days Report window in days.
 * @param string $trafficSegment Normalized traffic segment.
 * @return array<int,array<string,mixed>> Safe EXPLAIN plan rows.
 */
function telemetry_report_query_plans(int $days, string $trafficSegment = 'all'): array
{
    try {
        return telemetry_model_report_query_plans(
            telemetry_report_bound_int($days, 1, 3650),
            telemetry_traffic_segment($trafficSegment)
        );
    } catch (Throwable) {
        return [];
    }
}



/**
 * Return the session quality summary for the report window.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_session_summary(int $days, string $trafficSegment = 'all'): array
{
    try {
        return telemetry_model_report_session_summary($days, telemetry_traffic_segment($trafficSegment));
    } catch (Throwable) {
        return [];
    }
}

/**
 * Merge daily aggregate metrics with canonical unique-session counts.
 *
 * @param array $metricRows Daily aggregate metric rows.
 * @param array $sessionRows Daily canonical session rows.
 * @return array Structured result data for the caller.
 */
function telemetry_merge_daily_trends(array $metricRows, array $sessionRows): array
{
    $byDate = [];
    foreach ($metricRows as $row) {
        $reportDate = (string) ($row['report_date'] ?? '');
        if ($reportDate === '') {
            continue;
        }
        $row['sessions'] = 0;
        $byDate[$reportDate] = $row;
    }
    foreach ($sessionRows as $row) {
        $reportDate = (string) ($row['report_date'] ?? '');
        if ($reportDate === '') {
            continue;
        }
        if (!isset($byDate[$reportDate])) {
            $byDate[$reportDate] = [
                'report_date' => $reportDate,
                'page_views' => 0,
                'photo_views' => 0,
                'photo_seconds' => 0,
                'client_errors' => 0,
                'media_bytes' => 0,
            ];
        }
        $byDate[$reportDate]['sessions'] = (int) ($row['sessions'] ?? 0);
    }
    ksort($byDate, SORT_STRING);
    return array_values($byDate);
}

/**
 * Return daily trend rows for common report metrics.
 *
 * Session counts come from telemetry_sessions, the canonical unique-session
 * source. Event metrics continue to come from hourly aggregates.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_daily_trends(int $days, string $trafficSegment = 'all'): array
{
    $trafficSegment = telemetry_traffic_segment($trafficSegment);
    try {
        return telemetry_merge_daily_trends(
            telemetry_model_report_daily_trends($days, $trafficSegment),
            telemetry_model_report_daily_sessions($days, $trafficSegment)
        );
    } catch (Throwable) {
        return [];
    }
}

/**
 * Return cross-source consistency diagnostics for one telemetry report window.
 *
 * @param int $days Days value.
 * @param array $sessionSummary Canonical session summary.
 * @param array $dailyTrends Daily report rows with canonical sessions.
 * @return array Structured consistency result for presentation.
 */
function telemetry_report_consistency(int $days, array $sessionSummary, array $dailyTrends, string $trafficSegment = 'all'): array
{
    $summarySessions = (int) ($sessionSummary['sessions'] ?? 0);
    $dailySessions = 0;
    $dailyPageViews = 0;
    foreach ($dailyTrends as $row) {
        $dailySessions += (int) ($row['sessions'] ?? 0);
        $dailyPageViews += (int) ($row['page_views'] ?? 0);
    }
    $hourlyPageViews = telemetry_metric_events('public.page_views', $days, telemetry_traffic_segment($trafficSegment));
    $sessionPageViews = (int) ($sessionSummary['page_views'] ?? 0);

    return [
        'session_summary' => $summarySessions,
        'daily_sessions' => $dailySessions,
        'session_difference' => $summarySessions - $dailySessions,
        'hourly_page_views' => $hourlyPageViews,
        'daily_page_views' => $dailyPageViews,
        'page_view_difference' => $hourlyPageViews - $dailyPageViews,
        'session_row_page_views' => $sessionPageViews,
        'session_row_page_view_difference' => $sessionPageViews - $hourlyPageViews,
        'semantics_version' => TELEMETRY_SEMANTICS_VERSION,
        'stored_semantics_version' => telemetry_semantics_version(),
        'semantics_effective_at' => telemetry_semantics_effective_at(),
    ];
}

/**
 * Return top gallery engagement rows for the report window.
 *
 * @param int $days Days value.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function telemetry_report_top_galleries(int $days, int $limit = 25, string $trafficSegment = 'all'): array
{
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_top_galleries($days, $limit, telemetry_traffic_segment($trafficSegment));
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
function telemetry_report_top_routes(int $days, int $limit = 25, string $trafficSegment = 'all'): array
{
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_top_routes($days, $limit, telemetry_traffic_segment($trafficSegment));
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
function telemetry_report_metric_distribution(string $dimension, int $days, string $metricName, int $limit = 20, string $trafficSegment = 'all'): array
{
    $allowed = ['page_kind', 'browser_family', 'os_family', 'device_type', 'viewport_class', 'referrer_category', 'media_variant', 'cache_result'];
    if (!in_array($dimension, $allowed, true)) {
        return [];
    }
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_metric_distribution($dimension, $days, $metricName, $limit, telemetry_traffic_segment($trafficSegment));
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
function telemetry_report_session_distribution(string $dimension, int $days, int $limit = 20, string $trafficSegment = 'all'): array
{
    $allowed = ['entry_referrer_category', 'browser_family', 'os_family', 'device_type', 'viewport_class', 'first_route_name', 'last_route_name', 'exit_route_name'];
    if (!in_array($dimension, $allowed, true)) {
        return [];
    }
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_session_distribution($dimension, $days, $limit, telemetry_traffic_segment($trafficSegment));
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
function telemetry_report_performance_metrics(int $days, string $trafficSegment = 'all'): array
{
    try {
        return telemetry_model_report_performance_metrics($days, telemetry_traffic_segment($trafficSegment));
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
function telemetry_report_client_errors(int $days, int $limit = 25, string $trafficSegment = 'all'): array
{
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_client_errors($days, $limit, telemetry_traffic_segment($trafficSegment));
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
function telemetry_report_recent_events(int $days, int $limit = 80, string $trafficSegment = 'all'): array
{
    $limit = telemetry_report_bound_int($limit, 1, 200);
    try {
        return telemetry_model_report_recent_events($days, $limit, telemetry_traffic_segment($trafficSegment));
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
 * @return array<string,mixed> Structured result data for the caller.
 * @param string $ranking One of slow, volume or failed; invalid values use the slow ordering.
 */
function telemetry_report_database_fingerprints(int $days, int $limit = 30, string $ranking = 'slow'): array
{
    $limit = telemetry_report_bound_int($limit, 1, 100);
    try {
        return telemetry_model_report_database_fingerprints($days, $limit, $ranking);
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
