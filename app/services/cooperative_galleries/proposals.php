<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_galleries/proposals.php
 * Module Type: Service
 * Purpose: Implement immutable, unanimous public-album membership transitions.
 * Responsibilities: Bind consents to revisions and refuse transitive or stale authority.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Pure reducers consume trusted local state. Peer identities must be authenticated
 *   by the future adapter; supplied coordinator claims are never proof of consent.
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Create an empty group; initial membership also requires unanimous consent.
 *
 * @param string $groupId Stable public identifier of this collaboration.
 * @param string $coordinator Stable installation ID of the initial coordinator.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_new(string $groupId, string $coordinator): array
{
    return [
        'group_id' => cooperative_id_validate($groupId),
        'coordinator_id' => cooperative_id_validate($coordinator),
        'revision' => 0,
        'state' => 'pending',
        'members' => [],
        'pending' => null,
        'last_proposal_digest' => null,
        'friendships' => [],
    ];
}

/** Construct a canonical proposal body with bounded lifetime and public audience only.
 *
 * @param array<string,mixed> $body Exact proposed membership, audience, timestamps and revision.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_proposal_body(array $body): array
{
    $keys = array_keys($body);
    sort($keys);
    if ($keys !== ['audience', 'base_revision', 'coordinator_id', 'expires_at', 'group_id', 'issued_at', 'members', 'proposal_id', 'protocol']
        || $body['protocol'] !== COOPERATIVE_PROTOCOL_VERSION || $body['audience'] !== 'public'
        || !is_int($body['base_revision']) || $body['base_revision'] < 0
        || !is_int($body['issued_at']) || !is_int($body['expires_at']) || $body['issued_at'] < 1
        || $body['expires_at'] <= $body['issued_at']
        || $body['expires_at'] - $body['issued_at'] > COOPERATIVE_MAX_PROPOSAL_LIFETIME
        || !is_array($body['members'])) {
        throw new CooperativeException('invalid_proposal');
    }
    foreach (['proposal_id', 'group_id', 'coordinator_id'] as $key) {
        if (!is_string($body[$key])) {
            throw new CooperativeException('invalid_proposal');
        }
        cooperative_id_validate($body[$key]);
    }
    return [
        'protocol' => COOPERATIVE_PROTOCOL_VERSION,
        'proposal_id' => $body['proposal_id'],
        'group_id' => $body['group_id'],
        'coordinator_id' => $body['coordinator_id'],
        'base_revision' => $body['base_revision'],
        'audience' => 'public',
        'issued_at' => $body['issued_at'],
        'expires_at' => $body['expires_at'],
        'members' => cooperative_members_normalize($body['members']),
    ];
}

/** Hash semantic proposal content identically regardless of input member/scope ordering.
 *
 * @param array<string,mixed> $body Exact proposed membership, audience, timestamps and revision.
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_proposal_digest(array $body): string
{
    return hash('sha256', json_encode(cooperative_proposal_body($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/**
 * Start initial membership or a one-member expansion without replacing active members.
 * Invitation itself grants no authority; the inviting actor records their own
 * album consent through the same authenticated approval operation as everyone else.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @param string $actor Participant identity established by trusted local or peer authentication.
 * @param list<array{instance_id:string,album_id:string,scopes:list<string>}> $members Participating installations with their album identities and granted scopes.
 * @param int $now Explicit current Unix timestamp in seconds.
 * @param int $lifetime Proposal validity in seconds, bounded by the protocol maximum.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_propose(array $group, string $actor, array $members, int $now, int $lifetime = 86400): array
{
    cooperative_id_validate($actor);
    if ($group['pending'] !== null) {
        throw new CooperativeException('proposal_unavailable');
    }
    $existingIds = array_column($group['members'], 'instance_id');
    if (($group['revision'] === 0 && $actor !== $group['coordinator_id'])
        || ($group['revision'] > 0 && !in_array($actor, $existingIds, true))) {
        throw new CooperativeException('actor_not_member');
    }
    $members = cooperative_members_normalize($members);
    if (!in_array($group['coordinator_id'], array_column($members, 'instance_id'), true)) {
        throw new CooperativeException('coordinator_not_member');
    }
    if ($group['revision'] > 0) {
        if ($members !== $group['members'] && count($members) !== count($group['members']) + 1) {
            throw new CooperativeException('expansion_required');
        }
        $byId = array_column($members, null, 'instance_id');
        foreach ($group['members'] as $member) {
            if (($byId[$member['instance_id']] ?? null) !== $member) {
                throw new CooperativeException('existing_consent_changed');
            }
        }
    }
    if ($lifetime < 1 || $lifetime > COOPERATIVE_MAX_PROPOSAL_LIFETIME) {
        throw new CooperativeException('invalid_lifetime');
    }
    $body = cooperative_proposal_body([
        'protocol' => COOPERATIVE_PROTOCOL_VERSION,
        'proposal_id' => cooperative_id_generate(),
        'group_id' => $group['group_id'],
        'coordinator_id' => $group['coordinator_id'],
        'base_revision' => $group['revision'],
        'audience' => 'public',
        'issued_at' => $now,
        'expires_at' => $now + $lifetime,
        'members' => $members,
    ]);
    $group['pending'] = ['body' => $body, 'digest' => cooperative_proposal_digest($body), 'approvals' => []];
    return $group;
}

/** Validate current proposal and compare the exact digest supplied with a decision.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @param string $digest SHA-256 digest of the exact immutable proposal being decided.
 * @param int $now Explicit current Unix timestamp in seconds.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_pending(array $group, string $digest, int $now): array
{
    $pending = $group['pending'];
    if (!is_array($pending) || !is_string($pending['digest'] ?? null)
        || !hash_equals($pending['digest'], $digest)
        || !hash_equals(cooperative_proposal_digest($pending['body']), $digest)) {
        throw new CooperativeException('proposal_mismatch');
    }
    $body = $pending['body'];
    if ($body['group_id'] !== $group['group_id'] || $body['coordinator_id'] !== $group['coordinator_id']
        || $body['base_revision'] !== $group['revision']) {
        throw new CooperativeException('revision_conflict');
    }
    if ($now < $body['issued_at'] || $now >= $body['expires_at']) {
        throw new CooperativeException('proposal_expired');
    }
    return $pending;
}

/**
 * Record one independently authenticated participant's exact consent.
 * Actor comes from local authorization or direct verified peer communication,
 * never from an unverified actor field in a forwarded message.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @param string $authenticatedActor Participant identity verified independently of the proposal payload.
 * @param string $digest SHA-256 digest of the exact immutable proposal being decided.
 * @param int $now Explicit current Unix timestamp in seconds.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_approve(array $group, string $authenticatedActor, string $digest, int $now): array
{
    $pending = cooperative_group_pending($group, $digest, $now);
    if (!in_array($authenticatedActor, array_column($pending['body']['members'], 'instance_id'), true)) {
        throw new CooperativeException('actor_not_member');
    }
    $pending['approvals'][$authenticatedActor] = $digest;
    $group['pending'] = $pending;
    return $group;
}

/** Decline/cancel as one named participant without disturbing active membership.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @param string $actor Participant identity established by trusted local or peer authentication.
 * @param string $digest SHA-256 digest of the exact immutable proposal being decided.
 * @param int $now Explicit current Unix timestamp in seconds.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_decline(array $group, string $actor, string $digest, int $now): array
{
    $pending = cooperative_group_pending($group, $digest, $now);
    if (!in_array($actor, array_column($pending['body']['members'], 'instance_id'), true)) {
        throw new CooperativeException('actor_not_member');
    }
    $group['pending'] = null;
    return $group;
}

/** Explicitly clear an expired proposal; no consent or active membership is created.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @param int $now Explicit current Unix timestamp in seconds.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_expire(array $group, int $now): array
{
    if ($group['pending'] !== null && $now >= $group['pending']['body']['expires_at']) {
        $group['pending'] = null;
    }
    return $group;
}

/**
 * Require verified current friendship evidence for every direct pair.
 * The resolver returns a SHA-256 evidence stamp over BOTH verified current
 * credential generations for a pair, or null for inactive/unknown/expired
 * evidence. It must query trusted state, never accept coordinator claims.
 *
 * @param list<array{instance_id:string,album_id:string,scopes:list<string>}> $members Participating installations with their album identities and granted scopes.
 * @param callable $resolve Trusted resolver of fresh pair-generation evidence; unknown state returns null.
 * @return array<string,mixed>|null Validated domain state, or null when no usable record exists.
 */
function cooperative_friendship_evidence(array $members, callable $resolve): ?array
{
    $evidence = [];
    foreach (cooperative_friendship_pairs($members) as [$first, $second]) {
        try {
            $stamp = $resolve($first, $second);
            if (!is_string($stamp) || preg_match('/\A[a-f0-9]{64}\z/', $stamp) !== 1) {
                return null;
            }
            $evidence[$first . ':' . $second] = $stamp;
        } catch (\Throwable) {
            return null;
        }
    }
    return $evidence;
}

/** Activate only unanimous exact consents and a complete current friendship clique.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @param string $digest SHA-256 digest of the exact immutable proposal being decided.
 * @param int $now Explicit current Unix timestamp in seconds.
 * @param callable $friendshipResolver Trusted resolver returning current pair-generation stamps or null.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_activate(array $group, string $digest, int $now, callable $friendshipResolver): array
{
    if ($group['pending'] === null && $group['state'] === 'active' && $group['last_proposal_digest'] === $digest) {
        return $group;
    }
    $pending = cooperative_group_pending($group, $digest, $now);
    foreach ($pending['body']['members'] as $member) {
        if (($pending['approvals'][$member['instance_id']] ?? null) !== $digest) {
            throw new CooperativeException('consent_missing');
        }
    }
    $evidence = cooperative_friendship_evidence($pending['body']['members'], $friendshipResolver);
    if ($evidence === null) {
        throw new CooperativeException('friendship_unavailable');
    }
    $group['friendships'] = $evidence;
    $group['members'] = $pending['body']['members'];
    $group['revision']++;
    $group['state'] = 'active';
    $group['last_proposal_digest'] = $digest;
    $group['pending'] = null;
    return $group;
}

/**
 * Decide whether the cooperative layer permits a local source read.
 * A true result is necessary, NOT sufficient: sourcePolicy must enforce current
 * gallery visibility, public audience, NSFW and media authorization. No remote
 * source may be re-exported, and friendship is rechecked on every decision.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @param string $local Stable identity of this installation, which owns the source content.
 * @param string $requester Authenticated identity of the directly requesting installation.
 * @param string $album Stable identity of the requested locally owned album.
 * @param int $revision Exact active membership revision requested by the peer.
 * @param string $scope Requested allowlisted operation on the local source album.
 * @param callable $friendshipResolver Trusted resolver returning current pair-generation stamps or null.
 * @param callable $sourcePolicy Canonical source-authorization adapter; only literal true grants access.
 * @return bool Whether the exact validation or authorization contract holds.
 */
function cooperative_group_allows(array $group, string $local, string $requester, string $album, int $revision,
    string $scope, callable $friendshipResolver, callable $sourcePolicy): bool
{
    if ($group['state'] !== 'active' || $group['revision'] !== $revision || $local === $requester) {
        return false;
    }
    $members = array_column($group['members'], null, 'instance_id');
    if (!isset($members[$local], $members[$requester]) || $members[$local]['album_id'] !== $album
        || !in_array($scope, $members[$local]['scopes'], true)
        || cooperative_friendship_evidence($group['members'], $friendshipResolver) !== $group['friendships']) {
        return false;
    }
    try {
        return $sourcePolicy($album, $scope, 'public') === true;
    } catch (\Throwable) {
        return false;
    }
}

/** Revoke one's own participation immediately; stale proposals are discarded.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @param string $actor Participant identity established by trusted local or peer authentication.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_leave(array $group, string $actor): array
{
    if (!in_array($actor, array_column($group['members'], 'instance_id'), true)) {
        throw new CooperativeException('actor_not_member');
    }
    $group['members'] = array_values(array_filter($group['members'], /**
 * Retain only current participant or friendship entries after departure.
 * @param array<string,mixed> $member Canonical participant and its album grant.
 * @return bool Whether the exact validation or authorization contract holds.
 */ static fn(array $member): bool => $member['instance_id'] !== $actor));
    $group['revision']++;
    $group['pending'] = null;
    $group['last_proposal_digest'] = null;
    $remaining = array_column($group['members'], 'instance_id');
    $group['friendships'] = array_filter($group['friendships'], /**
 * Retain friendship evidence only when both participants remain.
 * @param string $pair Canonical pair key containing both installation identifiers.
 * @return bool Whether the exact validation or authorization contract holds.
 */ static function (string $pair) use ($remaining): bool {
        [$first, $second] = explode(':', $pair);
        return in_array($first, $remaining, true) && in_array($second, $remaining, true);
    }, ARRAY_FILTER_USE_KEY);
    if (count($group['members']) < 2 || $actor === $group['coordinator_id']) {
        $group['state'] = 'suspended';
    }
    return $group;
}

/** Persist observed trust loss; renewed friendship alone must not revive old grants.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_group_suspend(array $group): array
{
    $group['state'] = 'suspended';
    $group['pending'] = null;
    $group['last_proposal_digest'] = null;
    $group['revision']++;
    return $group;
}