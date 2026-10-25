<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/smart_galleries.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns Smart Gallery persistence and database query compilation.
 *
 * Responsibilities:
 *   - Persist Smart Gallery definitions and physical-gallery placements
 *   - Load bounded relationship-graph source rows
 *   - Compile validated semantic Smart Gallery rules into parameterized SQL
 *   - Execute count, image, card-summary, and membership queries without leaking SQL fragments to services
 *   - Load supporting gallery/tag rows used by service-layer presentation and diagnostics
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
 *   - Rule documents passed here must already be normalized by the Smart Gallery service.
 *   - Dynamic identifiers and ORDER BY expressions are selected only from internal allowlists.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use Throwable;
use function Gallery\Core\db;

/**
 * Fetch gallery hierarchy rows with a pre-allocation row ceiling.
 *
 * @param int $maxRows Maximum accepted source row count.
 * @return array<int,array<string,mixed>> Gallery hierarchy rows.
 */
function smart_gallery_model_graph_gallery_rows(int $maxRows): array
{
    return smart_gallery_model_bounded_rows(
        'SELECT id, parent_id FROM galleries ORDER BY id',
        $maxRows,
    );
}

/**
 * Fetch Smart Gallery definition rows with a pre-allocation row ceiling.
 *
 * @param int $maxRows Maximum accepted source row count.
 * @return array<int,array<string,mixed>> Definition rows.
 */
function smart_gallery_model_graph_definition_rows(int $maxRows): array
{
    return smart_gallery_model_bounded_rows(
        'SELECT id, rules_json FROM smart_galleries ORDER BY id',
        $maxRows,
    );
}

/**
 * Fetch placement rows with a pre-allocation row ceiling.
 *
 * @param int $maxRows Maximum accepted source row count.
 * @param bool $metadataReady Whether placement/order columns are available.
 * @return array<int,array<string,mixed>> Placement rows.
 */
function smart_gallery_model_graph_placement_rows(
    int $maxRows,
    bool $metadataReady,
): array {
    $columns = $metadataReady
        ? 'smart_gallery_id, gallery_id, placement, placement_order'
        : "smart_gallery_id, gallery_id, 'bottom' AS placement, 0 AS placement_order";
    return smart_gallery_model_bounded_rows(
        'SELECT '
            . $columns
            . ' FROM smart_gallery_placements ORDER BY gallery_id, smart_gallery_id',
        $maxRows,
    );
}

/**
 * Fetch one bounded result set before PHP materializes an unbounded relationship graph.
 *
 * @param string $sql Internal fixed SQL statement.
 * @param int $maxRows Maximum accepted source row count.
 * @return array<int,array<string,mixed>> Result rows, including at most ceiling+1 rows.
 */
function smart_gallery_model_bounded_rows(string $sql, int $maxRows): array
{
    $limit = max(1, $maxRows) + 1;
    return db()->query($sql . ' LIMIT ' . $limit)->fetchAll();
}

/**
 * Return existing Smart Gallery identifiers from one bounded candidate set.
 *
 * @param array<int,int> $ids Candidate identifiers.
 * @return array<int,int> Existing identifiers.
 */
function smart_gallery_model_existing_ids(array $ids): array
{
    $ids = smart_gallery_model_normalize_ids($ids);
    if ($ids === [])
        return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        'SELECT id FROM smart_galleries WHERE id IN (' . $marks . ')',
    );
    $stmt->execute($ids);
    return array_values(array_map(
        'intval',
        $stmt->fetchAll(\PDO::FETCH_COLUMN),
    ));
}

/**
 * Check for any stored Smart Gallery, including private or disabled definitions.
 * @return bool Whether at least one definition exists, without loading rules or placements.
 */
function smart_gallery_model_has_any(): bool
{
    return (
        db()->query('SELECT 1 FROM smart_galleries LIMIT 1')->fetchColumn()
            !== false
    );
}

/** Return all Smart Gallery definitions for the Admin list. */
function smart_gallery_model_all(): array
{
    return db()
        ->query('SELECT * FROM smart_galleries ORDER BY title ASC, id ASC')
        ->fetchAll();
}

/**
 * Read a bounded definition/placement inventory for visit-progress projection.
 * @param int $limit Maximum admitted definitions or placement edges, plus sentinel.
 * @return array{definitions:list<array<string,scalar|null>>,
 *     placements:list<array{smart_gallery_id:int|string,gallery_id:int|string}>}
 *     Enabled public definitions and their physical placements, never visitors.
 */
function smart_gallery_model_visit_inventory(int $limit): array
{
    return [
        'definitions' => db()->query(
            "SELECT * FROM smart_galleries WHERE enabled = 1 "
            . "AND visibility = 'public' ORDER BY id LIMIT " . ($limit + 1),
        )->fetchAll(\PDO::FETCH_ASSOC),
        'placements' => db()->query(
            'SELECT smart_gallery_id, gallery_id FROM smart_gallery_placements '
            . 'ORDER BY gallery_id, smart_gallery_id LIMIT ' . ($limit + 1),
        )->fetchAll(\PDO::FETCH_ASSOC),
    ];
}

/**
 * Read physical placement owners affected by explicit definition changes.
 * @param list<int> $smartIds Changed definition identities, including disabled.
 * @param int $limit Maximum admitted parent IDs plus overflow sentinel.
 * @return list<int> Unique physical placement parents, without source traversal.
 */
function smart_gallery_model_visit_parent_ids(array $smartIds, int $limit): array
{
    if ($smartIds === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($smartIds), '?'));
    $query = db()->prepare(
        'SELECT DISTINCT gallery_id FROM smart_gallery_placements '
        . 'WHERE smart_gallery_id IN (' . $marks . ') ORDER BY gallery_id '
        . 'LIMIT ' . ($limit + 1),
    );
    $query->execute($smartIds);
    return array_map('intval', $query->fetchAll(\PDO::FETCH_COLUMN));
}

/**
 * Read actual Smart membership identities in one bounded union per batch.
 * @param list<array{id:int,rules:array<string,mixed>}> $definitions Validated
 *     definitions; each rules root is a recursive Smart group/condition tree.
 * @param list<int> $sourceIds Public listed physical source identities.
 * @param int $limit Maximum admitted membership rows, plus overflow sentinel.
 * @return list<array{smart_id:int|string,id:int|string,gallery_id:int|string,
 *     nsfw:int|string}> Image membership with separate source authorization lanes.
 */
function smart_gallery_model_visit_members(
    array $definitions,
    array $sourceIds,
    int $limit,
): array {
    $selects = [];
    $params = [];
    foreach ($definitions as $definition) {
        $query = smart_gallery_model_result_query(
            $definition['rules'], $sourceIds, true, true, 'created_at', 'asc',
        );
        $selects[] = 'SELECT ' . (int) $definition['id'] . ' AS smart_id, '
            . 'i.id, i.gallery_id, CASE WHEN COALESCE(i.nsfw_enabled, 0) <> 0 '
            . 'OR COALESCE(g.nsfw_enabled, 0) <> 0 THEN 1 ELSE 0 END AS nsfw '
            . 'FROM images i INNER JOIN galleries g ON g.id = i.gallery_id '
            . 'WHERE ' . $query['where'];
        $params = array_merge($params, $query['params']);
    }
    if ($selects === []) {
        return [];
    }
    $statement = db()->prepare(
        implode(' UNION ALL ', $selects) . ' LIMIT ' . ($limit + 1),
    );
    $statement->execute($params);
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Return all placement rows used to attach placement metadata to Admin definitions.
 *
 * @param bool $metadataReady Whether placement/order columns are available.
 * @return list<array{
 *     smart_gallery_id:int|string,
 *     gallery_id:int|string,
 *     placement:string,
 *     placement_order:int|string
 * }> Placement rows; legacy fallbacks use bottom placement and order zero.
 */
function smart_gallery_model_all_placements(bool $metadataReady): array
{
    $columns = $metadataReady
        ? 'smart_gallery_id, gallery_id, placement, placement_order'
        : "smart_gallery_id, gallery_id, 'bottom' AS placement, 0 AS placement_order";
    return db()
        ->query(
            'SELECT '
                . $columns
                . ' FROM smart_gallery_placements ORDER BY gallery_id, smart_gallery_id',
        )
        ->fetchAll();
}

/** Find one Smart Gallery definition by id. */
function smart_gallery_model_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM smart_galleries WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Find one enabled public Smart Gallery definition by slug.
 * @param string $slug Public Smart Gallery slug to find.
 * @return array{
 *     id:int|string,
 *     title:string,
 *     slug:string,
 *     description:string|null,
 *     rules_json:string,
 *     rule_version:int|string,
 *     enabled:int|string,
 *     visibility:string,
 *     sort_mode:string,
 *     sort_direction:string,
 *     created_at:string,
 *     updated_at:string,
 *     placement_mode?:string,
 *     parent_gallery_id?:int|string|null,
 *     presentation_json?:string|null
 * }|null Enabled public definition, or null when no row matches.
 */
function smart_gallery_model_find_public_by_slug(string $slug): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM smart_galleries WHERE slug = ? AND enabled = 1 AND visibility = 'public' LIMIT 1",
    );
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Find one enabled public Smart Gallery definition by id.
 * @param int $id Public Smart Gallery identifier to find.
 * @return array{
 *     id:int|string,
 *     title:string,
 *     slug:string,
 *     description:string|null,
 *     rules_json:string,
 *     rule_version:int|string,
 *     enabled:int|string,
 *     visibility:string,
 *     sort_mode:string,
 *     sort_direction:string,
 *     created_at:string,
 *     updated_at:string,
 *     placement_mode?:string,
 *     parent_gallery_id?:int|string|null,
 *     presentation_json?:string|null
 * }|null Enabled public definition, or null when no row matches.
 */
function smart_gallery_model_find_public_by_id(int $id): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM smart_galleries WHERE id = ? AND enabled = 1 AND visibility = 'public' LIMIT 1",
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Persist one normalized Smart Gallery definition.
 *
 * @param array<string,mixed> $definition Normalized service-owned fields.
 * @param int $id Existing identifier or zero for insert.
 * @return int Saved identifier.
 */
function smart_gallery_model_save(array $definition, int $id): int
{
    $values = [
        (string) $definition['title'],
        (string) $definition['slug'],
        (string) $definition['description'],
        (string) $definition['rules_json'],
        (int) $definition['rule_version'],
        (int) $definition['enabled'],
        (string) $definition['visibility'],
        (string) $definition['placement_mode'],
        null,
        (string) $definition['sort_mode'],
        (string) $definition['sort_direction'],
        (string) $definition['presentation_json'],
    ];
    if ($id > 0) {
        $stmt = db()->prepare(
            'UPDATE smart_galleries SET title=?, slug=?, description=?, rules_json=?, rule_version=?, enabled=?, visibility=?, placement_mode=?, parent_gallery_id=?, sort_mode=?, sort_direction=?, presentation_json=?, updated_at=? WHERE id=?',
        );
        $stmt->execute(array_merge($values, [
            (string) $definition['updated_at'],
            $id,
        ]));
        return $id;
    }

    $stmt = db()->prepare(
        'INSERT INTO smart_galleries (title,slug,description,rules_json,rule_version,enabled,visibility,placement_mode,parent_gallery_id,sort_mode,sort_direction,presentation_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    );
    $stmt->execute(array_merge($values, [
        (string) $definition['created_at'],
        (string) $definition['updated_at'],
    ]));
    return (int) db()->lastInsertId();
}

/** Return whether a slug is already owned by another definition.
 * @param string $slug Candidate slug to check.
 * @param int $excludeId Definition excluded during edits; zero for a new definition.
 * @return bool True when another stored definition owns the slug.
 */
function smart_gallery_model_slug_exists(string $slug, int $excludeId): bool
{
    $stmt = db()->prepare(
        'SELECT id FROM smart_galleries WHERE slug = ? AND id <> ? LIMIT 1',
    );
    $stmt->execute([$slug, $excludeId]);
    return (bool) $stmt->fetchColumn();
}

/** Delete one Smart Gallery definition. */
function smart_gallery_model_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM smart_galleries WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Return attachment rows for one physical gallery.
 *
 * @param int $galleryId Physical gallery identifier.
 * @param bool $metadataReady Whether placement/order metadata is available.
 * @return list<array{
 *     id:int|string,
 *     title:string,
 *     slug:string,
 *     description:string|null,
 *     rules_json:string,
 *     rule_version:int|string,
 *     enabled:int|string,
 *     visibility:string,
 *     sort_mode:string,
 *     sort_direction:string,
 *     created_at:string,
 *     updated_at:string,
 *     placement:string,
 *     placement_order:int|string,
 *     placement_mode?:string,
 *     parent_gallery_id?:int|string|null,
 *     presentation_json?:string|null
 * }> Definitions joined to one physical gallery placement.
 */
function smart_gallery_model_attachment_rows_for_gallery(
    int $galleryId,
    bool $metadataReady,
): array {
    $placementSelect = $metadataReady
        ? 'sgp.placement, sgp.placement_order'
        : "'bottom' AS placement, 0 AS placement_order";
    $order = $metadataReady
        ? "CASE sgp.placement WHEN 'top' THEN 0 ELSE 1 END, sgp.placement_order, sg.id"
        : 'sg.id';
    $stmt = db()->prepare(
        'SELECT sg.*, '
            . $placementSelect
            . ' FROM smart_gallery_placements sgp INNER JOIN smart_galleries sg ON sg.id = sgp.smart_gallery_id WHERE sgp.gallery_id = ? ORDER BY '
            . $order,
    );
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll();
}

/**
 * Return enabled public root definitions or definitions attached to one physical gallery.
 *
 * @param ?int $parentGalleryId Null for root listings.
 * @param bool $metadataReady Whether placement/order metadata is available.
 * @return list<array{
 *     id:int|string,
 *     title:string,
 *     slug:string,
 *     description:string|null,
 *     rules_json:string,
 *     rule_version:int|string,
 *     enabled:int|string,
 *     visibility:string,
 *     sort_mode:string,
 *     sort_direction:string,
 *     created_at:string,
 *     updated_at:string,
 *     placement_mode:string,
 *     parent_gallery_id?:int|string|null,
 *     presentation_json?:string|null,
 *     placement?:string,
 *     placement_order?:int|string
 * }> Enabled public definitions for root or requested gallery placement.
 */
function smart_gallery_model_for_placement(
    ?int $parentGalleryId,
    bool $metadataReady,
): array {
    if ($parentGalleryId === null) {
        return db()
            ->query(
                "SELECT sg.* FROM smart_galleries sg WHERE sg.placement_mode = 'root' AND sg.enabled = 1 AND sg.visibility = 'public' ORDER BY sg.title, sg.id",
            )
            ->fetchAll();
    }
    $select = $metadataReady
        ? ', sgp.placement, sgp.placement_order'
        : ", 'bottom' AS placement, 0 AS placement_order";
    $order = $metadataReady
        ? "CASE sgp.placement WHEN 'top' THEN 0 ELSE 1 END, sgp.placement_order, sg.id"
        : 'sg.id';
    $stmt = db()->prepare(
        "SELECT sg.*{$select} FROM smart_galleries sg INNER JOIN smart_gallery_placements sgp ON sgp.smart_gallery_id = sg.id WHERE sg.placement_mode = 'gallery' AND sgp.gallery_id = ? AND sg.enabled = 1 AND sg.visibility = 'public' ORDER BY {$order}",
    );
    $stmt->execute([$parentGalleryId]);
    return $stmt->fetchAll();
}

/**
 * Atomically replace every Smart Gallery attachment beneath one physical gallery.
 *
 * @param int $galleryId Physical gallery identifier.
 * @param array<int,array{smart_gallery_id:int,placement:string,placement_order:int}> $attachments Normalized attachments.
 * @param string $now Shared SQL timestamp.
 * @return void Replaces all attachment rows in the caller-owned or locally opened transaction.
 */
function smart_gallery_model_replace_gallery_attachments(
    int $galleryId,
    array $attachments,
    string $now,
): void {
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction)
        $pdo->beginTransaction();
    try {
        $clear = $pdo->prepare(
            'DELETE FROM smart_gallery_placements WHERE gallery_id = ?',
        );
        $clear->execute([$galleryId]);
        if ($attachments !== []) {
            $place = $pdo->prepare(
                'INSERT INTO smart_gallery_placements (smart_gallery_id, gallery_id, placement, placement_order, created_at) VALUES (?, ?, ?, ?, ?)',
            );
            $ids = [];
            foreach ($attachments as $attachment) {
                $smartId = (int) ($attachment['smart_gallery_id'] ?? 0);
                if ($smartId <= 0)
                    continue;
                $place->execute([
                    $smartId,
                    $galleryId,
                    (string) ($attachment['placement'] ?? 'bottom'),
                    (int) ($attachment['placement_order'] ?? 0),
                    $now,
                ]);
                $ids[$smartId] = $smartId;
            }
            if ($ids !== []) {
                $idList = array_values($ids);
                $marks = implode(',', array_fill(0, count($idList), '?'));
                $markAsChildren = $pdo->prepare(
                    "UPDATE smart_galleries SET placement_mode='gallery', parent_gallery_id=NULL, updated_at=? WHERE id IN ($marks)",
                );
                $markAsChildren->execute(array_merge([$now], $idList));
            }
        }
        if ($ownsTransaction)
            $pdo->commit();
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction())
            $pdo->rollBack();
        throw $exception;
    }
}

/**
 * Return every physical gallery currently listing one Smart Gallery.
 *
 * @param int $smartGalleryId Smart Gallery identifier.
 * @param bool $metadataReady Whether placement/order metadata is available.
 * @return list<array{
 *     id:int|string,
 *     title:string,
 *     folder_path:string,
 *     visibility:string,
 *     access_mode:string,
 *     placement:string,
 *     placement_order:int|string
 * }> Physical galleries attached to the Smart Gallery, including legacy
 *     defaults.
 */
function smart_gallery_model_placement_galleries(
    int $smartGalleryId,
    bool $metadataReady,
): array {
    $select = $metadataReady
        ? 'sgp.placement, sgp.placement_order'
        : "'bottom' AS placement, 0 AS placement_order";
    $stmt = db()->prepare(
        'SELECT g.id, g.title, g.folder_path, g.visibility, g.access_mode, '
            . $select
            . ' FROM smart_gallery_placements sgp INNER JOIN galleries g ON g.id = sgp.gallery_id WHERE sgp.smart_gallery_id = ? ORDER BY g.folder_path, g.title, g.id',
    );
    $stmt->execute([$smartGalleryId]);
    return $stmt->fetchAll();
}

/** Update one existing attachment placement/order.
 * @param int $smartGalleryId Smart Gallery whose attachment is updated.
 * @param int $galleryId Physical gallery owning the attachment.
 * @param string $placement Normalized top or bottom placement value.
 * @param int $placementOrder Normalized order within the placement.
 * @return bool True when the attachment exists after the call, including if values were already equal.
 */
function smart_gallery_model_update_placement(
    int $smartGalleryId,
    int $galleryId,
    string $placement,
    int $placementOrder,
): bool {
    $stmt = db()->prepare(
        'UPDATE smart_gallery_placements SET placement = ?, placement_order = ? WHERE smart_gallery_id = ? AND gallery_id = ?',
    );
    $stmt->execute([$placement, $placementOrder, $smartGalleryId, $galleryId]);
    if ($stmt->rowCount() > 0)
        return true;
    $exists = db()->prepare(
        'SELECT 1 FROM smart_gallery_placements WHERE smart_gallery_id = ? AND gallery_id = ? LIMIT 1',
    );
    $exists->execute([$smartGalleryId, $galleryId]);
    return (bool) $exists->fetchColumn();
}

/** Remove one physical placement.
 * @param int $smartGalleryId Smart Gallery whose attachment is removed.
 * @param int $galleryId Physical gallery owning the attachment.
 * @return bool True when one placement row was deleted.
 */
function smart_gallery_model_remove_placement(
    int $smartGalleryId,
    int $galleryId,
): bool {
    $stmt = db()->prepare(
        'DELETE FROM smart_gallery_placements WHERE smart_gallery_id = ? AND gallery_id = ?',
    );
    $stmt->execute([$smartGalleryId, $galleryId]);
    return $stmt->rowCount() > 0;
}

/** Return every physical gallery row for service-layer access filtering. */
function smart_gallery_model_all_source_galleries(): array
{
    return db()->query('SELECT * FROM galleries ORDER BY id')->fetchAll();
}

/**
 * Count images matching one normalized semantic Smart Gallery query.
 *
 * @param array{
 *     version:int,
 *     root:array{
 *         type:string,
 *         operator?:string,
 *         children?:list<array{
 *             type:string,
 *             operator?:string,
 *             field?:string,
 *             value?:int|float|string|list<int>|array{
 *                 0:int|float|string,
 *                 1:int|float|string
 *             }|null
 *         }>,
 *         field?:string,
 *         value?:int|float|string|list<int>|array{
 *             0:int|float|string,
 *             1:int|float|string
 *         }|null
 *     },
 *     _photo_tag_media_type_ready?:bool
 * } $rules Validated versioned rules. Group children recurse through the same
 *     group and condition forms; value is omitted for value-free operators.
 *     `_photo_tag_media_type_ready` is internal to compilation and never
 *     persisted.
 * @param array<int,int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @return int Number of images matching canonical rule, gallery-access, visibility, and NSFW scope.
 */
function smart_gallery_model_count_images(
    array $rules,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
): int {
    $query = smart_gallery_model_result_query(
        $rules,
        $accessibleGalleryIds,
        $publicOnly,
        $allowNsfw,
        'capture_date',
        'desc',
    );
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE '
            . $query['where'],
    );
    $stmt->execute($query['params']);
    return (int) $stmt->fetchColumn();
}

/**
 * Query one bounded page of matching Smart Gallery images.
 *
 * @param array{
 *     version:int,
 *     root:array{
 *         type:string,
 *         operator?:string,
 *         children?:list<array{
 *             type:string,
 *             operator?:string,
 *             field?:string,
 *             value?:int|float|string|list<int>|array{
 *                 0:int|float|string,
 *                 1:int|float|string
 *             }|null
 *         }>,
 *         field?:string,
 *         value?:int|float|string|list<int>|array{
 *             0:int|float|string,
 *             1:int|float|string
 *         }|null
 *     },
 *     _photo_tag_media_type_ready?:bool
 * } $rules Validated versioned rules. Group children recurse through the same
 *     group and condition forms; value is omitted for value-free operators.
 *     `_photo_tag_media_type_ready` is an internal compiler capability marker
 *     set by the service and is never persisted.
 * @param array<int,int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @param string $sortMode Allowlisted sort mode.
 * @param string $sortDirection asc or desc.
 * @param int $limit Bounded row limit.
 * @param int $offset Non-negative offset.
 * @return list<array{
 *     id:int|string,
 *     gallery_id:int|string,
 *     relative_path:string,
 *     relative_path_hash:string,
 *     filename:string,
 *     title:string|null,
 *     description:string|null,
 *     url_slug?:string|null,
 *     content_language?:string|null,
 *     width:int|string|null,
 *     height:int|string|null,
 *     display_width?:int|string|null,
 *     display_height?:int|string|null,
 *     exif_orientation?:int|string|null,
 *     thumbnail_derivative_version?:int|string,
 *     thumbnail_metadata_refreshed_at?:string|null,
 *     mime_type:string|null,
 *     file_size:int|string|null,
 *     modified_at:string|null,
 *     checksum_sha256:string|null,
 *     sort_order:int|string,
 *     visibility:string,
 *     editorial_rating?:int|string|null,
 *     nsfw_enabled?:int|string,
 *     thumbnail_min_size?:int|string|null,
 *     thumbnail_max_size?:int|string|null,
 *     exif_taken_at?:string|null,
 *     exif_camera_make?:string|null,
 *     exif_camera_model?:string|null,
 *     exif_lens_model?:string|null,
 *     exif_focal_length?:string|null,
 *     exif_aperture?:string|null,
 *     exif_exposure_time?:string|null,
 *     exif_iso?:int|string|null,
 *     gps_lat?:float|string|null,
 *     gps_lng?:float|string|null,
 *     gps_altitude?:float|string|null,
 *     gps_extracted_at?:string|null,
 *     source_gallery_title:string,
 *     source_gallery_slug:string,
 *     source_gallery_folder_path:string,
 *     source_gallery_visibility:string,
 *     source_gallery_access_mode:string
 * }> One bounded image page with source-gallery context.
 */
function smart_gallery_model_query_images(
    array $rules,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
    string $sortMode,
    string $sortDirection,
    int $limit,
    int $offset,
): array {
    $query = smart_gallery_model_result_query(
        $rules,
        $accessibleGalleryIds,
        $publicOnly,
        $allowNsfw,
        $sortMode,
        $sortDirection,
    );
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $sql =
        'SELECT i.*, g.title AS source_gallery_title, g.slug AS source_gallery_slug, g.folder_path AS source_gallery_folder_path, g.visibility AS source_gallery_visibility, g.access_mode AS source_gallery_access_mode FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE '
        . $query['where']
        . ' ORDER BY '
        . $query['order']
        . ' LIMIT '
        . $limit
        . ' OFFSET '
        . $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($query['params']);
    return $stmt->fetchAll();
}

/** Count GPS-capable rows in one canonical Smart Gallery result scope.
 * @param array{
 *     version:int,
 *     root:array{
 *         type:string,
 *         operator?:string,
 *         children?:list<array{
 *             type:string,
 *             operator?:string,
 *             field?:string,
 *             value?:int|float|string|list<int>|array{
 *                 0:int|float|string,
 *                 1:int|float|string
 *             }|null
 *         }>,
 *         field?:string,
 *         value?:int|float|string|list<int>|array{
 *             0:int|float|string,
 *             1:int|float|string
 *         }|null
 *     }
 * } $rules Validated versioned rules for the canonical result scope. Groups
 *     recursively contain group or condition nodes; value is omitted for
 *     value-free operators.
 * @param list<int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @return int Number of GPS-capable images in the canonical result scope.
 */
function smart_gallery_model_count_map_images(
    array $rules,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
): int {
    $query = smart_gallery_model_result_query(
        $rules,
        $accessibleGalleryIds,
        $publicOnly,
        $allowNsfw,
        'capture_date',
        'desc',
    );
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE '
            . $query['where']
            . ' AND i.gps_lat IS NOT NULL AND i.gps_lng IS NOT NULL',
    );
    $stmt->execute($query['params']);
    return (int) $stmt->fetchColumn();
}

/**
 * Query a bounded GPS-only projection from the canonical Smart Gallery result set.
 *
 * The projection deliberately avoids SELECT * because aggregate maps need only
 * route/title/GPS fields. Canonical result ordering is preserved for deterministic
 * payloads and future Smart Gallery-context marker navigation.
 * @param array{
 *     version:int,
 *     root:array{
 *         type:string,
 *         operator?:string,
 *         children?:list<array{
 *             type:string,
 *             operator?:string,
 *             field?:string,
 *             value?:int|float|string|list<int>|array{
 *                 0:int|float|string,
 *                 1:int|float|string
 *             }|null
 *         }>,
 *         field?:string,
 *         value?:int|float|string|list<int>|array{
 *             0:int|float|string,
 *             1:int|float|string
 *         }|null
 *     }
 * } $rules Validated versioned rules for the canonical result scope. Groups
 *     recursively contain group or condition nodes; value is omitted for
 *     value-free operators.
 * @param list<int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @param string $sortMode Allowlisted service-owned sort mode.
 * @param string $sortDirection Ascending or descending order.
 * @param int $limit Maximum number of GPS image rows returned.
 * @return list<array{
 *     id:int|string,
 *     gallery_id:int|string,
 *     filename:string,
 *     url_slug:string|null,
 *     title:string|null,
 *     description:string|null,
 *     content_language:string|null,
 *     gps_lat:float|string|null,
 *     gps_lng:float|string|null
 * }> Bounded GPS projection in canonical result order.
 */
function smart_gallery_model_query_map_images(
    array $rules,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
    string $sortMode,
    string $sortDirection,
    int $limit,
): array {
    $query = smart_gallery_model_result_query(
        $rules,
        $accessibleGalleryIds,
        $publicOnly,
        $allowNsfw,
        $sortMode,
        $sortDirection,
    );
    $limit = max(1, $limit);
    $sql =
        'SELECT i.id, i.gallery_id, i.filename, i.url_slug, i.title, i.description, i.content_language, i.gps_lat, i.gps_lng '
        . 'FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE '
        . $query['where']
        . ' AND i.gps_lat IS NOT NULL AND i.gps_lng IS NOT NULL '
        . 'ORDER BY '
        . $query['order']
        . ' LIMIT '
        . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($query['params']);
    return $stmt->fetchAll();
}

/**
 * Return grouped physical-source counts for one canonical Smart Gallery result scope.
 *
 * Grouping remains model-owned so PHP never materializes all Smart Gallery image rows
 * merely to discover provenance counts. The canonical result predicate supplies rules,
 * gallery authorization, public visibility, and NSFW policy exactly as for the grid.
 *
 * @param array{
 *     version:int,
 *     root:array{
 *         type:string,
 *         operator?:string,
 *         children?:list<array{
 *             type:string,
 *             operator?:string,
 *             field?:string,
 *             value?:int|float|string|list<int>|array{
 *                 0:int|float|string,
 *                 1:int|float|string
 *             }|null
 *         }>,
 *         field?:string,
 *         value?:int|float|string|list<int>|array{
 *             0:int|float|string,
 *             1:int|float|string
 *         }|null
 *     }
 * } $rules Validated versioned rules; group children recursively contain
 *     groups or conditions.
 * @param list<int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @return list<array{gallery_id:int|string,image_count:int|string}> Counts of matching images grouped by source gallery.
 */
function smart_gallery_model_source_summary_rows(
    array $rules,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
): array {
    $query = smart_gallery_model_result_query(
        $rules,
        $accessibleGalleryIds,
        $publicOnly,
        $allowNsfw,
        'capture_date',
        'desc',
    );
    $stmt = db()->prepare(
        'SELECT i.gallery_id, COUNT(*) AS image_count '
            . 'FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE '
            . $query['where']
            . ' GROUP BY i.gallery_id ORDER BY image_count DESC, i.gallery_id ASC',
    );
    $stmt->execute($query['params']);
    return $stmt->fetchAll();
}

/**
 * Return physical source membership for a bounded batch of Smart Gallery cards.
 *
 * Each source must contribute at least one image to the canonical authorized
 * rule result. One UNION query replaces per-card reads and never walks the
 * filesystem or expands attachments from unrelated source galleries.
 *
 * @param array<int,array{id:int,rules:array<string,mixed>,sort_mode:string,
 *     sort_direction:string}> $definitions Validated card rule definitions.
 * @param list<int> $accessibleGalleryIds Galleries authorized for the audience.
 * @param bool $publicOnly Whether to require public image visibility.
 * @param bool $allowNsfw Whether NSFW images are available to this visitor.
 * @return list<array{smart_gallery_id:int,gallery_id:int}> Canonical source
 *     memberships, one entry per Smart Gallery and physical source.
 */
function smart_gallery_model_card_source_rows(
    array $definitions,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
): array {
    if ($accessibleGalleryIds === []) {
        return [];
    }

    $statements = [];
    $params = [];
    foreach ($definitions as $definition) {
        $smartId = (int) ($definition['id'] ?? 0);
        if ($smartId <= 0) {
            continue;
        }
        $query = smart_gallery_model_result_query(
            $definition['rules'],
            $accessibleGalleryIds,
            $publicOnly,
            $allowNsfw,
            $definition['sort_mode'],
            $definition['sort_direction'],
        );
        $statements[] = 'SELECT ' . $smartId
            . ' AS smart_gallery_id, i.gallery_id'
            . ' FROM images i INNER JOIN galleries g ON g.id=i.gallery_id'
            . ' WHERE ' . $query['where']
            . ' GROUP BY i.gallery_id';
        $params = array_merge($params, $query['params']);
    }

    if ($statements === []) {
        return [];
    }
    $statement = db()->prepare(implode(' UNION ALL ', $statements));
    $statement->execute($params);
    return array_map(
        static fn(array $row): array => [
            'smart_gallery_id' => (int) $row['smart_gallery_id'],
            'gallery_id' => (int) $row['gallery_id'],
        ],
        $statement->fetchAll(),
    );
}

/**
 * Return whether one image remains a member of one normalized Smart Gallery result set.
 *
 * @param array{
 *     version:int,
 *     root:array{
 *         type:string,
 *         operator?:string,
 *         children?:list<array{
 *             type:string,
 *             operator?:string,
 *             field?:string,
 *             value?:int|float|string|list<int>|array{
 *                 0:int|float|string,
 *                 1:int|float|string
 *             }|null
 *         }>,
 *         field?:string,
 *         value?:int|float|string|list<int>|array{
 *             0:int|float|string,
 *             1:int|float|string
 *         }|null
 *     },
 *     _photo_tag_media_type_ready?:bool
 * } $rules Validated versioned rules. Group children recurse through the same
 *     group and condition forms; value is omitted for value-free operators.
 *     `_photo_tag_media_type_ready` is internal to compilation and never
 *     persisted.
 * @param array<int,int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @param int $imageId Image identifier to re-authorize.
 * @return bool True when the image belongs to the authorized canonical result set.
 */
function smart_gallery_model_contains_image(
    array $rules,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
    int $imageId,
): bool {
    if ($imageId <= 0)
        return false;
    $query = smart_gallery_model_result_query(
        $rules,
        $accessibleGalleryIds,
        $publicOnly,
        $allowNsfw,
        'capture_date',
        'desc',
    );
    $params = $query['params'];
    $params[] = $imageId;
    $stmt = db()->prepare(
        'SELECT 1 FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE '
            . $query['where']
            . ' AND i.id = ? LIMIT 1',
    );
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

/**
 * Check one physical gallery's membership in the current Smart image result.
 *
 * This bounded existence lookup compiles the identical canonical rule and
 * public/NSFW predicates as the card and manifest queries, but does not group
 * every matching source just to authorize one original-media byte request.
 *
 * @param array{
 *     version:int,
 *     root:array<string,mixed>
 * } $rules Canonical validated Smart Gallery rule tree.
 * @param list<int> $accessibleGalleryIds Physical sources authorized now.
 * @param bool $publicOnly Apply public image visibility policy.
 * @param bool $allowNsfw Allow matching NSFW images for this visitor.
 * @param int $sourceGalleryId Physical source being reauthorized.
 * @return bool Whether this source contributes a currently matching image.
 */
function smart_gallery_model_contains_source(
    array $rules,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
    int $sourceGalleryId,
): bool {
    if (
        $sourceGalleryId <= 0
        || !in_array($sourceGalleryId, $accessibleGalleryIds, true)
    ) {
        return false;
    }
    $query = smart_gallery_model_result_query(
        $rules,
        $accessibleGalleryIds,
        $publicOnly,
        $allowNsfw,
        'capture_date',
        'desc',
    );
    $params = $query['params'];
    $params[] = $sourceGalleryId;
    $stmt = db()->prepare(
        'SELECT 1 FROM images i INNER JOIN galleries g ON g.id=i.gallery_id'
            . ' WHERE ' . $query['where']
            . ' AND i.gallery_id = ? LIMIT 1',
    );
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

/**
 * Return count/cover rows for a bounded Smart Gallery card chunk.
 *
 * @param array<int,array{
 *     id:int,
 *     rules:array{
 *         version:int,
 *         root:array{
 *             type:string,
 *             operator?:string,
 *             children?:list<array{
 *                 type:string,
 *                 operator?:string,
 *                 field?:string,
 *                 value?:int|float|string|list<int>|array{
 *                     0:int|float|string,
 *                     1:int|float|string
 *                 }|null
 *             }>,
 *             field?:string,
 *             value?:int|float|string|list<int>|array{
 *                 0:int|float|string,
 *                 1:int|float|string
 *             }|null
 *         }
 *     },
 *     sort_mode:string,
 *     sort_direction:string
 * }> $definitions Normalized definitions keyed by their input index; group
 *     children recursively contain group or condition nodes.
 * @param array<int,int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @return array<int,array{smart_gallery_id:int,image_count:int,cover_image_id:int}> Rows keyed sequentially.
 */
function smart_gallery_model_card_summary_rows(
    array $definitions,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
): array {
    $selects = [];
    $params = [];
    foreach ($definitions as $definition) {
        $smartId = (int) ($definition['id'] ?? 0);
        if ($smartId <= 0)
            continue;
        $query = smart_gallery_model_result_query(
            (array) ($definition['rules'] ?? []),
            $accessibleGalleryIds,
            $publicOnly,
            $allowNsfw,
            (string) ($definition['sort_mode'] ?? ''),
            (string) ($definition['sort_direction'] ?? 'desc'),
        );
        $base =
            ' FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE '
            . $query['where'];
        $selects[] =
            'SELECT '
            . $smartId
            . ' AS smart_gallery_id, '
            . '(SELECT COUNT(*)'
            . $base
            . ') AS image_count, '
            . '(SELECT i.id'
            . $base
            . ' ORDER BY '
            . $query['order']
            . ' LIMIT 1) AS cover_image_id';
        $params = array_merge($params, $query['params'], $query['params']);
    }
    if ($selects === [])
        return [];
    $stmt = db()->prepare(implode(' UNION ALL ', $selects));
    $stmt->execute($params);
    return array_values(array_map(static fn(array $row): array => [
        'smart_gallery_id' => (int) ($row['smart_gallery_id'] ?? 0),
        'image_count' => max(0, (int) ($row['image_count'] ?? 0)),
        'cover_image_id' => max(0, (int) ($row['cover_image_id'] ?? 0)),
    ], $stmt->fetchAll()));
}

/** Load source image rows with source-gallery metadata, keyed by image id.
 * @param list<int> $imageIds Candidate identifiers normalized to unique positive integers.
 * @return array<int,array{
 *     id:int|string,
 *     gallery_id:int|string,
 *     relative_path:string,
 *     relative_path_hash:string,
 *     filename:string,
 *     title:string|null,
 *     description:string|null,
 *     url_slug?:string|null,
 *     content_language?:string|null,
 *     width:int|string|null,
 *     height:int|string|null,
 *     display_width?:int|string|null,
 *     display_height?:int|string|null,
 *     exif_orientation?:int|string|null,
 *     thumbnail_derivative_version?:int|string,
 *     thumbnail_metadata_refreshed_at?:string|null,
 *     mime_type:string|null,
 *     file_size:int|string|null,
 *     modified_at:string|null,
 *     checksum_sha256:string|null,
 *     sort_order:int|string,
 *     visibility:string,
 *     editorial_rating?:int|string|null,
 *     nsfw_enabled?:int|string,
 *     thumbnail_min_size?:int|string|null,
 *     thumbnail_max_size?:int|string|null,
 *     exif_taken_at?:string|null,
 *     exif_camera_make?:string|null,
 *     exif_camera_model?:string|null,
 *     exif_lens_model?:string|null,
 *     exif_focal_length?:string|null,
 *     exif_aperture?:string|null,
 *     exif_exposure_time?:string|null,
 *     exif_iso?:int|string|null,
 *     gps_lat?:float|string|null,
 *     gps_lng?:float|string|null,
 *     gps_altitude?:float|string|null,
 *     gps_extracted_at?:string|null,
 *     source_gallery_title:string,
 *     source_gallery_slug:string,
 *     source_gallery_folder_path:string,
 *     source_gallery_visibility:string,
 *     source_gallery_access_mode:string
 * }> Image rows keyed by identifier with source-gallery metadata.
 */
function smart_gallery_model_images_by_ids(array $imageIds): array
{
    $imageIds = smart_gallery_model_normalize_ids($imageIds);
    if ($imageIds === [])
        return [];
    $stmt = db()->prepare(
        'SELECT i.*, g.title AS source_gallery_title, g.slug AS source_gallery_slug, '
            . 'g.folder_path AS source_gallery_folder_path, g.visibility AS source_gallery_visibility, '
            . 'g.access_mode AS source_gallery_access_mode '
            . 'FROM images i INNER JOIN galleries g ON g.id=i.gallery_id '
            . 'WHERE i.id IN ('
            . implode(',', array_fill(0, count($imageIds), '?'))
            . ')',
    );
    $stmt->execute($imageIds);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0)
            $rows[$id] = $row;
    }
    return $rows;
}

/** Load physical source galleries keyed by gallery id.
 * @param list<int> $galleryIds Candidate physical gallery identifiers normalized to unique positive integers.
 * @return array<int,array{
 *     id:int|string,
 *     title:string,
 *     slug:string,
 *     folder_path:string,
 *     visibility:string,
 *     access_mode:string,
 *     parent_id:int|string|null,
 *     description:string|null,
 *     content_language?:string|null,
 *     nsfw_enabled?:int|string,
 *     latitude?:float|string|null,
 *     longitude?:float|string|null,
 *     cover_image_id?:int|string|null,
 *     thumbnail_min_size?:int|string|null,
 *     thumbnail_max_size?:int|string|null,
 *     gps_map_enabled?:int|string,
 *     created_at:string,
 *     updated_at:string
 * }> Physical gallery rows keyed by identifier.
 */
function smart_gallery_model_galleries_by_ids(array $galleryIds): array
{
    $galleryIds = smart_gallery_model_normalize_ids($galleryIds);
    if ($galleryIds === [])
        return [];
    $stmt = db()->prepare(
        'SELECT * FROM galleries WHERE id IN ('
            . implode(',', array_fill(0, count($galleryIds), '?'))
            . ')',
    );
    $stmt->execute($galleryIds);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0)
            $rows[$id] = $row;
    }
    return $rows;
}

/** Return one image-tag display name when the media-type schema state permits lookup.
 *
 * @param int $tagId Persistent tag identifier.
 * @param string $mediaTypeState Confirmed tags.media_type state: available, missing, or unknown.
 * @return ?string Image-tag name, or null when unavailable.
 */
function smart_gallery_model_tag_name(
    int $tagId,
    string $mediaTypeState,
): ?string
{
    if (!in_array($mediaTypeState, ['available', 'missing'], true)) {
        return null;
    }
    $typeCondition = $mediaTypeState === 'available'
        ? " AND media_type = 'image'"
        : '';
    $stmt = db()->prepare('SELECT name FROM tags WHERE id = ?' . $typeCondition);
    $stmt->execute([$tagId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

/** Return one physical gallery display title or null when the reference is missing. */
function smart_gallery_model_gallery_title(int $galleryId): ?string
{
    $stmt = db()->prepare('SELECT title FROM galleries WHERE id = ?');
    $stmt->execute([$galleryId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

/** Persist a private editorial image rating, where null clears the rating.
 * @param int $imageId Image whose private editorial rating is changed.
 * @param int|null $rating Rating value, or null to clear it.
 * @param string $updatedAt Shared SQL update timestamp.
 * @return void Stores the rating and supplied update timestamp.
 */
function smart_gallery_model_set_image_rating(
    int $imageId,
    ?int $rating,
    string $updatedAt,
): void {
    $stmt = db()->prepare(
        'UPDATE images SET editorial_rating = ?, updated_at = ? WHERE id = ?',
    );
    $stmt->execute([$rating, $updatedAt, $imageId]);
}

/**
 * Compile a validated rule document into SQL and bound parameters.
 *
 * @param array{
 *     version:int,
 *     root:array{
 *         type:string,
 *         operator?:string,
 *         children?:list<array{
 *             type:string,
 *             operator?:string,
 *             field?:string,
 *             value?:int|float|string|list<int>|array{
 *                 0:int|float|string,
 *                 1:int|float|string
 *             }|null
 *         }>,
 *         field?:string,
 *         value?:int|float|string|list<int>|array{
 *             0:int|float|string,
 *             1:int|float|string
 *         }|null
 *     },
 *     _photo_tag_media_type_ready?:bool
 * } $rules Validated versioned rules. Group children recurse through the same
 *     group and condition forms; value is omitted for value-free operators.
 *     `_photo_tag_media_type_ready` is internal to compilation and never
 *     persisted.
 * @return array{sql:string,params:list<int|float|string|null>} Parameterized predicate and ordered values for the normalized rule document.
 */
function smart_gallery_model_compile_rules(array $rules): array
{
    $params = [];
    $photoTagMediaTypeReady = array_key_exists(
        '_photo_tag_media_type_ready',
        $rules,
    )
        ? (bool) $rules['_photo_tag_media_type_ready']
        : true;
    $sql = smart_gallery_model_compile_node(
        (array) ($rules['root'] ?? []),
        $params,
        $photoTagMediaTypeReady,
    );
    return ['sql' => $sql, 'params' => $params];
}

/** Compile one trusted, validated rule node.
 * @param array{
 *     type:string,
 *     operator?:string,
 *     children?:list<array{
 *         type:string,
 *         operator?:string,
 *         field?:string,
 *         value?:int|float|string|list<int>|array{
 *             0:int|float|string,
 *             1:int|float|string
 *         }|null
 *     }>,
 *     field?:string,
 *     value?:int|float|string|list<int>|array{0:int|float|string,1:int|float|string}|null
 * } $node Validated group or condition node; child groups recurse in this
 *     structure.
 * @param list<int|float|string|null> $params Ordered database values appended while compiling.
 * @param bool $photoTagMediaTypeReady Whether tags.media_type is confirmed available for photo-only predicates.
 * @return string Parenthesized SQL predicate containing placeholders for values.
 */
function smart_gallery_model_compile_node(
    array $node,
    array &$params,
    bool $photoTagMediaTypeReady,
): string
{
    if (($node['type'] ?? '') === 'group') {
        $parts = [];
        foreach ((array) ($node['children'] ?? []) as $child) {
            $parts[] = smart_gallery_model_compile_node(
                (array) $child,
                $params,
                $photoTagMediaTypeReady,
            );
        }
        if ($parts === [])
            return '1=1';
        $operator = (string) ($node['operator'] ?? 'AND');
        if ($operator === 'NOT')
            return '(NOT (' . $parts[0] . '))';
        if (!in_array($operator, ['AND', 'OR'], true))
            throw new InvalidArgumentException(
                'Unsupported Smart Gallery rule group.',
            );
        return '(' . implode(' ' . $operator . ' ', $parts) . ')';
    }
    return smart_gallery_model_compile_condition(
        $node,
        $params,
        $photoTagMediaTypeReady,
    );
}

/** Compile one allowlisted condition into a parameterized SQL predicate.
 * @param array{
 *     field:string,
 *     operator:string,
 *     value?:int|float|string|list<int>|array{0:int|float|string,1:int|float|string}|null
 * } $condition Validated allowlisted field condition.
 * @param list<int|float|string|null> $params Ordered database values appended while compiling.
 * @param bool $photoTagMediaTypeReady Whether tags.media_type is confirmed available for photo-only predicates.
 * @return string SQL predicate for the allowlisted field and operator.
 */
function smart_gallery_model_compile_condition(
    array $condition,
    array &$params,
    bool $photoTagMediaTypeReady,
): string {
    $field = (string) ($condition['field'] ?? '');
    $operator = (string) ($condition['operator'] ?? '');
    $value = $condition['value'] ?? null;
    $columns = [
        'capture_date' => 'i.exif_taken_at',
        'camera_make' => 'i.exif_camera_make',
        'camera_model' => 'i.exif_camera_model',
        'lens' => 'i.exif_lens_model',
        'iso' => 'i.exif_iso',
        'aperture' => 'i.exif_aperture',
        'focal_length' => 'i.exif_focal_length',
        'exposure_time' => 'i.exif_exposure_time',
        'exif_orientation' => 'i.exif_orientation',
        'filename' => 'i.filename',
        'title' => 'i.title',
        'description' => 'i.description',
        'gallery_title' => 'g.title',
        'rating' => 'i.editorial_rating',
        'extension' => "LOWER(SUBSTRING_INDEX(i.filename, '.', -1))",
        'width' => 'i.width',
        'height' => 'i.height',
        'file_size' => 'i.file_size',
    ];
    if ($field === 'gallery') {
        if (in_array($operator, ['equals', 'not_equals'], true)) {
            $params[] = (int) $value;
            return (
                'i.gallery_id ' . ($operator === 'equals' ? '=' : '<>') . ' ?'
            );
        }
        $params[] = (int) $value;
        $params[] = (int) $value;
        $predicate = "(i.gallery_id = ? OR g.folder_path LIKE CONCAT((SELECT sg_parent.folder_path FROM galleries sg_parent WHERE sg_parent.id = ?), '/%'))";
        return $operator === 'under' ? $predicate : '(NOT ' . $predicate . ')';
    }
    if ($field === 'tag') {
        if ($operator === 'untagged') {
            return "NOT EXISTS (SELECT 1 FROM image_tags sg_it WHERE sg_it.image_id = i.id) AND NOT EXISTS (SELECT 1 FROM gallery_tags sg_gt JOIN galleries sg_tag_gallery ON sg_tag_gallery.id = sg_gt.gallery_id WHERE g.folder_path = sg_tag_gallery.folder_path OR g.folder_path LIKE CONCAT(sg_tag_gallery.folder_path, '/%'))";
        }
        $ids = is_array($value)
            ? array_values(array_map('intval', $value))
            : [(int) $value];
        if ($operator === 'has_all_tags') {
            $predicates = [];
            foreach ($ids as $tagId) {
                $params[] = $tagId;
                $params[] = $tagId;
                $predicates[] = "(EXISTS (SELECT 1 FROM image_tags sg_it WHERE sg_it.image_id = i.id AND sg_it.tag_id = ?) OR EXISTS (SELECT 1 FROM gallery_tags sg_gt JOIN galleries sg_tag_gallery ON sg_tag_gallery.id = sg_gt.gallery_id WHERE sg_gt.tag_id = ? AND (g.folder_path = sg_tag_gallery.folder_path OR g.folder_path LIKE CONCAT(sg_tag_gallery.folder_path, '/%'))))";
            }
            return '(' . implode(' AND ', $predicates) . ')';
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        array_push($params, ...$ids, ...$ids);
        $exists = "(EXISTS (SELECT 1 FROM image_tags sg_it WHERE sg_it.image_id = i.id AND sg_it.tag_id IN ($marks)) OR EXISTS (SELECT 1 FROM gallery_tags sg_gt JOIN galleries sg_tag_gallery ON sg_tag_gallery.id = sg_gt.gallery_id WHERE sg_gt.tag_id IN ($marks) AND (g.folder_path = sg_tag_gallery.folder_path OR g.folder_path LIKE CONCAT(sg_tag_gallery.folder_path, '/%'))))";
        return $operator === 'not_has_tag' ? '(NOT ' . $exists . ')' : $exists;
    }
    if ($field === 'photo_tag') {
        $tagJoin = $photoTagMediaTypeReady
            ? " JOIN tags sg_pt ON sg_pt.id = sg_it.tag_id AND sg_pt.media_type = 'image'"
            : '';
        if ($operator === 'untagged') {
            return 'NOT EXISTS (SELECT 1 FROM image_tags sg_it'
                . $tagJoin
                . ' WHERE sg_it.image_id = i.id)';
        }
        $ids = is_array($value)
            ? array_values(array_map('intval', $value))
            : [(int) $value];
        if ($operator === 'has_all_tags') {
            $predicates = [];
            foreach ($ids as $tagId) {
                $params[] = $tagId;
                $predicates[] = 'EXISTS (SELECT 1 FROM image_tags sg_it'
                    . $tagJoin
                    . ' WHERE sg_it.image_id = i.id'
                    . ' AND sg_it.tag_id = ?)';
            }
            return '(' . implode(' AND ', $predicates) . ')';
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        array_push($params, ...$ids);
        $exists = 'EXISTS (SELECT 1 FROM image_tags sg_it'
            . $tagJoin
            . ' WHERE sg_it.image_id = i.id'
            . " AND sg_it.tag_id IN ($marks))";
        return $operator === 'not_has_tag' ? '(NOT ' . $exists . ')' : $exists;
    }
    if ($field === 'gps') {
        $exists = '(i.gps_lat IS NOT NULL AND i.gps_lng IS NOT NULL)';
        return $operator === 'exists' ? $exists : '(NOT ' . $exists . ')';
    }
    if ($field === 'ai_text' || $field === 'ai_metadata') {
        $base = 'EXISTS (SELECT 1 FROM image_ai_metadata sg_ai WHERE sg_ai.image_id = i.id';
        if ($field === 'ai_metadata')
            return $operator === 'exists' ? $base . ')' : 'NOT ' . $base . ')';
        $expression = 'sg_ai.searchable_text';
        $innerParams = [];
        $predicate = smart_gallery_model_compile_scalar(
            $expression,
            $operator,
            $value,
            $innerParams,
        );
        array_push($params, ...$innerParams);
        return $operator === 'missing' || $operator === 'is_empty'
            ? 'NOT EXISTS (SELECT 1 FROM image_ai_metadata sg_ai WHERE sg_ai.image_id = i.id AND '
            . smart_gallery_model_nonempty_sql($expression)
            . ')'
            : $base . ' AND ' . $predicate . ')';
    }
    if ($field === 'duplicate_status') {
        $pair = "EXISTS (SELECT 1 FROM images sg_dupe WHERE sg_dupe.id <> i.id AND i.checksum IS NOT NULL AND i.checksum <> '' AND sg_dupe.checksum = i.checksum)";
        $unresolved = "EXISTS (SELECT 1 FROM images sg_dupe WHERE sg_dupe.id <> i.id AND i.checksum IS NOT NULL AND i.checksum <> '' AND sg_dupe.checksum = i.checksum AND NOT EXISTS (SELECT 1 FROM duplicate_photo_ledger_pairs sg_dl WHERE sg_dl.image_id_low = LEAST(i.id, sg_dupe.id) AND sg_dl.image_id_high = GREATEST(i.id, sg_dupe.id)))";
        return match ($operator) {
            'unresolved' => $unresolved,
            'resolved' => '(' . $pair . ' AND NOT ' . $unresolved . ')',
            'exists' => $pair,
            default => '(NOT ' . $pair . ')',
        };
    }
    if ($field === 'media_orientation') {
        return match ($operator) {
            'landscape' => 'i.width > i.height',
            'portrait' => 'i.height > i.width',
            default => 'i.width = i.height',
        };
    }
    if ($operator === 'unrated')
        return 'i.editorial_rating IS NULL';
    if (!isset($columns[$field]))
        throw new InvalidArgumentException(
            'Unsupported Smart Gallery rule field.',
        );
    return smart_gallery_model_compile_scalar(
        $columns[$field],
        $operator,
        $value,
        $params,
    );
}

/** Compile a scalar comparison using only a trusted column expression.
 * @param string $column Trusted SQL column expression selected by the field allowlist.
 * @param string $operator Validated scalar comparison operator.
 * @param int|float|string|list<int>|array{
 *     0:int|float|string,
 *     1:int|float|string
 * }|null $value Comparison value, tag-id list, or two-value range.
 * @param list<int|float|string|null> $params Ordered database values appended while compiling.
 * @return string SQL predicate with placeholders for comparison values.
 */
function smart_gallery_model_compile_scalar(
    string $column,
    string $operator,
    mixed $value,
    array &$params,
): string {
    if ($operator === 'exists')
        return smart_gallery_model_nonempty_sql($column);
    if ($operator === 'missing')
        return '(' . $column . ' IS NULL OR ' . $column . " = '')";
    if ($operator === 'is_empty')
        return '(' . $column . ' IS NULL OR TRIM(' . $column . ") = '')";
    if ($operator === 'not_empty')
        return smart_gallery_model_nonempty_sql($column);
    if ($operator === 'between') {
        $params[] = $value[0];
        $params[] = $value[1];
        return "$column BETWEEN ? AND ?";
    }
    if ($operator === 'year') {
        $params[] = (int) $value;
        return "YEAR($column) = ?";
    }
    if ($operator === 'month') {
        $params[] = (int) $value;
        return "MONTH($column) = ?";
    }
    $sqlOperators = [
        'equals' => '=',
        'not_equals' => '<>',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'before' => '<',
        'after' => '>',
        'exact' => '=',
    ];
    if (isset($sqlOperators[$operator])) {
        $params[] = $value;
        return "$column {$sqlOperators[$operator]} ?";
    }
    if (!in_array(
        $operator,
        ['contains', 'not_contains', 'starts_with', 'ends_with'],
        true,
    )) {
        throw new InvalidArgumentException(
            'Unsupported Smart Gallery scalar operator.',
        );
    }
    $escaped = str_replace(
        ['\\', '%', '_'],
        ['\\\\', '\\%', '\\_'],
        (string) $value,
    );
    $pattern = match ($operator) {
        'starts_with' => $escaped . '%',
        'ends_with' => '%' . $escaped,
        default => '%' . $escaped . '%',
    };
    $params[] = $pattern;
    return (
        $column
            . ($operator === 'not_contains' ? ' NOT LIKE ?' : ' LIKE ?')
            . " ESCAPE '\\\\'"
    );
}

/** Return a reusable SQL non-empty test for a trusted column expression. */
function smart_gallery_model_nonempty_sql(string $column): string
{
    return '(' . $column . ' IS NOT NULL AND TRIM(' . $column . ") <> '')";
}

/**
 * Build the internal rule/access/order query definition.
 *
 * @param array{
 *     version:int,
 *     root:array{
 *         type:string,
 *         operator?:string,
 *         children?:list<array{
 *             type:string,
 *             operator?:string,
 *             field?:string,
 *             value?:int|float|string|list<int>|array{
 *                 0:int|float|string,
 *                 1:int|float|string
 *             }|null
 *         }>,
 *         field?:string,
 *         value?:int|float|string|list<int>|array{
 *             0:int|float|string,
 *             1:int|float|string
 *         }|null
 *     }
 * } $rules Validated versioned rules; group children recursively contain
 *     group or condition nodes, and conditions omit value for value-free operators.
 * @param array<int,int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @param string $sortMode Allowlisted service-owned sort mode.
 * @param string $sortDirection asc or desc.
 * @return array{where:string,params:list<int|float|string|null>,order:string}
 *     Membership predicate, ordered bound values, and deterministic SQL order.
 */
function smart_gallery_model_result_query(
    array $rules,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
    string $sortMode,
    string $sortDirection,
): array {
    $compiled = smart_gallery_model_compile_rules($rules);
    $ids = smart_gallery_model_normalize_ids($accessibleGalleryIds);
    $order = smart_gallery_model_order_sql($sortMode, $sortDirection);
    if ($ids === [])
        return ['where' => '1=0', 'params' => [], 'order' => $order];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $where = $compiled['sql'] . ' AND i.gallery_id IN (' . $marks . ')';
    $params = array_merge($compiled['params'], $ids);
    if ($publicOnly) {
        $where .= " AND i.visibility = 'public'";
        if (!$allowNsfw)
            $where .= ' AND COALESCE(i.nsfw_enabled, 0) = 0 AND COALESCE(g.nsfw_enabled, 0) = 0';
    }
    return ['where' => $where, 'params' => $params, 'order' => $order];
}

/** Return the hardcoded safe primary sort expression for one supported mode. */
function smart_gallery_model_order_column_sql(string $mode): string
{
    return match ($mode) {
        'filename' => 'i.filename',
        'created_at' => 'i.created_at',
        'title' => "COALESCE(NULLIF(i.title,''),i.filename)",
        'rating' => 'i.editorial_rating',
        'default' => 'i.sort_order',
        default => 'i.exif_taken_at',
    };
}

/** Return a hardcoded safe ORDER BY expression for one supported mode. */
function smart_gallery_model_order_sql(string $mode, string $direction): string
{
    $directionSql = $direction === 'asc' ? 'ASC' : 'DESC';
    $column = smart_gallery_model_order_column_sql($mode);
    return $column . ' ' . $directionSql . ', i.id ' . $directionSql;
}

/**
 * Return the zero-based canonical Smart Gallery position of one authorized image.
 *
 * The query mirrors MySQL/MariaDB NULL ordering without window functions so the
 * project remains compatible with MySQL 5.7 and MariaDB 10.2. The stable image-id
 * tie breaker is identical to smart_gallery_model_order_sql().
 * @param array{
 *     version:int,
 *     root:array{
 *         type:string,
 *         operator?:string,
 *         children?:list<array{
 *             type:string,
 *             operator?:string,
 *             field?:string,
 *             value?:int|float|string|list<int>|array{
 *                 0:int|float|string,
 *                 1:int|float|string
 *             }|null
 *         }>,
 *         field?:string,
 *         value?:int|float|string|list<int>|array{
 *             0:int|float|string,
 *             1:int|float|string
 *         }|null
 *     }
 * } $rules Validated versioned rules defining membership and ordering; groups
 *     recursively contain groups or conditions.
 * @param list<int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @param string $sortMode Allowlisted service-owned sort mode.
 * @param string $sortDirection Ascending or descending order.
 * @param int $imageId Image identifier whose canonical position is requested.
 * @return int Zero-based position, or -1 when invalid or outside the result set.
 */
function smart_gallery_model_image_position(
    array $rules,
    array $accessibleGalleryIds,
    bool $publicOnly,
    bool $allowNsfw,
    string $sortMode,
    string $sortDirection,
    int $imageId,
): int {
    if ($imageId <= 0)
        return -1;
    $query = smart_gallery_model_result_query(
        $rules,
        $accessibleGalleryIds,
        $publicOnly,
        $allowNsfw,
        $sortMode,
        $sortDirection,
    );
    $sortExpression = smart_gallery_model_order_column_sql($sortMode);

    $targetParams = $query['params'];
    $targetParams[] = $imageId;
    $targetStmt = db()->prepare(
        'SELECT '
            . $sortExpression
            . ' AS smart_gallery_sort_value FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE '
            . $query['where']
            . ' AND i.id = ? LIMIT 1',
    );
    $targetStmt->execute($targetParams);
    $target = $targetStmt->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($target))
        return -1;

    $targetValue = $target['smart_gallery_sort_value'] ?? null;
    $directionAsc = $sortDirection === 'asc';
    $beforeParams = $query['params'];
    if ($targetValue === null) {
        $beforeSql = $directionAsc
            ? '(' . $sortExpression . ' IS NULL AND i.id < ?)'
            : '(('
            . $sortExpression
            . ' IS NOT NULL) OR ('
            . $sortExpression
            . ' IS NULL AND i.id > ?))';
        $beforeParams[] = $imageId;
    } else {
        $comparison = $directionAsc ? '<' : '>';
        $tieComparison = $directionAsc ? '<' : '>';
        $beforeSql = $directionAsc
            ? '(('
            . $sortExpression
            . ' IS NULL) OR ('
            . $sortExpression
            . ' '
            . $comparison
            . ' ?) OR (('
            . $sortExpression
            . ' <=> ?) AND i.id '
            . $tieComparison
            . ' ?))'
            : '(('
            . $sortExpression
            . ' '
            . $comparison
            . ' ?) OR (('
            . $sortExpression
            . ' <=> ?) AND i.id '
            . $tieComparison
            . ' ?))';
        $beforeParams[] = $targetValue;
        $beforeParams[] = $targetValue;
        $beforeParams[] = $imageId;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE '
            . $query['where']
            . ' AND '
            . $beforeSql,
    );
    $stmt->execute($beforeParams);
    return (int) $stmt->fetchColumn();
}

/** Normalize a list of positive integer identifiers.
 * @param list<int|string> $ids Candidate values converted to unique positive integer identifiers.
 * @return list<int> Unique positive integer identifiers in first-seen order.
 */
function smart_gallery_model_normalize_ids(array $ids): array
{
    return array_values(array_unique(array_filter(
        array_map('intval', $ids),
        static fn(int $id): bool => $id > 0,
    )));
}
