<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_thumbnail_batch_stream_test.php
 * Module Type: Regression test
 * Purpose: Exercise framed thumbnails through the real media and stream owners.
 * Responsibilities: Replace database, authority and path selection boundaries
 *   while retaining real admission, validator policy and verified file opening.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models {
    /**
     * Resolve the current fixture gallery through the model boundary.
     * @param int $grantId Current grant identifier.
     * @param int $userId Current account identifier.
     * @param string $hash Bearer hash.
     * @param string $issuer Installation issuer.
     * @param string $scope Current scope.
     * @param int $id Requested gallery identifier.
     * @param bool $admin Whether Admin access is requested.
     * @return array{id:int,folder_path:string}|null Current gallery.
     */
    function gallery_model_linked_detail(int $grantId, int $userId, string $hash, string $issuer, string $scope, int $id, bool $admin): ?array
    {
        return $GLOBALS['batch_gallery'] ? ['id' => $id, 'folder_path' => 'fixture'] : null;
    }
    /**
     * Return the fixture's current indexed photo.
     * @param int $id Requested photo identity.
     * @return array{id:int,gallery_id:int,relative_path:string,visibility:string,nsfw_enabled:int}|null Current row.
     */
    function image_model_find_by_id(int $id): ?array
    {
        $GLOBALS['batch_row_reads']++;
        if ($GLOBALS['batch_row_reads'] === ($GLOBALS['batch_mutate_at'] ?? -1)) {
            $GLOBALS['batch_public'] = false;
            $GLOBALS['batch_preload'] = false;
            $GLOBALS['batch_rows'][$id]['nsfw_enabled'] = 1;
        }
        return $GLOBALS['batch_rows'][$id] ?? null;
    }
}

namespace Gallery\Services {
    // PHP has no native resource type; mixed is narrowed by these fixture seams.
    /**
     * Track actual file handles opened by the media owner.
     * @param string $filename Candidate file path.
     * @param string $mode Requested file mode.
     * @return resource|false Opened handle or failure.
     */
    function fopen(string $filename, string $mode): mixed
    {
        $stream = \fopen($filename, $mode);
        if (is_resource($stream)) {
            $GLOBALS['batch_handles'][(int) $stream] = $stream;
        }
        return $stream;
    }

    /**
     * Record closure of a media owner handle.
     * @param resource $stream Opened file handle.
     * @return bool Whether the handle closed successfully.
     */
    function fclose(mixed $stream): bool
    {
        unset($GLOBALS['batch_handles'][(int) $stream]);
        return \fclose($stream);
    }

    /**
     * Optionally truncate the selected file before the real read.
     * @param resource $stream Opened file handle.
     * @param int $length Maximum bytes requested.
     * @return string|false Read bytes or failure.
     * @throws \RuntimeException When the fixture injects a read exception.
     */
    function fread(mixed $stream, int $length): string|false
    {
        if ($GLOBALS['batch_read_exception'] ?? false) {
            throw new \RuntimeException('Fixture read failure');
        }
        if ($GLOBALS['batch_shorten'] ?? false) {
            $GLOBALS['batch_shorten'] = false;
            \file_put_contents($GLOBALS['batch_short_path'], 'x');
        }
        return \fread($stream, $length);
    }

    /**
     * Hash a fixture bearer for the real Admin gallery owner.
     * @param string $token Bearer credential.
     * @return string|null Stable fixture hash.
     */
    function app_linking_access_token_hash(string $token): ?string
    {
        return 'fixture-hash';
    }
    /**
     * Report the current fixture capability state.
     * @return bool Whether the fixture permits the batch capability.
     */
    function app_linking_thumbnail_batch_enabled(): bool
    {
        return $GLOBALS['batch_enabled'];
    }
    /**
     * Return the current fixture identity.
     * @param string $token Bearer supplied by the stream.
     * @param string $issuer Installation issuer.
     * @return array{grant_id:int,user_id:int,username:string,scope:string}|null Live identity.
     */
    function app_linking_authenticate(string $token, string $issuer): ?array
    {
        return $GLOBALS['batch_identity'];
    }
    /**
     * Identify the fixture's Admin scope.
     * @param string $scope Current scope.
     * @return bool Whether Admin media is authorized.
     */
    function app_linking_scope_allows_admin_media(string $scope): bool
    {
        return $scope === 'admin';
    }
    /**
     * Return current public export authority.
     * @param int $id Gallery identity.
     * @return array{allowed:bool} Current export policy.
     */
    function gallery_public_export_policy(int $id): array
    {
        return ['allowed' => $GLOBALS['batch_public']];
    }
    /**
     * Return current private preload authority.
     * @param int $id Gallery identity.
     * @return bool Whether private preload is approved.
     */
    function gallery_admin_media_preload_gallery_allowed(int $id): bool
    {
        return $GLOBALS['batch_preload'];
    }
    /**
     * Suppress thumbnail variants to exercise the existing display fallback.
     * @param array{id:int,gallery_id:int,relative_path:string,visibility:string,nsfw_enabled:int} $image Indexed image.
     * @return bool Whether a legacy thumbnail identity is owned.
     */
    function thumbnail_legacy_identity_owned(array $image): bool
    {
        return false;
    }
    /**
     * Select a fixture display file.
     * @param array{id:int,gallery_id:int,relative_path:string,visibility:string,nsfw_enabled:int} $image Indexed image.
     * @param array{id:int,folder_path:string} $gallery Current gallery.
     * @param bool $unused Display option.
     * @return array{path:string,mime:string}|null Selected file, if present.
     */
    function image_public_display_file(array $image, array $gallery, bool $unused): ?array
    {
        return $GLOBALS['batch_files'][$image['id']] ?? null;
    }
    /**
     * Check that a candidate lies in the isolated fixture directory.
     * @param string $path Selected candidate.
     * @return bool Whether its resolved parent is the fixture directory.
     */
    function gallery_filesystem_path_inside_root(string $path): bool
    {
        return realpath(dirname($path)) === realpath($GLOBALS['batch_dir']);
    }
}

namespace {
    require_once __DIR__ . '/../app/services/app_linking_media.php';
    require_once __DIR__ . '/../app/services/app_linking_thumbnail_batch.php';

    use function Gallery\Services\app_linking_batch_frames;
    use function Gallery\Services\app_linking_media_open_verified_photo_file;

    /**
     * Size a multi-chunk fixture payload.
     * Type: int. Units: repetitions. Scope: one sample photo.
     * Consumers: stream integrity and chunk checks.
     * Rationale: 1000 repetitions exceed the 8192-byte chunk boundary.
     */
    const BATCH_FIXTURE_PAYLOAD_REPEATS = 1000;

    /**
     * Bound fixture metadata decoding depth.
     * Type: int. Units: JSON nesting levels. Scope: fixture decoder.
     * Consumers: framed metadata assertions.
     * Rationale: 16 accommodates the flat metadata object with room for diagnostics.
     */
    const BATCH_FIXTURE_JSON_DEPTH = 16;

    /**
     * Set an already expired request age.
     * Type: int. Units: seconds. Scope: one deadline fixture.
     * Consumers: stream deadline regression.
     * Rationale: 31 seconds exceeds the production 30-second deadline.
     */
    const BATCH_FIXTURE_EXPIRED_SECONDS = 31;

    /**
     * Assert a fixture observation.
     * @param bool $condition Whether the expected behavior occurred.
     * @param string $message Failure explanation.
     * @return void Terminates on failure.
     */
    function batch_stream_check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
    }

    /**
     * Decode the exact ordered framing and validate declared byte lengths.
     * @param string $wire Nonempty chunks joined into a protocol response.
     * @return array{items:list<array{meta:array<string,int|string|null>,payload:string}>,ended:bool} Decoded items and terminal marker.
     */
    function batch_stream_decode(string $wire): array
    {
        batch_stream_check(substr($wire, 0, 7) === 'PGB1' . pack('Cn', 1, 3), 'Bad stream header');
        $offset = 7; $items = []; $ended = false;
        while ($offset < strlen($wire)) {
            $type = $wire[$offset++];
            if ($type === 'E') {
                batch_stream_check(substr($wire, $offset) === pack('n', 3), 'Bad terminal count or trailing bytes');
                $ended = true; break;
            }
            batch_stream_check($type === 'I', 'Bad frame marker');
            $lengths = unpack('nmeta/Npayload', substr($wire, $offset, 6)); $offset += 6;
            batch_stream_check($lengths['meta'] <= 2048 && $lengths['payload'] <= 4194304, 'Frame exceeds limits');
            $meta = json_decode(substr($wire, $offset, $lengths['meta']), true, BATCH_FIXTURE_JSON_DEPTH, JSON_THROW_ON_ERROR);
            $offset += $lengths['meta'];
            $payload = substr($wire, $offset, $lengths['payload']); $offset += $lengths['payload'];
            batch_stream_check(strlen($payload) === $lengths['payload'] && $meta['length'] === strlen($payload), 'Truncated payload');
            $items[] = ['meta' => $meta, 'payload' => $payload];
        }
        return ['items' => $items, 'ended' => $ended];
    }

    // Three frames and the exact sizes below exercise protocol boundaries.
    $GLOBALS['batch_handles'] = [];
    $GLOBALS['batch_row_reads'] = 0;
    $GLOBALS['batch_dir'] = sys_get_temp_dir() . '/pg-batch-' . bin2hex(random_bytes(8));
    mkdir($GLOBALS['batch_dir']);
    try {
        $path = $GLOBALS['batch_dir'] . '/photo';
        $secondPath = $GLOBALS['batch_dir'] . '/second-photo';
        $large = $GLOBALS['batch_dir'] . '/large';
        $budget = $GLOBALS['batch_dir'] . '/budget';
        $link = $GLOBALS['batch_dir'] . '/link';
        $payload = str_repeat('fixture-bytes', BATCH_FIXTURE_PAYLOAD_REPEATS);
        file_put_contents($path, $payload);
        $handle = fopen($large, 'wb'); ftruncate($handle, 4194305); fclose($handle);
        $GLOBALS['batch_files'] = [1 => ['path' => $path, 'mime' => 'image/jpeg'],
            3 => ['path' => $large, 'mime' => 'image/jpeg']];
        $GLOBALS['batch_rows'] = [];
        foreach ([1, 2, 3] as $id) {
            $GLOBALS['batch_rows'][$id] = ['id' => $id, 'gallery_id' => 42,
                'relative_path' => 'photo', 'visibility' => 'public', 'nsfw_enabled' => 0];
        }
        $identity = ['grant_id' => 5, 'user_id' => 7, 'username' => 'fixture', 'scope' => 'admin'];
        $GLOBALS['batch_identity'] = $identity;
        $GLOBALS['batch_enabled'] = $GLOBALS['batch_gallery'] = $GLOBALS['batch_public'] = $GLOBALS['batch_preload'] = true;
        $request = ['gallery_id' => 42, 'image_ids' => ['1', '2', '3']];
        $wire = '';
        foreach (app_linking_batch_frames($identity, 'token', 'issuer', $request, true, null, hrtime(true)) as $chunk) {
            batch_stream_check(strlen($chunk) <= 8192 || str_starts_with($chunk, 'I'), 'Payload chunk exceeds limit');
            $wire .= $chunk;
        }
        $decoded = batch_stream_decode($wire);
        batch_stream_check($decoded['ended'] && strlen($wire) <= 8388608, 'Complete response must fit budget');
        batch_stream_check(array_column(array_column($decoded['items'], 'meta'), 'status') === ['ok', 'unavailable', 'oversize'], 'Wrong ordered statuses');
        batch_stream_check($decoded['items'][0]['payload'] === $payload
            && $decoded['items'][0]['meta']['sha256'] === hash('sha256', $payload)
            && $decoded['items'][0]['meta']['etag'] === '"' . hash('sha256', $payload) . '"', 'Payload integrity or validator mismatch');

        // Re-resolve each photo path after its preceding frame is delivered.
        $secondPayload = 'independent-second-photo';
        file_put_contents($secondPath, $secondPayload);
        $savedFiles = $GLOBALS['batch_files'];
        $GLOBALS['batch_files'] = [
            1 => ['path' => $path, 'mime' => 'image/jpeg'],
            2 => ['path' => $secondPath, 'mime' => 'image/jpeg'],
            3 => ['path' => $path, 'mime' => 'image/jpeg'],
        ];
        $generator = app_linking_batch_frames(
            $identity, 'token', 'issuer', $request, true, null, hrtime(true),
        );
        $mutationWire = $generator->current();
        $generator->next();
        $deletedAfterFirst = false;
        while ($generator->valid()) {
            $chunk = $generator->current();
            if ($chunk === '') {
                batch_stream_check(
                    unlink($secondPath) && !file_exists($secondPath),
                    'The independent second photo was not deleted at the frame boundary',
                );
                $deletedAfterFirst = true;
                $generator->next();
                break;
            }
            $mutationWire .= $chunk;
            $generator->next();
        }
        while ($generator->valid()) {
            $mutationWire .= $generator->current();
            $generator->next();
        }
        $decodedMutation = batch_stream_decode($mutationWire);
        $mutationItems = $decodedMutation['items'];
        $GLOBALS['batch_files'] = $savedFiles;
        batch_stream_check($deletedAfterFirst, 'The second file deletion was not reached');
        batch_stream_check($decodedMutation['ended'], 'Filesystem change omitted the complete terminal');
        batch_stream_check(array_column(array_column($mutationItems, 'meta'), 'status')
            === ['ok', 'unavailable', 'ok'], 'Changed file path did not produce ordered per-item results');
        batch_stream_check(array_column(array_column($mutationItems, 'meta'), 'image_id')
            === ['1', '2', '3'], 'Filesystem-change item identities changed order');
        batch_stream_check(array_column(array_column($mutationItems, 'meta'), 'request_index')
            === [0, 1, 2], 'Filesystem-change request indexes changed order');
        batch_stream_check($mutationItems[0]['payload'] === $payload
            && $mutationItems[0]['meta']['sha256'] === hash('sha256', $payload)
            && $mutationItems[2]['payload'] === $payload
            && $mutationItems[2]['meta']['sha256'] === hash('sha256', $payload),
            'Independent readable files lost their exact payload or digest');
        batch_stream_check($mutationItems[1]['payload'] === ''
            && $mutationItems[1]['meta']['mime'] === 'application/octet-stream'
            && $mutationItems[1]['meta']['length'] === 0
            && $mutationItems[1]['meta']['sha256'] === null
            && $mutationItems[1]['meta']['etag'] === null,
            'Deleted file did not produce a neutral empty item');
        batch_stream_check($GLOBALS['batch_handles'] === [], 'Filesystem-change stream leaked a handle');

        batch_stream_check(app_linking_media_open_verified_photo_file($path, 'text/plain', 100000, false) === null, 'Unsupported MIME accepted');
        batch_stream_check(app_linking_media_open_verified_photo_file($large, 'image/jpeg', 4194304, false) === ['oversized' => true], 'Oversized file admitted');
        $generator = app_linking_batch_frames($identity, 'token', 'issuer', $request, true, null, hrtime(true));
        $wire = $generator->current(); $generator->next();
        $wire .= $generator->current();
        $GLOBALS['batch_identity'] = null;
        $generator->next();
        while ($generator->valid()) { $wire .= $generator->current(); $generator->next(); }
        batch_stream_check(!str_contains($wire, 'E' . pack('n', 3)), 'Revoked grant completed stream');
        $GLOBALS['batch_identity'] = $identity;
        $generator = app_linking_batch_frames($identity, 'token', 'issuer', $request, true, null, hrtime(true));
        $wire = $generator->current();
        $GLOBALS['batch_rows'][1]['gallery_id'] = 99;
        $generator->next();
        batch_stream_check(!$generator->valid(), 'Cross-gallery image emitted a frame');
        $GLOBALS['batch_rows'][1]['gallery_id'] = 42;
        $GLOBALS['batch_public'] = false;
        $wire = '';
        foreach (app_linking_batch_frames($identity, 'token', 'issuer', $request, true, null, hrtime(true)) as $chunk) { $wire .= $chunk; }
        $decoded = batch_stream_decode($wire);
        batch_stream_check($decoded['items'][0]['meta']['etag'] === null, 'Private photo received public validator');
        // Third row lookup is the final validator check after admission and open.
        foreach ([null, 'admin-v1'] as $preload) {
            $GLOBALS['batch_public'] = $GLOBALS['batch_preload'] = true;
            $GLOBALS['batch_rows'][1]['nsfw_enabled'] = 0;
            $GLOBALS['batch_row_reads'] = 0;
            $GLOBALS['batch_mutate_at'] = 3;
            $wire = '';
            foreach (app_linking_batch_frames($identity, 'token', 'issuer', $request, true, $preload, hrtime(true)) as $chunk) {
                $wire .= $chunk;
            }
            if ($preload === null) {
                $decoded = batch_stream_decode($wire);
                batch_stream_check($decoded['items'][0]['meta']['etag'] === null, 'Current private policy must demote validator');
            } else {
                batch_stream_check($wire === 'PGB1' . pack('Cn', 1, 3), 'Revoked preload emitted a frame or terminal');
            }
        }
        $GLOBALS['batch_mutate_at'] = -1;
        $GLOBALS['batch_rows'][1]['nsfw_enabled'] = 0;
        $GLOBALS['batch_public'] = $GLOBALS['batch_preload'] = true;
        foreach (['scope', 'account', 'gallery'] as $revocation) {
            $GLOBALS['batch_identity'] = $identity;
            $GLOBALS['batch_gallery'] = true;
            $generator = app_linking_batch_frames($identity, 'token', 'issuer', $request, true, null, hrtime(true));
            $wire = '';
            while ($generator->valid() && $generator->current() !== '') {
                $wire .= $generator->current();
                $generator->next();
            }
            if ($revocation === 'scope') {
                $GLOBALS['batch_identity']['scope'] = 'other';
            } elseif ($revocation === 'account') {
                $GLOBALS['batch_identity']['user_id']++;
            } else {
                $GLOBALS['batch_gallery'] = false;
            }
            $generator->next();
            batch_stream_check(!$generator->valid(), 'Revoked authority emitted a later frame or terminal');
            $decoded = batch_stream_decode($wire);
            batch_stream_check(count($decoded['items']) === 1 && !$decoded['ended'], 'First item must be complete before revocation');
        }
        $GLOBALS['batch_identity'] = $identity;
        $GLOBALS['batch_gallery'] = true;
        // An elapsed deadline greater than the production 30-second bound is expired.
        $expired = hrtime(true) - (BATCH_FIXTURE_EXPIRED_SECONDS * 1000000000);
        $wire = '';
        foreach (app_linking_batch_frames($identity, 'token', 'issuer', $request, true, null, $expired) as $chunk) {
            $wire .= $chunk;
        }
        batch_stream_check($wire === 'PGB1' . pack('Cn', 1, 3), 'Expired stream emitted a frame');

        // Three 3 MiB files fit individually but not together under the 8 MiB response bound.
        $handle = \fopen($budget, 'wb');
        \ftruncate($handle, 3 * 1024 * 1024);
        \fclose($handle);
        foreach ([1, 2, 3] as $id) {
            $GLOBALS['batch_files'][$id] = ['path' => $budget, 'mime' => 'image/jpeg'];
        }
        $wire = '';
        foreach (app_linking_batch_frames($identity, 'token', 'issuer', $request, true, null, hrtime(true)) as $chunk) {
            $wire .= $chunk;
        }
        $decoded = batch_stream_decode($wire);
        batch_stream_check($decoded['ended'] && strlen($wire) <= 8388608, 'Reserved response exceeds budget');
        batch_stream_check(array_column(array_column($decoded['items'], 'meta'), 'status') === ['ok', 'ok', 'oversize'], 'Remaining budget did not reject third file');

        // An exception during a real owner read must still close its open handle.
        $GLOBALS['batch_files'][1] = ['path' => $path, 'mime' => 'image/jpeg'];
        $GLOBALS['batch_read_exception'] = true;
        $raised = false;
        try {
            foreach (app_linking_batch_frames($identity, 'token', 'issuer', $request, true, null, hrtime(true)) as $chunk) {
                // Advancing the generator triggers the injected read failure.
            }
        } catch (\RuntimeException) {
            $raised = true;
        }
        $GLOBALS['batch_read_exception'] = false;
        batch_stream_check($raised && $GLOBALS['batch_handles'] === [], 'Read exception leaked a handle');

        // Truncate after verified opening so the actual read detects the short file.
        $GLOBALS['batch_files'][1] = ['path' => $path, 'mime' => 'image/jpeg'];
        $GLOBALS['batch_short_path'] = $path;
        $GLOBALS['batch_shorten'] = true;
        $wire = '';
        foreach (app_linking_batch_frames($identity, 'token', 'issuer', $request, true, null, hrtime(true)) as $chunk) {
            $wire .= $chunk;
        }
        batch_stream_check($wire === 'PGB1' . pack('Cn', 1, 3), 'Short file emitted a successful frame');
        batch_stream_check($GLOBALS['batch_handles'] === [], 'Media handle leaked after stream completion or failure');
        batch_stream_check(app_linking_media_open_verified_photo_file(__FILE__, 'image/jpeg', 4194304, false) === null, 'Outside file admitted');
        if (@symlink($path, $link)) {
            batch_stream_check(app_linking_media_open_verified_photo_file($link, 'image/jpeg', 4194304, false) === null, 'Symbolic link admitted');
        }
        batch_stream_check($GLOBALS['batch_handles'] === [], 'Verified file refusal leaked a handle');
        fwrite(STDOUT, "thumbnail batch stream checks passed\n");
    } finally {
        @unlink($path); @unlink($secondPath); @unlink($large); @unlink($budget); @unlink($link); @rmdir($GLOBALS['batch_dir']);
    }
}
