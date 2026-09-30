<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/activation.php
 * Module Type: Service
 * Purpose: Activate initial membership only from complete fresh direct verification.
 * Responsibilities: Bound authority by a fixed lease and recheck local source and credentials on every metadata decision.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Finalize one complete verification round without contacting peers or extending its deadline.
 * @param string $groupId Exact initial collaboration identity.
 * @param int $expected Local revision after the final peer check.
 * @param string $roundId Current verification identity; completed retries retain its original expiry.
 * @return array<string,mixed> Local activation projection, independent of other installations' progress.
 */
function cooperative_activation_finalize(string $groupId, int $expected, string $roundId): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    $group = $stored['group'];
    $round = cooperative_verification_round($group, $roundId);
    if (($group['exchange']['lease']['round_id'] ?? '') === $roundId && cooperative_activation_lease_valid($group)) {
        return cooperative_proposal_exchange_projection($stored);
    }
    if ($stored['storage_revision'] !== $expected) {
        throw new CooperativeException('revision_conflict');
    }
    if (cooperative_verification_wait_until($group) !== null) {
        throw new CooperativeException('consent_missing');
    }
    $document = cooperative_proposal_document($group);
    $local = cooperative_instance_id();
    $generations = [$local => $round['own_generations']];
    foreach ($round['own_generations'] as $remote => $peerRevision) {
        $receipt = $round['receipts'][$remote] ?? null;
        if (!is_array($receipt) || ($receipt['decision'] ?? '') !== 'approved'
            || ($receipt['challenge'] ?? '') !== $roundId || ($receipt['peer_revision'] ?? null) !== $peerRevision
            || !is_int($receipt['observed_at'] ?? null) || $receipt['observed_at'] < $round['started_at']
            || $receipt['observed_at'] > time() || $receipt['observed_at'] >= $round['expires_at']) {
            throw new CooperativeException('consent_missing');
        }
        $generations[$remote] = $receipt['generations'];
    }
    ksort($generations, SORT_STRING);
    $evidence = cooperative_verification_evidence($document['body'], $generations);
    if ($group['state'] === 'pending') {
        foreach (array_keys($generations) as $actor) {
            $group = cooperative_group_approve($group, $actor, $document['digest'], time());
        }
        $group['exchange']['document'] = ['body' => $document['body'], 'digest' => $document['digest']];
        $group = cooperative_group_activate($group, $document['digest'], time(),
            /** Resolve only evidence built from both fresh directly verified endpoints.
             * @param string $first First canonical participant identity.
             * @param string $second Second canonical participant identity.
             * @return string|null Verified stamp, or absence of the required pair.
             */ static fn(string $first, string $second): ?string => $evidence[$first . ':' . $second] ?? null);
    } elseif ($group['state'] !== 'active' || $group['friendships'] !== $evidence) {
        throw new CooperativeException('friendship_changed');
    }
    $group['exchange']['lease'] = ['round_id' => $roundId, 'digest' => $document['digest'],
        'membership_revision' => $group['revision'], 'started_at' => $round['started_at'],
        'expires_at' => $round['expires_at'], 'generations' => $generations];
    return cooperative_proposal_exchange_projection(cooperative_proposal_exchange_save($stored, $group));
}

/** Decide whether the locally activated revision has a current source-safe verification lease.
 * @param array<string,mixed> $group Trusted local group, never a caller-supplied peer snapshot.
 * @return bool Whether this installation may consider the initial grant current.
 */
function cooperative_activation_lease_valid(array $group): bool
{
    try {
        cooperative_require_enabled();
        $lease = $group['exchange']['lease'] ?? null;
        if ($group['state'] !== 'active' || !is_array($lease) || $lease['membership_revision'] !== $group['revision']
            || !is_int($lease['started_at']) || !is_int($lease['expires_at'])
            || $lease['expires_at'] !== $lease['started_at'] + COOPERATIVE_VERIFICATION_TTL
            || time() < $lease['started_at'] || time() >= $lease['expires_at']) {
            return false;
        }
        $document = cooperative_proposal_document($group);
        $local = cooperative_instance_id();
        $own = cooperative_proposal_own_decision($group);
        return $lease['digest'] === $document['digest'] && $group['members'] === $document['body']['members']
            && $own['decision'] === 'approved' && ($lease['generations'][$local] ?? null) === $own['generations']
            && cooperative_verification_evidence($document['body'], $lease['generations']) === $group['friendships'];
    } catch (\Throwable) {
        return false;
    }
}

/** Authorize only local album metadata using a direct credential and exact active revision.
 * This helper does not authorize image files, thumbnails, previews or originals.
 * Individual-media routes additionally use the content module image policy.
 * @param string $groupId Locally activated initial group identity.
 * @param string $requester Direct requesting installation, verified here against its credential.
 * @param string $bearer Plaintext system token from the HTTP Authorization boundary.
 * @param string $albumId Requested locally owned opaque album identity.
 * @param int $revision Exact active membership revision requested by the peer.
 * @return bool Whether the local metadata read satisfies all cooperative and source boundaries.
 */
function cooperative_activation_allows_metadata(string $groupId, string $requester, string $bearer, string $albumId, int $revision): bool
{
    try {
        cooperative_require_enabled();
        if (cooperative_peer_authenticate($requester, $bearer) === null) { return false; }
        return cooperative_activation_allows_scope(cooperative_proposal_exchange_load($groupId)['group'],
            $requester, $albumId, $revision, 'metadata');
    } catch (\Throwable) { return false; }
}

/** Check the current local public grant, including a local viewer represented by this installation.
 * @param array<string,mixed> $group Trusted local aggregate.
 * @param string $requester Direct peer identity, already authenticated by the HTTP adapter, or local identity.
 * @param string $album Exact locally owned source.
 * @param int $revision Exact active membership revision.
 * @param string $scope Required operation, never inferred from administrator/session state.
 * @return bool Whether this precise operation is authorized now.
 */
function cooperative_activation_allows_scope(array $group, string $requester, string $album, int $revision, string $scope): bool
{
    if (!cooperative_activation_lease_valid($group) || $group['revision'] !== $revision) { return false; }
    $member = cooperative_proposal_local_member(cooperative_proposal_document($group)['body']);
    if ($member['album_id'] !== $album || !in_array($scope, $member['scopes'], true)
        || !in_array($requester, array_column($group['members'], 'instance_id'), true)) { return false; }
    return cooperative_album_source_policy($album)['allowed'];
}
