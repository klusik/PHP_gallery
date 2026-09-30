<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_activation_test.php
 * Module Type: Regression Test
 * Purpose: Verify fresh unanimous activation and bounded local metadata authorization.
 * Responsibilities: Cover nonce replay, partial activation, leases, revocation and concurrent proof invalidation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/cooperative_exchange_fixture.php';
use Gallery\Services as S;
use Gallery\Models as M;

/** Collect a fresh full verification round at the current installation.
 * @param string $id Exact group to verify.
 * @return array<string,mixed> Current state after collecting all remote participants, before activation.
 */
function activation_collect(string $id): array
{
    $state = exchange_state($id);
    $state = S\cooperative_verification_start($id, $state['revision']);
    $round = $state['verification']['round_id'];
    foreach ($state['body']['members'] as $member) {
        if ($member['instance_id'] !== S\cooperative_instance_id()) {
            $state = S\cooperative_verification_peer($id, $state['revision'], $round, $member['instance_id']);
        }
    }
    return $state;
}

/** Obtain a directed fixture credential without confusing requesting and source nodes.
 * @param string $from Requesting node.
 * @param string $to Source node.
 * @return string Directed system token; never printed by the fixture.
 */
function activation_token(string $from, string $to): string
{
    $previous = $GLOBALS['exchange_node'];
    exchange_node($from);
    $token = S\cooperative_peer_outbound_credential($GLOBALS['exchange_nodes'][$to]['id']);
    exchange_node($previous);
    return $token;
}

/** Advance only the disposable retry boundary without extending the verification round.
 * @param string $id Isolated group with a failed verification operation.
 * @return array<string,mixed> Fresh projection with its retry cooldown elapsed.
 */
function activation_retry_ready(string $id): array
{
    $stored = S\cooperative_proposal_exchange_load($id);
    $group = $stored['group'];
    $group['exchange']['verification_retry_at'] = time() - 1;
    return S\cooperative_proposal_exchange_projection(S\cooperative_proposal_exchange_save($stored, $group));
}

$id = exchange_approved_fixture();
$a = $GLOBALS['exchange_nodes']['a']['id'];
$b = $GLOBALS['exchange_nodes']['b']['id'];
$c = $GLOBALS['exchange_nodes']['c']['id'];
$album = $GLOBALS['exchange_nodes']['a']['member']['album_id'];
$token = activation_token('b', 'a');
$state = exchange_state($id);
foreach ([$b, $c] as $peer) { $state = S\cooperative_proposal_exchange_contact($id, $state['revision'], $peer, 'decision'); }
exchange_check(!$state['sharing_active'], 'Ordinary observations activated a group.');
exchange_refuses(/** A fabricated round cannot reuse diagnostic observations. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_activation_finalize($id, $state['revision'], S\cooperative_id_generate()), 'verification_unavailable');
$state = S\cooperative_verification_start($id, $state['revision']);
$round = $state['verification']['round_id'];
$state = S\cooperative_verification_peer($id, $state['revision'], $round, $b);
exchange_refuses(/** The complete graph requires C's own fresh response. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_activation_finalize($id, $state['revision'], $round), 'consent_missing');

foreach (['challenge', 'edge', 'forwarded'] as $tamper) {
    $GLOBALS['exchange_tamper'] = $tamper;
    exchange_refuses(/** Reject a replayed nonce, missing edge or forwarded approval. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_verification_peer($id, $state['revision'], $round, $c), 'peer_response_invalid');
    $state = exchange_state($id);
    exchange_check(!in_array($c, $state['verification']['checked_peers'], true), 'Malformed response became a verification receipt.');
    $calls = $GLOBALS['exchange_calls'];
    $waiting = S\cooperative_verification_peer($id, $state['revision'], $round, $c);
    exchange_check($waiting['maintenance']['action'] === 'waiting' && $waiting['revision'] === $state['revision']
        && $GLOBALS['exchange_calls'] === $calls, 'Malformed reply retry bypassed transport cooldown.');
    $state = activation_retry_ready($id);
}
$GLOBALS['exchange_tamper'] = '';
$GLOBALS['exchange_drop'] = true;
exchange_refuses(/** Failure must erase a previously successful receipt before retrying that peer. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_verification_peer($id, $state['revision'], $round, $b), 'peer_unavailable');
$state = exchange_state($id);
exchange_check(!in_array($b, $state['verification']['checked_peers'], true), 'Lost response retained an older positive receipt.');
$state = activation_retry_ready($id);
$state = S\cooperative_verification_peer($id, $state['revision'], $round, $b);
$state = S\cooperative_verification_peer($id, $state['revision'], $round, $c);
$beforeActivation = $state['revision'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'activate', 'group_id' => $id, 'revision' => $beforeActivation, 'round_id' => $round];
$envelope = exchange_http();
exchange_check($envelope['ok'] && $envelope['mutation']['type'] === 'cooperative_proposal.activate'
    && $envelope['mutation']['entity_ids'] === [1] && count($envelope['contexts']) === 1
    && $envelope['contexts'][0]['type'] === 'gallery' && $envelope['contexts'][0]['gallery_id'] === 1
    && $envelope['contexts'][0]['render_url'] === '/index.php?page=gallery&id=1', 'Activation lost the canonical Admin mutation envelope.');
$active = $envelope['proposal'];
exchange_check($active['sharing_active'] && $active['membership_revision'] === 1 && $active['state'] === 'active', 'Complete direct verification did not activate locally.');
$raw = S\cooperative_group_read($id)['group'];
exchange_check($raw['pending'] === null && count($raw['friendships']) === 3 && count($raw['members']) === 3, 'Activation lost exact membership or complete clique evidence.');
$repeat = S\cooperative_activation_finalize($id, $beforeActivation, $round);
exchange_check($repeat['revision'] === $active['revision'] && $repeat['authorization_expires_at'] === $active['authorization_expires_at'], 'Lost activation-response retry extended its lease or repeated membership change.');
exchange_check(S\cooperative_activation_allows_metadata($id, $b, $token, $album, 1), 'Verified local metadata grant was refused.');
exchange_check(!S\cooperative_activation_allows_metadata($id, $b, $token, $album, 0)
    && !S\cooperative_activation_allows_metadata($id, $b, $token, $album, 2)
    && !S\cooperative_activation_allows_metadata($id, $b, $token, $GLOBALS['exchange_nodes']['b']['member']['album_id'], 1)
    && !S\cooperative_activation_allows_metadata($id, $b, S\cooperative_credential_generate(), $album, 1), 'Metadata gate accepted wrong revision, remote source or invalid credential.');
$d = $GLOBALS['exchange_nodes']['d']['id'];
exchange_check(!S\cooperative_activation_allows_metadata($id, $d, activation_token('d', 'a'), $album, 1), 'An unrelated direct friend inherited membership.');

exchange_node('b');
exchange_check(!exchange_state($id)['sharing_active']
    && !S\cooperative_activation_allows_metadata($id, $a, activation_token('a', 'b'), $GLOBALS['exchange_nodes']['b']['member']['album_id'], 1), 'Partial activation granted access at an unverified installation.');
foreach (['b', 'c'] as $name) {
    exchange_node($name);
    $state = activation_collect($id);
    S\cooperative_activation_finalize($id, $state['revision'], $state['verification']['round_id']);
    exchange_check(exchange_state($id)['sharing_active'], 'Another participant could not independently activate.');
}
// Existing active consent outlives the initial collection deadline, while leases do not.
$historicIssuedAt = time() - 200;
$historicExpiresAt = $historicIssuedAt + 100;
foreach (['a', 'b', 'c'] as $name) {
    exchange_node($name);
    $stored = S\cooperative_group_read($id);
    $group = $stored['group'];
    $group['exchange']['document']['body']['issued_at'] = $historicIssuedAt;
    $group['exchange']['document']['body']['expires_at'] = $historicExpiresAt;
    $historicDigest = S\cooperative_proposal_digest($group['exchange']['document']['body']);
    $group['exchange']['document']['digest'] = $historicDigest;
    $group['last_proposal_digest'] = $historicDigest;
    $group['exchange']['lease'] = null;
    $group['exchange']['verification'] = null;
    M\cooperative_model_group_save($group, $stored['storage_revision'], gmdate('Y-m-d H:i:s'));
    exchange_check(exchange_state($id)['own']['decision'] === 'approved', 'Initial proposal expiry cancelled already active local consent.');
}
exchange_node('a');
$state = activation_collect($id);
S\cooperative_activation_finalize($id, $state['revision'], $state['verification']['round_id']);
exchange_node('a');
\Gallery\Core\db()->exec('UPDATE galleries SET visibility = "private" WHERE id = 1');
exchange_check(!S\cooperative_activation_allows_metadata($id, $b, $token, $album, 1), 'Lease bypassed a newly private source.');
\Gallery\Core\db()->exec('UPDATE galleries SET visibility = "public" WHERE id = 1');

// Simulate expiry without sleeps: both timestamps retain the canonical lease duration.
$stored = S\cooperative_group_read($id);
$group = $stored['group'];
$group['exchange']['lease']['started_at'] = time() - S\COOPERATIVE_VERIFICATION_TTL - 1;
$group['exchange']['lease']['expires_at'] = $group['exchange']['lease']['started_at'] + S\COOPERATIVE_VERIFICATION_TTL;
M\cooperative_model_group_save($group, $stored['storage_revision'], gmdate('Y-m-d H:i:s'));
exchange_check(!S\cooperative_activation_allows_metadata($id, $b, $token, $album, 1), 'Expired lease still authorized metadata.');
$state = activation_collect($id);
exchange_check(!$state['sharing_active'], 'Collection bypassed explicit finalization.');
$state = S\cooperative_activation_finalize($id, $state['revision'], $state['verification']['round_id']);
exchange_check($state['sharing_active'] && $state['membership_revision'] === 1, 'Lease renewal changed membership or failed to reauthorize.');

// Known remote decline invalidates immediately on observation, with no old-round revival.
exchange_node('c');
$cState = exchange_state($id);
$cState = S\cooperative_proposal_exchange_decide($id, $cState['revision'], $cState['digest'], 'declined');
exchange_check(!$cState['sharing_active'] && $cState['state'] === 'suspended' && $cState['membership_revision'] === 2, 'Local active decline was not immediate.');
exchange_node('a');
$state = S\cooperative_proposal_exchange_contact($id, $state['revision'], $c, 'decision');
exchange_check(!$state['sharing_active'] && $state['verification'] === null
    && !S\cooperative_activation_allows_metadata($id, $b, $token, $album, 1), 'Direct decline observation retained old authority.');
$state = activation_collect($id);
exchange_refuses(/** A declined participant cannot be activated through a new round. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_activation_finalize($id, $state['revision'], $state['verification']['round_id']), 'consent_missing');

// An ordinary read that discovers a decline must invalidate collected proofs as well.
$id = exchange_approved_fixture();
$c = $GLOBALS['exchange_nodes']['c']['id'];
$state = activation_collect($id);
$oldRound = $state['verification']['round_id'];
exchange_node('c');
$current = exchange_state($id);
S\cooperative_proposal_exchange_decide($id, $current['revision'], $current['digest'], 'declined');
exchange_node('a');
$state = S\cooperative_proposal_exchange_contact($id, $state['revision'], $c, 'decision');
exchange_refuses(/** Known withdrawal cannot be hidden by earlier complete proofs. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_activation_finalize($id, $state['revision'], $oldRound), 'verification_unavailable');

// Verification cannot outlive its own collection deadline or a newer local decision.
$id = exchange_approved_fixture();
$b = $GLOBALS['exchange_nodes']['b']['id'];
$state = activation_collect($id);
$stored = S\cooperative_group_read($id);
$group = $stored['group'];
$group['exchange']['verification']['started_at'] = time() - S\COOPERATIVE_VERIFICATION_TTL - 1;
$group['exchange']['verification']['expires_at'] = $group['exchange']['verification']['started_at'] + S\COOPERATIVE_VERIFICATION_TTL;
M\cooperative_model_group_save($group, $stored['storage_revision'], gmdate('Y-m-d H:i:s'));
$state = exchange_state($id);
exchange_refuses(/** Collection time consumes the lease instead of extending authorization. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_activation_finalize($id, $state['revision'], $state['verification']['round_id']), 'verification_expired');
$state = S\cooperative_verification_start($id, $state['revision']);
$GLOBALS['exchange_hook'] = /** Revoke consent while a direct check is in flight.
 * @return void Simulate a concurrent administrator decision.
 */ static function () use ($id): void {
    $current = exchange_state($id);
    S\cooperative_proposal_exchange_decide($id, $current['revision'], $current['digest'], 'declined');
};
exchange_refuses(/** Late verification must lose to the local decision revision. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_verification_peer($id, $state['revision'], $state['verification']['round_id'], $b), 'revision_conflict');
exchange_check(exchange_state($id)['own']['decision'] === 'declined' && !exchange_state($id)['sharing_active'], 'In-flight verification overwrote a decline.');

// Changed B-C credential generations cannot silently renew A's original active graph.
$id = exchange_approved_fixture();
$state = activation_collect($id);
S\cooperative_activation_finalize($id, $state['revision'], $state['verification']['round_id']);
foreach ([['b', 'c'], ['c', 'b']] as [$name, $remoteName]) {
    exchange_node($name);
    $peer = M\cooperative_model_peer_find($GLOBALS['exchange_nodes'][$remoteName]['id']);
    M\cooperative_model_peer_save($peer, $peer['revision'], gmdate('Y-m-d H:i:s'));
    $current = exchange_state($id);
    S\cooperative_proposal_exchange_decide($id, $current['revision'], $current['digest'], 'approved');
}
exchange_node('a');
$state = activation_collect($id);
exchange_refuses(/** Renewing revision one cannot replace the original friendship graph. @return array<string,mixed> Refused projection. */ static fn() => S\cooperative_activation_finalize($id, $state['revision'], $state['verification']['round_id']), 'friendship_changed');
exchange_check(!exchange_state($id)['sharing_active'], 'Changed friendship evidence revived the old grant.');

// Feature and schema refusal remain ahead of every source authorization decision.
$id = exchange_approved_fixture();
$b = $GLOBALS['exchange_nodes']['b']['id'];
$album = $GLOBALS['exchange_nodes']['a']['member']['album_id'];
$token = activation_token('b', 'a');
$state = activation_collect($id);
S\cooperative_activation_finalize($id, $state['revision'], $state['verification']['round_id']);
$GLOBALS['exchange_enabled'] = false;
$before = $GLOBALS['exchange_db_calls'];
exchange_check(!S\cooperative_activation_allows_metadata($id, $b, $token, $album, 1)
    && $GLOBALS['exchange_db_calls'] === $before, 'Disabled metadata gate touched optional storage.');
$GLOBALS['exchange_enabled'] = true;
S\schema_inspection_set_query_executor_for_tests(/** Refuse an unknown security schema. @return never Simulated observation failure. */ static fn() => throw new \RuntimeException('private diagnostic'));
exchange_check(!S\cooperative_activation_allows_metadata($id, $b, $token, $album, 1), 'Unknown schema authorized metadata.');
S\schema_inspection_set_query_executor_for_tests(/** Restore isolated available schema. @return bool All fixture objects are available. */ static fn(): bool => true);
S\cooperative_peer_revoke($b);
exchange_check(!S\cooperative_activation_allows_metadata($id, $b, $token, $album, 1) && !exchange_state($id)['sharing_active'], 'Direct revocation did not immediately block a leased grant.');
echo "PASS cooperative initial activation\n";
