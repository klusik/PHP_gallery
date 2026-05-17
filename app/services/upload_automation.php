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

/**
 * Upload automation service model.
 *
 * Tokens are scoped to one gallery. The raw token is shown only once during
 * generation, while only a SHA-256 hash is stored in the database.
 */

/**
 * Return whether the upload automation token table is available.
 */
function upload_automation_schema_ready(): bool
{
    if (!db_table_exists('gallery_upload_tokens')) {
        return false;
    }

    // $requiredColumns stores the minimum schema used by the upload automation service.
    // Checking columns prevents partially-applied migrations from causing fatal SQL errors.
    $requiredColumns = [
        'id',
        'gallery_id',
        'token_hash',
        'label',
        'active',
        'created_by_user_id',
        'created_at',
        'last_used_at',
        'revoked_at',
    ];

    foreach ($requiredColumns as $column) {
        if (!db_column_exists('gallery_upload_tokens', $column)) {
            return false;
        }
    }

    return true;
}

/**
 * Return the visible prefix used for newly generated upload API keys.
 */
function upload_automation_token_prefix(): string
{
    return 'pgu_';
}

/**
 * Generate a new raw API key for one gallery upload automation configuration.
 */
function upload_automation_generate_token_value(): string
{
    return upload_automation_token_prefix() . bin2hex(random_bytes(32));
}

/**
 * Return the stable database hash for one raw API key.
 */
function upload_automation_token_hash(string $token): string
{
    return hash('sha256', trim($token));
}

/**
 * Normalize the optional human label stored with an API key.
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
 * @return array{token:string,id:int,label:string}
 */
function create_gallery_upload_automation_token(int $galleryId, ?int $createdByUserId, string $label = ''): array
{
    if (!upload_automation_schema_ready()) {
        throw new RuntimeException(t('upload_automation.error.migration_required', 'Upload automation database table is missing. Run pending migrations first.'));
    }

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
 * @return array<int, array<string, mixed>>
 */
function gallery_upload_automation_tokens(int $galleryId): array
{
    if (!upload_automation_schema_ready()) {
        return [];
    }

    // $stmt stores the active tokens listed in the gallery editor.
    $stmt = db()->prepare('SELECT id, gallery_id, label, active, created_at, last_used_at, revoked_at FROM gallery_upload_tokens WHERE gallery_id = ? AND active = 1 AND revoked_at IS NULL ORDER BY created_at DESC, id DESC');
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return active upload automation API keys across all galleries.
 *
 * @return array<int, array<string, mixed>>
 */
function upload_automation_tokens_for_manager(): array
{
    if (!upload_automation_schema_ready()) {
        return [];
    }

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
 */
function revoke_gallery_upload_automation_token(int $galleryId, int $tokenId): bool
{
    if (!upload_automation_schema_ready()) {
        return false;
    }

    // $stmt stores the revoke update. The gallery predicate prevents cross-gallery revocation.
    $stmt = db()->prepare('UPDATE gallery_upload_tokens SET active = 0, revoked_at = ? WHERE id = ? AND gallery_id = ?');
    $stmt->execute([now_sql(), $tokenId, $galleryId]);
    return $stmt->rowCount() > 0;
}

/**
 * Resolve a raw API key into the active token row that authorizes an upload.
 */
function find_upload_automation_token(string $token): ?array
{
    if (!upload_automation_schema_ready()) {
        return null;
    }

    // $normalizedToken stores the trimmed token as sent by the watcher app.
    $normalizedToken = trim($token);
    if ($normalizedToken === '') {
        return null;
    }

    // $stmt stores the lookup by hash so raw API keys are never stored server-side.
    $stmt = db()->prepare('SELECT id, gallery_id, label, active, created_at, last_used_at FROM gallery_upload_tokens WHERE token_hash = ? AND active = 1 AND revoked_at IS NULL LIMIT 1');
    $stmt->execute([upload_automation_token_hash($normalizedToken)]);
    // $row stores the matching token, if any.
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Record the most recent successful use of one upload automation API key.
 */
function mark_upload_automation_token_used(int $tokenId): void
{
    if (!upload_automation_schema_ready()) {
        return;
    }

    // $stmt stores a lightweight audit timestamp for the admin UI.
    $stmt = db()->prepare('UPDATE gallery_upload_tokens SET last_used_at = ? WHERE id = ?');
    $stmt->execute([now_sql(), $tokenId]);
}

/**
 * Extract an upload automation API key from common HTTP locations.
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
 * @return array<int, string>
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
 * Return validated client-generated thumbnail upload entries.
 *
 * The accepted format mirrors PHP Gallery's own responsive thumbnail cache:
 * known thumbnail sizes only, JPG or WebP only, and image content validated with
 * getimagesize(). Unknown, missing, or mismatched metadata causes the single
 * submitted thumbnail to be rejected before it can be written into a gallery.
 *
 * @return array<int, array{tmp_name:string,name:string,size_px:int,format:string,client_id:string}>
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
 * @param array<int, string> $clientIds Request-local image IDs aligned with images[].
 * @param array<string, mixed> $stored Result returned by store_uploaded_gallery_images().
 * @return array<string, array<string, mixed>>
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
        $image = find_image_by_path($galleryId, normalize_relative_path((string) $filename));
        if (is_array($image)) {
            $map[$clientId] = $image;
        }
    }

    return $map;
}

/**
 * Install client-generated thumbnails into the gallery thumbnail cache.
 *
 * This function writes only files that correspond to images accepted by the
 * existing upload pipeline. It does not create image records and it does not
 * decide the target gallery independently from the API key.
 *
 * @param int $galleryId Target gallery authorized by the API key.
 * @param array<string, mixed> $gallery Target gallery row.
 * @param array<int, array<string, mixed>> $thumbnailEntries Validated client thumbnail uploads.
 * @param array<int, string> $imageClientIds Request-local IDs aligned with images[].
 * @param array<string, mixed> $stored Result returned by store_uploaded_gallery_images().
 * @return array{installed:int,skipped:int,failed:int,errors:array<int,string>}
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
            $result['installed']++;
        } catch (Throwable $exception) {
            $result['failed']++;
            $result['errors'][] = $exception->getMessage();
        }
    }

    $result['errors'] = array_values(array_unique(array_filter(array_map('strval', $result['errors']))));
    if ($result['installed'] > 0 && function_exists('thumbnail_maintenance_summary_cache_clear')) {
        thumbnail_maintenance_summary_cache_clear();
    }

    return $result;
}

/**
 * Convert common submitted truthy values into a boolean flag.
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
