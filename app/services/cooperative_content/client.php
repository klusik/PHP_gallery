<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_content/client.php
 * Module Type: Service
 * Purpose: Read one paired source at a time and construct only fixed-origin media URLs.
 * Responsibilities: Keep public cooperation bounded, source-authorized and independent of admin sessions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;


/** Expose a programmatic isolated fixture adapter, never selectable by requests.
 * @return callable|null Test-only content transport, or the pinned HTTPS implementation.
 */
function &cooperative_content_transport_override(): mixed
{
    static $transport = null;
    return $transport;
}

/** Validate and whitelist a remote source page before it reaches public presentation.
 * @param array<string,mixed> $response Untrusted bounded peer JSON.
 * @param array<string,mixed> $message Exact request for correlation.
 * @return array<string,mixed>|null Safe catalog or a renewal-pending result.
 */
function cooperative_content_response(array $response, array $message): ?array
{
    foreach (['protocol', 'group_id', 'album_id', 'revision'] as $key) {
        if (($response[$key] ?? null) !== $message[$key]) { throw new CooperativeException('peer_response_invalid'); }
    }
    if (($response['ok'] ?? null) !== true || ($response['sender_id'] ?? '') !== $message['recipient_id']
        || ($response['recipient_id'] ?? '') !== $message['sender_id'] || !is_bool($response['pending'] ?? null)) {
        throw new CooperativeException('peer_response_invalid');
    }
    if ($response['pending']) { return null; }
    $catalog = $response['catalog'] ?? null;
    if (!is_array($catalog) || !is_string($catalog['title'] ?? null) || strlen($catalog['title']) > 2048
        || !is_int($catalog['expires_at'] ?? null) || $catalog['expires_at'] <= time()
        || $catalog['expires_at'] > time() + COOPERATIVE_VERIFICATION_TTL
        || !is_array($catalog['photos'] ?? null) || !array_is_list($catalog['photos'])
        || count($catalog['photos']) > COOPERATIVE_PHOTO_PAGE_SIZE
        || !array_key_exists('next_cursor', $catalog)) { throw new CooperativeException('peer_response_invalid'); }
    $cursor = $catalog['next_cursor'];
    if ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 1600 || preg_match('/\A[A-Za-z0-9_-]+\z/', $cursor) !== 1)) {
        throw new CooperativeException('peer_response_invalid');
    }
    $photos = [];
    foreach ($catalog['photos'] as $photo) {
        if (!is_array($photo) || !is_string($photo['ticket'] ?? null) || strlen($photo['ticket']) > 1600
            || preg_match('/\A[A-Za-z0-9_-]+\z/', $photo['ticket']) !== 1
            || !is_string($photo['alt'] ?? null) || strlen($photo['alt']) > 640 || !is_bool($photo['preview'] ?? null)) {
            throw new CooperativeException('peer_response_invalid');
        }
        $photos[] = ['ticket' => $photo['ticket'], 'alt' => $photo['alt'], 'preview' => $photo['preview']];
    }
    return ['title' => $catalog['title'], 'photos' => $photos, 'next_cursor' => $cursor, 'expires_at' => $catalog['expires_at']];
}

/** Load a public collaboration shell without contacting peers or granting new consent.
 * @param string $groupId Opaque locally approved collaboration.
 * @return array<string,mixed> Public local label and known source identities.
 */
function cooperative_content_page(string $groupId): array
{
    $group = cooperative_proposal_exchange_load($groupId)['group'];
    if ($group['state'] !== 'active' || cooperative_proposal_own_decision($group)['decision'] !== 'approved') {
        throw new CooperativeException('content_unauthorized');
    }
    $body = cooperative_proposal_document($group)['body'];
    $galleryId = cooperative_proposal_source_assert($body);
    $gallery = \Gallery\Models\gallery_model_find_by_id($galleryId);
    $local = cooperative_instance_id();
    $sources = [];
    foreach ($body['members'] as $member) {
        $peer = $member['instance_id'] === $local ? null : cooperative_peer_status($member['instance_id']);
        $sources[] = ['instance_id' => $member['instance_id'], 'local' => $member['instance_id'] === $local,
            'origin' => $peer['base_url'] ?? '', 'album_id' => $member['album_id']];
    }
    return ['group_id' => $groupId, 'title' => (string) $gallery['title'], 'sources' => $sources, 'local_id' => $local];
}

/** Fetch a single source page, performing at most one local maintenance step or one peer request.
 * @param string $groupId Exact local collaboration.
 * @param string $source Selected member identity, never an arbitrary URL.
 * @param string $cursor Source-issued opaque pagination cursor.
 * @return array<string,mixed> Pending status or a whitelisted catalog and trusted paired base.
 */
function cooperative_content_read(string $groupId, string $source, string $cursor = ''): array
{
    $page = cooperative_content_page($groupId);
    $selected = null;
    foreach ($page['sources'] as $candidate) {
        if ($candidate['instance_id'] === $source) { $selected = $candidate; }
    }
    if ($selected === null || strlen($cursor) > 1600) { throw new CooperativeException('content_unauthorized'); }
    $group = cooperative_content_progress($groupId);
    if (!cooperative_activation_lease_valid($group)) { return ['pending' => true]; }
    if ($source === $page['local_id']) {
        return ['pending' => false, 'base' => '', 'catalog' => cooperative_content_catalog($group, $source, $selected['album_id'], $cursor)];
    }
    $peer = cooperative_peer_status($source);
    if (($peer['state'] ?? '') !== 'active') { throw new CooperativeException('friendship_unavailable'); }
    $base = cooperative_peer_base_url($peer['base_url']);
    $message = ['protocol' => COOPERATIVE_PROTOCOL_VERSION, 'group_id' => $groupId, 'sender_id' => $page['local_id'],
        'recipient_id' => $source, 'album_id' => $selected['album_id'], 'revision' => $group['revision'], 'cursor' => $cursor];
    $reserved = cooperative_content_admission_reserve($groupId, $group, $source);
    if ($reserved === null) { return ['pending' => true]; }
    $override = &cooperative_content_transport_override();
    try {
        $bearer = cooperative_peer_outbound_credential($source);
        $response = is_callable($override) ? $override($base, $message, $bearer)
            : outbound_http_json_request($base . '/index.php?page=cooperative_content_api', $message, $bearer);
        if (!is_array($response) || strlen(json_encode($response, JSON_THROW_ON_ERROR)) > COOPERATIVE_PAIRING_MAX_BYTES) {
            throw new \RuntimeException();
        }
    } catch (\Throwable) {
        cooperative_content_admission_finish($reserved, $peer, false);
        throw new CooperativeException('peer_unavailable');
    }
    try {
        $catalog = cooperative_content_response($response, $message);
    } catch (\Throwable $error) {
        cooperative_content_admission_finish($reserved, $peer, false);
        throw $error;
    }
    // Returning content requires the same owned reservation and fresh consent,
    // lease and direct peer snapshot; stale replies cannot release a newer owner.
    if (!cooperative_content_admission_finish($reserved, $peer, true)) {
        throw new CooperativeException('content_unauthorized');
    }
    return $catalog === null ? ['pending' => true] : ['pending' => false, 'base' => $base, 'catalog' => $catalog];
}

/** Discover only local approved collaborations when the optional feature is enabled.
 * @param int $galleryId Public local gallery being displayed.
 * @return list<string> Approved collaboration IDs; unavailable optional storage produces no links.
 */
function cooperative_content_gallery_groups(int $galleryId): array
{
    if (!feature_capability_effective_enabled('cooperative_galleries')) { return []; }
    try {
        cooperative_proposal_exchange_ready();
        if (!gallery_public_export_policy($galleryId)['allowed']) { return []; }
        $ids = [];
        foreach (\Gallery\Models\cooperative_model_gallery_groups($galleryId) as $id) {
            try { cooperative_content_page($id); $ids[] = $id; } catch (\Throwable) { continue; }
        }
        return $ids;
    } catch (\Throwable) { return []; }
}
