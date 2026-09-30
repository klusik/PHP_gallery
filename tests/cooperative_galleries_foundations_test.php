<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_galleries_foundations_test.php
 * Module Type: Regression Test
 * Purpose: Exercise public collaboration consent, isolation and secret primitives.
 * Responsibilities: Reject incomplete trust, stale revisions and malformed authority.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/security_tokens.php';
require_once dirname(__DIR__) . '/app/services/cooperative_galleries.php';

use Gallery\Services\CooperativeException;
use function Gallery\Services\cooperative_group_new;
use function Gallery\Services\cooperative_group_propose;
use function Gallery\Services\cooperative_group_approve;
use function Gallery\Services\cooperative_group_activate;
use function Gallery\Services\cooperative_group_allows;
use function Gallery\Services\cooperative_group_decline;
use function Gallery\Services\cooperative_group_expire;
use function Gallery\Services\cooperative_group_leave;
use function Gallery\Services\cooperative_group_suspend;
use function Gallery\Services\cooperative_proposal_digest;
use function Gallery\Services\cooperative_peer_base_url;
use function Gallery\Services\cooperative_peer_new;
use function Gallery\Services\cooperative_peer_confirm;
use function Gallery\Services\cooperative_peer_summary;
use function Gallery\Services\cooperative_credential_generate;
use function Gallery\Services\cooperative_credential_valid;
use function Gallery\Services\security_secret_seal;
use function Gallery\Services\security_secret_open;

/** Stop on the first violated behavioral contract.
 *
 * @param bool $condition Behavioral assertion that must hold for the fixture to pass.
 * @param string $message Diagnostic describing the violated fixture contract.
 * @return void No return value; failure raises an exception.
 */
function coop_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Verify a bounded domain refusal without leaking submitted values.
 *
 * @param callable $operation Trusted internal operation; never selected from a remote callback payload.
 * @param string $reason Stable bounded refusal reason without user data or secrets.
 * @return void No return value; failure raises an exception.
 */
function coop_refuses(callable $operation, string $reason): void
{
    try {
        $operation();
    } catch (CooperativeException $error) {
        coop_check($error->reason === $reason, 'Wrong refusal: ' . $error->reason . ', expected ' . $reason);
        return;
    }
    throw new RuntimeException('Expected refusal: ' . $reason);
}

$a = str_repeat('a', 32);
$b = str_repeat('b', 32);
$c = str_repeat('c', 32);
$d = str_repeat('d', 32);
$member = /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @param string $id Stable public identifier of the persisted domain object.
 * @return array<string,mixed> Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn(string $id): array => ['instance_id' => $id, 'album_id' => str_repeat(substr($id, 0, 1), 31) . '1', 'scopes' => ['preview', 'metadata']];
$members = [$member($a), $member($b)];
$now = 1800000000;
$friends = /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @param string $first First installation identity in a canonical friendship pair.
 * @param string $second Second installation identity in a canonical friendship pair.
 * @return string Canonical identifier, digest or secret as described above.
 */ static fn(string $first, string $second): string => hash('sha256', $first . $second . ':generation:1');
$publicSource = /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @param string $album Stable identity of the requested locally owned album.
 * @param string $scope Requested allowlisted operation on the local source album.
 * @param string $audience Required source audience; foundations accept public only.
 * @return bool Whether the exact validation or authorization contract holds.
 */ static fn(string $album, string $scope, string $audience): bool => $audience === 'public';

$group = cooperative_group_new(str_repeat('1', 32), $a);
$pending = cooperative_group_propose($group, $a, $members, $now);
$digest = $pending['pending']['digest'];
coop_check($pending['members'] === [] && $pending['revision'] === 0, 'Invitation granted membership.');
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_activate($pending, $digest, $now, $friends), 'consent_missing');
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_approve($pending, $c, $digest, $now), 'actor_not_member');
$approved = cooperative_group_approve($pending, $a, $digest, $now);
$approved = cooperative_group_approve($approved, $b, $digest, $now);
coop_check(cooperative_group_approve($approved, $b, $digest, $now) === $approved, 'Duplicate consent is not idempotent.');
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_activate($approved, $digest, $now, /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => null), 'friendship_unavailable');
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_activate($approved, $digest, $now, /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => true), 'friendship_unavailable');
$active = cooperative_group_activate($approved, $digest, $now, $friends);
coop_check($active['revision'] === 1 && count($active['members']) === 2, 'Initial activation failed.');
coop_check(cooperative_group_activate($active, $digest, $now, $friends) === $active, 'Activation replay changed revision.');
coop_check(cooperative_group_allows($active, $a, $b, $member($a)['album_id'], 1, 'preview', $friends, $publicSource), 'Authorized local read denied.');
coop_check(!cooperative_group_allows($active, $a, $c, $member($a)['album_id'], 1, 'preview', $friends, $publicSource), 'Friend of friend received access.');
coop_check(!cooperative_group_allows($active, $a, $b, $member($b)['album_id'], 1, 'preview', $friends, $publicSource), 'Remote content was re-exported.');
coop_check(!cooperative_group_allows($active, $a, $b, $member($a)['album_id'], 0, 'preview', $friends, $publicSource), 'Stale revision authorized.');
coop_check(!cooperative_group_allows($active, $a, $b, $member($a)['album_id'], 1, 'original', $friends, $publicSource), 'Unapproved original authorized.');
coop_check(!cooperative_group_allows($active, $a, $b, $member($a)['album_id'], 1, 'preview', $friends, /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => false), 'Source policy bypassed.');
coop_check(!cooperative_group_allows($active, $a, $b, $member($a)['album_id'], 1, 'preview', /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => throw new RuntimeException('offline'), $publicSource), 'Unknown friendship authorized.');
coop_check(!cooperative_group_allows($active, $a, $b, $member($a)['album_id'], 1, 'preview', /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => hash('sha256', 'new-generation'), $publicSource), 'Renewed friendship revived old consent.');

$expansion = cooperative_group_propose($active, $b, [...$members, $member($c)], $now);
$newDigest = $expansion['pending']['digest'];
coop_check(cooperative_group_allows($expansion, $a, $b, $member($a)['album_id'], 1, 'preview', $friends, $publicSource), 'Pending expansion interrupted A+B.');
coop_check(!cooperative_group_allows($expansion, $a, $c, $member($a)['album_id'], 1, 'preview', $friends, $publicSource), 'Pending C received content.');
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_propose($expansion, $a, [...$members, $member($d)], $now), 'proposal_unavailable');
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_approve($expansion, $a, $digest, $now), 'proposal_mismatch');
$two = cooperative_group_approve(cooperative_group_approve($expansion, $b, $newDigest, $now), $c, $newDigest, $now);
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_activate($two, $newDigest, $now, $friends), 'consent_missing');
$all = cooperative_group_approve($two, $a, $newDigest, $now);
$missingAC = /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @param string $first First installation identity in a canonical friendship pair.
 * @param string $second Second installation identity in a canonical friendship pair.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn(string $first, string $second) => $first === $a && $second === $c ? null : $friends($first, $second);
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_activate($all, $newDigest, $now, $missingAC), 'friendship_unavailable');
$three = cooperative_group_activate($all, $newDigest, $now, $friends);
coop_check(count($three['friendships']) === 3 && $three['revision'] === 2, 'Three-member clique incomplete.');
coop_check(cooperative_group_allows($three, $a, $c, $member($a)['album_id'], 2, 'preview', $friends, $publicSource), 'Approved C cannot read A.');
$declined = cooperative_group_decline($expansion, $c, $newDigest, $now);
coop_check($declined === $active, 'Decline changed active state.');
coop_check(cooperative_group_expire($expansion, $now + 86400) === $active, 'Expiry changed active state.');
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_approve($expansion, $a, $newDigest, $now + 86400), 'proposal_expired');
$changed = $all;
$changed['pending']['body']['members'][0]['scopes'][] = 'original';
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_activate($changed, $newDigest, $now, $friends), 'proposal_mismatch');
$changed = $all;
$changed['revision']++;
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_activate($changed, $newDigest, $now, $friends), 'revision_conflict');
$left = cooperative_group_leave($three, $c);
coop_check(count($left['members']) === 2 && $left['revision'] === 3 && count($left['friendships']) === 1, 'Departure failed.');
coop_check(cooperative_group_allows($left, $a, $b, $member($a)['album_id'], 3, 'preview', $friends, $publicSource), 'Departure broke remaining group.');
coop_check(!cooperative_group_allows(cooperative_group_suspend($three), $a, $b, $member($a)['album_id'], 3, 'preview', $friends, $publicSource), 'Suspended group authorized.');
$reordered = $pending['pending']['body'];
$reordered['members'] = array_reverse($reordered['members']);
coop_check(cooperative_proposal_digest($reordered) === $digest, 'Hash depends on member ordering.');
$duplicate = [$member($a), $member($a)];
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_propose($group, $a, $duplicate, $now), 'duplicate_instance');
$private = $pending['pending']['body'];
$private['audience'] = 'private';
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_proposal_digest($private), 'invalid_proposal');
$changedMembers = [...$members, $member($c)];
$changedMembers[0]['scopes'][] = 'original';
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_group_propose($active, $b, $changedMembers, $now), 'existing_consent_changed');

coop_check(cooperative_peer_base_url('https://EXAMPLE.com:443/gallery/') === 'https://example.com/gallery', 'Base normalization failed.');
foreach (['http://example.com', 'https://user@example.com', 'https://example.com/?token=x', 'https://127.0.0.1', 'https://localhost', 'https://example.com/%2e%2e', "https://example.com/\n"] as $url) {
    coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_peer_base_url($url), 'invalid_peer_url');
}
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_peer_new($a, $a, 'https://example.com'), 'self_friendship');
$peer = cooperative_peer_new($a, $b, 'https://example.com');
coop_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ fn() => cooperative_peer_confirm($peer, 'local_consent'), 'invalid_pairing_confirmation');
$peer['incoming_hash'] = hash('sha256', 'test-only');
$peer['outgoing_cipher'] = 'opaque';
foreach (['local_consent', 'remote_consent', 'inbound_verified'] as $fact) {
    $peer = cooperative_peer_confirm($peer, $fact);
    coop_check($peer['state'] === 'pending', 'Incomplete pairing became active.');
}
$peer = cooperative_peer_confirm($peer, 'outbound_verified');
coop_check($peer['state'] === 'active' && !isset(cooperative_peer_summary($peer)['incoming_hash']), 'Peer summary leaks credentials.');
$token = cooperative_credential_generate();
coop_check(cooperative_credential_valid($token) && !cooperative_credential_valid('pgu_' . substr($token, 4)), 'Credential namespace overlap.');
$key = random_bytes(32);
$cipher = security_secret_seal($token, $key, 'peer:A:B');
coop_check(!str_contains($cipher, $token) && security_secret_open($cipher, $key, 'peer:A:B') === $token, 'Secret round trip failed.');
coop_check(security_secret_open($cipher, $key, 'peer:A:C') === null, 'Secret transplant accepted.');
coop_check(security_secret_open($cipher, random_bytes(32), 'peer:A:B') === null, 'Wrong key accepted.');
$payload = base64_decode(substr($cipher, 5), true);
$payload[20] = chr(ord($payload[20]) ^ 1);
coop_check(security_secret_open('gcm1:' . base64_encode($payload), $key, 'peer:A:B') === null, 'Tampered tag accepted.');
coop_check(security_secret_open('gcm1:' . base64_encode('short'), $key, 'peer:A:B') === null, 'Short tag accepted.');
$stamp = \Gallery\Services\cooperative_friendship_stamp($a, 6, $b, 8);
coop_check($stamp === \Gallery\Services\cooperative_friendship_stamp($b, 8, $a, 6), 'Evidence stamp is not symmetric.');
coop_check($stamp !== \Gallery\Services\cooperative_friendship_stamp($a, 7, $b, 8), 'Credential generation missing from evidence.');
$renewal = cooperative_group_propose(cooperative_group_suspend($active), $a, $members, $now);
$renewDigest = $renewal['pending']['digest'];
$renewal = cooperative_group_approve(cooperative_group_approve($renewal, $a, $renewDigest, $now), $b, $renewDigest, $now);
$renewed = cooperative_group_activate($renewal, $renewDigest, $now, $friends);
coop_check($renewed['state'] === 'active' && $renewed['revision'] === 3, 'Unanimous renewal failed.');
echo "Cooperative galleries foundation contracts passed.\n";