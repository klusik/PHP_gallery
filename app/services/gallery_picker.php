<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_picker.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides shared data helpers for gallery destination picker controls.
 *
 * Responsibilities:
 *   - Build one normalized gallery option shape for public and admin pickers
 *   - Keep destination suggestions outside controller-specific rendering code
 *   - Exclude the source gallery from destination choices defensively
 *   - Preserve the existing searchable picker HTML contract
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

/**
 * Return gallery rows formatted for the shared searchable gallery picker.
 *
 * The same data shape feeds public Picture manager destinations and admin
 * physical-move destinations, so both interfaces search and display galleries
 * consistently.
 *
 * @param int $selectedGalleryId Gallery that may be marked as committed initially.
 * @param int $excludedGalleryId Gallery that must not be selectable as a destination.
 * @return array<int array<string, mixed>> Searchable gallery option rows.
 */
function gallery_search_picker_rows(int $selectedGalleryId = 0, int $excludedGalleryId = 0): array
{
    // $rows stores normalized gallery choices for text-search widgets.
    $rows = [];
    // $galleries stores the canonical gallery list ordered by hierarchy path.
    $galleries = db()->query('SELECT id, title, folder_path FROM galleries ORDER BY folder_path')->fetchAll();
    foreach ($galleries as $gallery) {
        // $galleryId stores the numeric destination ID used by backend forms.
        $galleryId = (int) ($gallery['id'] ?? 0);
        if ($galleryId <= 0 || ($excludedGalleryId > 0 && $galleryId === $excludedGalleryId)) {
            continue;
        }
        // $folderPath stores the normalized public path used for hierarchy and search.
        $folderPath = trim((string) ($gallery['folder_path'] ?? ''), '/');
        // $depth stores the nesting depth used by both the old select and new picker.
        $depth = $folderPath === '' ? 0 : max(0, substr_count($folderPath, '/'));
        // $title stores the human gallery title shown before the path hint.
        $title = (string) ($gallery['title'] ?? '');
        // $pathSuffix stores a short filesystem-style hint for duplicate titles.
        $pathSuffix = $folderPath !== '' ? ' /' . $folderPath : '';
        // $label stores the committed input text and visible option title.
        $label = $title . $pathSuffix;
        // $searchText stores all searchable terms in one lowercase-friendly string.
        $searchText = trim($title . ' ' . $folderPath . ' ' . str_replace(['/', '-', '_'], ' ', $folderPath));
        $rows[] = [
            'id' => $galleryId,
            'title' => $title,
            'path' => $folderPath,
            'depth' => $depth,
            'label' => $label,
            'search' => $searchText,
            'selected' => $galleryId === $selectedGalleryId,
        ];
    }
    return $rows;
}

/**
 * Return the first direct child gallery that is a likely destination.
 *
 * Public file-manager workflows often move or copy photos from a parent album
 * into one of its subgalleries. Prefilling the first direct child gives users a
 * useful typeahead starting point without forcing them to use that target.
 *
 * @param int $sourceGalleryId Gallery currently being managed.
 * @return int Likely destination gallery ID, or zero when no child exists.
 */
function likely_gallery_destination_id(int $sourceGalleryId): int
{
    if ($sourceGalleryId <= 0) {
        return 0;
    }
    // $stmt stores the direct-child lookup ordered by the same fields as normal gallery listings.
    $stmt = db()->prepare('SELECT id FROM galleries WHERE parent_id = ? ORDER BY sort_order, title, id LIMIT 1');
    $stmt->execute([$sourceGalleryId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}
