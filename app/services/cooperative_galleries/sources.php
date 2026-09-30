<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_galleries/sources.php
 * Module Type: Service
 * Purpose: Prepare local album candidates and immutable membership references.
 * Responsibilities: Require current anonymous source policy before identity creation; never grant sharing.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Require enabled collaboration and verified source schema before listing local albums.
 *
 * @return void Refuse without issuing identifiers or reading candidate labels.
 */
function cooperative_album_sources_assert_ready(): void
{
    cooperative_require_enabled();
    $status = gallery_public_export_schema_status();
    if (!schema_inspection_is_available($status)) {
        throw new CooperativeException(schema_inspection_is_missing($status) ? 'schema_missing' : 'schema_unknown');
    }
    if (schema_inspection_is_unknown(gallery_visibility_schema_status())) {
        throw new CooperativeException('schema_unknown');
    }
}

/** Prepare a bounded administrator-only source picker, including refusal reasons.
 * Purpose: Bound catalog reads. Type: integer. Units: rows. Scope: one Admin response.
 * Consumers: source picker model query. Rationale: 50 candidates plus one lookahead
 * keep traversal work bounded and avoid an empty follow-up page at exact boundaries.
 *
 * @param int $afterId Exclusive local gallery ID cursor.
 * @return array{items:list<array<string,mixed>>,next_cursor:?int} Safe labels and eligibility; no identity writes.
 */
function cooperative_album_sources_state(int $afterId = 0): array
{
    cooperative_album_sources_assert_ready();
    if ($afterId < 0) {
        throw new CooperativeException('invalid_cursor');
    }
    $rows = \Gallery\Models\gallery_model_source_picker_page($afterId, 51);
    $hasMore = count($rows) > 50;
    $items = [];
    foreach (array_slice($rows, 0, 50) as $row) {
        $id = (int) $row['id'];
        $policy = gallery_public_export_policy($id);
        $items[] = ['gallery_id' => $id, 'title' => (string) $row['title'],
            'eligible' => $policy['allowed'], 'reason' => $policy['reason']];
    }
    return ['items' => $items, 'next_cursor' => $hasMore ? $items[count($items) - 1]['gallery_id'] : null];
}

/** Prepare a local member reference after caller-owned Admin authorization.
 * This is not consent, activation or authority to read media. The future proposal
 * operation must revalidate the source when accepting consent and before export.
 *
 * @param int $galleryId Authorized local album selected by the administrator.
 * @param list<string> $scopes Exact proposed sharing scope; metadata is mandatory.
 * @return array{instance_id:string,album_id:string,scopes:list<string>} Canonical proposal member without local IDs.
 */
function cooperative_album_source_member(int $galleryId, array $scopes): array
{
    cooperative_album_sources_assert_ready();
    $scopes = cooperative_scopes_normalize($scopes);
    $policy = gallery_public_export_policy($galleryId);
    if (!$policy['allowed']) {
        throw new CooperativeException($policy['reason']);
    }
    // Preflight both stores before either lazy identity can be created.
    cooperative_storage_assert('identity');
    cooperative_storage_assert('albums');
    return ['instance_id' => cooperative_instance_id(), 'album_id' => cooperative_album_identity($galleryId), 'scopes' => $scopes];
}

/** Revalidate an opaque locally owned album for later consent/export orchestration.
 * Media authorization must additionally check each image and the exact group grant.
 *
 * @param string $albumId Opaque local album identity; remote albums cannot resolve here.
 * @return array{allowed:bool,reason:string} Current source eligibility without an administrator bypass.
 */
function cooperative_album_source_policy(string $albumId): array
{
    cooperative_album_sources_assert_ready();
    $galleryId = cooperative_album_local_id($albumId);
    return $galleryId === null ? ['allowed' => false, 'reason' => 'gallery_missing'] : gallery_public_export_policy($galleryId);
}
