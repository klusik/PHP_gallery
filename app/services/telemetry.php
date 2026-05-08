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

/**
 * Anonymous telemetry service.
 *
 * Public telemetry is local-only, disabled by default, and stores only normalized
 * technical buckets. The service rejects unknown events and never stores raw IP
 * addresses, raw user-agent strings, raw referrer URLs, or request bodies.
 */

/**
 * Return whether the core telemetry event table exists.
 */
function telemetry_schema_ready(): bool
{
    try {
        // $stmt stores the metadata lookup result for the telemetry events table.
        $stmt = db()->query("SHOW TABLES LIKE 'telemetry_events'");
        return $stmt && (bool) $stmt->fetch();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Return a stable request id for this PHP request.
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
 */
function telemetry_request_excluded(): bool
{
    if (!telemetry_public_usage_enabled() || !telemetry_schema_ready()) {
        return true;
    }
    if (telemetry_setting_enabled('telemetry_respect_dnt', '1') && (string) ($_SERVER['HTTP_DNT'] ?? '') === '1') {
        return true;
    }
    if (telemetry_setting_enabled('telemetry_admin_excluded', '1') && current_user()) {
        return true;
    }
    return false;
}

/**
 * Return a normalized event name or null when the event is not supported.
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
 * Return the aggregate metric name used for one raw event.
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
    $referrerCategory = telemetry_enum($event['referrer_category'] ?? telemetry_referrer_category($_SERVER['HTTP_REFERER'] ?? null), ['direct', 'internal', 'search', 'social', 'external', 'unknown'], 'unknown');

    try {
        // $stmt stores the raw short-retention event insert query.
        $stmt = db()->prepare('INSERT INTO telemetry_events (
            occurred_at, received_at, event_name, source, session_hash, request_id, route_name, page_kind,
            gallery_id, image_id, referrer_category, browser_family, browser_major_bucket, os_family, device_type,
            viewport_class, locale_bucket, country_code, duration_ms_capped, value_count, value_bytes, value_ms,
            value_bucket, cache_result, media_variant, http_status, error_kind, sampled_rate, context_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            telemetry_datetime_from_event($event['occurred_at'] ?? null),
            now_sql(),
            $eventName,
            telemetry_enum($event['source'] ?? 'client', ['client', 'server', 'job'], 'client'),
            $sessionHash,
            telemetry_request_id(),
            telemetry_short_identifier($event['route_name'] ?? ($_GET['page'] ?? 'unknown'), 80),
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
 */
function telemetry_touch_session(?string $sessionHash, string $eventName, array $event, ?int $galleryId, ?int $imageId, string $referrerCategory, string $browserFamily, string $osFamily, string $deviceType, string $viewportClass): void
{
    if ($sessionHash === null) {
        return;
    }
    // $routeName stores the normalized current route.
    $routeName = telemetry_short_identifier($event['route_name'] ?? ($_GET['page'] ?? 'unknown'), 80);
    // $pageIncrement stores whether this event should count as a page view.
    $pageIncrement = in_array($eventName, ['public.session.started', 'public.page.viewed', 'public.gallery.viewed'], true) ? 1 : 0;
    // $photoIncrement stores whether this event should count as a photo view.
    $photoIncrement = $eventName === 'public.photo.opened' ? 1 : 0;
    // $durationSeconds stores capped visible seconds for session totals.
    $durationSeconds = $eventName === 'public.photo.visible_time' ? (int) floor(min(max(0, (int) ($event['duration_ms'] ?? 0)), telemetry_max_photo_view_ms()) / 1000) : 0;
    // $stmt stores the anonymous session upsert query.
    $stmt = db()->prepare('INSERT INTO telemetry_sessions (
        session_hash, started_at, last_seen_at, first_route_name, last_route_name, first_gallery_id, last_gallery_id,
        first_image_id, last_image_id, entry_referrer_category, browser_family, browser_major_bucket, os_family,
        device_type, viewport_class, locale_bucket, country_code, page_view_count, photo_view_count,
        duration_seconds_capped, bounced, exit_route_name, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        last_seen_at = VALUES(last_seen_at),
        last_route_name = VALUES(last_route_name),
        last_gallery_id = COALESCE(VALUES(last_gallery_id), last_gallery_id),
        last_image_id = COALESCE(VALUES(last_image_id), last_image_id),
        page_view_count = page_view_count + VALUES(page_view_count),
        photo_view_count = photo_view_count + VALUES(photo_view_count),
        duration_seconds_capped = duration_seconds_capped + VALUES(duration_seconds_capped),
        bounced = IF(page_view_count + VALUES(page_view_count) <= 1, 1, 0),
        exit_route_name = VALUES(exit_route_name),
        updated_at = VALUES(updated_at)');
    $stmt->execute([
        $sessionHash,
        now_sql(),
        now_sql(),
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
        now_sql(),
        now_sql(),
    ]);
}

/**
 * Record one immediate hourly metric for dashboard responsiveness.
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
    // $stmt stores the aggregate metric upsert query.
    $stmt = db()->prepare('INSERT INTO telemetry_hourly_metrics (
        bucket_start, metric_name, route_name, page_kind, gallery_id, image_id, browser_family, os_family,
        device_type, viewport_class, country_code, referrer_category, media_variant, cache_result,
        sample_count, event_count, value_sum, value_min, value_max, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        sample_count = sample_count + VALUES(sample_count),
        event_count = event_count + VALUES(event_count),
        value_sum = value_sum + VALUES(value_sum),
        value_min = IF(value_min IS NULL, VALUES(value_min), LEAST(value_min, VALUES(value_min))),
        value_max = IF(value_max IS NULL, VALUES(value_max), GREATEST(value_max, VALUES(value_max))),
        updated_at = VALUES(updated_at)');
    $stmt->execute([
        $bucketStart,
        $metricName,
        telemetry_short_identifier($event['route_name'] ?? ($_GET['page'] ?? ''), 80) ?? '',
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
 */
function telemetry_record_media_served_event(array $image, array $gallery, string $eventName, int $bytes, string $mediaVariant, string $cacheResult = 'miss'): void
{
    if ($bytes <= 0 || telemetry_request_excluded()) {
        return;
    }
    telemetry_record_event([
        'event_name' => $eventName,
        'source' => 'server',
        'occurred_at' => gmdate('c'),
        'route_name' => $_GET['page'] ?? 'media',
        'page_kind' => 'media',
        'gallery_id' => (int) $gallery['id'],
        'image_id' => (int) $image['id'],
        'referrer_category' => telemetry_referrer_category($_SERVER['HTTP_REFERER'] ?? null),
        'value_bytes' => $bytes,
        'media_variant' => $mediaVariant,
        'cache_result' => $cacheResult,
        'http_status' => http_response_code() ?: 200,
    ]);
}

/**
 * Format one byte count using 1024-based units.
 *
 * The telemetry dashboard uses binary units so large media totals stay readable
 * without implying decimal SI scaling.
 */
function telemetry_format_bytes(int|float $bytes, int $precision = 1): string
{
    $bytes = (float) $bytes;
    $units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    if ($index === 0) {
        return number_format($bytes, 0) . ' ' . $units[$index];
    }
    return number_format($bytes, $precision) . ' ' . $units[$index];
}

/**
 * Return public telemetry bootstrap config for the rendered page.
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
        'routeName' => telemetry_short_identifier($context['route_name'] ?? ($_GET['page'] ?? 'unknown'), 80) ?? 'unknown',
        'pageKind' => telemetry_enum($context['page_kind'] ?? 'unknown', ['home', 'gallery', 'subgallery', 'photo', 'media', 'admin', 'download', 'api', 'other', 'unknown'], 'unknown'),
        'galleryId' => telemetry_nullable_positive_int($context['gallery_id'] ?? null),
        'imageId' => telemetry_nullable_positive_int($context['image_id'] ?? null),
        'referrerCategory' => telemetry_referrer_category($_SERVER['HTTP_REFERER'] ?? null),
    ];
}

/**
 * Append the public telemetry script config to the current page footer.
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
