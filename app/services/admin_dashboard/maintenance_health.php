<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/admin_dashboard/maintenance_health.php
 * Module Type: Service Part
 * Purpose: Prepare bounded gallery-protection and read-only image-move health.
 * Responsibilities:
 *   - Reuse domain verification and the bounded pending-journal reader.
 *   - Keep maintenance discovery lazy and share localized presentation data.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use const Gallery\Core\IMAGE_MOVE_DIAGNOSTIC_LIMIT;
use const Gallery\Core\IMAGE_MOVE_OPERATION_RANDOM_BYTES;
use const Gallery\Core\IMAGE_MOVE_PREPARED;
use const Gallery\Core\IMAGE_MOVE_MOVING;
use const Gallery\Core\IMAGE_MOVE_DB_COMMITTED;
use const Gallery\Core\IMAGE_MOVE_NEEDS_RECONCILIATION;

/**
 * Adapt the exact trigger verifier to the established three-state health model.
 *
 * @return array{state:string,requirements:list<array{state:string,table:string,object:string,object_type:string}>}
 *   Only a fixed column identity is exposed, including when trigger enforcement fails.
 */
function admin_gallery_edit_schema_status(): array
{
    $state = gallery_edit_schema_state();
    $state = in_array($state, ['available', 'missing', 'unknown'], true) ? $state : 'unknown';
    return ['state' => $state, 'requirements' => [[
        'state' => $state, 'table' => 'galleries', 'object' => 'edit_revision', 'object_type' => 'column',
    ]]];
}

/**
 * Discover pending image moves only for an explicitly requested Admin maintenance view.
 *
 * This is a database-only snapshot, not recovery, a filesystem scan or a claim
 * that pending work has failed. Reuse the already resolved journal schema health
 * so confirmed missing/unknown storage never causes a journal-row query.
 *
 * @param array{state?:string,request_id?:string} $schemaHealth Prepared shared journal schema health.
 * @param bool $inspect Explicit maintenance/Diagnostics intent; false performs no discovery.
 * @return array{}|array{state:string,action_required:bool,request_id:string,entries:list<string>,labels:array{title:string,summary:string,guidance:string,action:string},report_lines:list<string>}
 *   Localized bounded lines containing only validated IDs and safe lifecycle/error categories.
 */
function admin_image_move_pending_health_status(array $schemaHealth, bool $inspect = false): array
{
    if (!$inspect) {
        return [];
    }
    $state = ($schemaHealth['state'] ?? '') === 'missing' ? 'missing' : 'unknown';
    $entries = [];
    if (($schemaHealth['state'] ?? '') === 'available') {
        try {
            $rows = array_slice(gallery_image_move_pending(), 0, IMAGE_MOVE_DIAGNOSTIC_LIMIT);
            $state = $rows === [] ? 'clear' : 'pending';
            foreach ($rows as $row) {
                $operationId = (string) ($row['operation_id'] ?? '');
                if (preg_match('/^[a-f0-9]{' . (IMAGE_MOVE_OPERATION_RANDOM_BYTES * 2) . '}$/D', $operationId) !== 1) {
                    $state = 'unknown';
                    continue;
                }
                $operationState = in_array($row['state'] ?? '', [IMAGE_MOVE_PREPARED, IMAGE_MOVE_MOVING, IMAGE_MOVE_DB_COMMITTED, IMAGE_MOVE_NEEDS_RECONCILIATION], true)
                    ? $row['state'] : 'unknown';
                // Do not reflect arbitrary stored text, even if it resembles an error code.
                $error = empty($row['last_error_code']) ? 'none'
                    : ($row['last_error_code'] === 'identity_or_storage_unverified' ? 'identity_or_storage_unverified' : 'unclassified');
                $entries[] = t('admin.health_image_moves.entry', 'Operation {operation_id}: gallery {source_id} → {destination_id}; state {state}; error {error}.', [
                    'operation_id' => $operationId,
                    'source_id' => max(0, (int) ($row['source_gallery_id'] ?? 0)),
                    'destination_id' => max(0, (int) ($row['destination_gallery_id'] ?? 0)),
                    'state' => $operationState, 'error' => $error,
                ]);
            }
        } catch (Throwable) {
            $state = 'unknown';
            $entries = [];
        }
    }
    $summaries = [
        'clear' => t('admin.health_image_moves.clear', 'No pending image moves were found in this read-only snapshot.'),
        'pending' => t('admin.health_image_moves.pending', 'Pending image moves: showing {count}, at most {limit}. Some operations may still be running; this is not a total backlog count.', ['count' => count($entries), 'limit' => IMAGE_MOVE_DIAGNOSTIC_LIMIT]),
        'missing' => t('admin.health_image_moves.missing', 'Required image-move storage is missing. Apply pending migrations before inspecting or recovering journal operations.'),
        'unknown' => t('admin.health_image_moves.unknown', 'Pending image moves could not be verified. Check database connectivity and metadata permissions; no raw errors or private paths are shown.'),
    ];
    $labels = [
        'title' => t('admin.health_image_moves.title', 'Pending image moves'),
        'summary' => $summaries[$state],
        'guidance' => t('admin.health_image_moves.guidance', 'Read-only discovery never moves files or runs recovery. After active work finishes, follow docs/IMAGE_MOVE_RECOVERY.md and use scripts/reconcile_image_moves.php --list before explicitly recovering an operation.'),
        'action' => t('admin.dashboard.badge_action', 'Action'),
    ];
    $requestId = $state === 'unknown' ? (string) ($schemaHealth['request_id'] ?? '') : '';
    if ($state === 'unknown' && $requestId === '' && function_exists('Gallery\Services\telemetry_request_id')) {
        $requestId = telemetry_request_id();
    }
    $requestId = preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $requestId) === 1 ? $requestId : '';
    $reportLines = [$labels['title'], $labels['summary'], $labels['guidance'], ...$entries];
    if ($requestId !== '') {
        $reportLines[] = t('public.request_reference', 'Reference: {request_id}', ['request_id' => $requestId]);
    }
    return ['state' => $state, 'action_required' => $state !== 'clear', 'request_id' => $requestId,
        'entries' => $entries, 'labels' => $labels, 'report_lines' => $reportLines];
}
