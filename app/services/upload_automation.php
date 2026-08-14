<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/upload_automation.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for API-key based upload automation.
 *
 * Responsibilities:
 *   - Generate and store one-way hashed gallery upload API keys
 *   - Resolve API keys to a single allowed gallery
 *   - Keep automation authorization separate from browser sessions
 *   - Avoid reimplementing gallery upload, scan, and thumbnail behavior
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
 *   2026-05-16
 */

declare(strict_types=1);

namespace Gallery\Services;

use DirectoryIterator;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;

/**
 * Upload automation service model.
 *
 * Tokens are scoped to one gallery. The raw token is shown only once during
 * generation, while only a SHA-256 hash is stored in the database.
 */

/**
 * Return whether the upload automation token table is available.
 *
 * @return bool True when the condition matches.
 */
function upload_automation_schema_ready(): bool
{
    return schema_inspection_is_available(upload_automation_schema_status());
}

/**
 * Return the visible prefix used for newly generated upload API keys.
 *
 * @return string Text result for the caller.
 */
function upload_automation_token_prefix(): string
{
    return 'pgu_';
}

/**
 * Generate a new raw API key for one gallery upload automation configuration.
 *
 * @return string Text result for the caller.
 */
function upload_automation_generate_token_value(): string
{
    return upload_automation_token_prefix() . bin2hex(random_bytes(32));
}

/**
 * Return the stable database hash for one raw API key.
 *
 * @param string $token Token value.
 * @return string Text result for the caller.
 */
function upload_automation_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

/**
 * Normalize the optional human label stored with an API key.
 *
 * @param string $label Label value.
 * @return string Text result for the caller.
 */
function upload_automation_normalize_label(string $label): string
{
    // $label stores the compact label that will be visible in the gallery editor.
    $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
    if ($label === '') {
        return 'Folder watcher';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($label, 0, 190);
    }
    return substr($label, 0, 190);
}

/**
 * Create a new active API key for one gallery and return the raw value once.
 *
 * @param int $galleryId Gallery identifier.
 * @param ?int $createdByUserId Created by user id identifier.
 * @param string $label Label value.
 * @return array{token:string,id:int,label:string} Structured result data for the caller.
 */
function create_gallery_upload_automation_token(int $galleryId, ?int $createdByUserId, string $label = ''): array
{
    mutation_schema_assert_available(
        upload_automation_schema_status(),
        'upload_automation.create_token',
        t('upload_automation.error.migration_required', 'Upload automation database table is missing. Run pending migrations first.'),
        t('upload_automation.error.schema_unknown', 'Upload automation is temporarily unavailable because its database schema could not be verified. No API key was created.')
    );

    // $gallery stores the gallery that owns the new API key.
    $gallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException(t('gallery.error.not_found', 'Gallery not found.'));
    }

    // $token stores the raw value that is shown to the admin only once.
    $token = upload_automation_generate_token_value();
    // $normalizedLabel stores the display label saved beside the hash.
    $normalizedLabel = upload_automation_normalize_label($label);
    // $stmt stores the insert for the one-way hashed API key.
    $stmt = db()->prepare('INSERT INTO gallery_upload_tokens (gallery_id, token_hash, label, active, created_by_user_id, created_at) VALUES (?, ?, ?, 1, ?, ?)');
    $stmt->execute([
        $galleryId,
        upload_automation_token_hash($token),
        $normalizedLabel,
        $createdByUserId,
        now_sql(),
    ]);

    return [
        'token' => $token,
        'id' => (int) db()->lastInsertId(),
        'label' => $normalizedLabel,
    ];
}

/**
 * Return active upload automation API keys for one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @return array<int array<string, mixed>>.
 */
function gallery_upload_automation_tokens(int $galleryId): array
{
    $schemaStatus = upload_automation_schema_status();
    if (schema_inspection_is_missing($schemaStatus)) {
        return [];
    }
    mutation_schema_assert_known(
        $schemaStatus,
        'upload_automation.list_tokens',
        t('upload_automation.error.schema_unknown', 'Upload automation is temporarily unavailable because its database schema could not be verified.')
    );

    // $stmt stores the active tokens listed in the gallery editor.
    $stmt = db()->prepare('SELECT id, gallery_id, label, active, created_at, last_used_at, revoked_at FROM gallery_upload_tokens WHERE gallery_id = ? AND active = 1 AND revoked_at IS NULL ORDER BY created_at DESC, id DESC');
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return active upload automation API keys across all galleries.
 *
 * @return array<int array<string, mixed>>.
 */
function upload_automation_tokens_for_manager(): array
{
    $schemaStatus = upload_automation_schema_status();
    if (schema_inspection_is_missing($schemaStatus)) {
        return [];
    }
    mutation_schema_assert_known(
        $schemaStatus,
        'upload_automation.list_tokens',
        t('upload_automation.error.schema_unknown', 'Upload automation is temporarily unavailable because its database schema could not be verified.')
    );

    // $sql stores the manager query. The users table in the base schema has
    // username but no display_name column, so both admin identity aliases use
    // username to keep the query compatible with existing installations.
    $sql = 'SELECT t.id, t.gallery_id, t.label, t.active, t.created_at, t.last_used_at, t.revoked_at, g.title AS gallery_title, g.slug AS gallery_slug, u.username AS created_by_username, u.username AS created_by_display_name
            FROM gallery_upload_tokens t
            INNER JOIN galleries g ON g.id = t.gallery_id
            LEFT JOIN users u ON u.id = t.created_by_user_id
            WHERE t.active = 1 AND t.revoked_at IS NULL
            ORDER BY g.title ASC, t.created_at DESC, t.id DESC';
    $stmt = db()->query($sql);
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

/**
 * Revoke one upload automation API key for a gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $tokenId Token id identifier.
 * @return bool True when the condition matches.
 */
function revoke_gallery_upload_automation_token(int $galleryId, int $tokenId): bool
{
    mutation_schema_assert_available(
        upload_automation_revocation_schema_status(),
        'upload_automation.revoke_token',
        t('upload_automation.error.migration_required', 'Upload automation database table is missing. Run pending migrations first.'),
        t('upload_automation.error.schema_unknown', 'Upload automation is temporarily unavailable because the API-key revocation schema could not be verified. No API key was changed.')
    );

    // $stmt stores the revoke update. The gallery predicate prevents cross-gallery revocation.
    $stmt = db()->prepare('UPDATE gallery_upload_tokens SET active = 0, revoked_at = ? WHERE id = ? AND gallery_id = ?');
    $stmt->execute([now_sql(), $tokenId, $galleryId]);
    return $stmt->rowCount() > 0;
}

/**
 * Resolve a raw API key into the active token row that authorizes an upload.
 *
 * @param string $token Token value.
 * @return ?array Structured result data for the caller.
 */
function find_upload_automation_token(string $token): ?array
{
    // $normalizedToken stores the trimmed token as sent by the watcher app.
    $normalizedToken = trim($token);
    if ($normalizedToken === '') {
        return null;
    }
    mutation_schema_assert_available(
        upload_automation_schema_status(),
        'upload_automation.authenticate',
        t('upload_automation.error.migration_required', 'Upload automation database table is missing. Run pending migrations first.'),
        t('upload_automation.error.schema_unknown', 'Upload automation authentication is temporarily unavailable because its database schema could not be verified.')
    );

    // $stmt stores the lookup by hash so raw API keys are never stored server-side.
    $stmt = db()->prepare('SELECT id, gallery_id, label, active, created_at, last_used_at FROM gallery_upload_tokens WHERE token_hash = ? AND active = 1 AND revoked_at IS NULL LIMIT 1');
    $stmt->execute([upload_automation_token_hash($normalizedToken)]);
    // $row stores the matching token, if any.
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Record the most recent successful use of one upload automation API key.
 *
 * @param int $tokenId Token id identifier.
 */
function mark_upload_automation_token_used(int $tokenId): void
{
    mutation_schema_assert_available(
        upload_automation_schema_status(),
        'upload_automation.mark_used',
        t('upload_automation.error.migration_required', 'Upload automation database table is missing. Run pending migrations first.'),
        t('upload_automation.error.schema_unknown', 'Upload automation usage metadata could not be updated because its database schema could not be verified.')
    );

    // $stmt stores a lightweight audit timestamp for the admin UI.
    $stmt = db()->prepare('UPDATE gallery_upload_tokens SET last_used_at = ? WHERE id = ?');
    $stmt->execute([now_sql(), $tokenId]);
}


/**
 * Normalize one client inventory row submitted by the companion app.
 *
 * Inventory rows are intentionally small. The client sends the original local
 * filename, byte size, and SHA-256 hash it already calculated before upload.
 * The gallery answers whether the scoped target gallery already contains the
 * same content, so interrupted transfer batches can continue without sending
 * confirmed files again.
 *
 * @param array<string,mixed> $row Client-submitted file descriptor.
 * @return array{client_id:string,filename:string,size:int,sha256:string}|null Normalized row or null when the row is unusable.
 */
function upload_automation_normalize_inventory_row(array $row): ?array
{
    // $sha256 stores the content hash supplied by the active gallery or companion app.
    $sha256 = strtolower(trim((string) ($row['sha256'] ?? '')));
    if ($sha256 === '' || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
        return null;
    }

    // $filename stores only a basename. Remote inventory never trusts client paths.
    $filename = basename(str_replace('\\', '/', trim((string) ($row['filename'] ?? ''))));
    if ($filename === '') {
        $filename = $sha256;
    }

    // $size stores the byte length as a defensive secondary match check.
    $size = max(0, (int) ($row['size'] ?? 0));
    // $clientId is echoed back so a caller can map responses without trusting filenames.
    $clientId = trim((string) ($row['client_id'] ?? ''));
    if ($clientId === '') {
        $clientId = $sha256;
    }

    return [
        'client_id' => $clientId,
        'filename' => $filename,
        'size' => $size,
        'sha256' => $sha256,
    ];
}

/**
 * Normalize a client-submitted inventory request.
 *
 * @param array<string,mixed> $payload Decoded JSON payload from the API request.
 * @return array<int,array{client_id:string,filename:string,size:int,sha256:string}> Clean candidate rows capped to a safe maximum.
 */
function upload_automation_inventory_candidates(array $payload): array
{
    // $rawRows stores the user-provided list from the JSON body.
    $rawRows = $payload['files'] ?? [];
    if (!is_array($rawRows)) {
        return [];
    }

    // $candidates stores de-duplicated rows keyed by content hash.
    $candidates = [];
    foreach ($rawRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidate = upload_automation_normalize_inventory_row($row);
        if ($candidate === null) {
            continue;
        }
        $candidates[$candidate['sha256']] = $candidate;
        if (count($candidates) >= 5000) {
            break;
        }
    }

    return array_values($candidates);
}

/**
 * Return a compact direct-image fingerprint for one gallery inventory response.
 *
 * @param int $galleryId Gallery being inspected.
 * @return string Stable fingerprint based on the current indexed direct images.
 */
function upload_automation_gallery_inventory_fingerprint(int $galleryId): string
{
    // $stmt stores a small aggregate used by clients for diagnostic logging.
    $stmt = db()->prepare("SELECT COUNT(*) AS image_count, COALESCE(MAX(updated_at), '') AS newest_update, COALESCE(SUM(COALESCE(file_size, 0)), 0) AS total_size FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'");
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch() ?: [];
    return hash('sha256', json_encode([
        'image_count' => (int) ($row['image_count'] ?? 0),
        'newest_update' => (string) ($row['newest_update'] ?? ''),
        'total_size' => (string) ($row['total_size'] ?? '0'),
    ], JSON_UNESCAPED_SLASHES));
}

/**
 * Return database matches for inventory candidates by SHA-256 checksum.
 *
 * @param int $galleryId Gallery whose direct images are searched.
 * @param array<int,array{client_id:string,filename:string,size:int,sha256:string}> $candidates Normalized client inventory candidates.
 * @return array<string,array<string,mixed>> Remote rows keyed by checksum.
 */
function upload_automation_inventory_db_matches(int $galleryId, array $candidates): array
{
    // $hashes stores the submitted content hashes that are cheap to compare with indexed image rows.
    $hashes = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['sha256'], $candidates)));
    if (!$hashes) {
        return [];
    }

    // $matches stores rows keyed by remote checksum_sha256.
    $matches = [];
    foreach (array_chunk($hashes, 250) as $hashChunk) {
        // $placeholders stores the prepared IN-list for this bounded chunk.
        $placeholders = implode(',', array_fill(0, count($hashChunk), '?'));
        $stmt = db()->prepare("SELECT id, filename, relative_path, file_size, checksum_sha256 FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%' AND checksum_sha256 IN ($placeholders)");
        $stmt->execute(array_merge([$galleryId], $hashChunk));
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $hash = strtolower((string) ($row['checksum_sha256'] ?? ''));
            if ($hash === '') {
                continue;
            }
            $matches[$hash] = [
                'image_id' => (int) ($row['id'] ?? 0),
                'filename' => (string) ($row['filename'] ?? ''),
                'relative_path' => (string) ($row['relative_path'] ?? ''),
                'file_size' => (int) ($row['file_size'] ?? 0),
                'sha256' => $hash,
                'matched_by' => 'checksum_sha256',
            ];
        }
    }

    return $matches;
}

/**
 * Return direct filesystem matches for inventory candidates that are not indexed yet.
 *
 * This catches the narrow failure case where an upload request stored the file on
 * disk but the HTTP response was lost before the client saw success. Only files
 * whose size appears in the submitted candidate set are hashed, which keeps the
 * reconnect probe bounded even in larger galleries.
 *
 * @param array<string,mixed> $gallery Gallery row that owns the scoped API key.
 * @param array<int,array{client_id:string,filename:string,size:int,sha256:string}> $candidates Normalized client inventory candidates.
 * @param array<string,array<string,mixed>> $knownMatches Existing checksum matches from the database.
 * @return array<string,array<string,mixed>> Additional remote rows keyed by checksum.
 */
function upload_automation_inventory_disk_matches(array $gallery, array $candidates, array $knownMatches): array
{
    // $missingBySize stores only hashes that still need a disk-level check.
    $missingBySize = [];
    foreach ($candidates as $candidate) {
        $hash = (string) $candidate['sha256'];
        if (isset($knownMatches[$hash])) {
            continue;
        }
        $size = (int) $candidate['size'];
        if ($size <= 0) {
            continue;
        }
        $missingBySize[$size][$hash] = true;
    }
    if (!$missingBySize) {
        return [];
    }

    // $root stores the gallery folder assigned to the authenticated API key.
    $root = gallery_abs_path((string) ($gallery['folder_path'] ?? ''));
    if (!is_dir($root)) {
        return [];
    }

    // $matches stores newly found disk matches keyed by content hash.
    $matches = [];
    foreach (new DirectoryIterator($root) as $file) {
        if (!$file->isFile() || !is_supported_image_path($file->getFilename())) {
            continue;
        }
        $size = $file->getSize();
        if (!isset($missingBySize[$size])) {
            continue;
        }
        $hash = strtolower((string) (hash_file('sha256', $file->getPathname()) ?: ''));
        if ($hash === '' || !isset($missingBySize[$size][$hash])) {
            continue;
        }
        $matches[$hash] = [
            'image_id' => 0,
            'filename' => $file->getFilename(),
            'relative_path' => normalize_relative_path($file->getFilename()),
            'file_size' => $size,
            'sha256' => $hash,
            'matched_by' => 'disk_sha256',
        ];
    }

    return $matches;
}

/**
 * Build the API response that tells the active side which files already exist.
 *
 * @param int $galleryId Gallery id authorized by the API key.
 * @param array<string,mixed> $gallery Gallery row authorized by the API key.
 * @param array<int,array{client_id:string,filename:string,size:int,sha256:string}> $candidates Normalized client inventory candidates.
 * @return array<string,mixed> JSON-safe inventory response.
 */
function upload_automation_gallery_inventory_response(int $galleryId, array $gallery, array $candidates): array
{
    // $dbMatches stores the normal indexed-image matches.
    $dbMatches = upload_automation_inventory_db_matches($galleryId, $candidates);
    // $diskMatches stores fallback direct-file matches for interrupted requests.
    $diskMatches = upload_automation_inventory_disk_matches($gallery, $candidates, $dbMatches);
    // $matches stores the combined remote truth keyed by content hash.
    $matches = array_replace($diskMatches, $dbMatches);

    // $existing stores rows the client can use to skip already transferred files.
    $existing = [];
    foreach ($candidates as $candidate) {
        $hash = (string) $candidate['sha256'];
        if (!isset($matches[$hash])) {
            continue;
        }
        $remote = $matches[$hash];
        $existing[] = [
            'client_id' => (string) $candidate['client_id'],
            'filename' => (string) $candidate['filename'],
            'size' => (int) $candidate['size'],
            'sha256' => $hash,
            'remote_filename' => (string) ($remote['filename'] ?? ''),
            'remote_relative_path' => (string) ($remote['relative_path'] ?? ''),
            'remote_image_id' => (int) ($remote['image_id'] ?? 0),
            'matched_by' => (string) ($remote['matched_by'] ?? 'checksum_sha256'),
        ];
    }

    return [
        'ok' => true,
        'action' => 'inventory',
        'gallery_id' => $galleryId,
        'gallery_folder' => (string) ($gallery['folder_path'] ?? ''),
        'checked' => count($candidates),
        'matched' => count($existing),
        'existing' => $existing,
        'fingerprint' => upload_automation_gallery_inventory_fingerprint($galleryId),
        'server_time' => now_sql(),
    ];
}

/**
 * Extract an upload automation API key from common HTTP locations.
 *
 * @return string Text result for the caller.
 */
function upload_automation_request_token(): string
{
    // $headerToken stores the preferred explicit API-key header.
    $headerToken = trim((string) ($_SERVER['HTTP_X_GALLERY_API_KEY'] ?? ''));
    if ($headerToken !== '') {
        return $headerToken;
    }

    // $authorization stores the standard bearer token header when forwarded by the web server.
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1) {
        return trim((string) $match[1]);
    }

    return trim((string) ($_POST['api_key'] ?? ''));
}

/**
 * Convert uploaded files from either images[] or image into the existing upload service shape.
 *
 * @return ?array Structured result data for the caller.
 */
function upload_automation_uploaded_files(): ?array
{
    if (isset($_FILES['images']) && is_array($_FILES['images'])) {
        return $_FILES['images'];
    }

    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        return null;
    }

    // $file stores the single-file upload shape used by simple clients.
    $file = $_FILES['image'];
    return [
        'name' => [(string) ($file['name'] ?? '')],
        'type' => [(string) ($file['type'] ?? '')],
        'tmp_name' => [(string) ($file['tmp_name'] ?? '')],
        'error' => [(int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)],
        'size' => [(int) ($file['size'] ?? 0)],
    ];
}

/**
 * Normalize one client-generated upload identifier.
 *
 * The identifier is not trusted for authorization. It is only a request-local
 * correlation value used to connect one original image with the thumbnail files
 * generated for it by the Windows companion app.
 *
 * @param string $clientId Client id identifier.
 * @return string Text result for the caller.
 */
function upload_automation_normalize_client_id(string $clientId): string
{
    // $clientId stores a compact ASCII-only correlation value supplied by the client.
    $clientId = trim($clientId);
    if ($clientId === '' || preg_match('/^[A-Za-z0-9_-]{1,80}$/', $clientId) !== 1) {
        return '';
    }

    return $clientId;
}

/**
 * Return request-local image IDs submitted beside images[].
 *
 * @return array<int string>.
 */
function upload_automation_image_client_ids(): array
{
    // $rawIds stores submitted IDs aligned with the images[] multipart field order.
    $rawIds = $_POST['image_client_ids'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
    }

    // $ids stores normalized correlation values by original upload index.
    $ids = [];
    foreach ($rawIds as $index => $rawId) {
        $ids[(int) $index] = upload_automation_normalize_client_id((string) $rawId);
    }

    return $ids;
}

/**
 * Parse optional Flight Simulator camera metadata from the upload request.
 *
 * @return array{lat:float,lng:float,altitude:float|null,source:string}|null Structured result data for the caller.
 */
function upload_automation_sim_camera_metadata(): ?array
{
    $rawSource = $_POST['sim_location_source'] ?? '';
    if (is_array($rawSource)) {
        return null;
    }

    $source = trim((string) $rawSource);
    if ($source !== 'simconnect_camera') {
        return null;
    }

    $latitude = upload_automation_float_field('sim_camera_latitude');
    $longitude = upload_automation_float_field('sim_camera_longitude');
    $altitude = upload_automation_float_field('sim_camera_altitude');
    if ($latitude === null || $longitude === null) {
        return null;
    }
    if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
        return null;
    }

    return [
        'lat' => round($latitude, 7),
        'lng' => round($longitude, 7),
        'altitude' => $altitude === null ? null : round($altitude, 2),
        'source' => $source,
    ];
}

/**
 * Parse one finite floating-point POST field.
 *
 * @param string $name Name value.
 * @return ?float Numeric result for the caller.
 */
function upload_automation_float_field(string $name): ?float
{
    $raw = $_POST[$name] ?? null;
    if (is_array($raw)) {
        return null;
    }

    $text = trim((string) $raw);
    if ($text === '') {
        return null;
    }

    $value = filter_var($text, FILTER_VALIDATE_FLOAT);
    return $value === false ? null : (float) $value;
}

/**
 * Attach parsed Flight Simulator camera metadata to stored image rows.
 *
 * @param int $galleryId Target gallery authorized by the API key.
 * @param array $stored Stored value.
 * @param array{lat:float,lng:float,altitude:float|null,source:string}|null $metadata Parsed metadata.
 * @return array{attached:int,skipped:int,error:string} Structured result data for the caller.
 */
function upload_automation_apply_sim_camera_metadata(int $galleryId, array $stored, ?array $metadata): array
{
    $result = ['attached' => 0, 'skipped' => 0, 'error' => ''];
    if ($metadata === null) {
        return $result;
    }
    if (!exif_gps_schema_ready()) {
        $result['skipped'] = count((array) ($stored['image_ids'] ?? []));
        $result['error'] = 'GPS metadata columns are unavailable.';
        return $result;
    }

    $imageIds = array_values(array_filter(array_map('intval', (array) ($stored['image_ids'] ?? []))));
    if (!$imageIds) {
        $result['error'] = 'No stored image rows were available for camera metadata.';
        return $result;
    }

    try {
        $stmt = db()->prepare('UPDATE images SET gps_lat = ?, gps_lng = ?, gps_altitude = ?, gps_extracted_at = ?, updated_at = ? WHERE id = ? AND gallery_id = ?');
        $now = now_sql();
        foreach ($imageIds as $imageId) {
            $stmt->execute([
                $metadata['lat'],
                $metadata['lng'],
                $metadata['altitude'],
                $now,
                $now,
                $imageId,
                $galleryId,
            ]);
            if ($stmt->rowCount() > 0) {
                $result['attached']++;
            } else {
                $result['skipped']++;
            }
        }
    } catch (Throwable $exception) {
        $result['attached'] = 0;
        $result['skipped'] = count($imageIds);
        $result['error'] = $exception->getMessage();
    }

    return $result;
}

/**
 * Return validated client-generated thumbnail upload entries.
 *
 * The accepted format mirrors PHP Gallery's own responsive thumbnail cache:
 * known thumbnail sizes only, JPG or WebP only, and image content validated with
 * getimagesize(). Unknown, missing, or mismatched metadata causes the single
 * submitted thumbnail to be rejected before it can be written into a gallery.
 *
 * @return array<int array{tmp_name:string,name:string,size_px:int,format:string,client_id:string}>.
 */
function upload_automation_client_thumbnail_entries(): array
{
    if (!isset($_FILES['client_thumbnails']) || !is_array($_FILES['client_thumbnails'])) {
        return [];
    }

    // $files stores the PHP multipart upload shape for all submitted thumbnails.
    $files = $_FILES['client_thumbnails'];
    if (empty($files['name']) || !is_array($files['name'])) {
        return [];
    }

    // $clientIds stores thumbnail-to-image correlation IDs aligned by index.
    $clientIds = $_POST['thumbnail_client_ids'] ?? [];
    // $sizes stores target long-side sizes aligned by index.
    $sizes = $_POST['thumbnail_sizes'] ?? [];
    // $formats stores target file formats aligned by index.
    $formats = $_POST['thumbnail_formats'] ?? [];
    if (!is_array($clientIds)) {
        $clientIds = [$clientIds];
    }
    if (!is_array($sizes)) {
        $sizes = [$sizes];
    }
    if (!is_array($formats)) {
        $formats = [$formats];
    }

    // $entries stores thumbnail files that passed all request-level validation.
    $entries = [];
    foreach ($files['name'] as $index => $name) {
        // $error stores the PHP upload status for this thumbnail file.
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(upload_error_message($error));
        }

        // $tmpName stores the uploaded temporary file that PHP created for this request.
        $tmpName = (string) ($files['tmp_name'][$index] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException(t('upload_automation.error.thumbnail_unavailable', 'Uploaded client thumbnail is not available.'));
        }

        // $clientId stores the request-local image correlation value.
        $clientId = upload_automation_normalize_client_id((string) ($clientIds[$index] ?? ''));
        if ($clientId === '') {
            throw new RuntimeException(t('upload_automation.error.thumbnail_client_id_missing', 'Uploaded client thumbnail is missing its image correlation ID.'));
        }

        // $size stores the target long-side thumbnail size.
        $size = (int) ($sizes[$index] ?? 0);
        if (!in_array($size, thumbnail_sizes(), true)) {
            throw new RuntimeException(t('upload_automation.error.thumbnail_size_invalid', 'Uploaded client thumbnail uses an unsupported size.'));
        }

        // $format stores the target thumbnail format.
        $format = strtolower((string) ($formats[$index] ?? ''));
        if (!in_array($format, ['jpg', 'webp'], true)) {
            throw new RuntimeException(t('upload_automation.error.thumbnail_format_invalid', 'Uploaded client thumbnail uses an unsupported format.'));
        }

        // $info stores image metadata used to reject wrong or damaged thumbnail files.
        $info = @getimagesize($tmpName);
        if ($info === false || empty($info['mime'])) {
            throw new RuntimeException(t('upload_automation.error.thumbnail_invalid', 'Uploaded client thumbnail is not a valid image.'));
        }
        // $mime stores the detected image MIME type for format validation.
        $mime = (string) $info['mime'];
        if ($format === 'jpg' && !in_array($mime, ['image/jpeg', 'image/pjpeg'], true)) {
            throw new RuntimeException(t('upload_automation.error.thumbnail_format_mismatch', 'Uploaded client thumbnail MIME type does not match its target format.'));
        }
        if ($format === 'webp' && $mime !== 'image/webp') {
            throw new RuntimeException(t('upload_automation.error.thumbnail_format_mismatch', 'Uploaded client thumbnail MIME type does not match its target format.'));
        }
        // $longSide stores the decoded thumbnail long side for size validation.
        $longSide = max((int) ($info[0] ?? 0), (int) ($info[1] ?? 0));
        if ($longSide > $size + 4) {
            throw new RuntimeException(t('upload_automation.error.thumbnail_dimensions_invalid', 'Uploaded client thumbnail is larger than its declared target size.'));
        }

        $entries[] = [
            'tmp_name' => $tmpName,
            'name' => (string) $name,
            'size_px' => $size,
            'format' => $format,
            'client_id' => $clientId,
        ];
    }

    return $entries;
}

/**
 * Build a map from client upload IDs to stored gallery image records.
 *
 * @param int $galleryId Gallery that received the originals.
 * @param array $clientIds Client ids value.
 * @param array $stored Stored value.
 * @return array<string array<string, mixed>>.
 */
function upload_automation_client_image_map(int $galleryId, array $clientIds, array $stored): array
{
    // $filenames stores final filenames after PHP Gallery normalized and de-duplicated them.
    $filenames = array_values((array) ($stored['filenames'] ?? []));
    // $map stores stored image rows by request-local client ID.
    $map = [];

    foreach ($filenames as $index => $filename) {
        // $clientId stores the ID originally submitted beside this image index.
        $clientId = upload_automation_normalize_client_id((string) ($clientIds[$index] ?? ''));
        if ($clientId === '') {
            continue;
        }

        // $image stores the database row created by the existing scan pipeline.
        // Do not use find_image_by_path() here. During scan_gallery_images(), that
        // helper may cache a negative lookup before inserting the new row. This
        // upload request needs a fresh database read so client thumbnails can be
        // attached immediately after the original was scanned.
        $image = upload_automation_find_image_by_path_uncached($galleryId, normalize_relative_path((string) $filename));
        if (is_array($image)) {
            $map[$clientId] = $image;
        }
    }

    return $map;
}

/**
 * Fetch one image row without using the process-local lookup cache.
 *
 * The normal finder intentionally caches misses for page rendering and repeated
 * maintenance reads. Upload automation runs the scanner and then immediately
 * needs the just-created image row in the same PHP request, so a stale cached
 * miss would incorrectly reject every client-generated thumbnail.
 *
 * @param int $galleryId Gallery that should contain the image.
 * @param string $relativePath Normalized image path inside the gallery folder.
 * @return array<string mixed>|null Fresh image row, or null when not found.
 */
function upload_automation_find_image_by_path_uncached(int $galleryId, string $relativePath): ?array
{
    // $normalizedPath stores the canonical path used by the image hash index.
    $normalizedPath = normalize_relative_path($relativePath);
    // $stmt stores the direct database lookup that bypasses static finder caches.
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? AND relative_path_hash = ? LIMIT 1');
    $stmt->execute([$galleryId, hash('sha256', $normalizedPath)]);
    // $image stores the fetched row or false when the image was not indexed.
    $image = $stmt->fetch();
    return is_array($image) ? $image : null;
}

/**
 * Execute upload automation work under a short gallery-scoped MySQL lock.
 *
 * Parallel manual uploads can send several requests to the same gallery at the
 * same time. Each request moves one original file and then reuses the existing
 * scanner, which scans the whole gallery folder. Without a gallery-level lock,
 * two PHP workers can both decide that the same newly discovered file is absent
 * and then race each other into the unique image-path index. The lock serializes
 * only the server-side store/scan/thumbnail-install phase for one gallery while
 * the Windows app can still generate thumbnails in parallel and upload request
 * bodies concurrently.
 *
 * @param int $galleryId Gallery being modified by the automation endpoint.
 * @param callable $callback Work to run while the gallery lock is held.
 * @return mixed Value returned by the callback.
 */
function upload_automation_with_gallery_lock(int $galleryId, callable $callback): mixed
{
    // $lockName stores a short deterministic advisory lock name for this gallery.
    $lockName = 'php_gallery_upload_automation_' . $galleryId;
    // $pdo stores the shared connection used for GET_LOCK() and RELEASE_LOCK().
    $pdo = db();
    // $stmt stores the advisory lock request. Ten seconds is enough for normal
    // small multipart requests while still failing clearly if a worker hangs.
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $stmt->execute([$lockName]);
    // $locked stores MySQL's GET_LOCK result: 1 acquired, 0 timeout, null error.
    $locked = (int) $stmt->fetchColumn();
    if ($locked !== 1) {
        throw new RuntimeException(t('upload_automation.error.gallery_busy', 'The target gallery is busy processing another upload. Please retry shortly.'));
    }

    try {
        return $callback();
    } finally {
        // $releaseStmt stores the matching advisory lock release request.
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStmt->execute([$lockName]);
    }
}

/**
 * Install client-generated thumbnails into the gallery thumbnail cache.
 *
 * This function writes only files that correspond to images accepted by the
 * existing upload pipeline. It does not create image records and it does not
 * decide the target gallery independently from the API key.
 *
 * @param int $galleryId Target gallery authorized by the API key.
 * @param array $gallery Gallery row or gallery data.
 * @param array $thumbnailEntries Thumbnail entries value.
 * @param array $imageClientIds Image client ids value.
 * @param array $stored Stored value.
 * @return array{installed:int,skipped:int,failed:int,errors:array<int,string>} Structured result data for the caller.
 */
function upload_automation_install_client_thumbnails(int $galleryId, array $gallery, array $thumbnailEntries, array $imageClientIds, array $stored): array
{
    if (!$thumbnailEntries) {
        return ['installed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    }

    // $imageMap stores accepted original images by their request-local client ID.
    $imageMap = upload_automation_client_image_map($galleryId, $imageClientIds, $stored);
    // $result stores counters returned to the client and admin logs.
    $result = ['installed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

    gallery_thumbs_dir($gallery, true);
    foreach ($thumbnailEntries as $entry) {
        // $clientId stores the original image correlation value for this thumbnail.
        $clientId = upload_automation_normalize_client_id((string) ($entry['client_id'] ?? ''));
        // $image stores the final image row belonging to this thumbnail.
        $image = $imageMap[$clientId] ?? null;
        if (!is_array($image)) {
            $result['skipped']++;
            $result['errors'][] = 'No stored image matched one uploaded client thumbnail.';
            continue;
        }

        try {
            // $targetPath stores the final gallery thumbnail cache path.
            $targetPath = thumbnail_abs_path($image, $gallery, (int) $entry['size_px'], (string) $entry['format']);
            // $targetDir stores the parent folder for the generated thumbnail file.
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
                throw new RuntimeException(t('upload_automation.error.thumbnail_target_dir_failed', 'Could not create the gallery thumbnail folder.'));
            }
            if (!move_uploaded_file((string) $entry['tmp_name'], $targetPath)) {
                throw new RuntimeException(t('upload_automation.error.thumbnail_store_failed', 'Could not store a client-generated thumbnail.'));
            }
            @touch($targetPath, time());
            if (function_exists('Gallery\\Services\\thumbnail_metadata_record_file')) {
                // $metadataResult stores validation and DB registration for the uploaded client thumbnail.
                $metadataResult = thumbnail_metadata_record_file($image, $gallery, (int) $entry['size_px'], (string) $entry['format'], $targetPath, image_abs_path($image, $gallery), true);
                if (empty($metadataResult['valid']) && (string) ($metadataResult['status'] ?? '') !== 'metadata_unavailable') {
                    $result['failed']++;
                    $result['errors'][] = 'Client-generated thumbnail geometry did not match the original image.';
                    continue;
                }
            }
            $result['installed']++;
        } catch (Throwable $exception) {
            $result['failed']++;
            $result['errors'][] = $exception->getMessage();
        }
    }

    $result['errors'] = array_values(array_unique(array_filter(array_map('strval', $result['errors']))));
    if ($result['installed'] > 0 && function_exists('Gallery\\Services\\thumbnail_maintenance_summary_cache_clear')) {
        thumbnail_maintenance_summary_cache_clear();
    }

    return $result;
}

/**
 * Convert common submitted truthy values into a boolean flag.
 *
 * @param mixed $value Value to process.
 * @param bool $default Default value when no explicit value is available.
 * @return bool True when the condition matches.
 */
function upload_automation_bool(mixed $value, bool $default = false): bool
{
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}
