<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_thumbnail_batch.php
 * Module Type: Service
 * Purpose: Revalidate and prepare bounded native thumbnail batch frames.
 * Responsibilities: Enforce weighted admission, current authority, bounded
 *   file reads and integrity before yielding protocol frames.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/**
 * Bound batch transport resources.
 * Type: array<string,int>. Units: bytes, items, and seconds as named.
 * Scope: one v1 thumbnail response and request.
 * Consumers: batch request parsing and frame generation.
 * Rationale: 16 KiB requests and 32 IDs bound admission; 8 MiB total, 4 MiB
 *     per image and 2048 bytes metadata bound response memory and transfer.
 *     8192-byte chunks bound individual reads and writes. The conservative
 *     30-second deadline is an unmeasured safety bound, not a host optimum.
 */
const APP_LINKING_BATCH_LIMITS = [
    'request' => 16384, 'count' => 32, 'body' => 8388608,
    'image' => 4194304, 'metadata' => 2048, 'chunk' => 8192,
    'deadline' => 30,
];


/**
 * Reauthenticate and resolve the same live gallery before a frame or payload.
 *
 * @param array{grant_id:int,user_id:int,username:string,scope:string} $identity Original identity.
 * @param string $token Original bearer.
 * @param string $issuer Verified installation issuer.
 * @param int $galleryId Requested physical gallery.
 * @return array{id:int|string,parent_id:int|string|null,folder_path:string,gps_map_enabled?:int|string|null,presentation_json?:?string}|null Currently authorized gallery.
 */
function app_linking_batch_gallery(array $identity, string $token, string $issuer, int $galleryId): ?array
{
    if (!app_linking_thumbnail_batch_enabled()) {
        return null;
    }
    $live = app_linking_authenticate($token, $issuer);
    if ($live === null || (!app_linking_scope_allows_admin_media($live['scope'])
            && !str_contains($live['scope'], ' media:read'))
        || $live['grant_id'] !== $identity['grant_id']
        || $live['user_id'] !== $identity['user_id'] || $live['scope'] !== $identity['scope']) {
        return null;
    }
    return app_linking_scope_allows_admin_media($live['scope'])
        ? app_linking_admin_media_gallery($live, $token, $issuer, $galleryId)
        : app_linking_media_gallery($live['scope'], $galleryId, false);
}

/**
 * Admit weighted photo work against all existing shared and account budgets.
 *
 * @param int $accountId Current authenticated account identifier.
 * @param int $count Number of requested photo reads.
 * @return array{allowed:bool,retry_after_seconds:int,attempts:int,reason:string} First refusal or final admission.
 */
function app_linking_batch_admit(int $accountId, int $count): array
{
    foreach (['app_link_photo_ip' => request_client_ip(),
        'app_link_photo_global' => 'global',
        'app_link_photo_account' => (string) $accountId] as $bucket => $subject) {
        $decision = app_linking_rate_limit_consume($bucket, $subject, $count);
        if (!$decision['allowed']) {
            return $decision;
        }
    }
    return $decision;
}

/**
 * Yield one bounded protocol body with live authority and exact byte integrity.
 *
 * @param array{grant_id:int,user_id:int,username:string,scope:string} $identity Original identity.
 * @param string $token Original bearer credential.
 * @param string $issuer Verified issuer.
 * @param array{gallery_id:int,image_ids:list<string>} $request Validated canonical request.
 * @param bool $admin Current Admin media authority.
 * @param string|null $preload Exact independently approved preload header or null.
 * @param int $started Monotonic request start in nanoseconds.
 * @return \Generator<int,string,void,void> Ordered wire chunks; an empty chunk
 *     marks one complete item for optional flushing, and truncation indicates failure.
 */
function app_linking_batch_frames(
    array $identity, string $token, string $issuer, array $request,
    bool $admin, ?string $preload, int $started,
): \Generator {
    $stream = null;
    try {
        $count = count($request['image_ids']);
        // PGB1 wire header: four magic bytes, one version byte and a uint16 count.
        // Version 1 metadata has the fixed contract keys and a zero-based index.
        yield 'PGB1' . pack('Cn', 1, $count);
        $used = 7;
        foreach ($request['image_ids'] as $index => $id) {
            // hrtime(true) is in nanoseconds; one second is 1,000,000,000 ns.
            if (connection_aborted() || hrtime(true) - $started > APP_LINKING_BATCH_LIMITS['deadline'] * 1000000000) {
                return;
            }
            $gallery = app_linking_batch_gallery($identity, $token, $issuer, $request['gallery_id']);
            if ($gallery === null || !app_linking_media_photo_admitted($gallery, (int) $id, $admin)) {
                return;
            }
            // Each item prefix is I + uint16 metadata length + uint32 data length
            // (7 bytes); the final E + uint16 completed count occupies 3 bytes.
            $reserve = ($count - $index) * (7 + APP_LINKING_BATCH_LIMITS['metadata']) + 3;
            $remaining = APP_LINKING_BATCH_LIMITS['body'] - $used - $reserve;
            if ($remaining < 0) {
                return;
            }
            $public = $admin && gallery_public_export_policy($request['gallery_id'])['allowed'];
            $photo = app_linking_media_open_photo($gallery, (int) $id, true, $admin, $public,
                $preload === 'admin-v1', min(APP_LINKING_BATCH_LIMITS['image'], $remaining), true, true);
            $status = isset($photo['oversized']) ? 'oversize' : 'unavailable';
            $bytes = '';
            $mime = 'application/octet-stream';
            $digest = null;
            $etag = null;
            if (isset($photo['stream'])) {
                $stream = $photo['stream'];
                if (in_array($photo['mime'], ['image/jpeg', 'image/webp'], true)) {
                    $before = fstat($stream);
                    while (!feof($stream) && strlen($bytes) < $photo['size']) {
                        if (connection_aborted() || hrtime(true) - $started > APP_LINKING_BATCH_LIMITS['deadline'] * 1000000000) {
                            return;
                        }
                        $part = fread($stream, min(APP_LINKING_BATCH_LIMITS['chunk'], $photo['size'] - strlen($bytes)));
                        if (!is_string($part) || $part === '') {
                            return;
                        }
                        $bytes .= $part;
                    }
                    $after = fstat($stream);
                    if (strlen($bytes) !== $photo['size'] || !is_array($before) || !is_array($after)
                        || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']
                        || $before['ctime'] !== $after['ctime']) {
                        return;
                    }
                    $digest = hash('sha256', $bytes);
                    $etag = $photo['validator_allowed'] ? '"' . $digest . '"' : null;
                    if ($preload === 'admin-v1' && $etag === null) {
                        return;
                    }
                    $mime = $photo['mime'];
                    $status = 'ok';
                }
                fclose($stream);
                $stream = null;
            }
            $gallery = app_linking_batch_gallery($identity, $token, $issuer, $request['gallery_id']);
            if ($gallery === null) {
                return;
            }
            $validatorAllowed = app_linking_media_current_validator_allowed(
                $gallery, (int) $id, $admin, $preload === 'admin-v1',
            );
            if ($validatorAllowed === null) {
                return;
            }
            // A current permission can narrow, never widen, the policy that
            // was applied when this exact file was opened.
            if (!$validatorAllowed) {
                $etag = null;
                if ($preload === 'admin-v1' && $status === 'ok') {
                    return;
                }
            }
            $metadata = json_encode(['v' => 1, 'image_id' => $id, 'request_index' => $index,
                'status' => $status, 'mime' => $mime, 'length' => strlen($bytes),
                'sha256' => $digest, 'etag' => $etag], JSON_THROW_ON_ERROR);
            if (strlen($metadata) > APP_LINKING_BATCH_LIMITS['metadata']) {
                return;
            }
            yield 'I' . pack('nN', strlen($metadata), strlen($bytes)) . $metadata;
            $gallery = app_linking_batch_gallery($identity, $token, $issuer, $request['gallery_id']);
            if ($gallery === null || !app_linking_media_photo_admitted($gallery, (int) $id, $admin)) {
                return;
            }
            for ($offset = 0; $offset < strlen($bytes); $offset += APP_LINKING_BATCH_LIMITS['chunk']) {
                if (connection_aborted() || hrtime(true) - $started > APP_LINKING_BATCH_LIMITS['deadline'] * 1000000000) {
                    return;
                }
                yield substr($bytes, $offset, APP_LINKING_BATCH_LIMITS['chunk']);
            }
            $used += 7 + strlen($metadata) + strlen($bytes);
            unset($bytes);
            // Empty output marks an item boundary without changing wire bytes.
            yield '';
        }
        if (!connection_aborted()
            && app_linking_batch_gallery($identity, $token, $issuer, $request['gallery_id']) !== null
            && hrtime(true) - $started <= APP_LINKING_BATCH_LIMITS['deadline'] * 1000000000) {
            yield 'E' . pack('n', $count);
        }
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
