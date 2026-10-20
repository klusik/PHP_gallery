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

namespace Gallery\Services;

use PDOException;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\url_for;

const THEME_FAVORITE_GALLERIES_SETTING = 'theme_favorite_gallery_ids';

const THEME_FAVORITE_GALLERIES_MAX = 3;

const THEME_FAVORITE_GALLERIES_HOME_TOKEN = 'home';

/**
 * Normalize submitted or stored favorite shortcut entries.
 * The Theme form submits ordered shortcut values. Older or manually edited
 * installations may contain JSON, CSV, or a scalar value, so this helper accepts
 * all three formats and returns a stable, duplicate-free list. Numeric entries
 * are gallery IDs, while the home token represents the main gallery page.
 *
 * @param string|int|float|array<array-key,mixed>|bool|null $value Stored JSON/CSV/scalar setting or submitted value list; unsupported types yield an empty list, while array entries are string-coerced for token/ID checks.
 * @param bool $limit Whether the result should be limited to the supported maximum.
 * @return list<int|'home'> Ordered list of unique positive gallery IDs and at most one literal 'home' shortcut, limited to the supported maximum when requested.
 */
function theme_favorite_gallery_ids_normalize(
    mixed $value,
    bool $limit = true,
): array {
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
    return theme_favorite_gallery_ids_normalize(app_setting(
        THEME_FAVORITE_GALLERIES_SETTING,
        '[]',
    ));
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
 * @param array<int,int|string> $ids Saved gallery IDs or the main-page shortcut token.
 * @return array<int,array<string,mixed>> Source rows keyed by gallery ID.
 */
function theme_favorite_gallery_rows_by_ids(array $ids): array
{
    // $ids stores validated lookup IDs after dropping non-gallery shortcut tokens.
    $ids = array_values(array_filter(
        theme_favorite_gallery_ids_normalize($ids),
        'is_int',
    ));
    if ($ids === []) {
        return [];
    }

    // Access columns must be evaluated as one capability. A metadata inspection
    // failure or partially applied access migration must never be converted into
    // permissive normal/listed aliases for public favorite-gallery consumers.
    gallery_access_assert_public_policy_available();
    try {
        return \Gallery\Models\favorite_gallery_model_rows_by_ids(
            $ids,
            db_column_exists('galleries', 'url_path'),
            gallery_access_schema_ready(),
            content_localization_enabled()
                && content_localization_schema_ready('gallery'),
        );
    } catch (PDOException) {
        return [];
    }
}

/**
 * Return submitted favorite IDs that exist in a resolved gallery row set.
 *
 * @param list<int|'home'> $ids Normalized submitted gallery IDs and optional home shortcut.
 * @param array<array-key,array<string,int|float|string|bool|null>> $rows Resolved gallery rows keyed by ID or row index.
 * @return list<int|'home'> Submitted shortcuts that still resolve to a gallery or the main page.
 */
function theme_favorite_gallery_existing_ids_from_rows(
    array $ids,
    array $rows,
): array {
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
        if (
            $galleryId === THEME_FAVORITE_GALLERIES_HOME_TOKEN
            || isset($existingIds[(int) $galleryId])
        ) {
            $savedIds[] = $galleryId;
        }
    }
    return $savedIds;
}

/**
 * Build ordered shortcut values from the Theme form slot fields.
 *
 * @param array<array-key,mixed>|scalar|null $types Submitted slot-type list; values are string-coerced and only empty, 'home', or 'gallery' affect a slot. Non-array input is treated as no slots.
 * @param array<array-key,mixed>|scalar|null $galleryIds Submitted picker-value list aligned by slot index; gallery slots use positive integer-like values. Non-array input is treated as no IDs.
 * @return list<int|'home'> Ordered list of unique positive gallery IDs and at most one literal 'home' shortcut after normalization and maximum-count clamping.
 */
function theme_favorite_gallery_entries_from_form(
    mixed $types,
    mixed $galleryIds,
): array {
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
 * @param array<array-key,string|null> $types Submitted slot type values: empty, home, or gallery.
 * @param array<array-key,int|string|null> $galleryIds Submitted gallery picker values aligned to slot indexes.
 * @return array{saved_ids:list<int|'home'>,removed_ids:list<int|'home'>} Validated saved shortcuts and submitted values dropped because their galleries no longer exist.
 */
function save_theme_favorite_gallery_slots(
    mixed $types,
    mixed $galleryIds,
): array {
    return save_theme_favorite_gallery_ids(theme_favorite_gallery_entries_from_form(
        $types,
        $galleryIds,
    ));
}

/**
 * Save submitted favorite galleries after removing duplicates and missing rows.
 *
 * @param string|int|float|array<array-key,mixed>|bool|null $input Submitted favorite shortcut list, JSON/CSV string, numeric scalar ID, or home token; unsupported types normalize to an empty list.
 * @return array{saved_ids:list<int|'home'>,removed_ids:list<int|'home'>} Result with saved_ids containing persisted home/valid gallery shortcuts and removed_ids containing submitted gallery IDs whose rows no longer exist.
 */
function save_theme_favorite_gallery_ids(mixed $input): array
{
    // $submittedIds stores normalized form values before existence checks.
    $submittedIds = theme_favorite_gallery_ids_normalize($input, false);
    // $existingRows stores rows that still exist in the galleries table.
    $existingRows = theme_favorite_gallery_rows_by_ids($submittedIds);
    // $savedIds stores submitted IDs that resolved to live galleries.
    $savedIds = array_slice(
        theme_favorite_gallery_existing_ids_from_rows(
            $submittedIds,
            $existingRows,
        ),
        0,
        THEME_FAVORITE_GALLERIES_MAX,
    );
    // $removedIds stores values dropped because their gallery row was missing.
    $removedIds = array_values(array_diff($submittedIds, $savedIds));
    set_app_setting(
        THEME_FAVORITE_GALLERIES_SETTING,
        theme_favorite_gallery_ids_encode($savedIds),
    );
    return [
        'saved_ids' => $savedIds,
        'removed_ids' => $removedIds,
    ];
}

/**
 * Convert resolved rows into public header navigation items.
 *
 * @param list<int|'home'> $ids Normalized saved gallery IDs and optional home shortcut.
 * @param array<array-key,array<string,int|float|string|bool|null>> $rows Resolved gallery rows keyed by ID or row index.
 * @param bool $publicOnly Whether private and unlisted rows must be filtered out.
 * @return list<array{id:int|'home',title:string,url:string,gallery:array<string,int|float|string|bool|null>|null}> Ordered home or gallery navigation items with their resolved gallery row.
 */
function theme_favorite_gallery_navigation_items_from_rows(
    array $ids,
    array $rows,
    bool $publicOnly,
): array {
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
        if (
            $publicOnly
            && function_exists('Gallery\\Services\\gallery_is_public_listed')
            && !gallery_is_public_listed($gallery)
        ) {
            continue;
        }
        // $title stores a stable label even if an old row has an empty title.
        $title = trim((string) ($gallery['title'] ?? ''));
        if ($title === '') {
            $title = t('nav.favorite_gallery_fallback', 'Gallery {id}', [
                'id' => (int) $galleryId,
            ]);
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
    $rows = theme_favorite_gallery_rows_by_ids($ids);
    // Apply presentation overlays after the existing listing policy, without
    // changing the raw rows used by favorite selection and settings editors.
    $rows = array_filter(
        $rows,
        static fn(array $gallery): bool => !$publicOnly
        || gallery_is_public_listed($gallery),
    );
    $rows = content_localize_entities('gallery', array_values($rows));
    return theme_favorite_gallery_navigation_items_from_rows(
        $ids,
        $rows,
        $publicOnly,
    );
}
