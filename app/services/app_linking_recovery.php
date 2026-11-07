<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_recovery.php
 * Module Type: Service
 * Purpose: Confirm bounded positive evidence for a delegated image upload.
 * Responsibilities:
 *   - Probe only the current authorized gallery under existing writer leases.
 *   - Hash bounded regular files without exposing paths or asserting absence.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Models\upload_automation_model_inventory_locks_owned;

/**
 * Bound indexed positive matches examined by one delegated inventory request.
 * Type: int. Units: files. Scope: one inventory response.
 * Consumers: app_linking_inventory_verify_files.
 * Rationale: 8 bounds open/hash operations; larger positive batches are unknown.
 */
const APP_LINKING_INVENTORY_VERIFY_MAX_FILES = 8;

/**
 * Limit directory enumeration in one recovery probe.
 * Type: int. Units: directory entries. Scope: one authorized gallery probe.
 * Consumers: app_linking_recovery_scan.
 * Rationale: 512 entries bound work; incomplete coverage always means unknown.
 */
const APP_LINKING_RECOVERY_MAX_ENTRIES = 512;

/**
 * Limit the size of one source eligible for a positive recovery probe.
 * Type: int. Units: bytes. Scope: one requested content hash.
 * Consumers: app_linking_recovery_scan, app_linking_inventory_verify_files.
 * Rationale: 67108864 bytes bounds individual file reads on shared hosting.
 */
const APP_LINKING_RECOVERY_MAX_FILE_BYTES = 67108864;

/**
 * Limit total file bytes hashed in one gallery recovery probe.
 * Type: int. Units: bytes. Scope: all matching-size files in one probe.
 * Consumers: app_linking_recovery_scan, app_linking_inventory_verify_files.
 * Rationale: 134217728 bytes bounds work across duplicate-sized files.
 */
const APP_LINKING_RECOVERY_MAX_TOTAL_BYTES = 134217728;

/**
 * Limit one application-level read from a candidate image file.
 * Type: int. Units: bytes. Scope: each recovery hash update.
 * Consumers: app_linking_recovery_scan, app_linking_inventory_verify_files.
 * Rationale: 65536-byte chunks permit deadline checks without large buffers.
 */
const APP_LINKING_RECOVERY_READ_BYTES = 65536;

/**
 * Bound admission of additional recovery work after the monotonic deadline.
 * Type: float. Units: seconds. Scope: one gallery recovery probe.
 * Consumers: app_linking_recovery_scan, app_linking_inventory_verify_files.
 * Rationale: 2 seconds limits shared-hosting work between filesystem calls;
 *     a blocking operating-system call itself cannot be interrupted by PHP.
 */
const APP_LINKING_RECOVERY_MAX_SECONDS = 2.0;

/**
 * Observe a monotonic deadline without assuming the clock is available.
 *
 * @param float $deadline Absolute monotonic deadline in seconds.
 * @return bool True only while a readable clock remains before the deadline.
 */
function app_linking_recovery_before_deadline(float $deadline): bool
{
    $now = hrtime(true);
    return (is_int($now) || is_float($now))
        && (float) $now / 1000000000 < $deadline;
}

/**
 * Compare native identities and reject changed file or directory metadata.
 *
 * @param array{dev?:int,ino?:int,mode?:int,size?:int,mtime?:int,ctime?:int,...}|false $first Earlier lstat/fstat result.
 * @param array{dev?:int,ino?:int,mode?:int,size?:int,mtime?:int,ctime?:int,...}|false $second Later lstat/fstat result.
 * @return bool True only for the same device, inode, mode, size and timestamps.
 */
function app_linking_recovery_same_stat(array|false $first, array|false $second): bool
{
    if ($first === false || $second === false
        || !isset($first['ino'], $second['ino'])
        || (int) $first['ino'] <= 0 || (int) $second['ino'] <= 0) {
        return false;
    }
    foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
        if (!isset($first[$field], $second[$field])
            || $first[$field] !== $second[$field]) {
            return false;
        }
    }
    return true;
}

/**
 * Resolve the gallery without accepting symlinks in its relative directory.
 *
 * @param string $folderPath Authorized gallery's stored relative folder.
 * @return string|null Canonical existing gallery directory, or null when unsafe.
 */
function app_linking_recovery_gallery_directory(string $folderPath): ?string
{
    try {
        $relative = normalize_relative_path($folderPath);
        $root = @realpath(galleries_root());
        if ($relative === '' || !is_string($root) || !is_dir($root)) {
            return null;
        }
        $current = $root;
        foreach (explode('/', $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            clearstatcache(true, $current);
            $stat = @lstat($current);
            // POSIX file-type bits: only actual directories, never symlinks.
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
                || @realpath($current) !== $current) {
                return null;
            }
        }
        return $current;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Search bounded direct regular image files for one exact content digest.
 *
 * A true result describes matching bytes observed during this probe only. A
 * false result is always inconclusive: limits, errors, concurrent filesystem
 * changes and an absent match do not prove that an upload failed. The caller
 * holds the shared gallery and writer leases and rechecks its grant separately.
 *
 * @param string $directory Canonical gallery directory checked by the caller.
 * @param string $sha256 Lowercase SHA-256 digest of the client's source image.
 * @param int $size Positive exact source length in bytes.
 * @return bool True only for a complete stable matching regular-file read.
 */
function app_linking_recovery_scan(string $directory, string $sha256, int $size): bool
{
    if ($size <= 0 || $size > APP_LINKING_RECOVERY_MAX_FILE_BYTES
        || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
        return false;
    }
    $start = hrtime(true);
    if (!is_int($start) && !is_float($start)) {
        return false;
    }
    $deadline = (float) $start / 1000000000 + APP_LINKING_RECOVERY_MAX_SECONDS;
    clearstatcache(true, $directory);
    $directoryStat = @lstat($directory);
    if (!is_array($directoryStat)
        || ($directoryStat['mode'] & 0170000) !== 0040000
        || @realpath($directory) !== $directory) {
        return false;
    }
    $handle = @opendir($directory);
    if ($handle === false) {
        return false;
    }
    $entries = 0;
    $readBytes = 0;
    try {
        while (($name = @readdir($handle)) !== false) {
            if (++$entries > APP_LINKING_RECOVERY_MAX_ENTRIES
                || !app_linking_recovery_before_deadline($deadline)) {
                return false;
            }
            if ($name === '.' || $name === '..' || !is_supported_image_path($name)) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            clearstatcache(true, $path);
            $before = @lstat($path);
            if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000
                || (int) $before['size'] !== $size
                || $readBytes + $size > APP_LINKING_RECOVERY_MAX_TOTAL_BYTES
                || @realpath($path) !== $path) {
                continue;
            }
            $file = @fopen($path, 'rb');
            if ($file === false) {
                continue;
            }
            try {
                // Compare the opened inode before reading so a substituted
                // symlink or file cannot turn this into an out-of-root read.
                clearstatcache(true, $directory);
                if (!app_linking_recovery_same_stat($before, @fstat($file))
                    || !app_linking_recovery_same_stat($directoryStat, @lstat($directory))
                    || @realpath($directory) !== $directory || @realpath($path) !== $path) {
                    return false;
                }
                $hash = hash_init('sha256');
                $remaining = $size;
                while ($remaining > 0) {
                    if (!app_linking_recovery_before_deadline($deadline)) {
                        return false;
                    }
                    $bytes = @fread($file, min($remaining, APP_LINKING_RECOVERY_READ_BYTES));
                    if (!is_string($bytes) || $bytes === '') {
                        return false;
                    }
                    $length = strlen($bytes);
                    $remaining -= $length;
                    $readBytes += $length;
                    hash_update($hash, $bytes);
                }
                clearstatcache(true, $path);
                clearstatcache(true, $directory);
                if (!app_linking_recovery_same_stat($before, @fstat($file))
                    || !app_linking_recovery_same_stat($before, @lstat($path))
                    || !app_linking_recovery_same_stat($directoryStat, @lstat($directory))
                    || @realpath($directory) !== $directory || @realpath($path) !== $path
                    || !app_linking_recovery_before_deadline($deadline)) {
                    return false;
                }
                if (hash_equals($sha256, hash_final($hash))) {
                    return true;
                }
            } finally {
                @fclose($file);
            }
        }
    } finally {
        @closedir($handle);
    }
    return false;
}

/**
 * Verify indexed direct originals with one bounded shared read budget.
 *
 * @param string $directory Canonical authorized gallery directory.
 * @param list<array{remote_relative_path:string,size:int,sha256:string,...}> $matches Indexed candidate projections from the current locked lookup.
 * @return bool True only when every selected original produced stable matching bytes.
 */
function app_linking_inventory_verify_files(string $directory, array $matches): bool
{
    if (count($matches) > APP_LINKING_INVENTORY_VERIFY_MAX_FILES
        || realpath($directory) !== $directory || is_link($directory)) {
        return false;
    }
    $start = hrtime(true);
    if (!is_int($start) && !is_float($start)) {
        return false;
    }
    $deadline = (float) $start / 1000000000 + APP_LINKING_RECOVERY_MAX_SECONDS;
    clearstatcache(true, $directory);
    $directoryStat = @lstat($directory);
    if (!is_array($directoryStat) || ($directoryStat['mode'] & 0170000) !== 0040000) {
        return false;
    }
    $totalBytes = 0;
    foreach ($matches as $match) {
        $name = $match['remote_relative_path'] ?? null;
        $size = $match['size'] ?? null;
        $expected = $match['sha256'] ?? null;
        if (!is_string($name) || $name === '' || $name === '.' || $name === '..'
            || str_contains($name, '/') || str_contains($name, '\\')
            || !is_supported_image_path($name) || !is_int($size) || $size <= 0
            || $size > APP_LINKING_RECOVERY_MAX_FILE_BYTES
            || $size > APP_LINKING_RECOVERY_MAX_TOTAL_BYTES - $totalBytes
            || !is_string($expected) || preg_match('/\A[a-f0-9]{64}\z/D', $expected) !== 1
            || !app_linking_recovery_before_deadline($deadline)) {
            return false;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000
            || ($before['nlink'] ?? 0) !== 1 || $before['size'] !== $size
            || @realpath($path) !== $path) {
            return false;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $directory);
            clearstatcache(true, $path);
            if (!app_linking_recovery_same_stat($before, $opened)
                || ($opened['nlink'] ?? 0) !== 1
                || !app_linking_recovery_same_stat($directoryStat, @lstat($directory))
                || @realpath($directory) !== $directory || @realpath($path) !== $path) {
                return false;
            }
            $hash = hash_init('sha256');
            $remaining = $size;
            while ($remaining > 0) {
                if (!app_linking_recovery_before_deadline($deadline)) {
                    return false;
                }
                $bytes = @fread($handle, min($remaining, APP_LINKING_RECOVERY_READ_BYTES));
                if (!is_string($bytes) || $bytes === '') {
                    return false;
                }
                $length = strlen($bytes);
                $remaining -= $length;
                $totalBytes += $length;
                hash_update($hash, $bytes);
            }
            clearstatcache(true, $path);
            clearstatcache(true, $directory);
            $after = @lstat($path);
            $openedAfter = fstat($handle);
            if (!app_linking_recovery_same_stat($before, $openedAfter)
                || !app_linking_recovery_same_stat($before, $after)
                || ($after['nlink'] ?? 0) !== 1 || ($openedAfter['nlink'] ?? 0) !== 1
                || !app_linking_recovery_same_stat($directoryStat, @lstat($directory))
                || @realpath($directory) !== $directory || @realpath($path) !== $path
                || !app_linking_recovery_before_deadline($deadline)
                || !hash_equals($expected, hash_final($hash))) {
                return false;
            }
        } finally {
            fclose($handle);
        }
    }
    return true;
}

/**
 * Recheck indexed inventory and its original files under both existing leases.
 *
 * @param int $galleryId Authorized gallery identifier.
 * @param array{folder_path?:string|null,...} $gallery Initially authorized target.
 * @param list<array{client_id:string,filename:string,size:int,sha256:string}> $candidates Normalized requested candidates.
 * @param array{existing:list<array{sha256:string,remote_image_id:int,remote_relative_path:string,size:int,...}>,...} $initial Initial indexed response with at least one match.
 * @param callable():array{folder_path?:string|null,...}|null $currentGallery Fresh authority and target lookup.
 * @return array<string,mixed>|null Revalidated inventory, or null on uncertain evidence or authority.
 * @throws Throwable When ownership or storage operations fail.
 */
function app_linking_inventory_confirm_indexed(
    int $galleryId,
    array $gallery,
    array $candidates,
    array $initial,
    callable $currentGallery,
): ?array {
    return upload_automation_with_gallery_lock(
        $galleryId,
        static function () use ($galleryId, $gallery, $candidates, $initial, $currentGallery): ?array {
            $writer = gallery_edit_writer_begin();
            try {
                $current = $currentGallery();
                $folder = $gallery['folder_path'] ?? null;
                if (!is_string($folder) || !is_array($current)
                    || ($current['folder_path'] ?? null) !== $folder
                    || !upload_automation_model_inventory_locks_owned($galleryId, $writer)) {
                    return null;
                }
                $fresh = upload_automation_gallery_inventory_response(
                    $galleryId, $current, $candidates, false, true,
                );
                $freshByHash = [];
                foreach ($fresh['existing'] as $row) {
                    $freshByHash[$row['sha256']] = $row;
                }
                foreach ($initial['existing'] as $row) {
                    $now = $freshByHash[$row['sha256']] ?? null;
                    if (!is_array($now) || $now['remote_image_id'] !== $row['remote_image_id']
                        || $now['remote_relative_path'] !== $row['remote_relative_path']
                        || $now['size'] !== $row['size']) {
                        return null;
                    }
                }
                $directory = app_linking_recovery_gallery_directory($folder);
                if ($directory === null || !app_linking_inventory_verify_files($directory, $fresh['existing'])
                    || !upload_automation_model_inventory_locks_owned($galleryId, $writer)) {
                    return null;
                }
                $current = $currentGallery();
                if (!is_array($current) || ($current['folder_path'] ?? null) !== $folder
                    || !upload_automation_model_inventory_locks_owned($galleryId, $writer)) {
                    return null;
                }
                return $fresh;
            } finally {
                gallery_edit_writer_end($writer);
            }
        },
    );
}

/**
 * Confirm matching bytes only while a current delegated gallery remains owned.
 *
 * @param int $galleryId Authorized physical gallery identifier.
 * @param array{folder_path?:string|null,...} $gallery Initial authorized gallery.
 * @param string $sha256 Lowercase expected source checksum.
 * @param int $size Positive expected source length in bytes.
 * @param callable():array{folder_path?:string|null,...}|null $currentGallery
 *     Trusted live feature, bearer, role, issuer, scope and target recheck.
 * @return 'present'|'unknown'|null Positive matching evidence, inconclusive
 *     result, or null when current target authority cannot be confirmed.
 * @throws Throwable When a required lease or recheck fails.
 */
function app_linking_recovery_probe(
    int $galleryId,
    array $gallery,
    string $sha256,
    int $size,
    callable $currentGallery,
): ?string {
    return upload_automation_with_gallery_lock(
        $galleryId,
        static function () use ($gallery, $sha256, $size, $currentGallery): ?string {
            $writer = gallery_edit_writer_begin();
            try {
                $current = $currentGallery();
                $folder = $gallery['folder_path'] ?? null;
                if (!is_string($folder) || !is_array($current)
                    || ($current['folder_path'] ?? null) !== $folder) {
                    return null;
                }
                $directory = app_linking_recovery_gallery_directory($folder);
                $present = $directory !== null
                    && app_linking_recovery_scan($directory, $sha256, $size);
                $current = $currentGallery();
                if (!is_array($current) || ($current['folder_path'] ?? null) !== $folder) {
                    return null;
                }
                return $present ? 'present' : 'unknown';
            } finally {
                gallery_edit_writer_end($writer);
            }
        },
    );
}
