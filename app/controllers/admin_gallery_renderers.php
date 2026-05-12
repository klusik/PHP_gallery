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
 *   2026-05-12
 */

declare(strict_types=1);

/**
 * Handles visibility options logic for the gallery application.
 * @param mixed $selected Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function visibility_options(string $selected): string
{
    // Variable $html stores this steps working value.
    $html = '';
    // $selected stores the canonical value shown by the simplified visibility UI.
    $selected = normalize_gallery_visibility($selected);
    foreach (gallery_visibility_values() as $visibility) {
        $html .= '<option value="' . e($visibility) . '"' . ($visibility === $selected ? ' selected' : '') . '>' . e(gallery_visibility_label($visibility)) . '</option>';
    }
    return $html;
}

/**
 * Handles image visibility options logic for the gallery application.
 * @param mixed $selected Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function image_visibility_options(string $selected): string
{
    // Variable $html stores this steps working value.
    $html = '';
    foreach (['draft', 'public', 'private'] as $visibility) {
        $html .= '<option value="' . e($visibility) . '"' . ($visibility === $selected ? ' selected' : '') . '>' . e($visibility) . '</option>';
    }
    return $html;
}

/**
 * Handles render tag datalist logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function render_tag_datalist(): void
{
    echo '<datalist id="tag-suggestions">';
    foreach (all_tag_names() as $name) {
        echo '<option value="' . e((string) $name) . '"></option>';
    }
    echo '</datalist>';
}

/**
 * Handles gallery parent options logic for the gallery application.
 * @param mixed $currentGallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_parent_options(array $currentGallery): string
{
    // Variable $galleries stores this steps working value.
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    // Variable $html stores this steps working value.
    $html = '';
    // Variable $currentPath stores this steps working value.
    $currentPath = rtrim((string) $currentGallery['folder_path'], '/');
    foreach ($galleries as $gallery) {
        if ((int) $gallery['id'] === (int) $currentGallery['id']) {
            continue;
        }
        // Variable $path stores this steps working value.
        $path = (string) $gallery['folder_path'];
        if ($path !== '' && str_starts_with($path . '/', $currentPath . '/')) {
            continue;
        }
        // Variable $selected stores this steps working value.
        $selected = (int) ($currentGallery['parent_id'] ?? 0) === (int) $gallery['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $gallery['id'] . '"' . $selected . '>' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

/**
 * Handles gallery parent options for new logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function gallery_parent_options_for_new(int $selectedGalleryId = 0): string
{
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '';
    // $galleries stores an intermediate value used by the surrounding gallery workflow.
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        // $selected stores the HTML selected marker for contextual admin links opened from a gallery page.
        $selected = (int) $gallery['id'] === $selectedGalleryId ? ' selected' : '';
        $html .= '<option value="' . (int) $gallery['id'] . '"' . $selected . '>' . e($gallery['title'] . ' (' . $gallery['folder_path'] . ')') . '</option>';
    }
    return $html;
}

/**
 * Handles gallery options for select logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function gallery_options_for_select(int $selectedGalleryId = 0, int $excludedGalleryId = 0): string
{
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '';
    // $galleries stores an intermediate value used by the surrounding gallery workflow.
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        if ($excludedGalleryId > 0 && (int) $gallery['id'] === $excludedGalleryId) {
            continue;
        }
        // $selected stores the HTML selected marker for contextual upload links opened from a gallery page.
        $selected = (int) $gallery['id'] === $selectedGalleryId ? ' selected' : '';
        // $folderPath stores the normalized public folder path used for hierarchy depth.
        $folderPath = trim((string) ($gallery['folder_path'] ?? ''), '/');
        // $depth stores how deeply nested the gallery is in the hierarchy.
        $depth = $folderPath === '' ? 0 : max(0, substr_count($folderPath, '/'));
        // $indent stores visible indentation that survives native select rendering better than CSS padding on options.
        $indent = str_repeat(' ', $depth);
        // $branch stores a compact hierarchy marker for nested galleries.
        $branch = $depth > 0 ? '↳ ' : '';
        // $pathSuffix stores the filesystem-style path hint without making the title hard to scan.
        $pathSuffix = $folderPath !== '' ? '  ·  /' . $folderPath : '';
        // $label stores the formatted select option label.
        $label = $indent . $branch . (string) $gallery['title'] . $pathSuffix;
        $html .= '<option value="' . (int) $gallery['id'] . '"' . $selected . '>' . e($label) . '</option>';
    }
    return $html;
}

/**
 * Read a gallery ID from the query string and only return it when the gallery exists.
 *
 * Contextual admin shortcuts pass gallery IDs through GET parameters. Validating the
 * identifier here keeps form pre-selection defensive and prevents stale or manually
 * edited URLs from selecting a non-existent gallery row.
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
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $selectedImageId Input used by this operation.
 * @param mixed $includeDescendants Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_cover_options(int $galleryId, int $selectedImageId, bool $includeDescendants = false): string
{
    // $images stores an intermediate value used by the surrounding gallery workflow.
    $images = $includeDescendants ? gallery_cover_choices($galleryId, false) : array_map(static fn (array $image): array => ['image' => $image], gallery_images($galleryId, false));
    // Variable $html stores this steps working value.
    $html = '';
    foreach ($images as $entry) {
        // $image stores an intermediate value used by the surrounding gallery workflow.
        $image = $entry['image'];
        // Variable $selected stores this steps working value.
        $selected = $selectedImageId === (int) $image['id'] ? ' selected' : '';
        // Variable $label stores this steps working value.
        $label = ($image['title'] ?: $image['filename']) . ' (' . $image['relative_path'] . ')';
        if ($includeDescendants && !empty($entry['gallery_title'])) {
            // $label stores an intermediate value used by the surrounding gallery workflow.
            $label = $entry['gallery_title'] . ' - ' . $label;
        }
        $html .= '<option value="' . (int) $image['id'] . '"' . $selected . '>' . e($label) . '</option>';
    }
    return $html;
}

/**
 * Handles unique slug for value logic for the gallery application.
 * @param mixed $slug Input used by this operation.
 * @param mixed $excludeGalleryId Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function unique_slug_for_value(string $slug, int $excludeGalleryId): string
{
    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $base stores this steps working value.
    $base = slugify($slug);
    // Variable $candidate stores this steps working value.
    $candidate = $base;
    // Variable $counter stores this steps working value.
    $counter = 2;
    while (true) {
        // Variable $stmt stores this steps working value.
        $stmt = $pdo->prepare('SELECT id FROM galleries WHERE slug = ? AND id <> ?');
        $stmt->execute([$candidate, $excludeGalleryId]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
        // Variable $candidate stores this steps working value.
        $candidate = $base . '-' . $counter;
        $counter++;
    }
}
