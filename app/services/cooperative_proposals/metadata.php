<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals/metadata.php
 * Module Type: Service
 * Purpose: Export a minimal public album label under an exact current cooperative grant.
 * Responsibilities: Validate requests, reuse direct-credential authorization and whitelist response fields.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/** Validate an exact metadata read independently of credential and source authority.
 * @param array<string,mixed> $message Untrusted bounded JSON object.
 * @return void Reject unknown fields, unsupported protocols and untyped revisions.
 */
function cooperative_metadata_message_validate(array $message): void
{
    $keys = array_keys($message);
    sort($keys);
    if ($keys !== ['album_id', 'group_id', 'protocol', 'recipient_id', 'revision', 'sender_id']
        || $message['protocol'] !== COOPERATIVE_PROTOCOL_VERSION || !is_int($message['revision']) || $message['revision'] < 1) {
        throw new CooperativeException('invalid_message');
    }
    foreach (['album_id', 'group_id', 'recipient_id', 'sender_id'] as $key) {
        if (!is_string($message[$key])) { throw new CooperativeException('invalid_message'); }
        cooperative_id_validate($message[$key]);
    }
}

/** Export only a currently public authorized title, never raw rows or media addresses.
 * Purpose: Bound response text. Type: integer. Units: Unicode characters.
 * Scope: exported album label. Consumers: authenticated peer metadata clients.
 * Rationale: 512 characters keep the complete reply below the 16 KiB transport cap.
 * @param array<string,mixed> $message Exact request for a local album and membership revision.
 * @param string $bearer Directed system credential from the Authorization header.
 * @return array<string,mixed> Whitelisted title, correlation and fixed authorization deadline.
 */
function cooperative_metadata_export(array $message, string $bearer): array
{
    cooperative_require_enabled();
    cooperative_metadata_message_validate($message);
    if (!cooperative_activation_allows_metadata($message['group_id'], $message['sender_id'], $bearer, $message['album_id'], $message['revision'])) {
        throw new CooperativeException('metadata_unauthorized');
    }
    if ($message['recipient_id'] !== cooperative_instance_id()) { throw new CooperativeException('metadata_unauthorized'); }
    $stored = cooperative_proposal_exchange_load($message['group_id']);
    $galleryId = cooperative_album_local_id($message['album_id']);
    $gallery = $galleryId !== null ? \Gallery\Models\gallery_model_find_by_id($galleryId) : null;
    if ($gallery === null || !is_string($gallery['title'] ?? null)
        || preg_match('/\A.{0,512}/us', $gallery['title'], $title) !== 1) {
        throw new CooperativeException('metadata_unavailable');
    }
    // Recheck public source policy and credentials after reading the title.
    // Concurrent renewal must not lend a new deadline to this old read.
    if (!cooperative_activation_allows_metadata($message['group_id'], $message['sender_id'], $bearer, $message['album_id'], $message['revision'])
        || cooperative_proposal_exchange_load($message['group_id'])['storage_revision'] !== $stored['storage_revision']) {
        throw new CooperativeException('metadata_unauthorized');
    }
    return ['ok' => true, 'protocol' => COOPERATIVE_PROTOCOL_VERSION,
        'sender_id' => $message['recipient_id'], 'recipient_id' => $message['sender_id'],
        'group_id' => $message['group_id'], 'revision' => $message['revision'],
        'album' => ['album_id' => $message['album_id'], 'title' => $title[0]],
        'authorization_expires_at' => $stored['group']['exchange']['lease']['expires_at']];
}
