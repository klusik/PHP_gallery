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
