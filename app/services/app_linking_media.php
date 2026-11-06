<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_media.php
 * Module Type: Service
 * Purpose: Resolve read-only native media using current public export policy.
 * Responsibilities: Check live authority, page public photos, and resolve
 *   bounded display files and visible presentation tracks.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/../models/cooperative_galleries.php';

use RuntimeException;
use function Gallery\Models\cooperative_model_photos_page;
use function Gallery\Models\gallery_model_find_by_id;
use function Gallery\Models\image_model_find_by_id;

/**
 * Bound native photo pages to the public export query's established limit.
 * Type: int. Units: photos per page. Scope: one physical gallery.
 * Consumers: app_linking_media_page. Rationale: Eight leaves one lookahead row.
 */
const APP_LINKING_MEDIA_PAGE_SIZE = 8;
/**
 * Bound binary responses buffered by WinApp for decoding or playback.
 * Type: int. Units: bytes. Scope: one authorized image or audio item.
 * Consumers: native image and track selection. Rationale: 16 MiB covers
 *     common display renditions and music without unbounded local buffers.
 */
const APP_LINKING_MEDIA_MAX_BYTES = 16 * 1024 * 1024;
/**
 * Bound the ordered playlist returned for one native presentation.
 * Type: int. Units: tracks. Scope: one gallery's projected playlist.
 * Consumers: app_linking_media_presentation. Rationale: 32 keeps metadata
 *     under the native JSON response budget while preserving playlist order.
 */
const APP_LINKING_MEDIA_TRACK_LIMIT = 32;
/**
 * Map normalized server interval presets to native playback durations.
 * Type: array<string,int>. Units: seconds. Scope: slideshow advance.
 * Consumers: app_linking_media_presentation. Rationale: 10, 5 and 3 seconds
 *     give distinct bounded slow, medium and fast viewing presets.
 */
const APP_LINKING_MEDIA_INTERVAL_SECONDS = ['slow' => 10, 'medium' => 5, 'fast' => 3];

/**
 * Limit native photo responses to formats the client can display.
 * Type: list<string>. Units: MIME types. Scope: one photo or thumbnail.
 * Consumers: app_linking_media_open_photo.
 * Rationale: JPEG, PNG, GIF and WebP match the native image renderer.
 */
const APP_LINKING_MEDIA_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

/**
 * Limit native presentation tracks to formats the client can play.
 * Type: list<string>. Units: MIME types. Scope: one gallery audio track.
 * Consumers: app_linking_media_track_playable.
 * Rationale: MPEG audio and both WAV MIME spellings match native playback.
 */
const APP_LINKING_MEDIA_AUDIO_TYPES = ['audio/mpeg', 'audio/wav', 'audio/x-wav'];

/**
 * Require source-policy and gallery/asset storage before announcing a capability.
 *
 * @param bool $presentation Whether persisted presentation support is required.
 * @return bool True only while the configured reader and schema are available.
 */
function app_linking_media_ready(bool $presentation): bool
{
    return APP_LINKING_MEDIA_READY
        && schema_inspection_is_available(gallery_public_export_schema_status())
        && schema_inspection_is_available(app_linking_schema_status(
            'app_linking.media',
            ['galleries' => ['id', 'folder_path'], 'images' => [
                'id', 'gallery_id', 'relative_path', 'filename', 'visibility', 'nsfw_enabled',
            ]],
            ['images' => ['PRIMARY' => ['id']]],
            [],
        ))
        && (!$presentation || schema_inspection_is_available(presentation_gallery_settings_schema_status()));
}

/**
 * Resolve a gallery only for an active explicitly scoped native reader.
 *
 * @param string $token Presented bearer, never retained or forwarded.
 * @param string $issuer Exact configured HTTPS installation.
 * @param int $galleryId Exact selected physical gallery ID.
 * @param bool $presentation Whether the presentation permission is required.
 * @return array{id:int|string,parent_id:int|string|null,folder_path:string,presentation_json?:?string,...}|null
 *     Internal row under the shared public export policy, or null for refusal.
 */
function app_linking_media_gallery(
    string $token, string $issuer, int $galleryId, bool $presentation,
): ?array {
    $identity = app_linking_authenticate($token, $issuer);
    if ($identity === null || $galleryId <= 0
        || $identity['scope'] === app_linking_scope_without_media($identity['scope'])
        || ($presentation && !str_ends_with($identity['scope'], APP_LINKING_PRESENTATION_SUFFIX))
        || !gallery_public_export_policy($galleryId)['allowed']) {
        return null;
    }
    return gallery_model_find_by_id($galleryId);
}

/**
 * Page direct photos from a previously admitted public gallery.
 *
 * @param array{id:int|string,parent_id:int|string|null} $gallery Authorized gallery.
 * @param int $afterId Exclusive image ID; zero begins at the first photo.
 * @return array{gallery_id:int,parent_id:?int,photos:list<array{id:int,title:string}>,next_after:?int}
 *     Ordered public photo descriptors; never includes paths or credentials.
 */
function app_linking_media_page(array $gallery, int $afterId): array
{
    $galleryId = (int) $gallery['id'];
    $rows = cooperative_model_photos_page($galleryId, $afterId, APP_LINKING_MEDIA_PAGE_SIZE + 1);
    $photos = [];
    foreach (array_slice($rows, 0, APP_LINKING_MEDIA_PAGE_SIZE) as $row) {
        $photos[] = ['id' => (int) $row['id'], 'title' => (string) preg_replace(
            '/[\x00-\x1f\x7f]/', ' ', basename((string) $row['filename']),
        )];
    }
    return [
        'gallery_id' => $galleryId,
        'parent_id' => empty($gallery['parent_id']) ? null : (int) $gallery['parent_id'],
        'photos' => $photos,
        'next_after' => count($rows) > APP_LINKING_MEDIA_PAGE_SIZE
            ? $photos[array_key_last($photos)]['id'] : null,
    ];
}

/**
 * Open an authorized existing display rendition or thumbnail without writes.
 *
 * @param array{id:int|string,folder_path:string,...} $gallery Admitted physical gallery.
 * @param int $imageId Positive ID of a direct public, non-NSFW photo.
 * @param bool $thumbnail Select the smallest configured existing thumbnail.
 * @return array{stream:resource,size:int,mime:string,etag:string}|null
 *     Open verified file and safe wire metadata, or null if unavailable.
 * @throws RuntimeException If the existing path owner cannot verify containment.
 */
function app_linking_media_open_photo(array $gallery, int $imageId, bool $thumbnail): ?array
{
    $image = image_model_find_by_id($imageId);
    if ($image === null || (int) $image['gallery_id'] !== (int) $gallery['id']
        || ($image['visibility'] ?? null) !== 'public'
        || !in_array($image['nsfw_enabled'] ?? null, [0, '0'], true)
        || str_contains((string) $image['relative_path'], '/')) {
        return null;
    }
    $path = null;
    $mime = '';
    if ($thumbnail) {
        $sizes = thumbnail_sizes();
        if ($sizes === []) {
            return null;
        }
        foreach (['webp' => 'image/webp', 'jpg' => 'image/jpeg'] as $format => $type) {
            $candidate = thumbnail_abs_path($image, $gallery, min($sizes), $format);
            if (is_file($candidate)) {
                $path = $candidate;
                $mime = $type;
                break;
            }
        }
    } else {
        $display = image_public_display_file($image, $gallery, false);
        $path = $display['path'] ?? null;
        $mime = $display['mime'] ?? '';
    }
    if ($path === null || !in_array($mime, APP_LINKING_MEDIA_IMAGE_TYPES, true)
        || !is_file($path) || is_link($path) || !gallery_filesystem_path_inside_root($path)) {
        return null;
    }
    $stream = @fopen($path, 'rb');
    if ($stream === false) {
        return null;
    }
    $before = fstat($stream);
    $named = @lstat($path);
    $size = $before['size'] ?? 0;
    if (!is_array($before) || !is_array($named)
        || ($before['mode'] & 0170000) !== 0100000
        || $before['dev'] !== $named['dev'] || $before['ino'] !== $named['ino']
        || $size <= 0 || $size > APP_LINKING_MEDIA_MAX_BYTES) {
        fclose($stream);
        return null;
    }
    $digest = hash_init('sha256');
    $read = hash_update_stream($digest, $stream, $size);
    $after = fstat($stream);
    if ($read !== $size || !is_array($after) || $after['size'] !== $size
        || $after['mtime'] !== $before['mtime'] || $after['ctime'] !== $before['ctime']
        || !rewind($stream)) {
        fclose($stream);
        return null;
    }
    return ['stream' => $stream, 'size' => $size, 'mime' => $mime,
        'etag' => '"' . hash_final($digest) . '"'];
}

/**
 * Project bounded native playback preferences and ordered visible tracks.
 *
 * The authorized presentation route loads the shared attachment owner first.
 *
 * @param array{id:int|string,folder_path:string,presentation_json?:?string} $gallery Admitted gallery.
 * @return array{enabled:bool,autoplay:bool,interval_seconds:int,transition:string,music:bool,tracks:list<array{id:string,title:string,mime:string,size:int,sha256:string}>}
 *     Safe player preferences, with no stream paths or tokens.
 */
function app_linking_media_presentation(array $gallery): array
{
    $settings = gallery_presentation_settings($gallery);
    $tracks = [];
    if ($settings['enabled'] && $settings['music']) {
        $playlist = gallery_music_playlist($gallery);
        if ($playlist['enabled']) {
            foreach ($playlist['tracks'] as $track) {
                if (app_linking_media_track_playable($track)) {
                    $tracks[] = ['id' => $track['id'], 'title' => (string) preg_replace(
                        '/[\x00-\x1f\x7f]/', ' ', $track['title'],
                    ),
                        'mime' => $track['mime'], 'size' => $track['size'], 'sha256' => $track['sha256']];
                    if (count($tracks) >= APP_LINKING_MEDIA_TRACK_LIMIT) {
                        break;
                    }
                }
            }
        }
    }
    return ['enabled' => $settings['enabled'], 'autoplay' => $settings['autoplay'],
        'interval_seconds' => APP_LINKING_MEDIA_INTERVAL_SECONDS[$settings['interval']],
        'transition' => $settings['transition'], 'music' => $settings['music'], 'tracks' => $tracks];
}

/**
 * Admit only visible bounded audio using a Windows playback format.
 *
 * @param array{hidden?:bool,mime:string,size:int} $track Current playlist row.
 * @return bool True when the track can be offered to the native player.
 */
function app_linking_media_track_playable(array $track): bool
{
    return gallery_music_track_visible($track)
        && in_array($track['mime'], APP_LINKING_MEDIA_AUDIO_TYPES, true)
        && $track['size'] > 0 && $track['size'] <= APP_LINKING_MEDIA_MAX_BYTES;
}

/**
 * Resolve an audio item only while present in the current native playlist.
 *
 * The authorized track route loads the shared attachment owner first.
 *
 * @param array{id:int|string,folder_path:string,presentation_json?:?string} $gallery Admitted gallery.
 * @param string $trackId Opaque gallery-scoped playlist ID.
 * @return array{path:string,track:array{filename:string,mime:string,size:int,sha256:string,...}}|null
 *     Internal music stream document, or null for unsupported/unavailable tracks.
 */
function app_linking_media_track(array $gallery, string $trackId): ?array
{
    foreach (app_linking_media_presentation($gallery)['tracks'] as $track) {
        if ($track['id'] !== $trackId) {
            continue;
        }
        $document = gallery_music_track($gallery, $trackId);
        if ($document !== null && app_linking_media_track_playable($document['track'])
            && hash_equals($track['sha256'], $document['track']['sha256'])) {
            return $document;
        }
    }
    return null;
}
