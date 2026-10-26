<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/autoupdate_background_http_test.php
 * Module Type: Regression Test
 * Purpose: Prove a single anonymous HTTP visit launches real updater successors.
 * Responsibilities: Exercise production shutdown, private transport, controller
 *   authentication, actual multi-hop arrival and final real cleanup completion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

/**
 * Require one real HTTP or durable-worker observation.
 * @param bool $condition Expected production-owner behavior.
 * @param string $message Failure description with only fixture-authored data.
 * @return void Throws when the observation is missing.
 */
function update_chain_http_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (getenv('GITHUB_ACTIONS') !== 'true' || DIRECTORY_SEPARATOR === '\\') {
    echo
        "SKIP hosted multi-worker updater HTTP fixture requires Linux PHP workers.\n";
    exit(0);
}

/**
 * Bound complete real-worker observation including server startup.
 * Type: int. Units: seconds. Scope: this disposable hosted server only.
 * Consumers: startup/completion polling and HTTP request timeout.
 * Rationale: twenty seconds exceeds the few expected cleanup hops without
 *   borrowing production limits or permitting an indefinitely stuck test.
 */
const UPDATE_CHAIN_HTTP_DEADLINE_SECONDS = 20;

/**
 * Avoid busy polling while observing independent server processes.
 * Type: int. Units: microseconds. Scope: this hosted fixture's passive polling.
 * Consumers: startup and durable completion loops.
 * Rationale: fifty milliseconds permits prompt evidence with bounded CPU use.
 */
const UPDATE_CHAIN_HTTP_POLL_US = 50_000;

$root = sys_get_temp_dir() . '/gallery-update-http-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
file_put_contents($root . '/owned-fixture', 'isolated updater HTTP test');
// Type: string. Units: absolute path. Scope: this parent fixture process.
// Consumers: real updater persistence and passive worker-state observation.
// Rationale: the random owned root shared with child requests confines all
// writes and final cleanup to disposable test data.
define('CACHE_LIFECYCLE_TEST_ROOT', $root);
require_once __DIR__ . '/../app/services/updates.php';
$socket = stream_socket_server(
    'tcp://127.0.0.1:0',
    $socketError,
    $socketMessage,
);
update_chain_http_assert(
    is_resource($socket),
    'Could not reserve a disposable HTTP port.',
);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$environment = getenv();
$environment['GALLERY_UPDATE_CHAIN_FIXTURE_ROOT'] = $root;
$environment['GALLERY_UPDATE_CHAIN_FIXTURE_URL'] = $base;
// Type: string. Units: PHP workers. Scope: this owned fixture server.
// Consumers: PHP built-in server. Rationale: two workers exercise asynchronous
// handoff safely without the prohibited single-worker loopback fallback.
$environment['PHP_CLI_SERVER_WORKERS'] = '2';
$process = null;
try {
    $jobId = '20261019000000-abcdef123456';
    $job = [
        'id' => $jobId,
        'operation' => 'stable_update',
        'trigger' => 'background',
        'status' => 'running',
        'stage' => 'cleanup',
        'attempts' => 0,
        'parameters' => [],
        'checkpoints' => [],
        'result' => [],
        'progress' => [],
    ];
    \Gallery\Services\application_update_save_job($job);
    \Gallery\Services\application_update_set_active_job($jobId);
    $trash = \Gallery\Services\application_update_prune_trash_root($root);
    \Gallery\Services\application_update_ensure_dir($trash);
    // Type: int. Units: files. Scope: this private cleanup fixture.
    // Consumers: production 180-entry cleanup slices. Rationale: 720 payloads
    // require several real worker requests after exactly one successful visit.
    $payloadCount = 720;
    for ($index = 0; $index < $payloadCount; $index++) {
        file_put_contents($trash . '/payload-' . $index, 'fixture');
    }
    $process = proc_open(
        [
            PHP_BINARY,
            '-S',
            $address,
            __DIR__ . '/fixtures/autoupdate_background_router.php',
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
    update_chain_http_assert(
        is_resource($process),
        'Could not start the owned HTTP server.',
    );
    fclose($pipes[0]);
    $deadline = microtime(true) + UPDATE_CHAIN_HTTP_DEADLINE_SECONDS;
    $serverReady = false;
    do {
        $ready = @stream_socket_client(
            'tcp://' . $address,
            $error,
            $message,
            UPDATE_CHAIN_HTTP_DEADLINE_SECONDS,
        );
        if (is_resource($ready)) {
            fclose($ready);
            $serverReady = true;
            break;
        }
        usleep(UPDATE_CHAIN_HTTP_POLL_US);
    } while (microtime(true) < $deadline);
    update_chain_http_assert(
        $serverReady,
        'The owned HTTP server never became ready.',
    );
    $context = stream_context_create(['http' => [
        'ignore_errors' => true,
        'timeout' => UPDATE_CHAIN_HTTP_DEADLINE_SECONDS,
    ]]);
    file_get_contents($base . '/blocked', false, $context);
    update_chain_http_assert(
        !is_file($root . '/cache/updates/background-chain.json'),
        'An HTTP 503 visitor response started an update chain.',
    );
    file_get_contents(
        $base . '/index.php?page=site_maintenance_cron',
        false,
        $context,
    );
    update_chain_http_assert(
        str_contains($http_response_header[0] ?? '', '403')
            && \Gallery\Services\application_update_load_job($jobId)['attempts']
                === 0,
        'An unauthenticated maintenance request advanced the updater.',
    );
    // No cookie, JavaScript, Admin page or follow-up visitor request participates.
    file_get_contents($base . '/visit', false, $context);
    $gatedBody = file_get_contents($base . '/after-gate', false, $context);
    update_chain_http_assert(
        str_contains($http_response_header[0] ?? '', '503')
            && str_contains((string) $gatedBody, 'Service temporarily unavailable'),
        'The ordinary follow-up did not encounter the real activation gate.',
    );
    do {
        $completed = \Gallery\Services\application_update_load_job($jobId);
        if (
            $completed['status'] === 'completed'
            || $completed['status'] === 'failed'
        ) {
            break;
        }
        usleep(UPDATE_CHAIN_HTTP_POLL_US);
    } while (microtime(true) < $deadline);
    $arrivals = file($root . '/arrivals.log', FILE_IGNORE_NEW_LINES);
    update_chain_http_assert(
        $completed['status'] === 'completed'
            && $completed['attempts'] > 1,
        'A closed-gate runtime wait lost its independent HTTP successors: '
            . json_encode($completed)
            . '\nOwned server: '
            . file_get_contents($root . '/server.log'),
    );
    // One rejected visitor plus exactly one admitted visitor; worker counts
    // include the deliberate forbidden request as well as actual continuations.
    update_chain_http_assert(
        count(array_filter(
            $arrivals,
            static fn(string $kind): bool => $kind === 'visit',
        )) === 2
            && count(array_filter(
                $arrivals,
                static fn(string $kind): bool => $kind === 'worker',
            )) >= 3,
        'Completion needed another visitor or had no real HTTP successors.',
    );
    echo
        "One anonymous visit, actual authenticated HTTP successors and real updater completion: PASS\n";
} finally {
    if (is_resource($process)) {
        require_once __DIR__ . '/../scripts/audit_process.php';
        $server = proc_get_status($process);
        \PhpGallery\Audit\terminate_process([
            'process' => $process,
            'pid' => $server['pid'],
            'group' => false,
        ]);
        proc_close($process);
    }
    while (is_dir($root)) {
        \Gallery\Services\application_update_delete_tree_slice($root);
    }
}
