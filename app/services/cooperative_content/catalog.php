<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_content/catalog.php
 * Module Type: Service
 * Purpose: Export cursor-paginated photographs under exact direct public grants.
 * Responsibilities: Enforce explicit public cooperative grants without exposing system credentials.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;


/** Validate a photo catalog request before any optional storage or network activity.
 * @param array<string,mixed> $message Untrusted bounded JSON.
 * @return void Refuse extra fields, malformed IDs and unbounded cursors.
 */
function cooperative_content_message_validate(array $message): void
{
    $cursor = $message['cursor'] ?? null;
    unset($message['cursor']);
    cooperative_metadata_message_validate($message);
    if (!is_string($cursor) || strlen($cursor) > 1600
        || ($cursor !== '' && preg_match('/\A[A-Za-z0-9_-]+\z/', $cursor) !== 1)) {
        throw new CooperativeException('invalid_message');
    }
}

/** Read one source page after a checked grant; return tickets rather than paths or remote URLs.
 * @param array<string,mixed> $group Current local aggregate with a valid lease.
 * @param string $requester Authenticated peer, or local public viewer's installation.
 * @param string $album Exact authorized local album.
 * @param string $cursor Opaque continuation, empty on the first page.
 * @return array<string,mixed> Whitelisted public title, photos, continuation and deadline.
 */
function cooperative_content_catalog(array $group, string $requester, string $album, string $cursor): array
{
    if (!cooperative_activation_allows_scope($group, $requester, $album, $group['revision'], 'metadata')) {
        throw new CooperativeException('content_unauthorized');
    }
    cooperative_content_schema_assert();
    $galleryId = cooperative_album_local_id($album);
    $gallery = \Gallery\Models\gallery_model_find_by_id($galleryId);
    $binding = ['group' => $group['group_id'], 'album' => $album, 'requester' => $requester, 'revision' => $group['revision']];
    $after = 0;
    if ($cursor !== '') {
        $decoded = cooperative_content_open($cursor, 'cursor');
        foreach ($binding as $key => $value) {
            if (($decoded[$key] ?? null) !== $value) { throw new CooperativeException('content_unauthorized'); }
        }
        if (!is_int($decoded['after'] ?? null) || $decoded['after'] < 1) { throw new CooperativeException('invalid_cursor'); }
        $after = $decoded['after'];
    }
    $scope = cooperative_proposal_local_member(cooperative_proposal_document($group)['body'])['scopes'];
    $photos = [];
    $next = null;
    if (in_array('thumbnail', $scope, true)) {
        $rows = \Gallery\Models\cooperative_model_photos_page($galleryId, $after, COOPERATIVE_PHOTO_PAGE_SIZE + 1);
        foreach (array_slice($rows, 0, COOPERATIVE_PHOTO_PAGE_SIZE) as $row) {
            if (!cooperative_content_image_allowed($row, $galleryId)) { continue; }
            $ticket = cooperative_content_seal($binding + ['image' => (int) $row['id'],
                'expires' => $group['exchange']['lease']['expires_at'],
                'peer_revision' => $requester === cooperative_instance_id() ? 0 : cooperative_peer_status($requester)['revision'],
                'scopes' => array_values(array_intersect($scope, ['thumbnail', 'preview']))], 'media');
            preg_match('/\A.{0,160}/us', (string) $row['filename'], $alt);
            $photos[] = ['ticket' => $ticket, 'alt' => $alt[0] ?? '', 'preview' => in_array('preview', $scope, true)];
        }
        if (count($rows) > COOPERATIVE_PHOTO_PAGE_SIZE) {
            $next = cooperative_content_seal($binding + ['after' => (int) $rows[COOPERATIVE_PHOTO_PAGE_SIZE - 1]['id']], 'cursor');
        }
    }
    preg_match('/\A.{0,512}/us', (string) ($gallery['title'] ?? ''), $title);
    $current = cooperative_proposal_exchange_load($group['group_id'])['group'];
    if (!cooperative_activation_allows_scope($current, $requester, $album, $group['revision'], 'metadata')
        || $current['exchange']['lease'] !== $group['exchange']['lease']) { throw new CooperativeException('content_unauthorized'); }
    return ['title' => $title[0] ?? '', 'photos' => $photos, 'next_cursor' => $next,
        'expires_at' => $group['exchange']['lease']['expires_at']];
}

/** Serve a directly authenticated catalog request with at most one verification operation.
 * @param array<string,mixed> $message Exact peer request.
 * @param string $bearer Header-only directed system key.
 * @return array<string,mixed> Correlated page or a bounded renewal-pending response.
 */
function cooperative_content_export(array $message, string $bearer): array
{
    cooperative_require_enabled();
    cooperative_content_message_validate($message);
    if (cooperative_peer_authenticate($message['sender_id'], $bearer) === null
        || $message['recipient_id'] !== cooperative_instance_id()) { throw new CooperativeException('content_unauthorized'); }
    $stored = cooperative_proposal_exchange_load($message['group_id']);
    $member = cooperative_proposal_local_member(cooperative_proposal_document($stored['group'])['body']);
    if ($message['album_id'] !== $member['album_id']
        || !in_array($message['sender_id'], array_column(cooperative_proposal_document($stored['group'])['body']['members'], 'instance_id'), true)
        || $message['revision'] !== 1) { throw new CooperativeException('content_unauthorized'); }
    $group = cooperative_content_progress($message['group_id']);
    $reply = ['ok' => true, 'protocol' => COOPERATIVE_PROTOCOL_VERSION, 'group_id' => $message['group_id'],
        'sender_id' => $message['recipient_id'], 'recipient_id' => $message['sender_id'],
        'album_id' => $message['album_id'], 'revision' => $message['revision']];
    if (!cooperative_activation_lease_valid($group)) { return $reply + ['pending' => true]; }
    if (cooperative_peer_authenticate($message['sender_id'], $bearer) === null) { throw new CooperativeException('content_unauthorized'); }
    return $reply + ['pending' => false, 'catalog' => cooperative_content_catalog($group, $message['sender_id'], $message['album_id'], $message['cursor'])];
}
