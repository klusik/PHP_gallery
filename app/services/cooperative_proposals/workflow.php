<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/workflow.php
 * Module Type: Service
 * Purpose: Complete explicit proposal delivery, synchronization and email invitations.
 * Responsibilities: Keep decisions explicit and email links non-authorizing.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/** Choose one delivery or verification operation; never synthesize local approval.
 * @param array<string,mixed> $state Current projection.
 * @return array{action:string,peer_id:string}|null Next bounded operation, or a stop.
 */
function cooperative_proposal_workflow_next(array $state): ?array
{
    if ($state['state'] === 'pending' && $state['body']['expires_at'] <= time()) { return null; }
    if ($state['own']['decision'] === 'declined') {
        return empty($state['withdrawal_pending']) ? null : ['action' => 'invalidate', 'peer_id' => $state['withdrawal_pending'][0]];
    }
    if ($state['state'] === 'pending' && $state['body']['coordinator_id'] === cooperative_instance_id()) {
        foreach ($state['body']['members'] as $member) {
            if ($member['instance_id'] !== cooperative_instance_id() && !isset($state['observations'][$member['instance_id']])) {
                return ['action' => 'deliver', 'peer_id' => $member['instance_id']];
            }
        }
    }
    return in_array($state['maintenance']['action'], ['start', 'verify', 'finalize'], true)
        ? ['action' => 'advance', 'peer_id' => ''] : null;
}

/** Perform one resumable step so the browser can keep the panel responsive.
 * @param string $groupId Exact proposal.
 * @param int $expected Revision shown in the current panel.
 * @return array<string,mixed> Updated projection; another step always uses its new revision.
 */
function cooperative_proposal_synchronize(string $groupId, int $expected): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    if ($stored['storage_revision'] !== $expected) { throw new CooperativeException('revision_conflict'); }
    $state = cooperative_proposal_exchange_projection($stored);
    $next = cooperative_proposal_workflow_next($state);
    if ($next === null) { return $state; }
    if ($next['action'] === 'invalidate') { return cooperative_proposal_notify_withdrawal($groupId, $expected, $next['peer_id']); }
    return $next['action'] === 'deliver'
        ? cooperative_proposal_exchange_contact($groupId, $expected, $next['peer_id'], 'offer')
        : cooperative_maintenance_step($groupId, $expected);
}

/** Expose test-only mail capture independently of real configured SMTP.
 * @return callable|null Fixture capture adapter, never selected from HTTP inputs.
 */
function &cooperative_invitation_mail_override(): mixed
{
    static $send = null;
    return $send;
}

/** Deliver the exact proposal and email a non-authorizing review link to its participant.
 * Email acceptance by the configured transport is not proof of inbox delivery or consent.
 * Purpose: Bound repeated sends. Type: int. Units: seconds. Scope: one proposal and participant.
 * Consumers: explicit Admin email action. Rationale: 60 seconds bound double clicks and uncertain retries.
 * @param string $groupId Existing immutable proposal.
 * @param int $expected Current local storage revision.
 * @param string $remote Explicit participant selected by the administrator.
 * @param string $email Explicit recipient address, not discovered or forwarded by a peer.
 * @return array<string,mixed> Updated proposal projection with nonsecret delivery state.
 */
function cooperative_proposal_email(string $groupId, int $expected, string $remote, string $email): array
{
    $email = trim($email);
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
        throw new CooperativeException('invalid_email');
    }
    $stored = cooperative_proposal_exchange_load($groupId);
    $group = $stored['group'];
    if ($stored['storage_revision'] !== $expected) { throw new CooperativeException('revision_conflict'); }
    $document = cooperative_proposal_document($group);
    if ($group['state'] !== 'pending' || $group['coordinator_id'] !== cooperative_instance_id()
        || $remote === cooperative_instance_id() || !in_array($remote, array_column($document['body']['members'], 'instance_id'), true)
        || $document['body']['expires_at'] <= time()) { throw new CooperativeException('proposal_unauthorized'); }
    $peer = cooperative_peer_status($remote);
    if (($peer['state'] ?? '') !== 'active') { throw new CooperativeException('friendship_unavailable'); }
    if (($group['exchange']['mail'][$remote]['attempted_at'] ?? 0) + 60 > time()) {
        throw new CooperativeException('mail_retry_later');
    }
    $send = &cooperative_invitation_mail_override();
    if (!is_callable($send)) {
        $settings = cms_password_reset_settings();
        if (!$settings['enabled'] || !filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)) {
            throw new CooperativeException('mail_unavailable');
        }
    }
    $state = cooperative_proposal_exchange_contact($groupId, $expected, $remote, 'offer');
    $stored = cooperative_proposal_exchange_load($groupId);
    if ($stored['storage_revision'] !== $state['revision']) { throw new CooperativeException('revision_conflict'); }
    $group = $stored['group'];
    $group['exchange']['mail'][$remote] = ['attempted_at' => time(), 'accepted' => false];
    $stored = cooperative_proposal_exchange_save($stored, $group);
    $url = cooperative_peer_base_url($peer['base_url']) . '/index.php?' . http_build_query([
        'page' => 'admin_cooperative_collaborations', 'focus_group' => $groupId]);
    $subject = t('cooperative.mail.subject', 'Invitation to an album collaboration');
    $body = t('cooperative.mail.body', 'An album collaboration is ready for your review. Sign in to your own gallery, check all participants and permissions, then explicitly accept or decline. Opening this link does not grant access.');
    $body .= "\n\n" . $url . "\n\n" . t('cooperative.mail.expires', 'Accept before (UTC):') . ' '
        . gmdate('Y-m-d H:i:s', $document['body']['expires_at']);
    try {
        $result = is_callable($send) ? $send($email, $subject, $body)
            : cms_send_configured_password_reset_mail($email, $subject, $body);
        $accepted = ($result['sent'] ?? false) === true;
    } catch (\Throwable) { $accepted = false; }
    $group['exchange']['mail'][$remote]['accepted'] = $accepted;
    $saved = cooperative_proposal_exchange_save($stored, $group);
    if (!$accepted) { throw new CooperativeException('mail_unavailable'); }
    return cooperative_proposal_exchange_projection($saved);
}

/** Notify one direct member after durable local withdrawal, retaining failed destinations for retry.
 * @param string $groupId Locally withdrawn collaboration.
 * @param int $expected Current local storage revision.
 * @param string $remote One member of the pending notification set.
 * @return array<string,mixed> Updated state; notification never changes another server's consent.
 */
function cooperative_proposal_notify_withdrawal(string $groupId, int $expected, string $remote): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    $group = $stored['group'];
    if ($stored['storage_revision'] !== $expected || $group['exchange']['decision'] !== 'declined'
        || !in_array($remote, $group['exchange']['withdrawal_pending'] ?? [], true)) {
        throw new CooperativeException('revision_conflict');
    }
    $peer = cooperative_peer_status($remote);
    if (($peer['state'] ?? '') === 'active') {
        $document = cooperative_proposal_document($group);
        $message = ['protocol' => COOPERATIVE_PROTOCOL_VERSION, 'action' => 'invalidate',
            'sender_id' => cooperative_instance_id(), 'recipient_id' => $remote, 'group_id' => $groupId, 'digest' => $document['digest']];
        cooperative_proposal_response_validate(cooperative_proposal_request($peer, $message), $message, $document['body']);
        if (cooperative_peer_status($remote) !== $peer) { throw new CooperativeException('revision_conflict'); }
    }
    $group['exchange']['withdrawal_pending'] = array_values(array_diff($group['exchange']['withdrawal_pending'], [$remote]));
    return cooperative_proposal_exchange_projection(cooperative_proposal_exchange_save($stored, $group));
}
