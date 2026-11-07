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

/**
 * Return the newest updated_at timestamp among public galleries.
 * @return ?string Newest public-gallery update timestamp, or null when no timestamp exists.
 */
function public_path_model_public_gallery_max_updated_at(): ?string
{
    $value = db()
        ->query(
            "SELECT MAX(updated_at) FROM galleries WHERE visibility = 'public'",
        )
        ->fetchColumn();
    return $value === false || $value === null || $value === ''
        ? null
        : (string) $value;
}

/**
 * Return public galleries in stable filesystem-path order.
 *
 * @param bool $accessSchemaReady Whether current access columns are verified.
 * @return list<array<string,int|string|null>> Public gallery rows ordered by filesystem path.
 */
function public_path_model_public_gallery_rows(bool $accessSchemaReady): array
{
    $columns = $accessSchemaReady
        ? '*'
        : 'id, parent_id, folder_path, slug, title, description, visibility, cover_image_id, created_at, updated_at';
    return (
        db()
            ->query(
                "SELECT {$columns} FROM galleries WHERE visibility = 'public' ORDER BY folder_path",
            )
            ->fetchAll() ?: []
    );
}

/**
 * Return public images for one gallery in display order.
 *
 * @param int $galleryId Gallery identifier.
 * @param ?int $limit Optional bounded row limit.
 * @return list<array<string,int|string|null>> Public image rows in display order, optionally bounded by the requested limit.
 */
function public_path_model_public_images(
    int $galleryId,
    ?int $limit = null,
): array {
    $sql = 'SELECT * FROM images WHERE gallery_id = ? AND visibility = ? ORDER BY sort_order, filename';
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

/**
 * Find one image by its persisted clean slug inside one gallery.
 * @param int $galleryId Physical gallery containing the image.
 * @param string $slug Persisted clean public image slug.
 * @return ?array<string,int|string|null> Matching image row, or null when no slug exists in the gallery.
 */
function public_path_model_find_image_by_slug(
    int $galleryId,
    string $slug,
): ?array {
    $stmt = db()->prepare(
        'SELECT * FROM images WHERE gallery_id = ? AND url_slug = ?',
    );
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
 * @return list<array{id:int|string,parent_id:int|string|null,title:string,folder_path:string,slug:string}> Gallery ids, parent links, names, and paths ordered parent-first.
 */
function public_path_model_gallery_regeneration_rows(?PDO $pdo = null): array
{
    $connection = $pdo ?? db();
    return (
        $connection
            ->query(
                'SELECT id, parent_id, title, folder_path, slug FROM galleries ORDER BY CHAR_LENGTH(folder_path), folder_path, id',
            )
            ->fetchAll() ?: []
    );
}

/**
 * Read canonical input and stored paths during a linked creation transaction.
 *
 * @param int $limit Positive maximum number of rows including lookahead.
 * @param bool $hasAccess Whether the complete gallery-access schema is verified.
 * @param bool $hasSchedule Whether scheduled publication storage is verified.
 * @return list<array{id:int|string,parent_id:int|string|null,title:string,description_is_empty:int|string|null,folder_path:string,folder_path_hash:string,slug:string,visibility:string,sort_order:int|string,voting_enabled:int|string,show_filenames:int|string,edit_revision:int|string,url_slug:?string,url_path:?string,url_path_hash:?string,access_mode?:string,access_listing?:string,scheduled_publish_at?:?string}> Ordered gallery rows with persisted creation fields and public paths.
 * @throws \RuntimeException When the caller's transaction is unavailable.
 */
function public_path_model_linked_creation_rows(
    int $limit,
    bool $hasAccess,
    bool $hasSchedule,
): array
{
    if (!db()->inTransaction() || $limit <= 0) {
        throw new \RuntimeException('Linked gallery transaction is unavailable.');
    }
    $statement = db()->prepare(
        "SELECT id, parent_id, title, (description = '') AS description_is_empty, "
            . 'folder_path, folder_path_hash, slug, visibility, '
            . 'sort_order, voting_enabled, show_filenames, edit_revision, '
            . 'url_slug, url_path, url_path_hash '
            . ($hasAccess ? ', access_mode, access_listing ' : '')
            . ($hasSchedule ? ', scheduled_publish_at ' : '')
            . 'FROM galleries ORDER BY CHAR_LENGTH(folder_path), folder_path, id LIMIT ?',
    );
    $statement->bindValue(1, $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll() ?: [];
}

/**
 * Assign only the newly inserted gallery's canonical URL inside its transaction.
 *
 * @param int $galleryId Identifier of the uncommitted new gallery.
 * @param string $slug Canonical sibling slug produced by the shared policy.
 * @param string $path Canonical installation-relative gallery path.
 * @param string $visibility Verified unpublished or legacy draft value.
 * @param array{title:string,parent_id:int|null,folder_path:string,legacy_slug:string,sort_order:int} $expected Original validated gallery identity and ordering.
 * @param bool $hasAccess Whether complete access columns are verified.
 * @param bool $hasSchedule Whether scheduled publication is verified.
 * @return void Stores exactly one new gallery path or throws on lost ownership.
 * @throws \RuntimeException When the transaction or expected row is unavailable.
 */
function public_path_model_assign_linked_creation(
    int $galleryId,
    string $slug,
    string $path,
    string $visibility,
    array $expected,
    bool $hasAccess,
    bool $hasSchedule,
): void {
    $pdo = db();
    if (!$pdo->inTransaction() || $galleryId <= 0 || $slug === '' || $path === '') {
        throw new \RuntimeException('Linked gallery transaction is unavailable.');
    }
    $statement = $pdo->prepare(
        'UPDATE galleries SET url_slug = ?, url_path = ?, url_path_hash = ?, '
            . 'edit_revision = edit_revision + 1 '
            . 'WHERE id = ? AND url_slug IS NULL AND url_path IS NULL AND url_path_hash IS NULL',
    );
    $statement->execute([$slug, $path, hash('sha256', $path), $galleryId]);
    if ($statement->rowCount() !== 1) {
        throw new \RuntimeException('Linked gallery path ownership is unavailable.');
    }
    if (!$pdo->inTransaction()) {
        throw new \RuntimeException('Linked gallery transaction is unavailable.');
    }
    $readback = $pdo->prepare(
        'SELECT url_slug, url_path, url_path_hash, visibility, title, parent_id, '
            . 'folder_path, folder_path_hash, slug, sort_order, voting_enabled, show_filenames '
            . ($hasAccess ? ', access_mode, access_listing ' : '')
            . ($hasSchedule ? ', scheduled_publish_at ' : '')
            . 'FROM galleries WHERE id = ?',
    );
    $readback->execute([$galleryId]);
    $row = $readback->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['url_slug'] !== $slug || $row['url_path'] !== $path
        || $row['url_path_hash'] !== hash('sha256', $path)
        || $row['visibility'] !== $visibility
        || $row['title'] !== $expected['title']
        || (int) ($row['parent_id'] ?? 0) !== (int) ($expected['parent_id'] ?? 0)
        || $row['folder_path'] !== $expected['folder_path']
        || $row['folder_path_hash'] !== hash('sha256', $expected['folder_path'])
        || $row['slug'] !== $expected['legacy_slug']
        || (int) $row['sort_order'] !== $expected['sort_order']
        || (int) $row['voting_enabled'] !== 0 || (int) $row['show_filenames'] !== 0
        || ($hasAccess && (($row['access_mode'] ?? null) !== 'normal'
            || ($row['access_listing'] ?? null) !== 'listed'))
        || ($hasSchedule && (!array_key_exists('scheduled_publish_at', $row)
            || $row['scheduled_publish_at'] !== null))) {
        throw new \RuntimeException('Linked gallery path could not be verified.');
    }
}

/**
 * Return compact image rows required to rebuild every public image slug.
 *
 * @param ?PDO $pdo Optional migration-owned connection.
 * @return list<array{id:int|string,gallery_id:int|string,title:string,filename:string}> Compact image rows ordered by gallery and display position.
 */
function public_path_model_image_regeneration_rows(?PDO $pdo = null): array
{
    $connection = $pdo ?? db();
    return (
        $connection
            ->query(
                'SELECT id, gallery_id, title, filename FROM images ORDER BY gallery_id, sort_order, filename, id',
            )
            ->fetchAll() ?: []
    );
}

/**
 * Return compact image rows required to rebuild slugs in one gallery.
 * @param int $galleryId Gallery whose image slugs are rebuilt.
 * @return list<array{id:int|string,gallery_id:int|string,title:string,filename:string}> Compact image rows for one gallery in display order.
 */
function public_path_model_gallery_image_regeneration_rows(int $galleryId): array
{
    $stmt = db()->prepare(
        'SELECT id, gallery_id, title, filename FROM images WHERE gallery_id = ? ORDER BY sort_order, filename, id',
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
function public_path_model_apply_parent_assignments(
    array $rows,
    array $parentAssignments,
    ?PDO $pdo = null,
): int {
    $connection = $pdo ?? db();
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }
    try {
        $updateParent = $connection->prepare(
            'UPDATE galleries SET parent_id = ?, edit_revision = edit_revision + 1 WHERE id = ?',
        );
        $changed = 0;
        foreach ($rows as $row) {
            $galleryId = (int) ($row['id'] ?? 0);
            if ($galleryId <= 0) {
                continue;
            }
            $currentParentId = $row['parent_id'] === null
                ? null
                : (int) $row['parent_id'];
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
 * @return array{galleries:int,parent_changed:int} Gallery path rows written and parent links changed.
 */
function public_path_model_apply_gallery_regeneration(
    array $rows,
    array $parentAssignments,
    array $pathAssignments,
    string $now,
    ?PDO $pdo = null,
): array {
    $connection = $pdo ?? db();
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }

    try {
        $parentChanged = public_path_model_apply_parent_assignments(
            $rows,
            $parentAssignments,
            $connection,
        );

        // Clear old values first so path swaps cannot collide with the unique hash index.
        $connection->exec(
            'UPDATE galleries SET url_slug = NULL, url_path = NULL, url_path_hash = NULL, edit_revision = edit_revision + 1',
        );
        $updatePath = $connection->prepare(
            'UPDATE galleries SET url_slug = ?, url_path = ?, url_path_hash = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ?',
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
        return [
            'galleries' => count($pathAssignments),
            'parent_changed' => $parentChanged,
        ];
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
 * @return array{galleries:int,images:int,parent_changed:int} Gallery paths written, image slugs written, and parent links changed.
 */
function public_path_model_apply_full_regeneration(
    array $galleryRows,
    array $parentAssignments,
    array $pathAssignments,
    array $imageSlugAssignments,
    string $now,
): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $galleryResult = public_path_model_apply_gallery_regeneration(
            $galleryRows,
            $parentAssignments,
            $pathAssignments,
            $now,
            $pdo,
        );
        $imageCount = public_path_model_apply_image_slugs(
            $imageSlugAssignments,
            $now,
            $pdo,
        );
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
function public_path_model_apply_image_slugs(
    array $assignments,
    string $now,
    ?PDO $pdo = null,
): int {
    if ($assignments === []) {
        return 0;
    }
    $connection = $pdo ?? db();
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }
    try {
        $update = $connection->prepare(
            'UPDATE images SET url_slug = ?, updated_at = ? WHERE id = ?',
        );
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
