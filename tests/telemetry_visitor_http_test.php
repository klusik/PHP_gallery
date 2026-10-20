<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/telemetry_visitor_http_test.php
 * Module Type: HTTP Regression Test
 * Purpose: Verify ordinary visits dispatch private telemetry without Admin or cron.
 * Responsibilities:
 *   - Exercise real registration, socket transport and controller authentication.
 *   - Verify throttling, opt-out, route restrictions and strict central ownership.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/**
 * Pace fixture startup and asynchronous receipt observation.
 * Type: integer. Units: microseconds. Scope: this isolated HTTP test.
 * Consumers: bounded startup and receipt loops.
 * Rationale: 25000 microseconds avoids busy polling while allowing quick readiness.
 */
const TELEMETRY_VISITOR_POLL_US = 25000;

/**
 * Bound child startup and asynchronous receipt admission.
 * Type: integer. Units: attempts. Scope: each fixture observation loop.
 * Consumers: server startup and worker receipt checks.
 * Rationale: 120 attempts allow three seconds of polling without unbounded waits.
 */
const TELEMETRY_VISITOR_POLL_ATTEMPTS = 120;

/**
 * Bound HTTP fixture reads independently of the production worker budget.
 * Type: integer. Units: seconds. Scope: each loopback test request.
 * Consumers: stream HTTP context.
 * Rationale: five seconds admits server startup overhead while failing hung tests.
 */
const TELEMETRY_VISITOR_HTTP_TIMEOUT = 5;

/**
 * Require one observed visitor/worker contract.
 * @param bool $condition Whether the required observation holds.
 * @param string $message Safe failure explanation.
 * @return void Throws when the contract is not met.
 */
function telemetry_visitor_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Send one bounded fixture request and return its visible response.
 * @param string $url Loopback fixture URL.
 * @param string $method GET, HEAD or POST selected by this test.
 * @param array<string,string> $body Form fields encoded for POST.
 * @param bool $authenticated Whether to attach the private fixture token header.
 * @return array{status:int,body:string} Actual response status and body.
 */
function telemetry_visitor_request(
    string $url,
    string $method = 'GET',
    array $body = [],
    bool $authenticated = false,
): array {
    $headers = "Content-Type: application/x-www-form-urlencoded\r\n";
    if ($authenticated) {
        $headers .= 'X-Site-Maintenance-Token: ' . str_repeat('a', 64) . "\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => http_build_query($body),
        'ignore_errors' => true,
        'timeout' => TELEMETRY_VISITOR_HTTP_TIMEOUT,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/^HTTP\/\S+ (\d+)/', $statusLine, $matches);
    return [
        'status' => (int) ($matches[1] ?? 0),
        'body' => is_string($response) ? $response : '',
    ];
}

/**
 * Count confirmed fixture worker arrivals without inventing deletion evidence.
 * @param string $root Marker-owned fixture directory.
 * @return int Number of successfully admitted stub worker slices.
 */
function telemetry_visitor_receipts(string $root): int
{
    return is_file($root . '/receipts')
        ? count(file($root . '/receipts', FILE_IGNORE_NEW_LINES) ?: [])
        : 0;
}

$root =
    sys_get_temp_dir()
    . '/gallery-telemetry-visitor-'
    . bin2hex(random_bytes(8));
mkdir($root);
file_put_contents($root . '/.telemetry-fixture-owner', 'owned');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
telemetry_visitor_assert(
    is_resource($socket),
    'Could not reserve a loopback fixture port.',
);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$environment = getenv();
$environment['TELEMETRY_VISITOR_ROOT'] = $root;
$environment['TELEMETRY_VISITOR_BASE'] = $base;
// One worker proves that handoff never waits for the queued maintenance response.
// Type: string. Units: workers. Scope: isolated PHP server.
// Consumers: PHP built-in server. Rationale: a single worker exposes self-request deadlocks.
$environment['PHP_CLI_SERVER_WORKERS'] = '1';
$process = proc_open(
    [
        PHP_BINARY,
        '-S',
        $address,
        __DIR__ . '/fixtures/telemetry_visitor_router.php',
    ],
    [
        0 => ['pipe', 'r'],
        1 => ['file', $root . '/server.log', 'a'],
        2 => ['file', $root . '/server.log', 'a'],
    ],
    $pipes,
    $root,
    $environment,
);
try {
    telemetry_visitor_assert(
        is_resource($process),
        'Fixture server did not start.',
    );
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < TELEMETRY_VISITOR_POLL_ATTEMPTS; $attempt++) {
        if (telemetry_visitor_request($base . '/?ready=1')['status'] === 200) {
            break;
        }
        usleep(TELEMETRY_VISITOR_POLL_US);
    }
    $workerUrl = $base . '/index.php?page=site_maintenance_cron';
    telemetry_visitor_assert(telemetry_visitor_request($workerUrl, 'POST', [
        'telemetry_only' => '1',
    ])['status'] === 403, 'Unauthenticated telemetry transport must be forbidden.');
    telemetry_visitor_assert(
        telemetry_visitor_request(
            $workerUrl . '&telemetry_only=1',
            'GET',
            [],
            true,
        )['status'] === 400,
        'Authenticated GET cannot start a telemetry worker.',
    );
    telemetry_visitor_assert(
        telemetry_visitor_request(
            $workerUrl,
            'POST',
            ['telemetry_only' => '1', 'cache_only' => '1'],
            true,
        )['status'] === 400,
        'Mixed worker payloads must be refused.',
    );
    foreach (['/?route=media', '/?preview=visual', '/?denied=1'] as $path) {
        telemetry_visitor_request($base . $path);
    }
    telemetry_visitor_request($base . '/', 'HEAD');
    telemetry_visitor_request($base . '/', 'POST');
    telemetry_visitor_assert(
        telemetry_visitor_receipts($root) === 0,
        'Media, preview, unsuccessful, HEAD and POST requests cannot schedule telemetry.',
    );
    $public = telemetry_visitor_request($base . '/');
    telemetry_visitor_assert(
        $public['status'] === 200
            && $public['body'] === '<main>Public gallery fixture</main>'
            && !str_contains($public['body'], str_repeat('a', 64)),
        'Ordinary public HTML must be preserved without maintenance credentials.',
    );
    for ($attempt = 0; $attempt < TELEMETRY_VISITOR_POLL_ATTEMPTS; $attempt++) {
        if (telemetry_visitor_receipts($root) === 1) {
            break;
        }
        usleep(TELEMETRY_VISITOR_POLL_US);
    }
    telemetry_visitor_assert(
        telemetry_visitor_receipts($root) === 1,
        'An ordinary visit must dispatch one real authenticated server request.',
    );
    telemetry_visitor_request($base . '/?route=gallery');
    telemetry_visitor_assert(
        telemetry_visitor_receipts($root) === 1,
        'Repeated visitors inside the shared interval must not fan out workers.',
    );
    $marker = $root . '/cache/site-maintenance/telemetry-trigger.touch';
    $defaults = require dirname(__DIR__) . '/app/configuration_defaults.php';
    $interval =
        (int) $defaults['runtime_limits']['telemetry.maintenance_retry_seconds'];
    touch($marker, time() - $interval);
    telemetry_visitor_request($base . '/?route=smart_gallery');
    for ($attempt = 0; $attempt < TELEMETRY_VISITOR_POLL_ATTEMPTS; $attempt++) {
        if (telemetry_visitor_receipts($root) === 2) {
            break;
        }
        usleep(TELEMETRY_VISITOR_POLL_US);
    }
    telemetry_visitor_assert(
        telemetry_visitor_receipts($root) === 2,
        'A later public visit must resume after the shared interval expires.',
    );
    foreach (['off', 'master-off', 'central', 'unknown'] as $condition) {
        file_put_contents(
            $root . '/' . $condition,
            'enabled fixture condition',
        );
        $response = telemetry_visitor_request(
            $workerUrl,
            'POST',
            ['telemetry_only' => '1'],
            true,
        );
        telemetry_visitor_assert(
            $response['status'] === ($condition === 'unknown' ? 500 : 200)
                && telemetry_visitor_receipts($root) === 2,
            'Worker must respect opt-out and known/unknown central ownership.',
        );
        unlink($root . '/' . $condition);
    }
    echo "telemetry_visitor_http_test: ok\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    // Verify the absolute owned target before recursively removing fixture data.
    $resolved = realpath($root);
    telemetry_visitor_assert(
        is_string($resolved)
            && str_starts_with(
                $resolved,
                realpath(sys_get_temp_dir())
                    . DIRECTORY_SEPARATOR
                    . 'gallery-telemetry-visitor-',
            )
            && is_file($resolved . '/.telemetry-fixture-owner'),
        'Fixture cleanup escaped its owned root.',
    );
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $resolved,
            FilesystemIterator::SKIP_DOTS,
        ),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($resolved);
}
