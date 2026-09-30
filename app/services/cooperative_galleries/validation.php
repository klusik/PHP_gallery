<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_galleries/validation.php
 * Module Type: Service
 * Purpose: Validate bounded identifiers, peer addresses and public album manifests.
 * Responsibilities: Canonicalize domain inputs before hashing or persistence.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Loaded only through the cooperative_galleries module entry point.
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Generate a stable public identifier independent of local row IDs and URLs.
 *
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_id_generate(): string
{
    return security_token_selector_generate(16);
}

/** Require the canonical 128-bit lowercase identifier format.
 *
 * @param string $id Stable public identifier of the persisted domain object.
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_id_validate(string $id): string
{
    if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
        throw new CooperativeException('invalid_id');
    }
    return $id;
}

/**
 * Normalize an HTTPS installation base, including subdirectory installations.
 * This is syntax validation only. A future transport MUST pin validated public
 * DNS addresses and refuse redirects before attaching any credentials.
 *
 * @param string $url Candidate HTTPS installation base address.
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_peer_base_url(string $url): string
{
    if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
        throw new CooperativeException('invalid_peer_url');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new CooperativeException('invalid_peer_url');
    }
    $host = strtolower($parts['host'] ?? '');
    if (strlen($host) > 253 || !str_contains($host, '.')
        || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        || filter_var($host, FILTER_VALIDATE_IP) !== false
        || str_ends_with($host, '.') || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        throw new CooperativeException('invalid_peer_url');
    }
    $port = $parts['port'] ?? 443;
    if ($port < 1 || $port > 65535) {
        throw new CooperativeException('invalid_peer_url');
    }
    $path = $parts['path'] ?? '';
    if (preg_match('~\A(?:/[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*)*/?\z~', $path) !== 1) {
        throw new CooperativeException('invalid_peer_url');
    }
    return 'https://' . $host . ($port === 443 ? '' : ':' . $port) . rtrim($path, '/');
}

/** Canonical public-only membership; unknown fields fail rather than silently losing consent.
 *
 * @param list<array{instance_id:string,album_id:string,scopes:list<string>}> $members Participating installations with their album identities and granted scopes.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_members_normalize(array $members): array
{
    if (!array_is_list($members) || count($members) < 2 || count($members) > COOPERATIVE_MAX_MEMBERS) {
        throw new CooperativeException('invalid_members');
    }
    $result = [];
    $seen = [];
    foreach ($members as $member) {
        if (!is_array($member)) {
            throw new CooperativeException('invalid_member');
        }
        $keys = array_keys($member);
        sort($keys);
        if ($keys !== ['album_id', 'instance_id', 'scopes'] || !is_string($member['instance_id'])
            || !is_string($member['album_id']) || !is_array($member['scopes'])) {
            throw new CooperativeException('invalid_member');
        }
        $instance = cooperative_id_validate($member['instance_id']);
        $album = cooperative_id_validate($member['album_id']);
        if (isset($seen[$instance])) {
            throw new CooperativeException('duplicate_instance');
        }
        $scopes = cooperative_scopes_normalize($member['scopes']);
        $seen[$instance] = true;
        $result[] = ['instance_id' => $instance, 'album_id' => $album, 'scopes' => $scopes];
    }
    usort($result, /**
 * Compare participant identities for deterministic membership ordering.
 * @param array<string,mixed> $a First canonical member record to compare.
 * @param array<string,mixed> $b Second canonical member record to compare.
 * @return int Comparison or revision value for the operation.
 */ static fn(array $a, array $b): int => strcmp($a['instance_id'], $b['instance_id']));
    return $result;
}

/** Return all direct pair identities required by the membership clique.
 *
 * @param list<array{instance_id:string,album_id:string,scopes:list<string>}> $members Participating installations with their album identities and granted scopes.
 * @return array<string,mixed> Validated domain state described above.
 */
function cooperative_friendship_pairs(array $members): array
{
    $members = cooperative_members_normalize($members);
    $pairs = [];
    foreach ($members as $index => $member) {
        for ($other = $index + 1; $other < count($members); $other++) {
            $pairs[] = [$member['instance_id'], $members[$other]['instance_id']];
        }
    }
    return $pairs;
}
/**
 * Bind a direct friendship to both independently verified peer revisions.
 * This digest is an identity for evidence, not a signature or proof of trust.
 *
 * @param string $first First stable installation identity.
 * @param int $firstRevision Verified credential-state revision issued by the first installation.
 * @param string $second Second stable installation identity.
 * @param int $secondRevision Verified credential-state revision issued by the second installation.
 * @return string Symmetric SHA-256 evidence stamp that changes after either side changes trust.
 */
function cooperative_friendship_stamp(string $first, int $firstRevision, string $second, int $secondRevision): string
{
    cooperative_id_validate($first);
    cooperative_id_validate($second);
    if ($first === $second || $firstRevision < 1 || $secondRevision < 1) {
        throw new CooperativeException('invalid_friendship_evidence');
    }
    $revisions = [$first => $firstRevision, $second => $secondRevision];
    ksort($revisions, SORT_STRING);
    return hash('sha256', 'cooperative-friendship-v1:' . json_encode($revisions, JSON_THROW_ON_ERROR));
}

/** Normalize one album's exact requested scopes without inventing defaults.
 *
 * @param list<string> $scopes Requested operations on this source album.
 * @return list<string> Unique allowlisted scopes in canonical order, including metadata.
 */
function cooperative_scopes_normalize(array $scopes): array
{
    if (!array_is_list($scopes) || $scopes === [] || count($scopes) > 4) {
        throw new CooperativeException('invalid_scopes');
    }
    foreach ($scopes as $scope) {
        if (!is_string($scope) || !in_array($scope, ['metadata', 'thumbnail', 'preview', 'original'], true)) {
            throw new CooperativeException('invalid_scopes');
        }
    }
    if (count(array_unique($scopes)) !== count($scopes) || !in_array('metadata', $scopes, true)) {
        throw new CooperativeException('invalid_scopes');
    }
    sort($scopes, SORT_STRING);
    return $scopes;
}
