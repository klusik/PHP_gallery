<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_create.php
 * Module Type: Service
 * Purpose: Coordinate explicitly scoped, idempotent linked gallery creation.
 * Responsibilities:
 *   - Bind a durable operation claim to an issuer, client and semantic input.
 *   - Hold live grant locks across the gallery write and ledger completion.
 *   - Return safe receipts without paths or automatically repeating uncertainty.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Models\admin_operation_model_complete_owned;
use function Gallery\Models\admin_operation_model_abandon_linked_create;
use function Gallery\Models\admin_operation_model_abandon_before_filesystem;
use function Gallery\Models\admin_operation_model_find;
use function Gallery\Models\admin_operation_model_lock;
use function Gallery\Models\admin_operation_model_lock_owned;
use function Gallery\Models\admin_operation_model_unlock;
use function Gallery\Models\app_link_model_create_transaction_begin;
use function Gallery\Models\app_link_model_create_transaction_end;
use function Gallery\Models\gallery_model_linked_visibility_lock;
use function Gallery\Models\gallery_model_linked_visibility_update;
use function Gallery\Models\gallery_model_visibility_inspect;
use function Gallery\Models\gallery_model_linked_reservation_insert;
use function Gallery\Models\gallery_model_linked_reservation_complete;
use function Gallery\Models\gallery_model_linked_reservation_assert_pending;

/**
 * Bound the primary gallery title before filesystem and ledger work.
 * Type: int. Units: UTF-8 bytes. Scope: one linked gallery creation.
 * Consumers: app_linking_create_input.
 * Rationale: 255 bounds a client-controlled value at the database boundary.
 */
const APP_LINKING_CREATE_TITLE_MAX_BYTES = 255;

/**
 * Bound the sidecar examined when confirming a linked creation receipt.
 * Type: int. Units: bytes. Scope: one receipt status observation.
 * Consumers: app_linking_create_receipt_storage_current.
 * Rationale: 1048576 bounds memory while refusing oversized uncertain metadata.
 */
const APP_LINKING_RECEIPT_SIDECAR_MAX_BYTES = 1048576;

/**
 * Normalize the deliberately small linked gallery creation input.
 *
 * @param array<string,mixed> $input Decoded JSON object with title and parent_id.
 * @return array{title:string,parent_id:int} Normalized title and zero for a root.
 * @throws RuntimeException When the request cannot safely name a gallery.
 */
function app_linking_create_input(array $input): array
{
    $title = $input['title'] ?? null;
    $parent = $input['parent_id'] ?? null;
    if (!is_string($title) || strlen($title) > APP_LINKING_CREATE_TITLE_MAX_BYTES
        || preg_match('//u', $title) !== 1
        || preg_match('/[\x00-\x1f\x7f]/', $title) === 1
        || trim($title) === ''
        || !($parent === null || (is_int($parent) && $parent > 0))) {
        throw new RuntimeException('Invalid gallery creation input.');
    }
    return ['title' => trim($title), 'parent_id' => $parent ?? 0];
}

/**
 * Bind a client operation key independently of any replaceable access grant.
 *
 * @param string $key Canonical client-generated 256-bit lowercase hex key.
 * @param string $issuer Exact configured installation issuer.
 * @return string Domain-separated canonical ledger key; no bearer is retained.
 */
function app_linking_create_ledger_key(string $key, string $issuer): string
{
    admin_operation_require_key($key);
    return hash('sha256', "linked-gallery-create-v1\0" . $issuer . "\0"
        . APP_LINKING_WINAPP_CLIENT_ID . "\0" . $key);
}

/**
 * Correlate private linked-create evidence with one retained ledger row.
 *
 * @param array<string,mixed> $evidence Private actor_id, issuer, operation_key,
 *     title and nullable parent_id from the original client intent.
 * @return array{matched:bool,state:string} Matched retained state or unknown;
 *     neither establishes completed filesystem effects or authorizes retry.
 * @throws RuntimeException When private evidence or storage is unavailable.
 */
function admin_operation_inspect_linked_create(array $evidence): array
{
    $keys = ['actor_id', 'issuer', 'operation_key', 'title', 'parent_id'];
    if (count($evidence) !== count($keys) || array_diff(array_keys($evidence), $keys)
        || !is_int($evidence['actor_id'] ?? null) || $evidence['actor_id'] <= 0
        || !is_string($evidence['issuer'] ?? null)
        || !app_linking_issuer_valid($evidence['issuer'])
        || !is_string($evidence['operation_key'] ?? null)) {
        throw new \RuntimeException('Linked creation evidence is unavailable.');
    }
    $input = app_linking_create_input([
        'title' => $evidence['title'] ?? null,
        'parent_id' => $evidence['parent_id'],
    ]);
    $keyHash = hash('sha256', app_linking_create_ledger_key(
        $evidence['operation_key'], $evidence['issuer'],
    ));
    $payloadHash = admin_operation_fingerprint('gallery.create', $input);
    admin_operation_assert_storage();
    $row = admin_operation_model_find($evidence['actor_id'], $keyHash);
    if (!is_array($row) || ($row['operation_name'] ?? null) !== 'gallery.create'
        || !is_string($row['payload_hash'] ?? null)
        || !hash_equals($payloadHash, $row['payload_hash'])) {
        return ['matched' => false, 'state' => 'unknown'];
    }
    $metadata = admin_operation_maintenance_metadata($row);
    return ['matched' => true, 'state' => $metadata['state']];
}

/**
 * Verify the narrow receipt or broader mutation transaction prerequisites.
 *
 * @param bool $mutation True before creation; false for read-only receipt lookup.
 * @return void Returns only with verified storage for the selected operation.
 * @throws RuntimeException When any required storage cannot be verified.
 */
function app_linking_create_require_schema(bool $mutation): void
{
    schema_inspection_reset_request_cache();
    admin_operation_assert_storage();
    $status = app_linking_schema_status(
        'app_linking.gallery_create',
        [
            'users' => ['id', 'role'],
            'app_link_grants' => [
                'id', 'user_id', 'client_id', 'issuer', 'scope', 'revoked_at',
                'access_token_hash', 'access_expires_at',
            ],
            'galleries' => ['id', 'parent_id', 'title', 'folder_path'],
            'admin_operation_keys' => ['actor_id', 'key_hash', 'state'],
        ],
        [],
        ['users', 'app_link_grants', 'admin_operation_keys'],
    );
    if (!schema_inspection_is_available($status)) {
        throw new RuntimeException('Linked creation storage is unavailable.');
    }
    if (!$mutation) {
        return;
    }
    if (gallery_linked_reservation_schema_state() !== 'available') {
        throw new RuntimeException('Linked creation storage is unavailable.');
    }
    // The narrow create owner writes only the new gallery row and its URL;
    // all uniqueness and transaction prerequisites are required before mkdir.
    $creation = app_linking_schema_status(
        'app_linking.gallery_create_target',
        ['galleries' => [
            'id', 'parent_id', 'folder_path', 'folder_path_hash', 'slug',
            'title', 'description', 'sort_order', 'visibility',
            'voting_enabled', 'show_filenames', 'created_at', 'updated_at', 'url_slug',
            'url_path', 'url_path_hash', 'edit_revision',
        ]],
        ['galleries' => [
            'PRIMARY' => ['id'],
            'galleries_folder_path_hash_unique' => ['folder_path_hash'],
            'galleries_slug_unique' => ['slug'],
            'galleries_url_path_hash_unique' => ['url_path_hash'],
        ]],
        ['galleries'],
    );
    if (!schema_inspection_is_available($creation)) {
        throw new RuntimeException('Linked creation storage is unavailable.');
    }
    // Full current access storage or proven legacy absence are the only safe
    // choices. Unknown scheduled-publication storage cannot be ignored.
    gallery_access_assert_public_policy_available();
    gallery_publication_manual_storage_available();
}

/**
 * Recognize only explicitly approved creation bundles.
 *
 * @param string $scope Exact grant scope, including any offline suffix.
 * @return bool True only for an exact registered creation bundle, including its
 *     optional offline variant; existing grants never gain creation authority.
 */
function app_linking_create_scope_allowed(string $scope): bool
{
    return in_array(app_linking_scope_base($scope), [
        APP_LINKING_CREATE_SCOPE, APP_LINKING_READ_CREATE_SCOPE,
        APP_LINKING_UPLOAD_CREATE_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
        APP_LINKING_CREATE_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
    ], true);
}

/** A proven stale selected-gallery revision, before any visibility write. */
final class LinkedGalleryVisibilityConflict extends RuntimeException {}

/** A confirmed active scheduled publication must be resolved in the gallery. */
final class LinkedGalleryScheduledPublicationActive extends RuntimeException {}

/**
 * Increment a canonical decimal revision without narrowing it to a PHP integer.
 *
 * @param string $revision Positive decimal revision previously validated by the editor.
 * @return string Exact next decimal value, including any additional leading digit.
 */
function app_linking_visibility_next_revision(string $revision): string
{
    $digits = str_split($revision);
    for ($index = count($digits) - 1; $index >= 0; $index--) {
        if ($digits[$index] !== '9') {
            $digits[$index] = (string) ((int) $digits[$index] + 1);
            return implode('', $digits);
        }
        $digits[$index] = '0';
    }
    return '1' . implode('', $digits);
}

/**
 * Refuse visibility changes while a stored publication deadline is active.
 *
 * @param array{scheduled_publish_at?:string|null,...} $gallery Locked current row.
 * @param bool $columnAvailable True when the schedule column is confirmed present.
 * @return void Returns only when no scheduled publication can override the decision.
 * @throws LinkedGalleryScheduledPublicationActive When any deadline is stored.
 * @throws RuntimeException When confirmed schedule storage is absent from the row.
 */
function app_linking_visibility_assert_schedule_clear(array $gallery, bool $columnAvailable): void
{
    if ($columnAvailable && !array_key_exists('scheduled_publish_at', $gallery)) {
        throw new RuntimeException('Gallery publication state is unavailable.');
    }
    if (($gallery['scheduled_publish_at'] ?? null) !== null) {
        throw new LinkedGalleryScheduledPublicationActive(
            'Resolve scheduled publication in the gallery before changing visibility.',
        );
    }
}

/**
 * Require exact storage for the delegated visibility mutation and its recovery.
 *
 * @return void Returns only for the complete schema and supported sync APIs.
 * @throws RuntimeException When visibility storage cannot be verified.
 */
function app_linking_visibility_require_schema(): void
{
    app_linking_require_schema(app_linking_gallery_detail_schema_status());
    app_linking_require_schema(app_linking_schema_status(
        'app_linking.gallery_visibility',
        [
            'users' => ['id', 'role'],
            'app_link_grants' => [
                'id', 'user_id', 'client_id', 'issuer', 'scope', 'revoked_at',
                'access_token_hash', 'access_expires_at',
            ],
            'galleries' => ['id', 'folder_path', 'visibility', 'updated_at', 'edit_revision'],
        ],
        ['galleries' => ['PRIMARY' => ['id']]],
        ['users', 'app_link_grants', 'galleries'],
    ));
    gallery_access_assert_public_policy_available();
    if (!gallery_publication_manual_storage_available() || !function_exists('fsync')
        || !in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
        throw new RuntimeException('Linked visibility storage is unavailable.');
    }
}

/**
 * Change only stored gallery visibility under current grant and writer ownership.
 *
 * A committed database result with an incomplete sidecar is uncertain to the
 * client and must never be automatically replayed. The common writer lease
 * excludes cooperating gallery writers, not unrelated filesystem processes.
 *
 * @param string $token Presented bearer, never retained.
 * @param string $issuer Exact configured HTTPS issuer.
 * @param int $galleryId Positive selected physical gallery identifier.
 * @param string $expectedRevision Exact canonical decimal revision from detail.
 * @param string $visibility One of public, private, unpublished.
 * @return array{id:int,revision:string,visibility:string,access_mode:string,access_listing:string}
 *     Acknowledged stored values after durable sidecar and database readback.
 * @throws LinkedGalleryVisibilityConflict Only for a confirmed stale revision before writing.
 * @throws LinkedGalleryScheduledPublicationActive When a stored schedule is active.
 * @throws Throwable On refusal, storage uncertainty, or an uncertain outcome.
 */
function app_linking_change_gallery_visibility(
    string $token,
    string $issuer,
    int $galleryId,
    string $expectedRevision,
    string $visibility,
): array {
    if ($galleryId <= 0 || gallery_edit_revision(['edit_revision' => $expectedRevision]) === ''
        || !in_array($visibility, ['public', 'private', 'unpublished'], true)) {
        throw new RuntimeException('Invalid visibility request.');
    }
    $identity = app_linking_authenticate($token, $issuer);
    if ($identity === null || !app_linking_visibility_scope_allowed($identity['scope'])
        || !app_linking_scope_allowed(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'])) {
        throw new RuntimeException('Linked visibility authority is unavailable.');
    }
    app_linking_visibility_require_schema();
    $storedVisibility = gallery_visibility_storage_value($visibility);
    $hash = app_linking_access_token_hash($token);
    if ($hash === null) {
        throw new RuntimeException('Linked visibility authority is unavailable.');
    }
    $writer = gallery_edit_writer_begin();
    $transaction = false;
    $sidecar = null;
    $marker = null;
    try {
        if (!app_link_model_create_transaction_begin(
            $identity['user_id'], $identity['grant_id'], $hash, $issuer,
            APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'],
        )) {
            throw new RuntimeException('Linked visibility authority is unavailable.');
        }
        $transaction = true;
        app_linking_recheck_wide_scope(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope']);
        $current = gallery_model_linked_visibility_lock($galleryId);
        if (!is_array($current) || (int) ($current['id'] ?? 0) !== $galleryId) {
            throw new RuntimeException('Gallery is unavailable.');
        }
        if (gallery_edit_revision($current) !== $expectedRevision) {
            throw new LinkedGalleryVisibilityConflict('Gallery revision changed.');
        }
        app_linking_visibility_assert_schedule_clear(
            $current, gallery_publication_manual_storage_available(),
        );
        if (($current['visibility'] ?? null) === $storedVisibility) {
            throw new RuntimeException('Gallery visibility is unchanged.');
        }
        require_once __DIR__ . '/gallery_pdf_publication.php';
        gallery_pdf_publication_assert_visibility_change($current, $storedVisibility);
        if (!admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Gallery visibility ownership is unavailable.');
        }
        $prepared = gallery_visibility_marker_prepare($current, $storedVisibility);
        $sidecar = $prepared['sidecar'];
        $marker = $prepared['marker'];
        gallery_visibility_marker_assert_owned($marker);
        if (!admin_operation_model_lock_owned($writer)
            || !gallery_model_linked_visibility_update(
                $galleryId, $expectedRevision, $storedVisibility,
            )) {
            throw new RuntimeException('Gallery visibility ownership is unavailable.');
        }
        $updated = gallery_model_linked_visibility_lock($galleryId);
        if (!is_array($updated) || ($updated['visibility'] ?? null) !== $storedVisibility
            || gallery_edit_revision($updated) !== app_linking_visibility_next_revision($expectedRevision)
            || !in_array($updated['access_mode'] ?? null, ['normal', 'password'], true)
            || !in_array($updated['access_listing'] ?? null, ['listed', 'unlisted'], true)) {
            throw new RuntimeException('Gallery visibility readback is unavailable.');
        }
        // Triggers must not silently change any other stored field, including
        // scheduled publication, NSFW, access and optional feature flags.
        $before = $current;
        $after = $updated;
        unset($before['visibility'], $before['updated_at'], $before['edit_revision']);
        unset($after['visibility'], $after['updated_at'], $after['edit_revision']);
        if ($before !== $after || !admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Unrelated gallery settings changed.');
        }
        gallery_visibility_marker_assert_owned($marker);
        app_link_model_create_transaction_end(true);
        $transaction = false;
        gallery_visit_content_update([$galleryId]);
        if (!admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Gallery sidecar ownership is unavailable.');
        }
        gallery_visibility_marker_assert_owned($marker);
        gallery_sidecar_visibility_publish($sidecar);
        require_once __DIR__ . '/gallery_visibility_inspection.php';
        gallery_model_visibility_inspect(
            $galleryId,
            static function (array $row) use (
                $updated, $storedVisibility, $sidecar, $marker, $writer,
            ): array {
                if ($row !== $updated) {
                    throw new RuntimeException('Gallery changed after visibility commit.');
                }
                gallery_visibility_marker_finish(
                    $marker, $storedVisibility,
                    hash('sha256', $sidecar['replacement']), $writer,
                );
                return ['confirmed' => true];
            },
        );
        return [
            'id' => $galleryId,
            'revision' => gallery_edit_revision($updated),
            'visibility' => (string) $updated['visibility'],
            'access_mode' => (string) $updated['access_mode'],
            'access_listing' => (string) $updated['access_listing'],
        ];
    } finally {
        if ($transaction) {
            try {
                app_link_model_create_transaction_end(false);
            } catch (Throwable) {
                // A lost transaction is uncertain, never a successful mutation.
            }
        }
        gallery_edit_writer_end($writer);
    }
}

/**
 * Project a validated ledger envelope to the native client's safe receipt.
 *
 * @param array<string,mixed> $response Stored canonical operation envelope.
 * @return array{state:string,gallery:array{id:int,title:string,parent_id:?int}} Safe receipt.
 * @throws RuntimeException When stored response fields are not valid.
 */
function app_linking_create_project_receipt(array $response): array
{
    $id = $response['gallery_id'] ?? null;
    $title = $response['gallery_title'] ?? null;
    $parent = $response['parent_gallery_id'] ?? null;
    if (!is_int($id) || $id <= 0 || !is_string($title)
        || strlen($title) > APP_LINKING_CREATE_TITLE_MAX_BYTES
        || !is_int($parent) || $parent < 0) {
        throw new RuntimeException('Gallery receipt is unavailable.');
    }
    return ['state' => 'completed', 'gallery' => [
        'id' => $id, 'title' => $title, 'parent_id' => $parent ?: null,
    ]];
}

/**
 * Verify a stored creation receipt against the current gallery and storage.
 *
 * @param array{id:int,title:string,parent_id:int|null} $receipt Original validated gallery receipt.
 * @return bool True only when the current gallery, parent and physical target remain available.
 */
function app_linking_create_receipt_is_current(array $receipt): bool
{
    $gallery = find_gallery($receipt['id'], true);
    if (!is_array($gallery) || (int) ($gallery['id'] ?? 0) !== $receipt['id']
        || ($gallery['title'] ?? null) !== $receipt['title']
        || (int) ($gallery['parent_id'] ?? 0) !== ($receipt['parent_id'] ?? 0)) {
        return false;
    }
    if ($receipt['parent_id'] !== null
        && find_gallery($receipt['parent_id'], true) === null) {
        return false;
    }
    return app_linking_create_receipt_storage_current($gallery);
}

/**
 * Observe the current target and valid sidecar without trusting symlink paths.
 *
 * This proves only the current observation, not the original directory identity
 * or exclusion of an unrelated filesystem writer. Sidecar settings may change
 * legitimately and are not compared with the original creation state.
 *
 * @param array{folder_path?:string|null,...} $gallery Current catalog row.
 * @return bool True only for a stable contained directory and regular JSON object sidecar.
 */
function app_linking_create_receipt_storage_current(array $gallery): bool
{
    try {
        $folder = $gallery['folder_path'] ?? null;
        if (!is_string($folder) || $folder === '') {
            return false;
        }
        $normalized = normalize_relative_path($folder);
        if ($normalized === '' || $normalized !== str_replace('\\', '/', $folder)) {
            return false;
        }
        clearstatcache(true);
        $root = realpath(galleries_root());
        if ($root === false || !is_dir($root)) {
            return false;
        }
        $rootBefore = @stat($root);
        $target = $root;
        foreach (explode('/', $normalized) as $segment) {
            $target .= DIRECTORY_SEPARATOR . $segment;
            clearstatcache(true, $target);
            if (is_link($target) || !is_dir($target) || !is_readable($target)
                || !gallery_filesystem_path_inside_root($target)) {
                return false;
            }
        }
        $targetBefore = @stat($target);
        $sidecar = $target . DIRECTORY_SEPARATOR . 'gallery.json';
        clearstatcache(true, $sidecar);
        $sidecarBefore = @lstat($sidecar);
        if (!is_array($rootBefore) || !is_array($targetBefore)
            || !is_array($sidecarBefore) || is_link($sidecar)
            || !is_file($sidecar) || !is_readable($sidecar)
            || ($sidecarBefore['nlink'] ?? 0) !== 1
            || ($sidecarBefore['size'] ?? -1) < 0
            || $sidecarBefore['size'] > APP_LINKING_RECEIPT_SIDECAR_MAX_BYTES) {
            return false;
        }
        $contents = @file_get_contents(
            $sidecar, false, null, 0, APP_LINKING_RECEIPT_SIDECAR_MAX_BYTES + 1,
        );
        clearstatcache(true);
        $rootAfter = @stat($root);
        $targetAfter = @stat($target);
        $sidecarAfter = @lstat($sidecar);
        if (!is_string($contents) || strlen($contents) !== $sidecarBefore['size']
            || !is_array($rootAfter) || !is_array($targetAfter) || !is_array($sidecarAfter)
            || $rootBefore['dev'] !== $rootAfter['dev'] || $rootBefore['ino'] !== $rootAfter['ino']
            || $targetBefore['dev'] !== $targetAfter['dev'] || $targetBefore['ino'] !== $targetAfter['ino']
            || $sidecarBefore['dev'] !== $sidecarAfter['dev'] || $sidecarBefore['ino'] !== $sidecarAfter['ino']
            || $sidecarBefore['size'] !== $sidecarAfter['size']
            || $sidecarBefore['mtime'] !== $sidecarAfter['mtime']
            || $sidecarBefore['ctime'] !== $sidecarAfter['ctime']
            || realpath(galleries_root()) !== $root
            || is_link($sidecar) || !gallery_filesystem_path_inside_root($target)) {
            return false;
        }
        return json_decode($contents, false, 512, JSON_THROW_ON_ERROR) instanceof \stdClass;
    } catch (Throwable) {
        // Unknown filesystem state cannot establish a current creation receipt.
        return false;
    }
}

/**
 * Read a receipt without creating a claim or inferring that absent means safe.
 *
 * @param string $token Presented bearer, never retained.
 * @param string $issuer Exact configured issuer.
 * @param string $key Original canonical operation key.
 * @return array{state:string,gallery?:array{id:int,title:string,parent_id:?int}} Completed safe receipt or unknown.
 * @throws RuntimeException When authority or storage cannot be verified.
 */
function app_linking_create_status(string $token, string $issuer, string $key): array
{
    $identity = app_linking_authenticate($token, $issuer);
    if ($identity === null || !app_linking_create_scope_allowed($identity['scope'])) {
        throw new RuntimeException('Linked creation authority is unavailable.');
    }
    app_linking_create_require_schema(false);
    app_linking_recheck_wide_scope(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope']);
    $hash = app_linking_access_token_hash($token);
    $ledgerKey = app_linking_create_ledger_key($key, $issuer);
    if ($hash === null || !app_link_model_create_transaction_begin(
        $identity['user_id'], $identity['grant_id'], $hash, $issuer,
        APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'],
    )) {
        throw new RuntimeException('Linked creation authority is unavailable.');
    }
    try {
        $row = admin_operation_model_find($identity['user_id'], hash('sha256', $ledgerKey));
        if (!is_array($row) || $row['operation_name'] !== 'gallery.create'
            || $row['state'] !== 'completed') {
            $result = ['state' => 'unknown'];
        } else {
            $response = json_decode((string) $row['response_json'], true);
            if (!is_array($response)) {
                throw new RuntimeException('Gallery receipt is unavailable.');
            }
            $response = admin_operation_response($response, false);
            admin_operation_validate_response_binding($row, $response);
            admin_operation_validate_result_references($response);
            $projected = app_linking_create_project_receipt($response);
            $result = app_linking_create_receipt_is_current($projected['gallery'])
                ? $projected : ['state' => 'unknown'];
        }
        app_link_model_create_transaction_end(true);
        return $result;
    } catch (Throwable $exception) {
        try {
            app_link_model_create_transaction_end(false);
        } catch (Throwable) {
            // A lost transaction is already unavailable, never a successful receipt.
        }
        throw $exception;
    }
}

/**
 * Verify the ledger can durably represent the linked-create tombstone.
 *
 * @return void Returns only when the original migration's exact storage is verified.
 * @throws RuntimeException When the required metadata is unavailable or differs.
 */
function app_linking_create_require_abandon_schema(): void
{
    app_linking_create_require_schema(false);
    $requirements = [];
    foreach ([
        'key_hash' => ['char(64)', false, 'ascii_bin'],
        'payload_hash' => ['char(64)', false, 'ascii_bin'],
        'owner_hash' => ['char(64)', false, 'ascii_bin'],
        'operation_name' => ['varchar(40)', false, 'utf8mb4_unicode_ci'],
        'state' => ['varchar(32)', false, 'utf8mb4_unicode_ci'],
        'response_json' => ['mediumtext', true, 'utf8mb4_unicode_ci'],
    ] as $column => [$type, $nullable, $collation]) {
        $requirements[] = schema_inspection_column_exact(
            'admin_operation_keys', $column, $type, $nullable, $collation,
            false, true,
        );
    }
    if (!schema_inspection_is_available(schema_inspection_feature(
        'app_linking.gallery_create_abandon', $requirements,
    ))) {
        throw new RuntimeException('Linked operation storage is unavailable.');
    }
}

/**
 * Permanently close one exact linked-create key without claiming no effects.
 *
 * @param string $token Current bearer, never retained.
 * @param string $issuer Exact trusted installation issuer.
 * @param string $key Original canonical client operation key.
 * @param array{title:string,parent_id:int} $input Original normalized input.
 * @return array{state:string,gallery?:array{id:int,title:string,parent_id:?int}}
 *     Abandoned tombstone or the existing completed/unknown status.
 * @throws Throwable When authority, locks, schema or transaction are uncertain.
 */
function app_linking_create_abandon(
    string $token,
    string $issuer,
    string $key,
    array $input,
): array {
    $identity = app_linking_authenticate($token, $issuer);
    if ($identity === null || !app_linking_create_scope_allowed($identity['scope'])) {
        throw new RuntimeException('Linked creation authority is unavailable.');
    }
    app_linking_create_require_abandon_schema();
    app_linking_recheck_wide_scope(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope']);
    $tokenHash = app_linking_access_token_hash($token);
    if ($tokenHash === null) {
        throw new RuntimeException('Linked creation authority is unavailable.');
    }
    $keyHash = hash('sha256', app_linking_create_ledger_key($key, $issuer));
    $payloadHash = admin_operation_fingerprint('gallery.create', $input);
    $lock = admin_operation_model_lock($identity['user_id'], $keyHash);
    if ($lock === null) {
        throw new RuntimeException('Linked operation is active.');
    }
    $writer = null;
    $transaction = false;
    try {
        $writer = gallery_edit_writer_begin();
        if (!admin_operation_model_lock_owned($lock)
            || !admin_operation_model_lock_owned($writer)
            || !app_link_model_create_transaction_begin(
                $identity['user_id'], $identity['grant_id'], $tokenHash, $issuer,
                APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'],
            )) {
            throw new RuntimeException('Linked operation ownership is unavailable.');
        }
        $transaction = true;
        app_linking_create_require_abandon_schema();
        app_linking_recheck_wide_scope(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope']);
        if (!admin_operation_model_lock_owned($lock)
            || !admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Linked operation ownership is unavailable.');
        }
        $result = admin_operation_model_abandon_linked_create(
            $identity['user_id'], $keyHash, $payloadHash,
            hash('sha256', admin_operation_new_key()), gmdate('Y-m-d H:i:s'),
        );
        if (!admin_operation_model_lock_owned($lock)
            || !admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Linked operation ownership is unavailable.');
        }
        app_link_model_create_transaction_end(true);
        $transaction = false;
    } finally {
        if ($transaction) {
            try {
                app_link_model_create_transaction_end(false);
            } catch (Throwable) {
                // A lost transaction cannot confirm closure to the client.
            }
        }
        try {
            if ($writer !== null) {
                gallery_edit_writer_end($writer);
            }
        } finally {
            admin_operation_model_unlock($lock);
        }
    }
    return $result === 'completed'
        ? app_linking_create_status($token, $issuer, $key)
        : ['state' => 'abandoned'];
}

/**
 * Create one gallery behind a durable claim and locked live bearer authority.
 *
 * @param string $token Presented bearer, never retained.
 * @param string $issuer Exact configured issuer.
 * @param string $key Original canonical client operation key.
 * @param array{title:string,parent_id:int} $input Validated normalized creation input.
 * @return array{state:string,gallery?:array{id:int,title:string,parent_id:?int}} Safe completed receipt or unknown.
 * @throws Throwable On refused, interrupted or uncertain operation; retain the key.
 */
function app_linking_create_gallery(
    string $token,
    string $issuer,
    string $key,
    array $input,
): array {
    $identity = app_linking_authenticate($token, $issuer);
    if ($identity === null || !app_linking_create_scope_allowed($identity['scope'])) {
        throw new RuntimeException('Linked creation authority is unavailable.');
    }
    app_linking_create_require_schema(true);
    app_linking_recheck_wide_scope(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope']);
    $hash = app_linking_access_token_hash($token);
    if ($hash === null) {
        throw new RuntimeException('Linked creation authority is unavailable.');
    }
    $ledgerKey = app_linking_create_ledger_key($key, $issuer);
    $claim = admin_operation_begin(
        $identity['user_id'], $ledgerKey, 'gallery.create',
        admin_operation_fingerprint('gallery.create', $input),
    );
    if ($claim['replay']) {
        // Reauthenticate under the grant lock before exposing a stored receipt.
        return app_linking_create_status($token, $issuer, $key);
    }
    $writer = null;
    $transaction = false;
    $reservationCommitted = false;
    $filesystemStarted = false;
    try {
        $writer = gallery_edit_writer_begin();
        if (!admin_operation_model_lock_owned((string) $claim['lock_name'])) {
            throw new RuntimeException('Operation ownership is unavailable.');
        }
        if (!app_link_model_create_transaction_begin(
            $identity['user_id'], $identity['grant_id'], $hash, $issuer,
            APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'],
        )) {
            throw new RuntimeException('Linked creation authority is unavailable.');
        }
        $transaction = true;
        app_linking_create_require_schema(true);
        app_linking_recheck_wide_scope(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope']);
        gallery_linked_reservation_assert_admin_write();
        $parentId = $input['parent_id'];
        $parent = $parentId > 0 ? find_gallery($parentId, true) : null;
        if ($parentId > 0 && !is_array($parent)) {
            throw new RuntimeException('Selected parent gallery is unavailable.');
        }
        $reservedFolder = gallery_child_folder_path(
            $parent, gallery_folder_segment($input['title']),
        );
        if ($reservedFolder === ''
            || strlen($reservedFolder) > LINKED_GALLERY_CREATE_PATH_MAX_BYTES) {
            throw new RuntimeException('Gallery folder name is unavailable.');
        }
        // Avoid a durable reservation for a target already known to be unsafe.
        // The owned create repeats these checks after the reservation commits.
        gallery_creation_assert_catalog_preserved($reservedFolder);
        $reservationTarget = gallery_target_abs_path($reservedFolder);
        clearstatcache(true, $reservationTarget);
        if (file_exists($reservationTarget) || is_link($reservationTarget)) {
            throw new RuntimeException('Gallery folder is already occupied.');
        }
        gallery_model_linked_reservation_insert(
            $reservedFolder, (int) $claim['actor_id'],
            (string) $claim['key_hash'], (string) $claim['payload_hash'],
        );
        if (!admin_operation_model_lock_owned((string) $claim['lock_name'])
            || !admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Gallery reservation ownership is unavailable.');
        }
        app_link_model_create_transaction_end(true);
        $transaction = false;
        $reservationCommitted = true;
        if (!admin_operation_model_lock_owned((string) $claim['lock_name'])
            || !admin_operation_model_lock_owned($writer)
            || !app_link_model_create_transaction_begin(
                $identity['user_id'], $identity['grant_id'], $hash, $issuer,
                APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'],
            )) {
            throw new RuntimeException('Gallery reservation ownership is unavailable.');
        }
        $transaction = true;
        app_linking_create_require_schema(true);
        app_linking_recheck_wide_scope(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope']);
        $reservation = [
            'folder' => $reservedFolder, 'actor_id' => (int) $claim['actor_id'],
            'key_hash' => (string) $claim['key_hash'],
            'payload_hash' => (string) $claim['payload_hash'],
        ];
        $gallery = create_empty_gallery_linked_owned($input, $reservation, $filesystemStarted);
        $id = (int) ($gallery['id'] ?? 0);
        $parent = (int) ($gallery['parent_id'] ?? 0);
        $title = (string) ($gallery['title'] ?? '');
        if ($id <= 0 || $parent !== $input['parent_id'] || $title !== $input['title']
            || !admin_operation_model_lock_owned((string) $claim['lock_name'])
            || !admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Gallery completion ownership is unavailable.');
        }
        $response = admin_operation_response([
            'ok' => true,
            'message' => '',
            'mutation' => ['type' => 'gallery.create', 'entity_ids' => [$id]],
            'contexts' => [],
            'fallback' => [],
            'gallery_id' => $id,
            'gallery_title' => $title,
            'parent_gallery_id' => $parent,
        ]);
        admin_operation_validate_response_binding($claim, $response);
        $result = app_linking_create_project_receipt($response);
        admin_operation_model_complete_owned(
            $claim,
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            gmdate('Y-m-d H:i:s'),
        );
        $filesystemProof = $gallery['_linked_creation_fs'] ?? null;
        if (!is_array($filesystemProof)) {
            throw new RuntimeException('Gallery filesystem proof is unavailable.');
        }
        gallery_linked_creation_assert_filesystem($filesystemProof);
        if (!admin_operation_model_lock_owned((string) $claim['lock_name'])
            || !admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Gallery completion ownership is unavailable.');
        }
        gallery_model_linked_reservation_complete(
            $reservedFolder, (int) $claim['actor_id'],
            (string) $claim['key_hash'], (string) $claim['payload_hash'],
        );
        if (!admin_operation_model_lock_owned((string) $claim['lock_name'])
            || !admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Gallery completion ownership is unavailable.');
        }
        app_link_model_create_transaction_end(true);
        $transaction = false;
        gallery_visit_content_update([$id]);
        return $result;
    } catch (Throwable $exception) {
        $rollbackConfirmed = !$transaction;
        if ($transaction) {
            try {
                app_link_model_create_transaction_end(false);
                $transaction = false;
                $rollbackConfirmed = true;
            } catch (Throwable) {
                // A failed commit can be ambiguous; retain the original claim.
            }
        }
        if ($reservationCommitted && !$filesystemStarted && $rollbackConfirmed
            && $writer !== null) {
            try {
                if (!admin_operation_model_lock_owned((string) $claim['lock_name'])
                    || !admin_operation_model_lock_owned($writer)
                    || !app_link_model_create_transaction_begin(
                        $identity['user_id'], $identity['grant_id'], $hash, $issuer,
                        APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'],
                    )) {
                    throw new RuntimeException('Gallery closure ownership is unavailable.');
                }
                $transaction = true;
                app_linking_create_require_schema(true);
                app_linking_create_require_abandon_schema();
                app_linking_recheck_wide_scope(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope']);
                gallery_model_linked_reservation_assert_pending(
                    $reservedFolder, (int) $claim['actor_id'],
                    (string) $claim['key_hash'], (string) $claim['payload_hash'],
                );
                admin_operation_model_abandon_before_filesystem($claim, gmdate('Y-m-d H:i:s'));
                gallery_model_linked_reservation_complete(
                    $reservedFolder, (int) $claim['actor_id'],
                    (string) $claim['key_hash'], (string) $claim['payload_hash'], 'no_effect',
                );
                if (!admin_operation_model_lock_owned((string) $claim['lock_name'])
                    || !admin_operation_model_lock_owned($writer)) {
                    throw new RuntimeException('Gallery closure ownership is unavailable.');
                }
                app_link_model_create_transaction_end(true);
                $transaction = false;
            } catch (Throwable) {
                if ($transaction) {
                    try {
                        app_link_model_create_transaction_end(false);
                        $transaction = false;
                    } catch (Throwable) {
                        // An ambiguous commit leaves either pending evidence or
                        // the atomically committed tombstone and terminal state.
                    }
                }
            }
        }
        if (!$transaction) {
            admin_operation_fail($claim);
        }
        throw $exception;
    } finally {
        try {
            if ($writer !== null) {
                gallery_edit_writer_end($writer);
            }
        } finally {
            admin_operation_release($claim);
        }
    }
}
