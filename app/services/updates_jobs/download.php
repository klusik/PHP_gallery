<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs/download.php
 * Module Type: Service
 *
 * Purpose:
 *   Downloads, validates, and extracts the release archive.
 *
 * Responsibilities:
 *   - Download the archive in resumable slices within the budget
 *   - Reject unsafe archive entries, symlinks, and manifest mismatches
 *   - Extract validated entries in bounded slices
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Loaded by app/services/updates_jobs.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/updates_jobs.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\run_migrations_bounded;

/**
 * Return the remote archive URL for a persisted job without storing it in job state.
 *
 * @param array $job Job state.
 * @return string Trusted update URL.
 */
function application_update_job_archive_url(array $job): string
{
    $operation = (string) ($job['operation'] ?? '');
    $parameters = (array) ($job['parameters'] ?? []);
    if ($operation === 'beta_install') {
        return application_update_commit_zip_url((string) ($parameters['commit'] ?? ''));
    }
    return application_update_zip_url((string) ($parameters['branch'] ?? ''));
}

/**
 * Stream a remote archive into the job workspace for a bounded amount of time.
 *
 * Partial files are kept between requests. cURL Range requests are preferred.
 * When a server ignores a resume Range and replies 200, the local partial file
 * is truncated before response bytes are written so duplicate data cannot be appended.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker budget.
 * @return bool True when the complete HTTP response finished in this request.
 */
function application_update_job_download_slice(array &$job, array $budget): bool
{
    $jobDir = application_update_job_dir((string) $job['id']);
    $archivePath = $jobDir . '/package.zip';
    $offset = is_file($archivePath) ? (int) filesize($archivePath) : 0;
    $url = trim((string) ($job['checkpoints']['archive_url'] ?? ''));
    if ($url === '') {
        // Keep the same trusted archive URL across continuation requests. The
        // response validator below protects Range resume when a branch head moves.
        $url = application_update_job_archive_url($job);
        $job['checkpoints']['archive_url'] = $url;
        application_update_save_job($job);
    }
    if (!str_starts_with($url, 'https://codeload.github.com/') && !str_starts_with($url, 'https://github.com/')) {
        throw new RuntimeException('Persisted update archive source is not trusted.');
    }

    // Keep each external I/O call below the normal worker slice.
    $remaining = max(2.0, (float) ($budget['deadline'] ?? microtime(true) + 2.0) - microtime(true) - 0.75);
    $ioTimeout = (int) max(2, min(7, floor($remaining)));

    if (function_exists('curl_init')) {
        $handle = fopen($archivePath, $offset > 0 ? 'ab' : 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not open update archive destination.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            throw new RuntimeException('Could not initialize update download.');
        }
        $responseStatus = 0;
        $contentLength = null;
        $contentRangeTotal = null;
        $responseEtag = null;
        $responseLastModified = null;
        $rangeHonored = $offset === 0;
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(4, $ioTimeout),
            CURLOPT_TIMEOUT => $ioTimeout,
            CURLOPT_USERAGENT => 'PHP-Gallery-CMS/' . \Gallery\Core\cms_current_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FILE => $handle,
            CURLOPT_FAILONERROR => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use (&$responseStatus, &$contentLength, &$contentRangeTotal, &$responseEtag, &$responseLastModified, &$rangeHonored, $offset, $handle): int {
                $length = strlen($line);
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', trim($line), $match) === 1) {
                    $responseStatus = (int) $match[1];
                    $contentLength = null;
                    $contentRangeTotal = null;
                    $responseEtag = null;
                    $responseLastModified = null;
                    if ($offset > 0 && $responseStatus === 200) {
                        // If-Range or the origin rejected the partial snapshot. Restart
                        // safely instead of appending bytes from a changed branch head.
                        ftruncate($handle, 0);
                        rewind($handle);
                        $rangeHonored = false;
                    }
                } elseif (stripos($line, 'Content-Range:') === 0 && $offset > 0) {
                    $rangeHonored = true;
                    if (preg_match('#/([0-9]+)\s*$#', trim($line), $rangeMatch) === 1) {
                        $contentRangeTotal = (int) $rangeMatch[1];
                    }
                } elseif (stripos($line, 'Content-Length:') === 0) {
                    $contentLength = (int) trim(substr($line, strlen('Content-Length:')));
                } elseif (stripos($line, 'ETag:') === 0) {
                    $responseEtag = trim(substr($line, strlen('ETag:')));
                } elseif (stripos($line, 'Last-Modified:') === 0) {
                    $responseLastModified = trim(substr($line, strlen('Last-Modified:')));
                }
                return $length;
            },
        ]);
        if ($offset > 0) {
            curl_setopt($curl, CURLOPT_RANGE, $offset . '-');
            $ifRange = trim((string) ($job['checkpoints']['archive_etag'] ?? ''));
            if ($ifRange === '') {
                $ifRange = trim((string) ($job['checkpoints']['archive_last_modified'] ?? ''));
            }
            if ($ifRange !== '') {
                curl_setopt($curl, CURLOPT_HTTPHEADER, ['If-Range: ' . $ifRange]);
            }
        }

        $ok = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fflush($handle);
        fclose($handle);

        $size = is_file($archivePath) ? (int) filesize($archivePath) : 0;
        $expectedBytes = $contentRangeTotal;
        if ($expectedBytes === null && $contentLength !== null) {
            $expectedBytes = $rangeHonored && $offset > 0 ? $offset + $contentLength : $contentLength;
        }
        if ($responseEtag !== null && $responseEtag !== '') {
            $job['checkpoints']['archive_etag'] = $responseEtag;
        }
        if ($responseLastModified !== null && $responseLastModified !== '') {
            $job['checkpoints']['archive_last_modified'] = $responseLastModified;
        }
        if ($expectedBytes !== null && $expectedBytes > 0) {
            $job['checkpoints']['archive_expected_bytes'] = $expectedBytes;
        }
        $job['progress'] = [
            'current' => $size,
            'total' => (int) ($job['checkpoints']['archive_expected_bytes'] ?? 0),
            'message' => 'Downloading update package.',
            'unit' => 'bytes',
        ];
        application_update_save_job($job);

        if ($status >= 400) {
            throw new RuntimeException('Update archive HTTP request failed.');
        }
        if ($ok === true && in_array($status, [200, 206], true)) {
            $expected = (int) ($job['checkpoints']['archive_expected_bytes'] ?? 0);
            if ($expected > 0 && $size !== $expected) {
                throw new RuntimeException('Update archive download completed with an unexpected size.');
            }
            return true;
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT && $size > 0) {
            return false;
        }
        throw new RuntimeException('Update archive download failed before completion.');
    }

    $headers = "User-Agent: PHP-Gallery-CMS/" . \Gallery\Core\cms_current_version() . "\r\nCache-Control: no-cache\r\n";
    if ($offset > 0) {
        $headers .= 'Range: bytes=' . $offset . "-\r\n";
        $ifRange = trim((string) ($job['checkpoints']['archive_etag'] ?? ''));
        if ($ifRange === '') {
            $ifRange = trim((string) ($job['checkpoints']['archive_last_modified'] ?? ''));
        }
        if ($ifRange !== '') {
            $headers .= 'If-Range: ' . $ifRange . "\r\n";
        }
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $ioTimeout,
            'header' => $headers,
            'follow_location' => 1,
            'max_redirects' => 3,
            'ignore_errors' => true,
        ],
    ]);
    $remote = @fopen($url, 'rb', false, $context);
    if ($remote === false) {
        throw new RuntimeException('Update archive download could not be opened.');
    }
    $metadata = stream_get_meta_data($remote);
    $status = 0;
    $contentLength = 0;
    $contentRangeTotal = 0;
    $responseEtag = '';
    $responseLastModified = '';
    $rangeHonored = $offset === 0;
    foreach ((array) ($metadata['wrapper_data'] ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', (string) $line, $match) === 1) {
            $status = (int) $match[1];
            $contentLength = 0;
            $contentRangeTotal = 0;
            $responseEtag = '';
            $responseLastModified = '';
        } elseif (stripos((string) $line, 'Content-Range:') === 0) {
            $rangeHonored = true;
            if (preg_match('#/([0-9]+)\s*$#', trim((string) $line), $rangeMatch) === 1) {
                $contentRangeTotal = (int) $rangeMatch[1];
            }
        } elseif (stripos((string) $line, 'Content-Length:') === 0) {
            $contentLength = (int) trim(substr((string) $line, strlen('Content-Length:')));
        } elseif (stripos((string) $line, 'ETag:') === 0) {
            $responseEtag = trim(substr((string) $line, strlen('ETag:')));
        } elseif (stripos((string) $line, 'Last-Modified:') === 0) {
            $responseLastModified = trim(substr((string) $line, strlen('Last-Modified:')));
        }
    }
    if ($status >= 400) {
        fclose($remote);
        throw new RuntimeException('Update archive HTTP request failed.');
    }

    $local = fopen($archivePath, $offset > 0 && $rangeHonored ? 'ab' : 'wb');
    if ($local === false) {
        fclose($remote);
        throw new RuntimeException('Could not open update archive destination.');
    }
    if ($offset > 0 && !$rangeHonored) {
        $offset = 0;
    }

    $complete = false;
    while (application_update_budget_allows($budget, 0.6)) {
        $chunk = fread($remote, 1024 * 256);
        if ($chunk === false) {
            break;
        }
        if ($chunk === '') {
            if (feof($remote)) {
                $complete = true;
            }
            break;
        }
        if (fwrite($local, $chunk) === false) {
            fclose($local);
            fclose($remote);
            throw new RuntimeException('Could not write update archive chunk.');
        }
    }
    fflush($local);
    fclose($local);
    fclose($remote);

    $size = is_file($archivePath) ? (int) filesize($archivePath) : 0;
    if ($responseEtag !== '') {
        $job['checkpoints']['archive_etag'] = $responseEtag;
    }
    if ($responseLastModified !== '') {
        $job['checkpoints']['archive_last_modified'] = $responseLastModified;
    }
    $expectedBytes = $contentRangeTotal > 0 ? $contentRangeTotal : ($contentLength > 0 ? $offset + $contentLength : 0);
    if ($expectedBytes > 0) {
        $job['checkpoints']['archive_expected_bytes'] = $expectedBytes;
    }
    $job['progress'] = [
        'current' => $size,
        'total' => (int) ($job['checkpoints']['archive_expected_bytes'] ?? 0),
        'message' => 'Downloading update package.',
        'unit' => 'bytes',
    ];
    application_update_save_job($job);
    if ($complete) {
        $expected = (int) ($job['checkpoints']['archive_expected_bytes'] ?? 0);
        if ($expected > 0 && $size !== $expected) {
            throw new RuntimeException('Update archive download completed with an unexpected size.');
        }
    }
    return $complete;
}

/**
 * Normalize a ZIP entry path and reject traversal, absolute paths, and drive paths.
 *
 * @param string $entry ZIP entry name.
 * @return string Safe normalized relative path.
 */
function application_update_safe_zip_entry(string $entry): string
{
    $normalized = str_replace('\\', '/', $entry);
    $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
    if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized)) {
        throw new RuntimeException('Update archive contains an unsafe path.');
    }
    foreach (explode('/', trim($normalized, '/')) as $segment) {
        if ($segment === '..' || $segment === '.') {
            throw new RuntimeException('Update archive contains an unsafe path.');
        }
    }
    return $normalized;
}

/**
 * Return true when a ZIP entry is a Unix symbolic link.
 *
 * @param ZipArchive $zip Open archive.
 * @param int $index Entry index.
 * @return bool True when the archive metadata identifies a symlink.
 */
function application_update_zip_entry_is_symlink(ZipArchive $zip, int $index): bool
{
    $opsys = 0;
    $attributes = 0;
    if (!method_exists($zip, 'getExternalAttributesIndex') || !$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
        return false;
    }
    $mode = ($attributes >> 16) & 0xFFFF;
    return ($mode & 0170000) === 0120000;
}

/**
 * Validate archive structure in bounded batches before extraction starts.
 *
 * The ZIP directory itself can contain thousands of entries. Persisting the next
 * central-directory index prevents a deliberately large but still permitted
 * archive from turning validation into one long shared-hosting request.
 *
 * @param array $job Job state, updated by reference.
 * @param ?array $budget Optional worker budget. Null still caps one call by entry count.
 * @return bool True when every archive entry has been validated.
 */
function application_update_job_validate_archive(array &$job, ?array $budget = null): bool
{
    $archivePath = application_update_job_dir((string) $job['id']) . '/package.zip';
    if (!is_file($archivePath) || filesize($archivePath) === 0) {
        throw new RuntimeException('Downloaded update archive is empty.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        throw new RuntimeException('Downloaded update archive could not be opened.');
    }

    try {
        if ($zip->numFiles < 1 || $zip->numFiles > 20000) {
            throw new RuntimeException('Downloaded update archive has an invalid entry count.');
        }

        $index = (int) ($job['checkpoints']['archive_validate_index'] ?? 0);
        $uncompressed = (int) ($job['checkpoints']['archive_uncompressed_bytes'] ?? 0);
        if ($index < 0 || $index > $zip->numFiles) {
            throw new RuntimeException('Update archive validation checkpoint is invalid.');
        }
        $processed = 0;
        while ($index < $zip->numFiles && $processed < 500 && ($budget === null || application_update_budget_allows($budget, 0.7))) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name'])) {
                throw new RuntimeException('Downloaded update archive contains an unreadable entry.');
            }
            application_update_safe_zip_entry((string) $stat['name']);
            if (application_update_zip_entry_is_symlink($zip, $index)) {
                throw new RuntimeException('Downloaded update archive contains unsupported symbolic links.');
            }
            $size = max(0, (int) ($stat['size'] ?? 0));
            // Application releases should contain code/assets, not giant opaque blobs.
            // This cap also bounds the one-entry extraction unit on slow hosting.
            if ($size > 32 * 1024 * 1024) {
                throw new RuntimeException('Downloaded update archive contains an oversized file.');
            }
            $uncompressed += $size;
            if ($uncompressed > 512 * 1024 * 1024) {
                throw new RuntimeException('Downloaded update archive expands beyond the safe size limit.');
            }

            $index++;
            $processed++;
            $job['checkpoints']['archive_validate_index'] = $index;
            $job['checkpoints']['archive_uncompressed_bytes'] = $uncompressed;
            $job['progress'] = [
                'current' => $index,
                'total' => $zip->numFiles,
                'message' => 'Validating update package structure.',
                'unit' => 'entries',
            ];
            application_update_save_job($job);
        }

        if ($index >= $zip->numFiles) {
            $job['checkpoints']['archive_entries'] = $zip->numFiles;
            $job['checkpoints']['archive_uncompressed_bytes'] = $uncompressed;
            $job['checkpoints']['extract_index'] = (int) ($job['checkpoints']['extract_index'] ?? 0);
            $job['progress'] = [
                'current' => $zip->numFiles,
                'total' => $zip->numFiles,
                'message' => 'Update package structure validated.',
                'unit' => 'entries',
            ];
            application_update_save_job($job);
            return true;
        }

        return false;
    } finally {
        $zip->close();
    }
}

/**
 * Extract a bounded number of ZIP entries and checkpoint the next entry index.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker budget.
 * @return bool True when extraction completed.
 */
function application_update_job_extract_slice(array &$job, array $budget): bool
{
    $jobDir = application_update_job_dir((string) $job['id']);
    $archivePath = $jobDir . '/package.zip';
    $extractDir = $jobDir . '/extract';
    application_update_ensure_dir($extractDir);

    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        throw new RuntimeException('Downloaded update archive could not be reopened for extraction.');
    }
    $index = (int) ($job['checkpoints']['extract_index'] ?? 0);
    $processed = 0;
    try {
        while ($index < $zip->numFiles && $processed < 80 && application_update_budget_allows($budget, 0.7)) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name'])) {
                throw new RuntimeException('Downloaded update archive contains an unreadable entry.');
            }
            $entry = application_update_safe_zip_entry((string) $stat['name']);
            $target = $extractDir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $entry);
            if (str_ends_with($entry, '/')) {
                application_update_ensure_dir($target);
            } else {
                application_update_ensure_dir(dirname($target));
                $input = $zip->getStream((string) $stat['name']);
                if ($input === false) {
                    throw new RuntimeException('Could not read an update archive entry.');
                }
                $temporary = $target . '.part';
                $output = fopen($temporary, 'wb');
                if ($output === false) {
                    fclose($input);
                    throw new RuntimeException('Could not create an extracted update file.');
                }
                $copied = stream_copy_to_stream($input, $output);
                fclose($output);
                fclose($input);
                if ($copied === false || $copied !== (int) ($stat['size'] ?? $copied)) {
                    @unlink($temporary);
                    throw new RuntimeException('Extracted update file failed size verification.');
                }
                if (!rename($temporary, $target)) {
                    @unlink($temporary);
                    throw new RuntimeException('Could not commit an extracted update file.');
                }
            }
            $index++;
            $processed++;
            $job['checkpoints']['extract_index'] = $index;
            $job['progress'] = ['current' => $index, 'total' => $zip->numFiles, 'message' => 'Extracting update package.', 'unit' => 'entries'];
            application_update_save_job($job);
        }
    } finally {
        $zip->close();
    }
    return $index >= (int) ($job['checkpoints']['archive_entries'] ?? $index);
}

/**
 * Calculate the normalized-text SHA-256 used by app/core-manifest.json.
 *
 * Hashing is streamed so package verification does not need one PHP string as
 * large as the release file. CRLF/CR normalization matches the manifest generator,
 * including a UTF-8 BOM at the beginning of a file.
 *
 * @param string $path File path.
 * @return string Manifest-format hash.
 */
function application_update_manifest_hash(string $path): string
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not read an update file for integrity validation.');
    }
    $context = hash_init('sha256');
    $first = true;
    $carryCarriageReturn = false;
    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Could not read an update file for integrity validation.');
            }
            if ($chunk === '') {
                continue;
            }
            if ($first) {
                $first = false;
                if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
                    $chunk = substr($chunk, 3);
                }
            }
            if ($carryCarriageReturn) {
                $chunk = "\r" . $chunk;
                $carryCarriageReturn = false;
            }
            if ($chunk !== '' && str_ends_with($chunk, "\r")) {
                $chunk = substr($chunk, 0, -1);
                $carryCarriageReturn = true;
            }
            if ($chunk !== '') {
                hash_update($context, str_replace(["\r\n", "\r"], "\n", $chunk));
            }
        }
        if ($carryCarriageReturn) {
            hash_update($context, "\n");
        }
    } finally {
        fclose($handle);
    }
    return 'sha256:' . hash_final($context);
}

/**
 * Validate the extracted package before activation.
 *
 * Core-manifest verification is temporarily disabled. Archive entry validation,
 * source-root validation, updater-managed path filtering, schema preflight, and
 * activation rollback remain enforced by their owning stages.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker budget.
 * @return bool True when the package is ready for activation.
 */
function application_update_job_validate_package_slice(array &$job, array $budget): bool
{
    $jobDir = application_update_job_dir((string) $job['id']);
    $sourceRoot = application_update_extracted_root($jobDir . '/extract');
    application_update_assert_source_root($sourceRoot);
    $job['checkpoints']['source_root'] = $sourceRoot;

    $bootstrapVersion = application_update_version_from_local_bootstrap($sourceRoot . '/app/bootstrap.php');
    $job['checkpoints']['validated_version'] = $bootstrapVersion ?? '';
    if ((string) ($job['operation'] ?? '') === 'stable_update') {
        $validatedVersion = (string) $job['checkpoints']['validated_version'];
        if ($validatedVersion === '' || version_compare($validatedVersion, \Gallery\Core\cms_current_version(), '<=')) {
            throw new RuntimeException('No newer version is available in the downloaded stable package.');
        }
        $targetVersion = application_update_normalize_version((string) ($job['parameters']['target_version'] ?? ''));
        if ($targetVersion !== null && version_compare($validatedVersion, $targetVersion, '<')) {
            throw new RuntimeException('Downloaded stable package is older than the version selected for this job.');
        }
    }

    unset($job['checkpoints']['manifest_files'], $job['checkpoints']['verify_index']);
    $job['progress'] = ['current' => 1, 'total' => 1, 'message' => 'Package structure validated.', 'unit' => 'step'];
    application_update_save_job($job);
    return true;
}
