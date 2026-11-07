<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_media.php
 * Module Type: Service
 * Purpose: Resolve native photos and playback under current scoped authority.
 * Responsibilities: Check live authority, project and edit authorized photos,
 *   and resolve bounded display files and public presentation tracks.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/../models/cooperative_galleries.php';

use RuntimeException;
use function Gallery\Core\now_sql;
use function Gallery\Models\app_link_model_create_transaction_begin;
use function Gallery\Models\app_link_model_create_transaction_end;
use const Gallery\Models\COOPERATIVE_NATIVE_PHOTO_ROW_LIMIT;
use function Gallery\Models\cooperative_model_photos_page;
use function Gallery\Models\gallery_model_find_by_id;
use function Gallery\Models\gallery_model_linked_detail;
use function Gallery\Models\gallery_model_mutation_context_count;
use function Gallery\Models\image_model_direct_inventory_summary;
use function Gallery\Models\image_model_linked_admin_photo_page;
use function Gallery\Models\image_model_linked_admin_photo_lock;
use function Gallery\Models\image_model_set_visibility;
use function Gallery\Models\image_model_find_by_id;
use function Gallery\Services\gallery_allows_gps_maps;

/**
 * Bound native photo pages to the public export query's established limit.
 * Type: int. Units: photos per page. Scope: one physical gallery.
 * Consumers: app_linking_media_page. Rationale: Eight leaves one lookahead row.
 */
const APP_LINKING_MEDIA_PAGE_SIZE = 8;
/**
 * Bound the opt-in native catalogue page.
 * Type: int. Units: photos per page. Scope: one authorized physical gallery.
 * Consumers: app_linking_media_page and its controller.
 * Rationale: 64 descriptors amortize listing overhead while retaining lookahead.
 */
const APP_LINKING_MEDIA_CATALOGUE_SIZE = COOPERATIVE_NATIVE_PHOTO_ROW_LIMIT - 1;
/**
 * Bound the optional catalogue hash work to one small existing rendition.
 * Type: int. Units: bytes per photo. Scope: native catalogue proof only.
 * Consumers: app_linking_media_page. Rationale: 128 KiB limits a full page
 *     to 8 MiB of reads and excludes unusually large thumbnails and originals.
 */
const APP_LINKING_MEDIA_CATALOGUE_THUMBNAIL_MAX_BYTES = 128 * 1024;

/**
 * Bound descriptions and EXIF text for the native Information card.
 * Type: int. Units: Unicode characters. Scope: one metadata text field.
 * Consumers: app_linking_media_info_text.
 * Rationale: 512 leaves room for useful descriptions in a compact card.
 */
const APP_LINKING_MEDIA_INFO_TEXT_LIMIT = 512;

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
 * Verify the extra storage used only by Admin media and inventory reads.
 *
 * @return bool True while linked detail and indexed original sizes are available.
 */
function app_linking_admin_media_ready(): bool
{
    return schema_inspection_is_available(app_linking_gallery_detail_schema_status())
        && schema_inspection_is_available(app_linking_schema_status(
            'app_linking.admin_media',
            ['images' => ['file_size', 'updated_at']],
            [],
            ['images'],
        ));
}

/**
 * Check the core storage required before advertising permanent photo deletion.
 *
 * @return bool True only when the deletion owner's required columns are available.
 */
function app_linking_admin_media_delete_ready(): bool
{
    try {
        return schema_inspection_is_available(gallery_deletion_schema_status());
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Resolve a physical gallery against a fresh current Admin grant.
 *
 * @param array{grant_id:int,user_id:int,scope:string} $identity Authenticated grant.
 * @param string $token Raw bearer supplied on this request.
 * @param string $issuer Verified exact installation issuer.
 * @param int $galleryId Selected physical gallery.
 * @return array{id:int|string,parent_id:int|string|null,folder_path:string,gps_map_enabled?:int|string|null}|null
 *     Scoped gallery with native media selectors, or null if authority or target changed.
 */
function app_linking_admin_media_gallery(
    array $identity, string $token, string $issuer, int $galleryId,
): ?array {
    $hash = app_linking_access_token_hash($token);
    if ($hash === null || $galleryId <= 0
        || !app_linking_scope_allows_admin_media($identity['scope'])) {
        return null;
    }
    return gallery_model_linked_detail(
        $identity['grant_id'], $identity['user_id'], $hash, $issuer,
        $identity['scope'], $galleryId, true,
    );
}

/**
 * Delete a validated batch through the same owner as the web Admin action.
 *
 * The live grant and every direct photo are checked while the writer lease is
 * held, before any filesystem or database mutation begins.
 *
 * @param array{grant_id:int,user_id:int,scope:string} $identity Current bearer identity.
 * @param string $token Raw bearer supplied on this request.
 * @param string $issuer Exact verified installation issuer.
 * @param int $galleryId Physical gallery containing every selected photo.
 * @param list<int> $ids Unique positive photo identifiers.
 * @return list<int> IDs confirmed deleted by the shared mutation owner.
 * @throws \DomainException When the grant or any selected target is unavailable.
 * @throws RuntimeException When the mutation outcome cannot be confirmed.
 */
function app_linking_admin_media_delete_photos(
    array $identity, string $token, string $issuer, int $galleryId, array $ids,
): array {
    $lock = gallery_edit_writer_begin();
    try {
        if (!app_linking_scope_allows_admin_media($identity['scope'])
            || app_linking_admin_media_gallery($identity, $token, $issuer, $galleryId) === null) {
            throw new \DomainException('Photo batch is unavailable.');
        }
        foreach ($ids as $id) {
            $photo = image_model_find_by_id($id);
            if ($photo === null || (int) ($photo['gallery_id'] ?? 0) !== $galleryId
                || str_contains((string) ($photo['relative_path'] ?? ''), '/')) {
                throw new \DomainException('Photo batch is stale.');
            }
        }
        $result = delete_gallery_images_owned($galleryId, $ids);
        if ((int) ($result['deleted'] ?? 0) !== count($ids)) {
            throw new RuntimeException('Photo deletion could not be confirmed.');
        }
        return $ids;
    } finally {
        gallery_edit_writer_end($lock);
    }
}

/**
 * Project the direct indexed original inventory for an authorized gallery.
 *
 * @param int $galleryId Exact admitted Admin gallery.
 * @return array{gallery_id:int,photo_count:int,file_count:int,size:int}
 *     Count and bytes of indexed original photo files in this gallery.
 */
function app_linking_admin_media_info(int $galleryId): array
{
    $inventory = image_model_direct_inventory_summary($galleryId);
    return [
        'gallery_id' => $galleryId,
        'photo_count' => $inventory['image_count'],
        'file_count' => $inventory['image_count'],
        'size' => (int) $inventory['total_size'],
    ];
}

/**
 * Prepare descriptive gallery metadata for an already authorized Admin row.
 *
 * @param array{id:int|string,title?:?string,parent_id?:int|string|null,description?:?string,visibility?:?string,access_listing?:?string,nsfw_enabled?:bool|int|string|null,created_at?:?string,updated_at?:?string} $gallery Internal row matched to the live Admin grant.
 * @return array{gallery_id:int,photo_count:int,file_count:int,size:int,child_count:int,title?:string,parent_id?:int,description?:string,visibility?:string,listed?:bool,nsfw?:bool,created_at?:string,updated_at?:string,jpeg_quality?:int,webp_quality?:int} Direct inventory, descriptive fields, and configured derivative quality when available.
 */
function app_linking_admin_media_gallery_info(array $gallery): array
{
    $id = (int) $gallery['id'];
    $result = app_linking_admin_media_info($id);
    foreach (['title', 'description', 'visibility', 'created_at', 'updated_at'] as $key) {
        if (is_string($gallery[$key] ?? null)) {
            $result[$key] = app_linking_media_info_text($gallery[$key]);
        }
    }
    if (isset($gallery['parent_id'])) {
        $result['parent_id'] = (int) $gallery['parent_id'];
    }
    if (isset($gallery['nsfw_enabled'])) {
        $result['nsfw'] = (bool) $gallery['nsfw_enabled'];
    }
    if (in_array($gallery['access_listing'] ?? null, ['listed', 'unlisted'], true)) {
        $result['listed'] = $gallery['access_listing'] === 'listed';
    }
    $result['child_count'] = gallery_model_mutation_context_count($id, false);
    return $result + app_linking_media_generation_quality();
}

/**
 * Prepare detailed metadata for a direct photo in the authorized gallery.
 *
 * @param int $galleryId Exact gallery admitted by the current Admin grant.
 * @param int $imageId Requested photo identifier.
 * @param bool $includeGps Whether the established gallery map policy allows GPS.
 * @return array{gallery_id:int,image_id:int,title:string,filename:string,description?:string,size?:int,mime_type?:string,width?:int,height?:int,display_width?:int,display_height?:int,exif_orientation?:int,visibility?:string,nsfw?:bool,created_at?:string,modified_at?:string,exif_taken_at?:string,exif_camera_make?:string,exif_camera_model?:string,exif_lens_model?:string,exif_focal_length?:string,exif_aperture?:string,exif_exposure_time?:string,exif_iso?:int,gps_lat?:float,gps_lng?:float,gps_altitude?:float,jpeg_quality?:int,webp_quality?:int}|null
 *     Bounded stored metadata and generation settings, or null for a nonmatch.
 */
function app_linking_admin_media_photo_info(
    int $galleryId,
    int $imageId,
    bool $includeGps,
): ?array {
    $row = $imageId > 0 ? image_model_find_by_id($imageId) : null;
    if ($row === null || (int) ($row['gallery_id'] ?? 0) !== $galleryId
        || str_contains((string) ($row['relative_path'] ?? ''), '/')) {
        return null;
    }
    $filename = app_linking_media_info_text(basename($row['filename']));
    $title = app_linking_media_info_text((string) ($row['title'] ?? ''));
    $result = [
        'gallery_id' => $galleryId,
        'image_id' => $imageId,
        'title' => $title !== '' ? $title : $filename,
        'filename' => $filename,
    ];
    foreach ([
        'description', 'mime_type', 'visibility',
        'created_at', 'modified_at', 'exif_taken_at', 'exif_camera_make',
        'exif_camera_model', 'exif_lens_model', 'exif_focal_length',
        'exif_aperture', 'exif_exposure_time',
    ] as $key) {
        if (is_string($row[$key] ?? null)) {
            $result[$key] = app_linking_media_info_text($row[$key]);
        }
    }
    foreach (['width', 'height', 'display_width', 'display_height', 'exif_orientation', 'exif_iso'] as $key) {
        if (isset($row[$key]) && (int) $row[$key] > 0) {
            $result[$key] = (int) $row[$key];
        }
    }
    if (isset($row['file_size'])) {
        $result['size'] = (int) $row['file_size'];
    }
    if (isset($row['nsfw_enabled'])) {
        $result['nsfw'] = (bool) $row['nsfw_enabled'];
    }
    if ($includeGps && is_numeric($row['gps_lat'] ?? null)
        && is_numeric($row['gps_lng'] ?? null)
        && abs((float) $row['gps_lat']) <= 90 && abs((float) $row['gps_lng']) <= 180) {
        $result['gps_lat'] = (float) $row['gps_lat'];
        $result['gps_lng'] = (float) $row['gps_lng'];
        if (is_numeric($row['gps_altitude'] ?? null)) {
            $result['gps_altitude'] = (float) $row['gps_altitude'];
        }
    }
    return $result + app_linking_media_generation_quality();
}

/**
 * Limit one stored information field to display-safe Unicode text.
 *
 * @param string $value Source title, description or metadata text.
 * @return string A bounded UTF-8 prefix with controls replaced by spaces.
 */
function app_linking_media_info_text(string $value): string
{
    $pattern = '/\A.{0,' . APP_LINKING_MEDIA_INFO_TEXT_LIMIT . '}/us';
    if (preg_match($pattern, $value, $parts) !== 1) {
        return '';
    }
    return trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', $parts[0]) ?? '');
}

/**
 * Report the configured generation policy when the thumbnail owner is loaded.
 *
 * @return array{jpeg_quality?:int,webp_quality?:int} Current encoder settings
 *     for future derivatives when this runtime has the generation owner.
 */
function app_linking_media_generation_quality(): array
{
    $result = [];
    // Minimal legacy media runtimes may omit the optional encoder owner.
    if (function_exists(__NAMESPACE__ . '\\thumbnail_jpeg_quality')) {
        $result['jpeg_quality'] = thumbnail_jpeg_quality();
    }
    if (function_exists(__NAMESPACE__ . '\\thumbnail_webp_quality')) {
        $result['webp_quality'] = thumbnail_webp_quality();
    }
    return $result;
}

/**
 * Resolve a gallery for a verified live link under the public export policy.
 *
 * @param string $scope Exact consented scope from the verified live bearer.
 * @param int $galleryId Exact selected physical gallery ID.
 * @param bool $presentation Whether explicit presentation consent is required.
 * @return array{id:int|string,parent_id:int|string|null,folder_path:string,presentation_json?:?string,...}|null
 *     Internal row under the shared public export policy, or null for refusal.
 */
function app_linking_media_gallery(
    string $scope, int $galleryId, bool $presentation,
): ?array {
    if ($galleryId <= 0 || !app_linking_scope_allows_gallery_listing($scope)
        || ($presentation && !app_linking_scope_allows_presentation($scope))
        || !app_linking_media_ready($presentation)
        || !gallery_public_export_policy($galleryId)['allowed']) {
        return null;
    }
    return gallery_model_find_by_id($galleryId);
}

/**
 * Page direct photos from a gallery admitted for this scoped request.
 *
 * @param array{id:int|string,parent_id:int|string|null,folder_path:string,gps_map_enabled?:int|string|null} $gallery
 *     Authorized gallery with its thumbnail location and optional map override.
 * @param int $afterId Exclusive image ID; zero begins at the first photo.
 * @param bool $nativeCatalogue Whether the native caller requested a wider page.
 * @param bool $adminMedia Whether this request passed the restricted media grant check.
 * @param bool $publicGallery Whether the Admin gallery separately passed public export.
 * @return array{gallery_id:int,parent_id:?int,photos:list<array{id:int,title:string,latitude?:float,longitude?:float,thumbnail_etag?:string,preload_thumbnail_etag?:string,visibility?:string,nsfw?:bool,size?:?int,cache_eligible?:bool,preload_eligible?:bool}>,next_after:?int}
 *     Ordered authorized photo descriptors without paths or credentials.
 */
function app_linking_media_page(
    array $gallery,
    int $afterId,
    bool $nativeCatalogue = false,
    bool $adminMedia = false,
    bool $publicGallery = false,
): array {
    $pageSize = $nativeCatalogue ? APP_LINKING_MEDIA_CATALOGUE_SIZE
        : APP_LINKING_MEDIA_PAGE_SIZE;
    $galleryId = (int) $gallery['id'];
    $preloadGalleryAllowed = $adminMedia
        && gallery_admin_media_preload_gallery_allowed($galleryId);
    // The public map policy already fails closed for missing or unknown GPS schema.
    $includeGps = (!$adminMedia || $publicGallery) && gallery_allows_gps_maps($gallery);
    $rows = $adminMedia
        ? image_model_linked_admin_photo_page($galleryId, $afterId, $pageSize + 1, $includeGps)
        : cooperative_model_photos_page(
            $galleryId, $afterId, $pageSize + 1, $includeGps, $nativeCatalogue,
        );
    $page = array_slice($rows, 0, $pageSize);
    $canProveThumbnails = $nativeCatalogue;
    if ($canProveThumbnails) {
        // The existing thumbnail owner checks all competing source rows in
        // bounded batches and caches ownership for the exact paths below.
        thumbnail_source_identity_preload(array_values(array_filter(
            $page,
            static fn(array $row): bool => app_linking_media_photo_is_public($row)
                || ($preloadGalleryAllowed
                    && in_array($row['nsfw_enabled'] ?? null, [0, '0'], true)),
        )));
    }
    $photos = [];
    foreach ($page as $row) {
        $photo = $adminMedia
            ? app_linking_admin_media_photo_identity(
                $row, $publicGallery, $preloadGalleryAllowed,
            )
            : app_linking_media_photo_identity($row);
        $publicPhoto = app_linking_media_photo_is_public($row);
        $preloadEligible = $adminMedia
            && $preloadGalleryAllowed
            && in_array($row['nsfw_enabled'] ?? null, [0, '0'], true);
        if ($canProveThumbnails && ($publicPhoto || $preloadEligible)) {
            $etag = app_linking_media_catalogue_thumbnail_etag($row, $gallery);
            if ($etag !== null) {
                if (!$adminMedia || ($publicGallery && $publicPhoto)) {
                    $photo['thumbnail_etag'] = $etag;
                } elseif ($preloadEligible) {
                    $photo['preload_thumbnail_etag'] = $etag;
                }
            }
        }
        if ($adminMedia) {
            $photo['preload_eligible'] = $preloadEligible;
        }
        if ($includeGps && $publicPhoto && is_numeric($row['gps_lat'] ?? null)
            && is_numeric($row['gps_lng'] ?? null)) {
            $latitude = (float) $row['gps_lat'];
            $longitude = (float) $row['gps_lng'];
            // Geographic bounds are structural coordinate validity limits.
            if (is_finite($latitude) && is_finite($longitude)
                && $latitude >= -90.0 && $latitude <= 90.0
                && $longitude >= -180.0 && $longitude <= 180.0) {
                $photo['latitude'] = $latitude;
                $photo['longitude'] = $longitude;
            }
        }
        $photos[] = $photo;
    }
    return [
        'gallery_id' => $galleryId,
        'parent_id' => empty($gallery['parent_id']) ? null : (int) $gallery['parent_id'],
        'photos' => $photos,
        'next_after' => count($rows) > $pageSize
            ? $photos[array_key_last($photos)]['id'] : null,
    ];
}

/**
 * Return a compact direct-photo inventory revision for an authorized gallery.
 *
 * This detects changes to the indexed count, newest ID, update or total bytes;
 * equal aggregates do not prove that every photo is unchanged.
 *
 * @param int $galleryId Exact gallery already admitted to Admin media.
 * @return array{gallery_id:int,revision:string} Gallery-bound aggregate hash.
 */
function app_linking_admin_media_revision(int $galleryId): array
{
    $summary = image_model_direct_inventory_summary($galleryId);
    return [
        'gallery_id' => $galleryId,
        'revision' => hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR)),
    ];
}

/**
 * Project a native photo identity without its internal storage path.
 *
 * @param array{id:int|string,filename:string} $row Current authorized photo.
 * @return array{id:int,title:string} Positive photo ID and safe display name.
 */
function app_linking_media_photo_identity(array $row): array
{
    return ['id' => (int) $row['id'], 'title' => (string) preg_replace(
        '/[\x00-\x1f\x7f]/', ' ', basename($row['filename']),
    )];
}

/**
 * Add stored photo settings and independent public cache admission.
 *
 * @param array{id:int|string,filename:string,visibility:string,nsfw_enabled:int|string,file_size:int|string|null}
 *     $row Authorized indexed photo.
 * @param bool $publicGallery Whether the current gallery passes public export.
 * @param bool $preloadGalleryAllowed Whether complete current ancestry is NSFW-free.
 * @return array{id:int,title:string,visibility:string,nsfw:bool,size:?int,cache_eligible:bool,preload_eligible:bool}
 *     Admin photo descriptor without a storage path.
 */
function app_linking_admin_media_photo_identity(
    array $row, bool $publicGallery, bool $preloadGalleryAllowed,
): array
{
    $preloadEligible = $preloadGalleryAllowed
        && in_array($row['nsfw_enabled'] ?? null, [0, '0'], true);
    return app_linking_media_photo_identity($row) + [
        'visibility' => $row['visibility'],
        'nsfw' => (bool) $row['nsfw_enabled'],
        'size' => $row['file_size'] === null ? null : (int) $row['file_size'],
        'cache_eligible' => $publicGallery && app_linking_media_photo_is_public($row),
        'preload_eligible' => $preloadEligible,
    ];
}

/**
 * Change one direct photo against the previously observed stored visibility.
 *
 * @param array{grant_id:int,user_id:int,scope:string} $identity Previously authenticated Admin grant.
 * @param string $token Raw bearer supplied on this request.
 * @param string $issuer Verified exact installation issuer.
 * @param int $galleryId Exact selected physical gallery.
 * @param int $imageId Positive selected direct photo ID.
 * @param string $expectedVisibility Visibility observed by the caller.
 * @param string $visibility Explicit public, private, or draft target.
 * @return array{id:int,title:string,visibility:string,nsfw:bool,size:?int,cache_eligible:bool,preload_eligible:bool}|null
 *     Confirmed fresh photo descriptor or null when the target changed before writing.
 * @throws RuntimeException When authority, persistence or confirmation is unavailable.
 */
function app_linking_admin_media_change_photo_visibility(
    array $identity,
    string $token,
    string $issuer,
    int $galleryId,
    int $imageId,
    string $expectedVisibility,
    string $visibility,
): ?array {
    $hash = app_linking_access_token_hash($token);
    if ($hash === null || !app_linking_scope_allows_admin_media($identity['scope'])
        || !app_linking_visibility_scope_allowed($identity['scope'])
        || !in_array($expectedVisibility, ['draft', 'public', 'private'], true)
        || !in_array($visibility, ['draft', 'public', 'private'], true)
        || $expectedVisibility === $visibility
        || !app_link_model_create_transaction_begin(
            $identity['user_id'], $identity['grant_id'], $hash, $issuer,
            APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'],
        )) {
        throw new RuntimeException('Photo visibility authority is unavailable.');
    }

    $commit = false;
    try {
        app_linking_recheck_wide_scope(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope']);
        $photo = image_model_linked_admin_photo_lock($galleryId, $imageId);
        if ($photo === null || $photo['visibility'] !== $expectedVisibility) {
            return null;
        }
        if (image_model_set_visibility([$imageId], $visibility, now_sql()) !== 1) {
            throw new RuntimeException('Photo visibility write is unavailable.');
        }
        $updated = image_model_linked_admin_photo_lock($galleryId, $imageId);
        $photo['visibility'] = $visibility;
        if ($updated !== $photo) {
            throw new RuntimeException('Photo visibility readback is unavailable.');
        }
        $commit = true;
    } finally {
        app_link_model_create_transaction_end($commit);
    }

    gallery_visit_content_update(
        \Gallery\Models\gallery_visit_content_model_image_sources([$imageId]),
    );
    return app_linking_admin_media_photo_identity(
        $updated, gallery_public_export_policy($galleryId)['allowed'],
        gallery_admin_media_preload_gallery_allowed($galleryId),
    );
}

/**
 * Check whether the indexed photo independently qualifies for public export.
 *
 * @param array{visibility?:string,nsfw_enabled?:int|string|null} $image Indexed photo.
 * @return bool True for an explicitly public photo with NSFW Guard off.
 */
function app_linking_media_photo_is_public(array $image): bool
{
    return ($image['visibility'] ?? null) === 'public'
        && in_array($image['nsfw_enabled'] ?? null, [0, '0'], true);
}

/**
 * Select the first existing owned native thumbnail in the shared variant order.
 *
 * @param array{id:int|string,gallery_id:int|string,filename:string,relative_path:string,thumbnail_source_identity_version?:int|string} $image
 *     Current indexed naming identity.
 * @param array{folder_path:string} $gallery Current physical gallery.
 * @return array{path:string,mime:string}|null Existing thumbnail candidate.
 * @throws RuntimeException When path containment cannot be verified.
 */
function app_linking_media_thumbnail_candidate(array $image, array $gallery): ?array
{
    if (!thumbnail_legacy_identity_owned($image)) {
        return null;
    }
    $sizes = thumbnail_sizes();
    sort($sizes, SORT_NUMERIC);
    foreach ($sizes as $size) {
        foreach (['webp' => 'image/webp', 'jpg' => 'image/jpeg'] as $format => $mime) {
            $path = thumbnail_abs_path($image, $gallery, $size, $format);
            if (is_file($path)) {
                return ['path' => $path, 'mime' => $mime];
            }
        }
    }
    return null;
}

/**
 * Verify an already-materialized thumbnail for one current public descriptor.
 *
 * @param array{id:int|string,gallery_id:int|string,filename:string,relative_path:string} $image
 *     Current independently public naming identity.
 * @param array{folder_path:string} $gallery Current independently public gallery.
 * @return string|null Strong current thumbnail ETag, or null when omitted.
 */
function app_linking_media_catalogue_thumbnail_etag(array $image, array $gallery): ?string
{
    try {
        $candidate = app_linking_media_thumbnail_candidate($image, $gallery);
        if ($candidate === null) {
            return null;
        }
        $opened = app_linking_media_open_verified_photo_file(
            $candidate['path'], $candidate['mime'],
            APP_LINKING_MEDIA_CATALOGUE_THUMBNAIL_MAX_BYTES, true,
        );
        if (!isset($opened['stream'])) {
            return null;
        }
        fclose($opened['stream']);
        return $opened['etag'];
    } catch (RuntimeException) {
        // A single unverifiable optional thumbnail cannot hide the gallery.
        return null;
    }
}

/**
 * Open an authorized existing display rendition or thumbnail without writes.
 *
 * A detail whose display source exceeds the transfer cap may use an existing
 * generated rendition. The selected file must pass the same checks and cap.
 *
 * @param array{id:int|string,folder_path:string} $gallery Admitted physical gallery.
 * @param int $imageId Positive ID of a direct photo.
 * @param bool $thumbnail Prefer the smallest available configured thumbnail.
 * @param bool $adminMedia Whether restricted photos are authorized on this request.
 * @param bool $publicGallery Whether the Admin gallery independently passed public export.
 * @param bool $adminPreload Whether this binary read explicitly opted into Admin preload.
 * @return array{stream:resource,size:int,mime:string,etag:?string}|null
 *     Open verified file and safe wire metadata, or null if unavailable.
 * @throws RuntimeException If the existing path owner cannot verify containment.
 */
function app_linking_media_open_photo(
    array $gallery,
    int $imageId,
    bool $thumbnail,
    bool $adminMedia = false,
    bool $publicGallery = false,
    bool $adminPreload = false,
): ?array {
    $image = image_model_find_by_id($imageId);
    if ($image === null || (int) $image['gallery_id'] !== (int) $gallery['id']
        || (!$adminMedia && !app_linking_media_photo_is_public($image))
        || str_contains((string) $image['relative_path'], '/')) {
        return null;
    }
    $display = $thumbnail ? app_linking_media_thumbnail_candidate($image, $gallery) : null;
    if ($display === null) {
        // Older uploads may have no configured thumbnails. The native reader
        // can downsize the existing display file under the same byte, path
        // and format limits. This read never creates a derivative.
        $display = image_public_display_file($image, $gallery, false);
    }
    $publicPhoto = $publicGallery && app_linking_media_photo_is_public($image);
    $preloadPhoto = $adminMedia && $adminPreload
        && gallery_admin_media_preload_gallery_allowed((int) $gallery['id'])
        && in_array($image['nsfw_enabled'] ?? null, [0, '0'], true);
    $withValidator = !$adminMedia || $publicPhoto || $preloadPhoto;
    $opened = $display === null ? null : app_linking_media_open_verified_photo_file(
        $display['path'], $display['mime'], APP_LINKING_MEDIA_MAX_BYTES, $withValidator,
    );
    if (!$thumbnail && isset($opened['oversized'])) {
        // Keep the existing bounded fallback for oversized display sources.
        $display = image_public_display_derivative_fallback_file($image, $gallery);
        $opened = $display === null ? null : app_linking_media_open_verified_photo_file(
            $display['path'], $display['mime'], APP_LINKING_MEDIA_MAX_BYTES, $withValidator,
        );
    }
    return isset($opened['stream']) ? $opened : null;
}

/**
 * Open an existing contained file and optionally hash the exact response bytes.
 *
 * @param string $path Internal selected raster path.
 * @param string $mime MIME type selected by the existing display owner.
 * @param int $maxBytes Positive per-file read budget.
 * @param bool $withValidator Whether this public file can use a strong validator.
 * @return array{stream:resource,size:int,mime:string,etag:?string}|array{oversized:true}|null
 *     Verified stream, oversized signal for the detail fallback, or refusal.
 */
function app_linking_media_open_verified_photo_file(
    string $path,
    string $mime,
    int $maxBytes,
    bool $withValidator,
): ?array {
    if (!in_array($mime, APP_LINKING_MEDIA_IMAGE_TYPES, true)
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
        || $size <= 0) {
        fclose($stream);
        return null;
    }
    if ($size > $maxBytes) {
        fclose($stream);
        return ['oversized' => true];
    }
    if (!$withValidator) {
        return ['stream' => $stream, 'size' => $size, 'mime' => $mime, 'etag' => null];
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
 * @return array{enabled:bool,autoplay:bool,interval_seconds:int,transition:string,music:bool,track_cache:bool,tracks:list<array{id:string,title:string,mime:string,size:int,sha256:string}>}
 *     Safe player preferences and support for opt-in native audio caching.
 */
function app_linking_media_presentation(array $gallery): array
{
    $settings = gallery_presentation_settings($gallery);
    $tracks = [];
    if ($settings['enabled'] && $settings['music']) {
        // Native playback is an explicit presentation action. The gallery
        // playlist switch controls default playback, while presentation.music
        // controls whether this presentation may offer its visible tracks.
        foreach (gallery_music_playlist($gallery)['tracks'] as $track) {
            if (app_linking_media_track_playable($track)) {
                $tracks[] = [
                    'id' => $track['id'],
                    'title' => (string) preg_replace(
                        '/[\x00-\x1f\x7f]/', ' ', $track['title'],
                    ),
                    'mime' => $track['mime'],
                    'size' => $track['size'],
                    'sha256' => $track['sha256'],
                ];
                if (count($tracks) >= APP_LINKING_MEDIA_TRACK_LIMIT) {
                    break;
                }
            }
        }
    }
    return ['enabled' => $settings['enabled'], 'autoplay' => $settings['autoplay'],
        'interval_seconds' => APP_LINKING_MEDIA_INTERVAL_SECONDS[$settings['interval']],
        'transition' => $settings['transition'], 'music' => $settings['music'],
        'track_cache' => true, 'tracks' => $tracks];
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
