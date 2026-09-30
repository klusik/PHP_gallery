<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_proposals_exchange_test.php
 * Module Type: Regression Test
 * Purpose: Exercise independently owned album decisions across isolated installations.
 * Responsibilities: Verify immutable delivery, direct authority, stale consent and non-activation under retries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/cooperative_exchange_fixture.php';

use Gallery\Services as S;
use Gallery\Models as M;

exchange_fixture();
    exchange_friend('a', 'b');
    exchange_friend('b', 'c');
    exchange_friend('a', 'd');
    $a = $GLOBALS['exchange_nodes']['a']['id'];
    $b = $GLOBALS['exchange_nodes']['b']['id'];
    $c = $GLOBALS['exchange_nodes']['c']['id'];
    $members = [$GLOBALS['exchange_nodes']['a']['member'], $GLOBALS['exchange_nodes']['b']['member'], $GLOBALS['exchange_nodes']['c']['member']];
    exchange_node('a');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['action' => 'prepare', 'gallery_id' => 1, 'scopes' => '["metadata","preview"]'];
    $prepared = exchange_http();
    exchange_check($prepared['ok'] && $prepared['mutation']['entity_ids'] === [1] && $prepared['member'] === $members[0], 'Admin source preparation lost canonical envelope or identity.');
    $groupId = S\cooperative_id_generate();
    $_POST = ['action' => 'create', 'request_id' => $groupId, 'members' => json_encode($members)];
    $created = exchange_http();
    exchange_check($created['ok'] && $created['contexts'] === [] && $created['proposal']['own']['decision'] === 'pending', 'Creation implicitly approved or published content.');
    $state = $created['proposal'];
    $digest = $state['digest'];
    exchange_check(S\cooperative_proposal_exchange_create($groupId, array_reverse($members))['digest'] === $digest, 'Create retry changed immutable proposal.');
    $changed = $members;
    $changed[1]['scopes'] = ['metadata'];
    exchange_refuses(/** Retry with a different exact intent. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_proposal_exchange_create($groupId, $changed), 'request_conflict');
    exchange_refuses(/** Attempt local approval before completing direct friendships. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_proposal_exchange_decide($groupId, $state['revision'], $digest, 'approved'), 'friendship_unavailable');
    $GLOBALS['exchange_drop'] = true;
    exchange_refuses(/** Lose an acknowledgement after the remote durable import. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_proposal_exchange_contact($groupId, $state['revision'], $b, 'offer'), 'peer_unavailable');
    exchange_node('b');
    $bBefore = exchange_state($groupId);
    exchange_check($bBefore['own']['decision'] === 'pending', 'Import consented for the recipient.');
    exchange_node('a');
    $state = S\cooperative_proposal_exchange_contact($groupId, $state['revision'], $b, 'offer');
    exchange_node('b');
    exchange_check(exchange_state($groupId)['revision'] === $bBefore['revision'], 'Repeated delivery rewrote recipient state.');
    $bState = S\cooperative_proposal_exchange_decide($groupId, $bBefore['revision'], $digest, 'approved');
    exchange_refuses(/** A member cannot deliver as coordinator. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_proposal_exchange_contact($groupId, $bState['revision'], $c, 'offer'), 'proposal_unauthorized');
    exchange_friend('a', 'c');
    exchange_node('a');
    $state = S\cooperative_proposal_exchange_contact($groupId, $state['revision'], $c, 'offer');
    $state = S\cooperative_proposal_exchange_decide($groupId, $state['revision'], $digest, 'approved');
    exchange_node('c');
    $cState = exchange_state($groupId);
    S\cooperative_proposal_exchange_decide($groupId, $cState['revision'], $digest, 'approved');
    foreach (['a', 'b', 'c'] as $name) {
        exchange_node($name);
        $current = exchange_state($groupId);
        foreach ([$a, $b, $c] as $remote) {
            if ($remote !== S\cooperative_instance_id()) {
                $current = S\cooperative_proposal_exchange_contact($groupId, $current['revision'], $remote, 'decision');
                exchange_check($current['observations'][$remote]['decision'] === 'approved', 'Direct member decision was not independently observed.');
            }
        }
        $raw = S\cooperative_group_read($groupId)['group'];
        exchange_check(!$current['sharing_active'] && $raw['state'] === 'pending' && $raw['members'] === []
            && count($raw['pending']['approvals']) === 1, 'Observations fabricated remote approvals or activated sharing.');
    }
    exchange_node('a');
    $state = exchange_state($groupId);
    foreach (['actor', 'forwarded'] as $tamper) {
        $GLOBALS['exchange_tamper'] = $tamper;
        exchange_refuses(/** Reject altered identity and forwarded consent claims. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_proposal_exchange_contact($groupId, $state['revision'], $b, 'decision'), 'peer_response_invalid');
        exchange_check(exchange_state($groupId)['revision'] === $state['revision'], 'Rejected response changed local state.');
    }
    $GLOBALS['exchange_tamper'] = '';
    $wire = ['protocol' => 1, 'action' => 'decision', 'sender_id' => $b, 'recipient_id' => $a, 'group_id' => $groupId, 'digest' => $digest];
    exchange_refuses(/** A valid-format unrelated secret cannot read a proposal. @return array<string,mixed> Refused reply. */ static fn() => S\cooperative_proposal_receive($wire, S\cooperative_credential_generate()), 'proposal_unauthorized');
    exchange_node('d');
    $outsiderKey = S\cooperative_peer_outbound_credential($a);
    $outsiderId = S\cooperative_instance_id();
    exchange_node('a');
    $outsiderWire = array_replace($wire, ['sender_id' => $outsiderId]);
    exchange_refuses(/** Friendship alone does not reveal another group's decisions. @return array<string,mixed> Refused reply. */ static fn() => S\cooperative_proposal_receive($outsiderWire, $outsiderKey), 'proposal_unauthorized');

    // A valid response must also lose against a concurrent change in direct peer trust.
    $GLOBALS['exchange_hook'] = /** Change the direct friendship generation during the request.
     * @return void Simulate local credential-state invalidation without changing proposal storage.
     */ static function () use ($b): void {
        $peer = M\cooperative_model_peer_find($b);
        exchange_check(M\cooperative_model_peer_save($peer, $peer['revision'], gmdate('Y-m-d H:i:s')), 'Fixture peer revision change failed.');
    };
    exchange_refuses(/** In-flight results cannot survive a changed direct friendship. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_proposal_exchange_contact($groupId, $state['revision'], $b, 'decision'), 'revision_conflict');
    exchange_check(exchange_state($groupId)['own']['decision'] === 'consent_stale', 'Changed friendship generation revived old consent.');
    $state = S\cooperative_proposal_exchange_decide($groupId, $state['revision'], $digest, 'approved');
    exchange_check($state['own']['decision'] === 'approved', 'Fresh explicit consent could not bind the new generation.');

    // A local decision made during an outbound request must win over its late response.
    $GLOBALS['exchange_hook'] = /** Decline after the remote response but before the initiating save.
     * @return void Simulate a concurrent local administrator mutation.
     */ static function () use ($groupId, $digest): void {
        $current = exchange_state($groupId);
        S\cooperative_proposal_exchange_decide($groupId, $current['revision'], $digest, 'declined');
    };
    exchange_refuses(/** The late response cannot overwrite concurrent decline. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_proposal_exchange_contact($groupId, $state['revision'], $b, 'decision'), 'revision_conflict');
    exchange_check(exchange_state($groupId)['own']['decision'] === 'declined', 'Concurrent decline was lost.');
    $declined = exchange_state($groupId);
    exchange_refuses(/** Decline is terminal for this exact initial proposal. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_proposal_exchange_decide($groupId, $declined['revision'], $digest, 'approved'), 'proposal_declined');
    exchange_node('b');
    $bState = exchange_state($groupId);
    $bState = S\cooperative_proposal_exchange_contact($groupId, $bState['revision'], $a, 'decision');
    exchange_check($bState['observations'][$a]['decision'] === 'declined', 'Direct decline did not propagate through observation.');
    \Gallery\Core\db()->exec('UPDATE galleries SET access_mode = "password" WHERE id = 1');
    exchange_check(exchange_state($groupId)['own']['decision'] === 'consent_stale', 'Approval bypassed current source protection.');
    \Gallery\Core\db()->exec('UPDATE galleries SET access_mode = "normal" WHERE id = 1');
    S\cooperative_peer_revoke($c);
    exchange_check(exchange_state($groupId)['own']['decision'] === 'consent_stale', 'Revocation retained effective consent.');

    // An exact replay preserves a recipient's terminal decline.
    exchange_node('c');
    $cState = exchange_state($groupId);
    S\cooperative_proposal_exchange_decide($groupId, $cState['revision'], $digest, 'declined');
    exchange_node('a');
    $tokenForC = S\cooperative_peer_outbound_credential($c);
    $offer = ['protocol' => 1, 'action' => 'offer', 'sender_id' => $a, 'recipient_id' => $c, 'group_id' => $groupId, 'digest' => $digest, 'body' => $state['body']];
    exchange_node('c');
    exchange_check(S\cooperative_proposal_receive($offer, $tokenForC)['decision'] === 'declined', 'Duplicate offer revived a declined proposal.');
    \Gallery\Core\db()->exec('UPDATE galleries SET visibility = "private" WHERE id = 1');
    exchange_check(S\cooperative_proposal_receive($offer, $tokenForC)['decision'] === 'declined', 'Exact replay lost a terminal decision after source protection changed.');
    \Gallery\Core\db()->exec('UPDATE galleries SET visibility = "public" WHERE id = 1');
    $changedOffer = $offer;
    $changedOffer['body']['members'][0]['scopes'] = ['metadata'];
    exchange_refuses(/** A changed body cannot borrow the old digest. @return array<string,mixed> Refused reply. */ static fn() => S\cooperative_proposal_receive($changedOffer, $tokenForC), 'proposal_mismatch');
    $changedOffer['digest'] = S\cooperative_proposal_digest($changedOffer['body']);
    exchange_refuses(/** Rehashing cannot replace the immutable stored proposal. @return array<string,mixed> Refused reply. */ static fn() => S\cooperative_proposal_receive($changedOffer, $tokenForC), 'request_conflict');
    $extraOffer = $offer + ['approvals' => [$a => $digest]];
    exchange_refuses(/** Peers cannot inject consent through extra envelope fields. @return array<string,mixed> Refused reply. */ static fn() => S\cooperative_proposal_receive($extraOffer, $tokenForC), 'invalid_message');
    $expiredOffer = $offer;
    $expiredOffer['group_id'] = $expiredOffer['body']['group_id'] = S\cooperative_id_generate();
    $expiredOffer['body']['issued_at'] = time() - 100;
    $expiredOffer['body']['expires_at'] = time() - 1;
    $expiredOffer['digest'] = S\cooperative_proposal_digest($expiredOffer['body']);
    exchange_refuses(/** Expired imports grant nothing and persist no new group. @return array<string,mixed> Refused reply. */ static fn() => S\cooperative_proposal_receive($expiredOffer, $tokenForC), 'proposal_expired');
    exchange_check(S\cooperative_group_read($expiredOffer['group_id']) === null, 'Expired proposal was persisted.');
    $expansion = $offer;
    $expansion['body']['base_revision'] = 1;
    $expansion['digest'] = S\cooperative_proposal_digest($expansion['body']);
    exchange_refuses(/** Initial exchange cannot reinterpret an expansion as an initial grant. @return array<string,mixed> Refused reply. */ static fn() => S\cooperative_proposal_receive($expansion, $tokenForC), 'proposal_revision_unsupported');

    $nonMemberCoordinator = $offer;
    $nonMemberCoordinator['group_id'] = $nonMemberCoordinator['body']['group_id'] = S\cooperative_id_generate();
    foreach ($nonMemberCoordinator['body']['members'] as &$candidate) {
        if ($candidate['instance_id'] === $a) { $candidate['instance_id'] = str_repeat('e', 32); }
    }
    unset($candidate);
    $nonMemberCoordinator['digest'] = S\cooperative_proposal_digest($nonMemberCoordinator['body']);
    exchange_refuses(/** Reject a coordinator absent from the proposed membership before persistence. @return array<string,mixed> Refused reply. */ static fn() => S\cooperative_proposal_receive($nonMemberCoordinator, $tokenForC), 'coordinator_not_member');
    exchange_check(S\cooperative_group_read($nonMemberCoordinator['group_id']) === null, 'Invalid coordinator persisted a group before refusal.');

    exchange_node('a');
    $calls = $GLOBALS['exchange_calls'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $inbox = exchange_http('Gallery\\Controllers\\cms_admin_cooperative_proposals');
    exchange_check($inbox['ok'] && count($inbox['state']['items']) === 1 && $GLOBALS['exchange_calls'] === $calls, 'Inbox performed network work or lost persisted proposal.');
    $json = json_encode($inbox, JSON_THROW_ON_ERROR);
    exchange_check(!str_contains($json, 'pgc_') && !str_contains($json, 'incoming_hash') && !str_contains($json, 'outgoing_cipher'), 'Proposal projection exposed system credentials.');
    exchange_check(exchange_http('Gallery\\Controllers\\cms_cooperative_proposal_api')['error_code'] === 'method_not_allowed', 'Peer proposal endpoint exposed GET discovery.');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['action' => 'approve', 'group_id' => $groupId, 'revision' => $declined['revision'], 'digest' => $digest];
    $GLOBALS['exchange_csrf'] = false;
    try { exchange_http(); throw new \RuntimeException('Missing CSRF accepted.'); }
    catch (\RuntimeException $error) { exchange_check($error->getMessage() === 'csrf_required', 'CSRF boundary failed.'); }
    $GLOBALS['exchange_csrf'] = true;
    $GLOBALS['exchange_admin'] = false;
    try { exchange_http(); throw new \RuntimeException('Anonymous decision accepted.'); }
    catch (\RuntimeException $error) { exchange_check($error->getMessage() === 'admin_required', 'Admin boundary failed.'); }
    $GLOBALS['exchange_admin'] = true;
    $GLOBALS['exchange_enabled'] = false;
    $before = $GLOBALS['exchange_db_calls'];
    $disabled = exchange_http();
    exchange_check(!$disabled['ok'] && http_response_code() === 404 && $GLOBALS['exchange_db_calls'] === $before, 'Disabled proposal path touched optional storage.');
    $GLOBALS['exchange_enabled'] = true;
    S\schema_inspection_set_query_executor_for_tests(/** Observe a confirmed missing proposal store.
     * @param string $type Inspected schema object type.
     * @param string $table Inspected schema table.
     * @return bool Whether this fixture object exists.
     */ static fn(string $type, string $table): bool => $table !== 'cooperative_groups');
    $before = $GLOBALS['exchange_db_calls'];
    $missing = exchange_http();
    exchange_check(!$missing['ok'] && $missing['error_code'] === 'cooperative.schema_missing'
        && $GLOBALS['exchange_db_calls'] === $before, 'Missing proposal storage caused partial work.');
    S\schema_inspection_set_query_executor_for_tests(/** Inject an unavailable schema observation. @return never Fail without exposing diagnostics. */ static fn() => throw new \RuntimeException('private database diagnostic'));
    $before = $GLOBALS['exchange_db_calls'];
    $unavailable = exchange_http();
    exchange_check(!$unavailable['ok'] && http_response_code() === 503 && $GLOBALS['exchange_db_calls'] === $before
        && !str_contains(json_encode($unavailable), 'private database'), 'Unknown schema did not fail before proposal reads/writes.');
    echo "PASS cooperative proposals exchange\n";
