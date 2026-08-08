<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/duplicate_photo_detector.php
 * Module Type: Service
 *
 * Purpose:
 *   Detects exact and metadata-supported duplicate-photo candidates from stored image metadata.
 *
 * Responsibilities:
 *   - Resolve selected-gallery and administrator-wide detector scopes
 *   - Normalize stored EXIF values into deterministic fingerprints
 *   - Process image metadata in bounded, session-backed batches
 *   - Build deterministic duplicate groups and bounded result pages
 *   - Keep metadata matching pure while exposing validated job state to controller-owned actions
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
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use PDO;
use function Gallery\Core\db;

const DUPLICATE_PHOTO_DETECTOR_DEFAULT_BATCH_SIZE = 200;
const DUPLICATE_PHOTO_DETECTOR_MAX_BATCH_SIZE = 300;
const DUPLICATE_PHOTO_DETECTOR_JOB_TTL_SECONDS = 3600;
const DUPLICATE_PHOTO_DETECTOR_MAX_SESSION_JOBS = 3;
const DUPLICATE_PHOTO_DETECTOR_GROUPS_PER_PAGE = 10;
const DUPLICATE_PHOTO_DETECTOR_MAX_GROUP_MEMBERS = 30;
const DUPLICATE_PHOTO_DETECTOR_PAIRS_PER_PAGE = 10;
const DUPLICATE_PHOTO_DETECTOR_MAX_PAIR_REFERENCES = 10000;
const DUPLICATE_PHOTO_DETECTOR_MIN_EXIF_COMPONENTS = 4;

/**
 * Resolve and validate the server-side duplicate detector scope.
 *
 * A valid selected gallery is always required, even when the administrator
 * requests a global search. The browser-provided global flag is only honored
 * after administrator authorization has already succeeded.
 *
 * @param array<string,mixed>|null $gallery Selected gallery row.
 * @param bool $searchAllRequested Whether the administrator requested global search.
 * @param bool $administratorAuthorized Whether administrator authorization succeeded.
 * @return array{gallery_id:int,search_all:bool} Resolved immutable detector scope.
 */
function duplicate_photo_detector_resolve_scope(?array $gallery, bool $searchAllRequested, bool $administratorAuthorized): array
{
    $galleryId = is_array($gallery) ? (int) ($gallery['id'] ?? 0) : 0;
    if (!$administratorAuthorized || $galleryId <= 0) {
        throw new InvalidArgumentException('The selected gallery is missing or inaccessible.');
    }

    return [
        'gallery_id' => $galleryId,
        'search_all' => $searchAllRequested,
    ];
}

/**
 * Return one selected gallery id plus all descendant gallery ids.
 *
 * The gallery hierarchy is mirrored by normalized folder paths throughout the
 * project. Local duplicate detection therefore follows the same branch model
 * used by gallery-date suggestions and other gallery-level maintenance tools.
 *
 * @param int $galleryId Selected branch-root gallery identifier.
 * @return array<int,int> Gallery ids in deterministic parent-first path order.
 */
function duplicate_photo_detector_gallery_branch_ids(int $galleryId): array
{
    if ($galleryId <= 0) {
        return [];
    }

    $stmt = db()->prepare(
        "SELECT child.id
         FROM galleries root
         INNER JOIN galleries child
             ON child.folder_path = root.folder_path
             OR child.folder_path LIKE CONCAT(root.folder_path, '/%')
         WHERE root.id = ?
         ORDER BY CHAR_LENGTH(child.folder_path), child.folder_path, child.id"
    );
    $stmt->execute([$galleryId]);

    $galleryIds = array_values(array_unique(array_filter(
        array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []),
        'Gallery\\Services\\duplicate_photo_detector_positive_id'
    )));

    return $galleryIds !== [] ? $galleryIds : [$galleryId];
}

/**
 * Return the immutable local gallery ids stored in one detector job.
 *
 * Jobs created by older application code may not have a gallery_ids snapshot.
 * Those jobs safely fall back to their selected gallery only rather than
 * widening scope after deployment.
 *
 * @param array<string,mixed> $job Existing detector job state.
 * @return array<int,int> Positive gallery identifiers in deterministic order.
 */
function duplicate_photo_detector_job_gallery_ids(array $job): array
{
    $galleryIds = array_values(array_unique(array_filter(
        array_map('intval', is_array($job['gallery_ids'] ?? null) ? $job['gallery_ids'] : []),
        'Gallery\\Services\\duplicate_photo_detector_positive_id'
    )));

    if ($galleryIds === []) {
        $galleryId = (int) ($job['gallery_id'] ?? 0);
        if ($galleryId > 0) {
            $galleryIds[] = $galleryId;
        }
    }

    sort($galleryIds, SORT_NUMERIC);
    return $galleryIds;
}

/**
 * Normalize one non-empty textual EXIF value for deterministic comparison.
 *
 * @param mixed $value Raw stored value.
 * @return string|null Normalized text, or null when no meaningful value exists.
 */
function duplicate_photo_detector_normalize_text(mixed $value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

/**
 * Normalize one stored EXIF capture date without applying timezone conversion.
 *
 * @param mixed $value Raw stored capture date.
 * @return string|null Canonical date-time text, or null when unavailable.
 */
function duplicate_photo_detector_normalize_taken_at(mixed $value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    if (preg_match('/^(\d{4})[-:](\d{2})[-:](\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $text, $matches) === 1) {
        return $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
    }

    return duplicate_photo_detector_normalize_text($text);
}

/**
 * Normalize one numeric or rational EXIF value into deterministic decimal text.
 *
 * @param mixed $value Raw stored EXIF value.
 * @param string $suffix Optional suffix to remove before numeric parsing.
 * @return string|null Canonical numeric/text value, or null when unavailable.
 */
function duplicate_photo_detector_normalize_numeric(mixed $value, string $suffix = ''): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    if ($suffix !== '') {
        $text = preg_replace('/\s*' . preg_quote($suffix, '/') . '\s*$/i', '', $text) ?? $text;
        $text = trim($text);
    }

    if (preg_match('/^(-?\d+(?:\.\d+)?)\s*\/\s*(-?\d+(?:\.\d+)?)$/', $text, $matches) === 1) {
        $denominator = (float) $matches[2];
        if (abs($denominator) < 0.000000000001) {
            return null;
        }
        return duplicate_photo_detector_normalize_decimal((float) $matches[1] / $denominator);
    }

    if (is_numeric($text)) {
        return duplicate_photo_detector_normalize_decimal((float) $text);
    }

    return duplicate_photo_detector_normalize_text($text);
}

/**
 * Normalize one aperture value across server and browser EXIF formats.
 *
 * The browser upload worker stores values such as "f/2.8", while the full
 * server EXIF scanner stores the equivalent value as "2.8". Both must map
 * to one deterministic value so an upload-path difference cannot hide a
 * duplicate candidate.
 *
 * @param mixed $value Raw stored aperture value.
 * @return string|null Canonical aperture value, or null when unavailable.
 */
function duplicate_photo_detector_normalize_aperture(mixed $value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $text = preg_replace('/^f\s*\/\s*/i', '', $text) ?? $text;
    return duplicate_photo_detector_normalize_numeric($text);
}

/**
 * Normalize one exposure-time value across server and browser EXIF formats.
 *
 * Browser metadata may use a seconds suffix while the server scanner may keep
 * the original EXIF rational. Removing presentation-only units lets values such
 * as "0.5 s" and "1/2" compare as the same exposure time.
 *
 * @param mixed $value Raw stored exposure-time value.
 * @return string|null Canonical exposure-time value, or null when unavailable.
 */
function duplicate_photo_detector_normalize_exposure_time(mixed $value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $text = preg_replace('/\s*(?:s|sec|secs|second|seconds)\s*$/i', '', $text) ?? $text;
    return duplicate_photo_detector_normalize_numeric(trim($text));
}

/**
 * Normalize one ISO value while accepting optional display prefixes.
 *
 * @param mixed $value Raw stored ISO value.
 * @return string|null Canonical ISO value, or null when unavailable.
 */
function duplicate_photo_detector_normalize_iso(mixed $value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $text = preg_replace('/^iso\s*/i', '', $text) ?? $text;
    return duplicate_photo_detector_normalize_numeric(trim($text));
}

/**
 * Normalize one floating-point value without locale-dependent formatting.
 *
 * @param float $value Numeric value.
 * @return string Canonical decimal text.
 */
function duplicate_photo_detector_normalize_decimal(float $value): string
{
    $formatted = number_format($value, 8, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
}

/**
 * Return normalized meaningful EXIF components for one stored image row.
 *
 * GPS only contributes evidence when both latitude and longitude are present.
 * Empty and NULL values are omitted entirely so missing values never match as
 * evidence merely because both images lack the same field.
 *
 * @param array<string,mixed> $image Stored image metadata row.
 * @return array<string,string> Canonical EXIF evidence in deterministic field order.
 */
function duplicate_photo_detector_exif_components(array $image): array
{
    $components = [];

    $takenAt = duplicate_photo_detector_normalize_taken_at($image['exif_taken_at'] ?? null);
    if ($takenAt !== null) {
        $components['taken_at'] = $takenAt;
    }

    foreach ([
        'camera_make' => 'exif_camera_make',
        'camera_model' => 'exif_camera_model',
        'lens_model' => 'exif_lens_model',
    ] as $component => $column) {
        $normalized = duplicate_photo_detector_normalize_text($image[$column] ?? null);
        if ($normalized !== null) {
            $components[$component] = $normalized;
        }
    }

    $focalLength = duplicate_photo_detector_normalize_numeric($image['exif_focal_length'] ?? null, 'mm');
    if ($focalLength !== null) {
        $components['focal_length'] = $focalLength;
    }

    $aperture = duplicate_photo_detector_normalize_aperture($image['exif_aperture'] ?? null);
    if ($aperture !== null) {
        $components['aperture'] = $aperture;
    }

    $exposureTime = duplicate_photo_detector_normalize_exposure_time($image['exif_exposure_time'] ?? null);
    if ($exposureTime !== null) {
        $components['exposure_time'] = $exposureTime;
    }

    $iso = duplicate_photo_detector_normalize_iso($image['exif_iso'] ?? null);
    if ($iso !== null) {
        $components['iso'] = $iso;
    }

    $gpsLat = duplicate_photo_detector_normalize_numeric($image['gps_lat'] ?? null);
    $gpsLng = duplicate_photo_detector_normalize_numeric($image['gps_lng'] ?? null);
    if ($gpsLat !== null && $gpsLng !== null) {
        $components['gps'] = $gpsLat . ',' . $gpsLng;
    }

    return $components;
}

/**
 * Build the deterministic EXIF fingerprint used for possible-duplicate groups.
 *
 * The fingerprint intentionally requires a capture timestamp, camera/lens
 * identity evidence, and at least four meaningful normalized components. This
 * keeps same-size files with sparse EXIF metadata from becoming false matches.
 *
 * @param array<string,mixed> $image Stored image metadata row.
 * @return array{canonical:string,hash:string,components:array<string,string>,complete:bool}|null Fingerprint details, or null when no evidence exists.
 */
function duplicate_photo_detector_exif_fingerprint(array $image): ?array
{
    $components = duplicate_photo_detector_exif_components($image);
    if ($components === []) {
        return null;
    }

    $hasCaptureTime = isset($components['taken_at']);
    $hasIdentity = isset($components['camera_make']) || isset($components['camera_model']) || isset($components['lens_model']);
    $complete = $hasCaptureTime && $hasIdentity && count($components) >= DUPLICATE_PHOTO_DETECTOR_MIN_EXIF_COMPONENTS;

    $parts = [];
    foreach ($components as $name => $value) {
        $parts[] = $name . '=' . $value;
    }
    $canonical = implode('|', $parts);

    return [
        'canonical' => $canonical,
        'hash' => hash('sha256', $canonical),
        'components' => $components,
        'complete' => $complete,
    ];
}

/**
 * Build the EXIF fingerprint used for possible-duplicate candidates.
 *
 * The original strict fingerprint remains available for conservative callers.
 * Candidate detection also accepts capture time plus camera/lens identity with
 * at least three meaningful components, because real uploads can legitimately
 * contain only make/model/time while still identifying a useful candidate.
 * File size is intentionally excluded from this fingerprint and remains only a
 * corroborating signal in the result view.
 *
 * @param array<string,mixed> $image Stored image metadata row.
 * @return array{canonical:string,hash:string,components:array<string,string>,complete:bool}|null Candidate fingerprint details, or null when no meaningful evidence exists.
 */
function duplicate_photo_detector_candidate_exif_fingerprint(array $image): ?array
{
    $fingerprint = duplicate_photo_detector_exif_fingerprint($image);
    if ($fingerprint === null) {
        return null;
    }
    if ($fingerprint['complete']) {
        return $fingerprint;
    }

    $components = $fingerprint['components'];
    $hasCaptureTime = isset($components['taken_at']);
    $hasIdentity = isset($components['camera_make']) || isset($components['camera_model']) || isset($components['lens_model']);
    $fingerprint['complete'] = $hasCaptureTime && $hasIdentity && count($components) >= 3;
    return $fingerprint;
}

/**
 * Normalize one stored SHA-256 checksum for exact matching.
 *
 * @param mixed $value Raw checksum value.
 * @return string|null Lowercase checksum, or null when missing/invalid.
 */
function duplicate_photo_detector_normalize_checksum(mixed $value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }

    $checksum = strtolower(trim((string) $value));
    return preg_match('/^[a-f0-9]{64}$/', $checksum) === 1 ? $checksum : null;
}

/**
 * Add one image id to a grouped-match accumulator without duplicating ids.
 *
 * @param array<string,array<int,int>> $groups Match groups keyed by deterministic fingerprint.
 * @param string $key Group key.
 * @param int $imageId Image identifier.
 */
function duplicate_photo_detector_append_group_id(array &$groups, string $key, int $imageId): void
{
    if ($imageId <= 0) {
        return;
    }
    if (!isset($groups[$key])) {
        $groups[$key] = [];
    }
    if (!in_array($imageId, $groups[$key], true)) {
        $groups[$key][] = $imageId;
    }
}

/**
 * Process one bounded set of stored image metadata rows into detector state.
 *
 * This function performs no database or filesystem writes and is intentionally
 * separated from the query layer so matching behavior can be unit-tested.
 *
 * @param array<string,mixed> $job Mutable detector job state.
 * @param array<int,array<string,mixed>> $rows Stored image metadata rows.
 */
function duplicate_photo_detector_process_rows(array &$job, array $rows): void
{
    if (!isset($job['exact_first']) || !is_array($job['exact_first'])) {
        $job['exact_first'] = [];
    }
    if (!isset($job['exact_groups']) || !is_array($job['exact_groups'])) {
        $job['exact_groups'] = [];
    }
    if (!isset($job['possible_first']) || !is_array($job['possible_first'])) {
        $job['possible_first'] = [];
    }
    if (!isset($job['possible_groups']) || !is_array($job['possible_groups'])) {
        $job['possible_groups'] = [];
    }
    if (!isset($job['image_gallery_ids']) || !is_array($job['image_gallery_ids'])) {
        $job['image_gallery_ids'] = [];
    }

    foreach ($rows as $row) {
        $imageId = (int) ($row['id'] ?? 0);
        if ($imageId <= 0) {
            continue;
        }
        $galleryId = (int) ($row['gallery_id'] ?? 0);
        if ($galleryId > 0) {
            $job['image_gallery_ids'][$imageId] = $galleryId;
        }

        $checksum = duplicate_photo_detector_normalize_checksum($row['checksum_sha256'] ?? null);
        if ($checksum !== null) {
            if (isset($job['exact_first'][$checksum])) {
                duplicate_photo_detector_append_group_id($job['exact_groups'], $checksum, (int) $job['exact_first'][$checksum]);
                duplicate_photo_detector_append_group_id($job['exact_groups'], $checksum, $imageId);
            } else {
                $job['exact_first'][$checksum] = $imageId;
            }
        }

        $fingerprint = duplicate_photo_detector_candidate_exif_fingerprint($row);
        if ($fingerprint === null || !$fingerprint['complete']) {
            continue;
        }

        // File size is corroborating evidence only. Re-encoded or differently
        // packaged copies of the same photo may retain EXIF while byte size differs.
        $possibleKey = $fingerprint['hash'];
        if (isset($job['possible_first'][$possibleKey])) {
            duplicate_photo_detector_append_group_id($job['possible_groups'], $possibleKey, (int) $job['possible_first'][$possibleKey]);
            duplicate_photo_detector_append_group_id($job['possible_groups'], $possibleKey, $imageId);
        } else {
            $job['possible_first'][$possibleKey] = $imageId;
        }
    }
}

/**
 * Finalize mutable match accumulators into deterministic group arrays.
 *
 * Possible groups that contain exactly the same image ids as an exact checksum
 * group are omitted to avoid reporting the same relationship twice.
 *
 * @param array<string,mixed> $job Mutable detector job state.
 * @return array<string,mixed> Finalized job state.
 */
function duplicate_photo_detector_finalize_job(array $job): array
{
    $exactGroups = duplicate_photo_detector_normalize_group_map((array) ($job['exact_groups'] ?? []));
    $possibleGroups = duplicate_photo_detector_normalize_group_map((array) ($job['possible_groups'] ?? []));

    $exactMemberSets = [];
    foreach ($exactGroups as $ids) {
        $exactMemberSets[implode(',', $ids)] = true;
    }
    foreach ($possibleGroups as $key => $ids) {
        if (isset($exactMemberSets[implode(',', $ids)])) {
            unset($possibleGroups[$key]);
        }
    }

    $job['exact_groups'] = $exactGroups;
    $job['possible_groups'] = $possibleGroups;
    $job['exact_first'] = [];
    $job['possible_first'] = [];
    $job['status'] = 'complete';
    $job['updated_at'] = time();
    return $job;
}

/**
 * Normalize one raw group map into sorted groups with at least two image ids.
 *
 * @param array<string,mixed> $groups Raw grouped image ids.
 * @return array<string,array<int,int>> Deterministically ordered normalized groups.
 */
function duplicate_photo_detector_normalize_group_map(array $groups): array
{
    $normalized = [];
    foreach ($groups as $key => $ids) {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), 'Gallery\\Services\\duplicate_photo_detector_positive_id')));
        sort($cleanIds, SORT_NUMERIC);
        if (count($cleanIds) >= 2) {
            $normalized[(string) $key] = $cleanIds;
        }
    }

    uksort($normalized, 'Gallery\\Services\\duplicate_photo_detector_group_key_compare');
    return $normalized;
}

/**
 * Return whether one completed detector job currently contains an image in a duplicate group.
 *
 * @param array<string,mixed> $job Detector job state.
 * @param int $imageId Image identifier to verify.
 * @return bool True when the image is still a member of an exact or possible group.
 */
function duplicate_photo_detector_job_contains_image(array $job, int $imageId): bool
{
    if ($imageId <= 0) {
        return false;
    }

    foreach (['exact_groups', 'possible_groups'] as $groupKey) {
        foreach ((array) ($job[$groupKey] ?? []) as $ids) {
            $memberIds = array_map('intval', is_array($ids) ? $ids : []);
            if (in_array($imageId, $memberIds, true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Return whether two images belong to the same current detector finding.
 *
 * @param array<string,mixed> $job Detector job state.
 * @param int $firstImageId First image identifier.
 * @param int $secondImageId Second image identifier.
 * @return bool True when both ids coexist in one exact or possible match group.
 */
function duplicate_photo_detector_job_contains_pair(array $job, int $firstImageId, int $secondImageId): bool
{
    if ($firstImageId <= 0 || $secondImageId <= 0 || $firstImageId === $secondImageId) {
        return false;
    }

    foreach (['exact_groups', 'possible_groups'] as $groupKey) {
        foreach ((array) ($job[$groupKey] ?? []) as $ids) {
            $memberIds = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
            if (in_array($firstImageId, $memberIds, true) && in_array($secondImageId, $memberIds, true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Return whether the immutable detector scope still permits one current gallery.
 *
 * Global jobs permit every gallery available to the authenticated administrator.
 * Local jobs remain restricted to the selected gallery branch snapshot captured
 * when the detector job started.
 *
 * @param array<string,mixed> $job Detector job state.
 * @param int $galleryId Current gallery identifier for the image.
 * @return bool True when the current gallery remains inside the detector scope.
 */
function duplicate_photo_detector_job_allows_gallery(array $job, int $galleryId): bool
{
    if ($galleryId <= 0) {
        return false;
    }
    if (!empty($job['search_all'])) {
        return true;
    }

    return in_array($galleryId, duplicate_photo_detector_job_gallery_ids($job), true);
}

/**
 * Remove one image id from completed duplicate groups after an explicit Admin deletion.
 *
 * Groups that fall below two surviving members are removed immediately so result
 * counts, pagination, and freshly rendered panel HTML remain accurate.
 *
 * @param array<string,mixed> $job Completed detector job state.
 * @param int $imageId Deleted image identifier.
 * @return array<string,mixed> Updated detector job state.
 */
function duplicate_photo_detector_prune_image_from_job(array $job, int $imageId): array
{
    if ($imageId <= 0) {
        return $job;
    }

    foreach (['exact_groups', 'possible_groups'] as $groupKey) {
        $groups = [];
        foreach ((array) ($job[$groupKey] ?? []) as $key => $ids) {
            $remainingIds = array_values(array_filter(
                array_map('intval', is_array($ids) ? $ids : []),
                static fn (int $memberId): bool => $memberId > 0 && $memberId !== $imageId
            ));
            $groups[(string) $key] = $remainingIds;
        }
        $job[$groupKey] = duplicate_photo_detector_normalize_group_map($groups);
    }
    if (isset($job['image_gallery_ids']) && is_array($job['image_gallery_ids'])) {
        unset($job['image_gallery_ids'][$imageId], $job['image_gallery_ids'][(string) $imageId]);
    }

    $job['updated_at'] = time();
    return $job;
}

/**
 * Remove one deleted image from a persisted detector session job.
 *
 * @param string $token Opaque detector job token.
 * @param int $imageId Deleted image identifier.
 * @return array<string,mixed>|null Updated job, or null when the job expired.
 */
function duplicate_photo_detector_remove_image_from_job(string $token, int $imageId): ?array
{
    $job = duplicate_photo_detector_read_job($token);
    if ($job === null) {
        return null;
    }

    $job = duplicate_photo_detector_prune_image_from_job($job, $imageId);
    duplicate_photo_detector_write_job($job);
    return $job;
}

/**
 * Return whether one integer is a valid positive image identifier.
 *
 * @param int $id Image identifier candidate.
 * @return bool True for positive ids.
 */
function duplicate_photo_detector_positive_id(int $id): bool
{
    return $id > 0;
}

/**
 * Compare deterministic group keys for stable ordering.
 *
 * @param string $left Left group key.
 * @param string $right Right group key.
 * @return int Comparison result.
 */
function duplicate_photo_detector_group_key_compare(string $left, string $right): int
{
    return $left <=> $right;
}

/**
 * Count images in the immutable detector scope and snapshot its highest id.
 *
 * @param array{gallery_id:int,search_all:bool} $scope Resolved detector scope.
 * @param array<int,int> $galleryIds Immutable local gallery-branch ids.
 * @return array{total:int,max_image_id:int} Snapshot bounds for the job.
 */
function duplicate_photo_detector_scope_snapshot(array $scope, array $galleryIds = []): array
{
    if ($scope['search_all']) {
        $stmt = db()->prepare('SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS max_image_id FROM images');
        $stmt->execute();
    } else {
        $galleryIds = array_values(array_unique(array_filter(
            array_map('intval', $galleryIds),
            'Gallery\\Services\\duplicate_photo_detector_positive_id'
        )));
        if ($galleryIds === []) {
            $galleryIds = [(int) $scope['gallery_id']];
        }

        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        $stmt = db()->prepare("SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS max_image_id FROM images WHERE gallery_id IN ($placeholders)");
        $stmt->execute($galleryIds);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total' => max(0, (int) ($row['total'] ?? 0)),
        'max_image_id' => max(0, (int) ($row['max_image_id'] ?? 0)),
    ];
}

/**
 * Start one bounded duplicate-photo detector job in the administrator session.
 *
 * @param array{gallery_id:int,search_all:bool} $scope Resolved immutable detector scope.
 * @return array<string,mixed> Public detector job state.
 */
function duplicate_photo_detector_start_job(array $scope): array
{
    duplicate_photo_detector_cleanup_jobs();
    $galleryIds = !empty($scope['search_all'])
        ? []
        : duplicate_photo_detector_gallery_branch_ids((int) $scope['gallery_id']);
    $snapshot = duplicate_photo_detector_scope_snapshot($scope, $galleryIds);
    $token = bin2hex(random_bytes(16));
    $job = [
        'token' => $token,
        'status' => 'running',
        'started_at' => time(),
        'updated_at' => time(),
        'gallery_id' => (int) $scope['gallery_id'],
        'gallery_ids' => $galleryIds,
        'search_all' => (bool) $scope['search_all'],
        'total' => (int) $snapshot['total'],
        'max_image_id' => (int) $snapshot['max_image_id'],
        'cursor' => 0,
        'processed' => 0,
        'exact_first' => [],
        'exact_groups' => [],
        'possible_first' => [],
        'possible_groups' => [],
        'image_gallery_ids' => [],
    ];

    if ((int) $snapshot['total'] === 0) {
        $job = duplicate_photo_detector_finalize_job($job);
    }

    duplicate_photo_detector_write_job($job);
    return duplicate_photo_detector_public_state($job);
}

/**
 * Process one bounded detector batch using only the immutable server-side job scope.
 *
 * @param string $token Session job token supplied by the browser.
 * @param int $batchSize Maximum rows to inspect in this request.
 * @return array<string,mixed> Public detector job state.
 */
function duplicate_photo_detector_process_job(string $token, int $batchSize = DUPLICATE_PHOTO_DETECTOR_DEFAULT_BATCH_SIZE): array
{
    $job = duplicate_photo_detector_read_job($token);
    if ($job === null) {
        return duplicate_photo_detector_missing_state();
    }
    if ((string) ($job['status'] ?? '') === 'complete') {
        return duplicate_photo_detector_public_state($job);
    }

    $batchSize = max(1, min(DUPLICATE_PHOTO_DETECTOR_MAX_BATCH_SIZE, $batchSize));
    $rows = duplicate_photo_detector_fetch_batch($job, $batchSize);
    duplicate_photo_detector_process_rows($job, $rows);

    if ($rows !== []) {
        $lastRow = $rows[count($rows) - 1];
        $job['cursor'] = max((int) ($job['cursor'] ?? 0), (int) ($lastRow['id'] ?? 0));
        $job['processed'] = min((int) ($job['total'] ?? 0), (int) ($job['processed'] ?? 0) + count($rows));
    }
    $job['updated_at'] = time();

    if ($rows === [] || count($rows) < $batchSize || (int) ($job['cursor'] ?? 0) >= (int) ($job['max_image_id'] ?? 0)) {
        $job['processed'] = (int) ($job['total'] ?? $job['processed'] ?? 0);
        $job = duplicate_photo_detector_finalize_job($job);
    }

    duplicate_photo_detector_write_job($job);
    return duplicate_photo_detector_public_state($job);
}

/**
 * Fetch one bounded metadata batch for an existing detector job.
 *
 * The query reads only database metadata already populated by the scanner. It
 * does not reopen image files or re-extract EXIF data.
 *
 * @param array<string,mixed> $job Existing detector job state.
 * @param int $batchSize Bounded row count.
 * @return array<int,array<string,mixed>> Stored image metadata rows.
 */
function duplicate_photo_detector_fetch_batch(array $job, int $batchSize): array
{
    $columns = 'id, gallery_id, checksum_sha256, file_size, width, height, mime_type, exif_taken_at, exif_camera_make, exif_camera_model, exif_lens_model, exif_focal_length, exif_aperture, exif_exposure_time, exif_iso, gps_lat, gps_lng';
    $cursor = max(0, (int) ($job['cursor'] ?? 0));
    $maxImageId = max(0, (int) ($job['max_image_id'] ?? 0));
    $batchSize = max(1, min(DUPLICATE_PHOTO_DETECTOR_MAX_BATCH_SIZE, $batchSize));

    if (!empty($job['search_all'])) {
        $stmt = db()->prepare("SELECT $columns FROM images WHERE id > ? AND id <= ? ORDER BY id ASC LIMIT ?");
        $stmt->bindValue(1, $cursor, PDO::PARAM_INT);
        $stmt->bindValue(2, $maxImageId, PDO::PARAM_INT);
        $stmt->bindValue(3, $batchSize, PDO::PARAM_INT);
    } else {
        $galleryIds = duplicate_photo_detector_job_gallery_ids($job);
        if ($galleryIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        $stmt = db()->prepare("SELECT $columns FROM images WHERE gallery_id IN ($placeholders) AND id > ? AND id <= ? ORDER BY id ASC LIMIT ?");
        $parameter = 1;
        foreach ($galleryIds as $galleryId) {
            $stmt->bindValue($parameter++, $galleryId, PDO::PARAM_INT);
        }
        $stmt->bindValue($parameter++, $cursor, PDO::PARAM_INT);
        $stmt->bindValue($parameter++, $maxImageId, PDO::PARAM_INT);
        $stmt->bindValue($parameter, $batchSize, PDO::PARAM_INT);
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Return the immutable public state for one detector job.
 *
 * @param array<string,mixed> $job Detector job state.
 * @return array<string,mixed> Public state safe to send to the browser.
 */
function duplicate_photo_detector_public_state(array $job): array
{
    $total = max(0, (int) ($job['total'] ?? 0));
    $processed = max(0, min($total, (int) ($job['processed'] ?? 0)));
    $complete = (string) ($job['status'] ?? '') === 'complete';
    $percent = $total > 0 ? round(($processed / $total) * 100, 1) : ($complete ? 100.0 : 0.0);

    return [
        'ok' => true,
        'job_token' => (string) ($job['token'] ?? ''),
        'status' => (string) ($job['status'] ?? 'running'),
        'done' => $complete,
        'processed' => $processed,
        'total' => $total,
        'percent' => $percent,
        'gallery_id' => (int) ($job['gallery_id'] ?? 0),
        'search_all' => !empty($job['search_all']),
        'exact_group_count' => count((array) ($job['exact_groups'] ?? [])),
        'possible_group_count' => count((array) ($job['possible_groups'] ?? [])),
    ];
}

/**
 * Return the public state used when a detector session job is missing or expired.
 *
 * @return array<string,mixed> Missing-job response state.
 */
function duplicate_photo_detector_missing_state(): array
{
    return [
        'ok' => false,
        'job_token' => '',
        'status' => 'missing',
        'done' => true,
        'processed' => 0,
        'total' => 0,
        'percent' => 0.0,
        'gallery_id' => 0,
        'search_all' => false,
        'exact_group_count' => 0,
        'possible_group_count' => 0,
    ];
}

/**
 * Read one duplicate detector job from the administrator session.
 *
 * @param string $token Browser-supplied opaque token.
 * @return array<string,mixed>|null Detector job, or null when missing/expired.
 */
function duplicate_photo_detector_read_job(string $token): ?array
{
    $token = preg_replace('/[^A-Fa-f0-9]/', '', $token) ?: '';
    if ($token === '' || empty($_SESSION['admin_duplicate_photo_detector_jobs'][$token]) || !is_array($_SESSION['admin_duplicate_photo_detector_jobs'][$token])) {
        return null;
    }

    $job = $_SESSION['admin_duplicate_photo_detector_jobs'][$token];
    if (time() - (int) ($job['updated_at'] ?? $job['started_at'] ?? 0) > DUPLICATE_PHOTO_DETECTOR_JOB_TTL_SECONDS) {
        unset($_SESSION['admin_duplicate_photo_detector_jobs'][$token]);
        return null;
    }

    return $job;
}

/**
 * Persist one duplicate detector job in the administrator session.
 *
 * @param array<string,mixed> $job Detector job state.
 */
function duplicate_photo_detector_write_job(array $job): void
{
    $token = preg_replace('/[^A-Fa-f0-9]/', '', (string) ($job['token'] ?? '')) ?: '';
    if ($token === '') {
        return;
    }

    if (!isset($_SESSION['admin_duplicate_photo_detector_jobs']) || !is_array($_SESSION['admin_duplicate_photo_detector_jobs'])) {
        $_SESSION['admin_duplicate_photo_detector_jobs'] = [];
    }
    $_SESSION['admin_duplicate_photo_detector_jobs'][$token] = $job;
}

/**
 * Remove expired and excess duplicate detector jobs from the admin session.
 */
function duplicate_photo_detector_cleanup_jobs(): void
{
    if (empty($_SESSION['admin_duplicate_photo_detector_jobs']) || !is_array($_SESSION['admin_duplicate_photo_detector_jobs'])) {
        $_SESSION['admin_duplicate_photo_detector_jobs'] = [];
        return;
    }

    foreach ($_SESSION['admin_duplicate_photo_detector_jobs'] as $token => $job) {
        if (!is_array($job) || time() - (int) ($job['updated_at'] ?? $job['started_at'] ?? 0) > DUPLICATE_PHOTO_DETECTOR_JOB_TTL_SECONDS) {
            unset($_SESSION['admin_duplicate_photo_detector_jobs'][$token]);
        }
    }

    if (count($_SESSION['admin_duplicate_photo_detector_jobs']) <= DUPLICATE_PHOTO_DETECTOR_MAX_SESSION_JOBS) {
        return;
    }

    uasort($_SESSION['admin_duplicate_photo_detector_jobs'], 'Gallery\\Services\\duplicate_photo_detector_job_recency_compare');
    $_SESSION['admin_duplicate_photo_detector_jobs'] = array_slice($_SESSION['admin_duplicate_photo_detector_jobs'], 0, DUPLICATE_PHOTO_DETECTOR_MAX_SESSION_JOBS, true);
}

/**
 * Compare detector jobs by most-recent update time.
 *
 * @param array<string,mixed> $left Left job.
 * @param array<string,mixed> $right Right job.
 * @return int Comparison result.
 */
function duplicate_photo_detector_job_recency_compare(array $left, array $right): int
{
    return (int) ($right['updated_at'] ?? 0) <=> (int) ($left['updated_at'] ?? 0);
}

/**
 * Return deterministic duplicate group references for one completed job.
 *
 * @param array<string,mixed> $job Completed detector job.
 * @return array<int,array{key:string,confidence:string,ids:array<int,int>,min_id:int}> Ordered group references.
 */
function duplicate_photo_detector_group_references(array $job): array
{
    $groups = [];
    foreach ((array) ($job['exact_groups'] ?? []) as $key => $ids) {
        $cleanIds = array_values(array_map('intval', is_array($ids) ? $ids : []));
        if (count($cleanIds) >= 2) {
            sort($cleanIds, SORT_NUMERIC);
            $groups[] = [
                'key' => (string) $key,
                'confidence' => 'exact',
                'ids' => $cleanIds,
                'min_id' => $cleanIds[0],
            ];
        }
    }
    foreach ((array) ($job['possible_groups'] ?? []) as $key => $ids) {
        $cleanIds = array_values(array_map('intval', is_array($ids) ? $ids : []));
        if (count($cleanIds) >= 2) {
            sort($cleanIds, SORT_NUMERIC);
            $groups[] = [
                'key' => (string) $key,
                'confidence' => 'possible',
                'ids' => $cleanIds,
                'min_id' => $cleanIds[0],
            ];
        }
    }

    usort($groups, 'Gallery\\Services\\duplicate_photo_detector_group_reference_compare');
    return $groups;
}

/**
 * Compare group references by confidence, lowest image id, and key.
 *
 * @param array<string,mixed> $left Left group reference.
 * @param array<string,mixed> $right Right group reference.
 * @return int Comparison result.
 */
function duplicate_photo_detector_group_reference_compare(array $left, array $right): int
{
    $rank = ['exact' => 0, 'possible' => 1];
    $leftRank = $rank[(string) ($left['confidence'] ?? '')] ?? 99;
    $rightRank = $rank[(string) ($right['confidence'] ?? '')] ?? 99;
    if ($leftRank !== $rightRank) {
        return $leftRank <=> $rightRank;
    }

    $idCompare = (int) ($left['min_id'] ?? 0) <=> (int) ($right['min_id'] ?? 0);
    return $idCompare !== 0 ? $idCompare : ((string) ($left['key'] ?? '') <=> (string) ($right['key'] ?? ''));
}

/**
 * Fetch detailed image and gallery rows for bounded result ids.
 *
 * @param array<int,int> $imageIds Image identifiers from the server-side job.
 * @return array<int,array<string,mixed>> Rows keyed by image id.
 */
function duplicate_photo_detector_fetch_images_by_ids(array $imageIds): array
{
    $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), 'Gallery\\Services\\duplicate_photo_detector_positive_id')));
    if ($imageIds === []) {
        return [];
    }
    if (count($imageIds) > DUPLICATE_PHOTO_DETECTOR_GROUPS_PER_PAGE * DUPLICATE_PHOTO_DETECTOR_MAX_GROUP_MEMBERS) {
        $imageIds = array_slice($imageIds, 0, DUPLICATE_PHOTO_DETECTOR_GROUPS_PER_PAGE * DUPLICATE_PHOTO_DETECTOR_MAX_GROUP_MEMBERS);
    }

    $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
    $stmt = db()->prepare("SELECT i.id, i.gallery_id, i.relative_path, i.filename, i.url_slug, i.width, i.height, i.mime_type, i.file_size, i.checksum_sha256, i.exif_taken_at, i.exif_camera_make, i.exif_camera_model, i.exif_lens_model, i.exif_focal_length, i.exif_aperture, i.exif_exposure_time, i.exif_iso, i.gps_lat, i.gps_lng, i.visibility, g.title AS gallery_title, g.folder_path AS gallery_folder_path, g.slug AS gallery_slug, g.url_path AS gallery_url_path FROM images i INNER JOIN galleries g ON g.id = i.gallery_id WHERE i.id IN ($placeholders)");
    foreach ($imageIds as $index => $imageId) {
        $stmt->bindValue($index + 1, $imageId, PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[(int) ($row['id'] ?? 0)] = $row;
    }
    return $rows;
}

/**
 * Return common meaningful EXIF components shared by every row in a group.
 *
 * @param array<int,array<string,mixed>> $rows Detailed image rows.
 * @return array<string,string> Common normalized EXIF evidence.
 */
function duplicate_photo_detector_common_exif_components(array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $common = duplicate_photo_detector_exif_components($rows[0]);
    foreach (array_slice($rows, 1) as $row) {
        $components = duplicate_photo_detector_exif_components($row);
        foreach ($common as $name => $value) {
            if (!isset($components[$name]) || $components[$name] !== $value) {
                unset($common[$name]);
            }
        }
    }
    return $common;
}

/**
 * Determine whether non-empty normalized EXIF metadata is mutually compatible.
 *
 * Missing fields are ignored, but two non-empty values for the same field must
 * agree. At least one meaningful EXIF component must be shared by every row so
 * absence alone never becomes corroborating evidence.
 *
 * @param array<int,array<string,mixed>> $rows Detailed image rows.
 * @return bool True when meaningful EXIF evidence is shared without conflicts.
 */
function duplicate_photo_detector_exif_metadata_compatible(array $rows): bool
{
    if (count($rows) < 2) {
        return false;
    }

    $seen = [];
    foreach ($rows as $row) {
        foreach (duplicate_photo_detector_exif_components($row) as $name => $value) {
            if (isset($seen[$name]) && $seen[$name] !== $value) {
                return false;
            }
            $seen[$name] = $value;
        }
    }

    return duplicate_photo_detector_common_exif_components($rows) !== [];
}

/**
 * Determine whether every image has the same positive file size.
 *
 * @param array<int,array<string,mixed>> $rows Detailed image rows.
 * @return bool True when all rows share one meaningful size.
 */
function duplicate_photo_detector_same_file_size(array $rows): bool
{
    if (count($rows) < 2) {
        return false;
    }
    $first = (int) ($rows[0]['file_size'] ?? 0);
    if ($first <= 0) {
        return false;
    }
    foreach (array_slice($rows, 1) as $row) {
        if ((int) ($row['file_size'] ?? 0) !== $first) {
            return false;
        }
    }
    return true;
}

/**
 * Determine whether every image has the same positive pixel dimensions.
 *
 * @param array<int,array<string,mixed>> $rows Detailed image rows.
 * @return bool True when all rows share width and height.
 */
function duplicate_photo_detector_same_dimensions(array $rows): bool
{
    if (count($rows) < 2) {
        return false;
    }
    $width = (int) ($rows[0]['width'] ?? 0);
    $height = (int) ($rows[0]['height'] ?? 0);
    if ($width <= 0 || $height <= 0) {
        return false;
    }
    foreach (array_slice($rows, 1) as $row) {
        if ((int) ($row['width'] ?? 0) !== $width || (int) ($row['height'] ?? 0) !== $height) {
            return false;
        }
    }
    return true;
}

/**
 * Determine whether every image has one matching non-empty normalized checksum.
 *
 * @param array<int,array<string,mixed>> $rows Detailed image rows.
 * @return bool True when all rows share a valid SHA-256 checksum.
 */
function duplicate_photo_detector_same_checksum(array $rows): bool
{
    if (count($rows) < 2) {
        return false;
    }
    $checksum = duplicate_photo_detector_normalize_checksum($rows[0]['checksum_sha256'] ?? null);
    if ($checksum === null) {
        return false;
    }
    foreach (array_slice($rows, 1) as $row) {
        if (duplicate_photo_detector_normalize_checksum($row['checksum_sha256'] ?? null) !== $checksum) {
            return false;
        }
    }
    return true;
}

/**
 * Build machine-readable matching signals for one displayed result group.
 *
 * Strong-candidate corroboration is reported only inside an exact SHA-256
 * group, because matching checksums already prove byte-for-byte identity. The
 * additional label makes the requested checksum, size, dimensions, and EXIF
 * corroboration visible without downgrading an exact match to a candidate.
 *
 * @param array<int,array<string,mixed>> $rows Detailed image rows.
 * @param string $confidence Primary group confidence: exact or possible.
 * @return array<string,mixed> Group signal summary.
 */
function duplicate_photo_detector_group_signals(array $rows, string $confidence): array
{
    $sameChecksum = duplicate_photo_detector_same_checksum($rows);
    $sameFileSize = duplicate_photo_detector_same_file_size($rows);
    $sameDimensions = duplicate_photo_detector_same_dimensions($rows);
    $commonExif = duplicate_photo_detector_common_exif_components($rows);
    $strongCorroboration = $confidence === 'exact'
        && $sameChecksum
        && $sameFileSize
        && $sameDimensions
        && duplicate_photo_detector_exif_metadata_compatible($rows);

    return [
        'same_checksum' => $sameChecksum,
        'same_file_size' => $sameFileSize,
        'same_dimensions' => $sameDimensions,
        'common_exif' => $commonExif,
        'strong_corroboration' => $strongCorroboration,
    ];
}

/**
 * Expand completed detector groups into deterministic pair references.
 *
 * Exact groups are traversed before possible groups, and repeated pair
 * relationships are emitted only once. Persistent ledger rules are applied
 * before pagination. Gallery ledger rules match exact gallery ids only.
 *
 * A hard reference cap prevents pathological duplicate groups from expanding
 * into unbounded O(n^2) work during one panel render. When the cap is reached,
 * the result model reports truncation explicitly.
 *
 * @param array<string,mixed> $job Completed detector job.
 * @param array<string,mixed> $ledger Persistent ledger snapshot.
 * @return array{references:array<int,array<string,mixed>>,truncated:bool} Pair reference state.
 */
function duplicate_photo_detector_pair_references(array $job, array $ledger = []): array
{
    $references = [];
    $seenPairs = [];
    $galleryMap = is_array($job['image_gallery_ids'] ?? null) ? $job['image_gallery_ids'] : [];
    $truncated = false;
    $consideredPairs = 0;

    foreach (duplicate_photo_detector_group_references($job) as $group) {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($group['ids'] ?? null) ? $group['ids'] : []),
            'Gallery\\Services\\duplicate_photo_detector_positive_id'
        )));
        sort($ids, SORT_NUMERIC);
        $memberCount = count($ids);
        if ($memberCount < 2) {
            continue;
        }

        for ($leftIndex = 0; $leftIndex < $memberCount - 1; $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < $memberCount; $rightIndex++) {
                $consideredPairs++;
                if ($consideredPairs > DUPLICATE_PHOTO_DETECTOR_MAX_PAIR_REFERENCES) {
                    $truncated = true;
                    break 3;
                }

                $leftImageId = $ids[$leftIndex];
                $rightImageId = $ids[$rightIndex];
                $pairKey = duplicate_photo_ledger_pair_key($leftImageId, $rightImageId);
                if (isset($seenPairs[$pairKey])) {
                    continue;
                }
                $seenPairs[$pairKey] = true;

                $leftGalleryId = (int) ($galleryMap[$leftImageId] ?? $galleryMap[(string) $leftImageId] ?? 0);
                $rightGalleryId = (int) ($galleryMap[$rightImageId] ?? $galleryMap[(string) $rightImageId] ?? 0);
                if (duplicate_photo_ledger_ignores_pair($ledger, $leftImageId, $rightImageId, $leftGalleryId, $rightGalleryId)) {
                    continue;
                }

                $references[] = [
                    'key' => (string) ($group['key'] ?? ''),
                    'pair_key' => $pairKey,
                    'confidence' => (string) ($group['confidence'] ?? 'possible'),
                    'ids' => [$leftImageId, $rightImageId],
                    'min_id' => $leftImageId,
                    'max_id' => $rightImageId,
                    'source_member_count' => $memberCount,
                ];
            }
        }
    }

    return [
        'references' => $references,
        'truncated' => $truncated,
    ];
}

/**
 * Build one bounded, deterministic page of detailed duplicate groups.
 *
 * @param array<string,mixed> $job Completed detector job.
 * @param int $page One-based result page number.
 * @return array<string,mixed> Page model for the renderer.
 */

/**
 * Build one bounded, deterministic page of detailed duplicate pairs.
 *
 * The matching engine keeps efficient checksum/EXIF groups internally, but the
 * administrator sees pair comparisons so a reviewed relationship can be added
 * to the persistent ledger without suppressing unrelated members of a larger
 * duplicate group.
 *
 * @param array<string,mixed> $job Completed detector job.
 * @param int $page One-based result page number.
 * @param array<string,mixed> $ledger Persistent ledger snapshot.
 * @return array<string,mixed> Page model for the renderer.
 */
function duplicate_photo_detector_result_page(array $job, int $page = 1, array $ledger = []): array
{
    $pairState = duplicate_photo_detector_pair_references($job, $ledger);
    $references = $pairState['references'];
    $totalPairs = count($references);
    $perPage = DUPLICATE_PHOTO_DETECTOR_PAIRS_PER_PAGE;
    $pageCount = max(1, (int) ceil($totalPairs / $perPage));
    $page = max(1, min($pageCount, $page));
    $pageReferences = array_slice($references, ($page - 1) * $perPage, $perPage);

    $detailIds = [];
    foreach ($pageReferences as $reference) {
        foreach ((array) ($reference['ids'] ?? []) as $imageId) {
            $detailIds[] = (int) $imageId;
        }
    }
    $rowsById = duplicate_photo_detector_fetch_images_by_ids($detailIds);

    $groups = [];
    $exactPairCount = 0;
    $possiblePairCount = 0;
    foreach ($references as $reference) {
        if ((string) ($reference['confidence'] ?? '') === 'exact') {
            $exactPairCount++;
        } else {
            $possiblePairCount++;
        }
    }

    foreach ($pageReferences as $reference) {
        $rows = [];
        foreach ((array) ($reference['ids'] ?? []) as $imageId) {
            $imageId = (int) $imageId;
            if (isset($rowsById[$imageId])) {
                $rows[] = $rowsById[$imageId];
            }
        }
        if (count($rows) !== 2) {
            continue;
        }

        $confidence = (string) ($reference['confidence'] ?? 'possible');
        $signals = duplicate_photo_detector_group_signals($rows, $confidence);
        // Strong corroboration must describe the whole exact group, not only the bounded visible subset.
        if ($confidence === 'exact' && (int) ($reference['source_member_count'] ?? 2) > 2) {
            $signals['strong'] = false;
        }

        $groups[] = [
            'key' => (string) ($reference['key'] ?? ''),
            'pair_key' => (string) ($reference['pair_key'] ?? ''),
            'confidence' => $confidence,
            'images' => $rows,
            'signals' => $signals,
            'member_count' => 2,
            'source_member_count' => (int) ($reference['source_member_count'] ?? 2),
            'truncated' => false,
        ];
    }

    return [
        'groups' => $groups,
        'total_groups' => $totalPairs,
        'total_pairs' => $totalPairs,
        'exact_pair_count' => $exactPairCount,
        'possible_pair_count' => $possiblePairCount,
        'reference_limit_reached' => !empty($pairState['truncated']),
        'page' => $page,
        'page_count' => $pageCount,
        'per_page' => $perPage,
    ];
}
