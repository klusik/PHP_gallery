<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_gallery_renderers.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Provides small admin select-list and option renderers.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one admin or thumbnail responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   2026-05-19
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Services\all_tag_names;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_editor_unique_slug;
use function Gallery\Services\gallery_cover_choices;
use function Gallery\Services\gallery_images;
use function Gallery\Services\gallery_search_picker_rows;
use function Gallery\Services\gallery_picker_source_rows;
use function Gallery\Services\gallery_visibility_label;
use function Gallery\Services\gallery_visibility_values;
use function Gallery\Services\normalize_gallery_visibility;
use function Gallery\Services\t;
use function Gallery\Services\weighted_tag_suggestions_for_gallery;
use function Gallery\Views\view_render_admin_select_options;
use function Gallery\Views\view_render_gallery_search_picker;
use function Gallery\Views\view_render_tag_datalist;
use function Gallery\Views\view_render_weighted_tag_suggestions_attribute;

/**
 * Handles visibility options logic for the gallery application.
 *
 * @param mixed $selected Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function visibility_options(string $selected): string
{
    $selected = normalize_gallery_visibility($selected);
    $rows = [];
    foreach (gallery_visibility_values() as $visibility) {
        $rows[] = [
            'value' => $visibility,
            'label' => gallery_visibility_label($visibility),
            'selected' => $visibility === $selected,
        ];
    }
    return view_render_admin_select_options($rows);
}

/**
 * Handles image visibility options logic for the gallery application.
 *
 * @param mixed $selected Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function image_visibility_options(string $selected): string
{
    $rows = [];
    foreach (['draft', 'public', 'private'] as $visibility) {
        $rows[] = [
            'value' => $visibility,
            'label' => $visibility,
            'selected' => $visibility === $selected,
        ];
    }
    return view_render_admin_select_options($rows);
}

/**
 * Handles render tag datalist logic for the gallery application.
 */
function render_tag_datalist(): void
{
    view_render_tag_datalist(array_map('strval', all_tag_names()));
}

/**
 * Return an escaped JSON attribute containing context-aware tag advice.
 *
 * @param int $galleryId Gallery identifier.
 * @return string Text result for the caller.
 */
function admin_weighted_tag_suggestions_attribute(int $galleryId): string
{
    return view_render_weighted_tag_suggestions_attribute(weighted_tag_suggestions_for_gallery($galleryId));
}

/**
 * Handles gallery parent options logic for the gallery application.
 *
 * @param mixed $currentGallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_parent_options(array $currentGallery): string
{
    $rows = [];
    $currentPath = rtrim((string) $currentGallery['folder_path'], '/');
    foreach (gallery_picker_source_rows() as $gallery) {
        if ((int) $gallery['id'] === (int) $currentGallery['id']) {
            continue;
        }
        $path = (string) $gallery['folder_path'];
        if ($path !== '' && str_starts_with($path . '/', $currentPath . '/')) {
            continue;
        }
        $rows[] = [
            'value' => (int) $gallery['id'],
            'label' => (string) $gallery['title'] . ' (' . $path . ')',
            'selected' => (int) ($currentGallery['parent_id'] ?? 0) === (int) $gallery['id'],
        ];
    }
    return view_render_admin_select_options($rows);
}

/**
 * Handles gallery parent options for new logic for the gallery application.
 *
 * @param int $selectedGalleryId Selected gallery id identifier.
 * @return mixed Result produced by this operation.
 */
function gallery_parent_options_for_new(int $selectedGalleryId = 0): string
{
    $rows = [];
    foreach (gallery_picker_source_rows() as $gallery) {
        $rows[] = [
            'value' => (int) $gallery['id'],
            'label' => (string) $gallery['title'] . ' (' . (string) $gallery['folder_path'] . ')',
            'selected' => (int) $gallery['id'] === $selectedGalleryId,
        ];
    }
    return view_render_admin_select_options($rows);
}


/**
 * Render a shared searchable gallery destination picker.
 *
 * The control submits through a hidden input so existing controllers continue to
 * receive the same field names. The visible text input and option list are
 * enhanced by `searchable-gallery-picker.js` with a 200 ms search debounce.
 *
 * @param string $fieldName Submitted hidden input name. Use an empty string for JSON-only widgets.
 * @param int $selectedGalleryId Initial committed gallery ID, usually zero for safe bulk actions.
 * @param int $excludedGalleryId Gallery that must not be selected as a destination.
 * @param array $options Optional behavior flags.
 * @return string Complete HTML for the picker.
 */
function render_gallery_search_picker(string $fieldName, int $selectedGalleryId = 0, int $excludedGalleryId = 0, array $options = []): string
{
    $pickerId = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($options['id'] ?? ('gallery-picker-' . $fieldName . '-' . uniqid('', false))));
    $rows = gallery_search_picker_rows($selectedGalleryId, $excludedGalleryId);
    $prefillGalleryId = (int) ($options['prefill_gallery_id'] ?? 0);
    $prefillEnabled = empty($options['disable_prefill']);
    $selectedRow = null;
    $prefillRow = null;
    foreach ($rows as $row) {
        if ((int) $row['id'] === $selectedGalleryId) {
            $selectedRow = $row;
        }
        if ($prefillGalleryId > 0 && (int) $row['id'] === $prefillGalleryId) {
            $prefillRow = $row;
        }
    }
    if ($prefillEnabled && $prefillRow === null && $selectedRow === null && $rows !== []) {
        $prefillRow = $rows[0];
    }
    $preparedRows = [];
    foreach ($rows as $row) {
        $prepared = $row;
        $prepared['path_label'] = (string) ($row['path'] ?? '') !== ''
            ? '/' . (string) $row['path']
            : t('gallery_picker.root_gallery', 'Root gallery');
        $preparedRows[] = $prepared;
    }
    return view_render_gallery_search_picker([
        'field_name' => $fieldName,
        'picker_id' => $pickerId,
        'list_id' => $pickerId . '-list',
        'hidden_value' => $selectedRow !== null ? (string) $selectedRow['id'] : '',
        'input_value' => $selectedRow !== null ? (string) $selectedRow['label'] : ($prefillRow !== null ? (string) $prefillRow['label'] : ''),
        'prefill_value' => $prefillRow !== null ? (string) $prefillRow['label'] : '',
        'placeholder' => (string) ($options['placeholder'] ?? t('gallery_picker.placeholder', 'Search gallery by name or path')),
        'hidden_attributes' => is_array($options['hidden_attributes'] ?? null) ? $options['hidden_attributes'] : [],
        'clear_label' => t('gallery_picker.clear', 'Clear selected gallery'),
        'empty_label' => t('gallery_picker.no_results', 'No matching galleries found.'),
        'help_label' => t('gallery_picker.help', 'Type to search, then press Enter or click a result to select it.'),
        'rows' => $preparedRows,
    ]);
}

/**
 * Handles gallery options for select logic for the gallery application.
 *
 * @param int $selectedGalleryId Selected gallery id identifier.
 * @param int $excludedGalleryId Excluded gallery id identifier.
 * @return mixed Result produced by this operation.
 */
function gallery_options_for_select(int $selectedGalleryId = 0, int $excludedGalleryId = 0): string
{
    $rows = [];
    foreach (gallery_picker_source_rows() as $gallery) {
        if ($excludedGalleryId > 0 && (int) $gallery['id'] === $excludedGalleryId) {
            continue;
        }
        $folderPath = trim((string) ($gallery['folder_path'] ?? ''), '/');
        $depth = $folderPath === '' ? 0 : max(0, substr_count($folderPath, '/'));
        $indent = str_repeat(' ', $depth);
        $branch = $depth > 0 ? '↳ ' : '';
        $pathSuffix = $folderPath !== '' ? '  ·  /' . $folderPath : '';
        $rows[] = [
            'value' => (int) $gallery['id'],
            'label' => $indent . $branch . (string) $gallery['title'] . $pathSuffix,
            'selected' => (int) $gallery['id'] === $selectedGalleryId,
        ];
    }
    return view_render_admin_select_options($rows);
}

/**
 * Read a gallery ID from the query string and only return it when the gallery exists.
 *
 * Contextual admin shortcuts pass gallery IDs through GET parameters. Validating the
 * identifier here keeps form pre-selection defensive and prevents stale or manually
 * edited URLs from selecting a non-existent gallery row.
 *
 * @param string $parameterName Parameter name value.
 * @return int Integer result for the caller.
 */
function selected_gallery_id_from_query(string $parameterName): int
{
    // $galleryId stores the normalized numeric query parameter.
    $galleryId = (int) ($_GET[$parameterName] ?? 0);
    if ($galleryId <= 0) {
        return 0;
    }
    return find_gallery($galleryId) ? $galleryId : 0;
}

/**
 * Handles gallery cover options logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $selectedImageId Input used by this operation.
 * @param mixed $includeDescendants Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_cover_options(int $galleryId, int $selectedImageId, bool $includeDescendants = false): string
{
    $images = $includeDescendants
        ? gallery_cover_choices($galleryId, false)
        : array_map(static fn (array $image): array => ['image' => $image], gallery_images($galleryId, false));
    $rows = [];
    foreach ($images as $entry) {
        $image = $entry['image'];
        $label = ($image['title'] ?: $image['filename']) . ' (' . $image['relative_path'] . ')';
        if ($includeDescendants && !empty($entry['gallery_title'])) {
            $label = $entry['gallery_title'] . ' - ' . $label;
        }
        $rows[] = [
            'value' => (int) $image['id'],
            'label' => $label,
            'selected' => $selectedImageId === (int) $image['id'],
        ];
    }
    return view_render_admin_select_options($rows);
}

/**
 * Handles unique slug for value logic for the gallery application.
 *
 * @param mixed $slug Input used by this operation.
 * @param mixed $excludeGalleryId Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function unique_slug_for_value(string $slug, int $excludeGalleryId): string
{
    return gallery_editor_unique_slug($slug, $excludeGalleryId);
}
