<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/state.php
 * Module Type: Service
 * Purpose: Persist replay-safe initial proposals with local decision ownership.
 * Responsibilities: Reject replacement bodies, stale writers and imported group authority.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Preflight the optional proposal stores without exposing a partial new group.
 * @return void Refuse disabled or unavailable proposal storage.
 */
function cooperative_proposal_exchange_ready(): void
{
    cooperative_require_enabled();
    foreach (['identity', 'groups', 'peers', 'albums'] as $area) {
        cooperative_storage_assert($area);
    }
}

/** Resolve this installation's exact source from the immutable member list.
 * @param array<string,mixed> $body Canonical proposal body.
 * @return array<string,mixed> Local member reference, without granting content access.
 */
function cooperative_proposal_local_member(array $body): array
{
    $local = cooperative_instance_id();
    foreach ($body['members'] as $member) {
        if ($member['instance_id'] === $local) {
            return $member;
        }
    }
    throw new CooperativeException('actor_not_member');
}

/** Recheck a locally owned proposal source; preparation alone is not consent.
 * @param array<string,mixed> $body Canonical immutable proposal body.
 * @return int Authorized local source identity for internal Admin mutation metadata.
 */
function cooperative_proposal_source_assert(array $body): int
{
    $member = cooperative_proposal_local_member($body);
    $policy = cooperative_album_source_policy($member['album_id']);
    if (!$policy['allowed']) {
        throw new CooperativeException($policy['reason']);
    }
    $galleryId = cooperative_album_local_id($member['album_id']);
    if ($galleryId === null) {
        throw new CooperativeException('gallery_missing');
    }
    return $galleryId;
}

/** Read the immutable initial document before or after local activation.
 * @param array<string,mixed> $group Trusted locally persisted aggregate.
 * @return array<string,mixed> Original body and digest; archived documents grant no authority alone.
 */
function cooperative_proposal_document(array $group): array
{
    $document = $group['pending'] ?? $group['exchange']['document'] ?? null;
    if (!is_array($document) || !is_array($document['body'] ?? null) || !is_string($document['digest'] ?? null)) {
        throw new CooperativeException('proposal_mismatch');
    }
    return $document;
}

/** Load a locally persisted initial proposal or its activated/suspended revision.
 * @param string $groupId Opaque group identity.
 * @return array<string,mixed> Persisted group with its independent optimistic revision.
 */
function cooperative_proposal_exchange_load(string $groupId): array
{
    cooperative_proposal_exchange_ready();
    $stored = cooperative_group_read($groupId);
    if ($stored === null) {
        throw new CooperativeException('proposal_not_found');
    }
    $group = $stored['group'];
    if (($group['exchange']['format'] ?? '') !== 'initial-v1'
        || !in_array($group['state'], ['pending', 'active', 'suspended'], true)
        || ($group['state'] === 'pending' && ($group['revision'] !== 0 || !is_array($group['pending'])))
        || ($group['state'] === 'active' && ($group['revision'] !== 1 || $group['pending'] !== null))
        || ($group['state'] === 'suspended' && ($group['revision'] < 1 || $group['pending'] !== null))) {
        throw new CooperativeException('proposal_revision_unsupported');
    }
    $document = cooperative_proposal_document($group);
    $body = cooperative_proposal_body($document['body']);
    if ($body['base_revision'] !== 0 || $body['group_id'] !== $groupId
        || $body['coordinator_id'] !== $group['coordinator_id']
        || !hash_equals(cooperative_proposal_digest($body), $document['digest'])
        || ($group['state'] === 'active' && ($group['members'] !== $body['members'] || $group['last_proposal_digest'] !== $document['digest']))) {
        throw new CooperativeException('proposal_mismatch');
    }
    cooperative_proposal_local_member($body);
    return $stored;
}

/** Save a locally computed transition against the snapshot that produced it.
 * @param array<string,mixed> $stored Previously loaded aggregate and storage revision.
 * @param array<string,mixed> $group Locally computed replacement, never a received group snapshot.
 * @return array<string,mixed> Saved aggregate with its new storage revision.
 */
function cooperative_proposal_exchange_save(array $stored, array $group): array
{
    // Even an identical response must validate the pre-network storage revision.
    return cooperative_group_update($group['group_id'], $stored['storage_revision'],
        /** Return only the already computed local transition after storage CAS preflight.
         * @param array<string,mixed> $current Current stored aggregate.
         * @return array<string,mixed> Validated replacement for this same revision.
         */
        static fn(array $current): array => $group);
}

/** Attach locally empty decision state to a new immutable initial proposal.
 * @param array<string,mixed> $body Canonical initial body; no approvals or active state are imported.
 * @return array<string,mixed> Non-authorizing group aggregate.
 */
function cooperative_proposal_exchange_group(array $body): array
{
    if (!in_array($body['coordinator_id'], array_column($body['members'], 'instance_id'), true)) {
        throw new CooperativeException('coordinator_not_member');
    }
    if ($body['base_revision'] !== 0) {
        throw new CooperativeException('proposal_revision_unsupported');
    }
    $group = cooperative_group_new($body['group_id'], $body['coordinator_id']);
    $group['pending'] = ['body' => $body, 'digest' => cooperative_proposal_digest($body), 'approvals' => []];
    $group['exchange'] = ['format' => 'initial-v1', 'decision' => 'pending', 'generations' => [], 'observations' => []];
    return $group;
}

/** Create one retryable local initial proposal without automatically approving it.
 * @param string $requestId Stable client intent identity, also the new group ID.
 * @param list<array<string,mixed>> $members Exact prepared local and remote album references.
 * @return array<string,mixed> Nonsecret Admin proposal projection.
 */
function cooperative_proposal_exchange_create(string $requestId, array $members): array
{
    cooperative_proposal_exchange_ready();
    cooperative_id_validate($requestId);
    $members = cooperative_members_normalize($members);
    $local = cooperative_instance_id();
    $existing = cooperative_group_read($requestId);
    if ($existing !== null) {
        $stored = cooperative_proposal_exchange_load($requestId);
        if ($stored['group']['coordinator_id'] !== $local || cooperative_proposal_document($stored['group'])['body']['members'] !== $members) {
            throw new CooperativeException('request_conflict');
        }
        return cooperative_proposal_exchange_projection($stored);
    }
    $draft = cooperative_group_propose(cooperative_group_new($requestId, $local), $local, $members, time());
    $galleryId = cooperative_proposal_source_assert($draft['pending']['body']);
    $group = cooperative_proposal_exchange_group($draft['pending']['body']);
    $group['exchange']['gallery_id'] = $galleryId;
    \Gallery\Models\cooperative_model_group_insert_once($group, gmdate('Y-m-d H:i:s'));
    $stored = cooperative_proposal_exchange_load($requestId);
    if ($stored['group']['coordinator_id'] !== $local || cooperative_proposal_document($stored['group'])['body']['members'] !== $members) {
        throw new CooperativeException('request_conflict');
    }
    return cooperative_proposal_exchange_projection($stored);
}

/** Import only a directly authenticated coordinator's exact initial proposal.
 * @param array<string,mixed> $body Received canonical proposal body, never a group snapshot.
 * @param string $coordinator Identity authenticated with this installation's direct peer credential.
 * @param string $digest Exact wire digest supplied alongside the immutable body.
 * @return array<string,mixed> Existing or newly persisted non-authorizing proposal.
 */
function cooperative_proposal_exchange_import(array $body, string $coordinator, string $digest): array
{
    cooperative_proposal_exchange_ready();
    $body = cooperative_proposal_body($body);
    if ($body['coordinator_id'] !== $coordinator || !hash_equals(cooperative_proposal_digest($body), $digest)) {
        throw new CooperativeException('proposal_mismatch');
    }
    $group = cooperative_proposal_exchange_group($body);
    cooperative_group_pending($group, $digest, time());
    if (cooperative_group_read($body['group_id']) === null) {
        $group['exchange']['gallery_id'] = cooperative_proposal_source_assert($body);
        \Gallery\Models\cooperative_model_group_insert_once($group, gmdate('Y-m-d H:i:s'));
    }
    $stored = cooperative_proposal_exchange_load($body['group_id']);
    if (cooperative_proposal_document($stored['group'])['body'] !== $body || cooperative_proposal_document($stored['group'])['digest'] !== $digest) {
        throw new CooperativeException('request_conflict');
    }
    return $stored;
}

/** List a bounded inbox without contacting peers or treating observations as authority.
 * Purpose: Bound persisted inbox work. Type: integer. Units: groups per page.
 * Scope: Admin proposal inbox. Consumers: group model pagination.
 * Rationale: 20 proposals plus lookahead limit projection and source-policy work.
 * @param string $afterId Exclusive lexical group-ID cursor, empty for the first page.
 * @return array{items:list<array<string,mixed>>,next_cursor:?string} Pending exchange projections.
 */
function cooperative_proposal_exchange_inbox(string $afterId = ''): array
{
    cooperative_proposal_exchange_ready();
    if ($afterId !== '') {
        cooperative_id_validate($afterId);
    }
    $rows = \Gallery\Models\cooperative_model_groups_page($afterId, 21);
    $items = [];
    foreach (array_slice($rows, 0, 20) as $stored) {
        if (($stored['group']['exchange']['format'] ?? '') === 'initial-v1') {
            $items[] = cooperative_proposal_exchange_projection($stored);
        }
    }
    return ['items' => $items, 'next_cursor' => count($rows) > 20 ? $rows[19]['group']['group_id'] : null];
}
