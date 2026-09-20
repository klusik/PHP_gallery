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
use function Gallery\Models\gallery_picker_model_search;
use function Gallery\Models\gallery_picker_model_selection;
use const Gallery\Core\GALLERY_PICKER_SEARCH_PAGE_SIZE;
use const Gallery\Core\GALLERY_PICKER_QUERY_MAX_BYTES;
use const Gallery\Core\GALLERY_PICKER_TITLE_MAX_BYTES;
use const Gallery\Core\GALLERY_PICKER_PATH_MAX_BYTES;
use const Gallery\Core\GALLERY_PICKER_TITLE_MAX_CHARACTERS;
use const Gallery\Core\GALLERY_PICKER_PATH_MAX_CHARACTERS;
use const Gallery\Core\GALLERY_TITLE_COMPLETION_MAX_CANDIDATES;
use const Gallery\Core\GALLERY_TITLE_COMPLETION_SCAN_BUDGET;
use const Gallery\Core\GALLERY_TITLE_COMPLETION_SIBLING_BUDGET;
use const Gallery\Core\GALLERY_TITLE_COMPLETION_PAGE_SIZE;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * Return gallery rows formatted for the shared searchable gallery picker.
 *
 * The same data shape feeds public Picture manager destinations and admin
 * physical-move destinations, so both interfaces search and display galleries
 * consistently.
 *
 * @param int $selectedGalleryId Gallery that may be marked as committed initially.
 * @param int $excludedGalleryId Gallery that must not be selectable as a destination.
 * @return array<int,array<string,mixed>> At most thirty options with selection pinned.
 */
function gallery_search_picker_rows(int $selectedGalleryId = 0, int $excludedGalleryId = 0): array
{
    $page = gallery_picker_search_page('', 0, $selectedGalleryId, $excludedGalleryId);
    $rows = $page['rows'];
    if ($page['selected'] !== null && !in_array($selectedGalleryId, array_column($rows, 'id'), true)) {
        // Compatibility callers receive a bounded page with the selection pinned.
        array_pop($rows);
        array_unshift($rows, $page['selected']);
    }
    return $rows;
}

/**
 * Normalize one compact gallery into the destination presentation contract.
 *
 * @param array<string,mixed> $gallery Compact model row.
 * @param int $selectedGalleryId Explicitly committed gallery identifier.
 * @return array<string,mixed> Safe bounded label with full relative ancestry.
 */
function gallery_picker_option(array $gallery, int $selectedGalleryId = 0): array
{
    $id = (int) ($gallery['id'] ?? 0);
    $title = (string) ($gallery['title'] ?? '');
    $path = trim((string) ($gallery['folder_path'] ?? ''), '/');
    if ($id <= 0 || strlen($title) > GALLERY_PICKER_TITLE_MAX_BYTES
        || strlen($path) > GALLERY_PICKER_PATH_MAX_BYTES
        || preg_match('//u', $title . $path) !== 1
        || preg_match_all('/./us', $title) > GALLERY_PICKER_TITLE_MAX_CHARACTERS
        || preg_match_all('/./us', $path) > GALLERY_PICKER_PATH_MAX_CHARACTERS) {
        throw new \UnexpectedValueException('Invalid destination data.');
    }
    return [
        'id' => $id,
        'title' => $title,
        'path' => $path,
        'depth' => substr_count($path, '/'),
        'label' => $title . ($path !== '' ? ' /' . $path : '') . ' (#' . $id . ')',
        'selected' => $id === $selectedGalleryId,
    ];
}

/**
 * Resolve an optional photo-destination hint without committing a form value.
 *
 * Parent-move controls deliberately disable hints; their branch policy and
 * committed selection remain owned by gallery_picker_search_page().
 *
 * @param int $galleryId Suggested child gallery identifier.
 * @param int $excludedGalleryId Source gallery that cannot receive its own photos.
 * @return ?array<string,mixed> One bounded label, or null for an invalid hint.
 */
function gallery_picker_destination_hint(int $galleryId, int $excludedGalleryId = 0): ?array
{
    if ($galleryId <= 0 || $galleryId === $excludedGalleryId) {
        return null;
    }
    $gallery = gallery_picker_model_selection($galleryId);
    return $gallery === null ? null : gallery_picker_option($gallery);
}

/**
 * Return an empty response with the same shape on every endpoint status.
 *
 * @param bool $ok Whether the destination search succeeded.
 * @return array<string,mixed> Empty bounded search envelope.
 */
function gallery_picker_empty_page(bool $ok = false): array
{
    return ['ok' => $ok, 'rows' => [], 'selected' => null, 'next_after_id' => null,
        'more' => false, 'page_size' => GALLERY_PICKER_SEARCH_PAGE_SIZE];
}

/**
 * Search physical destinations with independent committed-selection context.
 *
 * This is discovery only: existing move/create services still validate arbitrary
 * submitted IDs, physical self/descendant relationships and Smart Gallery cycles
 * immediately before mutation. No graph is loaded for each search candidate.
 *
 * @param string $query Literal title/path substring, blank to browse by ID.
 * @param int $afterId Exclusive keyset cursor, zero for the first page.
 * @param int $selectedGalleryId Existing committed selection, zero for none/root.
 * @param int $excludedGalleryId Source gallery omitted from results and selection.
 * @param bool $excludeDescendants Omit its physical branch for parent moves only.
 * @return array<string,mixed> At most thirty results and one selected context row.
 */
function gallery_picker_search_page(string $query = '', int $afterId = 0, int $selectedGalleryId = 0, int $excludedGalleryId = 0, bool $excludeDescendants = false): array
{
    if (strlen($query) > GALLERY_PICKER_QUERY_MAX_BYTES || preg_match('//u', $query) !== 1
        || preg_match_all('/./us', $query) > GALLERY_PICKER_TITLE_MAX_CHARACTERS
        || min($afterId, $selectedGalleryId, $excludedGalleryId) < 0) {
        throw new \InvalidArgumentException('Invalid destination search.');
    }
    $excludedPath = null;
    if ($excludeDescendants && $excludedGalleryId > 0) {
        $source = gallery_picker_model_selection($excludedGalleryId);
        if ($source === null) {
            throw new \InvalidArgumentException('Invalid source gallery.');
        }
        $excludedPath = gallery_picker_option($source)['path'];
        if ($excludedPath === '') {
            throw new \InvalidArgumentException('Invalid source gallery.');
        }
    }
    $result = gallery_picker_empty_page(true);
    if ($selectedGalleryId > 0 && $selectedGalleryId !== $excludedGalleryId) {
        $selection = gallery_picker_model_selection($selectedGalleryId);
        if ($selection !== null) {
            $option = gallery_picker_option($selection, $selectedGalleryId);
            if ($excludedPath === null || ($option['path'] !== $excludedPath
                && !str_starts_with($option['path'] . '/', $excludedPath . '/'))) {
                $result['selected'] = $option;
            }
        }
    }
    foreach (gallery_picker_model_search(trim($query), $afterId, $excludedGalleryId, $excludedPath) as $gallery) {
        $result['rows'][] = gallery_picker_option($gallery, $selectedGalleryId);
    }
    // A full page permits continuation; the final continuation can be empty.
    $result['more'] = count($result['rows']) === GALLERY_PICKER_SEARCH_PAGE_SIZE;
    if ($result['more']) {
        $result['next_after_id'] = $result['rows'][count($result['rows']) - 1]['id'];
    }
    return $result;
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
 * @param string $query Literal entered prefix, validated before any title query.
 * @param int $parentGalleryId Nonnegative committed parent ID; zero groups root siblings.
 * @return array{ok:bool,candidates:list<array{id:int,parent_id:int,title:string,created_at:string}>,normalization:string,truncated:bool} Optional bounded suggestions and explicit incompleteness.
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
