<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/activation_maintenance_response_test.php
 * Module Type: Regression Test
 * Purpose: Exercise the early activation response through real HTTP requests.
 * Responsibilities: Preserve the closed gate, retry/cache headers and machine
 *   replies while presenting browser maintenance without replaying POST data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/early_runtime.php';

/**
 * Bound startup and each request to the disposable early-runtime server.
 * Type: int. Units: seconds. Scope: this fixture's own HTTP server only.
 * Consumers: startup polling and maintenance_http_response().
 * Rationale: 10 seconds accommodates hosted startup without hiding a deadlock.
 */
const MAINTENANCE_HTTP_TIMEOUT_SECONDS = 10;

/**
 * Yield while waiting for the owned server to accept connections.
 * Type: int. Units: microseconds. Scope: fixture startup polling only.
 * Consumers: the bounded startup loop.
 * Rationale: 20000 avoids busy polling while keeping startup responsive.
 */
const MAINTENANCE_HTTP_STARTUP_POLL_US = 20_000;

/**
 * Require an observed real response or owned filesystem postcondition.
 * @param bool $condition Expected public response or durable state.
 * @param string $message Failure explanation using fixture-authored values.
 * @return void Throws when the observation does not hold.
 */
function maintenance_response_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Read one actual early-runtime HTTP response without following redirects.
 * @param string $url Owned loopback server URL.
 * @param string $method HTTP method to exercise, including HEAD and POST.
 * @param string $accept Requested response representation.
 * @return array{body:string,headers:list<string>} Body and lowercase wire headers.
 */
function maintenance_http_response(
    string $url,
    string $method,
    string $accept,
): array {
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => 'Accept: ' . $accept . "\r\n",
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => MAINTENANCE_HTTP_TIMEOUT_SECONDS,
    ]]);
    $body = file_get_contents($url, false, $context);
    return [
        'body' => (string) $body,
        'headers' => array_map('strtolower', $http_response_header ?? []),
    ];
}

$root = sys_get_temp_dir() . '/gallery-maintenance-' . bin2hex(random_bytes(8));
$jobId = '20261026000000-abcdef123456';
$updates = $root . '/cache/updates';
// Purpose: confine disposable gate state to the executing account.
// Type: int. Units: POSIX permission bits. Scope: this fixture's random root.
// Consumers: mkdir. Rationale: 0700 keeps the owned state private on POSIX.
$directoryMode = 0700;
mkdir($updates . '/jobs/' . $jobId, $directoryMode, true);
$job = ['id' => $jobId, 'status' => 'running', 'checkpoints' => []];
$marker = ['job_id' => $jobId, 'recovery_isolation' => true];
file_put_contents($updates . '/jobs/' . $jobId . '/job.json',
    json_encode($job, JSON_THROW_ON_ERROR));
file_put_contents($updates . '/activation.json',
    json_encode($marker, JSON_THROW_ON_ERROR));
$reservation = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
maintenance_response_assert(is_resource($reservation),
    'Could not reserve a disposable maintenance HTTP port.');
$address = stream_socket_get_name($reservation, false);
fclose($reservation);
$environment = getenv();
$environment['PHP_GALLERY_TEST_PROJECT_ROOT'] = $root;
$process = null;
try {
    $process = proc_open([
        PHP_BINARY, '-S', $address,
        __DIR__ . '/fixtures/early_runtime_probe.php',
    ], [
        0 => ['pipe', 'r'],
        1 => ['file', $root . '/server.log', 'a'],
        2 => ['file', $root . '/server.log', 'a'],
    ], $pipes, $root, $environment);
    maintenance_response_assert(is_resource($process),
        'Could not start the owned maintenance HTTP server.');
    fclose($pipes[0]);
    $deadline = microtime(true) + MAINTENANCE_HTTP_TIMEOUT_SECONDS;
    $ready = false;
    do {
        $connection = @stream_socket_client('tcp://' . $address,
            $error, $message, MAINTENANCE_HTTP_TIMEOUT_SECONDS);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(MAINTENANCE_HTTP_STARTUP_POLL_US);
    } while (microtime(true) < $deadline);
    maintenance_response_assert($ready, 'Owned maintenance server did not start.');

    $url = 'http://' . $address . '/?untrusted=%3Cscript%3Ebad%3C/script%3E';
    $browser = maintenance_http_response($url, 'GET', 'text/html');
    $retry = (string) \Gallery\EarlyRuntime\ACTIVATION_RETRY_AFTER_SECONDS;
    maintenance_response_assert(
        str_contains($browser['headers'][0] ?? '', '503')
            && in_array('content-type: text/html; charset=utf-8',
                $browser['headers'], true)
            && in_array('retry-after: ' . $retry, $browser['headers'], true)
            && in_array('cache-control: no-store, private, max-age=0',
                $browser['headers'], true)
            && in_array('x-robots-tag: noindex, nofollow',
                $browser['headers'], true)
            && in_array('x-content-type-options: nosniff',
                $browser['headers'], true)
            && in_array("content-security-policy: default-src 'none'; "
                . "style-src 'unsafe-inline'; base-uri 'none'; "
                . "form-action 'none'; frame-ancestors 'none'",
                $browser['headers'], true)
            && str_contains($browser['body'], '<main class="maintenance">')
            && str_contains($browser['body'], 'The gallery is being updated.')
            && str_contains($browser['body'],
                '<meta http-equiv="refresh" content="' . $retry . '">')
            && str_contains($browser['body'], '<a href="">Try again</a>')
            && !str_contains($browser['body'], '<script')
            && !str_contains($browser['body'], $root)
            && !str_contains($browser['body'], $jobId),
        'Browser maintenance lost its safe HTML, HTTP headers or local retry.',
    );
    foreach ([['GET', 'application/json'], ['GET', 'image/webp'],
        ['POST', 'text/html']] as [$method, $accept]) {
        $machine = maintenance_http_response($url, $method, $accept);
        maintenance_response_assert(
            str_contains($machine['headers'][0] ?? '', '503')
                && in_array('content-type: text/plain; charset=utf-8',
                    $machine['headers'], true)
                && $machine['body'] === "Service temporarily unavailable.\n"
                && !str_contains(implode("\n", $machine['headers']), 'refresh:'),
            'A machine or POST request received HTML, refresh or changed status.',
        );
    }
    $head = maintenance_http_response($url, 'HEAD', 'text/html');
    maintenance_response_assert(str_contains($head['headers'][0] ?? '', '503')
        && $head['body'] === '', 'HEAD emitted a maintenance document body.');
    maintenance_response_assert(
        !is_file($updates . '/runtime-use.lock')
            && is_file($updates . '/activation.json')
            && json_decode(file_get_contents($updates . '/jobs/' . $jobId
                . '/job.json'), true, flags: JSON_THROW_ON_ERROR) === $job,
        'Presentation admitted a runtime reader or changed the active job.',
    );
    unlink($updates . '/activation.json');
    $finished = maintenance_http_response($url, 'GET', 'text/html');
    maintenance_response_assert(
        str_contains($finished['headers'][0] ?? '', '200')
            && $finished['body'] === "OK\n",
        'The same URL did not return normally after maintenance ended.',
    );
    echo "Activation maintenance HTML, wire headers and non-replaying replies: PASS\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    // Remove only known files beneath the random fixture-owned installation.
    foreach ([$updates . '/activation.json', $updates . '/runtime-use.lock',
        $updates . '/jobs/' . $jobId . '/job.json', $root . '/server.log'] as $file) {
        if (is_file($file)) { unlink($file); }
    }
    foreach ([$updates . '/jobs/' . $jobId, $updates . '/jobs', $updates,
        $root . '/cache', $root] as $directory) {
        if (is_dir($directory)) { rmdir($directory); }
    }
}
