<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/public_search.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database access for the legacy/no-phase public search compatibility path.
 *
 * Responsibilities:
 *   - Execute compatibility gallery and image search queries
 *   - Keep PDO, SQL assembly, and schema-sensitive gallery column selection out of services
 *   - Preserve the historical no-phase result source while progressive phases remain the normal browser path
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
 *   - Listing SQL fragments are hardcoded service-policy fragments and must never contain user-derived values.
 *   - Progressive candidate-first data access lives in app/models/public_search_progressive.php.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;
use function Gallery\Core\db_column_exists;

/**
 * Fetch gallery rows for the legacy/no-phase compatibility search.
 *
 * This query intentionally preserves the historical broad search semantics. It is
 * not used by the normal progressive browser flow, but it remains available to
 * callers that use the endpoint without a phase parameter.
 *
 * @param string $query Normalized search query.
 * @param string $like Escaped substring LIKE pattern.
 * @param string $listingCondition Hardcoded public-listing SQL condition.
 * @param array<int, mixed> $contextParams Bound branch-scope values.
 * @param bool $aiSearchReady Whether AI searchable metadata may be queried.
 * @param bool $localizedGallerySearchReady Whether gallery translations may be queried.
 * @param string $contentLanguage Active content language.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw compatibility gallery rows.
 */
function public_search_model_compatibility_gallery_rows(
    string $query,
    string $like,
    string $listingCondition,
    array $contextParams,
    bool $aiSearchReady,
    bool $localizedGallerySearchReady,
    string $contentLanguage,
    int $limit
): array {
    $limit = max(1, min(90, $limit));
    $aiJoin = $aiSearchReady ? 'LEFT JOIN image_ai_metadata public_image_ai ON public_image_ai.image_id = public_image.id' : '';
    $aiScoreSql = $aiSearchReady ? ', MAX(CASE WHEN public_image_ai.searchable_text LIKE ? THEN 10 ELSE 0 END) AS ai_score' : ', 0 AS ai_score';
    $aiWhereSql = $aiSearchReady ? ' OR public_image_ai.searchable_text LIKE ?' : '';
    $localizedGalleryWhereSql = $localizedGallerySearchReady ? ' OR EXISTS (SELECT 1 FROM gallery_translations content_gallery_translation WHERE content_gallery_translation.gallery_id = g.id AND content_gallery_translation.language_code = ? AND (content_gallery_translation.title LIKE ? OR content_gallery_translation.description LIKE ?))' : '';

    $sql = "SELECT g.*,
            GROUP_CONCAT(DISTINCT gallery_tag.name ORDER BY gallery_tag.name SEPARATOR ', ') AS gallery_tag_names,
            GROUP_CONCAT(DISTINCT image_tag.name ORDER BY image_tag.name SEPARATOR ', ') AS image_tag_names,
            MAX(CASE WHEN LOWER(g.title) = LOWER(?) THEN 80 ELSE 0 END) AS exact_title_score,
            MAX(CASE WHEN g.title LIKE ? THEN 40 ELSE 0 END) AS title_score,
            MAX(CASE WHEN gallery_tag.name LIKE ? THEN 24 ELSE 0 END) AS gallery_tag_score,
            MAX(CASE WHEN public_image.filename LIKE ? OR public_image.title LIKE ? THEN 16 ELSE 0 END) AS image_name_score
            $aiScoreSql
        FROM galleries g
        LEFT JOIN images public_image ON public_image.gallery_id = g.id AND public_image.visibility = 'public'
        $aiJoin
        LEFT JOIN gallery_tags gt ON gt.gallery_id = g.id
        LEFT JOIN tags gallery_tag ON gallery_tag.id = gt.tag_id
        LEFT JOIN image_tags it ON it.image_id = public_image.id
        LEFT JOIN tags image_tag ON image_tag.id = it.tag_id
        WHERE $listingCondition
          AND (
              g.title LIKE ? OR g.description LIKE ?
              OR gallery_tag.name LIKE ? OR gallery_tag.description LIKE ?
              OR public_image.filename LIKE ? OR public_image.title LIKE ? OR public_image.description LIKE ?
              OR image_tag.name LIKE ? OR image_tag.description LIKE ?
              $aiWhereSql
              $localizedGalleryWhereSql
          )
        GROUP BY g.id
        ORDER BY exact_title_score DESC, title_score DESC, gallery_tag_score DESC, image_name_score DESC, ai_score DESC, g.title ASC
        LIMIT " . (int) $limit;

    $scoreParams = [$query, $like, $like, $like, $like];
    if ($aiSearchReady) {
        $scoreParams[] = $like;
    }
    $whereParams = [$like, $like, $like, $like, $like, $like, $like, $like, $like];
    if ($aiSearchReady) {
        $whereParams[] = $like;
    }
    if ($localizedGallerySearchReady) {
        array_push($whereParams, $contentLanguage, $like, $like);
    }

    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge($scoreParams, $contextParams, $whereParams));
    return $stmt->fetchAll();
}

/**
 * Fetch image rows for the legacy/no-phase compatibility search.
 *
 * @param string $query Normalized search query.
 * @param string $like Escaped substring LIKE pattern.
 * @param string $listingCondition Hardcoded public-listing SQL condition.
 * @param array<int, mixed> $contextParams Bound branch-scope values.
 * @param bool $aiSearchReady Whether AI searchable metadata may be queried.
 * @param bool $localizedSearchReady Whether image translations may be queried.
 * @param string $contentLanguage Active content language.
 * @param int $limit Maximum rows returned.
 * @return array<int, array<string, mixed>> Raw compatibility image rows.
 */
function public_search_model_compatibility_image_rows(
    string $query,
    string $like,
    string $listingCondition,
    array $contextParams,
    bool $aiSearchReady,
    bool $localizedSearchReady,
    string $contentLanguage,
    int $limit
): array {
    $limit = max(1, min(90, $limit));
    $aiJoin = $aiSearchReady ? 'LEFT JOIN image_ai_metadata image_ai ON image_ai.image_id = i.id' : '';
    $aiScoreSql = $aiSearchReady ? ', MAX(CASE WHEN image_ai.searchable_text LIKE ? THEN 14 ELSE 0 END) AS ai_score' : ', 0 AS ai_score';
    $aiWhereSql = $aiSearchReady ? ' OR image_ai.searchable_text LIKE ?' : '';
    $localizedWhereSql = $localizedSearchReady ? ' OR EXISTS (SELECT 1 FROM image_translations content_image_translation WHERE content_image_translation.image_id = i.id AND content_image_translation.language_code = ? AND (content_image_translation.title LIKE ? OR content_image_translation.description LIKE ?))' : '';

    $sql = "SELECT i.*, g.id AS matched_gallery_id, g.parent_id AS matched_gallery_parent_id,
            g.folder_path AS matched_gallery_folder_path, g.folder_path_hash AS matched_gallery_folder_path_hash,
            g.slug AS matched_gallery_slug, g.title AS matched_gallery_title, g.description AS matched_gallery_description,
            g.cover_image_id AS matched_gallery_cover_image_id, g.sort_order AS matched_gallery_sort_order,
            g.visibility AS matched_gallery_visibility, g.voting_enabled AS matched_gallery_voting_enabled,
            g.show_filenames AS matched_gallery_show_filenames, g.access_mode AS matched_gallery_access_mode,
            g.access_listing AS matched_gallery_access_listing, g.access_password_hash AS matched_gallery_access_password_hash,
            g.access_share_token AS matched_gallery_access_share_token, g.access_token_hash AS matched_gallery_access_token_hash,
            g.access_token_expires_at AS matched_gallery_access_token_expires_at, g.created_at AS matched_gallery_created_at,
            g.updated_at AS matched_gallery_updated_at,
            " . (db_column_exists('galleries', 'url_slug') ? 'g.url_slug AS matched_gallery_url_slug,' : "'' AS matched_gallery_url_slug,") . "
            " . (db_column_exists('galleries', 'url_path') ? 'g.url_path AS matched_gallery_url_path,' : "'' AS matched_gallery_url_path,") . "
            GROUP_CONCAT(DISTINCT image_tag.name ORDER BY image_tag.name SEPARATOR ', ') AS image_tag_names,
            MAX(CASE WHEN LOWER(i.filename) = LOWER(?) OR LOWER(i.title) = LOWER(?) THEN 70 ELSE 0 END) AS exact_name_score,
            MAX(CASE WHEN i.filename LIKE ? OR i.title LIKE ? THEN 36 ELSE 0 END) AS name_score,
            MAX(CASE WHEN image_tag.name LIKE ? THEN 24 ELSE 0 END) AS tag_score
            $aiScoreSql
        FROM images i
        INNER JOIN galleries g ON g.id = i.gallery_id
        $aiJoin
        LEFT JOIN image_tags it ON it.image_id = i.id
        LEFT JOIN tags image_tag ON image_tag.id = it.tag_id
        WHERE i.visibility = 'public'
          AND $listingCondition
          AND (
              i.filename LIKE ? OR i.title LIKE ? OR i.description LIKE ?
              OR image_tag.name LIKE ? OR image_tag.description LIKE ?
              $aiWhereSql
              $localizedWhereSql
          )
        GROUP BY i.id
        ORDER BY exact_name_score DESC, name_score DESC, tag_score DESC, ai_score DESC, i.filename ASC
        LIMIT " . (int) $limit;

    $scoreParams = [$query, $query, $like, $like, $like];
    if ($aiSearchReady) {
        $scoreParams[] = $like;
    }
    $whereParams = [$like, $like, $like, $like, $like];
    if ($aiSearchReady) {
        $whereParams[] = $like;
    }
    if ($localizedSearchReady) {
        array_push($whereParams, $contentLanguage, $like, $like);
    }

    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge($scoreParams, $contextParams, $whereParams));
    return $stmt->fetchAll();
}
