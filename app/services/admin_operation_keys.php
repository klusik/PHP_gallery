<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Coordinate claim, replay and completion policy while controllers retain authentication and CSRF.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/admin_operation_keys.php
 * Module Type: Service
 * Purpose: Bind durable create/upload operation keys to an actor and semantic input.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Controller authentication/CSRF checks remain mandatory on every replay.
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use const Gallery\Core\ADMIN_OPERATION_KEY_BYTES;
use const Gallery\Core\ADMIN_OPERATION_RESPONSE_MAX_BYTES;
use const Gallery\Core\ADMIN_OPERATION_INPUT_MAX_BYTES;
use const Gallery\Core\ADMIN_OPERATION_MAX_FILES;
use function Gallery\Models\admin_operation_model_claim;
use function Gallery\Models\admin_operation_model_complete;
use function Gallery\Models\admin_operation_model_fail;
use function Gallery\Models\admin_operation_model_find;
use function Gallery\Models\admin_operation_model_lock;
use function Gallery\Models\admin_operation_model_primary_state;
use function Gallery\Models\admin_operation_model_unlock;

require_once dirname(__DIR__) . '/models/admin_operation_keys.php';
require_once dirname(__DIR__) . '/policy_constants.php';
require_once __DIR__ . '/schema_inspection.php';

/** A bounded conflict/unavailability result that controllers can map to HTTP. */
final class AdminOperationRefusal extends RuntimeException
{
    /**
     * Construct a safe operation refusal without carrying an underlying exception.
     *
     * @param string $reason Closed machine reason owned by this service.
     * @param string $message Safe human explanation, never native SQL or paths.
     * @return void
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct(function_exists('Gallery\\Services\\t') ? t('admin.operation.' . $reason, $message) : $message);
    }
}

require_once __DIR__ . '/admin_operation_keys/diagnostics.php';
require_once __DIR__ . '/admin_operation_keys/maintenance.php';

/**
 * Generate a fresh intentional-operation identifier without touching session state.
 *
 * @return string Lowercase 64-character random hexadecimal identifier.
 */
function admin_operation_new_key(): string
{
    return bin2hex(random_bytes(ADMIN_OPERATION_KEY_BYTES));
}

/**
 * Retain a submitted key for failure rendering, otherwise issue a new form key.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $existing Prior form value; only a valid hexadecimal string is retained, all other values request a new key.
 * @return string Valid form key.
 */
function admin_operation_form_key(mixed $existing = null): string
{
    return is_string($existing) && preg_match('/^[a-f0-9]{64}$/D', $existing) ? $existing : admin_operation_new_key();
}

/**
 * Require an explicit key; old clients must reload a generated form before posting.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $key Untrusted identifier; only a valid hexadecimal string is admitted, other values receive a required-key refusal.
 * @return string Validated identifier, suitable only for actor-bound hashing.
 */
function admin_operation_require_key(mixed $key): string
{
    if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
        throw new AdminOperationRefusal('operation_key_required', 'Reload this form before submitting. A valid operation key is required to protect retries.');
    }
    return $key;
}

/**
 * Normalize only creation semantics; omit CSRF, session, routing, and UI fields.
 *
 * @param array<string,mixed> $input Controller-normalized creation fields.
 * @return array<string,int|string> Stable creation payload for hashing only.
 */
function admin_operation_gallery_payload(array $input): array
{
    $payload = [];
    foreach (['title', 'folder_name', 'description', 'gallery_date', 'gallery_date_end', 'visibility', 'count_badge_visibility'] as $key) {
        $value = $input[$key] ?? '';
        if (!is_scalar($value) && $value !== null) {
            throw new AdminOperationRefusal('operation_payload_invalid', 'The operation contains invalid form values.');
        }
        $payload[$key] = in_array($key, ['title', 'folder_name'], true) ? trim((string) $value) : (string) $value;
    }
    $payload['parent_id'] = max(0, (int) ($input['parent_id'] ?? 0));
    $payload['voting_enabled'] = !empty($input['voting_enabled']) ? 1 : 0;
    $payload['show_filenames'] = !empty($input['show_filenames']) ? 1 : 0;
    if (array_key_exists('sort_order', $input)) {
        $payload['sort_order'] = (int) $input['sort_order'];
    }
    return $payload;
}

/**
 * Hash canonical semantic fields and streamed uploaded bytes, never temporary paths.
 *
 * @param string $operation Closed operation selected by the controller.
 * @param array<string,mixed> $payload Creation fields, or gallery/create-thumbnails upload semantics.
 * @param list<array<string,mixed>> $files Validated multipart entries in storage order.
 * @return string SHA-256 payload digest; raw inputs are not retained.
 */
function admin_operation_fingerprint(string $operation, array $payload, array $files = []): string
{
    if (!in_array($operation, ['gallery.create', 'gallery.create_upload', 'image.classic_upload'], true) || count($files) > ADMIN_OPERATION_MAX_FILES) {
        throw new AdminOperationRefusal('operation_payload_invalid', 'The operation type or file count is not supported.');
    }
    $semantic = $operation === 'gallery.create'
        ? admin_operation_gallery_payload($payload)
        : ['gallery' => $operation === 'gallery.create_upload' ? admin_operation_gallery_payload((array) ($payload['gallery'] ?? [])) : null,
            'gallery_id' => $operation === 'image.classic_upload' ? (int) ($payload['gallery_id'] ?? 0) : 0,
            'create_thumbnails' => !empty($payload['create_thumbnails'])];
    $identities = [];
    foreach ($files as $file) {
        $path = (string) ($file['tmp_name'] ?? '');
        clearstatcache(true, $path);
        $size = @filesize($path);
        $hash = $size !== false && is_file($path) ? @hash_file('sha256', $path) : false;
        if ($size === false || $hash === false) {
            throw new AdminOperationRefusal('operation_payload_invalid', 'Uploaded file identity could not be verified. No operation was started.');
        }
        $identities[] = ['name' => (string) ($file['name'] ?? ''), 'bytes' => $size, 'sha256' => $hash];
    }
    $json = json_encode(['operation' => $operation, 'fields' => $semantic, 'files' => $identities], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > ADMIN_OPERATION_INPUT_MAX_BYTES) {
        throw new AdminOperationRefusal('operation_payload_invalid', 'The operation input cannot be represented safely.');
    }
    return hash('sha256', $json);
}

/**
 * Require observed ledger columns and exact full actor/key PRIMARY uniqueness.
 *
 * Named-index existence alone cannot admit claims. Missing, incompatible and
 * unknown definitions all use the existing bounded storage refusal, before locks,
 * ledger insertion or target mutation. Definition reads are deliberately fresh.
 *
 * @return void
 */
function admin_operation_assert_storage(): void
{
    $requirements = [schema_inspection_table('admin_operation_keys')];
    if (schema_inspection_is_available($requirements[0])) {
        foreach (['actor_id', 'key_hash', 'operation_name', 'payload_hash', 'owner_hash', 'state', 'response_json', 'created_at', 'updated_at'] as $column) {
            $requirements[] = schema_inspection_column('admin_operation_keys', $column);
        }
        $requirements[] = schema_inspection_index('admin_operation_keys', 'PRIMARY');
    }
    if (!schema_inspection_is_available(schema_inspection_feature('admin_operation_keys', $requirements))
        || admin_operation_model_primary_state() !== 'available') {
        throw new AdminOperationRefusal('operation_storage_unavailable', 'Operation storage could not be verified. Run pending migrations or restore database availability before retrying.');
    }
}

/**
 * Claim a new operation or return its completed response without repeating work.
 *
 * @param int $actorId Currently authenticated administrator, supplied after controller reauthorization.
 * @param scalar|array<array-key,mixed>|object|resource|null $key Submitted identifier validated as a hexadecimal string before storage admission.
 * @param string $operation Controller-selected create/upload operation.
 * @param string $payloadHash Canonical input digest computed before target mutation.
 * @return array{replay:true,response:array<string,mixed>}|array{replay:false,lock_name:string,actor_id:int|numeric-string,key_hash:string,operation_name:string,payload_hash:string,owner_hash:string,state:'pending',response_json:null,created_at:string,updated_at:string} Either the already-completed canonical response or an owned durable claim that the caller must complete/fail and release.
 */
function admin_operation_begin(int $actorId, mixed $key, string $operation, string $payloadHash): array
{
    $key = admin_operation_require_key($key);
    if ($actorId <= 0 || !in_array($operation, ['gallery.create', 'gallery.create_upload', 'image.classic_upload'], true) || !preg_match('/^[a-f0-9]{64}$/D', $payloadHash)) {
        throw new AdminOperationRefusal('operation_payload_invalid', 'The operation identity is invalid.');
    }
    $lockName = null;
    try {
        admin_operation_assert_storage();
        $keyHash = hash('sha256', $key);
        $lockName = admin_operation_model_lock($actorId, $keyHash);
        if ($lockName === null) {
            throw new AdminOperationRefusal('operation_pending', 'This operation is already running. Retain its key and retry after it finishes.');
        }
        $ownerHash = hash('sha256', random_bytes(ADMIN_OPERATION_KEY_BYTES));
        $row = admin_operation_model_claim($actorId, $keyHash, $operation, $payloadHash, $ownerHash, gmdate('Y-m-d H:i:s'));
        if ($row['operation_name'] !== $operation || !hash_equals((string) $row['payload_hash'], $payloadHash)) {
            throw new AdminOperationRefusal('operation_payload_conflict', 'This operation key was already used with different input. Start a new operation for an intentional change.');
        }
        if ($row['state'] === 'completed') {
            $json = (string) $row['response_json'];
            $response = strlen($json) <= ADMIN_OPERATION_RESPONSE_MAX_BYTES ? json_decode($json, true) : null;
            if (!is_array($response)) {
                throw new AdminOperationRefusal('operation_result_unavailable', 'The original operation result requires reconciliation. No new work was started.');
            }
            // Stored safe messages retain their original language and content.
            $response = admin_operation_response($response, false);
            admin_operation_validate_response_binding($row, $response);
            admin_operation_validate_result_references($response);
            admin_operation_model_unlock($lockName);
            return ['replay' => true, 'response' => $response];
        }
        if ($row['state'] !== 'pending' || !hash_equals((string) $row['owner_hash'], $ownerHash)) {
            throw new AdminOperationRefusal('operation_needs_reconciliation', 'An earlier attempt may have changed gallery data. Reconcile that attempt before starting replacement work.');
        }
        return $row + ['replay' => false, 'lock_name' => $lockName];
    } catch (Throwable $exception) {
        admin_operation_release(['lock_name' => $lockName]);
        if ($exception instanceof AdminOperationRefusal) {
            throw $exception;
        }
        throw new AdminOperationRefusal('operation_storage_unavailable', 'Operation storage is unavailable. No new target work was authorized; retain the key for retry.');
    }
}

/**
 * Revalidate referenced entities under the controller's freshly authenticated admin.
 *
 * @param array<string,mixed> $response Original completion envelope and stable IDs.
 * @return void
 */
function admin_operation_validate_result_references(array $response): void
{
    $galleryId = (int) ($response['gallery_id'] ?? 0);
    $gallery = $galleryId > 0 ? find_gallery($galleryId, true) : null;
    $parentId = (int) ($response['parent_gallery_id'] ?? 0);
    if (!$gallery || (int) ($gallery['parent_id'] ?? 0) !== $parentId || ($parentId > 0 && !find_gallery($parentId, true))) {
        throw new AdminOperationRefusal('operation_result_unavailable', 'The original gallery or its context has changed. No replacement operation was started.');
    }
    $ids = $response['image_ids'] ?? [];
    if (!is_array($ids) || count($ids) > ADMIN_OPERATION_MAX_FILES) {
        throw new AdminOperationRefusal('operation_result_unavailable', 'The original image result requires reconciliation.');
    }
    foreach ($ids as $id) {
        $image = (int) $id > 0 ? find_image((int) $id, true) : null;
        if (!$image || (int) ($image['gallery_id'] ?? 0) !== $galleryId) {
            throw new AdminOperationRefusal('operation_result_unavailable', 'An original uploaded image is no longer in this gallery. No replacement upload was started.');
        }
    }
}

/**
 * Preserve canonical response data and project safe diagnostics before initial storage.
 *
 * @param array<string,mixed> $response Controller-built canonical success envelope.
 * @param bool $prepareDiagnostics Project raw processing fields only for new completion; replay preserves stored translations.
 * @return array<string,mixed> Bounded private response used for both initial output and replay.
 */
function admin_operation_response(array $response, bool $prepareDiagnostics = true): array
{
    if ($prepareDiagnostics && in_array($response['mutation']['type'] ?? '', ['image.upload', 'gallery.create_with_upload'], true)) {
        $response = array_replace($response, admin_operation_upload_diagnostics($response));
    }
    $allowed = ['ok', 'message', 'mutation', 'panel', 'contexts', 'fallback', 'gallery_id', 'gallery_ids', 'gallery_title', 'gallery_url', 'edit_url', 'parent_gallery_id', 'parent_gallery_url', 'refresh_gallery_id', 'refresh_url', 'created_gallery', 'image_ids', 'uploaded', 'scanned', 'thumbnails', 'thumbnail_failed', 'scan_failed', 'renamed', 'redirect_url'];
    if (in_array($response['mutation']['type'] ?? '', ['image.upload', 'gallery.create_with_upload'], true)) {
        $allowed = array_merge($allowed, ['filenames', 'filenames_omitted', 'thumbnail_failed_filenames', 'scan_failed_filenames', 'thumbnail_errors', 'rename_warnings', 'rename_failures', 'upload_events', 'upload_diagnostics']);
    }
    $response = array_intersect_key($response, array_flip($allowed));
    if (($response['ok'] ?? false) !== true || !is_array($response['mutation'] ?? null) || !is_array($response['contexts'] ?? null) || !is_array($response['fallback'] ?? null)) {
        throw new AdminOperationRefusal('operation_result_unavailable', 'The operation result could not be recorded safely. Reconciliation is required.');
    }
    admin_operation_assert_response_private($response);
    $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || strlen($json) > ADMIN_OPERATION_RESPONSE_MAX_BYTES) {
        throw new AdminOperationRefusal('operation_result_unavailable', 'The operation result exceeds safe storage bounds. Reconciliation is required.');
    }
    return json_decode($json, true);
}

/**
 * Reject credential-shaped response fields and credential-bearing URL queries.
 *
 * @param array<string|int,mixed> $data Prepared response tree, never arbitrary request payload.
 * @return void
 */
function admin_operation_assert_response_private(array $data): void
{
    foreach ($data as $key => $value) {
        if (preg_match('/(?:csrf|password|secret|token|cookie|authorization|session)/i', (string) $key)) {
            throw new AdminOperationRefusal('operation_result_unavailable', 'The operation result contains private authentication data and was not stored.');
        }
        if (is_array($value)) {
            admin_operation_assert_response_private($value);
        } elseif (is_string($value) && str_ends_with((string) $key, '_url')) {
            $parts = parse_url($value);
            if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
                throw new AdminOperationRefusal('operation_result_unavailable', 'The operation result contains an unsafe URL.');
            }
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (array_diff(array_keys($query), ['r', 'route', 'id', 'gallery_id', 'public_path', 'page', 'gallery_page', 'photo_page', 'created', 'uploaded', 'scanned', 'thumbnails', 'thumbnail_failed', 'scan_failed', 'tab', 'lang', 'language'])) {
                throw new AdminOperationRefusal('operation_result_unavailable', 'The operation result contains an unrecognized URL parameter and was not stored.');
            }
            admin_operation_assert_response_private($query);
        }
    }
}

/**
 * Retain pagination without persisting arbitrary source-URL credentials or paths.
 *
 * @param string $canonicalUrl Server-generated authorized gallery URL.
 * @param string $sourceUrl Optional current-page URL supplied by the browser.
 * @return string Canonical gallery URL with only positive pagination overrides.
 */
function admin_operation_refresh_url(string $canonicalUrl, string $sourceUrl): string
{
    $canonicalPath = rtrim((string) parse_url($canonicalUrl, PHP_URL_PATH), '/');
    $sourcePath = (string) parse_url($sourceUrl, PHP_URL_PATH);
    // Admit only a bounded clean pagination suffix of this exact canonical
    // gallery. Never copy a source path, host, share token, or arbitrary query.
    if ($canonicalPath !== '' && !str_contains($canonicalUrl, '?')
        && preg_match('~^' . preg_quote($canonicalPath, '~') . '/((?:galleries/)?[1-9][0-9]{0,8})/?$~D', $sourcePath, $match)) {
        $canonicalUrl = rtrim($canonicalUrl, '/') . '/' . $match[1] . '/';
    }
    parse_str((string) (parse_url($sourceUrl, PHP_URL_QUERY) ?: ''), $query);
    foreach (['gallery_page', 'photo_page'] as $key) {
        $value = $query[$key] ?? null;
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,8}$/D', $value)) {
            $canonicalUrl .= (str_contains($canonicalUrl, '?') ? '&' : '?') . $key . '=' . $value;
        }
    }
    return $canonicalUrl;
}

/**
 * Store the exact stable response before the controller sends bytes or redirects.
 *
 * @param array<string,mixed> $claim Owned pending ledger claim.
 * @param array<string,mixed> $response Controller-built canonical completion.
 * @return array<string,mixed> Original durable response for initial output and later replay.
 */
function admin_operation_complete(array $claim, array $response): array
{
    $response = admin_operation_response($response);
    admin_operation_validate_response_binding($claim, $response);
    try {
        admin_operation_model_complete($claim, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), gmdate('Y-m-d H:i:s'));
    } catch (Throwable) {
        throw new AdminOperationRefusal('operation_outcome_unknown', 'Work may have completed, but its response could not be confirmed. Retain this key; do not submit a replacement operation.');
    } finally {
        admin_operation_release($claim);
    }
    return $response;
}

/**
 * Require the durable envelope to describe this operation and its stable identities.
 *
 * @param array<string,mixed> $claim Durable operation identity.
 * @param array<string,mixed> $response Prepared canonical response.
 * @return void
 */
function admin_operation_validate_response_binding(array $claim, array $response): void
{
    $type = match ($claim['operation_name'] ?? '') {
        'gallery.create' => 'gallery.create',
        'gallery.create_upload' => 'gallery.create_with_upload',
        'image.classic_upload' => 'image.upload',
        default => '',
    };
    $expectedIds = $type === 'image.upload' ? ($response['image_ids'] ?? []) : [(int) ($response['gallery_id'] ?? 0)];
    if ($type === '' || ($response['mutation']['type'] ?? '') !== $type || ($response['mutation']['entity_ids'] ?? null) !== $expectedIds) {
        throw new AdminOperationRefusal('operation_result_unavailable', 'The original operation and result identities do not match. Reconciliation is required.');
    }
}

/**
 * Preserve ambiguous failure; if storage fails, the already-durable pending row remains.
 *
 * @param array<string,mixed>|null $claim Owned claim, or null before claim admission.
 * @return void
 */
function admin_operation_fail(?array $claim): void
{
    if (!$claim || !empty($claim['replay']) || empty($claim['lock_name'])) {
        return;
    }
    try {
        admin_operation_model_fail($claim, gmdate('Y-m-d H:i:s'));
    } catch (Throwable) {
        // Never erase/reopen a pending claim or hide the original controlled refusal.
    } finally {
        admin_operation_release($claim);
    }
}

/**
 * Release an owned connection lock on every controller exit path.
 *
 * @param array<string,mixed>|null $claim Owned claim, replay descriptor, or null.
 * @return void
 */
function admin_operation_release(?array $claim): void
{
    if (!empty($claim['lock_name'])) {
        try {
            admin_operation_model_unlock((string) $claim['lock_name']);
        } catch (Throwable) {
            // Database connection termination also releases its advisory lock.
        }
    }
}

/**
 * Explicitly attach an operator-verified result after an interrupted worker has stopped.
 *
 * This service is intentionally not routed to HTTP. The operator must establish
 * exact original effects from independent evidence, never title/filename guessing.
 * The live-worker lock prevents reconciliation while target work is still active.
 *
 * @param int $actorId Original administrator whose operation is being reconciled.
 * @param string $key Original operation key from the retained client form.
 * @param string $payloadHash Exact original ledger fingerprint, independently inspected.
 * @param array<string,mixed> $response Verified canonical original completion response.
 * @return array<string,mixed> Durable original response; no create/upload is rerun.
 */
function admin_operation_reconcile_completed(int $actorId, string $key, string $payloadHash, array $response): array
{
    admin_operation_require_key($key);
    return admin_operation_reconcile_result($actorId, hash('sha256', $key), $payloadHash, $response, true);
}
