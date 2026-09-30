<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/maintenance.php
 * Module Type: Service
 * Purpose: Resume bounded verification and renew previously activated collaborations.
 * Responsibilities: Select one durable next step without inventing consent or extending evidence lifetime.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Plan verification from current local evidence without network calls or writes.
 * @param array<string,mixed> $group Trusted persisted initial collaboration aggregate.
 * @return array{action:string,peer_id:?string,retry_at:?int,reason:?string} Safe orchestration hint; execution rechecks storage.
 */
function cooperative_maintenance_plan(array $group): array
{
    $plan = ['action' => 'blocked', 'peer_id' => null, 'retry_at' => null, 'reason' => null];
    cooperative_require_enabled();
    $own = cooperative_proposal_own_decision($group);
    if ($own['decision'] !== 'approved') {
        $plan['reason'] = $own['decision'];
        return $plan;
    }
    $retryAt = cooperative_verification_wait_until($group);
    if ($retryAt !== null) {
        $plan['action'] = 'waiting';
        $plan['retry_at'] = $retryAt;
        return $plan;
    }
    if (cooperative_activation_lease_valid($group)) {
        $renewAt = $group['exchange']['lease']['expires_at'] - COOPERATIVE_RENEWAL_WINDOW;
        $plan['action'] = time() < $renewAt ? 'ready' : 'start';
        $plan['retry_at'] = $renewAt;
        return $plan;
    }
    $round = $group['exchange']['verification'] ?? null;
    try {
        if (!is_array($round)) {
            throw new CooperativeException('verification_unavailable');
        }
        $round = cooperative_verification_round($group, $round['round_id']);
    } catch (CooperativeException $error) {
        if (!in_array($error->reason, ['verification_unavailable', 'verification_expired'], true)) {
            throw $error;
        }
        $plan['action'] = 'start';
        return $plan;
    }
    foreach (array_keys($round['own_generations']) as $remote) {
        $receipt = $round['receipts'][$remote] ?? null;
        if (is_array($receipt) && ($receipt['decision'] ?? '') === 'approved') {
            continue;
        }
        $retryAt = is_array($receipt) ? $receipt['observed_at'] + COOPERATIVE_CONSENT_RETRY_DELAY : null;
        $plan['action'] = $retryAt !== null && time() < $retryAt ? 'waiting' : 'verify';
        $plan['peer_id'] = $remote;
        $plan['retry_at'] = $retryAt;
        $plan['reason'] = is_array($receipt) ? 'consent_missing' : null;
        return $plan;
    }
    $plan['action'] = 'finalize';
    return $plan;
}

/** Advance exactly one verification operation using the current compare-and-swap revision.
 * This operation never approves, delivers proposals or changes the selected membership.
 * @param string $groupId Existing locally approved collaboration identity.
 * @param int $expected Storage revision observed by the caller before this step.
 * @return array<string,mixed> Updated projection with the next safe maintenance hint.
 */
function cooperative_maintenance_step(string $groupId, int $expected): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    if ($stored['storage_revision'] !== $expected) {
        throw new CooperativeException('revision_conflict');
    }
    $group = $stored['group'];
    $plan = cooperative_maintenance_plan($group);
    return match ($plan['action']) {
        'start' => cooperative_verification_start($groupId, $expected),
        'verify' => cooperative_verification_peer($groupId, $expected, $group['exchange']['verification']['round_id'], $plan['peer_id']),
        'finalize' => cooperative_activation_finalize($groupId, $expected, $group['exchange']['verification']['round_id']),
        default => cooperative_proposal_exchange_projection($stored),
    };
}

/** Renew one already activated local group in a finite pass suitable for a scheduler.
 * Stop on a negative consent, a conflict or a transport error; a later invocation may
 * resume persisted progress. A valid lease outside the renewal window needs no writes.
 * @param string $groupId Explicit active group selected by the installation operator.
 * @return array<string,mixed> Current projection; waiting/blocked means no usable renewed grant.
 */
function cooperative_maintenance_renew(string $groupId): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    if ($stored['group']['state'] !== 'active') {
        throw new CooperativeException('proposal_not_active');
    }
    $state = cooperative_proposal_exchange_projection($stored);
    // One start, at most one check per other member, and one finalization.
    $steps = count($state['body']['members']) + 1;
    for ($step = 0; $step < $steps; $step++) {
        if (in_array($state['maintenance']['action'], ['ready', 'waiting', 'blocked'], true)) {
            return $state;
        }
        // Do not start a second round in the same pass if collection crossed its deadline.
        if ($step > 0 && $state['maintenance']['action'] === 'start') {
            return $state;
        }
        $operation = $state['maintenance']['action'];
        $state = cooperative_maintenance_step($groupId, $state['revision']);
        if ($operation === 'finalize' && $state['sharing_active']) {
            return $state;
        }
    }
    return $state;
}
