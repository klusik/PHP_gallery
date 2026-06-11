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
 *
 * @return bool True when the condition matches.
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
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param string $eventName Event name value.
 * @param int $bytes Bytes value.
 * @param string $mediaVariant Media variant value.
 * @param string $cacheResult Cache result value.
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
 *
 * @param int|float $bytes Bytes value.
 * @param int $precision Precision value.
 * @return string Text result for the caller.
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
        'routeName' => telemetry_short_identifier($context['route_name'] ?? ($_GET['page'] ?? 'unknown'), 80) ?? 'unknown',
        'pageKind' => telemetry_enum($context['page_kind'] ?? 'unknown', ['home', 'gallery', 'subgallery', 'photo', 'media', 'admin', 'download', 'api', 'other', 'unknown'], 'unknown'),
        'galleryId' => telemetry_nullable_positive_int($context['gallery_id'] ?? null),
        'imageId' => telemetry_nullable_positive_int($context['image_id'] ?? null),
        'referrerCategory' => telemetry_referrer_category($_SERVER['HTTP_REFERER'] ?? null),
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
    if (!telemetry_settings_schema_ready()) {
        return 0.0;
    }
    // $stmt stores the aggregate read query for one metric name.
    $stmt = db()->prepare('SELECT COALESCE(SUM(value_sum), 0) FROM telemetry_hourly_metrics WHERE metric_name = ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$metricName, $days]);
    return (float) $stmt->fetchColumn();
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
    if (!telemetry_settings_schema_ready()) {
        return 0;
    }
    // $stmt stores the aggregate count query for one metric name.
    $stmt = db()->prepare('SELECT COALESCE(SUM(event_count), 0) FROM telemetry_hourly_metrics WHERE metric_name = ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$metricName, $days]);
    return (int) $stmt->fetchColumn();
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
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $stmt stores the top photo view query.
    $stmt = db()->prepare('SELECT i.id, i.filename, g.title AS gallery_title, SUM(m.event_count) AS photo_views
        FROM telemetry_hourly_metrics m
        JOIN images i ON i.id = m.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE m.metric_name = ? AND m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.image_id > 0
        GROUP BY i.id, i.filename, g.title
        ORDER BY photo_views DESC
        LIMIT ' . max(1, min(50, $limit)));
    $stmt->execute(['photo.views', $days]);
    return $stmt->fetchAll();
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
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $stmt stores the average capped view-time query.
    $stmt = db()->prepare('SELECT i.id, i.filename, g.title AS gallery_title, SUM(m.value_sum) / NULLIF(SUM(m.event_count), 0) AS avg_view_seconds, SUM(m.event_count) AS view_count
        FROM telemetry_hourly_metrics m
        JOIN images i ON i.id = m.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE m.metric_name = ? AND m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.image_id > 0
        GROUP BY i.id, i.filename, g.title
        HAVING view_count > 0
        ORDER BY avg_view_seconds DESC
        LIMIT ' . max(1, min(50, $limit)));
    $stmt->execute(['photo.view_seconds', $days]);
    return $stmt->fetchAll();
}

/**
 * Return browser family mix using anonymous session aggregates.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_browser_mix(int $days = 30): array
{
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $stmt stores the browser mix query.
    $stmt = db()->prepare('SELECT browser_family, COUNT(*) AS sessions FROM telemetry_sessions WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY browser_family ORDER BY sessions DESC');
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return cache result distribution from hourly metrics.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_cache_mix(int $days = 30): array
{
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $stmt stores the cache mix query.
    $stmt = db()->prepare('SELECT cache_result, SUM(event_count) AS events FROM telemetry_hourly_metrics WHERE metric_name LIKE ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY cache_result ORDER BY events DESC');
    $stmt->execute(['cache.%', $days]);
    return $stmt->fetchAll();
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
    $allowedTables = [
        'telemetry_events',
        'telemetry_sessions',
        'telemetry_hourly_metrics',
        'telemetry_daily_metrics',
        'telemetry_db_query_metrics',
        'telemetry_job_runs',
    ];
    if (!in_array($tableName, $allowedTables, true)) {
        return 0;
    }
    try {
        $stmt = db()->query('SELECT COUNT(*) FROM ' . $tableName);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Return a single scalar value from a parameterized telemetry report query.
 *
 * @param string $sql Sql value.
 * @param array $params Params value.
 * @return float Numeric result for the caller.
 */
function telemetry_report_scalar(string $sql, array $params = []): float
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0.0;
    }
}

/**
 * Return rows from a parameterized telemetry report query.
 *
 * @param string $sql Sql value.
 * @param array $params Params value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_rows(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
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
function telemetry_report_session_summary(int $days): array
{
    return telemetry_report_rows('SELECT
        COUNT(*) AS sessions,
        COALESCE(SUM(page_view_count), 0) AS page_views,
        COALESCE(SUM(photo_view_count), 0) AS photo_views,
        COALESCE(SUM(duration_seconds_capped), 0) AS duration_seconds,
        COALESCE(AVG(page_view_count), 0) AS avg_pages_per_session,
        COALESCE(AVG(photo_view_count), 0) AS avg_photos_per_session,
        COALESCE(AVG(duration_seconds_capped), 0) AS avg_duration_seconds,
        COALESCE(SUM(CASE WHEN page_view_count <= 1 THEN 1 ELSE 0 END), 0) AS bounced_sessions,
        COALESCE(SUM(CASE WHEN started_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS previous_sessions,
        COALESCE(SUM(CASE WHEN started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS recent_sessions
        FROM telemetry_sessions
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days])[0] ?? [];
}

/**
 * Return daily trend rows for common report metrics.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_daily_trends(int $days): array
{
    return telemetry_report_rows('SELECT DATE(bucket_start) AS report_date,
        SUM(CASE WHEN metric_name = \'public.sessions\' THEN event_count ELSE 0 END) AS sessions,
        SUM(CASE WHEN metric_name = \'public.page_views\' THEN event_count ELSE 0 END) AS page_views,
        SUM(CASE WHEN metric_name = \'photo.views\' THEN event_count ELSE 0 END) AS photo_views,
        SUM(CASE WHEN metric_name = \'photo.view_seconds\' THEN value_sum ELSE 0 END) AS photo_seconds,
        SUM(CASE WHEN metric_name = \'client.errors\' THEN event_count ELSE 0 END) AS client_errors,
        SUM(CASE WHEN metric_name IN (\'media.image.bytes\', \'media.thumbnail.bytes\', \'media.download.bytes\') THEN value_sum ELSE 0 END) AS media_bytes
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(bucket_start)
        ORDER BY report_date ASC', [$days]);
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
    return telemetry_report_rows('SELECT g.id, g.title, g.slug,
        SUM(CASE WHEN m.metric_name = \'public.page_views\' THEN m.event_count ELSE 0 END) AS page_views,
        SUM(CASE WHEN m.metric_name = \'photo.views\' THEN m.event_count ELSE 0 END) AS photo_views,
        SUM(CASE WHEN m.metric_name = \'photo.view_seconds\' THEN m.value_sum ELSE 0 END) AS photo_seconds,
        SUM(CASE WHEN m.metric_name IN (\'media.image.bytes\', \'media.thumbnail.bytes\', \'media.download.bytes\') THEN m.value_sum ELSE 0 END) AS media_bytes
        FROM telemetry_hourly_metrics m
        JOIN galleries g ON g.id = m.gallery_id
        WHERE m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.gallery_id > 0
        GROUP BY g.id, g.title, g.slug
        ORDER BY page_views DESC, photo_views DESC, media_bytes DESC
        LIMIT ' . $limit, [$days]);
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
    return telemetry_report_rows('SELECT route_name,
        SUM(CASE WHEN metric_name = \'public.page_views\' THEN event_count ELSE 0 END) AS page_views,
        SUM(CASE WHEN metric_name = \'photo.views\' THEN event_count ELSE 0 END) AS photo_views,
        SUM(CASE WHEN metric_name = \'client.errors\' THEN event_count ELSE 0 END) AS client_errors,
        SUM(CASE WHEN metric_name IN (\'media.image.bytes\', \'media.thumbnail.bytes\', \'media.download.bytes\') THEN value_sum ELSE 0 END) AS media_bytes
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND route_name <> \'\'
        GROUP BY route_name
        ORDER BY page_views DESC, photo_views DESC, client_errors DESC
        LIMIT ' . $limit, [$days]);
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
    return telemetry_report_rows('SELECT ' . $dimension . ' AS label, SUM(event_count) AS events, SUM(value_sum) AS value_sum
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND metric_name = ?
        GROUP BY ' . $dimension . '
        ORDER BY events DESC, value_sum DESC
        LIMIT ' . $limit, [$days, $metricName]);
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
    return telemetry_report_rows('SELECT COALESCE(NULLIF(' . $dimension . ', \'\'), \'unknown\') AS label,
        COUNT(*) AS sessions,
        COALESCE(SUM(page_view_count), 0) AS page_views,
        COALESCE(SUM(photo_view_count), 0) AS photo_views,
        COALESCE(AVG(duration_seconds_capped), 0) AS avg_duration_seconds
        FROM telemetry_sessions
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY COALESCE(NULLIF(' . $dimension . ', \'\'), \'unknown\')
        ORDER BY sessions DESC
        LIMIT ' . $limit, [$days]);
}

/**
 * Return web vital and browser performance aggregates.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_performance_metrics(int $days): array
{
    return telemetry_report_rows('SELECT metric_name,
        SUM(event_count) AS samples,
        SUM(value_sum) / NULLIF(SUM(event_count), 0) AS avg_value,
        MIN(value_min) AS min_value,
        MAX(value_max) AS max_value
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND (metric_name LIKE \'web_vital.%\' OR metric_name IN (\'client.image_decode_ms\', \'client.image_display_ms\'))
        GROUP BY metric_name
        ORDER BY metric_name ASC', [$days]);
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
    return telemetry_report_rows('SELECT COALESCE(NULLIF(error_kind, \'\'), \'unknown\') AS error_kind,
        COALESCE(NULLIF(route_name, \'\'), \'unknown\') AS route_name,
        COUNT(*) AS events,
        MAX(occurred_at) AS last_seen
        FROM telemetry_events
        WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND event_name = \'client.error.javascript\'
        GROUP BY COALESCE(NULLIF(error_kind, \'\'), \'unknown\'), COALESCE(NULLIF(route_name, \'\'), \'unknown\')
        ORDER BY events DESC, last_seen DESC
        LIMIT ' . $limit, [$days]);
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
    return telemetry_report_rows('SELECT occurred_at, event_name, source, route_name, page_kind, gallery_id, image_id,
        referrer_category, browser_family, os_family, device_type, viewport_class, media_variant,
        cache_result, http_status, error_kind, value_bytes, value_ms, duration_ms_capped
        FROM telemetry_events
        WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY occurred_at DESC
        LIMIT ' . $limit, [$days]);
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
    return telemetry_report_rows('SELECT route_name, operation, table_name,
        SUM(query_count) AS query_count,
        SUM(failed_count) AS failed_count,
        SUM(slow_count) AS slow_count,
        SUM(latency_ms_sum) AS latency_ms_sum,
        MAX(latency_ms_max) AS latency_ms_max,
        SUM(rows_returned_sum) AS rows_returned_sum,
        SUM(rows_affected_sum) AS rows_affected_sum
        FROM telemetry_db_query_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY route_name, operation, table_name
        ORDER BY latency_ms_sum DESC, query_count DESC
        LIMIT ' . $limit, [$days]);
}


/**
 * Return total database telemetry counters for the report window.
 *
 * @param int $days Days value.
 * @return array Structured result data for the caller.
 */
function telemetry_report_database_totals(int $days): array
{
    return [
        'query_count' => telemetry_report_scalar('SELECT COALESCE(SUM(query_count), 0) FROM telemetry_db_query_metrics WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]),
        'slow_count' => telemetry_report_scalar('SELECT COALESCE(SUM(slow_count), 0) FROM telemetry_db_query_metrics WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]),
        'failed_count' => telemetry_report_scalar('SELECT COALESCE(SUM(failed_count), 0) FROM telemetry_db_query_metrics WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]),
    ];
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
    return telemetry_report_rows('SELECT query_fingerprint, route_name, operation, table_name,
        SUM(query_count) AS query_count,
        SUM(failed_count) AS failed_count,
        SUM(slow_count) AS slow_count,
        SUM(latency_ms_sum) / NULLIF(SUM(query_count), 0) AS avg_latency_ms,
        MAX(latency_ms_max) AS max_latency_ms
        FROM telemetry_db_query_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY query_fingerprint, route_name, operation, table_name
        ORDER BY slow_count DESC, avg_latency_ms DESC, query_count DESC
        LIMIT ' . $limit, [$days]);
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
    return telemetry_report_rows('SELECT job_name, status, started_at, finished_at, duration_ms, gallery_id, image_id, item_count, retry_count, error_kind
        FROM telemetry_job_runs
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY started_at DESC
        LIMIT ' . $limit, [$days]);
}
