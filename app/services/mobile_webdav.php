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

namespace Gallery\Services;

use RuntimeException;
use function Gallery\Core\absolute_public_url;
use function Gallery\Core\base_url;
use function Gallery\Core\now_sql;
use function Gallery\Models\mobile_webdav_model_create_token;
use function Gallery\Models\mobile_webdav_model_delete_token;
use function Gallery\Models\mobile_webdav_model_find_active_by_path_token;
use function Gallery\Models\mobile_webdav_model_mark_used;
use function Gallery\Models\mobile_webdav_model_tokens;

/**
 * Return whether the WebDAV token table is available.
 *
 * @return bool True when the condition matches.
 */
function mobile_webdav_ready(): bool
{
    return schema_inspection_is_available(mobile_webdav_schema_status());
}

/**
 * Return all configured mobile WebDAV upload tokens for the admin UI.
 *
 * @return array Structured result data for the caller.
 */
function mobile_webdav_tokens(): array
{
    $schemaStatus = mobile_webdav_schema_status();
    if (schema_inspection_is_missing($schemaStatus)) {
        return [];
    }
    mutation_schema_assert_known(
        $schemaStatus,
        'mobile_webdav.list_tokens',
        t('mobile_webdav.error_schema_unknown', 'Mobile upload connections are temporarily unavailable because their database schema could not be verified.')
    );
    return mobile_webdav_model_tokens();
}

/**
 * Create a scoped mobile WebDAV credential and return the plaintext password once.
 *
 * @param int $userId User id identifier.
 * @param int $galleryId Gallery identifier.
 * @param string $label Label value.
 * @return array Structured result data for the caller.
 */
function mobile_webdav_create_token(int $userId, int $galleryId, string $label): array
{
    mutation_schema_assert_available(
        mobile_webdav_schema_status(),
        'mobile_webdav.create_token',
        t('mobile_webdav.error_migration_required', 'Run database migrations before creating mobile upload connections.'),
        t('mobile_webdav.error_schema_unknown', 'Mobile upload connections are temporarily unavailable because their database schema could not be verified. No credential was created.')
    );
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException(t('gallery.error.not_found', 'Gallery not found.'));
    }
    $cleanLabel = trim($label) !== '' ? trim($label) : (string) t('mobile_webdav.default_label', 'Mobile upload');
    $pathToken = bin2hex(random_bytes(24));
    $password = mobile_webdav_plain_password();
    $username = 'mobile-' . $pathToken;
    $now = now_sql();
    $tokenId = mobile_webdav_model_create_token($userId, $galleryId, $cleanLabel, $username, password_hash($password, PASSWORD_DEFAULT), $pathToken, $now);
    return [
        'id' => $tokenId,
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
 *
 * @param int $tokenId Token id identifier.
 */
function mobile_webdav_delete_token(int $tokenId): void
{
    mutation_schema_assert_available(
        mobile_webdav_revocation_schema_status(),
        'mobile_webdav.delete_token',
        t('mobile_webdav.error_migration_required', 'Run database migrations before managing mobile upload connections.'),
        t('mobile_webdav.error_schema_unknown', 'The mobile upload credential could not be deleted because its database schema could not be verified. No credential was changed.')
    );
    mobile_webdav_model_delete_token($tokenId);
}

/**
 * Return a generated app password for mobile WebDAV clients.
 *
 * @return string Text result for the caller.
 */
function mobile_webdav_plain_password(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

/**
 * Return the absolute WebDAV collection URL for one path token.
 *
 * @param string $pathToken Path token filesystem path.
 * @return string Text result for the caller.
 */
function mobile_webdav_absolute_url(string $pathToken): string
{
    return absolute_public_url(base_url('webdav/' . rawurlencode($pathToken) . '/'));
}

/**
 * Resolve a mobile WebDAV token row by its path token.
 *
 * @param string $pathToken Path token filesystem path.
 * @return ?array Structured result data for the caller.
 */
function mobile_webdav_find_by_path_token(string $pathToken): ?array
{
    if ($pathToken === '') {
        return null;
    }
    mutation_schema_assert_available(
        mobile_webdav_schema_status(),
        'mobile_webdav.authenticate',
        t('mobile_webdav.error_migration_required', 'Run database migrations before using mobile upload connections.'),
        t('mobile_webdav.error_schema_unknown', 'Mobile upload authentication is temporarily unavailable because its database schema could not be verified.')
    );
    return mobile_webdav_model_find_active_by_path_token($pathToken);
}

/**
 * Authenticate one mobile WebDAV request with Basic Auth.
 *
 * @param string $pathToken Path token filesystem path.
 * @return ?array Structured result data for the caller.
 */
function mobile_webdav_authenticated_token(string $pathToken, string $username = '', string $password = '', string $authorization = ''): ?array
{
    $token = mobile_webdav_find_by_path_token($pathToken);
    if (!$token) {
        return null;
    }
    if ($username === '' && $authorization !== '') {
        $decoded = mobile_webdav_decode_basic_authorization($authorization);
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
 *
 * @param string $header Header value.
 * @return array Structured result data for the caller.
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
 *
 * @param string $path Filesystem path.
 * @return string Text result for the caller.
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
 *
 * @param array $token Token value.
 * @param string $filename Filename value.
 * @param string $sourcePath Source filesystem path.
 * @return array Structured result data for the caller.
 */
function mobile_webdav_store_put(array $token, string $filename, string $sourcePath): array
{
    mutation_schema_assert_available(
        mobile_webdav_schema_status(),
        'mobile_webdav.store_put_token',
        t('mobile_webdav.error_migration_required', 'Run database migrations before using mobile upload connections.'),
        t('mobile_webdav.error_schema_unknown', 'Mobile upload is temporarily unavailable because its credential schema could not be verified. No gallery file was changed.')
    );
    mutation_schema_assert_available(
        upload_ingestion_schema_status(),
        'mobile_webdav.store_put_gallery',
        'Mobile upload requires the current gallery/image database schema. Run pending migrations first.',
        'Mobile upload is temporarily unavailable because the gallery/image database schema could not be verified. No gallery file was changed.'
    );
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
    mobile_webdav_model_mark_used((int) $token['id'], now_sql());
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
