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

namespace Gallery\Services;

use function Gallery\Models\gallery_model_likely_destination_id;
use function Gallery\Models\gallery_model_picker_rows;
use function Gallery\Models\gallery_model_title_completion_rows;

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
    $galleries = gallery_model_picker_rows();
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
    return gallery_model_likely_destination_id($sourceGalleryId);
}

/**
 * Return compact raw gallery rows for legacy select and editor controls.
 *
 * @param bool $secondaryTitleSort Whether title is used as a secondary sort key.
 * @return array<int,array<string,mixed>> Gallery rows.
 */
function gallery_picker_source_rows(bool $secondaryTitleSort = false): array
{
    return gallery_model_picker_rows($secondaryTitleSort);
}

/**
 * Return compact existing-gallery titles for inline completion while creating a gallery.
 *
 * Scan siblings before a disjoint fallback scope, newest first in each scope.
 * These optional suggestions are deliberately incomplete once a budget is hit.
 * No catalog is embedded in the form and no database collation approximates NFKC.
 *
 * @return array{ok:bool,candidates:array,normalization:string,truncated:bool}
 */
function gallery_title_completion_candidates(string $query = '', int $parentGalleryId = 0): array
{
    $result = gallery_title_completion_empty_result();
    if (strlen($query) > 1024 || preg_match('//u', $query) !== 1 || $parentGalleryId < 0) {
        throw new \InvalidArgumentException('Invalid title completion query.');
    }
    $characters = preg_match_all('/./us', $query);
    if ($characters > 255) {
        throw new \InvalidArgumentException('Invalid title completion query.');
    }
    if ($characters < 2 || preg_match('/\A\s*\z/u', $query) === 1) {
        return $result;
    }
    $prefix = gallery_title_completion_normalize($query, $result['normalization']);
    if ($prefix === null || $prefix === '') {
        return $result;
    }

    $remaining = GALLERY_TITLE_COMPLETION_SCAN_BUDGET;
    try {
        foreach ([true, false] as $siblings) {
            $scopeRemaining = $siblings ? min(GALLERY_TITLE_COMPLETION_SIBLING_BUDGET, $remaining) : $remaining;
            $before = null;
            do {
                $pageSize = min(GALLERY_TITLE_COMPLETION_PAGE_SIZE, $scopeRemaining);
                $rows = gallery_model_title_completion_rows($parentGalleryId, $siblings, $pageSize + 1, $before);
                $hasMore = count($rows) > $pageSize;
                if ($hasMore) {
                    array_pop($rows);
                }
                foreach ($rows as $gallery) {
                    $title = trim((string) ($gallery['title'] ?? ''));
                    $id = (int) ($gallery['id'] ?? 0);
                    $createdAt = (string) ($gallery['created_at'] ?? '');
                    if ($id <= 0 || strlen($title) > 1024 || preg_match('/\A.{1,255}\z/us', $title) !== 1
                        || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $createdAt) !== 1) {
                        continue;
                    }
                    $normalized = gallery_title_completion_normalize($title, $result['normalization']);
                    if ($normalized === null || $normalized === $prefix || !str_starts_with($normalized, $prefix)) {
                        continue;
                    }
                    $result['candidates'][] = [
                        'id' => $id,
                        'parent_id' => max(0, (int) ($gallery['parent_id'] ?? 0)),
                        'title' => $title,
                        'created_at' => $createdAt,
                    ];
                    if (count($result['candidates']) >= GALLERY_TITLE_COMPLETION_MAX_CANDIDATES) {
                        // Do not issue another sorted query merely to prove exhaustiveness.
                        $result['truncated'] = true;
                        return $result;
                    }
                }
                $remaining -= count($rows);
                $scopeRemaining -= count($rows);
                if (!$hasMore) {
                    break;
                }
                if ($scopeRemaining <= 0) {
                    $result['truncated'] = true;
                    if ($siblings) {
                        // Unexamined siblings may outrank every fallback candidate.
                        return $result;
                    }
                    break;
                }
                $last = $rows[count($rows) - 1];
                $before = ['id' => (int) $last['id'], 'created_at' => (string) $last['created_at']];
            } while ($scopeRemaining > 0);
        }
    } catch (\Throwable) {
        // Suggestion failure must neither break creation nor reveal database details.
        return gallery_title_completion_empty_result(false, true);
    }
    return $result;
}

// Fixed deployment budgets, never supplied by a browser request. At most three
// 513-row SQL pages (including lookahead), with at most 1024 titles normalized.
const GALLERY_TITLE_COMPLETION_MAX_CANDIDATES = 8;
const GALLERY_TITLE_COMPLETION_SCAN_BUDGET = 1024;
const GALLERY_TITLE_COMPLETION_SIBLING_BUDGET = 512;
const GALLERY_TITLE_COMPLETION_PAGE_SIZE = 512;

/** Return the explicit matching capability and an empty, bounded response. */
function gallery_title_completion_empty_result(bool $ok = true, bool $truncated = false): array
{
    return [
        'ok' => $ok,
        'candidates' => [],
        'normalization' => class_exists(\Normalizer::class) && function_exists('mb_strtolower')
            ? 'nfkc-lowercase'
            : 'ascii-lowercase',
        'truncated' => $truncated,
    ];
}

/**
 * Match using NFKC followed by locale-independent Unicode lowercase, like the
 * browser's normalize('NFKC').toLowerCase(). Without both extensions, only ASCII
 * queries and titles are eligible; never claim Unicode-equivalent matching.
 */
function gallery_title_completion_normalize(string $text, string $normalization): ?string
{
    if ($normalization === 'nfkc-lowercase') {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        return is_string($normalized) ? mb_strtolower($normalized, 'UTF-8') : null;
    }
    if ($normalization !== 'ascii-lowercase' || preg_match('/[^\x00-\x7F]/', $text) === 1) {
        return null;
    }
    return strtr($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
}
