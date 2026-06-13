<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_warmup.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides guarded background thumbnail generation for public media fallbacks.
 *
 * Responsibilities:
 *   - Mark images that had to fall back to original media during public rendering
 *   - Verify browser-submitted warmup tokens before doing server work
 *   - Generate only the requested thumbnail sizes in tiny, rate-limited batches
 *   - Keep public browsing behavior unchanged while the cache repairs itself
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
 *   2026-06-08
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\cms_config;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\url_for;

/**
 * Return true when public media fallbacks may request background thumbnail repair.
 *
 * @return bool True when the condition matches.
 */
function thumbnail_warmup_enabled(): bool
{
    return (string) app_setting('thumbnail_background_warmup_enabled', '1') !== '0';
}

/**
 * Return the writable directory used for lightweight warmup locks and cooldown files.
 *
 * @return string Text result for the caller.
 */
function thumbnail_warmup_cache_dir(): string
{
    // $dir stores the process-local cache area used only by this self-healing feature.
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'thumbnail-warmup';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Return the HMAC secret used for short public warmup tokens.
 *
 * @return string Text result for the caller.
 */
function thumbnail_warmup_secret(): string
{
    // $config stores the application configuration because the warmup token must survive without a PHP session.
    $config = cms_config();
    // $secret stores a configured random value already present in existing installs.
    $secret = trim((string) ($config['visitor_vote_secret'] ?? ''));
    if ($secret === '') {
        $secret = trim((string) ($config['setup_key'] ?? ''));
    }
    return $secret !== '' ? $secret : 'php-gallery-thumbnail-warmup-fallback-secret';
}

/**
 * Build the stable token payload for one rendered warmup candidate.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function thumbnail_warmup_token_payload(array $image, array $gallery): string
{
    return implode('|', [
        (string) (int) ($image['id'] ?? 0),
        (string) (int) ($image['gallery_id'] ?? 0),
        (string) ($image['filename'] ?? ''),
        (string) ($image['relative_path'] ?? ''),
        (string) ($gallery['folder_path'] ?? ''),
    ]);
}

/**
 * Return a browser-submittable token proving that the server rendered this image as a warmup candidate.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function thumbnail_warmup_token(array $image, array $gallery): string
{
    return hash_hmac('sha256', thumbnail_warmup_token_payload($image, $gallery), thumbnail_warmup_secret());
}

/**
 * Verify a submitted warmup token for one image and gallery.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param string $token Token value.
 * @return bool True when the condition matches.
 */
function thumbnail_warmup_token_is_valid(array $image, array $gallery, string $token): bool
{
    $expected = thumbnail_warmup_token($image, $gallery);
    return $token !== '' && hash_equals($expected, $token);
}

/**
 * Return a compact HTML attribute string for an image that used original media as thumbnail fallback.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param array $sizes Sizes value.
 * @return string Text result for the caller.
 */
function thumbnail_warmup_candidate_attributes(array $image, array $gallery, array $sizes): string
{
    if (!thumbnail_warmup_enabled()) {
        return '';
    }

    // $imageId stores the database image id sent back by the browser module.
    $imageId = (int) ($image['id'] ?? 0);
    if ($imageId <= 0) {
        return '';
    }

    // $sizes stores only supported thumbnail sizes, limited to the public rendering need that exposed the fallback.
    $sizes = thumbnail_warmup_normalize_sizes($sizes);
    if (!$sizes) {
        return '';
    }

    return implode(' ', [
        'data-thumbnail-warmup-id="' . $imageId . '"',
        'data-thumbnail-warmup-token="' . e(thumbnail_warmup_token($image, $gallery)) . '"',
        'data-thumbnail-warmup-sizes="' . e(implode(',', $sizes)) . '"',
        'data-thumbnail-warmup-endpoint="' . e(url_for('thumbnail_warmup')) . '"',
    ]);
}

/**
 * Return a normalized list of thumbnail sizes accepted by the warmup endpoint.
 *
 * @param array $sizes Sizes value.
 * @return array<int int>.
 */
function thumbnail_warmup_normalize_sizes(array $sizes): array
{
    // $allowed stores supported generated thumbnail sizes.
    $allowed = array_fill_keys(array_map('intval', thumbnail_sizes()), true);
    // $normalized stores accepted sizes in caller order without duplicates.
    $normalized = [];
    foreach ($sizes as $size) {
        $size = (int) $size;
        if ($size <= 0 || !isset($allowed[$size])) {
            continue;
        }
        $normalized[$size] = $size;
    }
    return array_values($normalized);
}

/**
 * Return true when the current visitor may ask the server to repair thumbnails for this image.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function thumbnail_warmup_current_visitor_can_process(array $image, array $gallery): bool
{
    if (current_user() && !current_user_is_known_under_18()) {
        return true;
    }
    return public_image_visible_to_current_visitor($image, $gallery);
}

/**
 * Return the per-image cooldown file path used to avoid repeated expensive repair attempts.
 *
 * @param int $imageId Image identifier.
 * @return string Text result for the caller.
 */
function thumbnail_warmup_cooldown_path(int $imageId): string
{
    return thumbnail_warmup_cache_dir() . DIRECTORY_SEPARATOR . 'image-' . $imageId . '.cooldown';
}

/**
 * Return true when a recently attempted image should be skipped for this request.
 *
 * @param int $imageId Image identifier.
 * @param int $cooldownSeconds Cooldown seconds value.
 * @return bool True when the condition matches.
 */
function thumbnail_warmup_image_is_cooling_down(int $imageId, int $cooldownSeconds = 60): bool
{
    // $path stores the last-attempt marker for this image.
    $path = thumbnail_warmup_cooldown_path($imageId);
    return is_file($path) && time() - (int) filemtime($path) < $cooldownSeconds;
}

/**
 * Mark one image as recently attempted by the background warmup worker.
 *
 * @param int $imageId Image identifier.
 */
function thumbnail_warmup_touch_cooldown(int $imageId): void
{
    @touch(thumbnail_warmup_cooldown_path($imageId));
}

/**
 * Return the global non-waiting lock file path for public warmup processing.
 *
 * @return string Text result for the caller.
 */
function thumbnail_warmup_lock_path(): string
{
    return thumbnail_warmup_cache_dir() . DIRECTORY_SEPARATOR . 'worker.lock';
}


/**
 * Return a compact visitor label for thumbnail warmup logging.
 *
 * @return string Text result for the caller.
 */
function thumbnail_warmup_log_visitor_type(): string
{
    if (current_user()) {
        return 'admin';
    }
    return 'public';
}

/**
 * Return request metadata that helps admins understand why a warmup event happened.
 *
 * @return array Structured result data for the caller.
 */
function thumbnail_warmup_log_request_context(): array
{
    // $referer stores the public page that rendered fallback image candidates, when the browser sent it.
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    // $userAgent stores a short browser signature for diagnosing repeated warmup activity without logging full headers.
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    return array_filter([
        'visitor_type' => thumbnail_warmup_log_visitor_type(),
        'referer' => $referer !== '' ? substr($referer, 0, 240) : null,
        'user_agent' => $userAgent !== '' ? substr($userAgent, 0, 180) : null,
    ], static fn($value): bool => $value !== null && $value !== '');
}

/**
 * Write a guarded thumbnail warmup event to the admin log when the log schema is available.
 *
 * @param string $level Level value.
 * @param string $eventKey Event key value.
 * @param string $message Message value.
 * @param array $context Context value.
 * @param array $options Optional behavior flags.
 */
function thumbnail_warmup_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
{
    if (!function_exists('admin_log_event')) {
        return;
    }

    $options = array_merge([
        'category' => 'thumbnail',
        'severity' => $level === 'error' ? 'error' : 'notice',
        'subject_type' => 'thumbnail_warmup',
        'route_name' => 'thumbnail_warmup',
    ], $options);

    admin_log_event($level, $eventKey, $message, $context, $options);
}

/**
 * Return a compact, non-secret summary of normalized warmup candidates.
 *
 * @param array $items Items value.
 * @return array<int array{id:int,sizes:array<int,int>}>.
 */
function thumbnail_warmup_log_candidate_summary(array $items): array
{
    $summary = [];
    foreach ($items as $item) {
        $summary[] = [
            'id' => (int) ($item['id'] ?? 0),
            'sizes' => array_values(array_map('intval', (array) ($item['sizes'] ?? []))),
        ];
    }
    return $summary;
}


/**
 * Return the active thumbnail policy for one warmup request before source-specific checks.
 *
 * @param array $items Items value.
 * @return array<string mixed>.
 */
function thumbnail_warmup_request_policy_summary(array $items): array
{
    // $requestedSizes stores the union of sizes requested by rendered media fallback elements.
    $requestedSizes = [];
    foreach ($items as $item) {
        foreach ((array) ($item['sizes'] ?? []) as $size) {
            $size = (int) $size;
            if ($size > 0) {
                $requestedSizes[$size] = $size;
            }
        }
    }
    $requestedSizes = thumbnail_warmup_normalize_sizes(array_values($requestedSizes));

    return [
        'mode' => function_exists('Gallery\\Services\\thumbnail_compatibility_mode_log_value') ? thumbnail_compatibility_mode_log_value() : 'jpg_plus_webp',
        'compatibility_mode' => function_exists('Gallery\\Services\\thumbnail_compatibility_mode') ? thumbnail_compatibility_mode() : 'legacy',
        'formats_requested' => function_exists('Gallery\\Services\\thumbnail_policy_requested_formats') ? thumbnail_policy_requested_formats() : ['jpg', 'webp'],
        'enabled_sizes' => array_values(array_map('intval', thumbnail_sizes())),
        'requested_sizes' => $requestedSizes,
        'jpg_quality' => function_exists('Gallery\\Services\\thumbnail_jpeg_quality') ? thumbnail_jpeg_quality() : 82,
        'webp_quality' => function_exists('Gallery\\Services\\thumbnail_webp_quality') ? thumbnail_webp_quality() : 82,
    ];
}

/**
 * Return a compact per-image warmup log detail without exposing private tokens.
 *
 * @param array $item Item value.
 * @param ?array $image Image row or image data.
 * @param ?array $gallery Gallery row or gallery data.
 * @param string $action Action value.
 * @param string $reason Reason value.
 * @param array $extra Extra value.
 * @return array Structured result data for the caller.
 */
function thumbnail_warmup_log_image_detail(array $item, ?array $image, ?array $gallery, string $action, string $reason, array $extra = []): array
{
    $detail = [
        'submitted_id' => (int) ($item['id'] ?? 0),
        'requested_sizes' => array_values(array_map('intval', (array) ($item['sizes'] ?? []))),
        'action' => $action,
        'reason' => $reason,
    ];

    if ($image) {
        $detail['image_id'] = (int) ($image['id'] ?? 0);
        $detail['gallery_id'] = (int) ($image['gallery_id'] ?? 0);
        $detail['filename'] = substr((string) ($image['filename'] ?? ''), 0, 160);
        $detail['relative_path'] = substr((string) ($image['relative_path'] ?? ''), 0, 220);
    }

    if ($gallery) {
        $detail['gallery_path'] = substr((string) ($gallery['folder_path'] ?? ''), 0, 220);
    }

    foreach ($extra as $key => $value) {
        $detail[(string) $key] = $value;
    }

    return $detail;
}

/**
 * Normalize browser-submitted warmup items.
 *
 * @param mixed $rawItems Decoded JSON item list.
 * @return array<int array{id:int,token:string,sizes:array<int,int>}>.
 */
function thumbnail_warmup_normalize_items(mixed $rawItems): array
{
    if (!is_array($rawItems)) {
        return [];
    }

    // $items stores accepted candidates keyed by image id so duplicates from src and srcset candidates collapse.
    $items = [];
    foreach ($rawItems as $rawItem) {
        if (!is_array($rawItem)) {
            continue;
        }
        // $imageId stores the submitted database image id.
        $imageId = (int) ($rawItem['id'] ?? 0);
        // $token stores the HMAC rendered into the public image element.
        $token = trim((string) ($rawItem['token'] ?? ''));
        if ($imageId <= 0 || $token === '') {
            continue;
        }
        // $sizes stores requested generated variants relevant to the current card/list context.
        $sizes = thumbnail_warmup_normalize_sizes((array) ($rawItem['sizes'] ?? []));
        if (!$sizes) {
            $sizes = [300];
        }
        if (!isset($items[$imageId])) {
            $items[$imageId] = ['id' => $imageId, 'token' => $token, 'sizes' => $sizes];
            continue;
        }
        $items[$imageId]['sizes'] = thumbnail_warmup_normalize_sizes(array_merge($items[$imageId]['sizes'], $sizes));
    }

    return array_slice(array_values($items), 0, 24);
}

/**
 * Process a small background thumbnail warmup batch.
 *
 * @param array $items Items value.
 * @return array<string mixed>.
 */
function thumbnail_warmup_process_items(array $items): array
{
    // $startedAt stores the request start time used in the admin-log summary.
    $startedAt = microtime(true);
    // $baseContext stores request metadata shared by all thumbnail warmup log entries.
    $baseContext = array_merge(thumbnail_warmup_log_request_context(), [
        'accepted' => count($items),
        'candidates' => thumbnail_warmup_log_candidate_summary($items),
        'thumbnail_policy' => thumbnail_warmup_request_policy_summary($items),
    ]);

    if (!thumbnail_warmup_enabled()) {
        $response = ['ok' => true, 'enabled' => false, 'accepted' => 0, 'processed' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'busy' => false];
        thumbnail_warmup_log_event(
            'info',
            'thumbnail_warmup.disabled',
            'Background thumbnail warmup was requested but the feature is disabled.',
            array_merge($baseContext, $response),
            ['severity' => 'notice']
        );
        return $response;
    }

    // $lockHandle stores a non-waiting process lock so anonymous traffic cannot stack concurrent GD jobs.
    $lockHandle = @fopen(thumbnail_warmup_lock_path(), 'c');
    if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        $response = ['ok' => true, 'enabled' => true, 'accepted' => count($items), 'processed' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'busy' => true];
        thumbnail_warmup_log_event(
            'info',
            'thumbnail_warmup.busy',
            'Background thumbnail warmup was skipped because another warmup worker is already running.',
            array_merge($baseContext, $response, [
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]),
            ['severity' => 'notice']
        );
        return $response;
    }

    try {
        // $maxImages stores the guarded amount of source images one request may decode and resize.
        $maxImages = current_user() ? 4 : 2;
        // $deadlineSeconds stores the request budget before the absolute deadline is calculated.
        $deadlineSeconds = current_user() ? 8.0 : 4.0;
        // $deadline stores a short wall-clock budget so public requests stay safe on shared hosting.
        $deadline = microtime(true) + $deadlineSeconds;
        // $processed stores how many source images reached generation.
        $processed = 0;
        // $created stores how many thumbnail files were written.
        $created = 0;
        // $skipped stores how many candidates were ignored because they were invalid, inaccessible, cooling down, or already repaired.
        $skipped = 0;
        // $failed stores failed thumbnail writes reported by the generator.
        $failed = 0;
        // $errors stores compact error strings for the JSON response.
        $errors = [];
        // $invalidGeometryDeleted stores stale thumbnail files removed before regeneration.
        $invalidGeometryDeleted = 0;
        // $invalidGeometryFiles stores a capped list of stale thumbnail filenames removed in this batch.
        $invalidGeometryFiles = [];
        // $details stores per-image decisions for the admin log context.
        $details = [];
        // $stoppedReason stores why the batch loop stopped before all submitted candidates were inspected.
        $stoppedReason = null;

        foreach ($items as $item) {
            if ($processed >= $maxImages) {
                $stoppedReason = 'max_images_reached';
                break;
            }
            if (microtime(true) >= $deadline) {
                $stoppedReason = 'deadline_reached';
                break;
            }

            // $image stores the image row selected by the browser-submitted id.
            $image = find_image((int) $item['id']);
            if (!$image) {
                $skipped++;
                $details[] = thumbnail_warmup_log_image_detail($item, null, null, 'skipped', 'image_not_found');
                continue;
            }

            // $gallery stores the parent gallery required for access checks and filesystem paths.
            $gallery = find_gallery((int) $image['gallery_id']);
            if (!$gallery) {
                $skipped++;
                $details[] = thumbnail_warmup_log_image_detail($item, $image, null, 'skipped', 'gallery_not_found');
                continue;
            }

            if (!thumbnail_warmup_token_is_valid($image, $gallery, (string) $item['token'])) {
                $skipped++;
                $details[] = thumbnail_warmup_log_image_detail($item, $image, $gallery, 'skipped', 'invalid_token');
                continue;
            }

            if (!thumbnail_warmup_current_visitor_can_process($image, $gallery)) {
                $skipped++;
                $details[] = thumbnail_warmup_log_image_detail($item, $image, $gallery, 'skipped', 'visitor_cannot_access_image');
                continue;
            }

            // $sizes stores the exact public-rendering sizes that are missing for this browser context.
            $sizes = thumbnail_warmup_normalize_sizes($item['sizes']);
            if (!$sizes) {
                $sizes = [300];
            }

            if (function_exists('Gallery\\Services\\thumbnail_metadata_bundle_data') && thumbnail_metadata_schema_ready()) {
                // $metadata stores the DB-only renderability state so repeated warmup calls do not touch files.
                $metadata = thumbnail_metadata_bundle_data($image, $gallery, $sizes);
                $metadataWarmupSizes = array_values(array_map('intval', (array) ($metadata['warmup_sizes'] ?? [])));
                if (!$metadataWarmupSizes) {
                    $skipped++;
                    $details[] = thumbnail_warmup_log_image_detail($item, $image, $gallery, 'skipped', 'db_already_renderable', [
                        'sizes' => $sizes,
                        'required' => 0,
                        'missing_before' => 0,
                        'metadata_known' => true,
                    ]);
                    continue;
                }
                $sizes = $metadataWarmupSizes;
            }

            // $imageId stores the integer id used for cooldown bookkeeping.
            $imageId = (int) $image['id'];
            if (thumbnail_warmup_image_is_cooling_down($imageId)) {
                $skipped++;
                $details[] = thumbnail_warmup_log_image_detail($item, $image, $gallery, 'skipped', 'image_cooldown_active');
                continue;
            }
            thumbnail_warmup_touch_cooldown($imageId);

            // $status stores the preflight maintenance state, allowing already-healed items to skip decoding originals.
            $status = thumbnail_maintenance_status_for_sizes($image, $gallery, $sizes);
            $invalidGeometryDeleted += (int) ($status['invalid_geometry_deleted'] ?? 0);
            $invalidGeometryFiles = array_merge($invalidGeometryFiles, (array) ($status['invalid_geometry_files'] ?? []));
            if (empty($status['target_formats'])) {
                $skipped++;
                $details[] = thumbnail_warmup_log_image_detail($item, $image, $gallery, 'skipped', 'thumbnail_policy_no_writable_formats', [
                    'sizes' => $sizes,
                    'required' => (int) ($status['required'] ?? 0),
                    'missing_before' => (int) ($status['missing'] ?? 0),
                    'webp_skipped' => (int) ($status['webp_skipped'] ?? 0),
                    'target_formats' => [],
                    'thumbnail_policy' => is_array($status['thumbnail_policy'] ?? null) ? $status['thumbnail_policy'] : null,
                    'invalid_geometry_deleted' => (int) ($status['invalid_geometry_deleted'] ?? 0),
                    'invalid_geometry_files' => array_slice(array_values(array_unique(array_filter(array_map('strval', (array) ($status['invalid_geometry_files'] ?? []))))), 0, 24),
                ]);
                continue;
            }
            if ((int) ($status['missing'] ?? 0) <= 0) {
                $skipped++;
                $details[] = thumbnail_warmup_log_image_detail($item, $image, $gallery, 'skipped', 'already_repaired', [
                    'sizes' => $sizes,
                    'required' => (int) ($status['required'] ?? 0),
                    'missing_before' => (int) ($status['missing'] ?? 0),
                    'webp_skipped' => (int) ($status['webp_skipped'] ?? 0),
                    'target_formats' => array_values(array_map('strval', (array) ($status['target_formats'] ?? []))),
                    'thumbnail_policy' => is_array($status['thumbnail_policy'] ?? null) ? $status['thumbnail_policy'] : null,
                    'invalid_geometry_deleted' => (int) ($status['invalid_geometry_deleted'] ?? 0),
                    'invalid_geometry_files' => array_slice(array_values(array_unique(array_filter(array_map('strval', (array) ($status['invalid_geometry_files'] ?? []))))), 0, 24),
                ]);
                continue;
            }

            // $result stores generator counters for this single image and requested size set.
            $result = create_image_thumbnails_result($image, $gallery, $sizes);
            $processed++;
            $created += (int) ($result['created'] ?? 0);
            $skipped += (int) ($result['skipped'] ?? 0);
            $failed += (int) ($result['failed'] ?? 0);
            $invalidGeometryDeleted += (int) ($result['invalid_geometry_deleted'] ?? 0);
            $invalidGeometryFiles = array_merge($invalidGeometryFiles, (array) ($result['invalid_geometry_files'] ?? []));
            foreach ((array) ($result['errors'] ?? []) as $error) {
                $errors[] = (string) $error;
            }
            $details[] = thumbnail_warmup_log_image_detail($item, $image, $gallery, 'processed', ((int) ($result['failed'] ?? 0) > 0 ? 'generation_failed' : 'missing_derivatives_generated'), [
                'sizes' => $sizes,
                'required' => (int) ($status['required'] ?? 0),
                'missing_before' => (int) ($status['missing'] ?? 0),
                'webp_skipped' => (int) ($status['webp_skipped'] ?? 0),
                'target_formats' => array_values(array_map('strval', (array) ($result['target_formats'] ?? ($status['target_formats'] ?? [])))),
                'thumbnail_policy' => is_array($result['thumbnail_policy'] ?? null) ? $result['thumbnail_policy'] : (is_array($status['thumbnail_policy'] ?? null) ? $status['thumbnail_policy'] : null),
                'created' => (int) ($result['created'] ?? 0),
                'created_files' => array_slice(array_values(array_unique(array_filter(array_map('strval', (array) ($result['created_files'] ?? []))))), 0, 24),
                'invalid_geometry_deleted' => (int) ($status['invalid_geometry_deleted'] ?? 0) + (int) ($result['invalid_geometry_deleted'] ?? 0),
                'invalid_geometry_files' => array_slice(array_values(array_unique(array_filter(array_map('strval', array_merge((array) ($status['invalid_geometry_files'] ?? []), (array) ($result['invalid_geometry_files'] ?? [])))))), 0, 24),
                'generator_skipped' => (int) ($result['skipped'] ?? 0),
                'generator_failed' => (int) ($result['failed'] ?? 0),
                'errors' => array_slice(array_values(array_unique(array_filter(array_map('strval', (array) ($result['errors'] ?? []))))), 0, 6),
            ]);
        }

        if ($created > 0 || $invalidGeometryDeleted > 0) {
            thumbnail_maintenance_summary_cache_clear();
        }

        $response = [
            'ok' => true,
            'enabled' => true,
            'accepted' => count($items),
            'processed' => $processed,
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'invalid_geometry_deleted' => $invalidGeometryDeleted,
            'invalid_geometry_files' => array_slice(array_values(array_unique(array_filter(array_map('strval', $invalidGeometryFiles)))), 0, 24),
            'busy' => false,
            'remaining' => max(0, count($items) - $processed - $skipped),
            'errors' => array_values(array_unique(array_filter($errors))),
        ];

        if ($failed > 0 || $created > 0 || $invalidGeometryDeleted > 0) {
            $severity = $failed > 0 ? 'warning' : 'notice';
            thumbnail_warmup_log_event(
                $failed > 0 ? 'warning' : 'info',
                'thumbnail_warmup.batch',
                'Background thumbnail warmup processed a public media-fallback batch.',
                array_merge($baseContext, $response, [
                    'max_images' => $maxImages,
                    'deadline_seconds' => $deadlineSeconds,
                    'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'stopped_reason' => $stoppedReason,
                    'details' => $details,
                ]),
                ['severity' => $severity]
            );
        }

        return $response;
    } finally {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}
