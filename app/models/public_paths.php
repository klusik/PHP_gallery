<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/public_paths.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database persistence used by clean public gallery/image paths and sitemap discovery.
 *
 * Responsibilities:
 *   - Read public gallery/image rows used by sitemap and clean-path resolution
 *   - Persist gallery parent/path assignments atomically
 *   - Persist gallery-local and global image URL slug assignments
 *   - Keep SQL and PDO transaction ownership out of service orchestration
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
 *   - Optional PDO parameters exist only for migration compatibility and nested transactions.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use Throwable;
use function Gallery\Core\db;

/** Return the newest updated_at timestamp among public galleries. */
function public_path_model_public_gallery_max_updated_at(): ?string
{
    $value = db()->query("SELECT MAX(updated_at) FROM galleries WHERE visibility = 'public'")->fetchColumn();
    return $value === false || $value === null || $value === '' ? null : (string) $value;
}

/**
 * Return public galleries in stable filesystem-path order.
 *
 * @param bool $accessSchemaReady Whether current access columns are verified.
 * @return array<int,array<string,mixed>>
 */
function public_path_model_public_gallery_rows(bool $accessSchemaReady): array
{
    $columns = $accessSchemaReady
        ? '*'
        : 'id, parent_id, folder_path, slug, title, description, visibility, cover_image_id, created_at, updated_at';
    return db()->query("SELECT {$columns} FROM galleries WHERE visibility = 'public' ORDER BY folder_path")->fetchAll() ?: [];
}

/**
 * Return public images for one gallery in display order.
 *
 * @param int $galleryId Gallery identifier.
 * @param ?int $limit Optional bounded row limit.
 * @return array<int,array<string,mixed>>
 */
function public_path_model_public_images(int $galleryId, ?int $limit = null): array
{
    $sql = "SELECT * FROM images WHERE gallery_id = ? AND visibility = ? ORDER BY sort_order, filename";
    if ($limit !== null) {
        $limit = max(1, min(100, $limit));
        $sql .= ' LIMIT ' . $limit;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$galleryId, 'public']);
    return $stmt->fetchAll() ?: [];
}

/** Find one gallery by the SHA-256 hash of its normalized public path. */
function public_path_model_find_gallery_by_path_hash(string $pathHash): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE url_path_hash = ?');
    $stmt->execute([$pathHash]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Find one image by its persisted clean slug inside one gallery. */
function public_path_model_find_image_by_slug(int $galleryId, string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? AND url_slug = ?');
    $stmt->execute([$galleryId, $slug]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Return every image row for one gallery for legacy clean-slug fallback matching. */
function public_path_model_gallery_images(int $galleryId): array
{
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return gallery rows required to rebuild hierarchy and public paths.
 *
 * @param ?PDO $pdo Optional migration-owned connection.
 * @return array<int,array<string,mixed>>
 */
function public_path_model_gallery_regeneration_rows(?PDO $pdo = null): array
{
    $connection = $pdo ?? db();
    return $connection->query(
        'SELECT id, parent_id, title, folder_path, slug FROM galleries ORDER BY CHAR_LENGTH(folder_path), folder_path, id'
    )->fetchAll() ?: [];
}

/**
 * Return compact image rows required to rebuild every public image slug.
 *
 * @param ?PDO $pdo Optional migration-owned connection.
 * @return array<int,array<string,mixed>>
 */
function public_path_model_image_regeneration_rows(?PDO $pdo = null): array
{
    $connection = $pdo ?? db();
    return $connection->query(
        'SELECT id, gallery_id, title, filename FROM images ORDER BY gallery_id, sort_order, filename, id'
    )->fetchAll() ?: [];
}

/** Return compact image rows required to rebuild slugs in one gallery. */
function public_path_model_gallery_image_regeneration_rows(int $galleryId): array
{
    $stmt = db()->prepare(
        'SELECT id, gallery_id, title, filename FROM images WHERE gallery_id = ? ORDER BY sort_order, filename, id'
    );
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Persist canonical parent ids through an optional caller-owned transaction.
 *
 * @param array<int,array<string,mixed>> $rows Current gallery rows.
 * @param array<int,int|null> $parentAssignments Parent ids keyed by gallery id.
 * @param ?PDO $pdo Optional migration-owned connection.
 * @return int Number of parent links changed.
 */
function public_path_model_apply_parent_assignments(array $rows, array $parentAssignments, ?PDO $pdo = null): int
{
    $connection = $pdo ?? db();
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }
    try {
        $updateParent = $connection->prepare('UPDATE galleries SET parent_id = ? WHERE id = ?');
        $changed = 0;
        foreach ($rows as $row) {
            $galleryId = (int) ($row['id'] ?? 0);
            if ($galleryId <= 0) {
                continue;
            }
            $currentParentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $desiredParentId = $parentAssignments[$galleryId] ?? null;
            if ($currentParentId === $desiredParentId) {
                continue;
            }
            $updateParent->execute([$desiredParentId, $galleryId]);
            $changed++;
        }
        if ($ownsTransaction) {
            $connection->commit();
        }
        return $changed;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

/**
 * Persist canonical parent ids and clean public paths atomically.
 *
 * @param array<int,array<string,mixed>> $rows Current gallery rows.
 * @param array<int,int|null> $parentAssignments Parent ids keyed by gallery id.
 * @param array<int,array{slug:string,path:string}> $pathAssignments Clean path assignments keyed by gallery id.
 * @param string $now Shared SQL timestamp.
 * @param ?PDO $pdo Optional migration-owned connection.
 * @return array{galleries:int,parent_changed:int}
 */
function public_path_model_apply_gallery_regeneration(
    array $rows,
    array $parentAssignments,
    array $pathAssignments,
    string $now,
    ?PDO $pdo = null
): array {
    $connection = $pdo ?? db();
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }

    try {
        $parentChanged = public_path_model_apply_parent_assignments($rows, $parentAssignments, $connection);

        // Clear old values first so path swaps cannot collide with the unique hash index.
        $connection->exec('UPDATE galleries SET url_slug = NULL, url_path = NULL, url_path_hash = NULL');
        $updatePath = $connection->prepare(
            'UPDATE galleries SET url_slug = ?, url_path = ?, url_path_hash = ?, updated_at = ? WHERE id = ?'
        );
        foreach ($pathAssignments as $galleryId => $assignment) {
            $path = (string) ($assignment['path'] ?? '');
            $updatePath->execute([
                (string) ($assignment['slug'] ?? ''),
                $path,
                hash('sha256', $path),
                $now,
                (int) $galleryId,
            ]);
        }

        if ($ownsTransaction) {
            $connection->commit();
        }
        return ['galleries' => count($pathAssignments), 'parent_changed' => $parentChanged];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

/**
 * Persist gallery paths and every image slug as one transaction.
 *
 * @param array<int,array<string,mixed>> $galleryRows Current gallery rows.
 * @param array<int,int|null> $parentAssignments Parent ids keyed by gallery id.
 * @param array<int,array{slug:string,path:string}> $pathAssignments Clean path assignments keyed by gallery id.
 * @param array<int,string> $imageSlugAssignments Image slugs keyed by image id.
 * @param string $now Shared SQL timestamp.
 * @return array{galleries:int,images:int,parent_changed:int}
 */
function public_path_model_apply_full_regeneration(
    array $galleryRows,
    array $parentAssignments,
    array $pathAssignments,
    array $imageSlugAssignments,
    string $now
): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $galleryResult = public_path_model_apply_gallery_regeneration(
            $galleryRows,
            $parentAssignments,
            $pathAssignments,
            $now,
            $pdo
        );
        $imageCount = public_path_model_apply_image_slugs($imageSlugAssignments, $now, $pdo);
        $pdo->commit();
        return [
            'galleries' => $galleryResult['galleries'],
            'images' => $imageCount,
            'parent_changed' => $galleryResult['parent_changed'],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Persist clean image slugs using an optional caller-owned transaction.
 *
 * @param array<int,string> $assignments Slugs keyed by image id.
 * @param string $now Shared SQL timestamp.
 * @param ?PDO $pdo Optional caller-owned connection.
 * @return int Number of assignments executed.
 */
function public_path_model_apply_image_slugs(array $assignments, string $now, ?PDO $pdo = null): int
{
    if ($assignments === []) {
        return 0;
    }
    $connection = $pdo ?? db();
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }
    try {
        $update = $connection->prepare('UPDATE images SET url_slug = ?, updated_at = ? WHERE id = ?');
        foreach ($assignments as $imageId => $slug) {
            $update->execute([(string) $slug, $now, (int) $imageId]);
        }
        if ($ownsTransaction) {
            $connection->commit();
        }
        return count($assignments);
    } catch (Throwable $exception) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}
