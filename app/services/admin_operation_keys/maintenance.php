<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/admin_operation_keys/maintenance.php
 * Module Type: Service Part
 * Purpose: Expose bounded retained-operation inspection and explicit reconciliation.
 * Responsibilities:
 *   - Keep operator workflows separate from request replay and forbid expiry/reexecution.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Loaded only by the admin_operation_keys service entry point.
 */
declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use const Gallery\Core\ADMIN_OPERATION_PENDING_PAGE_SIZE;
use const Gallery\Core\ADMIN_OPERATION_INPUT_MAX_BYTES;
use function Gallery\Models\admin_operation_model_find;
use function Gallery\Models\admin_operation_model_lock;
use function Gallery\Models\admin_operation_model_pending_counts;
use function Gallery\Models\admin_operation_model_pending_page;

/**
 * Validate an exact retained-claim identity for trusted maintenance callers.
 *
 * @param int $actorId Original administrator identity.
 * @param string $keyHash SHA-256 operation-key digest, not an authorization token.
 * @return void
 */
function admin_operation_maintenance_identity(int $actorId, string $keyHash): void
{
    if ($actorId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $keyHash)) {
        throw new AdminOperationRefusal('operation_payload_invalid', 'The retained operation identity is invalid.');
    }
}

/**
 * Project only bounded non-credential metadata useful to an authorized operator.
 *
 * @param array<string,mixed> $row Retained ledger row; worker ownership/response data stay private.
 * @return array{actor_id:int,key_hash:string,payload_hash:string,operation:'gallery.create'|'gallery.create_upload'|'image.classic_upload'|'unknown',state:'pending'|'needs_reconciliation'|'completed'|'unknown',created_at:string,updated_at:string} Correlation hashes and bounded SQL timestamps, empty when invalid; never worker ownership or response contents.
 */
function admin_operation_maintenance_metadata(array $row): array
{
    admin_operation_maintenance_identity((int) ($row['actor_id'] ?? 0), (string) ($row['key_hash'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/D', (string) ($row['payload_hash'] ?? ''))) {
        throw new AdminOperationRefusal('operation_result_unavailable', 'The retained operation metadata requires investigation.');
    }
    $result = array_intersect_key($row, array_flip(['actor_id', 'key_hash', 'payload_hash']));
    $result['actor_id'] = (int) $result['actor_id'];
    $result['operation'] = in_array($row['operation_name'] ?? '', ['gallery.create', 'gallery.create_upload', 'image.classic_upload'], true) ? $row['operation_name'] : 'unknown';
    $result['state'] = in_array($row['state'] ?? '', ['pending', 'needs_reconciliation', 'completed'], true) ? $row['state'] : 'unknown';
    foreach (['created_at', 'updated_at'] as $field) {
        $value = (string) ($row[$field] ?? '');
        $result[$field] = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) ? $value : '';
    }
    return $result;
}

/**
 * Report pending counts and one bounded page; never expire, claim, or rerun work.
 *
 * @param string $cursor Exact actor:key-hash cursor returned by the prior report, or empty.
 * @return array{
 *   counts:array{pending?:int,needs_reconciliation?:int,unknown?:int},
 *   items:list<array{actor_id:int,key_hash:string,payload_hash:string,operation:'gallery.create'|'gallery.create_upload'|'image.classic_upload'|'unknown',state:'pending'|'needs_reconciliation'|'completed'|'unknown',created_at:string,updated_at:string}>,
 *   next_cursor:string|null
 * } Nonnegative observed counts and at most one page of metadata; null cursor means no later observed row.
 */
function admin_operation_pending_report(string $cursor = ''): array
{
    $afterActor = 0;
    $afterHash = '';
    if ($cursor !== '') {
        if (!preg_match('/^([1-9][0-9]{0,18}):([a-f0-9]{64})$/D', $cursor, $match)) {
            throw new AdminOperationRefusal('operation_payload_invalid', 'The pending-operation cursor is invalid.');
        }
        $afterActor = (int) $match[1];
        $afterHash = $match[2];
    }
    try {
        admin_operation_assert_storage();
        $counts = admin_operation_model_pending_counts();
        $rows = admin_operation_model_pending_page($afterActor, $afterHash);
        $items = array_map('Gallery\\Services\\admin_operation_maintenance_metadata', array_slice($rows, 0, ADMIN_OPERATION_PENDING_PAGE_SIZE));
        $last = $items ? $items[count($items) - 1] : null;
        return ['counts' => array_map(
            /** Normalize a database aggregate without exposing query details. @param int|numeric-string|null $value Observed aggregate count. @return int Nonnegative count. */
            static fn ($value): int => max(0, (int) $value), array_intersect_key($counts, array_flip(['pending', 'needs_reconciliation', 'unknown']))),
            'items' => $items, 'next_cursor' => count($rows) > ADMIN_OPERATION_PENDING_PAGE_SIZE && $last ? $last['actor_id'] . ':' . $last['key_hash'] : null];
    } catch (Throwable $exception) {
        throw $exception instanceof AdminOperationRefusal ? $exception
            : new AdminOperationRefusal('operation_storage_unavailable', 'Pending operations could not be inspected. Retained rows were not changed.');
    }
}

/**
 * Inspect one exact claim without returning plaintext inputs, worker hashes or URLs.
 *
 * @param int $actorId Original administrator identity.
 * @param string $keyHash Exact operation-key digest from the pending report.
 * @return array{actor_id:int,key_hash:string,payload_hash:string,operation:'gallery.create'|'gallery.create_upload'|'image.classic_upload'|'unknown',state:'pending'|'needs_reconciliation'|'completed'|'unknown',created_at:string,updated_at:string} Bounded original-operation identity/state; invalid timestamps are empty, not native errors.
 */
function admin_operation_inspect(int $actorId, string $keyHash): array
{
    admin_operation_maintenance_identity($actorId, $keyHash);
    try {
        admin_operation_assert_storage();
        $row = admin_operation_model_find($actorId, $keyHash);
        if (!$row) {
            throw new AdminOperationRefusal('operation_result_unavailable', 'The requested retained operation does not exist.');
        }
        return admin_operation_maintenance_metadata($row);
    } catch (Throwable $exception) {
        throw $exception instanceof AdminOperationRefusal ? $exception
            : new AdminOperationRefusal('operation_storage_unavailable', 'The retained operation could not be inspected.');
    }
}

/**
 * Parse a bounded operator evidence document, without treating it as proof of physical success.
 *
 * @param string $json Private evidence document read by the CLI controller.
 * @return array{actor_id:int,key_hash:string,payload_hash:string,response:array<array-key,mixed>} Bounded document identity; response remains untrusted until reconcile_result validates canonical binding and references.
 */
function admin_operation_reconciliation_document(string $json): array
{
    $document = strlen($json) <= ADMIN_OPERATION_INPUT_MAX_BYTES ? json_decode($json, true) : null;
    if (!is_array($document) || !is_int($document['actor_id'] ?? null)
        || !is_string($document['key_hash'] ?? null) || !is_string($document['payload_hash'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $document['payload_hash']) || !is_array($document['response'] ?? null)
        || array_diff(array_keys($document), ['actor_id', 'key_hash', 'payload_hash', 'response'])) {
        throw new AdminOperationRefusal('operation_payload_invalid', 'The reconciliation document is invalid or exceeds its safe bound.');
    }
    admin_operation_maintenance_identity($document['actor_id'], $document['key_hash']);
    return $document;
}

/**
 * Dry-run or explicitly attach independently verified results; never rerun target work.
 *
 * @param int $actorId Original administrator identity.
 * @param string $keyHash Exact retained key digest; possession confers no HTTP authority.
 * @param string $payloadHash Independently inspected original semantic fingerprint.
 * @param array<string,mixed> $response Operator-verified canonical original completion.
 * @param bool $apply False validates only; true persists completion once under worker exclusion.
 * @return array{ok:true,gallery_id:int|numeric-string,mutation:array{type:'gallery.create'|'gallery.create_with_upload'|'image.upload',entity_ids:array<array-key,int|numeric-string>},contexts:array<array-key,mixed>,fallback:array<string,mixed>,...} Canonical response with original bound IDs and the additional allowlisted presentation/diagnostic fields documented in ADMIN_OPERATION_KEYS.md.
 */
function admin_operation_reconcile_result(int $actorId, string $keyHash, string $payloadHash, array $response, bool $apply = false): array
{
    admin_operation_maintenance_identity($actorId, $keyHash);
    $lockName = null;
    try {
        admin_operation_assert_storage();
        $lockName = admin_operation_model_lock($actorId, $keyHash);
        if ($lockName === null) {
            throw new AdminOperationRefusal('operation_pending', 'The original worker is still active; reconciliation cannot proceed.');
        }
        $row = admin_operation_model_find($actorId, $keyHash);
        if (!$row || !in_array($row['state'], ['pending', 'needs_reconciliation'], true)
            || !hash_equals((string) $row['payload_hash'], $payloadHash)) {
            throw new AdminOperationRefusal('operation_payload_conflict', 'The retained operation does not match the reconciliation evidence.');
        }
        $response = admin_operation_response($response);
        admin_operation_validate_response_binding($row, $response);
        admin_operation_validate_result_references($response);
        return $apply ? admin_operation_complete($row, $response) : $response;
    } catch (Throwable $exception) {
        throw $exception instanceof AdminOperationRefusal ? $exception
            : new AdminOperationRefusal('operation_storage_unavailable', 'Operation reconciliation could not be acknowledged. Retain the row and inspect its state.');
    } finally {
        admin_operation_release(['lock_name' => $lockName]);
    }
}
