<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/mobile_webdav.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides WebDAV-compatible mobile upload helpers for PhotoSync-style clients.
 *
 * Responsibilities:
 *   - Manage scoped mobile upload credentials
 *   - Authenticate WebDAV PUT requests using Basic Auth and path tokens
 *   - Store compatible image files through the existing gallery scan pipeline
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
 *   2026-06-04
 */

declare(strict_types=1);

/**
 * Return whether the WebDAV token table is available.
 */
function mobile_webdav_ready(): bool
{
    return function_exists('db_table_exists') && db_table_exists('mobile_webdav_upload_tokens');
}

/**
 * Return all configured mobile WebDAV upload tokens for the admin UI.
 */
function mobile_webdav_tokens(): array
{
    if (!mobile_webdav_ready()) {
        return [];
    }
    $stmt = db()->query("SELECT t.*, g.title AS gallery_title, g.folder_path AS gallery_folder_path
        FROM mobile_webdav_upload_tokens t
        INNER JOIN galleries g ON g.id = t.gallery_id
        ORDER BY t.created_at DESC, t.id DESC");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * Create a scoped mobile WebDAV credential and return the plaintext password once.
 */
function mobile_webdav_create_token(int $userId, int $galleryId, string $label): array
{
    if (!mobile_webdav_ready()) {
        throw new RuntimeException(t('mobile_webdav.error_migration_required', 'Run database migrations before creating mobile upload connections.'));
    }
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException(t('gallery.error.not_found', 'Gallery not found.'));
    }
    $cleanLabel = trim($label) !== '' ? trim($label) : (string) t('mobile_webdav.default_label', 'Mobile upload');
    $pathToken = bin2hex(random_bytes(24));
    $password = mobile_webdav_plain_password();
    $username = 'mobile-' . $pathToken;
    $stmt = db()->prepare('INSERT INTO mobile_webdav_upload_tokens (user_id, gallery_id, label, username, password_hash, path_token, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
    $now = now_sql();
    $stmt->execute([$userId, $galleryId, $cleanLabel, $username, password_hash($password, PASSWORD_DEFAULT), $pathToken, $now, $now]);
    return [
        'id' => (int) db()->lastInsertId(),
        'label' => $cleanLabel,
        'username' => $username,
        'password' => $password,
        'path_token' => $pathToken,
        'gallery' => $gallery,
        'url' => mobile_webdav_absolute_url($pathToken),
    ];
}

/**
 * Delete one mobile WebDAV token.
 */
function mobile_webdav_delete_token(int $tokenId): void
{
    if (!mobile_webdav_ready()) {
        return;
    }
    $stmt = db()->prepare('DELETE FROM mobile_webdav_upload_tokens WHERE id = ?');
    $stmt->execute([$tokenId]);
}

/**
 * Return a generated app password for mobile WebDAV clients.
 */
function mobile_webdav_plain_password(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

/**
 * Return the absolute WebDAV collection URL for one path token.
 */
function mobile_webdav_absolute_url(string $pathToken): string
{
    return absolute_public_url(base_url('webdav/' . rawurlencode($pathToken) . '/'));
}

/**
 * Resolve a mobile WebDAV token row by its path token.
 */
function mobile_webdav_find_by_path_token(string $pathToken): ?array
{
    if (!mobile_webdav_ready() || $pathToken === '') {
        return null;
    }
    $stmt = db()->prepare("SELECT t.*, g.folder_path, g.title AS gallery_title
        FROM mobile_webdav_upload_tokens t
        INNER JOIN galleries g ON g.id = t.gallery_id
        WHERE t.path_token = ? AND t.enabled = 1
        LIMIT 1");
    $stmt->execute([$pathToken]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Authenticate one mobile WebDAV request with Basic Auth.
 */
function mobile_webdav_authenticated_token(string $pathToken): ?array
{
    $token = mobile_webdav_find_by_path_token($pathToken);
    if (!$token) {
        return null;
    }
    $username = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $password = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($username === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $decoded = mobile_webdav_decode_basic_authorization((string) $_SERVER['HTTP_AUTHORIZATION']);
        $username = (string) ($decoded['username'] ?? '');
        $password = (string) ($decoded['password'] ?? '');
    }
    if (!hash_equals((string) $token['username'], $username)) {
        return null;
    }
    if (!password_verify($password, (string) $token['password_hash'])) {
        return null;
    }
    return $token;
}

/**
 * Decode a Basic Authorization header when PHP did not populate PHP_AUTH_*.
 */
function mobile_webdav_decode_basic_authorization(string $header): array
{
    if (!preg_match('/^Basic\s+(.+)$/i', trim($header), $match)) {
        return [];
    }
    $decoded = base64_decode($match[1], true);
    if (!is_string($decoded) || !str_contains($decoded, ':')) {
        return [];
    }
    [$username, $password] = explode(':', $decoded, 2);
    return ['username' => $username, 'password' => $password];
}

/**
 * Sanitize a WebDAV target path into one filename.
 */
function mobile_webdav_filename_from_path(string $path): string
{
    $path = trim(str_replace('\\', '/', rawurldecode($path)), '/');
    $filename = basename($path);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        throw new RuntimeException(t('mobile_webdav.error_missing_filename', 'The WebDAV upload did not include a filename.'));
    }
    return $filename;
}

/**
 * Store one WebDAV PUT body into the token destination gallery.
 */
function mobile_webdav_store_put(array $token, string $filename, string $sourcePath): array
{
    if (!is_file($sourcePath)) {
        throw new RuntimeException(t('mobile_webdav.error_empty_upload', 'The WebDAV upload body was empty.'));
    }
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        throw new RuntimeException(t('mobile_webdav.error_supported_formats', 'Mobile WebDAV upload accepts JPG, PNG, GIF, and WebP files. Configure the mobile app to convert HEIC to JPEG before transfer.'));
    }
    $info = @getimagesize($sourcePath);
    if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
        throw new RuntimeException(t('upload.error.invalid_image', 'One uploaded file is not a valid image.'));
    }
    $gallery = find_gallery((int) $token['gallery_id']);
    if (!$gallery) {
        throw new RuntimeException(t('gallery.error.not_found', 'Gallery not found.'));
    }
    [$storedFilename, $targetPath] = unique_gallery_upload_target($gallery, $filename);
    if (!@rename($sourcePath, $targetPath)) {
        if (!@copy($sourcePath, $targetPath)) {
            throw new RuntimeException(t('upload.error.store_image_failed', 'Could not store uploaded image.'));
        }
        @unlink($sourcePath);
    }
    $changed = scan_gallery_images((int) $gallery['id']);
    $imageIds = uploaded_gallery_image_ids((int) $gallery['id'], [$storedFilename]);
    $renameResult = null;
    if (admin_upload_auto_rename_enabled() && $imageIds) {
        $renameResult = gallery_upload_auto_rename_image_ids((int) $gallery['id'], $imageIds);
        $finalNames = uploaded_gallery_filenames_for_image_ids((int) $gallery['id'], $imageIds);
        $storedFilename = (string) ($finalNames[0] ?? $storedFilename);
    }
    $stmt = db()->prepare('UPDATE mobile_webdav_upload_tokens SET last_used_at = ?, updated_at = ? WHERE id = ?');
    $now = now_sql();
    $stmt->execute([$now, $now, (int) $token['id']]);
    admin_log_event('info', 'mobile_webdav.uploaded', 'Mobile WebDAV client uploaded an image.', [
        'token_id' => (int) $token['id'],
        'gallery_id' => (int) $gallery['id'],
        'filename' => $storedFilename,
        'renamed' => $renameResult === null ? 0 : (int) ($renameResult['renamed'] ?? 0),
        'rename_failures' => $renameResult === null ? [] : array_values((array) ($renameResult['failures'] ?? [])),
    ]);
    return [
        'filename' => $storedFilename,
        'scanned' => $changed,
        'image_ids' => array_map('intval', $imageIds),
        'renamed' => $renameResult === null ? 0 : (int) ($renameResult['renamed'] ?? 0),
        'rename_warnings' => $renameResult === null ? [] : array_values((array) ($renameResult['warnings'] ?? [])),
        'rename_failures' => $renameResult === null ? [] : array_values((array) ($renameResult['failures'] ?? [])),
    ];
}
