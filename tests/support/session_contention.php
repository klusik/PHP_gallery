<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/session_contention.php
 * Module Type: Test Fixture
 * Purpose: Provide disposable session test transport and timing helpers.
 * Responsibilities:
 *   - Coordinate owned workers and normalize contention observations.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Shared ownership, transport and timing helpers for disposable session tests.
 */
declare(strict_types=1);

namespace Gallery\Tests\SessionContention;

/**
 * Observe simultaneous readers for at least 900 milliseconds.
 * Type: int. Units: milliseconds. Scope: disposable session-contention tests.
 * Consumers: holder and reader comparison fixtures.
 * Rationale: expose serialization without defining a production latency SLA.
 */
const OBSERVATION_MILLISECONDS = 900;

/**
 * Bound each worker readiness/reader barrier to five seconds.
 * Type: int. Units: seconds. Scope: disposable session-contention tests.
 * Consumers: server readiness polling, reader barriers and cURL connection.
 * Rationale: tolerate slow CI startup while refusing unbounded hangs.
 */
const READY_TIMEOUT_SECONDS = 5;

/**
 * Self-release the holder after 15 seconds even if its parent fails.
 * Type: int. Units: seconds. Scope: disposable session-contention tests.
 * Consumers: owned holder worker deadline.
 * Rationale: cover two five-second readiness phases plus observation margin.
 */
const HOLD_TIMEOUT_SECONDS = 15;

/**
 * Bound each fixture HTTP operation to 20 seconds.
 * Type: int. Units: seconds. Scope: disposable session-contention tests.
 * Consumers: cURL transport and asynchronous reader deadlines.
 * Rationale: exceed the 15-second holder self-release but keep failure bounded.
 */
const HTTP_TIMEOUT_SECONDS = 20;

/**
 * Collect three repetitions for each session-contention comparison.
 * Type: int. Units: samples. Scope: disposable session-contention tests.
 * Consumers: baseline and held-session sample loops.
 * Rationale: provide repeated evidence while keeping fixture runtime bounded.
 */
const SAMPLE_COUNT = 3;

/**
 * Allocate three independent PHP servers: one holder and two readers.
 * Type: int. Units: workers. Scope: disposable session-contention tests.
 * Consumers: server allocation and worker-index validation.
 * Rationale: prevent development-server queuing from mimicking session locking.
 */
const WORKER_COUNT = 3;

/**
 * Pause readiness and barrier polling for 10000 microseconds per iteration.
 * Type: int. Units: microseconds. Scope: disposable session-contention tests.
 * Consumers: readiness and barrier usleep loops.
 * Rationale: avoid a busy wait while retaining responsive fixture coordination.
 */
const POLL_MICROSECONDS = 10000;

/**
 * Convert seconds to milliseconds with the exact factor 1000.
 * Type: int. Units: milliseconds per second. Scope: session-contention tests.
 * Consumers: HTTP and session-start measurement normalization.
 * Rationale: report comparable units without changing measured timings.
 */
const MILLISECONDS_PER_SECOND = 1000;

/**
 * Delay one reader by the observation window plus 250 milliseconds (1150 total).
 * Type: int. Units: milliseconds. Scope: session barrier regression fixture.
 * Consumers: deliberately delayed reader-start scenario.
 * Rationale: prove the barrier waits for real reader starts before observation.
 */
const DELAYED_READER_MILLISECONDS = OBSERVATION_MILLISECONDS + 250;

/** Bounded fixture assertion with a static, non-sensitive message safe for test failure output. */
final class FixtureAssertionFailure extends \RuntimeException {}

/**
 * Refuse a fixture invariant without printing request bodies or credentials.
 *
 * @param bool $condition Whether the invariant holds.
 * @param string $message Static, non-sensitive assertion description.
 * @return void
 */
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new FixtureAssertionFailure($message);
    }
}

/**
 * Resolve a private direct child of the system temporary directory by its marker.
 *
 * @param string $directory Candidate fixture directory, never an application root.
 * @param string $token Random hexadecimal owner identity retained only in memory/files.
 * @return string Canonical owned directory; throws on links, broad roots or mismatch.
 */
function owned_directory(string $directory, string $token): string
{
    check(
        preg_match('/\A[a-f0-9]{32}\z/', $token) === 1,
        'Invalid session fixture owner.',
    );
    $resolved = realpath($directory);
    $temporary = realpath(sys_get_temp_dir());
    check(
        is_string($resolved)
            && is_string($temporary)
            && !is_link($directory)
            && strcasecmp(dirname($resolved), $temporary) === 0
            && basename($resolved) === 'gallery-session-' . $token,
        'Invalid session fixture directory.',
    );
    $marker = $resolved . '/.session-owner';
    check(
        is_file($marker)
            && !is_link($marker)
            && hash_equals($token, (string) file_get_contents($marker)),
        'Session fixture ownership mismatch.',
    );
    return $resolved;
}

/**
 * Remove only this test's verified temporary tree after its servers have stopped.
 *
 * @param string $directory Generated temporary fixture root.
 * @param string $token Matching private owner identity.
 * @return void Links are unlinked, never traversed; failed cleanup throws.
 */
function remove_directory(string $directory, string $token): void
{
    $directory = owned_directory($directory, $token);
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(
            $directory,
            \FilesystemIterator::SKIP_DOTS,
        ),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if (
            $entry->getFilename() === '.session-owner'
            && $entry->getPath() === $directory
        ) {
            continue;
        }
        check(
            $entry->isLink() || !$entry->isDir()
                ? unlink($entry->getPathname())
                : rmdir($entry->getPathname()),
            'Session fixture cleanup failed.',
        );
    }
    check(
        unlink($directory . '/.session-owner') && rmdir($directory),
        'Session fixture owner cleanup failed.',
    );
}

/**
 * Start a separate PHP worker so application queuing cannot mimic session locking.
 *
 * @param string $directory Verified private directory with session storage.
 * @param string $token Owner identity passed in the child environment, never a URL.
 * @param int $index Allowlisted worker number for its private log filename.
 * @return array{process:resource,origin:string} Caller must terminate and close process.
 */
function start_server(string $directory, string $token, int $index): array
{
    $directory = owned_directory($directory, $token);
    check(
        $index >= 0 && $index < WORKER_COUNT,
        'Invalid session fixture worker.',
    );
    $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    check(is_resource($listener), 'Session fixture port reservation failed.');
    $address = (string) stream_socket_get_name($listener, false);
    fclose($listener);
    $origin = 'http://' . $address;
    $environment = array_merge(getenv(), [
        'GALLERY_SESSION_FIXTURE' => $directory,
        'GALLERY_SESSION_OWNER' => $token,
    ]);
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            'session.save_handler=files',
            '-d',
            'session.save_path=' . $directory . '/sessions',
            '-d',
            'session.gc_probability=0',
            '-d',
            'session.use_strict_mode=1',
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=1',
            '-d',
            'register_argc_argv=0',
            '-S',
            $address,
            '-t',
            $directory,
            dirname(__DIR__) . '/fixtures/session_contention_router.php',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $directory . '/worker-' . $index . '.log', 'a'],
            2 => ['file', $directory . '/worker-' . $index . '.log', 'a'],
        ],
        $pipes,
        $directory,
        $environment,
        ['bypass_shell' => true],
    );
    check(is_resource($process), 'Session fixture server launch failed.');
    fclose($pipes[0]);
    try {
        $deadline = microtime(true) + READY_TIMEOUT_SECONDS;
        do {
            $probe = request_handle($origin, '/health', $token);
            $body = curl_exec($probe);
            $ready =
                is_string($body)
                && hash_equals($token, $body)
                && curl_getinfo($probe, CURLINFO_RESPONSE_CODE) === 200;
            unset($probe);
            if ($ready) {
                return ['process' => $process, 'origin' => $origin];
            }
            usleep(POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Session fixture server readiness failed.');
    } catch (\Throwable $exception) {
        proc_terminate($process);
        proc_close($process);
        throw $exception;
    }
}

/**
 * Create a cookie-isolated loopback request carrying only the fixture authority.
 *
 * @param string $origin Validated literal loopback origin with ephemeral port.
 * @param string $path Fixed fixture route and optionally bounded scenario query.
 * @param string $token Owner identity used only in a private HTTP header.
 * @param list<string> $cookies Private cURL cookie records from a fixture session.
 * @return \CurlHandle Configured handle; caller owns transport and disposal.
 */
function request_handle(
    string $origin,
    string $path,
    string $token,
    array $cookies = [],
): \CurlHandle {
    check(
        preg_match('~\Ahttp://127\.0\.0\.1:[1-9][0-9]{3,4}\z~', $origin) === 1,
        'Invalid session fixture origin.',
    );
    check(
        preg_match(
            '~\A/(?:health|login|state|suggest(?:\?scenario=(?:held|released)-[0-2]&reader=(?:same|independent))?|hold\?scenario=(?:held|released)-[0-2])\z~',
            $path,
        ) === 1,
        'Invalid session fixture route.',
    );
    $handle = curl_init();
    curl_setopt_array($handle, [
        CURLOPT_URL => $origin . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_HTTPHEADER => ['X-Fixture-Owner: ' . $token],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        CURLOPT_CONNECTTIMEOUT => READY_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT_SECONDS,
    ]);
    // Health readiness uses its literal identity body, without response headers.
    if ($path === '/health') {
        curl_setopt($handle, CURLOPT_HEADER, false);
    }
    if ($path === '/login' || str_starts_with($path, '/hold')) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, '');
    }
    foreach ($cookies as $cookie) {
        curl_setopt($handle, CURLOPT_COOKIELIST, $cookie);
    }
    return $handle;
}

/**
 * Extract only bounded metrics and JSON while retaining sensitive transport privately.
 *
 * @param \CurlHandle $handle Completed HTTP transfer.
 * @param string $response Raw headers/body, never forwarded to test output.
 * @return array{status:int,total_ms:float,first_byte_ms:float,session_start_ms:float,handler:string,json:array<string,mixed>}
 *   Timing is milliseconds; session_start includes lock, storage I/O and decode.
 */
function response_metrics(\CurlHandle $handle, string $response): array
{
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    preg_match('/^X-Fixture-Session-Ms: ([0-9.]+)\r?$/mi', $headers, $timing);
    preg_match('/^X-Fixture-Handler: (files)\r?$/mi', $headers, $handler);
    check(
        isset($timing[1], $handler[1]),
        'Session measurement headers missing.',
    );
    $json = json_decode(
        substr($response, $headerSize),
        true,
        32,
        JSON_THROW_ON_ERROR,
    );
    check(is_array($json), 'Session fixture JSON response missing.');
    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'total_ms' => round(
            curl_getinfo($handle, CURLINFO_TOTAL_TIME)
                * MILLISECONDS_PER_SECOND,
            3,
        ),
        'first_byte_ms' => round(
            curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME)
                * MILLISECONDS_PER_SECOND,
            3,
        ),
        'session_start_ms' => (float) $timing[1],
        'handler' => $handler[1],
        'json' => $json,
    ];
}

/**
 * Progress all concurrent HTTP requests and retain their completed safe measurements.
 *
 * @param \CurlMultiHandle $multi Active concurrent transport owner.
 * @param array<string,\CurlHandle> $handles Requests indexed by fixed role.
 * @param array<string,array<string,mixed>> $completed Results updated as requests finish.
 * @return void Nonzero transfer results fail without echoing cURL errors.
 */
function pump(\CurlMultiHandle $multi, array $handles, array &$completed): void
{
    check(
        curl_multi_exec($multi, $running) === CURLM_OK,
        'Concurrent session transport failed.',
    );
    while (($info = curl_multi_info_read($multi)) !== false) {
        check(
            $info['result'] === CURLE_OK,
            'Session HTTP request did not complete.',
        );
        $role = array_search($info['handle'], $handles, true);
        check(is_string($role), 'Unexpected session HTTP completion.');
        $completed[$role] = response_metrics(
            $info['handle'],
            (string) curl_multi_getcontent($info['handle']),
        );
    }
    // Bounded wait prevents a busy loop while keeping cross-worker requests active.
    if (curl_multi_select($multi, 0.01) === -1) {
        usleep(1000);
    }
}
