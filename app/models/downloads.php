<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/downloads.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns gallery-download inventory and ZIP cache persistence.
 *
 * Responsibilities:
 *   - Read gallery subtrees and ordered image rows for archive preparation
 *   - Read and mutate ZIP archive cache metadata
 *   - Return deterministic content-signature source rows
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
 *   - Filesystem ZIP generation, authorization, and HTTP streaming remain outside the model.
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Return one gallery subtree in deterministic folder order.
 *
 * @param string $folderPath Normalized root folder path.
 * @param ?int $limit Optional positive row limit.
 * @return array<int,array<string,mixed>> Gallery rows.
 */
function downloads_model_gallery_subtree(string $folderPath, ?int $limit = null): array
{
    if ($folderPath === '') {
        return [];
    }
    $sql = 'SELECT * FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY CHAR_LENGTH(folder_path), folder_path, id';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$folderPath, $folderPath . '/%']);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return ordered image rows for selected galleries.
 *
 * @param array $galleryIds Gallery identifiers.
 * @param bool $publicOnly Whether only public image rows are eligible.
 * @param ?int $limit Optional positive row limit.
 * @return array<int,array<string,mixed>> Image rows.
 */
function downloads_model_images_for_galleries(array $galleryIds, bool $publicOnly, ?int $limit = null): array
{
    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if ($galleryIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    $sql = "SELECT * FROM images WHERE gallery_id IN ($placeholders)";
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= ' ORDER BY gallery_id, sort_order, filename, relative_path, id';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($galleryIds);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return all ZIP archive cache metadata rows.
 *
 * @return array<int,array{id:int,file_path:string}> Cache rows.
 */
function downloads_model_zip_archives(): array
{
    $stmt = db()->query('SELECT id, file_path FROM zip_archives');
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

/**
 * Delete one ZIP archive cache metadata row.
 *
 * @param int $archiveId ZIP archive row identifier.
 * @return int Number of deleted rows.
 */
function downloads_model_delete_zip_archive(int $archiveId): int
{
    $stmt = db()->prepare('DELETE FROM zip_archives WHERE id = ?');
    $stmt->execute([$archiveId]);
    return (int) $stmt->rowCount();
}

/**
 * Find the newest ZIP archive cache row for one scope and content signature.
 *
 * @param string $scope Cache scope.
 * @param ?int $galleryId Optional gallery identifier.
 * @param string $contentSignature Content signature.
 * @return ?array<string,mixed> Cache row or null.
 */
function downloads_model_find_zip_archive(string $scope, ?int $galleryId, string $contentSignature): ?array
{
    if ($galleryId === null) {
        $stmt = db()->prepare('SELECT * FROM zip_archives WHERE scope = ? AND gallery_id IS NULL AND content_signature = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$scope, $contentSignature]);
    } else {
        $stmt = db()->prepare('SELECT * FROM zip_archives WHERE scope = ? AND gallery_id = ? AND content_signature = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$scope, $galleryId, $contentSignature]);
    }
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Insert one ZIP archive cache metadata row.
 *
 * @param string $scope Cache scope.
 * @param ?int $galleryId Optional gallery identifier.
 * @param string $filePath Generated ZIP filesystem path.
 * @param string $contentSignature Content signature.
 * @param string $now Current SQL timestamp.
 */
function downloads_model_insert_zip_archive(string $scope, ?int $galleryId, string $filePath, string $contentSignature, string $now): void
{
    $stmt = db()->prepare('INSERT INTO zip_archives (scope, gallery_id, file_path, content_signature, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$scope, $galleryId, $filePath, $contentSignature, $now, $now]);
}

/**
 * Return deterministic rows used to calculate the all-gallery ZIP signature.
 *
 * @return array<int,array<string,mixed>> Signature source rows.
 */
function downloads_model_all_signature_rows(): array
{
    $stmt = db()->query("SELECT g.folder_path, g.updated_at AS gallery_updated_at, i.relative_path, i.file_size, i.modified_at, i.visibility
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id
        ORDER BY g.folder_path, i.relative_path");
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

/**
 * Return every gallery in deterministic archive order.
 *
 * @return array<int,array<string,mixed>> Gallery rows.
 */
function downloads_model_all_galleries(): array
{
    $stmt = db()->query('SELECT * FROM galleries ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}
