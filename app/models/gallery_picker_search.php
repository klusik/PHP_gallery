<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Model
 * Purpose: Query bounded destination candidates from the gallery catalog.
 * Responsibilities:
 *   - Accept semantic search inputs and return keyset-limited rows with hierarchy context.
 * File: app/models/gallery_picker_search.php
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Owns bounded destination lookup SQL; callers supply semantic search inputs.
 */
declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

use const Gallery\Core\GALLERY_PICKER_SEARCH_PAGE_SIZE;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * Read one compact selected/excluded gallery without loading its branch.
 *
 * @param int $galleryId Positive physical gallery identifier.
 * @return ?array<string,mixed> Compact gallery row, or null when missing.
 */
function gallery_picker_model_selection(int $galleryId): ?array
{
    if ($galleryId <= 0) {
        return null;
    }
    $statement = db()->prepare('SELECT id, title, folder_path FROM galleries WHERE id = ? LIMIT 1');
    $statement->execute([$galleryId]);
    $row = $statement->fetch(\PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Escape literal search/path text for a portable SQL LIKE expression.
 *
 * @param string $text Literal text, never SQL syntax.
 * @return string Escaped text using the explicit exclamation escape character.
 */
function gallery_picker_model_like_literal(string $text): string
{
    return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);
}

/**
 * Fetch one stable ID-keyset page of literal title/path matches.
 *
 * LIMIT bounds materialization, not database examined rows. No count, OFFSET,
 * whole-tree fallback, schema probe, or request-selected SQL fragment is used.
 *
 * @param string $query Literal title or relative path substring.
 * @param int $afterId Exclusive last returned gallery identifier, zero initially.
 * @param int $excludedGalleryId Single source gallery to omit.
 * @param ?string $excludedBranchPath Source path when descendants must also be omitted.
 * @return array<int,array<string,mixed>> At most thirty compact gallery rows.
 */
function gallery_picker_model_search(string $query, int $afterId = 0, int $excludedGalleryId = 0, ?string $excludedBranchPath = null): array
{
    $sql = 'SELECT id, title, folder_path FROM galleries WHERE id > ? AND id <> ?';
    $parameters = [max(0, $afterId), max(0, $excludedGalleryId)];
    if ($query !== '') {
        $sql .= " AND (title LIKE ? ESCAPE '!' OR folder_path LIKE ? ESCAPE '!')";
        $pattern = '%' . gallery_picker_model_like_literal($query) . '%';
        $parameters[] = $pattern;
        $parameters[] = $pattern;
    }
    if ($excludedBranchPath !== null) {
        $sql .= " AND folder_path <> ? AND folder_path NOT LIKE ? ESCAPE '!'";
        $parameters[] = $excludedBranchPath;
        $parameters[] = gallery_picker_model_like_literal(rtrim($excludedBranchPath, '/')) . '/%';
    }
    $sql .= ' ORDER BY id ASC LIMIT ' . GALLERY_PICKER_SEARCH_PAGE_SIZE;
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
}
