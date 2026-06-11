<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/favorite_galleries.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Resolves Theme-configured favorite gallery shortcuts for header navigation.
 *
 * Responsibilities:
 *   - Normalize and persist up to three favorite gallery IDs
 *   - Resolve configured IDs to existing gallery rows in one place
 *   - Keep duplicate, missing, and inaccessible favorites out of public output
 *   - Provide database-free helpers for focused regression tests
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

const THEME_FAVORITE_GALLERIES_SETTING = 'theme_favorite_gallery_ids';
const THEME_FAVORITE_GALLERIES_MAX = 3;
const THEME_FAVORITE_GALLERIES_HOME_TOKEN = 'home';

/**
 * Normalize submitted or stored favorite shortcut entries.
 *
 * The Theme form submits ordered shortcut values. Older or manually edited
 * installations may contain JSON, CSV, or a scalar value, so this helper accepts
 * all three formats and returns a stable, duplicate-free list. Numeric entries
 * are gallery IDs, while the home token represents the main gallery page.
 *
 * @param mixed $value Raw stored setting or submitted form value.
 * @param bool $limit Whether the result should be limited to the supported maximum.
 * @return array<int int|string> Ordered shortcut entries.
 */
function theme_favorite_gallery_ids_normalize(mixed $value, bool $limit = true): array
{
    // $rawValues stores candidate values before numeric validation.
    $rawValues = [];
    if (is_string($value)) {
        // $trimmed stores the submitted or DB value without surrounding whitespace.
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        // $decoded stores JSON arrays from the structured app setting.
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $rawValues = $decoded;
        } else {
            $rawValues = preg_split('/[\s,;]+/', $trimmed) ?: [];
        }
    } elseif (is_array($value)) {
        $rawValues = $value;
    } elseif (is_int($value) || is_float($value)) {
        $rawValues = [$value];
    }

    // $items stores validated shortcuts in the order chosen by the admin.
    $items = [];
    foreach ($rawValues as $rawValue) {
        // $rawString stores string values before token or ID validation.
        $rawString = strtolower(trim((string) $rawValue));
        if ($rawString === THEME_FAVORITE_GALLERIES_HOME_TOKEN) {
            if (!in_array(THEME_FAVORITE_GALLERIES_HOME_TOKEN, $items, true)) {
                $items[] = THEME_FAVORITE_GALLERIES_HOME_TOKEN;
            }
        } else {
            // $id stores the normalized candidate gallery ID.
            $id = (int) $rawValue;
            if ($id <= 0 || in_array($id, $items, true)) {
                continue;
            }
            $items[] = $id;
        }
        if ($limit && count($items) >= THEME_FAVORITE_GALLERIES_MAX) {
            break;
        }
    }
    return $items;
}

/**
 * Return the configured favorite gallery IDs from app settings.
 *
 * @return array<int int|string> Ordered configured shortcuts.
 */
function theme_favorite_gallery_ids(): array
{
    return theme_favorite_gallery_ids_normalize(app_setting(THEME_FAVORITE_GALLERIES_SETTING, '[]'));
}

/**
 * Encode favorite gallery IDs for the structured app setting value.
 *
 * @param array $ids Ids value.
 * @return string Text result for the caller.
 */
function theme_favorite_gallery_ids_encode(array $ids): string
{
    // $normalized stores only supported shortcut entries before JSON encoding.
    $normalized = theme_favorite_gallery_ids_normalize($ids);
    // $encoded stores the stable JSON representation used in app_settings.
    $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '[]';
}

/**
 * Resolve gallery rows for selected IDs while preserving enough data for links.
 *
 * @param array $ids Ids value.
 * @return array<int array<string, mixed>> Rows keyed by gallery ID.
 */
function theme_favorite_gallery_rows_by_ids(array $ids): array
{
    // $ids stores validated lookup IDs after dropping non-gallery shortcut tokens.
    $ids = array_values(array_filter(theme_favorite_gallery_ids_normalize($ids), 'is_int'));
    if ($ids === []) {
        return [];
    }

    // $selects stores explicit columns so partially upgraded installations stay safe.
    $selects = [
        'id',
        'parent_id',
        'folder_path',
        'slug',
        'title',
        'visibility',
    ];
    $selects[] = db_column_exists('galleries', 'url_path') ? 'url_path' : "'' AS url_path";
    $selects[] = db_column_exists('galleries', 'access_mode') ? 'access_mode' : "'normal' AS access_mode";
    $selects[] = db_column_exists('galleries', 'access_listing') ? 'access_listing' : "'listed' AS access_listing";
    $selects[] = db_column_exists('galleries', 'access_password_hash') ? 'access_password_hash' : 'NULL AS access_password_hash';
    $selects[] = db_column_exists('galleries', 'access_token_hash') ? 'access_token_hash' : 'NULL AS access_token_hash';
    $selects[] = db_column_exists('galleries', 'access_token_expires_at') ? 'access_token_expires_at' : 'NULL AS access_token_expires_at';

    // $placeholders stores the prepared statement placeholders for the ID list.
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    try {
        // $stmt stores the favorite gallery lookup.
        $stmt = db()->prepare('SELECT ' . implode(', ', $selects) . ' FROM galleries WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        // $rows stores lookup results keyed by integer gallery ID.
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            // $galleryId stores the numeric row key used for order restoration.
            $galleryId = (int) ($row['id'] ?? 0);
            if ($galleryId > 0) {
                $rows[$galleryId] = $row;
            }
        }
        return $rows;
    } catch (PDOException) {
        return [];
    }
}


/**
 * Return submitted favorite IDs that exist in a resolved gallery row set.
 *
 * @param array $ids Ids value.
 * @param array $rows Rows to process.
 * @return array<int int|string> Submitted shortcuts that still resolve safely.
 */
function theme_favorite_gallery_existing_ids_from_rows(array $ids, array $rows): array
{
    // $normalizedIds stores submitted IDs after duplicate removal and max-count clamping.
    $normalizedIds = theme_favorite_gallery_ids_normalize($ids, false);
    if ($normalizedIds === []) {
        return [];
    }

    // $existingIds stores every available gallery row ID.
    $existingIds = [];
    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        // $galleryId stores either the explicit row ID or the array key fallback.
        $galleryId = (int) ($row['id'] ?? (is_int($key) ? $key : 0));
        if ($galleryId > 0) {
            $existingIds[$galleryId] = true;
        }
    }

    // $savedIds stores only configured shortcuts that still exist or point to the main page.
    $savedIds = [];
    foreach ($normalizedIds as $galleryId) {
        if ($galleryId === THEME_FAVORITE_GALLERIES_HOME_TOKEN || isset($existingIds[(int) $galleryId])) {
            $savedIds[] = $galleryId;
        }
    }
    return $savedIds;
}


/**
 * Build ordered shortcut values from the Theme form slot fields.
 *
 * @param mixed $types Submitted slot types: empty, home, or gallery.
 * @param mixed $galleryIds Submitted gallery picker IDs aligned by slot index.
 * @return array<int int|string> Ordered shortcut entries ready for validation and saving.
 */
function theme_favorite_gallery_entries_from_form(mixed $types, mixed $galleryIds): array
{
    // $typeValues stores normalized slot type values from the form.
    $typeValues = is_array($types) ? array_values($types) : [];
    // $galleryValues stores gallery picker values aligned with the type slots.
    $galleryValues = is_array($galleryIds) ? array_values($galleryIds) : [];
    // $entries stores explicit shortcut entries before duplicate and existence checks.
    $entries = [];
    for ($index = 0; $index < THEME_FAVORITE_GALLERIES_MAX; $index++) {
        // $type stores the requested shortcut type for one slot.
        $type = strtolower(trim((string) ($typeValues[$index] ?? '')));
        if ($type === THEME_FAVORITE_GALLERIES_HOME_TOKEN) {
            $entries[] = THEME_FAVORITE_GALLERIES_HOME_TOKEN;
            continue;
        }
        if ($type !== 'gallery') {
            continue;
        }
        // $galleryId stores the committed picker value for a gallery shortcut slot.
        $galleryId = (int) ($galleryValues[$index] ?? 0);
        if ($galleryId > 0) {
            $entries[] = $galleryId;
        }
    }
    return theme_favorite_gallery_ids_normalize($entries);
}

/**
 * Save favorite shortcut slots submitted by the Theme form.
 *
 * @param mixed $types Submitted slot types.
 * @param mixed $galleryIds Submitted gallery IDs aligned by slot index.
 * @return array<string array<int, int|string>> Saved shortcuts and removed submitted entries.
 */
function save_theme_favorite_gallery_slots(mixed $types, mixed $galleryIds): array
{
    return save_theme_favorite_gallery_ids(theme_favorite_gallery_entries_from_form($types, $galleryIds));
}

/**
 * Save submitted favorite galleries after removing duplicates and missing rows.
 *
 * @param mixed $input Submitted form value, usually favorite_gallery_ids[].
 * @return array<string array<int, int>> Saved IDs and removed submitted IDs.
 */
function save_theme_favorite_gallery_ids(mixed $input): array
{
    // $submittedIds stores normalized form values before existence checks.
    $submittedIds = theme_favorite_gallery_ids_normalize($input, false);
    // $existingRows stores rows that still exist in the galleries table.
    $existingRows = theme_favorite_gallery_rows_by_ids($submittedIds);
    // $savedIds stores submitted IDs that resolved to live galleries.
    $savedIds = array_slice(theme_favorite_gallery_existing_ids_from_rows($submittedIds, $existingRows), 0, THEME_FAVORITE_GALLERIES_MAX);
    // $removedIds stores values dropped because their gallery row was missing.
    $removedIds = array_values(array_diff($submittedIds, $savedIds));
    set_app_setting(THEME_FAVORITE_GALLERIES_SETTING, theme_favorite_gallery_ids_encode($savedIds));
    return [
        'saved_ids' => $savedIds,
        'removed_ids' => $removedIds,
    ];
}

/**
 * Convert resolved rows into public header navigation items.
 *
 * @param array $ids Ids value.
 * @param array $rows Rows to process.
 * @param bool $publicOnly Whether private and unlisted rows must be filtered out.
 * @return array<int array<string, mixed>> Navigation items with id, title, url, and gallery row.
 */
function theme_favorite_gallery_navigation_items_from_rows(array $ids, array $rows, bool $publicOnly): array
{
    // $normalizedIds stores the preferred order without duplicate IDs.
    $normalizedIds = theme_favorite_gallery_ids_normalize($ids);
    if ($normalizedIds === []) {
        return [];
    }

    // $rowMap stores rows keyed by integer gallery ID, regardless of input shape.
    $rowMap = [];
    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        // $galleryId stores either the explicit row ID or the array key fallback.
        $galleryId = (int) ($row['id'] ?? (is_int($key) ? $key : 0));
        if ($galleryId > 0) {
            $rowMap[$galleryId] = $row;
        }
    }

    // $items stores final navigation models consumed by the header renderer.
    $items = [];
    foreach ($normalizedIds as $galleryId) {
        if ($galleryId === THEME_FAVORITE_GALLERIES_HOME_TOKEN) {
            $items[] = [
                'id' => THEME_FAVORITE_GALLERIES_HOME_TOKEN,
                'title' => t('nav.favorite_home', 'Main page'),
                'url' => url_for('home'),
                'gallery' => null,
            ];
            continue;
        }
        if (!isset($rowMap[(int) $galleryId])) {
            continue;
        }
        // $gallery stores the resolved gallery row for one favorite button.
        $gallery = $rowMap[(int) $galleryId];
        if ($publicOnly && function_exists('gallery_is_public_listed') && !gallery_is_public_listed($gallery)) {
            continue;
        }
        // $title stores a stable label even if an old row has an empty title.
        $title = trim((string) ($gallery['title'] ?? ''));
        if ($title === '') {
            $title = t('nav.favorite_gallery_fallback', 'Gallery {id}', ['id' => (int) $galleryId]);
        }
        $items[] = [
            'id' => (int) $galleryId,
            'title' => $title,
            'url' => gallery_public_url($gallery),
            'gallery' => $gallery,
        ];
    }
    return $items;
}

/**
 * Return favorite gallery buttons for the current header request.
 *
 * @param bool $publicOnly Whether the caller is rendering for an anonymous public visitor.
 * @return array<int array<string, mixed>> Navigation items in configured order.
 */
function theme_favorite_gallery_navigation_items(bool $publicOnly): array
{
    // $ids stores the configured ordered favorite IDs.
    $ids = theme_favorite_gallery_ids();
    if ($ids === []) {
        return [];
    }
    return theme_favorite_gallery_navigation_items_from_rows($ids, theme_favorite_gallery_rows_by_ids($ids), $publicOnly);
}
