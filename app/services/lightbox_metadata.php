<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/lightbox_metadata.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable data access helpers for lazy public lightbox metadata.
 *
 * Responsibilities:
 *   - Count gallery photos using the same ordering rules as public gallery pages
 *   - Fetch small ordered windows of image rows for asynchronous lightbox navigation
 *   - Compute stable zero-based positions for direct image links and visible cards
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
 *   2026-05-17
 */

declare(strict_types=1);

/**
 * Return true when the current public lightbox request must hide NSFW image rows.
 *
 * @param array $gallery Gallery database row used for inherited NSFW checks.
 * @param bool $publicOnly True when the current request uses anonymous visitor visibility.
 * @return bool True when restricted image rows must not be exposed to the browser.
 */
function gallery_lightbox_excludes_restricted_nsfw(array $gallery, bool $publicOnly): bool
{
    if (!$publicOnly || !nsfw_guard_schema_ready()) {
        return false;
    }
    return !visitor_can_access_nsfw_content();
}

/**
 * Return true when a whole gallery is NSFW-gated for the current public lightbox request.
 *
 * @param array $gallery Gallery database row used for inherited NSFW checks.
 * @param bool $publicOnly True when the current request uses anonymous visitor visibility.
 * @param bool $excludeRestrictedNsfw True when restricted image rows should be removed.
 * @return bool True when no image metadata may be exposed.
 */
function gallery_lightbox_gallery_restricted_by_nsfw(array $gallery, bool $publicOnly, bool $excludeRestrictedNsfw): bool
{
    return $publicOnly
        && $excludeRestrictedNsfw
        && nsfw_guard_schema_ready()
        && gallery_nsfw_requirement($gallery) !== null;
}

/**
 * Build the SQL WHERE clause shared by photo counts, page rows, and lightbox windows.
 *
 * @param bool $publicOnly True when only public image rows should be selected.
 * @param bool $excludeRestrictedNsfw True when image-level NSFW rows should be removed.
 * @return string SQL fragment with one gallery-id placeholder as the first parameter.
 */
function gallery_lightbox_image_where_sql(bool $publicOnly, bool $excludeRestrictedNsfw = false): string
{
    $where = "i.gallery_id = ? AND i.relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $where .= " AND i.visibility = 'public'";
    }
    if ($excludeRestrictedNsfw && nsfw_guard_schema_ready()) {
        $where .= ' AND COALESCE(i.nsfw_enabled, 0) = 0';
    }
    return $where;
}

/**
 * Count ordered top-level photos in one gallery.
 *
 * @param array $gallery Gallery database row.
 * @param bool $publicOnly True when only public image rows should be counted.
 * @param bool $excludeRestrictedNsfw True when lightbox-ineligible NSFW rows should be removed.
 * @return int Number of rows matching the request visibility rules.
 */
function gallery_lightbox_total_count(array $gallery, bool $publicOnly, bool $excludeRestrictedNsfw = false): int
{
    if (gallery_lightbox_gallery_restricted_by_nsfw($gallery, $publicOnly, $excludeRestrictedNsfw)) {
        return 0;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM images i WHERE ' . gallery_lightbox_image_where_sql($publicOnly, $excludeRestrictedNsfw));
    $stmt->execute([(int) $gallery['id']]);
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Fetch one ordered window of image rows with aggregate vote scores.
 *
 * The public gallery page uses this for the visible photo page. The JSON endpoint
 * uses the same helper for lazy lightbox metadata, keeping order and visibility
 * rules identical between server-rendered cards and asynchronous navigation.
 *
 * @param array $gallery Gallery database row.
 * @param bool $publicOnly True when only public image rows should be selected.
 * @param int $offset Zero-based row offset.
 * @param int|null $limit Maximum rows to return, or null for all rows.
 * @param bool $excludeRestrictedNsfw True when lightbox-ineligible NSFW rows should be removed.
 * @return array<int,array<string,mixed>> Ordered image rows.
 */
function gallery_lightbox_fetch_images(array $gallery, bool $publicOnly, int $offset = 0, ?int $limit = null, bool $excludeRestrictedNsfw = false): array
{
    if (gallery_lightbox_gallery_restricted_by_nsfw($gallery, $publicOnly, $excludeRestrictedNsfw)) {
        return [];
    }

    $offset = max(0, $offset);
    $where = gallery_lightbox_image_where_sql($publicOnly, $excludeRestrictedNsfw);
    $sql = "SELECT i.*, (
            SELECT COALESCE(SUM(v.vote), 0)
            FROM image_votes v
            WHERE v.image_id = i.id
        ) AS score
        FROM images i
        WHERE $where
        ORDER BY i.sort_order, i.filename, i.id";
    if ($limit !== null) {
        $sql .= ' LIMIT ? OFFSET ?';
    }

    $stmt = db()->prepare($sql);
    $stmt->bindValue(1, (int) $gallery['id'], PDO::PARAM_INT);
    if ($limit !== null) {
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Return the zero-based ordered position of one image under the supplied visibility rules.
 *
 * @param array $image Image database row whose gallery-local position is needed.
 * @param array $gallery Gallery database row.
 * @param bool $publicOnly True when only public image rows should be considered.
 * @param bool $excludeRestrictedNsfw True when lightbox-ineligible NSFW rows should be removed.
 * @return int Zero-based position, or -1 when the image cannot be part of the requested list.
 */
function gallery_lightbox_image_position(array $image, array $gallery, bool $publicOnly, bool $excludeRestrictedNsfw = false): int
{
    if ((int) ($image['gallery_id'] ?? 0) !== (int) ($gallery['id'] ?? 0)) {
        return -1;
    }
    if ($publicOnly && (string) ($image['visibility'] ?? '') !== 'public') {
        return -1;
    }
    if ($excludeRestrictedNsfw && image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content()) {
        return -1;
    }
    if (gallery_lightbox_gallery_restricted_by_nsfw($gallery, $publicOnly, $excludeRestrictedNsfw)) {
        return -1;
    }

    $where = gallery_lightbox_image_where_sql($publicOnly, $excludeRestrictedNsfw);
    $sql = 'SELECT COUNT(*)
        FROM images i
        WHERE ' . $where . '
          AND (
              i.sort_order < ?
              OR (i.sort_order = ? AND i.filename < ?)
              OR (i.sort_order = ? AND i.filename = ? AND i.id < ?)
          )';
    $stmt = db()->prepare($sql);
    $stmt->execute([
        (int) $gallery['id'],
        (int) ($image['sort_order'] ?? 0),
        (int) ($image['sort_order'] ?? 0),
        (string) ($image['filename'] ?? ''),
        (int) ($image['sort_order'] ?? 0),
        (string) ($image['filename'] ?? ''),
        (int) ($image['id'] ?? 0),
    ]);
    return max(0, (int) $stmt->fetchColumn());
}
