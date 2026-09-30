<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/decisions.php
 * Module Type: Service
 * Purpose: Own local consent and project direct-peer observations without activating access.
 * Responsibilities: Bind decisions to exact digests and current local credential generations.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Read this participant's current direct credential generations for every other member.
 * @param array<string,mixed> $body Canonical proposal membership.
 * @return array<string,int> Sorted generations owned by this installation, without peer secrets.
 */
function cooperative_proposal_local_generations(array $body): array
{
    $local = cooperative_instance_id();
    $generations = [];
    foreach ($body['members'] as $member) {
        if ($member['instance_id'] === $local) {
            continue;
        }
        $peer = cooperative_peer_status($member['instance_id']);
        if ($peer === null || $peer['state'] !== 'active') {
            throw new CooperativeException('friendship_unavailable');
        }
        $generations[$member['instance_id']] = $peer['revision'];
    }
    ksort($generations, SORT_STRING);
    return $generations;
}

/** Describe only this installation's decision, rechecking source and friendship state.
 * @param array<string,mixed> $group Locally persisted initial exchange aggregate.
 * @return array{decision:string,generations:array<string,int>} Effective own consent, never forwarded votes.
 */
function cooperative_proposal_own_decision(array $group): array
{
    $pending = cooperative_proposal_document($group);
    $decision = $group['exchange']['decision'];
    if ($decision === 'declined') {
        return ['decision' => 'declined', 'generations' => []];
    }
    try {
        if ($group['state'] === 'pending') {
            cooperative_group_pending($group, $pending['digest'], time());
        }
    } catch (CooperativeException) {
        return ['decision' => 'expired', 'generations' => []];
    }
    if ($group['state'] === 'suspended') {
        return ['decision' => 'consent_stale', 'generations' => []];
    }
    if ($decision !== 'approved') {
        return ['decision' => 'pending', 'generations' => []];
    }
    try {
        cooperative_proposal_source_assert($pending['body']);
        $generations = cooperative_proposal_local_generations($pending['body']);
        if ($generations !== $group['exchange']['generations']) {
            return ['decision' => 'consent_stale', 'generations' => []];
        }
    } catch (\Throwable) {
        return ['decision' => 'consent_stale', 'generations' => []];
    }
    return ['decision' => 'approved', 'generations' => $generations];
}

/** Decide only for the authenticated local administrator's own album.
 * @param string $groupId Existing initial group identity.
 * @param int $expected Local storage revision shown to the administrator.
 * @param string $digest Exact immutable proposal digest the administrator reviewed.
 * @param string $decision Explicit approved or declined decision.
 * @return array<string,mixed> Updated non-authorizing Admin projection.
 */
function cooperative_proposal_exchange_decide(string $groupId, int $expected, string $digest, string $decision): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    if ($stored['storage_revision'] !== $expected) {
        throw new CooperativeException('revision_conflict');
    }
    if (!in_array($decision, ['approved', 'declined'], true)) {
        throw new CooperativeException('invalid_decision');
    }
    $group = $stored['group'];
    $pending = cooperative_proposal_document($group);
    if (!hash_equals($pending['digest'], $digest)) {
        throw new CooperativeException('proposal_mismatch');
    }
    if ($decision === 'approved' && $group['state'] !== 'pending') {
        throw new CooperativeException('proposal_already_active');
    }
    if ($group['state'] === 'pending') {
        cooperative_group_pending($group, $digest, time());
    }
    if ($group['exchange']['decision'] === 'declined') {
        if ($decision !== 'declined') {
            throw new CooperativeException('proposal_declined');
        }
        return cooperative_proposal_exchange_projection($stored);
    }
    $local = cooperative_instance_id();
    $generations = [];
    if ($decision === 'approved') {
        cooperative_proposal_source_assert($pending['body']);
        $generations = cooperative_proposal_local_generations($pending['body']);
        $group = cooperative_group_approve($group, $local, $digest, time());
    } else {
        if ($group['state'] === 'active') {
            $group = cooperative_group_suspend($group);
        } elseif (is_array($group['pending'])) {
            unset($group['pending']['approvals'][$local]);
        }
    }
    $group['exchange']['verification'] = null;
    $group['exchange']['lease'] = null;
    $group['exchange']['decision'] = $decision;
    if ($decision === 'declined') {
        $group['exchange']['withdrawal_pending'] = array_values(array_diff(array_column($pending['body']['members'], 'instance_id'), [$local]));
    }
    $group['exchange']['generations'] = $generations;
    return cooperative_proposal_exchange_projection(cooperative_proposal_exchange_save($stored, $group));
}

/** Project immutable body, own decision and explicitly non-authoritative observations.
 * @param array<string,mixed> $stored Persisted local snapshot and storage revision.
 * @return array<string,mixed> Safe Admin state with separate membership, verification and effective authorization status.
 */
function cooperative_proposal_exchange_projection(array $stored): array
{
    $group = $stored['group'];
    $document = cooperative_proposal_document($group);
    $verification = $group['exchange']['verification'] ?? null;
    return ['group_id' => $group['group_id'], 'revision' => $stored['storage_revision'],
        'body' => $document['body'], 'digest' => $document['digest'],
        'gallery_id' => $group['exchange']['gallery_id'],
        'own' => cooperative_proposal_own_decision($group),
        'mail' => $group['exchange']['mail'] ?? [],
        'withdrawal_pending' => $group['exchange']['withdrawal_pending'] ?? [],
        'observations' => $group['exchange']['observations'],
        'membership_revision' => $group['revision'], 'state' => $group['state'],
        'verification' => is_array($verification) ? ['round_id' => $verification['round_id'],
            'expires_at' => $verification['expires_at'], 'checked_peers' => array_keys($verification['receipts'])] : null,
        'authorization_expires_at' => $group['exchange']['lease']['expires_at'] ?? null,
        'sharing_active' => cooperative_activation_lease_valid($group),
        'maintenance' => cooperative_maintenance_plan($group)];
}
