<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_content_admission_test.php
 * Module Type: Regression Test
 * Purpose: Verify durable anonymous catalog admission without network or live storage.
 * Responsibilities: Cover concurrent readers, retry spacing, crash recovery and stale ownership.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require_once __DIR__ . '/support/cooperative_exchange_fixture.php';
require_once dirname(__DIR__) . '/app/services/cooperative_content.php';
use Gallery\Services as S;

/** Activate the isolated approved fixture through its four bounded maintenance steps.
 * @return string Explicitly approved active group identity.
 */
function admission_active_fixture(): string
{
    $id = exchange_approved_fixture();
    $state = exchange_state($id);
    $state = S\cooperative_maintenance_step($id, $state['revision']);
    $state = S\cooperative_maintenance_step($id, $state['revision']);
    $state = S\cooperative_maintenance_step($id, $state['revision']);
    $state = S\cooperative_maintenance_step($id, $state['revision']);
    exchange_check($state['sharing_active'], 'Admission fixture did not activate.');
    return $id;
}

/** Move only the admission cooldown into the past without sleeping or extending authority.
 * @param string $id Isolated active group.
 * @return void Allow the next admission against the unchanged security lease.
 */
function admission_retry_ready(string $id): void
{
    $stored = S\cooperative_proposal_exchange_load($id);
    $group = $stored['group'];
    $group['exchange']['content_retry_at'] = time() - 1;
    S\cooperative_proposal_exchange_save($stored, $group);
}

/** Keep the fixture's catalog cooldown active during an immediate contention read.
 * @param string $id Isolated active group whose admission gate is under test.
 * @return void Store a finite future cooldown without changing the security lease or operation owner.
 */
function admission_retry_blocked(string $id): void
{
    $stored = S\cooperative_proposal_exchange_load($id);
    $group = $stored['group'];
    $group['exchange']['content_retry_at'] = time() + S\COOPERATIVE_CONSENT_RETRY_DELAY;
    S\cooperative_proposal_exchange_save($stored, $group);
}

$id = admission_active_fixture();
$b = $GLOBALS['exchange_nodes']['b']['id'];
$c = $GLOBALS['exchange_nodes']['c']['id'];
$calls = 0;
$hook = null;
$fail = false;
$pending = false;
$transport = &S\cooperative_content_transport_override();
$transport = /** Return bounded correlated fixture content while another reader may run.
 * @param string $base Stored origin.
 * @param array<string,mixed> $message Correlated request.
 * @param string $bearer Directed disposable credential.
 * @return array<string,mixed> Whitelisted response without cached remote content.
 */ static function(string $base, array $message, string $bearer) use (&$calls, &$hook, &$fail, &$pending): array {
    $calls++;
    $during = $hook;
    $hook = null;
    if (is_callable($during)) { $during(); }
    if ($fail) { throw new RuntimeException('Disposable transport failure.'); }
    return ['protocol' => $message['protocol'], 'group_id' => $message['group_id'], 'album_id' => $message['album_id'],
        'revision' => $message['revision'], 'ok' => true, 'sender_id' => $message['recipient_id'],
        'recipient_id' => $message['sender_id'], 'pending' => $pending,
        'catalog' => ['title' => 'Fixture', 'photos' => [], 'next_cursor' => null, 'expires_at' => time() + 10]];
};
$lease = S\cooperative_proposal_exchange_load($id)['group']['exchange']['lease'];
$hook = /** A second source still shares this group's single network admission.
 * @return void Assert pending without another transport or storage mutation.
 */ static function () use ($id, $c, &$calls): void {
    $stored = S\cooperative_proposal_exchange_load($id);
    $result = S\cooperative_content_read($id, $c);
    exchange_check($result === ['pending' => true] && $calls === 1
        && S\cooperative_proposal_exchange_load($id)['storage_revision'] === $stored['storage_revision'],
        'Concurrent anonymous reader launched or superseded catalog work.');
};
$firstReadStartedAt = time();
$result = S\cooperative_content_read($id, $b);
$firstReadCompletedAt = time();
$firstSuccessRetryAt = S\cooperative_proposal_exchange_load($id)['group']['exchange']['content_retry_at'] ?? null;
exchange_check(!$result['pending'] && $calls === 1 && is_int($firstSuccessRetryAt)
    && $firstSuccessRetryAt >= $firstReadStartedAt + S\COOPERATIVE_CONTENT_SUCCESS_RETRY_DELAY
    && $firstSuccessRetryAt <= $firstReadCompletedAt + S\COOPERATIVE_CONTENT_SUCCESS_RETRY_DELAY,
    'First owned catalog did not complete with its bounded successful-admission cooldown.');
$stored = S\cooperative_proposal_exchange_load($id);
exchange_check(!isset($stored['group']['exchange']['content_operation'])
    && $stored['group']['exchange']['lease'] === $lease, 'Successful content changed the security lease.');
admission_retry_blocked($id);
exchange_check(S\cooperative_content_read($id, $c) === ['pending' => true] && $calls === 1,
    'Successful admission spacing allowed another source request before the saved cooldown expired.');

admission_retry_ready($id);
$pending = true;
$pendingReadStartedAt = time();
$pendingResult = S\cooperative_content_read($id, $b);
$pendingReadCompletedAt = time();
$pendingSuccessRetryAt = S\cooperative_proposal_exchange_load($id)['group']['exchange']['content_retry_at'] ?? null;
exchange_check($pendingResult === ['pending' => true] && $calls === 2 && is_int($pendingSuccessRetryAt)
    && $pendingSuccessRetryAt >= $pendingReadStartedAt + S\COOPERATIVE_CONTENT_SUCCESS_RETRY_DELAY
    && $pendingSuccessRetryAt <= $pendingReadCompletedAt + S\COOPERATIVE_CONTENT_SUCCESS_RETRY_DELAY,
    'Validated remote pending status was not preserved with its successful-admission cooldown.');
admission_retry_blocked($id);
exchange_check(S\cooperative_content_read($id, $b) === ['pending' => true] && $calls === 2,
    'Remote pending status bypassed successful admission spacing before the saved cooldown expired.');
$pending = false;
admission_retry_ready($id);
$fail = true;
exchange_refuses(/** Record finite failed admission cooldown.
 * @return array<string,mixed> Refused remote content.
 */ static fn() => S\cooperative_content_read($id, $b), 'peer_unavailable');
$stored = S\cooperative_proposal_exchange_load($id);
exchange_check(!isset($stored['group']['exchange']['content_operation'])
    && $stored['group']['exchange']['content_retry_at'] >= time() + S\COOPERATIVE_CONSENT_RETRY_DELAY - 1,
    'Failed transport did not release ownership with finite cooldown.');
exchange_check(S\cooperative_content_read($id, $c) === ['pending' => true] && $calls === 3,
    'Failed admission allowed polling to hammer another source.');
$fail = false;
admission_retry_ready($id);
$group = S\cooperative_proposal_exchange_load($id)['group'];
$reserved = S\cooperative_content_admission_reserve($id, $group, $b);
exchange_check($reserved !== null && S\cooperative_content_read($id, $c) === ['pending' => true] && $calls === 3,
    'Crashed owned reservation permitted another catalog request.');
$group = $reserved['group'];
$group['exchange']['content_operation']['expires_at'] = time() - 1;
$stored = S\cooperative_proposal_exchange_save($reserved, $group);
exchange_check(S\cooperative_content_read($id, $c) === ['pending' => true] && $calls === 3,
    'Expired ownership bypassed crash cooldown.');
admission_retry_ready($id);
exchange_check(!S\cooperative_content_read($id, $c)['pending'] && $calls === 4,
    'Expired crash reservation did not recover after cooldown.');
exchange_check(S\cooperative_proposal_exchange_load($id)['group']['exchange']['lease'] === $lease,
    'Crash recovery extended security authority.');

// Catalog admission yields to existing verification work even if a valid lease
// is still present in this deliberately constructed concurrency fixture.
admission_retry_ready($id);
$stored = S\cooperative_proposal_exchange_load($id);
$group = $stored['group'];
$group['exchange']['verification']['operation'] = ['nonce' => S\cooperative_id_generate(),
    'round_id' => $lease['round_id'], 'peer_id' => $b, 'expires_at' => time() + 10];
$stored = S\cooperative_proposal_exchange_save($stored, $group);
exchange_check(S\cooperative_content_admission_reserve($id, $group, $b) === null && $calls === 4,
    'Catalog reservation competed with active verification.');
unset($group['exchange']['verification']['operation']);
S\cooperative_proposal_exchange_save($stored, $group);

admission_retry_ready($id);
$newNonce = null;
$hook = /** Commit newer ownership before the delayed old response arrives.
 * @return void Replace only the disposable admission operation.
 */ static function () use ($id, &$newNonce): void {
    $stored = S\cooperative_proposal_exchange_load($id);
    $group = $stored['group'];
    $newNonce = S\cooperative_id_generate();
    $group['exchange']['content_operation']['nonce'] = $newNonce;
    S\cooperative_proposal_exchange_save($stored, $group);
};
exchange_refuses(/** A stale reply cannot release a newer operation or return content.
 * @return array<string,mixed> Refused delayed content.
 */ static fn() => S\cooperative_content_read($id, $b), 'content_unauthorized');
exchange_check(S\cooperative_proposal_exchange_load($id)['group']['exchange']['content_operation']['nonce'] === $newNonce,
    'Stale catalog reply cleared newer ownership.');

$stored = S\cooperative_proposal_exchange_load($id);
$group = $stored['group'];
$group['exchange']['content_operation']['expires_at'] = time() - 1;
$group['exchange']['content_retry_at'] = time() - 1;
S\cooperative_proposal_exchange_save($stored, $group);
$fail = true;
$hook = /** Failure cleanup must also respect a newer owner.
 * @return void Replace the admission operation before transport failure.
 */ static function () use ($id, &$newNonce): void {
    $stored = S\cooperative_proposal_exchange_load($id);
    $group = $stored['group'];
    $newNonce = S\cooperative_id_generate();
    $group['exchange']['content_operation']['nonce'] = $newNonce;
    S\cooperative_proposal_exchange_save($stored, $group);
};
exchange_refuses(/** Transport failure cannot release another request's ownership.
 * @return array<string,mixed> Refused failed content.
 */ static fn() => S\cooperative_content_read($id, $b), 'peer_unavailable');
exchange_check(S\cooperative_proposal_exchange_load($id)['group']['exchange']['content_operation']['nonce'] === $newNonce,
    'Failed old request cleared newer ownership.');
$fail = false;

$stored = S\cooperative_proposal_exchange_load($id);
$group = $stored['group'];
$group['exchange']['content_operation']['expires_at'] = time() - 1;
$group['exchange']['content_retry_at'] = time() - 1;
S\cooperative_proposal_exchange_save($stored, $group);
$hook = /** Withdraw consent while the outbound catalog request is active.
 * @return void Commit the independent administrator decision.
 */ static function () use ($id): void {
    $state = exchange_state($id);
    S\cooperative_proposal_exchange_decide($id, $state['revision'], $state['digest'], 'declined');
};
exchange_refuses(/** Refuse delayed content after concurrent local decline.
 * @return array<string,mixed> Refused unauthorized content.
 */ static fn() => S\cooperative_content_read($id, $b), 'content_unauthorized');
exchange_check(exchange_state($id)['own']['decision'] === 'declined' && !exchange_state($id)['sharing_active'],
    'Content completion recreated declined consent.');

echo "PASS cooperative content admission\n";
