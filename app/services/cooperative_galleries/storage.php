<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_galleries/storage.php
 * Module Type: Service
 * Purpose: Gate cooperative persistence with explicit schema readiness.
 * Responsibilities: Expose dormant storage orchestration and reject stale writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Loading the module never probes schema or initializes an identity.
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Require explicit cooperative enablement before any optional storage work.
 *
 * @return void Refuse disabled operations without inspecting schema or creating identities.
 */
function cooperative_require_enabled(): void
{
    if (!feature_capability_effective_enabled('cooperative_galleries')) {
        throw new CooperativeException('feature_disabled');
    }
}

/** Inspect exactly the named storage boundary, preserving missing and unknown states.
 *
 * @param string $area Allowlisted storage boundary to inspect.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_storage_status(string $area): array
{
    $definitions = [
        'albums' => ['cooperative_albums' => ['gallery_id', 'album_id']],
        'identity' => ['cooperative_identity' => ['singleton_id', 'instance_id']],
        'peers' => ['cooperative_peers' => ['instance_id', 'base_url', 'state', 'revision', 'incoming_hash', 'outgoing_cipher', 'confirmations_json', 'updated_at']],
        'revoke' => ['cooperative_peers' => ['instance_id', 'state', 'revision', 'incoming_hash']],
        'groups' => ['cooperative_groups' => ['group_id', 'storage_revision', 'state_json', 'updated_at']],
    ];
    if (!isset($definitions[$area])) {
        throw new CooperativeException('invalid_storage_area');
    }
    $requirements = [];
    foreach ($definitions[$area] as $table => $columns) {
        $requirements[] = schema_inspection_table($table);
        $requirements[] = schema_inspection_index($table, 'PRIMARY');
        foreach ($columns as $column) {
            $requirements[] = schema_inspection_column($table, $column);
        }
    }
    if ($area === 'albums') {
        $requirements[] = schema_inspection_index('cooperative_albums', 'cooperative_albums_public_id');
    }
    if ($area === 'identity') {
        $requirements[] = schema_inspection_index('cooperative_identity', 'cooperative_identity_instance');
    }
    return schema_inspection_feature('cooperative.' . $area, $requirements);
}

/** Fail closed before reading authority or mutating cooperative storage.
 *
 * @param string $area Allowlisted storage boundary to inspect.
 * @return void No return value; failure raises an exception.
 */
function cooperative_storage_assert(string $area): void
{
    $status = cooperative_storage_status($area);
    if (!schema_inspection_is_available($status)) {
        throw new CooperativeException(schema_inspection_is_missing($status) ? 'schema_missing' : 'schema_unknown');
    }
}

/** Lazily provision one durable installation ID with concurrent initialization safety.
 *
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_instance_id(): string
{
    cooperative_storage_assert('identity');
    return cooperative_id_validate(\Gallery\Models\cooperative_model_identity(cooperative_id_generate()));
}

/** Load a peer at an explicit expected revision for trusted local orchestration.
 *
 * @param string $remote Stable identity of the directly paired remote installation.
 * @param int $expected Expected optimistic storage revision, distinct from membership revision.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_peer_load_for_change(string $remote, int $expected): array
{
    cooperative_id_validate($remote);
    cooperative_storage_assert('peers');
    $peer = \Gallery\Models\cooperative_model_peer_find($remote);
    if ($peer === null || $expected < 1 || $peer['revision'] !== $expected) {
        throw new CooperativeException('revision_conflict');
    }
    return $peer;
}

/** Persist an already validated peer transition using compare-and-set.
 *
 * @param array<string,mixed> $peer Internal peer record containing pairing state and credential material.
 * @param int $expected Expected optimistic storage revision, distinct from membership revision.
 * @return void No return value; failure raises an exception.
 */
function cooperative_peer_save(array $peer, int $expected): void
{
    if (!\Gallery\Models\cooperative_model_peer_save($peer, $expected, gmdate('Y-m-d H:i:s'))) {
        throw new CooperativeException('revision_conflict');
    }
}

/** Persist a new local coordinator group without publishing any content.
 *
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_create(): array
{
    cooperative_storage_assert('groups');
    $group = cooperative_group_new(cooperative_id_generate(), cooperative_instance_id());
    \Gallery\Models\cooperative_model_group_insert($group, gmdate('Y-m-d H:i:s'));
    return ['storage_revision' => 1, 'group' => $group];
}

/**
 * Apply a trusted domain operation under optimistic concurrency.
 * Callback input is persisted local state, never a remote snapshot. Callers use
 * the proposal reducers below; a callback is an internal orchestration seam,
 * not an HTTP/API input. Failed concurrent operations persist nothing.
 *
 * @param string $id Stable public identifier of the persisted domain object.
 * @param int $expected Expected optimistic storage revision, distinct from membership revision.
 * @param callable $operation Trusted internal operation; never selected from a remote callback payload.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_update(string $id, int $expected, callable $operation): array
{
    cooperative_id_validate($id);
    cooperative_storage_assert('groups');
    $stored = \Gallery\Models\cooperative_model_group_find($id);
    if ($stored === null || $expected < 1 || $stored['storage_revision'] !== $expected) {
        throw new CooperativeException('revision_conflict');
    }
    $group = $operation($stored['group']);
    if (!is_array($group) || ($group['group_id'] ?? null) !== $id
        || ($group['coordinator_id'] ?? null) !== $stored['group']['coordinator_id']) {
        throw new CooperativeException('invalid_group_transition');
    }
    if (!\Gallery\Models\cooperative_model_group_save($group, $expected, gmdate('Y-m-d H:i:s'))) {
        throw new CooperativeException('revision_conflict');
    }
    return ['storage_revision' => $expected + 1, 'group' => $group];
}
/** Lazily assign an opaque album ID after the caller authorizes local gallery ownership.
 *
 * @param int $galleryId Existing local gallery primary key, after ownership authorization.
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_album_identity(int $galleryId): string
{
    if ($galleryId < 1) {
        throw new CooperativeException('invalid_gallery');
    }
    cooperative_storage_assert('albums');
    return cooperative_id_validate(\Gallery\Models\cooperative_model_album_identity($galleryId, cooperative_id_generate()));
}

/** Resolve a local album reference; callers still enforce the canonical source policy.
 *
 * @param string $albumId Stable opaque public album identity.
 * @return ?int Resolved local gallery ID, or null when absent.
 */
function cooperative_album_local_id(string $albumId): ?int
{
    cooperative_id_validate($albumId);
    cooperative_storage_assert('albums');
    return \Gallery\Models\cooperative_model_album_local_id($albumId);
}

/** Read persisted local group state for orchestration, never as proof from another server.
 *
 * @param string $id Stable public identifier of the persisted domain object.
 * @return array<string,mixed>|null Validated domain state, or null when no usable record exists.
 */
function cooperative_group_read(string $id): ?array
{
    cooperative_id_validate($id);
    cooperative_storage_assert('groups');
    return \Gallery\Models\cooperative_model_group_find($id);
}
