<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_content/media.php
 * Module Type: Service
 * Purpose: Resolve only current, source-issued thumbnail and preview tickets.
 * Responsibilities: Enforce explicit public cooperative grants without exposing system credentials.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;


/** Resolve a revocable media ticket to an existing derivative; never fall back to an original.
 * @param string $ticket Source-issued, purpose-bound encrypted media capability.
 * @param string $scope Explicit thumbnail or preview operation.
 * @return array{bytes:string,mime:string} Sanitized existing derivative for the HTTP responder.
 */
function cooperative_content_media(string $ticket, string $scope): array
{
    cooperative_require_enabled();
    if (!in_array($scope, ['thumbnail', 'preview'], true)) { throw new CooperativeException('content_unauthorized'); }
    $data = cooperative_content_open($ticket, 'media');
    if (!is_int($data['expires'] ?? null) || time() >= $data['expires']
        || !in_array($scope, $data['scopes'] ?? [], true)) { throw new CooperativeException('content_unauthorized'); }
    $group = cooperative_proposal_exchange_load($data['group'])['group'];
    if (!cooperative_activation_allows_scope($group, $data['requester'], $data['album'], $data['revision'], $scope)) {
        throw new CooperativeException('content_unauthorized');
    }
    if ($data['requester'] !== cooperative_instance_id()) {
        $peer = cooperative_peer_status($data['requester']);
        if (($peer['state'] ?? '') !== 'active' || $peer['revision'] !== $data['peer_revision']) {
            throw new CooperativeException('content_unauthorized');
        }
    }
    cooperative_content_schema_assert();
    $galleryId = cooperative_album_local_id($data['album']);
    $image = \Gallery\Models\image_model_find_by_id($data['image']);
    if (!cooperative_content_image_allowed($image, $galleryId)) { throw new CooperativeException('content_unauthorized'); }
    try { thumbnail_assert_source_identity_owned($image); }
    catch (\RuntimeException) { throw new CooperativeException('content_unavailable'); }
    $gallery = \Gallery\Models\gallery_model_find_by_id($galleryId);
    $root = realpath(gallery_thumbs_dir($gallery, false));
    $galleryRoot = realpath(gallery_abs_path((string) $gallery['folder_path']));
    if ($root === false || $galleryRoot === false || !str_starts_with($root, $galleryRoot . DIRECTORY_SEPARATOR)) {
        throw new CooperativeException('content_unavailable');
    }
    foreach ($scope === 'thumbnail' ? [600, 300] : [1600, 1280, 960, 800, 600, 300] as $size) {
        foreach (['webp' => 'image/webp', 'jpg' => 'image/jpeg'] as $format => $mime) {
            $path = realpath(thumbnail_abs_path($image, $gallery, $size, $format));
            if ($root !== false && $path !== false && is_file($path)
                && str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                $payload = cooperative_derivative_read($path, $mime);
                $currentGroup = cooperative_proposal_exchange_load($data['group'])['group'];
                $currentImage = \Gallery\Models\image_model_find_by_id($data['image']);
                if (time() >= $data['expires']
                    || !cooperative_activation_allows_scope($currentGroup, $data['requester'], $data['album'], $data['revision'], $scope)
                    || !cooperative_content_image_allowed($currentImage, $galleryId)
                    || ($currentImage['thumbnail_source_identity_version'] ?? null) !== ($image['thumbnail_source_identity_version'] ?? null)
                    || ($currentImage['relative_path'] ?? null) !== ($image['relative_path'] ?? null)
                    || ($currentImage['filename'] ?? null) !== ($image['filename'] ?? null)) {
                    throw new CooperativeException('content_unauthorized');
                }
                if ($data['requester'] !== cooperative_instance_id()) {
                    $currentPeer = cooperative_peer_status($data['requester']);
                    if (($currentPeer['state'] ?? '') !== 'active' || $currentPeer['revision'] !== $data['peer_revision']) {
                        throw new CooperativeException('content_unauthorized');
                    }
                }
                cooperative_derivative_assert_unchanged($path, $root, $payload['source_hash']);
                if (time() >= $data['expires']) { throw new CooperativeException('content_unauthorized'); }
                return ['bytes' => $payload['bytes'], 'mime' => $payload['mime']];
            }
        }
    }
    throw new CooperativeException('content_unavailable');
}
