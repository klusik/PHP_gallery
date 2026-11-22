<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/media_renamer/preview.php
 * Module Type: Service Part
 * Purpose: Analyze indexed rename proposals without physical file inspection.
 * Responsibilities:
 *   - Reuse the canonical naming/collision policy over lightweight database rows.
 *   - Retain only summaries or one explicitly requested detail/candidate page.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Models\media_renamer_model_preview_rows;

/**
 * Bound names rendered/downloaded after an explicit gallery expansion.
 * Type: int. Units: detail rows per page. Scope: Admin rename previews/reports.
 * Consumers: lazy detail controllers. Rationale: 50 keeps table/JSON work small
 * independently of the number of files in a selected gallery.
 */
const MEDIA_RENAMER_DETAIL_PAGE_SIZE = 50;

/**
 * Bound ID/sequence-position pages fetched when physical apply starts.
 * Type: int. Units: image identifiers per response. Scope: apply preparation.
 * Consumers: candidate controller and database preview. Rationale: 500 avoids
 * a full hidden-input list while amortizing metadata requests for large albums.
 */
const MEDIA_RENAMER_CANDIDATE_PAGE_SIZE = 500;

/**
 * Bound candidate retention before planner output can grow without limit.
 * Type: int. Units: candidate rows. Scope: one plan. Consumers: snapshot collector.
 * Rationale: 20000 covers large galleries with no more than 40 chunk references.
 */
const MEDIA_RENAMER_CANDIDATE_PLAN_MAX_CANDIDATES = 20000;

/**
 * Analyze one gallery using indexed facts and retain only the requested page.
 *
 * No path containment/stat/hash/derivative scan belongs here. The physical
 * executor still rebuilds its complete safety plan immediately before moving
 * files, including conflicts with unindexed files and current derivatives.
 *
 * @param int $galleryId Physical gallery whose direct files are analyzed.
 * @param string $pattern Requested deterministic naming pattern.
 * @param int $offset Exclusive-page displacement among visible proposals/candidates.
 * @param int $limit Maximum retained rows; zero requests only the summary.
 * @param bool $candidatesOnly Return executable IDs and original sequence positions for apply preparation.
 * @param bool $collectCandidates Retain the complete bounded candidate snapshot
 *     for confirmation. Requires candidate mode with no requested page slice.
 * @return array{gallery:array{id:int|string,title?:string,folder_path:string,...},items:list<array{gallery_id:int,image_id:int,old_relative_path:string,new_relative_path:string,status:string,warnings:list<string>,can_rename:bool}>,summary:array{total:int,rename:int,already_matches:int,missing:int,collision:int,skipped:int,warnings:int},context_base:string,pattern:string,selected_image_ids:list<int>,preview_only:bool,candidate_image_ids:list<int>,candidate_sequences:list<int>,next_offset:?int} Summary, requested page or complete candidate snapshot.
 * @throws RuntimeException When the gallery, requested page or snapshot size is invalid.
 */
function media_renamer_database_preview(
    int $galleryId, string $pattern = '', int $offset = 0, int $limit = 0,
    bool $candidatesOnly = false, bool $collectCandidates = false,
): array {
    if ($offset < 0 || $limit < 0 || $limit > MEDIA_RENAMER_CANDIDATE_PAGE_SIZE
        || ($collectCandidates && (!$candidatesOnly || $offset !== 0 || $limit !== 0))) {
        throw new RuntimeException('Invalid rename preview page.');
    }
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        throw new RuntimeException(t('admin.media_renamer.error_gallery_missing',
            'Gallery was not found.'));
    }
    $rows = media_renamer_model_preview_rows($galleryId);
    $indexed = [];
    $selected = [];
    foreach ($rows as $row) {
        $path = normalize_relative_path($row['relative_path']);
        $indexed[media_renamer_path_key($path)] = (int) $row['id'];
        // Match image_model_rows_for_gallery's direct-row SQL selection.
        if (!str_contains($row['relative_path'], '/')) {
            $selected[(int) $row['id']] = true;
        }
    }
    $pattern = media_renamer_normalize_pattern($pattern);
    $context = media_renamer_gallery_context_base($gallery);
    $used = [];
    $summary = ['total' => 0, 'rename' => 0, 'already_matches' => 0,
        'missing' => 0, 'collision' => 0, 'skipped' => 0, 'warnings' => 0];
    $items = [];
    $candidateIds = [];
    $candidateSequences = [];
    $visible = 0;
    $sequence = 0;
    foreach ($rows as $image) {
        if (!isset($selected[(int) $image['id']])) {
            continue;
        }
        $sequence++;
        $summary['total']++;
        $old = normalize_relative_path($image['relative_path']);
        $warnings = [];
        $new = '';
        $status = 'skipped';
        if ($old === '' || str_contains($old, '/')) {
            $warnings[] = t('admin.media_renamer.warning_nested_path',
                'Only direct image files in the gallery folder are renamed.');
        } else {
            $target = media_renamer_unique_target_from_database(
                $gallery, $image, $context, $pattern, $sequence,
                media_renamer_safe_extension($image['filename']),
                $selected, $indexed, $used, $warnings,
            );
            $new = $target['relative_path'] ?? '';
            $status = $target === null ? 'collision'
                : ($old === $new ? 'already_matches' : 'rename');
        }
        $summary[$status]++;
        $summary['warnings'] += count($warnings);
        $canRename = $status === 'rename';
        if ($collectCandidates && $canRename
            && count($candidateIds) >= MEDIA_RENAMER_CANDIDATE_PLAN_MAX_CANDIDATES) {
            throw new RuntimeException('Candidate plan capacity is exhausted.');
        }
        if (($candidatesOnly && !$canRename)
            || (!$candidatesOnly && $status === 'already_matches' && $warnings === [])) {
            continue;
        }
        if ($collectCandidates || ($limit > 0 && $visible >= $offset
            && $visible < $offset + $limit)) {
            if ($candidatesOnly) {
                $candidateIds[] = (int) $image['id'];
                $candidateSequences[] = $sequence;
            } else {
                $items[] = ['gallery_id' => $galleryId, 'image_id' => (int) $image['id'],
                    'old_relative_path' => $old, 'new_relative_path' => $new,
                    'status' => $status, 'warnings' => $warnings, 'can_rename' => $canRename];
            }
        }
        $visible++;
    }
    return ['gallery' => $gallery, 'items' => $items, 'summary' => $summary,
        'context_base' => $context, 'pattern' => $pattern, 'selected_image_ids' => [],
        'preview_only' => true, 'candidate_image_ids' => $candidateIds,
        'candidate_sequences' => $candidateSequences,
        'next_offset' => $limit > 0 && $visible > $offset + $limit ? $offset + $limit : null];
}
