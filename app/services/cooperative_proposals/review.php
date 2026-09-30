<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/review.php
 * Module Type: Service
 * Purpose: Prepare a bounded review inbox with explicit local decision opportunities.
 * Responsibilities: Resolve local album labels and paired identities without contacting participants.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Prepare safe review data for a page of initial proposals without granting consent.
 * @param string $cursor Exclusive opaque group cursor, empty for the first page.
 * @param string $focusGroup Optional locally completed mutation to keep visible outside the current page.
 * @return array{items:list<array<string,mixed>>,next_cursor:?string} Bounded review rows and continuation cursor.
 */
function cooperative_proposal_review_inbox(string $cursor = '', string $focusGroup = ''): array
{
    $page = cooperative_proposal_exchange_inbox($cursor);
    if ($focusGroup !== '' && !in_array($focusGroup, array_column($page['items'], 'group_id'), true)) {
        array_unshift($page['items'], cooperative_proposal_exchange_projection(cooperative_proposal_exchange_load($focusGroup)));
    }
    if ($page['items'] === []) {
        return $page;
    }
    $local = cooperative_instance_id();
    $rows = [];
    foreach ($page['items'] as $proposal) {
        $gallery = \Gallery\Models\gallery_model_find_by_id($proposal['gallery_id']);
        $live = $proposal['body']['expires_at'] > time();
        $actions = [];
        if ($proposal['state'] === 'pending' && $live && in_array($proposal['own']['decision'], ['pending', 'consent_stale'], true)) {
            $actions[] = 'approve';
        }
        if (($proposal['state'] === 'pending' && $live || $proposal['state'] === 'active') && $proposal['own']['decision'] !== 'declined') {
            $actions[] = 'decline';
        }
        if (in_array($proposal['maintenance']['action'], ['start', 'verify', 'finalize'], true)) {
            $actions[] = 'advance';
        }
        if (cooperative_proposal_workflow_next($proposal) !== null) { $actions[] = 'synchronize'; }
        $participants = [];
        foreach ($proposal['body']['members'] as $member) {
            $isLocal = $member['instance_id'] === $local;
            $peer = $isLocal ? null : cooperative_peer_status($member['instance_id']);
            $peerActions = [];
            if (!$isLocal && ($peer['state'] ?? '') === 'active') {
                if ($proposal['body']['coordinator_id'] === $local && $proposal['state'] === 'pending' && $live) {
                    $peerActions[] = 'deliver';
                    $peerActions[] = 'email';
                }
                if ($proposal['state'] !== 'pending' || $live) {
                    $peerActions[] = 'refresh';
                }
            }
            $participants[] = ['instance_id' => $member['instance_id'], 'album_id' => $member['album_id'],
                'mail_accepted' => (bool) ($proposal['mail'][$member['instance_id']]['accepted'] ?? false),
                'local' => $isLocal, 'base_url' => $peer['base_url'] ?? '', 'scopes' => $member['scopes'],
                'decision' => $isLocal ? $proposal['own']['decision'] : ($proposal['observations'][$member['instance_id']]['decision'] ?? 'unknown'),
                'observed_at' => $isLocal ? null : ($proposal['observations'][$member['instance_id']]['observed_at'] ?? null),
                'actions' => $peerActions];
        }
        $rows[] = ['proposal' => $proposal, 'title' => (string) ($gallery['title'] ?? ''),
            'participants' => $participants, 'actions' => $actions];
    }
    return ['items' => $rows, 'next_cursor' => $page['next_cursor']];
}
