<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/updater_download_transport_test.php
 * Module Type: Regression Test
 * Purpose: Exercise the actual downloader through confined HTTP boundaries.
 * Responsibilities:
 *   - Check fresh sizes, Range continuation, restarts and partial writes.
 *   - Bind production ZIP fallback to the original commit and asset digest.
 *   - Keep all payloads and durable jobs in one disposable installation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Identify a synthetic repository without making external requests. */
    const CMS_GITHUB_REPOSITORY = 'fixture/gallery';
    /** Preserve the updater's stable-branch admission contract. */
    const CMS_UPDATE_BRANCHES = ['main'];

    /** Return the installed fixture version. @return string Installed version. */
    function cms_current_version(): string
    {
        return '1.0.0';
    }
}

namespace Gallery\Services {
    /**
     * Select the stream fallback without disabling any native PHP functions.
     * @param string $name Native function queried by the updater.
     * @return bool Native availability, with cURL hidden in stream scenarios.
     */
    function function_exists(string $name): bool
    {
        return $name === 'curl_init' && $GLOBALS['download_transport'] === 'stream'
            ? false : \function_exists($name);
    }

    /**
     * Create a genuine cURL handle without opening a network connection.
     * @param string $url Trusted archive URL selected by the real downloader.
     * @return \CurlHandle Native handle used only to type-check header callbacks.
     */
    function curl_init(string $url): \CurlHandle
    {
        $GLOBALS['download_url'] = $url;
        $GLOBALS['download_options'] = [];
        return \curl_init();
    }

    /**
     * Capture the actual downloader's transport configuration.
     * @param \CurlHandle $handle Confined native handle, unused by this recorder.
     * @param array<int,bool|int|string|resource|\Closure> $options Native cURL options.
     * @return bool True after recording the complete option map.
     */
    function curl_setopt_array(\CurlHandle $handle, array $options): bool
    {
        $GLOBALS['download_options'] = $options;
        return true;
    }

    /**
     * Capture continuation-specific native options.
     * @param \CurlHandle $handle Confined native handle, unused by this recorder.
     * @param int $option Native Range or request-header option.
     * @param string|list<string> $value Range identity or conditional headers.
     * @return bool True after preserving the option.
     */
    function curl_setopt(\CurlHandle $handle, int $option, string|array $value): bool
    {
        $GLOBALS['download_options'][$option] = $value;
        return true;
    }

    /**
     * Deliver synthetic response headers and bytes to the actual output stream.
     * @param \CurlHandle $handle Native callback identity; no network is used.
     * @return bool Whether the synthetic HTTP response completed.
     */
    function curl_exec(\CurlHandle $handle): bool
    {
        $options = $GLOBALS['download_options'];
        foreach ($GLOBALS['download_headers'] as $line) {
            $options[CURLOPT_HEADERFUNCTION]($handle, $line . "\r\n");
        }
        \fwrite($options[CURLOPT_FILE], $GLOBALS['download_body']);
        return $GLOBALS['download_complete'];
    }

    /**
     * Supply the configured transport interruption.
     * @param \CurlHandle $handle Confined native handle, unused by this fixture.
     * @return int Zero for completion, or the native timeout error identity.
     */
    function curl_errno(\CurlHandle $handle): int
    {
        return $GLOBALS['download_complete'] ? 0 : CURLE_OPERATION_TIMEDOUT;
    }

    /**
     * Supply the response status observed by the transport.
     * @param \CurlHandle $handle Confined native handle, unused by this fixture.
     * @param int $option Requested native response-code field.
     * @return int Synthetic final HTTP status.
     */
    function curl_getinfo(\CurlHandle $handle, int $option): int
    {
        \download_assert($option === CURLINFO_RESPONSE_CODE, 'Unexpected cURL field.');
        return $GLOBALS['download_status'];
    }

    /**
     * Leave disposal to the native handle's lifetime.
     * @param \CurlHandle $handle Confined native handle, unused by this fixture.
     * @return void No network or installation action occurs.
     */
    function curl_close(\CurlHandle $handle): void
    {
    }

    /**
     * Replace only remote opens with a private in-memory response stream.
     * @param string $path Remote trusted URL or actual disposable file path.
     * @param string $mode Native stream mode.
     * @param bool $useIncludePath Native include-path choice.
     * @param resource|null $context Native optional stream context.
     * @return resource|false Synthetic remote stream or native local result.
     */
    function fopen(
        string $path,
        string $mode,
        bool $useIncludePath = false,
        mixed $context = null,
    ): mixed {
        if (str_starts_with($path, 'https://')) {
            $GLOBALS['download_url'] = $path;
            $GLOBALS['download_context'] = \stream_context_get_options($context);
            $remote = \fopen('php://temp', 'w+b');
            \fwrite($remote, $GLOBALS['download_body']);
            \rewind($remote);
            return $remote;
        }
        return \fopen($path, $mode, $useIncludePath, $context);
    }

    /**
     * Supply only the synthetic HTTP response headers.
     * @param resource $stream Synthetic remote response, unused by this fixture.
     * @return array{wrapper_data:list<string>} HTTP status and header lines.
     */
    function stream_get_meta_data(mixed $stream): array
    {
        return ['wrapper_data' => $GLOBALS['download_headers']];
    }

    /**
     * Distinguish an interrupted response from a completed remote EOF.
     * @param resource $stream Remote response whose native cursor reached EOF.
     * @return bool True only for a complete synthetic response at EOF.
     */
    function feof(mixed $stream): bool
    {
        return $GLOBALS['download_complete'] && \feof($stream);
    }

    /**
     * Inject a positive short local write in the stream fallback.
     * @param resource $stream Actual disposable archive destination.
     * @param string $bytes Requested response chunk.
     * @return int|false Native written byte count, optionally one byte short.
     */
    function fwrite(mixed $stream, string $bytes): int|false
    {
        if ($GLOBALS['download_short_write']) {
            return \fwrite($stream, substr($bytes, 0, -1));
        }
        return \fwrite($stream, $bytes);
    }

    /**
     * Serve one expected metadata endpoint without external HTTP.
     * @param string $url Actual immutable bootstrap, release or tag-commit URL.
     * @param int $timeoutSeconds Requested bounded network budget.
     * @param list<string> $headers Native provider media-type headers.
     * @param bool $strict Whether provider errors should throw.
     * @return array{body:string} Exact synthetic endpoint payload.
     */
    function cms_github_api_get(
        string $url,
        int $timeoutSeconds,
        array $headers,
        bool $strict,
    ): array {
        \download_assert($timeoutSeconds > 0 && $strict, 'Unbounded metadata request.');
        \download_assert(isset($GLOBALS['download_api'][$url]), 'Unexpected metadata URL.');
        $GLOBALS['download_api_calls'][] = $url;
        return ['body' => $GLOBALS['download_api'][$url]];
    }
}

namespace {
    require_once __DIR__ . '/../app/services/updates.php';

    use function Gallery\Services\application_update_create_job;
    use function Gallery\Services\application_update_download_select_release;
    use function Gallery\Services\application_update_extracted_root;
    use function Gallery\Services\application_update_job_dir;
    use function Gallery\Services\application_update_job_download_slice;
    use function Gallery\Services\application_update_job_validate_archive;
    use function Gallery\Services\application_update_load_job;
    use function Gallery\Services\application_update_remove_path;
    use function Gallery\Services\application_update_retry_job;
    use function Gallery\Services\application_update_save_job;
    use function Gallery\Services\application_update_time_budget;
    use const Gallery\Services\APPLICATION_UPDATE_CHAIN_SLICE_SECONDS;
    use const Gallery\Services\APPLICATION_UPDATE_DOWNLOAD_RESTART_LIMIT;

    /**
     * Require one observable downloader postcondition.
     * @param bool $condition Expected transport or durable-state outcome.
     * @param string $message Fixture-authored failure description.
     * @return void Throws on a violated condition.
     */
    function download_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Configure one confined HTTP response.
     * @param int $status Protocol status: successful 200/206 or an adverse response.
     * @param list<string> $headers Response headers after the status line.
     * @param string $body Exact response bytes.
     * @param bool $complete Whether EOF/completion or timeout is observed.
     * @return void Replaces the transport response without changing job state.
     */
    function download_response(
        int $status,
        array $headers,
        string $body,
        bool $complete = true,
    ): void {
        $GLOBALS['download_status'] = $status;
        $GLOBALS['download_headers'] = array_merge(['HTTP/1.1 ' . $status], $headers);
        $GLOBALS['download_body'] = $body;
        $GLOBALS['download_complete'] = $complete;
    }

    if (!extension_loaded('curl')) {
        throw new RuntimeException('Downloader regression requires the hosted cURL extension.');
    }
    $root = sys_get_temp_dir() . '/gallery-download-' . bin2hex(random_bytes(6));
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    $GLOBALS['download_short_write'] = false;
    $GLOBALS['download_transport'] = 'curl';
    $GLOBALS['download_api_calls'] = [];
    // Forty hexadecimal characters are the provider's full commit wire identity.
    $commit = str_repeat('a', 40);
    try {
        foreach (['curl', 'stream'] as $transport) {
            $GLOBALS['download_transport'] = $transport;
            $job = application_update_create_job('stable_update', ['branch' => 'main'], 'background');
            $job['checkpoints']['archive_commit'] = $commit;
            $path = application_update_job_dir($job['id']) . '/package.zip';
            $budget = application_update_time_budget(APPLICATION_UPDATE_CHAIN_SLICE_SECONDS);

            // A previously statted empty archive must expose new bytes immediately.
            file_put_contents($path, '');
            download_response(200, ['Content-Length: 6', 'ETag: "fixture"'], 'abc', false);
            download_assert(
                !application_update_job_download_slice($job, $budget),
                'Partial response completed.',
            );
            download_assert($job['progress']['current'] === 3, 'Stale first-slice file size.');

            // Three retained bytes plus bytes 3..5 form the six-byte payload.
            download_response(206, ['Content-Length: 3', 'Content-Range: bytes 3-5/6'], 'def');
            download_assert(
                application_update_job_download_slice($job, $budget),
                'Range completion failed.',
            );
            download_assert(
                file_get_contents($path) === 'abcdef',
                'Range payload was combined incorrectly.',
            );
            download_assert(
                $job['progress']['current'] === 6,
                'Resumed completion retained old size.',
            );
            $request = $transport === 'curl'
                ? implode(' ', $GLOBALS['download_options'][CURLOPT_HTTPHEADER])
                : $GLOBALS['download_context']['http']['header'];
            download_assert(
                str_contains($request, 'If-Range: "fixture"'),
                'Validator was omitted.',
            );

            // HTTP 200 replaces the old prefix; it must never append a full ZIP.
            download_response(200, ['Content-Length: 6'], 'UVWXYZ');
            download_assert(
                application_update_job_download_slice($job, $budget),
                'Full restart did not complete.',
            );
            download_assert(
                file_get_contents($path) === 'UVWXYZ',
                'Ignored Range appended duplicate bytes.',
            );

            // Repeated incomplete full responses must leave the normal chain.
            download_response(200, ['Content-Length: 6'], 'abc', false);
            for ($restart = 0; $restart < APPLICATION_UPDATE_DOWNLOAD_RESTART_LIMIT; $restart++) {
                application_update_job_download_slice($job, $budget);
            }
            download_assert(
                !empty($job['checkpoints']['archive_fallback_pending']),
                'Ignored Range loops forever.',
            );
            download_assert(
                !empty(application_update_load_job($job['id'])['checkpoints']['archive_fallback_pending']),
                'Fallback selection was not durable.',
            );
            $GLOBALS['download_fallback_job'] = $job;
        }

        $GLOBALS['download_short_write'] = true;
        $job = application_update_create_job('stable_update', ['branch' => 'main'], 'admin');
        $job['checkpoints']['archive_commit'] = $commit;
        download_response(200, ['Content-Length: 6'], 'abcdef');
        try {
            application_update_job_download_slice($job, $budget);
            throw new RuntimeException('Positive short write was accepted.');
        } catch (RuntimeException $error) {
            download_assert(
                $error->getMessage() === 'Could not write update archive chunk.',
                'Short write failed at the wrong boundary.',
            );
        }
        $GLOBALS['download_short_write'] = false;

        download_response(302, [], 'redirect body');
        try {
            application_update_job_download_slice($job, $budget);
            throw new RuntimeException('Unresolved redirect was accepted.');
        } catch (RuntimeException $error) {
            download_assert(
                $error->getMessage() === 'Update archive HTTP request failed.',
                'Redirect failed at the wrong boundary.',
            );
        }

        $job = $GLOBALS['download_fallback_job'];
        $path = application_update_job_dir($job['id']) . '/package.zip';
        $archive = new ZipArchive();
        $assetPath = $root . '/published.zip';
        download_assert(
            $archive->open($assetPath, ZipArchive::CREATE) === true,
            'Could not create private ZIP.',
        );
        $archive->addFromString('app/bootstrap.php', "<?php const CMS_VERSION = '1.1.0';");
        $archive->close();
        $asset = [
            'name' => 'production.zip',
            'browser_download_url' => 'https://github.com/fixture/gallery/releases/download/v_1.1.0/production.zip',
            'size' => filesize($assetPath),
            'digest' => 'sha256:' . hash_file('sha256', $assetPath),
        ];
        $release = ['tag_name' => 'v_1.1.0', 'draft' => false, 'prerelease' => false, 'assets' => [$asset]];
        $api = 'https://api.github.com/repos/fixture/gallery';
        $GLOBALS['download_api'] = [
            $api . '/contents/app/bootstrap.php?ref=' . $commit => "<?php const CMS_VERSION = '1.1.0';",
            $api . '/releases/tags/v_1.1.0' => json_encode($release, JSON_THROW_ON_ERROR),
            $api . '/commits/v_1.1.0' => json_encode(['sha' => $commit], JSON_THROW_ON_ERROR),
        ];
        $probe = $job;
        $probe['checkpoints']['archive_fallback_version'] = '1.1.0';
        $invalidReleases = [];
        $withoutDigest = $release;
        unset($withoutDigest['assets'][0]['digest']);
        $invalidReleases[] = $withoutDigest;
        $wrongUrl = $release;
        $wrongUrl['assets'][0]['browser_download_url'] = 'https://example.invalid/production.zip';
        $invalidReleases[] = $wrongUrl;
        $draft = $release;
        $draft['draft'] = true;
        $invalidReleases[] = $draft;
        $duplicates = $release;
        $duplicates['assets'][] = $asset;
        $invalidReleases[] = $duplicates;
        $prefix = file_get_contents($path);
        foreach ($invalidReleases as $invalidRelease) {
            $GLOBALS['download_api'][$api . '/releases/tags/v_1.1.0'] =
                json_encode($invalidRelease, JSON_THROW_ON_ERROR);
            try {
                application_update_download_select_release($probe, $budget);
                throw new RuntimeException('Unbound release metadata was accepted.');
            } catch (RuntimeException $error) {
                download_assert(
                    $error->getMessage() === 'Matching published update archive is unavailable.',
                    'Invalid release failed at the wrong boundary.',
                );
            }
            download_assert(
                file_get_contents($path) === $prefix,
                'Rejected release discarded source bytes.',
            );
        }
        $GLOBALS['download_api'][$api . '/releases/tags/v_1.1.0'] =
            json_encode($release, JSON_THROW_ON_ERROR);
        foreach (array_keys($GLOBALS['download_api']) as $expectedUrl) {
            $before = count($GLOBALS['download_api_calls']);
            application_update_job_download_slice($job, $budget);
            download_assert(
                count($GLOBALS['download_api_calls']) === $before + 1,
                'Metadata selection exceeded one request per slice.',
            );
            download_assert(
                end($GLOBALS['download_api_calls']) === $expectedUrl,
                'Fallback metadata order changed.',
            );
        }
        clearstatcache(true, $path);
        download_assert(!is_file($path), 'Source prefix survived asset selection.');
        download_assert(
            $job['checkpoints']['archive_commit'] === $commit,
            'Fallback changed the target commit.',
        );
        $payload = file_get_contents($assetPath);
        // The midpoint is a structural split of this synthetic ZIP's bytes.
        $midpoint = intdiv(strlen($payload), 2);
        download_response(200, ['Content-Length: ' . $asset['size']], substr($payload, 0, $midpoint), false);
        download_assert(
            !application_update_job_download_slice($job, $budget),
            'Partial asset completed early.',
        );
        download_response(206, [
            'Content-Length: ' . ($asset['size'] - $midpoint),
            'Content-Range: bytes ' . $midpoint . '-' . ($asset['size'] - 1) . '/' . $asset['size'],
        ], substr($payload, $midpoint));
        download_assert(
            application_update_job_download_slice($job, $budget),
            'Published ZIP failed to resume.',
        );
        download_assert(
            $GLOBALS['download_url'] === $asset['browser_download_url'],
            'Unbound release URL was used.',
        );
        download_assert(
            application_update_job_validate_archive($job),
            'Valid published ZIP was refused.',
        );
        download_assert(
            !empty($job['checkpoints']['archive_release_digest_verified']),
            'Asset digest was not verified.',
        );

        unset($job['checkpoints']['archive_release_digest_verified']);
        file_put_contents($path, str_repeat('X', $asset['size']));
        try {
            application_update_job_validate_archive($job);
            throw new RuntimeException('Corrupt published ZIP was accepted.');
        } catch (RuntimeException $error) {
            download_assert(
                $error->getMessage() === 'Published update archive failed SHA-256 verification.',
                'Digest was not the refusal boundary.',
            );
        }

        $foreign = $GLOBALS['download_fallback_job'];
        $foreign['checkpoints']['archive_fallback_version'] = '1.1.0';
        $foreign['checkpoints']['archive_fallback_release'] = $job['checkpoints']['archive_release_asset'];
        $GLOBALS['download_api'][$api . '/commits/v_1.1.0'] = json_encode(['sha' => str_repeat('b', 40)], JSON_THROW_ON_ERROR);
        $originalPrefix = file_get_contents($path);
        try {
            application_update_download_select_release($foreign, $budget);
            throw new RuntimeException('Different release commit was accepted.');
        } catch (RuntimeException $error) {
            download_assert(
                $error->getMessage() === 'Published update archive belongs to a different commit.',
                'Wrong-commit refusal changed.',
            );
        }
        download_assert(
            file_get_contents($path) === $originalPrefix,
            'Unverified fallback deleted original evidence.',
        );

        $job['status'] = 'failed';
        $job['error'] = ['message' => 'Fixture transport failure', 'reference' => 'fixture', 'retryable' => true];
        application_update_save_job($job);
        application_update_retry_job($job['id']);
        $retried = application_update_load_job($job['id']);
        download_assert(
            $retried['checkpoints']['archive_commit'] === $commit,
            'Retry changed pinned commit.',
        );
        foreach (['archive_fallback_pending', 'archive_fallback_version', 'archive_fallback_release',
            'archive_release_asset', 'archive_release_digest_verified', 'archive_resume_restarts'] as $key) {
            download_assert(
                !array_key_exists($key, $retried['checkpoints']),
                'Retry retained untrusted archive selection.',
            );
        }

        mkdir($root . '/flat/app', \Gallery\EarlyRuntime\UPDATER_RUNTIME_DIRECTORY_MODE, true);
        file_put_contents($root . '/flat/app/bootstrap.php', '<?php');
        download_assert(
            application_update_extracted_root($root . '/flat') === $root . '/flat',
            'Flat production ZIP root was lost.',
        );
        mkdir($root . '/wrapped/repository/app', \Gallery\EarlyRuntime\UPDATER_RUNTIME_DIRECTORY_MODE, true);
        download_assert(
            application_update_extracted_root($root . '/wrapped') === $root . '/wrapped/repository',
            'Historical source ZIP root was lost.',
        );
        echo "Updater download transport tests passed.\n";
    } finally {
        application_update_remove_path($root);
    }
}
