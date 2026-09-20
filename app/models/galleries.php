<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/galleries.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns reusable gallery-row persistence that is independent of HTTP and presentation.
 *
 * Responsibilities:
 *   - Persist bulk gallery state fields through semantic model operations
 *   - Return append-style child sort-order values for gallery creation workflows
 *   - Keep gallery-table SQL out of controllers and service orchestration
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
 *   - Callers must pass already-normalized positive gallery identifiers.
 *   - Reads use the shared PDO associative fetch mode; numeric SQL values may
 *     remain decimal strings. Only explicitly cast results promise PHP integers.
 *   - These SQL-only primitives do not authorize access, inspect required schema,
 *     acquire gallery writer ownership, touch files, refresh sidecars or clear
 *     request caches. Services own those steps and any wider transaction.
 *   - Updates participate in an existing transaction or autocommit. Every model
 *     write advances edit_revision explicitly; an installed compatibility
 *     trigger may enforce the same OLD + 1 value and refuse competing writes.
 *     The timestamp is not an optimistic concurrency precondition.
 *
 * Documentation shape:
 *   GalleryDatabaseRow describes raw columns from the repository migrations,
 *   not an HTTP DTO. Optional keys account for confirmed older schema versions,
 *   not permission to ignore unknown schema state. Dates are SQL date/datetime
 *   strings. Null parent/cover means no link; null presentation overrides inherit.
 *   Boolean columns are stored as 0/1; visibility may contain legacy draft.
 *   Paths are gallery-relative except public url_path. Thumbnail bounds are
 *   pixels; edit_revision is a positive decimal counter, never a timestamp.
 *   access_password_hash, access_share_token (encrypted or historical display
 *   material) and access_token_hash are sensitive: never serialize/log this row
 *   wholesale. Listing queries still need service-owned password/NSFW policy.
 *
 * @phpstan-type GalleryDatabaseRow array{
 *   id:int|string,parent_id:int|string|null,folder_path:string,folder_path_hash:string,
 *   slug:string,title:string,description:string|null,cover_image_id:int|string|null,
 *   sort_order:int|string,visibility:string,created_at:string,updated_at:string,
 *   voting_enabled?:int|string,show_filenames?:int|string,picture_game_enabled?:int|string,
 *   gps_map_enabled?:int|string|null,nsfw_enabled?:int|string,content_language?:string|null,
 *   url_slug?:string|null,url_path?:string|null,url_path_hash?:string|null,
 *   gallery_date?:string|null,gallery_date_end?:string|null,description_layout?:string|null,
 *   count_badge_visibility?:string|null,lightbox_browsing_mode?:string|null,
 *   grid_columns?:int|string|null,grid_rows?:int|string|null,grid_use_for_subgalleries?:int|string,
 *   thumbnail_min_size?:int|string|null,thumbnail_max_size?:int|string|null,
 *   access_mode?:string,access_listing?:string,access_password_hash?:string|null,
 *   access_share_token?:string|null,access_token_hash?:string|null,access_token_expires_at?:string|null,
 *   cover_image_path?:string|null,banner_image_path?:string|null,logo_image_path?:string|null,
 *   separator_image_path?:string|null,background_source?:string|null,edit_revision?:int|string
 * }
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use PDO;
use function Gallery\Core\db;
use function Gallery\Core\slugify;

/**
 * Find catalog ownership of a requested folder or any descendant without loading the tree.
 *
 * Uses equality and a slash-delimited prefix, not LIKE wildcards. This is an
 * existence check, not a reservation; creation must retain its service guard.
 *
 * @param string $folderPath Normalized relative gallery folder path.
 * @return int Lowest matching gallery ID, or zero when no catalog row owns the path/subtree.
 */
function gallery_model_catalog_path_owner(string $folderPath): int
{
    $prefix = rtrim($folderPath, '/') . '/';
    $stmt = db()->prepare('SELECT id FROM galleries WHERE folder_path = ? OR LEFT(folder_path, CHAR_LENGTH(?)) = ? ORDER BY id LIMIT 1');
    $stmt->execute([$folderPath, $prefix, $prefix]);
    return (int) $stmt->fetchColumn();
}

/**
 * Update one explicitly supported scalar gallery column for a set of rows.
 *
 * This is an internal model primitive. Public callers should use the semantic
 * wrappers below so domain intent remains visible at call sites.
 * Empty selection performs no SQL; values and required schema are not validated.
 * The single UPDATE also touches updated_at and participates in the caller's
 * transaction. It neither starts a transaction nor acquires a writer lease.
 *
 * @param list<int> $galleryIds Already normalized positive gallery IDs; duplicates are not removed here.
 * @param 'visibility'|'gps_map_enabled'|'voting_enabled'|'show_filenames'|'picture_game_enabled' $column Fixed bulk-update target.
 * @param int|string|null $value Storage visibility string, integer 0/1, or null for an inheritable GPS override.
 * @param string $now Shared SQL timestamp for every touched row.
 * @return int Number of rows reported as changed by the database driver.
 * @throws InvalidArgumentException For an unsupported column in a nonempty selection.
 */
function gallery_model_update_scalar_for_ids(array $galleryIds, string $column, mixed $value, string $now): int
{
    if ($galleryIds === []) {
        return 0;
    }

    $allowedColumns = [
        'visibility',
        'gps_map_enabled',
        'voting_enabled',
        'show_filenames',
        'picture_game_enabled',
    ];
    if (!in_array($column, $allowedColumns, true)) {
        throw new InvalidArgumentException('Unsupported gallery bulk-update column.');
    }

    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    $stmt = db()->prepare('UPDATE galleries SET ' . $column . ' = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$value, $now], $galleryIds));
    return $stmt->rowCount();
}

/**
 * Persist one visibility value for selected galleries.
 *
 * Does not map the historical draft vocabulary or apply access policy; the
 * service must normalize the storage enum before this timestamped bulk update.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param string $visibility Schema-compatible public/private/unpublished value, or draft for a verified historical enum.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_visibility(array $galleryIds, string $visibility, string $now): int
{
    return gallery_model_update_scalar_for_ids($galleryIds, 'visibility', $visibility, $now);
}

/**
 * Persist an explicit or inherited GPS-map override for selected galleries.
 *
 * Converts explicit booleans to 0/1; inheritance stays SQL NULL. Does not
 * traverse descendants or rewrite the global EXIF/GPS display preference.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param ?bool $enabled True/false for explicit state, null for inheritance.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_gps_map_enabled(array $galleryIds, ?bool $enabled, string $now): int
{
    return gallery_model_update_scalar_for_ids($galleryIds, 'gps_map_enabled', $enabled === null ? null : ($enabled ? 1 : 0), $now);
}

/**
 * Persist image-voting state for selected galleries.
 *
 * Writes 0/1 and updated_at only. Any coordinated Picture Game changes and
 * sidecar refreshes remain the bulk-mutation service's responsibility.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param bool $enabled Whether image voting is enabled.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_voting_enabled(array $galleryIds, bool $enabled, string $now): int
{
    return gallery_model_update_scalar_for_ids($galleryIds, 'voting_enabled', $enabled ? 1 : 0, $now);
}

/**
 * Persist filename-display state for selected galleries.
 *
 * Stores 0/1 and updated_at without renaming images or refreshing sidecars.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param bool $enabled Whether filenames are shown.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_show_filenames(array $galleryIds, bool $enabled, string $now): int
{
    return gallery_model_update_scalar_for_ids($galleryIds, 'show_filenames', $enabled ? 1 : 0, $now);
}

/**
 * Persist Picture Game state for selected galleries.
 *
 * Stores 0/1 and updated_at only; enabling voting or enforcing the capability
 * dependency is a separate service decision, not an effect of this function.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param bool $enabled Whether Picture Game is enabled.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_picture_game_enabled(array $galleryIds, bool $enabled, string $now): int
{
    return gallery_model_update_scalar_for_ids($galleryIds, 'picture_game_enabled', $enabled ? 1 : 0, $now);
}

/**
 * Return the append-style sort order for a new child gallery.
 *
 * The query intentionally preserves the legacy `parent_id = ?` semantics,
 * including the current treatment of a zero parent identifier.
 * It does not reserve the returned value: concurrent callers can receive the
 * same result. NULL-parent roots do not match a zero parameter.
 *
 * @param int $parentGalleryId Parent gallery identifier.
 * @return int Next sort-order value spaced by ten.
 */
function gallery_model_next_child_sort_order(int $parentGalleryId): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM galleries WHERE parent_id = ?');
    $stmt->execute([$parentGalleryId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return whether a gallery slug is already used by another gallery.
 *
 * This unlocked, collation-dependent lookup is not a uniqueness reservation.
 *
 * @param string $slug Candidate normalized slug.
 * @param int $excludeGalleryId Gallery identifier excluded from the collision check.
 * @return bool True when another gallery already owns the slug.
 */
function gallery_model_slug_exists(string $slug, int $excludeGalleryId = 0): bool
{
    return gallery_model_slug_exists_on_connection(db(), $slug, $excludeGalleryId > 0 ? $excludeGalleryId : null);
}

/**
 * Preserve explicit-connection collision checks for legacy setup/tooling callers.
 *
 * Only null disables exclusion here; unlike the shared-connection wrapper,
 * an explicitly supplied zero or negative ID is passed through to the query.
 * Does not change connection attributes or transaction state.
 *
 * @param PDO $connection Caller-owned database connection; this model owns all SQL.
 * @param string $slug Candidate slug.
 * @param ?int $excludeGalleryId Optional exact identifier excluded from the query.
 * @return bool Whether another row already uses the candidate.
 */
function gallery_model_slug_exists_on_connection(PDO $connection, string $slug, ?int $excludeGalleryId = null): bool
{
    $sql = 'SELECT id FROM galleries WHERE slug = ?';
    $params = [$slug];
    if ($excludeGalleryId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeGalleryId;
    }
    $stmt = $connection->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetch();
}

/**
 * Persist a semantic set of editable gallery fields for one gallery row.
 *
 * Validates column names, not values, field combinations or schema availability.
 * Nonpositive IDs and empty maps are no-ops; absent rows are not reported as
 * conflicts. updated_at and an application-owned revision increment are
 * appended to the single UPDATE. An installed compatibility trigger may enforce
 * the same increment, but this primitive is not an expected-revision check.
 * Credential fields must already be hashed/encrypted by their domain owner;
 * neither this input nor raw PDO exceptions are safe for response/log output.
 *
 * @param int $galleryId Gallery identifier.
 * @param array{
 *   title?:string,description?:string|null,slug?:string,visibility?:string,sort_order?:int,
 *   parent_id?:int|null,cover_image_id?:int|null,gallery_date?:string|null,gallery_date_end?:string|null,
 *   picture_game_enabled?:int,gps_map_enabled?:int|null,voting_enabled?:int,show_filenames?:int,
 *   description_layout?:string|null,count_badge_visibility?:string|null,lightbox_browsing_mode?:string|null,
 *   nsfw_enabled?:int,grid_columns?:int|null,grid_rows?:int|null,grid_use_for_subgalleries?:int,
 *   thumbnail_min_size?:int|null,thumbnail_max_size?:int|null,access_listing?:string,access_mode?:string,
 *   access_password_hash?:string|null,access_share_token?:string|null,access_token_hash?:string|null,
 *   access_token_expires_at?:string|null,cover_image_path?:string|null,banner_image_path?:string|null,
 *   logo_image_path?:string|null,separator_image_path?:string|null,background_source?:string|null
 * } $fields Service-normalized editable columns; omitted keys retain their value, nullable keys clear or inherit.
 * @param string $now SQL timestamp written to updated_at.
 * @return void Executes one update without returning a row/count or refreshing dependent state.
 * @throws InvalidArgumentException When a supplied field name is outside the fixed allowlist.
 */
function gallery_model_update_fields(int $galleryId, array $fields, string $now): void
{
    if ($galleryId <= 0 || $fields === []) {
        return;
    }

    $allowedColumns = [
        'title',
        'description',
        'slug',
        'visibility',
        'sort_order',
        'parent_id',
        'cover_image_id',
        'gallery_date',
        'gallery_date_end',
        'picture_game_enabled',
        'gps_map_enabled',
        'voting_enabled',
        'show_filenames',
        'description_layout',
        'count_badge_visibility',
        'lightbox_browsing_mode',
        'nsfw_enabled',
        'grid_columns',
        'grid_rows',
        'grid_use_for_subgalleries',
        'thumbnail_min_size',
        'thumbnail_max_size',
        'access_listing',
        'access_mode',
        'access_password_hash',
        'access_share_token',
        'access_token_hash',
        'access_token_expires_at',
        'cover_image_path',
        'banner_image_path',
        'logo_image_path',
        'separator_image_path',
        'background_source',
    ];

    $assignments = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if (!is_string($column) || !in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported gallery update field.');
        }
        $assignments[] = $column . ' = ?';
        $params[] = $value;
    }
    $assignments[] = 'updated_at = ?';
    $params[] = $now;
    $assignments[] = 'edit_revision = edit_revision + 1';
    $params[] = $galleryId;

    $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', $assignments) . ' WHERE id = ?');
    $stmt->execute($params);
}

/**
 * Return compact gallery rows used by Admin picker controls.
 *
 * This historical API loads the entire catalog without visibility filtering;
 * it is not the bounded search-picker endpoint.
 *
 * @param bool $secondaryTitleSort Whether title is used as a secondary sort key.
 * @return list<array{id:int|string,title:string,folder_path:string}> Unredacted Admin picker labels in folder-path order.
 */
function gallery_model_picker_rows(bool $secondaryTitleSort = false): array
{
    $order = $secondaryTitleSort ? 'folder_path, title' : 'folder_path';
    return db()->query('SELECT id, title, folder_path FROM galleries ORDER BY ' . $order)->fetchAll();
}

/**
 * Return compact gallery rows used by the new-gallery title completion control.
 *
 * Newer galleries are returned first so a repeated naming scheme naturally
 * proposes the most recent sibling before older entries with the same prefix.
 *
 * Scope and cursor are semantic inputs; matching remains service-owned. The
 * hard limit includes one optional lookahead row. Never load the whole catalog.
 *
 * @param int $parentGalleryId Selected parent, or zero for root galleries.
 * @param bool $siblings True for siblings, false for the disjoint fallback scope.
 * @param int $limit Requested page size, hard-capped at 513 including lookahead.
 * @param ?array{id:int,created_at:string} $before Exclusive descending keyset cursor.
 * @return list<array{id:int|string,parent_id:int|string|null,title:string,created_at:string}> Raw naming candidates ordered by created_at DESC then id DESC; no visibility/access filtering or normalization.
 */
function gallery_model_title_completion_rows(int $parentGalleryId = 0, bool $siblings = true, int $limit = 512, ?array $before = null): array
{
    $limit = max(1, min(513, $limit));
    $params = [];
    if ($parentGalleryId > 0) {
        $scope = $siblings ? 'parent_id = ?' : '(parent_id IS NULL OR parent_id <> ?)';
        $params[] = $parentGalleryId;
    } else {
        $scope = $siblings ? '(parent_id IS NULL OR parent_id = 0)' : 'parent_id > 0';
    }
    $sql = 'SELECT id, parent_id, title, created_at FROM galleries WHERE ' . $scope;
    if ($before !== null) {
        $sql .= ' AND (created_at < ? OR (created_at = ? AND id < ?))';
        $params[] = (string) $before['created_at'];
        $params[] = (string) $before['created_at'];
        $params[] = (int) $before['id'];
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Return the first direct child gallery in normal display order.
 *
 * Considers every visibility state and does not validate that the parent exists.
 *
 * @param int $sourceGalleryId Source gallery identifier.
 * @return int Direct child identifier or zero.
 */
function gallery_model_likely_destination_id(int $sourceGalleryId): int
{
    $stmt = db()->prepare('SELECT id FROM galleries WHERE parent_id = ? ORDER BY sort_order, title, id LIMIT 1');
    $stmt->execute([$sourceGalleryId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Find a gallery by hashed share token, optionally restricting the gallery id.
 *
 * Hash equality alone is not access authorization: expiration, visibility and
 * NSFW/password policy belong to the service. With no positive ID restriction,
 * ties select updated_at DESC then id DESC. The full result contains credentials.
 *
 * @param string $tokenHash SHA-256 access token hash.
 * @param int $galleryId Optional gallery identifier restriction.
 * @return GalleryDatabaseRow|null Raw sensitive row, or null when the hash/ID lookup finds nothing.
 */
function gallery_model_find_by_access_token_hash(string $tokenHash, int $galleryId = 0): ?array
{
    if ($galleryId > 0) {
        $stmt = db()->prepare('SELECT * FROM galleries WHERE id = ? AND access_token_hash = ? LIMIT 1');
        $stmt->execute([$galleryId, $tokenHash]);
    } else {
        $stmt = db()->prepare('SELECT * FROM galleries WHERE access_token_hash = ? ORDER BY updated_at DESC, id DESC LIMIT 1');
        $stmt->execute([$tokenHash]);
    }
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return public physical root galleries with direct public image counts.
 *
 * Roots are strictly parent_id IS NULL. Password and NSFW access are not
 * checked here; image_count includes only public images without a slash in
 * relative_path, not descendants or access-filtered viewer totals.
 *
 * @param bool $requireListedAccess Whether access_listing must be listed.
 * @return list<GalleryDatabaseRow&array{image_count:int|string}> Full sensitive rows ordered by sort_order/title, with direct public-image counts.
 */
function gallery_model_public_root_rows(bool $requireListedAccess): array
{
    $sql = "SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE g.visibility = 'public'";
    if ($requireListedAccess) {
        $sql .= " AND g.access_listing = 'listed'";
    }
    $sql .= ' AND g.parent_id IS NULL GROUP BY g.id ORDER BY g.sort_order, g.title';
    return db()->query($sql)->fetchAll();
}

/**
 * Count physical cards in an Admin mutation's parent/root render context.
 *
 * Positive-parent counts include all direct children. Root counts require
 * public visibility and optionally listed access, matching the physical-root
 * query; neither branch counts Smart Gallery placements or enforces passwords.
 *
 * @param int $parentGalleryId Positive parent ID, or zero for the public root index.
 * @param bool $requireListedAccess Whether root policy requires the verified access_listing column.
 * @return int Full context count, never a paginated subset.
 */
function gallery_model_mutation_context_count(int $parentGalleryId, bool $requireListedAccess): int
{
    if ($parentGalleryId > 0) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM galleries WHERE parent_id = ?');
        $stmt->execute([$parentGalleryId]);
    } else {
        $stmt = db()->query('SELECT COUNT(*) FROM galleries g WHERE g.parent_id IS NULL'
            . gallery_model_public_listing_filter_sql('g', true, $requireListedAccess));
    }
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Clear all explicit gallery background-source overrides.
 *
 * Sets non-null background_source values to NULL plus updated_at. Does not
 * remove uploaded backgrounds or update sidecars; services synchronize those.
 *
 * @param string $now SQL timestamp written to updated_at.
 * @return int Number of rows reported as changed.
 */
function gallery_model_clear_background_sources(string $now): int
{
    $stmt = db()->prepare('UPDATE galleries SET background_source = NULL, updated_at = ?, edit_revision = edit_revision + 1 WHERE background_source IS NOT NULL');
    $stmt->execute([$now]);
    return $stmt->rowCount();
}

/**
 * Build the model-owned public listing predicate for a fixed gallery alias.
 *
 * No SQL is executed. publicOnly=false returns empty before checking the alias.
 * This predicate is only listing eligibility, not password/NSFW authorization.
 *
 * @param string $alias Trusted model-selected SQL alias.
 * @param bool $publicOnly Whether public visibility filtering is required.
 * @param bool $requireListedAccess Whether access_listing must be listed.
 * @return string Hardcoded SQL predicate prefixed with AND, or an empty string.
 * @throws InvalidArgumentException If public filtering is requested with an alias other than g.
 */
function gallery_model_public_listing_filter_sql(string $alias, bool $publicOnly, bool $requireListedAccess): string
{
    if (!$publicOnly) {
        return '';
    }
    if (!in_array($alias, ['g'], true)) {
        throw new InvalidArgumentException('Unsupported gallery model SQL alias.');
    }

    $sql = " AND {$alias}.visibility = 'public'";
    if ($requireListedAccess) {
        $sql .= " AND {$alias}.access_listing = 'listed'";
    }
    return $sql;
}

/**
 * Return direct child gallery rows for multiple parents with direct public-image counts.
 *
 * Empty input performs no query. Access listing is used only when publicOnly
 * is true; image counts always exclude nonpublic and nested-path images.
 * Results are ordered by parent_id, sort_order and title without ID tie-breaking.
 *
 * @param array<int,int> $parentIds Positive parent gallery identifiers.
 * @param bool $publicOnly Whether only publicly listable gallery rows are eligible.
 * @param bool $requireListedAccess Whether public access_listing must be listed.
 * @return list<GalleryDatabaseRow&array{image_count:int|string}> Full sensitive child rows and direct public-image counts; no service access filtering.
 */
function gallery_model_child_rows_for_parents(array $parentIds, bool $publicOnly, bool $requireListedAccess): array
{
    if ($parentIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
    $sql = "SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE g.parent_id IN (" . $placeholders . ')'
        . gallery_model_public_listing_filter_sql('g', $publicOnly, $requireListedAccess)
        . ' GROUP BY g.id ORDER BY g.parent_id, g.sort_order, g.title';
    $stmt = db()->prepare($sql);
    $stmt->execute($parentIds);
    return $stmt->fetchAll();
}

/**
 * Return descendant gallery rows below one or more roots with direct public-image counts.
 *
 * Folder-path LIKE membership, not parent_id recursion, selects descendants.
 * A root is excluded from its own branch but may belong to another input root.
 * Stored path wildcards are not escaped here. Overlapping roots are grouped by
 * descendant ID; DISTINCT prevents their join from multiplying image counts.
 *
 * @param array<int,int> $rootIds Positive root gallery identifiers.
 * @param bool $publicOnly Whether only publicly listable descendants are eligible.
 * @param bool $requireListedAccess Whether public access_listing must be listed.
 * @return list<GalleryDatabaseRow&array{image_count:int|string}> Full sensitive descendants ordered by parent_id/sort_order/title; direct public counts are not access authorization.
 */
function gallery_model_descendant_rows_for_roots(array $rootIds, bool $publicOnly, bool $requireListedAccess): array
{
    if ($rootIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($rootIds), '?'));
    $sql = "SELECT g.*, COUNT(DISTINCT i.id) AS image_count
        FROM galleries root
        JOIN galleries g ON g.folder_path LIKE CONCAT(root.folder_path, '/%')
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE root.id IN (" . $placeholders . ')'
        . gallery_model_public_listing_filter_sql('g', $publicOnly, $requireListedAccess)
        . ' GROUP BY g.id ORDER BY g.parent_id, g.sort_order, g.title';
    $stmt = db()->prepare($sql);
    $stmt->execute($rootIds);
    return $stmt->fetchAll();
}

/**
 * Return every root-to-descendant gallery membership row for branch counting.
 *
 * Includes each root itself and preserves a separate membership for every
 * overlapping input root. Uses unescaped stored folder-path LIKE prefixes,
 * applies only listing predicates, and specifies no result ordering.
 *
 * @param array<int,int> $rootIds Positive root gallery identifiers.
 * @param bool $publicOnly Whether only publicly listable descendants are eligible.
 * @param bool $requireListedAccess Whether public access_listing must be listed.
 * @return list<GalleryDatabaseRow&array{root_id:int|string}> Full sensitive member rows with their requested root ID; no image count or deduplication across roots.
 */
function gallery_model_branch_descendant_rows(array $rootIds, bool $publicOnly, bool $requireListedAccess): array
{
    if ($rootIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($rootIds), '?'));
    $sql = "SELECT root.id AS root_id, g.*
        FROM galleries root
        JOIN galleries g ON g.folder_path = root.folder_path OR g.folder_path LIKE CONCAT(root.folder_path, '/%')
        WHERE root.id IN (" . $placeholders . ')'
        . gallery_model_public_listing_filter_sql('g', $publicOnly, $requireListedAccess);
    $stmt = db()->prepare($sql);
    $stmt->execute($rootIds);
    return $stmt->fetchAll();
}

/**
 * Fetch one gallery row by numeric identifier.
 *
 * @param int $galleryId Gallery identifier.
 * @return GalleryDatabaseRow|null Raw sensitive row or null; no authorization, locking, localization or cache lookup.
 */
function gallery_model_find_by_id(int $galleryId): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE id = ?');
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Fetch one gallery row by the legacy slug column, not clean url_slug/url_path.
 *
 * @param string $slug Candidate slug compared using the database collation.
 * @return GalleryDatabaseRow|null Raw sensitive row or null, without visibility/access filtering.
 */
function gallery_model_find_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Fetch one gallery row by normalized folder-path hash.
 *
 * @param string $folderPathHash SHA-256 normalized folder path hash.
 * @return GalleryDatabaseRow|null Raw sensitive row or null; caller computes/normalizes the hash and enforces access.
 */
function gallery_model_find_by_folder_path_hash(string $folderPathHash): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE folder_path_hash = ?');
    $stmt->execute([$folderPathHash]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}


/**
 * Return nonempty stored gallery folder paths without additional normalization.
 *
 * @return list<string> Nonempty stored paths in unspecified database order; whitespace and duplicates are not removed.
 */
function gallery_model_folder_paths(): array
{
    $rows = db()->query('SELECT folder_path FROM galleries')->fetchAll(\PDO::FETCH_COLUMN);
    return array_values(array_filter(array_map('strval', $rows), /**
     * Exclude only empty path values after converting fetched scalars to strings.
     * @param string $path Stored folder path, not normalized by this filter.
     * @return bool True for any nonempty string, including whitespace.
     * @author Rudolf Klusal
     */ static fn (string $path): bool => $path !== ''));
}

/**
 * Generate a unique gallery slug from a human-readable title.
 *
 * Tries slugify(title), then suffixes -2, -3 and so on. This read-only search
 * does not reserve the result; the service guard and unique index remain
 * necessary to handle another writer choosing the same slug before insertion.
 *
 * @param string $title Human-readable gallery title.
 * @param int $excludeGalleryId Existing gallery identifier excluded from collision checks.
 * @return string Normalized candidate unused by another row at the time of its last lookup.
 */
function gallery_model_unique_slug(string $title, int $excludeGalleryId = 0): string
{
    $base = slugify($title);
    $slug = $base;
    $counter = 2;
    while (gallery_model_slug_exists($slug, $excludeGalleryId)) {
        $slug = $base . '-' . $counter;
        $counter++;
    }
    return $slug;
}

/**
 * Insert one gallery row using the explicitly supported persistence columns.
 *
 * Optional schema-aware fields may be omitted by callers when the respective
 * migration is unavailable. The model validates column names before composing
 * the INSERT statement so service orchestration never needs direct SQL access.
 * Required values, normalization, feature policy, folder creation and uniqueness
 * are caller/database responsibilities. No transaction or writer lease is opened.
 * This allowlist intentionally excludes credential hashes/display tokens and
 * edit_revision; the database supplies defaults for omitted supported columns.
 *
 * @param array{
 *   parent_id?:int|null,folder_path?:string,folder_path_hash?:string,slug?:string,title?:string,
 *   description?:string|null,sort_order?:int,visibility?:string,voting_enabled?:int,show_filenames?:int,
 *   content_language?:string|null,description_layout?:string|null,count_badge_visibility?:string|null,
 *   lightbox_browsing_mode?:string|null,gallery_date?:string|null,gallery_date_end?:string|null,
 *   grid_columns?:int|null,grid_rows?:int|null,grid_use_for_subgalleries?:int,
 *   thumbnail_min_size?:int|null,thumbnail_max_size?:int|null,access_mode?:string,access_listing?:string,
 *   cover_image_path?:string|null,banner_image_path?:string|null,logo_image_path?:string|null,
 *   separator_image_path?:string|null,background_source?:string|null,created_at?:string,updated_at?:string
 * } $fields Nonempty service-normalized insertion map; optional notation describes omission, not permission to omit database-required values.
 * @return int Newly created gallery identifier.
 * @throws InvalidArgumentException For an empty map or an unsupported column name.
 */
function gallery_model_insert(array $fields): int
{
    if ($fields === []) {
        throw new InvalidArgumentException('Gallery insert fields cannot be empty.');
    }

    $allowedColumns = [
        'parent_id',
        'folder_path',
        'folder_path_hash',
        'slug',
        'title',
        'description',
        'sort_order',
        'visibility',
        'voting_enabled',
        'show_filenames',
        'content_language',
        'description_layout',
        'count_badge_visibility',
        'lightbox_browsing_mode',
        'gallery_date',
        'gallery_date_end',
        'grid_columns',
        'grid_rows',
        'grid_use_for_subgalleries',
        'thumbnail_min_size',
        'thumbnail_max_size',
        'access_mode',
        'access_listing',
        'cover_image_path',
        'banner_image_path',
        'logo_image_path',
        'separator_image_path',
        'background_source',
        'created_at',
        'updated_at',
    ];

    $columns = [];
    $values = [];
    foreach ($fields as $column => $value) {
        if (!is_string($column) || !in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported gallery insert field.');
        }
        $columns[] = $column;
        $values[] = $value;
    }

    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO galleries (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute($values);
    return (int) $pdo->lastInsertId();
}

/**
 * Calculate a sort order that places a newly created gallery before its siblings.
 *
 * Nonpositive parent IDs select NULL-parent roots. Empty scopes yield -10;
 * no row or ordering position is reserved by this read.
 *
 * @param int $parentGalleryId Parent gallery identifier, or zero for root galleries.
 * @return int Sort order preceding the current first sibling by ten.
 */
function gallery_model_prepend_sort_order(int $parentGalleryId): int
{
    if ($parentGalleryId > 0) {
        $stmt = db()->prepare('SELECT COALESCE(MIN(sort_order), 0) FROM galleries WHERE parent_id = ?');
        $stmt->execute([$parentGalleryId]);
    } else {
        $stmt = db()->query('SELECT COALESCE(MIN(sort_order), 0) FROM galleries WHERE parent_id IS NULL');
    }
    return (int) $stmt->fetchColumn() - 10;
}

/**
 * Return whether the galleries table exposes the custom cover-image path column.
 *
 * Legacy observation only: missing metadata and any inspection exception both
 * become false. Do not use this as a three-state mutation authorization check.
 *
 * @return bool True for a returned column row; false for absence or unknown inspection failure.
 */
function gallery_model_cover_image_path_column_exists(): bool
{
    try {
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'cover_image_path'");
        return (bool) $stmt->fetch();
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Persist the selected gallery cover image identifier.
 *
 * Updates only the row's linked cover and timestamp. Does not verify image
 * ownership, resolve descendants, clear cover_image_path or refresh sidecars.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $imageId Cover image identifier.
 * @param string $now SQL timestamp written to updated_at.
 * @return void Executes the cover UPDATE; an absent gallery is not reported.
 */
function gallery_model_set_cover_image_id(int $galleryId, int $imageId, string $now): void
{
    $stmt = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ?');
    $stmt->execute([$imageId, $now, $galleryId]);
}

/**
 * Persist the optional gallery cover asset relative path.
 *
 * Does not validate or create/delete the asset, clear cover_image_id, or refresh
 * sidecars. The service must verify schema and retain filesystem writer ownership.
 *
 * @param int $galleryId Gallery identifier.
 * @param ?string $relativePath Relative cover asset path, or null to clear it.
 * @param string $now SQL timestamp written to updated_at.
 * @return void Executes the path UPDATE; an absent gallery is not reported.
 */
function gallery_model_set_cover_image_path(int $galleryId, ?string $relativePath, string $now): void
{
    $stmt = db()->prepare('UPDATE galleries SET cover_image_path = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ?');
    $stmt->execute([$relativePath, $now, $galleryId]);
}

/**
 * Return whether the galleries table exposes the per-gallery background source column.
 *
 * Legacy observation only: false conflates confirmed absence and inspection
 * failure, so it must not authorize security-sensitive or destructive changes.
 *
 * @return bool True for a returned column row; false for absence or any inspection exception.
 */
function gallery_model_background_source_column_exists(): bool
{
    try {
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'background_source'");
        return (bool) $stmt->fetch();
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Return gallery date-maintenance rows in folder order.
 *
 * @param bool $includeRangeEnd Whether gallery_date_end is available.
 * @return list<array{id:int|string,parent_id:int|string|null,folder_path:string,title:string,gallery_date:string|null,gallery_date_end:string|null}> All galleries in folder order; range end is an explicit null projection when unavailable.
 */
function gallery_model_date_suggestion_rows(bool $includeRangeEnd): array
{
    $selects = ['id', 'parent_id', 'folder_path', 'title', 'gallery_date'];
    $selects[] = $includeRangeEnd ? 'gallery_date_end' : 'NULL AS gallery_date_end';
    return db()->query('SELECT ' . implode(', ', $selects) . ' FROM galleries ORDER BY folder_path')->fetchAll();
}

/**
 * Return all gallery rows in stable folder-path order.
 *
 * @return list<GalleryDatabaseRow> Entire unfiltered catalog, including credentials and private paths; not a bounded picker or safe response payload.
 */
function gallery_model_all_rows_by_folder_path(): array
{
    return db()->query('SELECT * FROM galleries ORDER BY folder_path')->fetchAll();
}

/**
 * Clear every explicit gallery-grid override.
 *
 * Sets dimensions to NULL, restores grid_use_for_subgalleries=1 and timestamps
 * only matching rows. Does not clear sidecar copies or change global defaults.
 *
 * @param string $now SQL timestamp written to updated_at.
 * @return int Number of rows reported as changed.
 */
function gallery_model_reset_grid_overrides(string $now): int
{
    $stmt = db()->prepare('UPDATE galleries SET grid_columns = NULL, grid_rows = NULL, grid_use_for_subgalleries = 1, updated_at = ?, edit_revision = edit_revision + 1 WHERE grid_columns IS NOT NULL OR grid_rows IS NOT NULL OR grid_use_for_subgalleries <> 1');
    $stmt->execute([$now]);
    return $stmt->rowCount();
}

/**
 * Return imported gallery identifiers in stable folder-path order.
 *
 * @return list<int> Every catalog gallery ID, cast to int and ordered by stored folder_path.
 */
function gallery_model_ids_by_folder_path(): array
{
    $rows = db()->query('SELECT id FROM galleries ORDER BY folder_path')->fetchAll(\PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}


/**
 * Find a direct child by title equality under the database collation.
 *
 * Returns the first sort_order/id match without visibility or access filtering.
 * A zero parent is compared literally and does not select NULL-parent roots.
 *
 * @param int $parentGalleryId Parent gallery identifier.
 * @param string $title Title lookup value; case/accent equality is determined by the SQL collation.
 * @return GalleryDatabaseRow|null Full sensitive child row or null; caller decides whether it can be used.
 */
function gallery_model_find_child_by_title(int $parentGalleryId, string $title): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE parent_id = ? AND title = ? ORDER BY sort_order, id LIMIT 1');
    $stmt->execute([$parentGalleryId, $title]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return direct child gallery identifiers in stable display order.
 *
 * Uses sort_order then id, with no visibility filtering; parent zero is not NULL.
 *
 * @param int $parentGalleryId Parent gallery identifier.
 * @return list<int> Matching direct child IDs cast to int, or an empty list.
 */
function gallery_model_direct_child_ids(int $parentGalleryId): array
{
    $stmt = db()->prepare('SELECT id FROM galleries WHERE parent_id = ? ORDER BY sort_order, id');
    $stmt->execute([$parentGalleryId]);
    return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
}


/**
 * Return one gallery branch as identifiers ordered from parent to descendants.
 *
 * Normalizes separators and outer slashes only. The LIKE prefix is not escaped
 * for '%'/'_' in stored paths. If no rows match (or the path is empty), a
 * positive supplied ID is returned without checking whether that row exists.
 *
 * @param int $galleryId Fallback identity, not an additional query restriction.
 * @param string $folderPath Requested branch path; caller supplies a current validated snapshot.
 * @return list<int> Path-matched IDs ordered by path length/path/id, or the documented singleton/empty fallback.
 */
function gallery_model_branch_ids_by_folder_path(int $galleryId, string $folderPath): array
{
    $folderPath = trim(str_replace('\\', '/', $folderPath), '/');
    if ($folderPath === '') {
        return $galleryId > 0 ? [$galleryId] : [];
    }
    $stmt = db()->prepare('SELECT id FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $stmt->execute([$folderPath, $folderPath . '/%']);
    $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    return $ids !== [] ? $ids : ($galleryId > 0 ? [$galleryId] : []);
}

/**
 * Persist gallery thumbnail bounds shared by the supported thumbnail renderers.
 *
 * Deduplicates positive IDs before one timestamped UPDATE. Does not validate
 * min/max ordering, inspect schema, select descendants or regenerate derivatives.
 *
 * @param list<int|string> $galleryIds Explicit selection, integer-normalized before filtering.
 * @param int|null $minSize Service-validated minimum pixel bound, or null for inherited/default behavior.
 * @param int|null $maxSize Service-validated maximum pixel bound, or null for inherited/default behavior.
 * @param string $now Shared SQL timestamp written to selected rows.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_thumbnail_bounds(array $galleryIds, ?int $minSize, ?int $maxSize, string $now): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $galleryIds), /**
     * Exclude invalid nonpositive IDs before building the thumbnail-bound update.
     * @param int $id Integer-normalized submitted gallery identifier.
     * @return bool Whether the ID remains in the explicit update selection.
     * @author Rudolf Klusal
     */ static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('UPDATE galleries SET thumbnail_min_size = ?, thumbnail_max_size = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$minSize, $maxSize, $now], $ids));
    return $stmt->rowCount();
}
