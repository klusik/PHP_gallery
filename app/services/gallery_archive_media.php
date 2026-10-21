<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_archive_media.php
 * Module Type: Service
 * Purpose: Opt-in PDF and music inclusion in gallery ZIP archives.
 * Responsibilities:
 *   - Resolve independent global, physical-gallery and item preferences
 *   - Preserve legacy music assignment download toggles as item preferences
 *   - Recheck visitor access, feature capabilities and hidden state
 *   - Resolve trusted original bytes without widening Smart result membership
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/gallery_pdf_documents.php';
require_once __DIR__ . '/simbrief_ofp_attachments.php';
require_once __DIR__ . '/gallery_music.php';

/**
 * Validate the only supported non-photo archive media kinds.
 *
 * @param string $kind Media kind supplied by the settings or download caller.
 * @return string Trusted kind: pdf or music.
 */
function gallery_archive_media_kind(string $kind): string
{
    if (!in_array($kind, ['pdf', 'music'], true)) {
        throw new \InvalidArgumentException('Invalid archive media kind.');
    }
    return $kind;
}

/**
 * Read the explicit, default-off global archive inclusion preference.
 *
 * @param string $kind PDF or music media kind.
 * @return bool True only when this installation has explicitly opted in.
 */
function gallery_archive_media_global_enabled(string $kind): bool
{
    $kind = gallery_archive_media_kind($kind);
    return app_setting('download_archive_' . $kind . '_global', '0') === '1';
}

/**
 * Read the explicit, default-off physical-gallery inclusion preference.
 *
 * @param string $kind PDF or music media kind.
 * @param int $galleryId Positive physical source gallery identifier.
 * @return bool Whether the gallery override is currently on.
 */
function gallery_archive_media_gallery_enabled(
    string $kind,
    int $galleryId,
): bool {
    $kind = gallery_archive_media_kind($kind);
    return $galleryId > 0
        && app_setting(
            'download_archive_' . $kind . '_gallery_' . $galleryId,
            '0',
        ) === '1';
}

/**
 * Return enabled per-PDF preferences indexed by original attachment identity.
 *
 * Old installations have no stored map and remain off. Missing/corrupt maps
 * fail closed and never alter the protected standalone PDF download control.
 *
 * @param string $kind PDF or music media kind.
 * @param int $galleryId Physical source gallery identifier.
 * @return array<string,bool> Explicitly allowed original media identities.
 */
function gallery_archive_media_item_preferences(
    string $kind,
    int $galleryId,
): array {
    $kind = gallery_archive_media_kind($kind);
    if ($galleryId <= 0) {
        return [];
    }
    $raw = app_setting(
        'download_archive_' . $kind . '_items_' . $galleryId,
        '{}',
    );
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $result = [];
    foreach ($decoded as $id => $allowed) {
        if (
            is_string($id)
            && (
                ($kind === 'pdf' && $id === 'ofp')
                || preg_match('/^[a-f0-9]{32}$/D', $id) === 1
            )
            && $allowed === true
        ) {
            $result[$id] = true;
        }
    }
    return $result;
}

/**
 * Compatibility reader for independently permitted original PDF IDs.
 *
 * @param int $galleryId Physical source gallery ID.
 * @return array<string,bool> Explicit PDF archive item preferences.
 */
function gallery_archive_pdf_items(int $galleryId): array
{
    return gallery_archive_media_item_preferences('pdf', $galleryId);
}

/**
 * Check the independent archive preference hierarchy without bypassing access.
 *
 * Archive permissions are independent for both media types. In particular the
 * preexisting assignment-level music downloadable switch still controls its
 * standalone download route only. An older installation with that switch ON
 * remains photo-only in gallery ZIPs until a new archive preference is saved.
 *
 * @param string $kind PDF or music kind.
 * @param int $galleryId Physical source gallery identifier.
 * @param string $itemId PDF document or music delivery identifier.
 * @param bool $musicDownloadable Ignored compatibility input; standalone
 *     download permission never implicitly opts an old file into archives.
 * @return bool Whether archive inclusion is explicitly permitted.
 */
function gallery_archive_media_allowed(
    string $kind,
    int $galleryId,
    string $itemId,
    bool $musicDownloadable = false,
): bool {
    $kind = gallery_archive_media_kind($kind);
    if ($galleryId <= 0) {
        return false;
    }
    if (
        gallery_archive_media_global_enabled($kind)
        || gallery_archive_media_gallery_enabled($kind, $galleryId)
    ) {
        return true;
    }
    return isset(
        gallery_archive_media_item_preferences($kind, $galleryId)[$itemId],
    );
}

/**
 * Persist one explicit global or gallery archive preference.
 *
 * @param string $kind PDF or music.
 * @param string $scope Global or physical-gallery scope.
 * @param int $galleryId Positive gallery ID when scope is gallery.
 * @param bool $allowed Requested explicit inclusion state.
 * @return void Persist the preference without changing lower-level settings.
 */
function gallery_archive_media_save_preference(
    string $kind,
    string $scope,
    int $galleryId,
    bool $allowed,
): void {
    $kind = gallery_archive_media_kind($kind);
    if (
        !in_array($scope, ['global', 'gallery'], true)
        || ($scope === 'gallery' && $galleryId <= 0)
    ) {
        throw new \InvalidArgumentException('Invalid archive preference scope.');
    }
    $key = 'download_archive_' . $kind . '_'
        . ($scope === 'global' ? 'global' : 'gallery_' . $galleryId);
    set_app_setting($key, $allowed ? '1' : '0');
}

/**
 * Persist the per-original PDF archive setting, independent of visibility.
 *
 * @param string $kind PDF or music media kind.
 * @param int $galleryId Physical gallery identifier.
 * @param string $itemId Trusted original attachment/track ID or PDF OFP ID.
 * @param bool $allowed Whether this original should enter gallery archives.
 * @return void Save the bounded PDF item map; hidden controls are separate.
 */
function gallery_archive_media_save_item(
    string $kind,
    int $galleryId,
    string $itemId,
    bool $allowed,
): void {
    $kind = gallery_archive_media_kind($kind);
    if (
        $galleryId <= 0
        || (
            !($kind === 'pdf' && $itemId === 'ofp')
            && preg_match('/^[a-f0-9]{32}$/D', $itemId) !== 1
        )
    ) {
        throw new \InvalidArgumentException('Invalid media archive identity.');
    }
    $items = gallery_archive_media_item_preferences($kind, $galleryId);
    if ($allowed) {
        $items[$itemId] = true;
    } else {
        unset($items[$itemId]);
    }
    // Shared-hosted app_setting payloads remain independently bounded.
    // Type: int; Units: bytes; Scope: one physical gallery/media type;
    // Consumers: Admin media editor; Rationale: prevent unbounded metadata.
    $maxJsonBytes = 32768;
    $encoded = json_encode($items, JSON_THROW_ON_ERROR);
    if (strlen($encoded) > $maxJsonBytes) {
        throw new \RuntimeException('Too many media archive preferences.');
    }
    set_app_setting(
        'download_archive_' . $kind . '_items_' . $galleryId,
        $encoded,
    );
}

/**
 * Save one PDF original archive preference without affecting standalone URLs.
 *
 * @param int $galleryId Physical source gallery ID.
 * @param string $documentId Manual PDF identifier or OFP marker.
 * @param bool $allowed Explicit PDF ZIP inclusion value.
 * @return void Save the preference through the common media owner.
 */
function gallery_archive_pdf_save_item(
    int $galleryId,
    string $documentId,
    bool $allowed,
): void {
    gallery_archive_media_save_item('pdf', $galleryId, $documentId, $allowed);
}

/**
 * Check whether a selected source has media eligible for archive evaluation.
 *
 * @param string $kind PDF or music.
 * @param array{id:int|string,folder_path:string} $gallery Authorized source.
 * @return bool Whether source policy and stored preferences warrant a read.
 */
function gallery_archive_media_wants_source(
    string $kind,
    array $gallery,
): bool {
    $id = (int) ($gallery['id'] ?? 0);
    if ($id <= 0) {
        return false;
    }
    if (
        !feature_capability_effective_enabled(
            $kind === 'pdf' ? 'gallery_pdf_documents' : 'gallery_music',
        )
        || !visitor_can_access_gallery($gallery)
    ) {
        return false;
    }
    return gallery_archive_media_global_enabled($kind)
        || gallery_archive_media_gallery_enabled($kind, $id)
        || gallery_archive_media_item_preferences($kind, $id) !== [];
}

/**
 * Deduplicate ZIP-eligible songs by transitive song ID and content digest.
 *
 * The gallery player already uses this union for Smart playlists. Using the
 * same identity equivalence here avoids two different encodings of a merged
 * logical song being shipped as separate archive files. Source authorization
 * was checked before entry to this function; catalog data is advisory only.
 *
 * @param list<array<string,mixed>> $candidates Authorized music originals
 *     with gallery, media ID and SHA-256 digest.
 * @return list<array<string,mixed>> First authorized representative per song
 *     or identical-content component, preserving source order.
 */
function gallery_archive_music_deduplicate(array $candidates): array
{
    if (count($candidates) < 2) {
        return $candidates;
    }
    $catalogRows = [];
    foreach (array_chunk($candidates, GALLERY_MUSIC_CATALOG_BATCH) as $batch) {
        $references = [];
        foreach ($batch as $item) {
            $references[] = [
                'gallery_id' => (int) $item['gallery']['id'],
                'track_id' => $item['media_id'],
            ];
        }
        try {
            $catalogRows += music_catalog_titles_for_references($references);
        } catch (\Throwable) {
            // Missing catalog records cannot add a song-ID union; recorded
            // SHA-256 identity still preserves content deduplication.
        }
    }

    $parents = array_keys($candidates);
    $rank = array_fill(0, count($candidates), 0);
    $owners = [];
    foreach ($candidates as $position => $item) {
        $reference =
            (int) $item['gallery']['id'] . ':' . $item['media_id'];
        $songId = (int) ($item['song_id'] ?? 0);
        if ($songId <= 0) {
            $songId = (int) ($catalogRows[$reference]['song_id'] ?? 0);
        }
        $keys = ['sha:' . $item['digest']];
        if ($songId > 0) {
            $keys[] = 'song:' . $songId;
        }
        foreach ($keys as $key) {
            if (!isset($owners[$key])) {
                $owners[$key] = $position;
                continue;
            }
            $left = $position;
            while ($parents[$left] !== $left) {
                $parents[$left] = $parents[$parents[$left]];
                $left = $parents[$left];
            }
            $right = $owners[$key];
            while ($parents[$right] !== $right) {
                $parents[$right] = $parents[$parents[$right]];
                $right = $parents[$right];
            }
            if ($left === $right) {
                continue;
            }
            if ($rank[$left] < $rank[$right]) {
                [$left, $right] = [$right, $left];
            }
            $parents[$right] = $left;
            if ($rank[$left] === $rank[$right]) {
                $rank[$left]++;
            }
        }
    }
    $unique = [];
    $seen = [];
    foreach ($candidates as $position => $item) {
        $root = $position;
        while ($parents[$root] !== $root) {
            $parents[$root] = $parents[$parents[$root]];
            $root = $parents[$root];
        }
        if (!isset($seen[$root])) {
            $seen[$root] = true;
            $unique[] = $item;
        }
    }
    return $unique;
}

/**
 * Read only original PDF/music records currently allowed into an archive.
 *
 * The caller supplies the exact authorized physical sources contributing to
 * this manifest. Hidden/disabled files are always refused even with global ON.
 * Digests are reused for deterministic same-content deduplication; no file
 * bytes or bearer capabilities are stored in a reusable manifest cache.
 *
 * @param list<array{id:int|string,folder_path:string}> $sources Authorized
 *     source gallery rows from the physical subtree or Smart result itself.
 * @return list<array{gallery:array<string,mixed>,media_type:string,
 *     media_id:string,filename:string,size:int,version:string,path:string,
 *     digest:string,song_id?:int}> Permitted original-file descriptors.
 */
function gallery_archive_media_items(array $sources): array
{
    $files = [];
    $musicCandidates = [];
    $digests = ['pdf' => []];
    foreach ($sources as $gallery) {
        if (!is_array($gallery)) {
            continue;
        }
        $galleryId = (int) ($gallery['id'] ?? 0);
        if ($galleryId <= 0) {
            continue;
        }
        if (gallery_archive_media_wants_source('pdf', $gallery)) {
            try {
                $documents = gallery_pdf_documents_list($gallery);
            } catch (\Throwable $error) {
                throw new \RuntimeException(
                    'An original PDF source could not be checked.',
                    0,
                    $error,
                );
            }
            foreach ($documents as $document) {
                $id = (string) $document['id'];
                if (
                    !empty($document['hidden'])
                    || !gallery_archive_media_allowed('pdf', $galleryId, $id)
                ) {
                    continue;
                }
                $resolved = gallery_archive_media_original(
                    $gallery,
                    'pdf',
                    $id,
                );
                if ($resolved !== null) {
                    $digest = $resolved['digest'];
                    if (!isset($digests['pdf'][$digest])) {
                        $digests['pdf'][$digest] = true;
                        $files[] = $resolved;
                    }
                }
            }
            if (
                !gallery_pdf_documents_hidden($gallery, 'ofp')
                && gallery_archive_media_allowed('pdf', $galleryId, 'ofp')
            ) {
                $resolved = gallery_archive_media_original(
                    $gallery,
                    'pdf',
                    'ofp',
                );
                if (
                    $resolved !== null
                    && !isset($digests['pdf'][$resolved['digest']])
                ) {
                    $digests['pdf'][$resolved['digest']] = true;
                    $files[] = $resolved;
                }
            }
        }
        if (!gallery_archive_media_wants_source('music', $gallery)) {
            continue;
        }
        try {
            $playlist = gallery_music_playlist($gallery);
        } catch (\Throwable $error) {
            throw new \RuntimeException(
                'The source music playlist could not be checked.',
                0,
                $error,
            );
        }
        if (
            !empty($playlist['partial'])
            || !empty($playlist['over_limit'])
            || (int) ($playlist['unavailable'] ?? 0) > 0
        ) {
            throw new \RuntimeException(
                'The source music playlist is incomplete.',
            );
        }
        foreach ($playlist['tracks'] as $track) {
            if (
                !gallery_music_track_visible($track)
                || (
                    !gallery_archive_media_allowed(
                        'music',
                        $galleryId,
                        (string) $track['id'],
                    )
                    && !gallery_archive_media_allowed(
                        'music',
                        $galleryId,
                        (string) ($track['source_id'] ?? ''),
                    )
                )
            ) {
                continue;
            }
            $resolved = gallery_archive_media_original(
                $gallery,
                'music',
                (string) $track['id'],
            );
            if ($resolved !== null) {
                $musicCandidates[] = $resolved;
            }
        }
    }
    return array_merge(
        $files,
        gallery_archive_music_deduplicate($musicCandidates),
    );
}

/**
 * Re-resolve one original and verify feature, access, hidden and inclusion policy.
 *
 * This is also called by every progressive source-byte request, so cached
 * manifests, previous capabilities and revised preferences grant no authority.
 * The media-specific storage owners verify file confinement/integrity before
 * anything is streamed. Standalone media routes are left unchanged.
 *
 * @param array{id:int|string,folder_path:string} $gallery Physical source.
 * @param string $kind Requested original type.
 * @param string $id PDF ID/OFP marker or opaque music delivery ID.
 * @return ?array{gallery:array<string,mixed>,media_type:string,
 *     media_id:string,filename:string,size:int,version:string,path:string,
 *     digest:string,song_id:int} Verified source descriptor, null when denied/missing.
 */
function gallery_archive_media_original(
    array $gallery,
    string $kind,
    string $id,
): ?array {
    $kind = gallery_archive_media_kind($kind);
    if (!gallery_archive_media_wants_source($kind, $gallery)) {
        return null;
    }
    $galleryId = (int) $gallery['id'];
    $filename = '';
    $path = '';
    $digest = '';
    $size = 0;
    $songId = 0;
    if ($kind === 'pdf') {
        if ($id === 'ofp') {
            if (gallery_pdf_documents_hidden($gallery, 'ofp')) {
                return null;
            }
            $path = simbrief_ofp_local_pdf_path($gallery) ?? '';
            $filename = 'simbrief-ofp.pdf';
            if ($path === '') {
                return null;
            }
            $size = (int) @filesize($path);
            $digest = (string) @hash_file('sha256', $path);
        } else {
            $document = gallery_pdf_documents_read($gallery, $id);
            if (
                $document === null
                || !empty($document['metadata']['hidden'])
            ) {
                return null;
            }
            $path = (string) $document['path'];
            $filename = (string) $document['metadata']['filename'];
            $size = (int) $document['metadata']['size'];
            $digest = (string) $document['metadata']['sha256'];
        }
        if (!gallery_archive_media_allowed('pdf', $galleryId, $id)) {
            return null;
        }
    } else {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            return null;
        }
        try {
            $music = gallery_music_track($gallery, $id);
        } catch (\Throwable) {
            return null;
        }
        if (
            $music === null
            || !gallery_music_track_visible($music['track'])
            || (
                !gallery_archive_media_allowed('music', $galleryId, $id)
                && !gallery_archive_media_allowed(
                    'music',
                    $galleryId,
                    (string) ($music['track']['source_id'] ?? ''),
                )
            )
        ) {
            return null;
        }
        $path = (string) $music['path'];
        $filename = (string) $music['track']['filename'];
        $size = (int) $music['track']['size'];
        $digest = (string) $music['track']['sha256'];
        // A live album member already has an authoritative logical song ID.
        // Direct files still use the indexed catalog lookup during deduplication.
        if (($music['track']['source_type'] ?? '') === 'album') {
            $songId = max(
                0,
                (int) ($music['track']['source_track_id'] ?? 0),
            );
        }
    }

    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    if (
        $path === ''
        || !is_file($path)
        || (
            $kind === 'pdf' || empty($music['track']['asset_id'])
                ? !\Gallery\Core\path_inside($galleryRoot, $path)
                : false
        )
        || $size <= 0
        || @filesize($path) !== $size
        || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
    ) {
        return null;
    }
    return [
        'gallery' => $gallery,
        'media_type' => $kind,
        'media_id' => $id,
        'filename' => $filename,
        'size' => $size,
        'version' => substr($digest, 0, 16),
        'path' => $path,
        'digest' => $digest,
        'song_id' => $songId,
    ];
}
