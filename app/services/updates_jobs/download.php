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
 * @param array{operation:string,parameters:array{commit?:string,branch?:string},checkpoints?:array{archive_commit?:string,archive_release_asset?:array{version:string,sha256:string,bytes:int}}} $job Operation, pinned commit and optional verified published archive.
 * @return string Trusted update URL.
 */
function application_update_job_archive_url(array $job): string
{
    $operation = (string) ($job['operation'] ?? '');
    $parameters = (array) ($job['parameters'] ?? []);
    $commit = (string) ($job['checkpoints']['archive_commit'] ?? '');
    $asset = $job['checkpoints']['archive_release_asset'] ?? null;
    if (is_array($asset)) {
        $version = (string) ($asset['version'] ?? '');
        if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1
            || preg_match('/^[0-9]+(?:\.[0-9]+){1,2}$/D', $version) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($asset['sha256'] ?? '')) !== 1
            || (int) ($asset['bytes'] ?? 0) <= 0) {
            throw new RuntimeException('Persisted update asset identity is invalid.');
        }
        return 'https://github.com/' . \Gallery\Core\CMS_GITHUB_REPOSITORY
            . '/releases/download/v_' . $version . '/production.zip';
    }
    if ($commit !== '') {
        if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1) {
            throw new RuntimeException('Persisted update commit identity is invalid.');
        }
        return application_update_commit_zip_url($commit);
    }
    if ($operation === 'beta_install') {
        return application_update_commit_zip_url(
            (string) ($parameters['commit'] ?? ''),
        );
    }
    return application_update_zip_url((string) ($parameters['branch'] ?? ''));
}

/**
 * Observe archive bytes after this request has changed the file.
 *
 * PHP caches successful stat results independently of writes through cURL or
 * an open stream. Refresh both the initial offset and the completed byte count
 * so a resumed transfer cannot report its previous size or reject completion.
 *
 * @param string $path Absolute path to this job's downloaded archive.
 * @return int Current byte count, or zero when the archive does not exist yet.
 * @throws RuntimeException When an existing archive cannot be measured.
 */
function application_update_download_size(string $path): int
{
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return 0;
    }
    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('Could not measure update archive bytes.');
    }
    return $size;
}

/**
 * Yield an incomplete download or stop repeated unsupported Range restarts.
 *
 * The original archive commit and every completed installation checkpoint stay
 * intact. Repeated restarts request the separately verified published archive;
 * a source without a matching published archive requires manual deployment.
 *
 * @param array{id:string,checkpoints:array{archive_resume_restarts?:int,archive_fallback_pending?:bool,archive_release_asset?:array{version:string,sha256:string,bytes:int}}} $job Durable restart and archive-selection checkpoints.
 * @param bool $restarted Whether this response replaced a nonempty prefix with a full HTTP 200 response.
 * @return bool Always false; requests published-archive selection after source restarts, or throws when the selected asset also cannot resume.
 * @throws RuntimeException When bounded transfers cannot resume this source.
 */
function application_update_download_incomplete(
    array &$job,
    bool $restarted,
): bool {
    if ($restarted) {
        $job['checkpoints']['archive_resume_restarts'] =
            (int) ($job['checkpoints']['archive_resume_restarts'] ?? 0) + 1;
        application_update_save_job($job);
        if ($job['checkpoints']['archive_resume_restarts']
            >= APPLICATION_UPDATE_DOWNLOAD_RESTART_LIMIT) {
            if (isset($job['checkpoints']['archive_release_asset'])) {
                throw new RuntimeException(
                    'Update archive source does not support resuming within the worker time limit.',
                );
            }
            $job['checkpoints']['archive_fallback_pending'] = true;
            application_update_save_job($job);
        }
    }
    return false;
}

/**
 * Select a published production archive for the same immutable source commit.
 *
 * Each invocation performs at most one GitHub metadata request. The release's
 * canonical tag must resolve to archive_commit, and its sole production.zip
 * asset must provide a SHA-256 digest and byte count. Only then may the old
 * partial source ZIP be discarded. A crash before publication repeats selection
 * against the same commit; it never combines two archives or changes the target.
 *
 * @param array{id:string,checkpoints:array{archive_commit?:string,archive_fallback_version?:string,archive_fallback_release?:array{version:string,sha256:string,bytes:int},archive_release_asset?:array{version:string,sha256:string,bytes:int},archive_fallback_pending?:bool,archive_url?:string,archive_etag?:string,archive_last_modified?:string,archive_expected_bytes?:int,archive_resume_restarts?:int}} $job Durable source and metadata checkpoints, updated by reference.
 * @param array{deadline:float,seconds:float} $budget Current worker deadline and duration.
 * @return bool Always false; a later slice downloads the selected archive.
 * @throws RuntimeException When metadata or exact-commit/digest binding is unavailable.
 */
function application_update_download_select_release(
    array &$job,
    array $budget,
): bool {
    $commit = (string) ($job['checkpoints']['archive_commit'] ?? '');
    if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1) {
        throw new RuntimeException('Update archive fallback requires a pinned commit.');
    }
    $timeout = application_update_remote_timeout_seconds(
        (float) $budget['deadline'], APPLICATION_UPDATE_COMMIT_LOOKUP_SECONDS,
    );
    if ($timeout <= 0) {
        return false;
    }
    $api = 'https://api.github.com/repos/'
        . \Gallery\Core\CMS_GITHUB_REPOSITORY;
    $version = (string) ($job['checkpoints']['archive_fallback_version'] ?? '');
    if ($version === '') {
        $response = cms_github_api_get(
            $api . '/contents/app/bootstrap.php?ref=' . $commit,
            $timeout,
            ['Accept: application/vnd.github.raw+json'],
            true,
        );
        $version = application_update_version_from_bootstrap(
            (string) $response['body'],
        );
        if ($version === null) {
            throw new RuntimeException('Pinned update archive version is unavailable.');
        }
        $job['checkpoints']['archive_fallback_version'] = $version;
        application_update_save_job($job);
        return false;
    }
    if (application_update_normalize_version($version) !== $version) {
        throw new RuntimeException('Pinned update archive version is invalid.');
    }
    $tag = 'v_' . $version;
    $asset = $job['checkpoints']['archive_fallback_release'] ?? null;
    if (!is_array($asset)) {
        $response = cms_github_api_get(
            $api . '/releases/tags/' . $tag,
            $timeout,
            ['Accept: application/vnd.github+json'],
            true,
        );
        $release = json_decode((string) $response['body'], true);
        $assets = is_array($release['assets'] ?? null)
            ? array_values(array_filter(
                $release['assets'],
                static fn(mixed $entry): bool => is_array($entry)
                    && ($entry['name'] ?? '') === 'production.zip',
            )) : [];
        $candidate = $assets[0] ?? [];
        $digest = (string) ($candidate['digest'] ?? '');
        $expectedUrl = 'https://github.com/'
            . \Gallery\Core\CMS_GITHUB_REPOSITORY
            . '/releases/download/' . $tag . '/production.zip';
        if (($release['tag_name'] ?? '') !== $tag
            || ($release['draft'] ?? null) !== false
            || ($release['prerelease'] ?? null) !== false
            || count($assets) !== 1
            || ($candidate['browser_download_url'] ?? '') !== $expectedUrl
            || !is_int($candidate['size'] ?? null)
            || $candidate['size'] <= 0
            || preg_match('/^sha256:([a-f0-9]{64})$/D', $digest, $match) !== 1) {
            throw new RuntimeException('Matching published update archive is unavailable.');
        }
        $job['checkpoints']['archive_fallback_release'] = [
            'version' => $version,
            'sha256' => $match[1],
            'bytes' => $candidate['size'],
        ];
        application_update_save_job($job);
        return false;
    }
    $response = cms_github_api_get(
        $api . '/commits/' . $tag,
        $timeout,
        ['Accept: application/vnd.github+json'],
        true,
    );
    $tagCommit = json_decode((string) $response['body'], true);
    if (($tagCommit['sha'] ?? '') !== $commit
        || ($asset['version'] ?? '') !== $version) {
        throw new RuntimeException('Published update archive belongs to a different commit.');
    }
    $archivePath = application_update_job_dir((string) $job['id'])
        . '/package.zip';
    if (is_file($archivePath) && !unlink($archivePath)) {
        throw new RuntimeException('Could not replace partial update archive.');
    }
    unset(
        $job['checkpoints']['archive_url'],
        $job['checkpoints']['archive_etag'],
        $job['checkpoints']['archive_last_modified'],
        $job['checkpoints']['archive_expected_bytes'],
    );
    $job['checkpoints']['archive_resume_restarts'] = 0;
    $job['checkpoints']['archive_release_asset'] = $asset;
    $job['checkpoints']['archive_fallback_pending'] = false;
    $job['progress'] = [
        'current' => 0, 'total' => $asset['bytes'],
        'message' => 'Downloading update package.',
        'unit' => 'bytes',
    ];
    application_update_save_job($job);
    return false;
}

/**
 * Stream a remote archive into the job workspace for a bounded amount of time.
 *
 * Partial files are kept between requests. cURL Range requests are preferred.
 * When a server ignores a resume Range and replies 200, the local partial file
 * is truncated before response bytes are written so duplicate data cannot be appended.
 *
 * @param array{id:string,operation:string,stage:string,trigger?:string,recovery_schema?:int,parameters:array{branch?:string,commit?:string},checkpoints:array{archive_commit?:string,archive_url?:string,archive_etag?:string,archive_last_modified?:string,archive_expected_bytes?:int,archive_resume_restarts?:int,archive_fallback_pending?:bool,archive_fallback_version?:string,archive_fallback_release?:array{version:string,sha256:string,bytes:int},archive_release_asset?:array{version:string,sha256:string,bytes:int}}} $job Durable source identity and transfer checkpoints, updated by reference.
 * @param array{deadline:float,seconds:float} $budget Wall-clock worker deadline and duration.
 * @return bool True for a complete response; false for a pinned lookup or incomplete transfer.
 * @throws RuntimeException When transport, local storage or bounded resumption fails.
 */
function application_update_job_download_slice(array &$job, array $budget): bool
{
    if (!empty($job['checkpoints']['archive_fallback_pending'])) {
        return application_update_download_select_release($job, $budget);
    }
    $jobDir = application_update_job_dir((string) $job['id']);
    $archivePath = $jobDir . '/package.zip';
    $offset = application_update_download_size($archivePath);
    if (($job['recovery_schema'] ?? 0) === 1
        && ($job['operation'] ?? '') !== 'rollback'
        && empty($job['checkpoints']['archive_commit'])) {
        $branch = ($job['operation'] ?? '') === 'beta_install'
            ? (string) ($job['parameters']['commit'] ?? '')
            : (string) ($job['parameters']['branch'] ?? '');
        if (($job['operation'] ?? '') !== 'beta_install') {
            application_update_assert_allowed_branch($branch);
        }
        $timeout = application_update_remote_timeout_seconds(
            (float) $budget['deadline'], APPLICATION_UPDATE_COMMIT_LOOKUP_SECONDS,
        );
        if ($timeout <= 0) {
            return false;
        }
        $response = cms_github_api_get(
            'https://api.github.com/repos/' . \Gallery\Core\CMS_GITHUB_REPOSITORY
                . '/commits/' . rawurlencode($branch),
            $timeout,
            ['Accept: application/vnd.github+json'],
            true,
        );
        $payload = json_decode((string) $response['body'], true);
        $commit = (string) ($payload['sha'] ?? '');
        if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1) {
            throw new RuntimeException('Stable GitHub commit identity is unavailable.');
        }
        $job['checkpoints']['archive_commit'] = $commit;
        application_update_save_job($job);
        $excluded = json_decode(app_setting('application_update_failed_commits', '[]'), true);
        if (($job['trigger'] ?? '') === 'background' && ($job['operation'] ?? '') === 'stable_update'
            && is_array($excluded) && in_array($commit, $excluded, true)) {
            throw new RuntimeException('This previously failed update commit is quarantined.');
        }
        // Resolve and stream in different slices, preserving the I/O budget.
        return false;
    }
    $url = trim((string) ($job['checkpoints']['archive_url'] ?? ''));
    if ($url === '') {
        // Keep the same trusted archive URL across continuation requests. The
        // response validator below protects Range resume when a branch head moves.
        $url = application_update_job_archive_url($job);
        $job['checkpoints']['archive_url'] = $url;
        application_update_save_job($job);
    }
    if (
        !str_starts_with($url, 'https://codeload.github.com/')
        && !str_starts_with($url, 'https://github.com/')
    ) {
        throw new RuntimeException(
            'Persisted update archive source is not trusted.',
        );
    }
    if (!empty($job['checkpoints']['archive_commit'])
        && $url !== application_update_job_archive_url($job)) {
        throw new RuntimeException('Persisted update archive identity changed.');
    }

    // Keep each external I/O call below the normal worker slice.
    $remaining = max(
        2.0,
        (float) ($budget['deadline'] ?? (microtime(true) + 2.0))
            - microtime(true)
            - 0.75,
    );
    $ioTimeout = (int) max(2, min(7, floor($remaining)));

    if (function_exists('curl_init')) {
        $handle = fopen($archivePath, $offset > 0 ? 'ab' : 'wb');
        if ($handle === false) {
            throw new RuntimeException(
                'Could not open update archive destination.',
            );
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
            CURLOPT_USERAGENT =>
                'PHP-Gallery-CMS/' . \Gallery\Core\cms_current_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FILE => $handle,
            CURLOPT_FAILONERROR => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function (
                \CurlHandle $curlHandle,
                string $line,
            ) use (
                &$responseStatus,
                &$contentLength,
                &$contentRangeTotal,
                &$responseEtag,
                &$responseLastModified,
                &$rangeHonored,
                $offset,
                $handle,
            ): int {
                $length = strlen($line);
                if (
                    preg_match('/^HTTP\/\S+\s+(\d+)/i', trim($line), $match)
                    === 1
                ) {
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
                } elseif (
                    stripos($line, 'Content-Range:') === 0
                    && $offset > 0
                ) {
                    $rangeHonored = true;
                    if (
                        preg_match('#/([0-9]+)\s*$#', trim($line), $rangeMatch)
                        === 1
                    ) {
                        $contentRangeTotal = (int) $rangeMatch[1];
                    }
                } elseif (stripos($line, 'Content-Length:') === 0) {
                    $contentLength = (int) trim(substr(
                        $line,
                        strlen('Content-Length:'),
                    ));
                } elseif (stripos($line, 'ETag:') === 0) {
                    $responseEtag = trim(substr($line, strlen('ETag:')));
                } elseif (stripos($line, 'Last-Modified:') === 0) {
                    $responseLastModified = trim(substr(
                        $line,
                        strlen('Last-Modified:'),
                    ));
                }
                return $length;
            },
        ]);
        if ($offset > 0) {
            curl_setopt($curl, CURLOPT_RANGE, $offset . '-');
            $ifRange = trim(
                (string) ($job['checkpoints']['archive_etag'] ?? ''),
            );
            if ($ifRange === '') {
                $ifRange = trim(
                    (string) (
                        $job['checkpoints']['archive_last_modified'] ?? ''
                    ),
                );
            }
            if ($ifRange !== '') {
                curl_setopt(
                    $curl,
                    CURLOPT_HTTPHEADER,
                    ['If-Range: ' . $ifRange],
                );
            }
        }

        $ok = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fflush($handle);
        fclose($handle);

        $size = application_update_download_size($archivePath);
        $expectedBytes = $contentRangeTotal;
        if ($expectedBytes === null && $contentLength !== null) {
            $expectedBytes = $rangeHonored && $offset > 0
                ? $offset + $contentLength
                : $contentLength;
        }
        if ($responseEtag !== null && $responseEtag !== '') {
            $job['checkpoints']['archive_etag'] = $responseEtag;
        }
        if ($responseLastModified !== null && $responseLastModified !== '') {
            $job['checkpoints']['archive_last_modified'] =
                $responseLastModified;
        }
        if ($expectedBytes !== null && $expectedBytes > 0) {
            $job['checkpoints']['archive_expected_bytes'] = $expectedBytes;
        }
        $job['progress'] = [
            'current' => $size,
            'total' => (int) (
                $job['checkpoints']['archive_expected_bytes'] ?? 0
            ),
            'message' => 'Downloading update package.',
            'unit' => 'bytes',
        ];
        application_update_save_job($job);

        if ($status >= 400) {
            throw new RuntimeException('Update archive HTTP request failed.');
        }
        if ($ok === true && in_array($status, [200, 206], true)) {
            $expected = (int) (
                $job['checkpoints']['archive_expected_bytes'] ?? 0
            );
            if ($expected > 0 && $size !== $expected) {
                throw new RuntimeException(
                    'Update archive download completed with an unexpected size.',
                );
            }
            return true;
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT && $size > 0) {
            return application_update_download_incomplete(
                $job,
                $offset > 0 && $status === 200,
            );
        }
        throw new RuntimeException(
            'Update archive download failed before completion.',
        );
    }

    $headers =
        'User-Agent: PHP-Gallery-CMS/'
        . \Gallery\Core\cms_current_version()
        . "\r\nCache-Control: no-cache\r\n";
    if ($offset > 0) {
        $headers .= 'Range: bytes=' . $offset . "-\r\n";
        $ifRange = trim((string) ($job['checkpoints']['archive_etag'] ?? ''));
        if ($ifRange === '') {
            $ifRange = trim(
                (string) ($job['checkpoints']['archive_last_modified'] ?? ''),
            );
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
        throw new RuntimeException(
            'Update archive download could not be opened.',
        );
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
            if (
                preg_match('#/([0-9]+)\s*$#', trim((string) $line), $rangeMatch)
                === 1
            ) {
                $contentRangeTotal = (int) $rangeMatch[1];
            }
        } elseif (stripos((string) $line, 'Content-Length:') === 0) {
            $contentLength = (int) trim(substr(
                (string) $line,
                strlen('Content-Length:'),
            ));
        } elseif (stripos((string) $line, 'ETag:') === 0) {
            $responseEtag = trim(substr((string) $line, strlen('ETag:')));
        } elseif (stripos((string) $line, 'Last-Modified:') === 0) {
            $responseLastModified = trim(substr(
                (string) $line,
                strlen('Last-Modified:'),
            ));
        }
    }
    if (!in_array($status, [200, 206], true)) {
        fclose($remote);
        throw new RuntimeException('Update archive HTTP request failed.');
    }

    $restarted = $offset > 0 && !$rangeHonored;
    $local = fopen($archivePath, $offset > 0 && $rangeHonored ? 'ab' : 'wb');
    if ($local === false) {
        fclose($remote);
        throw new RuntimeException(
            'Could not open update archive destination.',
        );
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
        if (fwrite($local, $chunk) !== strlen($chunk)) {
            fclose($local);
            fclose($remote);
            throw new RuntimeException('Could not write update archive chunk.');
        }
    }
    fflush($local);
    fclose($local);
    fclose($remote);

    $size = application_update_download_size($archivePath);
    if ($responseEtag !== '') {
        $job['checkpoints']['archive_etag'] = $responseEtag;
    }
    if ($responseLastModified !== '') {
        $job['checkpoints']['archive_last_modified'] = $responseLastModified;
    }
    $expectedBytes = $contentRangeTotal > 0
        ? $contentRangeTotal
        : ($contentLength > 0 ? $offset + $contentLength : 0);
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
            throw new RuntimeException(
                'Update archive download completed with an unexpected size.',
            );
        }
    }
    if ($complete) {
        return true;
    }
    return application_update_download_incomplete($job, $restarted);
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
    if (
        $normalized === ''
        || str_starts_with($normalized, '/')
        || preg_match('/^[A-Za-z]:\//', $normalized)
    ) {
        throw new RuntimeException('Update archive contains an unsafe path.');
    }
    foreach (explode('/', trim($normalized, '/')) as $segment) {
        if ($segment === '..' || $segment === '.') {
            throw new RuntimeException(
                'Update archive contains an unsafe path.',
            );
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
function application_update_zip_entry_is_symlink(
    ZipArchive $zip,
    int $index,
): bool {
    $opsys = 0;
    $attributes = 0;
    if (
        !method_exists($zip, 'getExternalAttributesIndex')
        || !$zip->getExternalAttributesIndex($index, $opsys, $attributes)
    ) {
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
 * @param array{id:string,stage:string,checkpoints:array{archive_release_asset?:array{version:string,sha256:string,bytes:int},archive_release_digest_verified?:bool,archive_validate_index?:int,archive_uncompressed_bytes?:int,archive_entries?:int,extract_index?:int}} $job Archive identity and bounded validation cursor, updated by reference.
 * @param array{deadline:float,seconds:float}|null $budget Optional worker budget; null still caps one call by entry count.
 * @return bool True when every archive entry has been validated.
 */
function application_update_job_validate_archive(
    array &$job,
    ?array $budget = null,
): bool {
    $archivePath =
        application_update_job_dir((string) $job['id']) . '/package.zip';
    if (!is_file($archivePath) || filesize($archivePath) === 0) {
        throw new RuntimeException('Downloaded update archive is empty.');
    }
    $asset = $job['checkpoints']['archive_release_asset'] ?? null;
    if (is_array($asset)
        && empty($job['checkpoints']['archive_release_digest_verified'])) {
        if (application_update_download_size($archivePath) !== $asset['bytes']
            || !hash_equals($asset['sha256'],
                (string) hash_file('sha256', $archivePath))) {
            throw new RuntimeException('Published update archive failed SHA-256 verification.');
        }
        $job['checkpoints']['archive_release_digest_verified'] = true;
        application_update_save_job($job);
    }
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        throw new RuntimeException(
            'Downloaded update archive could not be opened.',
        );
    }

    try {
        if ($zip->numFiles < 1 || $zip->numFiles > 20000) {
            throw new RuntimeException(
                'Downloaded update archive has an invalid entry count.',
            );
        }

        $index = (int) ($job['checkpoints']['archive_validate_index'] ?? 0);
        $uncompressed = (int) (
            $job['checkpoints']['archive_uncompressed_bytes'] ?? 0
        );
        if ($index < 0 || $index > $zip->numFiles) {
            throw new RuntimeException(
                'Update archive validation checkpoint is invalid.',
            );
        }
        $processed = 0;
        while (
            $index < $zip->numFiles
            && $processed < 500
            && (
                $budget === null
                || application_update_budget_allows($budget, 0.7)
            )
        ) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name'])) {
                throw new RuntimeException(
                    'Downloaded update archive contains an unreadable entry.',
                );
            }
            application_update_safe_zip_entry((string) $stat['name']);
            if (application_update_zip_entry_is_symlink($zip, $index)) {
                throw new RuntimeException(
                    'Downloaded update archive contains unsupported symbolic links.',
                );
            }
            $size = max(0, (int) ($stat['size'] ?? 0));
            // Application releases should contain code/assets, not giant opaque blobs.
            // This cap also bounds the one-entry extraction unit on slow hosting.
            if ($size > (32 * 1024 * 1024)) {
                throw new RuntimeException(
                    'Downloaded update archive contains an oversized file.',
                );
            }
            $uncompressed += $size;
            if ($uncompressed > (512 * 1024 * 1024)) {
                throw new RuntimeException(
                    'Downloaded update archive expands beyond the safe size limit.',
                );
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
            $job['checkpoints']['extract_index'] = (int) (
                $job['checkpoints']['extract_index'] ?? 0
            );
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
 * @param array{id:string,stage:string,...<string,mixed>} $job Durable update job state, updated by reference.
 * @param array{deadline:float,seconds:float,...<string,int|float|string>} $budget Worker time budget.
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
        throw new RuntimeException(
            'Downloaded update archive could not be reopened for extraction.',
        );
    }
    $index = (int) ($job['checkpoints']['extract_index'] ?? 0);
    $processed = 0;
    try {
        while (
            $index < $zip->numFiles
            && $processed < 80
            && application_update_budget_allows($budget, 0.7)
        ) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name'])) {
                throw new RuntimeException(
                    'Downloaded update archive contains an unreadable entry.',
                );
            }
            $entry = application_update_safe_zip_entry((string) $stat['name']);
            $target =
                $extractDir
                . '/'
                . str_replace('/', DIRECTORY_SEPARATOR, $entry);
            if (str_ends_with($entry, '/')) {
                application_update_ensure_dir($target);
            } else {
                application_update_ensure_dir(dirname($target));
                $input = $zip->getStream((string) $stat['name']);
                if ($input === false) {
                    throw new RuntimeException(
                        'Could not read an update archive entry.',
                    );
                }
                $temporary = $target . '.part';
                $output = fopen($temporary, 'wb');
                if ($output === false) {
                    fclose($input);
                    throw new RuntimeException(
                        'Could not create an extracted update file.',
                    );
                }
                $copied = stream_copy_to_stream($input, $output);
                fclose($output);
                fclose($input);
                if (
                    $copied === false
                    || $copied !== (int) ($stat['size'] ?? $copied)
                ) {
                    @unlink($temporary);
                    throw new RuntimeException(
                        'Extracted update file failed size verification.',
                    );
                }
                if (!rename($temporary, $target)) {
                    @unlink($temporary);
                    throw new RuntimeException(
                        'Could not commit an extracted update file.',
                    );
                }
            }
            $index++;
            $processed++;
            $job['checkpoints']['extract_index'] = $index;
            $job['progress'] = [
                'current' => $index,
                'total' => $zip->numFiles,
                'message' => 'Extracting update package.',
                'unit' => 'entries',
            ];
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
        throw new RuntimeException(
            'Could not read an update file for integrity validation.',
        );
    }
    $context = hash_init('sha256');
    $first = true;
    $carryCarriageReturn = false;
    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException(
                    'Could not read an update file for integrity validation.',
                );
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
                hash_update($context, str_replace(
                    ["\r\n", "\r"],
                    "\n",
                    $chunk,
                ));
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
 * Core-manifest verification is temporarily disabled for legacy persisted jobs.
 * Explicit Admin FTP/stable recovery treats manifest checks as diagnostics:
 * their failures become warnings and never stop forward installation.
 * The optional publisher sweep is disabled until explicitly re-enabled.
 * Independent runtime files and safe paths remain required.
 * Archive entry validation, source-root validation, updater-managed path
 * filtering, schema preflight, and
 * activation rollback remain enforced by their owning stages.
 *
 * @param array{id:string,stage:string,...<string,mixed>} $job Durable update job state, updated by reference.
 * @param array{deadline:float,seconds:float,...<string,int|float|string>} $budget Worker time budget.
 * @return bool True when the package is ready for activation.
 */
function application_update_job_validate_package_slice(
    array &$job,
    array $budget,
): bool {
    $jobDir = application_update_job_dir((string) $job['id']);
    $manual = ($job['operation'] ?? '') === 'manual_deploy';
    $sourceRoot = $manual ? application_update_project_root()
        : application_update_extracted_root($jobDir . '/extract');
    $recovery = $manual || !empty($job['recovery_override']);
    application_update_assert_source_root($sourceRoot, $recovery, !$recovery);
    $job['checkpoints']['source_root'] = $sourceRoot;
    $verifyPublisher = APPLICATION_UPDATE_PACKAGE_INTEGRITY_ENABLED;
    $job['checkpoints']['publisher_integrity_disabled'] = !$verifyPublisher;

    $bootstrapVersion = application_update_version_from_local_bootstrap($sourceRoot
        . '/app/bootstrap.php');
    $job['checkpoints']['validated_version'] = $bootstrapVersion ?? '';
    if ($recovery) {
        try {
            if ($verifyPublisher && !is_file($sourceRoot . '/app/core-manifest.json')) {
                throw new RuntimeException('Recovery diagnostic manifest is missing.');
            }
            if ($verifyPublisher && !application_update_recovery_verify_package(
                $job, $sourceRoot, $budget, true,
            )) {
                return false;
            }
        } catch (Throwable $warning) {
            $safe = application_update_safe_error($warning);
            // Use the existing redaction owner for exception references. Only
            // the observed file presence becomes a reason, never raw host text.
            $reason = is_file($sourceRoot . '/app/core-manifest.json')
                ? 'invalid_or_inconsistent_manifest' : 'missing_manifest';
            $context = [
                'job_id' => $job['id'],
                'stage' => 'package_validate',
                'integrity_file' => 'app/core-manifest.json',
                'reason' => $reason,
                'reference' => $safe['reference'],
                'exception_class' => get_class($warning),
                'owner_file' => basename($warning->getFile()),
                'owner_line' => $warning->getLine(),
                'outcome' => 'continuing',
            ];
            $job['integrity_warnings']['app/core-manifest.json'] = $context;
            try {
                if (function_exists(__NAMESPACE__ . '\\admin_log_event')) {
                    admin_log_event(
                        'warning', 'update.recovery_validation_warning',
                        'Recovery validation reported a warning; installation continues.',
                        $context, ['category' => 'update', 'severity' => 'warning'],
                    );
                }
            } catch (Throwable) {
                // The saved warning survives unavailable host logging.
            }
        }
        // These observations cannot veto the explicit Admin overwrite intent.
        // Staging pins the actual selected bytes independently of this metadata.
        $job['checkpoints']['core_manifest_not_required'] = true;
        $job['progress'] = [
            'current' => 1, 'total' => 1,
            'message' => 'Recovery source accepted; diagnostic warnings do not stop installation.',
            'unit' => 'step',
        ];
        application_update_save_job($job);
        return true;
    }
    if ((string) ($job['operation'] ?? '') === 'stable_update') {
        $validatedVersion = (string) $job['checkpoints']['validated_version'];
        if (
            $validatedVersion === ''
            || version_compare(
                $validatedVersion,
                \Gallery\Core\cms_current_version(),
                '<=',
            )
        ) {
            throw new RuntimeException(
                'No newer version is available in the downloaded stable package.',
            );
        }
        $targetVersion = application_update_normalize_version(
            (string) ($job['parameters']['target_version'] ?? ''),
        );
        if (
            $targetVersion !== null
            && version_compare($validatedVersion, $targetVersion, '<')
        ) {
            throw new RuntimeException(
                'Downloaded stable package is older than the version selected for this job.',
            );
        }
    }

    if ($verifyPublisher && ($job['recovery_schema'] ?? 0) === 1
        && !application_update_recovery_verify_package($job, $sourceRoot, $budget)) {
        return false;
    }

    unset(
        $job['checkpoints']['manifest_files'],
        $job['checkpoints']['verify_index'],
    );
    $job['progress'] = [
        'current' => 1,
        'total' => 1,
        'message' => 'Package structure validated.',
        'unit' => 'step',
    ];
    application_update_save_job($job);
    return true;
}


// Recovery responsibilities stay with their existing updater domain owner.

/**
 * Fingerprint exact bytes outside the publisher's normalized text manifest.
 * @param string $path Existing validated regular file path.
 * @return string Private sha256-raw digest preserving binary and snapshot bytes.
 * @throws RuntimeException When the complete file cannot be read.
 */
function application_update_recovery_raw_hash(string $path): string
{
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException('Recovery file bytes could not be verified.');
    }
    return 'sha256-raw:' . $hash;
}

/**
 * Verify a stored typed digest without normalizing binary or snapshot bytes.
 * @param string $expected Publisher normalized digest or private exact-byte digest.
 * @param string $path Existing safe regular file path.
 * @return bool True only for a valid digest type and matching complete bytes.
 */
function application_update_recovery_hash_matches(string $expected, string $path): bool
{
    if (preg_match('/^sha256-raw:[a-f0-9]{64}$/D', $expected) === 1) {
        return hash_equals($expected, application_update_recovery_raw_hash($path));
    }
    return preg_match('/^sha256:[a-f0-9]{64}$/D', $expected) === 1
        && hash_equals($expected, application_update_manifest_hash($path));
}

/**
 * Verify publisher-owned core coverage and pin other trusted package bytes.
 * @param array<string,mixed> $job Recovery job with durable verification cursor.
 * @param string $root Validated extracted package root.
 * @param array{deadline:float,seconds:float,...<string,int|float|string>} $budget Bounded worker opportunity.
 * @param bool $installedOverlay Whether declared files belong to explicit recovery, preserving extras and recording manifest differences as warnings.
 * @return bool True after every managed file is observed/pinned; false yields the cursor. Ordinary jobs reject differences; explicit recovery records warnings.
 * @throws RuntimeException On missing coverage, changed manifest or bad bytes.
 */
function application_update_recovery_verify_package(
    array &$job,
    string $root,
    array $budget,
    bool $installedOverlay = false,
): bool {
    $path = $root . '/app/core-manifest.json';
    $size = filesize($path);
    if ($size === false || $size > APPLICATION_UPDATE_RECOVERY_MANIFEST_BYTES) {
        throw new RuntimeException('Recovery package manifest is oversized or missing.');
    }
    $manifest = application_update_read_json($path);
    $identity = application_update_manifest_hash($path);
    $previous = (string) ($job['checkpoints']['recovery_manifest_identity'] ?? $identity);
    if ($identity !== $previous) {
        throw new RuntimeException('Recovery package manifest changed during verification. Restart the recovery.');
    }
    if (($manifest['algorithm'] ?? '') !== 'sha256'
        || ($manifest['hash_mode'] ?? '') !== 'normalized-text-sha256'
        || ($manifest['version'] ?? '') !== ($job['checkpoints']['validated_version'] ?? '')
        || !is_array($manifest['files'] ?? null)) {
        throw new RuntimeException('Recovery package manifest identity is invalid.');
    }
    $job['checkpoints']['recovery_manifest_identity'] = $identity;
    $files = application_update_release_files($root, $installedOverlay);
    $index = (int) ($job['checkpoints']['recovery_verify_index'] ?? 0);
    $processed = 0;
    while ($index < count($files)
        && $processed < APPLICATION_UPDATE_RECOVERY_VERIFY_FILES
        && application_update_budget_allows($budget, 0.7)) {
        $relative = $files[$index];
        // The manifest cannot contain a hash of its own final bytes.
        if ($relative !== 'app/core-manifest.json') {
            application_update_assert_safe_target($root, $relative);
            $core = \Gallery\Core\release_file_policy_is_integrity_path($relative);
            $expected = $core ? (string) ($manifest['files'][$relative] ?? '')
                : application_update_recovery_raw_hash($root . '/' . $relative);
            $actual = $core ? application_update_manifest_hash($root . '/' . $relative)
                : $expected;
            if (($core && preg_match('/^sha256:[a-f0-9]{64}$/D', $expected) !== 1)
                || !hash_equals($expected, $actual)) {
                $difference = new ApplicationUpdateFileIntegrityException(
                    $relative,
                    preg_match('/^sha256:[a-f0-9]{64}$/D', $expected) === 1 ? $expected : '',
                    $actual,
                );
                if (!$installedOverlay) {
                    throw $difference;
                }
                application_update_record_integrity_warning($job, $difference);
                // Explicit recovery installs the selected bytes; later staging
                // still pins those exact bytes instead of accepting a new change.
                $expected = application_update_recovery_raw_hash($root . '/' . $relative);
            }
            $job['checkpoints']['recovery_hashes'][$relative] = $expected;
        }
        $index++;
        $processed++;
    }
    $job['checkpoints']['recovery_verify_index'] = $index;
    $job['progress'] = [
        'current' => $index,
        'total' => count($files),
        'message' => 'Verifying package file integrity.',
        'unit' => 'files',
    ];
    application_update_save_job($job);
    return $index === count($files);
}
