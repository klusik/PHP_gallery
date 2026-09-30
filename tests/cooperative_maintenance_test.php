<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_maintenance_test.php
 * Module Type: Regression Test
 * Purpose: Exercise automatic cooperative verification and active-only scheduler renewal.
 * Responsibilities: Prove bounded steps, no implicit consent, restart recovery and fail-closed concurrency.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/cooperative_exchange_fixture.php';
use Gallery\Services as S;

/** Shift only an existing lease into its renewal window without waiting in real time.
 * @param string $id Existing locally active fixture group.
 * @return void Persist a valid lease near expiry using the production CAS writer.
 */
function maintenance_due(string $id): void
{
    $stored = S\cooperative_proposal_exchange_load($id);
    $group = $stored['group'];
    $group['exchange']['lease']['started_at'] = time() - S\COOPERATIVE_RENEWAL_WINDOW - 1;
    $group['exchange']['lease']['expires_at'] = $group['exchange']['lease']['started_at'] + S\COOPERATIVE_VERIFICATION_TTL;
    S\cooperative_proposal_exchange_save($stored, $group);
}

$id = exchange_approved_fixture();
$state = exchange_state($id);
$calls = $GLOBALS['exchange_calls'];
exchange_refuses(/** A scheduler cannot activate an invitation. @return array<string,mixed> Refused renewal. */ static fn() => S\cooperative_maintenance_renew($id), 'proposal_not_active');
exchange_check($GLOBALS['exchange_calls'] === $calls, 'Pending renewal contacted a peer.');
$state = S\cooperative_maintenance_step($id, $state['revision']);
exchange_check($state['maintenance']['action'] === 'verify' && $GLOBALS['exchange_calls'] === $calls, 'Start did network work.');
$firstRevision = $state['revision'];
while ($state['maintenance']['action'] === 'verify') {
    $before = $GLOBALS['exchange_calls'];
    $state = S\cooperative_maintenance_step($id, $state['revision']);
    exchange_check($GLOBALS['exchange_calls'] === $before + 1 && !$state['sharing_active'], 'Step did more than one request or activated early.');
}
exchange_check($state['maintenance']['action'] === 'finalize', 'All direct checks did not enable finalization.');
$state = S\cooperative_maintenance_step($id, $state['revision']);
exchange_check($state['sharing_active'] && $state['maintenance']['action'] === 'ready', 'Automatic selection did not activate the initial group.');
exchange_refuses(/** A stale browser must not write into a newer round. @return array<string,mixed> Refused step. */ static fn() => S\cooperative_maintenance_step($id, $firstRevision), 'revision_conflict');
$calls = $GLOBALS['exchange_calls'];
$renewed = S\cooperative_maintenance_renew($id);
exchange_check($renewed['revision'] === $state['revision'] && $GLOBALS['exchange_calls'] === $calls, 'Fresh lease renewal performed writes or network calls.');

maintenance_due($id);
$state = S\cooperative_maintenance_renew($id);
exchange_check($state['sharing_active'] && $state['membership_revision'] === 1 && $GLOBALS['exchange_calls'] === $calls + 2, 'Due lease did not renew using exactly one check per peer.');

// A truly expired lease also recovers without altering the approved membership.
$stored = S\cooperative_proposal_exchange_load($id);
$group = $stored['group'];
$group['exchange']['lease']['started_at'] = time() - S\COOPERATIVE_VERIFICATION_TTL - 1;
$group['exchange']['lease']['expires_at'] = $group['exchange']['lease']['started_at'] + S\COOPERATIVE_VERIFICATION_TTL;
$group['exchange']['verification'] = null;
S\cooperative_proposal_exchange_save($stored, $group);
exchange_check(!exchange_state($id)['sharing_active'], 'Expired fixture lease remained authorized.');
$state = S\cooperative_maintenance_renew($id);
exchange_check($state['sharing_active'] && $state['membership_revision'] === 1, 'Expired lease did not recover unchanged membership.');

// A crashed/lost response reserves progress but cannot retain an older proof.
maintenance_due($id);
$GLOBALS['exchange_drop'] = true;
try {
    S\cooperative_maintenance_renew($id);
    throw new RuntimeException('Expected lost response.');
} catch (S\CooperativeException $error) {
    exchange_check($error->reason === 'peer_unavailable', 'Unexpected transport refusal.');
}
$failed = exchange_state($id);
exchange_check(!$failed['sharing_active'] && $failed['maintenance']['action'] === 'waiting', 'Failed renewal left authority or lost bounded retry progress.');
$round = $failed['verification']['round_id'];
$calls = $GLOBALS['exchange_calls'];
$waiting = S\cooperative_maintenance_renew($id);
exchange_check($waiting['revision'] === $failed['revision'] && $GLOBALS['exchange_calls'] === $calls, 'Transport cooldown hammered a peer.');
$stored = S\cooperative_proposal_exchange_load($id);
$group = $stored['group'];
$group['exchange']['verification_retry_at'] = time() - 1;
S\cooperative_proposal_exchange_save($stored, $group);
$state = S\cooperative_maintenance_renew($id);
exchange_check($state['sharing_active'] && $state['verification']['round_id'] === $round, 'Restart discarded valid progress instead of resuming.');

// Negative consent pauses progress rather than creating a busy retry loop.
exchange_node('b');
$remote = exchange_state($id);
S\cooperative_proposal_exchange_decide($id, $remote['revision'], $remote['digest'], 'declined');
exchange_node('a');
maintenance_due($id);
$state = S\cooperative_maintenance_renew($id);
exchange_check(!$state['sharing_active'] && $state['maintenance']['action'] === 'waiting'
    && $state['maintenance']['retry_at'] > time(), 'Negative participant did not stop the worker.');
$calls = $GLOBALS['exchange_calls'];
$waiting = S\cooperative_maintenance_renew($id);
exchange_check($waiting['revision'] === $state['revision'] && $GLOBALS['exchange_calls'] === $calls, 'Waiting renewal hammered a participant.');

// An expired interrupted round starts again without reusing old receipts.
$stored = S\cooperative_proposal_exchange_load($id);
$group = $stored['group'];
$group['exchange']['verification']['started_at'] = time() - S\COOPERATIVE_VERIFICATION_TTL - 1;
$group['exchange']['verification']['expires_at'] = $group['exchange']['verification']['started_at'] + S\COOPERATIVE_VERIFICATION_TTL;
$stored = S\cooperative_proposal_exchange_save($stored, $group);
$state = S\cooperative_maintenance_step($id, $stored['storage_revision']);
exchange_check($state['verification']['round_id'] !== $round && $state['verification']['checked_peers'] === [], 'Expired round reused old proofs.');

// A concurrent poll with the new reserved revision must wait without superseding it.
$id = exchange_approved_fixture();
$state = exchange_state($id);
$state = S\cooperative_maintenance_step($id, $state['revision']);
$GLOBALS['exchange_hook'] = /** Poll while the first request still owns its persisted operation.
 * @return void Confirm no network request or write supersedes the reservation.
 */ static function () use ($id): void {
    $current = exchange_state($id);
    $calls = $GLOBALS['exchange_calls'];
    $waiting = S\cooperative_maintenance_step($id, $current['revision']);
    exchange_check($waiting['maintenance']['action'] === 'waiting'
        && $waiting['revision'] === $current['revision'] && $GLOBALS['exchange_calls'] === $calls,
        'Concurrent polling superseded the owned operation.');
    $stored = S\cooperative_proposal_exchange_load($id);
    $operation = $stored['group']['exchange']['verification']['operation'];
    exchange_check($operation['round_id'] === $current['verification']['round_id']
        && strlen($operation['nonce']) === 32 && isset($operation['peer_id']), 'Reservation lost ownership binding.');
};
$state = S\cooperative_maintenance_step($id, $state['revision']);
exchange_check(count($state['verification']['checked_peers']) === 1, 'Concurrent poll discarded a valid owned reply.');

// Simulate a process dying after its reservation was committed. Recovery never
// extends the round or reuses its missing receipt, and remains bounded by cooldown.
$stored = S\cooperative_proposal_exchange_load($id);
$group = $stored['group'];
$remote = array_key_last($group['exchange']['verification']['own_generations']);
$round = $group['exchange']['verification']['round_id'];
$deadline = $group['exchange']['verification']['expires_at'];
$group['exchange']['verification']['operation'] = ['nonce' => S\cooperative_id_generate(),
    'round_id' => $round, 'peer_id' => $remote, 'expires_at' => time() + 10];
$group['exchange']['verification_retry_at'] = time() + 40;
$stored = S\cooperative_proposal_exchange_save($stored, $group);
$calls = $GLOBALS['exchange_calls'];
$waiting = S\cooperative_maintenance_step($id, $stored['storage_revision']);
exchange_check($waiting['maintenance']['action'] === 'waiting' && $GLOBALS['exchange_calls'] === $calls,
    'Crashed reservation allowed an immediate duplicate.');
$group['exchange']['verification']['operation']['expires_at'] = time() - 1;
$stored = S\cooperative_proposal_exchange_save($stored, $group);
$waiting = S\cooperative_maintenance_step($id, $stored['storage_revision']);
exchange_check($waiting['maintenance']['action'] === 'waiting' && $GLOBALS['exchange_calls'] === $calls,
    'Expired reservation skipped the crash cooldown.');
$group['exchange']['verification_retry_at'] = time() - 1;
$stored = S\cooperative_proposal_exchange_save($stored, $group);
$state = S\cooperative_maintenance_step($id, $stored['storage_revision']);
exchange_check($GLOBALS['exchange_calls'] === $calls + 1 && $state['verification']['round_id'] === $round
    && S\cooperative_proposal_exchange_load($id)['group']['exchange']['verification']['expires_at'] === $deadline,
    'Crash recovery extended the round or failed to resume exactly one request.');

// A late network reply cannot replace a newer operation after the old owner times out.
$id = exchange_approved_fixture();
$state = exchange_state($id);
$state = S\cooperative_maintenance_step($id, $state['revision']);
$GLOBALS['exchange_hook'] = /** Replace an expired owner while its original response is delayed.
 * @return void Persist a distinct operation and retain its empty evidence.
 */ static function () use ($id): void {
    $stored = S\cooperative_proposal_exchange_load($id);
    $group = $stored['group'];
    $group['exchange']['verification']['operation']['nonce'] = S\cooperative_id_generate();
    S\cooperative_proposal_exchange_save($stored, $group);
};
exchange_refuses(/** Discard the stale operation's reply.
 * @return array<string,mixed> Refused verification.
 */ static fn() => S\cooperative_maintenance_step($id, $state['revision']), 'revision_conflict');
$stored = S\cooperative_proposal_exchange_load($id);
exchange_check(isset($stored['group']['exchange']['verification']['operation'])
    && $stored['group']['exchange']['verification']['receipts'] === [], 'Stale reply cleared a newer owner or wrote evidence.');

// A concurrent decline wins over the worker's in-flight peer response.
$id = exchange_approved_fixture();
$state = exchange_state($id);
$state = S\cooperative_maintenance_step($id, $state['revision']);
$GLOBALS['exchange_hook'] = /** Withdraw local consent while the selected request is in flight.
 * @return void Commit the independent administrator decision.
 */ static function () use ($id): void {
    $current = exchange_state($id);
    S\cooperative_proposal_exchange_decide($id, $current['revision'], $current['digest'], 'declined');
};
exchange_refuses(/** Refuse stale verification after local withdrawal. @return array<string,mixed> Refused step. */ static fn() => S\cooperative_maintenance_step($id, $state['revision']), 'revision_conflict');
$state = exchange_state($id);
$calls = $GLOBALS['exchange_calls'];
$blocked = S\cooperative_maintenance_step($id, $state['revision']);
exchange_check($blocked['maintenance']['action'] === 'blocked' && $blocked['own']['decision'] === 'declined'
    && $GLOBALS['exchange_calls'] === $calls && $blocked['revision'] === $state['revision'], 'Orchestration recreated revoked consent.');

// The existing HTTP boundary owns method, administrator and CSRF checks.
$id = exchange_approved_fixture();
$state = exchange_state($id);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'advance', 'group_id' => $id, 'revision' => $state['revision']];
$result = exchange_http();
exchange_check($result['ok'] && $result['mutation']['entity_ids'] === [1]
    && $result['proposal']['maintenance']['action'] === 'verify', 'Advance lost canonical mutation or progress metadata.');
foreach (['exchange_admin' => 'admin_required', 'exchange_csrf' => 'csrf_required'] as $boundary => $reason) {
    $GLOBALS[$boundary] = false;
    try {
        exchange_http();
        throw new RuntimeException('Missing HTTP boundary refusal.');
    } catch (RuntimeException $error) {
        exchange_check($error->getMessage() === $reason, 'Advance bypassed its HTTP boundary.');
    } finally {
        $GLOBALS[$boundary] = true;
    }
}
$GLOBALS['exchange_enabled'] = false;
$calls = $GLOBALS['exchange_db_calls'];
$result = exchange_http();
exchange_check(!$result['ok'] && $GLOBALS['exchange_db_calls'] === $calls, 'Disabled maintenance accessed optional storage.');

// Help and malformed arguments must work without application bootstrap or live storage.
foreach ([['--help', 0], ['--group=invalid', 2], ['--group=' . str_repeat('a', 32), 2, '--unexpected']] as $case) {
    $command = [PHP_BINARY, dirname(__DIR__) . '/scripts/cooperative_renew.php', $case[0]];
    if (isset($case[2])) { $command[] = $case[2]; }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    exchange_check(is_resource($process), 'CLI fixture could not start.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    exchange_check(proc_close($process) === $case[1] && str_starts_with($output, 'Usage: php scripts/cooperative_renew.php'), 'CLI argument handling unexpectedly bootstrapped or failed.');
}

echo "PASS cooperative maintenance\n";
