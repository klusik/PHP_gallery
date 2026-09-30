<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/composition.php
 * Module Type: Service
 * Purpose: Compose initial public collaborations from locally selected albums and non-authorizing references.
 * Responsibilities: Validate bounded reference codes and direct friendships before preparing a local member.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/** Prepare a portable public album reference without approving any proposal.
 * @param int $galleryId Explicit local source album selected by its administrator.
 * @param bool $photos Explicit permission to propose thumbnail and preview sharing.
 * @return string Nonsecret reference code; never a credential or acceptance token.
 */
function cooperative_album_reference_code(int $galleryId, bool $photos = false): string
{
    $member = cooperative_album_source_member($galleryId, $photos ? ['metadata', 'thumbnail', 'preview'] : ['metadata']);
    return 'pga1.' . rtrim(strtr(base64_encode(json_encode($member, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
}

/** Decode one strict public reference with no URLs or forwarded consent.
 * Purpose: Bound reference decoding. Type: integer. Units: bytes. Scope: one reference.
 * Consumers: composition parser. Rationale: 512 bytes cover two IDs and fixed metadata scope.
 * @param string $code Non-authorizing reference supplied by another administrator.
 * @return array{instance_id:string,album_id:string,scopes:list<string>} Exact canonical referenced member.
 */
function cooperative_album_reference_decode(string $code): array
{
    if (strlen($code) > 512 || preg_match('/\Apga1\.([A-Za-z0-9_-]+)\z/', $code, $match) !== 1) {
        throw new CooperativeException('invalid_reference');
    }
    try {
        $json = base64_decode(strtr($match[1], '-_', '+/'), true);
        $member = json_decode($json === false ? '' : $json, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($member)) { throw new \RuntimeException(); }
        $keys = array_keys($member);
        sort($keys);
        if ($keys !== ['album_id', 'instance_id', 'scopes'] || !is_string($member['instance_id'])
            || !is_string($member['album_id']) || !in_array($member['scopes'], [['metadata'], ['metadata', 'preview', 'thumbnail']], true)) {
            throw new \RuntimeException();
        }
        cooperative_id_validate($member['instance_id']);
        cooperative_id_validate($member['album_id']);
        $canonical = 'pga1.' . rtrim(strtr(base64_encode(json_encode($member, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        if (!hash_equals($canonical, $code)) { throw new \RuntimeException(); }
        return $member;
    } catch (\Throwable) {
        throw new CooperativeException('invalid_reference');
    }
}

/** Create a retryable proposal from local selection and one reference per remote participant.
 * Purpose: Bound pasted composition input. Type: integer. Units: bytes and participants.
 * Scope: Admin proposal composition. Consumers: parser and existing member normalizer.
 * Rationale: 16 KiB admits at most 31 small reference codes without an unbounded split.
 * @param string $requestId Stable form intent retained across failed or lost responses.
 * @param int $galleryId Local album selected for public collaboration.
 * @param string $references Newline-separated remote references, never network addresses.
 * @param bool $photos Explicit local media permission; old callers keep metadata-only behavior.
 * @return array<string,mixed> Newly created or replayed pending proposal with no implicit approval.
 */
function cooperative_proposal_compose(string $requestId, int $galleryId, string $references, bool $photos = false): array
{
    cooperative_proposal_exchange_ready();
    cooperative_id_validate($requestId);
    if (strlen($references) > COOPERATIVE_PAIRING_MAX_BYTES) { throw new CooperativeException('invalid_reference'); }
    $codes = preg_split('/\R/', trim($references));
    if (!is_array($codes) || count($codes) < 1 || count($codes) >= COOPERATIVE_MAX_MEMBERS) {
        throw new CooperativeException('invalid_members');
    }
    $members = [];
    $seen = [];
    foreach ($codes as $code) {
        $member = cooperative_album_reference_decode(trim($code));
        if (isset($seen[$member['instance_id']])) { throw new CooperativeException('duplicate_instance'); }
        $peer = cooperative_peer_status($member['instance_id']);
        if ($peer === null || $peer['state'] !== 'active') { throw new CooperativeException('friendship_unavailable'); }
        $seen[$member['instance_id']] = true;
        $members[] = $member;
    }
    $members[] = cooperative_album_source_member($galleryId, $photos ? ['metadata', 'thumbnail', 'preview'] : ['metadata']);
    return cooperative_proposal_exchange_create($requestId, $members);
}

/** Prepare a larger collaboration without altering the existing group's access or consents.
 * Every member must review the new immutable proposal, including its new coordinator.
 * @param string $groupId Existing collaboration selected by a current participant.
 * @param int $expected Expected local storage revision.
 * @param string $requestId Stable identity for the new proposal, retained across retries.
 * @param string $reference Exactly one new participant's non-authorizing album reference.
 * @return array<string,mixed> New unapproved group containing every unchanged existing member.
 */
function cooperative_proposal_expand(string $groupId, int $expected, string $requestId, string $reference): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    if ($stored['storage_revision'] !== $expected) { throw new CooperativeException('revision_conflict'); }
    $group = $stored['group'];
    if ($group['state'] !== 'active' || cooperative_proposal_own_decision($group)['decision'] !== 'approved') {
        throw new CooperativeException('proposal_not_active');
    }
    $member = cooperative_album_reference_decode(trim($reference));
    $peer = cooperative_peer_status($member['instance_id']);
    if (($peer['state'] ?? '') !== 'active') { throw new CooperativeException('friendship_unavailable'); }
    $members = $group['members'];
    $members[] = $member;
    return cooperative_proposal_exchange_create($requestId, cooperative_members_normalize($members));
}
