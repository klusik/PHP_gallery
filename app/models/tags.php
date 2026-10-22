<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/tags.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns tag, tag-assignment, tag-reporting, and public tag lookup persistence.
 *
 * Responsibilities:
 *   - Persist canonical media-typed tag rows and entity assignments
 *   - Own atomic direct image-tag mutation transactions and row locking
 *   - Provide bulk tag reads and usage aggregates
 *   - Provide tag suggestion source rows without owning scoring policy
 *   - Support admin tag maintenance and duplicate merges atomically
 *   - Keep tag SQL out of service orchestration
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
 *   - Dynamic entity types and sort fields are constrained by explicit allowlists.
 *   - Public listing predicates are built only from boolean schema capability flags.
 *
 * Last Updated:
 *   2026-10-21
 */

declare(strict_types=1);

namespace Gallery\Models;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;
use function Gallery\Core\db;

/**
 * Return the storage table and entity-id column for a supported tag assignment type.
 *
 * @param string $type Assignment owner: gallery, image, or logical music song.
 * @return array{0:string,1:string}
 *     Table name and matching entity-id column.
 * @throws InvalidArgumentException When the assignment type is unsupported.
 */
function tag_model_assignment_target(string $type): array
{
    return match ($type) {
        'gallery' => ['gallery_tags', 'gallery_id'],
        'image' => ['image_tags', 'image_id'],
        'music_song' => ['music_song_tags', 'song_id'],
        default => throw new InvalidArgumentException(
            'Unsupported tag assignment type.',
        ),
    };
}

/** Return one tag id by canonical slug. */
function tag_model_id_by_slug(string $slug): int
{
    $stmt = db()->prepare('SELECT id FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Find a matching tag or insert it using precomputed slug candidates.
 *
 * @param string $name Canonical display name for the shared tag row.
 * @param list<string> $slugCandidates Ordered canonical slugs to try.
 * @param string $now Timestamp used for created_at and updated_at.
 * @param string $mediaType Validated tag category: image, music, or pdf.
 * @param bool $mediaTypeReady Whether tags.media_type was confirmed available.
 * @return int Existing or inserted tag ID, or zero when no candidate is usable.
 * @throws InvalidArgumentException When a slug candidate is malformed.
 * @throws PDOException When storage fails and no concurrent matching row exists.
 */
function tag_model_find_or_create_by_slug_candidates(
    string $name,
    array $slugCandidates,
    string $now,
    string $mediaType,
    bool $mediaTypeReady,
): int {
    if (
        $name === ''
        || $slugCandidates === []
        || !in_array($mediaType, ['image', 'music', 'pdf'], true)
        || (!$mediaTypeReady && $mediaType !== 'image')
    ) {
        return 0;
    }
    foreach ($slugCandidates as $slug) {
        if (!is_string($slug) || $slug === '') {
            throw new InvalidArgumentException(
                'Invalid tag slug candidate.',
            );
        }
        $existingId = tag_model_id_by_slug($slug);
        if ($existingId > 0) {
            if (tag_model_matches_media_type(
                $existingId,
                $mediaType,
                $mediaTypeReady,
            )) {
                return $existingId;
            }
            continue;
        }
        try {
            return tag_model_create(
                $name,
                $slug,
                $now,
                $mediaType,
                $mediaTypeReady,
            );
        } catch (PDOException $exception) {
            // Another writer may have claimed this globally unique slug after
            // the read. Reuse a same-category winner or try the next candidate.
            try {
                $racedId = tag_model_id_by_slug($slug);
            } catch (Throwable) {
                throw $exception;
            }
            if ($racedId < 1) {
                throw $exception;
            }
            if (tag_model_matches_media_type(
                $racedId,
                $mediaType,
                $mediaTypeReady,
            )) {
                return $racedId;
            }
        }
    }
    return 0;
}

/**
 * Return whether a tag has the requested media type when the column is ready.
 *
 * @param int $tagId Existing tag identifier.
 * @param string $mediaType Validated tag media category.
 * @param bool $mediaTypeReady Whether tags.media_type was confirmed available.
 * @return bool True only when the existing row matches the requested category.
 */
function tag_model_matches_media_type(
    int $tagId,
    string $mediaType,
    bool $mediaTypeReady,
): bool {
    if (!$mediaTypeReady) {
        return $mediaType === 'image';
    }
    $stmt = db()->prepare('SELECT media_type FROM tags WHERE id = ?');
    $stmt->execute([$tagId]);
    return $stmt->fetchColumn() === $mediaType;
}

/**
 * Insert one canonical tag row and return its id.
 *
 * @param string $name Display name for the tag.
 * @param string $slug Canonical URL-safe tag slug.
 * @param string $now Timestamp used for both created_at and updated_at.
 * @param string $mediaType Validated category image, music, or pdf; defaults to image.
 * @param bool $mediaTypeReady Whether tags.media_type is confirmed available; defaults to true.
 * @return int Inserted tag ID, or 0 when legacy storage cannot represent the requested category.
 */
function tag_model_create(
    string $name,
    string $slug,
    string $now,
    string $mediaType = 'image',
    bool $mediaTypeReady = true,
): int
{
    $pdo = db();
    if (!$mediaTypeReady) {
        if ($mediaType !== 'image') {
            return 0;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO tags (name, slug, created_at, updated_at) VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([$name, $slug, $now, $now]);
        return (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare(
        'INSERT INTO tags (name, slug, media_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
    );
    $stmt->execute([$name, $slug, $mediaType, $now, $now]);
    return (int) $pdo->lastInsertId();
}

/** Remove every direct tag assignment from one gallery or image. */
function tag_model_clear_entity_tags(string $type, int $id): void
{
    [$table, $idColumn] = tag_model_assignment_target($type);
    db()
        ->prepare('DELETE FROM ' . $table . ' WHERE ' . $idColumn . ' = ?')
        ->execute([$id]);
}

/**
 * Attach one tag to one gallery or image, ignoring an existing identical assignment.
 *
 * @param string $type Assignment owner: gallery, image, or music_song.
 * @param int $id Stable gallery, image, or logical song identifier.
 * @param int $tagId Tag identifier to attach.
 * @param string $mediaType Expected category image or music; defaults to image.
 * @param bool $mediaTypeReady Whether the category column is confirmed available; defaults to true.
 * @return bool True only when this call inserted the assignment.
 */
function tag_model_attach_entity_tag(
    string $type,
    int $id,
    int $tagId,
    string $mediaType = 'image',
    bool $mediaTypeReady = true,
): bool
{
    [$table, $idColumn] = tag_model_assignment_target($type);
    $expectedType = $type === 'music_song' ? 'music' : 'image';
    if (
        $mediaType !== $expectedType
        || !tag_model_matches_media_type($tagId, $expectedType, $mediaTypeReady)
    ) {
        throw new InvalidArgumentException('Tag media type does not match assignment type.');
    }
    $stmt = db()->prepare(
        'INSERT IGNORE INTO '
            . $table
            . ' ('
            . $idColumn
            . ', tag_id) VALUES (?, ?)',
    );
    $stmt->execute([$id, $tagId]);
    return $stmt->rowCount() > 0;
}

/**
 * Add or remove one direct image-tag assignment without replacing other tags.
 *
 * @param int $imageId Stable physical image identifier.
 * @param int $tagId Existing image-category tag identifier.
 * @param string $operation Requested targeted change: add or remove.
 * @param bool $mediaTypeReady Whether tags.media_type is confirmed available.
 * @return bool True only when this call changed the direct assignment.
 * @throws InvalidArgumentException When the image, tag, operation, or category is invalid.
 */
function tag_model_mutate_image_tag(
    int $imageId,
    int $tagId,
    string $operation,
    bool $mediaTypeReady,
): bool {
    if (
        $imageId < 1
        || $tagId < 1
        || !in_array($operation, ['add', 'remove'], true)
        || !tag_model_matches_media_type($tagId, 'image', $mediaTypeReady)
    ) {
        throw new InvalidArgumentException('Invalid image tag mutation.');
    }
    if ($operation === 'add') {
        return tag_model_attach_entity_tag(
            'image',
            $imageId,
            $tagId,
            'image',
            $mediaTypeReady,
        );
    }
    $stmt = db()->prepare(
        'DELETE FROM image_tags WHERE image_id = ? AND tag_id = ?',
    );
    $stmt->execute([$imageId, $tagId]);
    return $stmt->rowCount() > 0;
}

/**
 * Apply one image-tag mutation and read its authoritative state atomically.
 *
 * @param int $imageId Stable physical image identifier.
 * @param int $expectedGalleryId Physical gallery identity held by the caller.
 * @param string $operation Targeted assignment change: add or remove.
 * @param int|null $tagId Existing image-category tag ID, when selected.
 * @param array{name:string,slug_candidates:list<string>,created_at:string}|null $newTag Canonical new tag details for a name-based add.
 * @param bool $mediaTypeReady Whether tags.media_type was confirmed available.
 * @return array{changed_tag:array{id:int,name:string,slug:string},already_present:bool,tags:list<array{id:int,name:string,slug:string}>} Changed tag and committed direct image-tag state.
 * @throws DomainException When the image no longer belongs to the expected gallery.
 * @throws InvalidArgumentException When the operation or selected tag is invalid.
 * @throws Throwable When persistence fails; an owned transaction is rolled back.
 */
function tag_model_mutate_image_tag_atomically(
    int $imageId,
    int $expectedGalleryId,
    string $operation,
    ?int $tagId,
    ?array $newTag,
    bool $mediaTypeReady,
): array {
    if (
        $imageId < 1
        || $expectedGalleryId < 1
        || !in_array($operation, ['add', 'remove'], true)
        || ($operation === 'remove' && ($tagId === null || $newTag !== null))
        || ($operation === 'add' && $tagId !== null && $newTag !== null)
    ) {
        throw new InvalidArgumentException('Invalid image tag mutation.');
    }
    $connection = db();
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }
    try {
        if (!tag_model_lock_image_owner($imageId, $expectedGalleryId)) {
            throw new DomainException(
                'The image no longer belongs to the expected gallery.',
            );
        }
        if ($operation === 'add' && $tagId === null && $newTag !== null) {
            $tagId = tag_model_find_or_create_by_slug_candidates(
                $newTag['name'],
                $newTag['slug_candidates'],
                $newTag['created_at'],
                'image',
                $mediaTypeReady,
            );
        }
        if ($tagId === null || $tagId < 1) {
            throw new InvalidArgumentException('Select a valid image tag.');
        }
        $tag = tag_model_find_by_id($tagId);
        if (
            !is_array($tag)
            || !tag_model_matches_media_type(
                $tagId,
                'image',
                $mediaTypeReady,
            )
        ) {
            throw new InvalidArgumentException(
                'Tag media type does not match image.',
            );
        }
        $before = tag_model_tags_for_entity(
            'image',
            $imageId,
            $mediaTypeReady,
        );
        $alreadyPresent = in_array(
            $tagId,
            array_map(
                static fn(array $row): int => (int) $row['id'],
                $before,
            ),
            true,
        );
        $changed = false;
        if ($operation === 'remove' || !$alreadyPresent) {
            $changed = tag_model_mutate_image_tag(
                $imageId,
                $tagId,
                $operation,
                $mediaTypeReady,
            );
        }
        if ($operation === 'add') {
            // INSERT IGNORE may lose a race after the pre-mutation snapshot.
            $alreadyPresent = !$changed;
        }
        $rows = tag_model_tags_for_entity(
            'image',
            $imageId,
            $mediaTypeReady,
        );
        if ($ownsTransaction) {
            $connection->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }

    return [
        'changed_tag' => [
            'id' => $tagId,
            'name' => (string) $tag['name'],
            'slug' => (string) $tag['slug'],
        ],
        'already_present' => $alreadyPresent,
        'tags' => array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ],
            $rows,
        ),
    ];
}

/**
 * Lock a physical image row and confirm it still belongs to the expected gallery.
 *
 * @param int $imageId Stable physical image identifier.
 * @param int $expectedGalleryId Gallery identity observed by the caller.
 * @return bool True while the row is locked and still owned by that gallery.
 */
function tag_model_lock_image_owner(
    int $imageId,
    int $expectedGalleryId,
): bool {
    if ($imageId < 1 || $expectedGalleryId < 1) {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT gallery_id FROM images WHERE id = ? FOR UPDATE',
    );
    $stmt->execute([$imageId]);
    return (int) $stmt->fetchColumn() === $expectedGalleryId;
}

/**
 * Return whether every selected tag is an existing image-category tag.
 *
 * @param list<int> $tagIds Unique positive tag identifiers to validate.
 * @param bool $mediaTypeReady Whether tags.media_type is confirmed available.
 * @return bool True when all IDs exist and belong to the image category.
 */
function tag_model_ids_match_image_category(
    array $tagIds,
    bool $mediaTypeReady,
): bool {
    $tagIds = array_values(array_unique($tagIds));
    if ($tagIds === []) {
        return true;
    }
    foreach ($tagIds as $tagId) {
        if (!is_int($tagId) || $tagId < 1) {
            return false;
        }
    }
    $marks = implode(',', array_fill(0, count($tagIds), '?'));
    $sql = 'SELECT COUNT(*) FROM tags WHERE id IN (' . $marks . ')';
    if ($mediaTypeReady) {
        $sql .= " AND media_type = 'image'";
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($tagIds);
    return (int) $stmt->fetchColumn() === count($tagIds);
}

/**
 * Replace one entity's validated tag links as a single database transition.
 *
 * @param string $type Allowlisted gallery, image, or music_song assignment.
 * @param int $id Positive stable entity identifier.
 * @param list<int> $tagIds Unique positive IDs resolved by the tag service.
 * @param bool $mediaTypeReady Whether category schema is confirmed available;
 *     false supports only the permanent legacy image-assignment path.
 * @return void Preserves old links on failure and joins an existing catalog
 *     transaction when present; otherwise commits its own transaction.
 * @throws InvalidArgumentException When IDs, storage, or media types disagree.
 * @throws \Throwable When persistence fails; the owning transaction rolls back.
 */
function tag_model_replace_entity_tags(
    string $type,
    int $id,
    array $tagIds,
    bool $mediaTypeReady,
): void {
    [$table, $column] = tag_model_assignment_target($type);
    $mediaType = $type === 'music_song' ? 'music' : 'image';
    if ($id < 1 || (!$mediaTypeReady && $mediaType !== 'image')) {
        throw new InvalidArgumentException('Invalid typed tag assignment.');
    }
    foreach ($tagIds as $tagId) {
        if (!is_int($tagId) || $tagId < 1) {
            throw new InvalidArgumentException('Invalid tag identifier.');
        }
    }
    $tagIds = array_values(array_unique($tagIds));
    $connection = db();
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }
    try {
        if ($tagIds !== []) {
            $marks = implode(',', array_fill(0, count($tagIds), '?'));
            $select = $connection->prepare(
                "SELECT id FROM tags WHERE id IN ($marks)"
                    . ($mediaTypeReady ? ' AND media_type = ?' : ''),
            );
            $select->execute($mediaTypeReady
                ? [...$tagIds, $mediaType]
                : $tagIds);
            if (count($select->fetchAll(PDO::FETCH_COLUMN)) !== count($tagIds)) {
                throw new InvalidArgumentException(
                    'Tag media type does not match assignment type.',
                );
            }
        }
        tag_model_clear_entity_tags($type, $id);
        $insert = $connection->prepare(
            'INSERT INTO ' . $table . ' (' . $column . ', tag_id) VALUES (?, ?)',
        );
        foreach ($tagIds as $tagId) {
            $insert->execute([$id, $tagId]);
        }
        if ($ownsTransaction) {
            $connection->commit();
        }
    } catch (\Throwable $exception) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

/**
 * Return tags directly assigned to one gallery, image, or logical song.
 *
 * @param string $type Assignment owner: gallery, image, or music_song.
 * @param int $id Gallery, image, or logical song identifier.
 * @param bool $mediaTypeReady Whether tags.media_type was confirmed available.
 * @return list<array{id:int|string,name:string,slug:string,media_type?:string,description?:string|null,created_at?:string|null,updated_at?:string|null}> Tag rows ordered by name, or an empty list.
 */
function tag_model_tags_for_entity(
    string $type,
    int $id,
    bool $mediaTypeReady = true,
): array
{
    [$table, $idColumn] = tag_model_assignment_target($type);
    $mediaType = $type === 'music_song' ? 'music' : 'image';
    $mediaPredicate = $mediaTypeReady
        ? ' AND t.media_type = ?'
        : '';
    $stmt = db()->prepare(
        'SELECT t.* FROM tags t JOIN '
            . $table
            . ' mt ON mt.tag_id = t.id WHERE mt.'
            . $idColumn
            . ' = ?'
            . $mediaPredicate
            . ' ORDER BY t.name',
    );
    $stmt->execute($mediaTypeReady ? [$id, $mediaType] : [$id]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return combined gallery and image assignment counts for selected tags.
 *
 * @param array<int,int> $tagIds Tag identifiers whose usage should be counted.
 * @param bool $mediaTypeReady Whether the image category column is verified;
 *     false selects the confirmed pre-category schema.
 * @return list<array{tag_id:int|string,usage_count:int|string}> Image assignment
 *     totals; empty when no IDs or matches exist.
 */
function tag_model_usage_count_rows(
    array $tagIds,
    bool $mediaTypeReady = true,
): array
{
    if ($tagIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
    $stmt = db()->prepare(
        'SELECT usage_rows.tag_id, SUM(usage_rows.usage_count) AS usage_count
         FROM (
             SELECT tag_id, COUNT(*) AS usage_count
             FROM gallery_tags
             WHERE tag_id IN ('
            . $placeholders
            . ')
             GROUP BY tag_id
             UNION ALL
             SELECT tag_id, COUNT(*) AS usage_count
             FROM image_tags
             WHERE tag_id IN ('
            . $placeholders
            . ')
             GROUP BY tag_id
         ) usage_rows '
            . ($mediaTypeReady
                ? "JOIN tags t ON t.id = usage_rows.tag_id AND t.media_type = 'image' "
                : '')
            . 'GROUP BY usage_rows.tag_id',
    );
    $stmt->execute(array_merge($tagIds, $tagIds));
    return $stmt->fetchAll() ?: [];
}

/**
 * Return assigned tags for a set of galleries, images, or music songs.
 *
 * @param string $type Assignment owner: gallery, image, or music_song.
 * @param list<int> $ids Positive gallery, image, or song identifiers.
 * @param bool $mediaTypeReady Whether category inspection confirms availability.
 * @return list<array{entity_id:int|string,id:int|string,name:string,slug:string,media_type?:string,description?:string|null,created_at?:string|null,updated_at?:string|null}> Tag rows ordered by entity and name, or an empty list.
 */
function tag_model_tags_for_entities(
    string $type,
    array $ids,
    bool $mediaTypeReady = true,
): array
{
    if ($ids === []) {
        return [];
    }
    [$table, $idColumn] = tag_model_assignment_target($type);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $mediaPredicate = $mediaTypeReady ? 't.media_type = ? AND ' : '';
    $mediaType = $type === 'music_song' ? 'music' : 'image';
    $stmt = db()->prepare(
        'SELECT mt.'
            . $idColumn
            . ' AS entity_id, t.*
         FROM tags t
         JOIN '
            . $table
            . ' mt ON mt.tag_id = t.id
         WHERE ' . $mediaPredicate . 'mt.'
            . $idColumn
            . ' IN ('
            . $placeholders
            . ')
         ORDER BY mt.'
            . $idColumn
            . ', t.name',
    );
    $stmt->execute($mediaTypeReady ? array_merge([$mediaType], $ids) : $ids);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return image tag names in alphabetical order.
 *
 * @param bool $mediaTypeReady Whether tags.media_type is confirmed available.
 * @return list<string> Alphabetically ordered image tag names.
 */
function tag_model_all_names(bool $mediaTypeReady = true): array
{
    $sql = $mediaTypeReady
        ? 'SELECT name FROM tags WHERE media_type = \'image\' ORDER BY name'
        : 'SELECT name FROM tags ORDER BY name';
    $stmt = db()->prepare($sql);
    $stmt->execute();
    return array_values(array_map(
        'strval',
        $stmt->fetchAll(PDO::FETCH_COLUMN),
    ));
}

/**
 * Return the small selectable image-tag catalog for an authorized editor.
 *
 * @param bool $mediaTypeReady Whether tags.media_type is confirmed available.
 * @return list<array{id:int|string,name:string,slug:string}> Image tags ordered by name and ID.
 */
function tag_model_image_catalog(bool $mediaTypeReady): array
{
    $sql = $mediaTypeReady
        ? "SELECT id, name, slug FROM tags WHERE media_type = 'image' ORDER BY name, id"
        : 'SELECT id, name, slug FROM tags ORDER BY name, id';
    $stmt = db()->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

/**
 * Return the parent and folder context for one gallery.
 *
 * @param int $galleryId Gallery identifier to look up.
 * @return array<string,mixed>|null Row with id, parent_id, and folder_path, or
 *     null when the gallery does not exist.
 */
function tag_model_gallery_context(int $galleryId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, parent_id, folder_path FROM galleries WHERE id = ?',
    );
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return raw usage rows for one weighted suggestion source.
 *
 * @param string $assignmentType gallery or image.
 * @param string $scope current, siblings, descendants, ancestors, or global.
 * @param array{gallery_id?:int,parent_id?:int,folder_prefix?:string,paths?:list<string>} $context Scope identifiers or paths required by the selected filter; defaults to no values.
 * @param bool $mediaTypeReady Whether tags.media_type was confirmed available; defaults to true.
 * @return list<array{id:int|string,name:string,slug:string,usage_count:int|string}> Matching image tag aggregates, or an empty list.
 */
function tag_model_suggestion_usage_rows(
    string $assignmentType,
    string $scope,
    array $context = [],
    bool $mediaTypeReady = true,
): array {
    if (!in_array($assignmentType, ['gallery', 'image'], true)) {
        throw new InvalidArgumentException(
            'Unsupported tag suggestion assignment type.',
        );
    }
    if (!in_array(
        $scope,
        ['current', 'siblings', 'descendants', 'ancestors', 'global'],
        true,
    )) {
        throw new InvalidArgumentException('Unsupported tag suggestion scope.');
    }

    $join = $assignmentType === 'gallery'
        ? 'JOIN gallery_tags a ON a.tag_id = t.id JOIN galleries g ON g.id = a.gallery_id'
        : 'JOIN image_tags a ON a.tag_id = t.id JOIN images i ON i.id = a.image_id JOIN galleries g ON g.id = i.gallery_id';
    $where = $mediaTypeReady
        ? ' WHERE t.media_type = \'image\''
        : '';
    $params = [];
    if ($scope === 'current') {
        $where .= ($where === '' ? ' WHERE ' : ' AND ') . 'g.id = ?';
        $params[] = (int) ($context['gallery_id'] ?? 0);
    } elseif ($scope === 'siblings') {
        $where .= ($where === '' ? ' WHERE ' : ' AND ')
            . 'g.parent_id = ? AND g.id <> ?';
        $params[] = (int) ($context['parent_id'] ?? 0);
        $params[] = (int) ($context['gallery_id'] ?? 0);
    } elseif ($scope === 'descendants') {
        $where .= ($where === '' ? ' WHERE ' : ' AND ')
            . 'g.folder_path LIKE ? AND g.id <> ?';
        $params[] = (string) ($context['folder_prefix'] ?? '');
        $params[] = (int) ($context['gallery_id'] ?? 0);
    } elseif ($scope === 'ancestors') {
        $paths = array_values(array_filter(
            array_map('strval', (array) ($context['paths'] ?? [])),
            static fn(string $path): bool => $path !== '',
        ));
        if ($paths === []) {
            return [];
        }
        $where .=
            ($where === '' ? ' WHERE ' : ' AND ')
            . 'g.folder_path IN ('
            . implode(',', array_fill(0, count($paths), '?'))
            . ')';
        $params = $paths;
    }

    $stmt = db()->prepare(
        'SELECT t.id, t.name, t.slug, COUNT(*) AS usage_count
        FROM tags t '
            . $join
            . $where
            . '
        GROUP BY t.id, t.name, t.slug',
    );
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return globally ranked tag usage aggregates for one media category.
 *
 * @param int $limit Maximum returned row count.
 * @param bool $mediaTypeReady Whether category storage is confirmed available; defaults to true.
 * @param string $mediaType Category to suggest: image, music, or pdf; defaults to image.
 * @param bool $musicAssignmentReady Whether song/tag assignments are confirmed available; defaults to false.
 * @return list<array{id:int|string,name:string,slug:string,gallery_count:int|string,image_count:int|string,music_count:int|string}> Ranked tag usage aggregate rows.
 */
function tag_model_global_suggestion_rows(
    int $limit,
    bool $mediaTypeReady = true,
    string $mediaType = 'image',
    bool $musicAssignmentReady = false,
): array
{
    $limit = max(1, $limit);
    if (!in_array($mediaType, ['image', 'music', 'pdf'], true)) {
        throw new InvalidArgumentException('Unsupported tag media type.');
    }
    if (!$mediaTypeReady && $mediaType !== 'image') {
        return [];
    }
    $where = $mediaTypeReady ? 'WHERE t.media_type = ?' : '';
    $galleryJoin = $mediaType === 'image'
        ? 'LEFT JOIN (SELECT tag_id, COUNT(*) AS gallery_count FROM gallery_tags GROUP BY tag_id) gallery_usage ON gallery_usage.tag_id = t.id'
        : '';
    $imageJoin = $mediaType === 'image'
        ? 'LEFT JOIN (SELECT tag_id, COUNT(*) AS image_count FROM image_tags GROUP BY tag_id) image_usage ON image_usage.tag_id = t.id'
        : '';
    $musicJoin = $mediaType === 'music' && $musicAssignmentReady
        ? 'LEFT JOIN (SELECT tag_id, COUNT(*) AS music_count FROM music_song_tags GROUP BY tag_id) music_usage ON music_usage.tag_id = t.id'
        : '';
    $galleryCount = $mediaType === 'image' ? 'gallery_usage.gallery_count' : '0';
    $imageCount = $mediaType === 'image' ? 'image_usage.image_count' : '0';
    $musicCount = $mediaType === 'music' && $musicAssignmentReady
        ? 'music_usage.music_count'
        : '0';
    $stmt = db()->prepare('SELECT t.id, t.name, t.slug,
            COALESCE(' . $galleryCount . ', 0) AS gallery_count,
            COALESCE(' . $imageCount . ', 0) AS image_count,
            COALESCE(' . $musicCount . ', 0) AS music_count
        FROM tags t
        ' . $galleryJoin . '
        ' . $imageJoin . '
        ' . $musicJoin . '
        ' . $where . '
        ORDER BY (COALESCE(' . $galleryCount . ', 0) + COALESCE(' . $imageCount
            . ', 0) + COALESCE(' . $musicCount . ', 0)) DESC, t.name
        LIMIT ' . $limit);
    $stmt->execute($mediaTypeReady ? [$mediaType] : []);
    return $stmt->fetchAll() ?: [];
}

/** Probe whether the optional tag description column is available. */
function tag_model_description_column_ready(): bool
{
    $stmt = db()->prepare('SELECT description FROM tags LIMIT 1');
    $stmt->execute();
    return true;
}

/**
 * Return admin tag rows with media-specific usage counts.
 *
 * @param bool $descriptionReady Whether the optional description column exists.
 * @param string $sortField Requested sort field; only name and usage are used.
 * @param string $sortDirection Requested direction; only asc selects ascending
 *     order, otherwise descending order is used.
 * @param string $mediaType Media category image, music, or all; defaults to image.
 * @param bool $mediaTypeReady Whether tags.media_type was confirmed available; defaults to true.
 * @param bool $musicAssignmentReady Whether song/tag assignments are confirmed available; defaults to false.
 * @return list<array{id:int|string,name:string,slug:string,description:string,media_type:string,created_at:string,updated_at:string,gallery_count:int|string,image_count:int|string,music_count:int|string,usage_count:int|string}> Admin rows with usage aggregates.
 */
function tag_model_admin_rows(
    bool $descriptionReady,
    string $sortField,
    string $sortDirection,
    string $mediaType = 'image',
    bool $mediaTypeReady = true,
    bool $musicAssignmentReady = false,
): array {
    $safeSortField = in_array($sortField, ['name', 'usage'], true)
        ? $sortField
        : 'usage';
    $safeSortDirection = strtolower($sortDirection) === 'asc' ? 'ASC' : 'DESC';
    $descriptionColumn = $descriptionReady
        ? 't.description'
        : "'' AS description";
    $groupByDescription = $descriptionReady ? ', t.description' : '';
    $orderBy = $safeSortField === 'name'
        ? 't.name ' . $safeSortDirection . ', t.slug ' . $safeSortDirection
        : 'usage_count ' . $safeSortDirection . ', t.name ASC';
    $typeField = $mediaTypeReady ? 't.media_type' : "'image' AS media_type";
    $typeWhere = !$mediaTypeReady
        ? ''
        : ($mediaType === 'all'
            ? "WHERE t.media_type IN ('image', 'music')"
            : 'WHERE t.media_type = ?');
    $musicJoin = $musicAssignmentReady
        ? 'LEFT JOIN music_song_tags mst ON mst.tag_id = t.id'
        : '';
    $musicCount = $musicAssignmentReady
        ? 'COUNT(DISTINCT mst.song_id)'
        : '0';
    $stmt = db()->prepare(
        'SELECT t.id, t.name, t.slug, '
            . $descriptionColumn
            . ', ' . $typeField . ', t.created_at, t.updated_at,
        COUNT(DISTINCT gt.gallery_id) AS gallery_count,
        COUNT(DISTINCT it.image_id) AS image_count,
        COUNT(DISTINCT gt.gallery_id) + COUNT(DISTINCT it.image_id) + '
            . $musicCount . ' AS usage_count,
        ' . $musicCount . ' AS music_count
        FROM tags t
        LEFT JOIN gallery_tags gt ON gt.tag_id = t.id
        LEFT JOIN image_tags it ON it.tag_id = t.id
        ' . $musicJoin . '
        ' . $typeWhere . '
        GROUP BY t.id, t.name, t.slug'
            . $groupByDescription
            . ($mediaTypeReady ? ', t.media_type' : '')
            . ', t.created_at, t.updated_at
        ORDER BY '
            . $orderBy,
    );
    $stmt->execute(
        $mediaTypeReady && $mediaType !== 'all' ? [$mediaType] : [],
    );
    return $stmt->fetchAll() ?: [];
}

/** @return array<int,array<string,mixed>> */
function tag_model_admin_gallery_usage_rows(int $tagId): array
{
    $stmt = db()->prepare('SELECT DISTINCT g.id, g.title, g.slug, g.url_path, g.folder_path
        FROM gallery_tags gt
        JOIN galleries g ON g.id = gt.gallery_id
        WHERE gt.tag_id = ?
        ORDER BY g.title, g.id');
    $stmt->execute([$tagId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return image usage rows for one tag for the Admin usage view.
 *
 * @param int $tagId Tag identifier.
 * @return array<int,array<string,mixed>> Image and gallery display rows ordered
 *     by gallery and image order.
 */
function tag_model_admin_image_usage_rows(int $tagId): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT i.id, i.relative_path, i.filename, i.gallery_id, i.sort_order AS image_sort_order, g.title AS gallery_title, g.slug AS gallery_slug
        FROM image_tags it
        JOIN images i ON i.id = it.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE it.tag_id = ?
        ORDER BY g.title, i.sort_order, i.filename, i.id',
    );
    $stmt->execute([$tagId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return logical song usage rows for one music tag.
 *
 * @param int $tagId Music-tag identifier.
 * @return list<array{id:int|string,title:string|null,indexed_title:string|null,filename:string|null}> Song identities and canonical-title source fields.
 */
function tag_model_admin_song_usage_rows(int $tagId): array
{
    $stmt = db()->prepare(
        'SELECT s.id, s.title,
        (SELECT i.indexed_title FROM music_track_instances i
            WHERE i.song_id = s.id AND i.active = 1
            ORDER BY i.gallery_id, i.track_id LIMIT 1) AS indexed_title,
        (SELECT i.filename FROM music_track_instances i
            WHERE i.song_id = s.id AND i.active = 1
            ORDER BY i.gallery_id, i.track_id LIMIT 1) AS filename
        FROM music_song_tags mst
        JOIN music_songs s ON s.id = mst.song_id
        WHERE mst.tag_id = ?
        ORDER BY s.title, s.id',
    );
    $stmt->execute([$tagId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Find one tag row by its database identifier.
 *
 * @param int $id Tag identifier to find.
 * @return array{id:int|string,name:string,slug:string,media_type?:string,description?:string|null,created_at?:string|null,updated_at?:string|null}|null Matching tag row, or null when absent.
 */
function tag_model_find_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tags WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Return whether another tag owns a slug. */
function tag_model_slug_exists_except(string $slug, int $excludeId): bool
{
    $stmt = db()->prepare('SELECT id FROM tags WHERE slug = ? AND id <> ?');
    $stmt->execute([$slug, $excludeId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Persist canonical tag metadata, including description when supported.
 *
 * @param int $id Tag identifier to update.
 * @param string $name Canonical display name.
 * @param string $slug Canonical URL-safe slug.
 * @param string $description Description value, written only when available.
 * @param bool $descriptionReady Whether the optional description column exists.
 * @param string $now Updated-at timestamp.
 * @return void Persist the canonical tag metadata supported by the schema.
 */
function tag_model_update_metadata(
    int $id,
    string $name,
    string $slug,
    string $description,
    bool $descriptionReady,
    string $now,
): void {
    if ($descriptionReady) {
        $stmt = db()->prepare(
            'UPDATE tags SET name = ?, slug = ?, description = ?, updated_at = ? WHERE id = ?',
        );
        $stmt->execute([$name, $slug, $description, $now, $id]);
        return;
    }
    $stmt = db()->prepare(
        'UPDATE tags SET name = ?, slug = ?, updated_at = ? WHERE id = ?',
    );
    $stmt->execute([$name, $slug, $now, $id]);
}

/**
 * Delete one typed tag and its matching assignments in a transaction.
 *
 * @param int $id Tag identifier to delete.
 * @param string $mediaType Assignment category image or music; defaults to image.
 * @param bool $musicAssignmentReady Whether music assignment storage is available; defaults to false.
 * @return void Delete the tag and the assignments owned by its media category.
 * @throws \Throwable Rethrows a database failure after rolling back an active
 *     transaction.
 */
function tag_model_delete_with_assignments(
    int $id,
    string $mediaType = 'image',
    bool $musicAssignmentReady = false,
): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($mediaType === 'music') {
            if (!$musicAssignmentReady) {
                throw new InvalidArgumentException(
                    'Music tag assignments are not available.',
                );
            }
            $pdo->prepare('DELETE FROM music_song_tags WHERE tag_id = ?')
                ->execute([$id]);
        } elseif ($mediaType === 'image') {
            $pdo->prepare('DELETE FROM gallery_tags WHERE tag_id = ?')->execute([
                $id,
            ]);
            $pdo->prepare('DELETE FROM image_tags WHERE tag_id = ?')->execute([
                $id,
            ]);
        } else {
            throw new InvalidArgumentException(
                'PDF tags do not have an assignment surface.',
            );
        }
        $pdo->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @return array<int,array<string,mixed>> */
function tag_model_all_rows_by_id(): array
{
    $stmt = db()->prepare('SELECT * FROM tags ORDER BY id');
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

/** Delete one tag row only. */
function tag_model_delete_row(int $id): void
{
    db()->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
}

/**
 * Move duplicate tag assignments to a canonical target, then remove duplicate
 * assignments.
 *
 * @param int $targetId Canonical tag identifier to retain.
 * @param int $duplicateId Duplicate tag identifier whose assignments are moved.
 * @param bool $mediaTypeReady Whether tags.media_type is confirmed available.
 * @param bool $musicAssignmentReady Whether music tag assignments are available.
 * @return void Move assignments to the canonical tag and remove the duplicate.
 */
function tag_model_merge_duplicate(
    int $targetId,
    int $duplicateId,
    bool $mediaTypeReady = true,
    bool $musicAssignmentReady = false,
): void
{
    $connection = db();
    $ownsTransaction = !$connection->inTransaction();
    if ($ownsTransaction) {
        $connection->beginTransaction();
    }
    try {
        if ($mediaTypeReady) {
            $stmt = $connection->prepare(
                'SELECT id, media_type FROM tags WHERE id IN (?, ?) ORDER BY id FOR UPDATE',
            );
            $stmt->execute([$targetId, $duplicateId]);
            $rows = $stmt->fetchAll() ?: [];
            if (
                count($rows) !== 2
                || (string) $rows[0]['media_type'] !== (string) $rows[1]['media_type']
            ) {
                throw new InvalidArgumentException(
                    'Tags with different media types cannot be merged.',
                );
            }
            $mediaType = (string) $rows[0]['media_type'];
        } else {
            $mediaType = 'image';
        }
        if ($mediaType === 'music' && !$musicAssignmentReady) {
            throw new InvalidArgumentException(
                'Music tag assignments are not available.',
            );
        }
        if ($mediaType === 'pdf') {
            throw new InvalidArgumentException('PDF tags cannot be merged.');
        }
        if ($mediaType !== 'image' && $mediaType !== 'music') {
            throw new InvalidArgumentException('Unknown tag media type.');
        }
        if ($mediaType === 'music') {
            $connection
                ->prepare(
                    'INSERT IGNORE INTO music_song_tags (song_id, tag_id) SELECT song_id, ? FROM music_song_tags WHERE tag_id = ?',
                )
                ->execute([$targetId, $duplicateId]);
            $connection
                ->prepare('DELETE FROM music_song_tags WHERE tag_id = ?')
                ->execute([$duplicateId]);
        } else {
            $connection
                ->prepare(
                    'INSERT IGNORE INTO gallery_tags (gallery_id, tag_id) SELECT gallery_id, ? FROM gallery_tags WHERE tag_id = ?',
                )
                ->execute([$targetId, $duplicateId]);
            $connection
                ->prepare(
                    'INSERT IGNORE INTO image_tags (image_id, tag_id) SELECT image_id, ? FROM image_tags WHERE tag_id = ?',
                )
                ->execute([$targetId, $duplicateId]);
            $connection
                ->prepare('DELETE FROM gallery_tags WHERE tag_id = ?')
                ->execute([$duplicateId]);
            $connection
                ->prepare('DELETE FROM image_tags WHERE tag_id = ?')
                ->execute([$duplicateId]);
        }
        $connection->prepare('DELETE FROM tags WHERE id = ?')
            ->execute([$duplicateId]);
        if ($ownsTransaction) {
            $connection->commit();
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

/**
 * Update one tag to canonical name and slug.
 *
 * @param int $id Tag identifier to update.
 * @param string $name Canonical display name.
 * @param string $slug Canonical URL-safe slug.
 * @param string $now Updated-at timestamp.
 * @return void Persist the canonical tag name, slug, and update timestamp.
 */
function tag_model_update_canonical(
    int $id,
    string $name,
    string $slug,
    string $now,
): void {
    db()
        ->prepare(
            'UPDATE tags SET name = ?, slug = ?, updated_at = ? WHERE id = ?',
        )
        ->execute([$name, $slug, $now, $id]);
}

/**
 * Find a tag by slug with an optional category boundary.
 *
 * @param string $slug Canonical tag slug.
 * @param bool $mediaTypeReady Whether tags.media_type is confirmed available.
 * @param string $mediaType Category to constrain when the column is available.
 * @return array{id:int|string,name:string,slug:string,media_type?:string,description?:string|null,created_at?:string|null,updated_at?:string|null}|null Matching tag row, or null when absent.
 */
function tag_model_find_by_slug(
    string $slug,
    bool $mediaTypeReady = true,
    string $mediaType = 'image',
): ?array
{
    $sql = 'SELECT * FROM tags WHERE slug = ?'
        . ($mediaTypeReady ? ' AND media_type = ?' : '');
    $stmt = db()->prepare($sql);
    $stmt->execute($mediaTypeReady ? [$slug, $mediaType] : [$slug]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return public galleries associated with a tag through gallery or image tags.
 *
 * @param int $tagId Tag identifier to find.
 * @param bool $accessListingReady Whether the optional access_listing column is
 *     available for public listing filtering.
 * @param bool $mediaTypeReady Whether tags.media_type is confirmed available; defaults to true.
 * @return list<array{id:int|string,title:string,slug:string,url_path:string,folder_path:string,image_count:int|string}> Public gallery rows and image counts.
 */
function tag_model_public_galleries(
    int $tagId,
    bool $accessListingReady,
    bool $mediaTypeReady = true,
): array
{
    $listing =
        "g.visibility = 'public'"
        . ($accessListingReady ? " AND g.access_listing = 'listed'" : '');
    $stmt = db()->prepare("SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE "
        . $listing
        . ($mediaTypeReady
            ? ' AND EXISTS (SELECT 1 FROM tags typed_tag WHERE typed_tag.id = ? AND typed_tag.media_type = \'image\')'
            : '')
        . ' AND (
            EXISTS (SELECT 1 FROM gallery_tags gt WHERE gt.gallery_id = g.id AND gt.tag_id = ?)
            OR EXISTS (SELECT 1 FROM image_tags it JOIN images tagged_image ON tagged_image.id = it.image_id WHERE tagged_image.gallery_id = g.id AND it.tag_id = ?)
        )
        GROUP BY g.id
        ORDER BY g.sort_order, g.title');
    $stmt->execute($mediaTypeReady
        ? [$tagId, $tagId, $tagId]
        : [$tagId, $tagId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return image tags assigned within a gallery folder subtree.
 *
 * @param string $folderPath Folder path prefix used to select galleries.
 * @param bool $publicOnly Whether only public galleries and images are included.
 * @param bool $accessListingReady Whether the optional access_listing column is
 *     available for public listing filtering.
 * @param bool $mediaTypeReady Whether tags.media_type is confirmed available; defaults to true.
 * @return list<array{id:int|string,name:string,slug:string}> Distinct matching tag rows.
 */
function tag_model_contained_for_gallery(
    string $folderPath,
    bool $publicOnly,
    bool $accessListingReady,
    bool $mediaTypeReady = true,
): array {
    $mediaFilter = $mediaTypeReady
        ? " AND t.media_type = 'image'"
        : '';
    $galleryVisibility = '';
    if ($publicOnly) {
        $galleryVisibility =
            " AND g.visibility = 'public'"
            . ($accessListingReady ? " AND g.access_listing = 'listed'" : '');
    }
    $imageVisibility = $publicOnly
        ? " AND tagged_image.visibility = 'public'"
        : '';
    $stmt = db()->prepare(
        'SELECT DISTINCT t.id, t.name, t.slug
        FROM tags t
        JOIN gallery_tags gt ON gt.tag_id = t.id
        JOIN galleries g ON g.id = gt.gallery_id
        WHERE g.folder_path LIKE ?'
            . $mediaFilter
            . $galleryVisibility
            . '
        UNION
        SELECT DISTINCT t.id, t.name, t.slug
        FROM tags t
        JOIN image_tags it ON it.tag_id = t.id
        JOIN images tagged_image ON tagged_image.id = it.image_id
        JOIN galleries g ON g.id = tagged_image.gallery_id
        WHERE g.folder_path LIKE ?'
            . $mediaFilter
            . $galleryVisibility
            . $imageVisibility
            . '
        ORDER BY name',
    );
    $stmt->execute([$folderPath . '/%', $folderPath . '/%']);
    return $stmt->fetchAll() ?: [];
}
