<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/session_contention_test.php
 * Module Type: Regression Test
 * Purpose: Measure real PHP files-handler lock contention.
 * Responsibilities:
 *   - Coordinate three HTTP workers against temporary sessions and in-memory SQLite.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Measure real files-handler contention through three concurrent HTTP workers.
 * Only a fresh temporary session directory and in-memory SQLite are used.
 */
declare(strict_types=1);

require_once __DIR__ . '/support/session_contention.php';

use function Gallery\Tests\SessionContention\check;
use function Gallery\Tests\SessionContention\owned_directory;
use function Gallery\Tests\SessionContention\pump;
use function Gallery\Tests\SessionContention\remove_directory;
use function Gallery\Tests\SessionContention\request_handle;
use function Gallery\Tests\SessionContention\response_metrics;
use function Gallery\Tests\SessionContention\start_server;
use const Gallery\Tests\SessionContention\HTTP_TIMEOUT_SECONDS;
use const Gallery\Tests\SessionContention\MILLISECONDS_PER_SECOND;
use const Gallery\Tests\SessionContention\OBSERVATION_MILLISECONDS;
use const Gallery\Tests\SessionContention\READY_TIMEOUT_SECONDS;
use const Gallery\Tests\SessionContention\SAMPLE_COUNT;
use const Gallery\Tests\SessionContention\WORKER_COUNT;

/**
 * Compare same-session and independent-session suggestions behind one bounded holder.
 *
 * @param list<array{process:resource,origin:string}> $servers Three owned PHP workers.
 * @param string $directory Verified disposable directory for readiness barriers.
 * @param string $owner Private owner authority, never reported.
 * @param string $scenario Allowlisted held/released sample identity.
 * @param list<string> $sharedCookies Authenticated fixture cookie records, kept private.
 * @param list<string> $independentCookies A different authenticated fixture session.
 * @return array{scenario:string,reader_barriers_observed:bool,reader_readiness_ms:float,same_completed_before_holder_release:bool,overlap_ms:float,
 *   requests:array<string,array{status:int,total_ms:float,first_byte_ms:float,session_start_ms:float,handler:string}>}
 *   Only timing numbers, fixed scenario and barrier observations; no cookie or response content.
 */
function session_contention_sample(array $servers, string $directory, string $owner, string $scenario,
    array $sharedCookies, array $independentCookies): array
{
    $directory = owned_directory($directory, $owner);
    $multi = curl_multi_init();
    $handles = ['holder' => request_handle($servers[0]['origin'], '/hold?scenario=' . $scenario, $owner, $sharedCookies)];
    $completed = [];
    try {
        curl_multi_add_handle($multi, $handles['holder']);
        $deadline = microtime(true) + READY_TIMEOUT_SECONDS;
        do {
            pump($multi, $handles, $completed);
            clearstatcache(true, $directory . '/ready-' . $scenario);
            $ready = is_file($directory . '/ready-' . $scenario);
        } while (!$ready && microtime(true) < $deadline);
        check($ready && !isset($completed['holder']), 'Holder did not enter its bounded barrier.');
        $handles['same'] = request_handle($servers[1]['origin'], '/suggest?scenario=' . $scenario . '&reader=same', $owner, $sharedCookies);
        $handles['independent'] = request_handle($servers[2]['origin'], '/suggest?scenario=' . $scenario . '&reader=independent', $owner, $independentCookies);
        curl_multi_add_handle($multi, $handles['same']);
        curl_multi_add_handle($multi, $handles['independent']);
        $started = microtime(true);
        $released = str_starts_with($scenario, 'released-');
        do {
            pump($multi, $handles, $completed);
            clearstatcache(true, $directory . '/enter-' . $scenario . '-same');
            clearstatcache(true, $directory . '/enter-' . $scenario . '-independent');
            $barriersReady = is_file($directory . '/enter-' . $scenario . '-same')
                && is_file($directory . '/enter-' . $scenario . '-independent');
            $readersReady = $barriersReady && isset($completed['independent']) && (!$released || isset($completed['same']));
        } while (!$readersReady && microtime(true) - $started < READY_TIMEOUT_SECONDS);
        check($barriersReady, 'Both readers must reach session startup before lock observation.');
        check($readersReady && !isset($completed['holder']), 'Independent readers must run while holder remains active.');
        $readinessMilliseconds = (microtime(true) - $started) * MILLISECONDS_PER_SECOND;
        $observationStarted = microtime(true);
        do {
            pump($multi, $handles, $completed);
            $elapsed = microtime(true) - $observationStarted;
        } while ($elapsed * MILLISECONDS_PER_SECOND < OBSERVATION_MILLISECONDS);
        check(!isset($completed['holder']), 'Holder must remain active throughout the ready-reader observation.');
        $sameCompletedBeforeRelease = isset($completed['same']);
        check($sameCompletedBeforeRelease === $released, 'Observed files-session serialization disagrees with the holder policy.');
        check(file_put_contents($directory . '/release-' . $scenario, 'release') !== false, 'Holder barrier release failed.');
        $deadline = microtime(true) + HTTP_TIMEOUT_SECONDS;
        do {
            pump($multi, $handles, $completed);
        } while (count($completed) < WORKER_COUNT && microtime(true) < $deadline);
        check(count($completed) === WORKER_COUNT, 'Session HTTP completion deadline exceeded.');
        foreach ($completed as $role => $response) {
            check($response['status'] === 200 && ($response['json']['ok'] ?? false) === true, 'Authenticated fixture request failed.');
            if ($role !== 'holder') {
                check(count($response['json']['candidates'] ?? []) === 1, 'Real title controller/model suggestion missing.');
            }
            unset($completed[$role]['json']);
        }
        return ['scenario' => $scenario, 'reader_barriers_observed' => true,
            'reader_readiness_ms' => round($readinessMilliseconds, 3), 'same_completed_before_holder_release' => $sameCompletedBeforeRelease,
            'overlap_ms' => round($elapsed * MILLISECONDS_PER_SECOND, 3), 'requests' => $completed];
    } finally {
        // Also release a waiting holder on assertion failure, before tearing down transports.
        file_put_contents($directory . '/release-' . $scenario, 'release');
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
        }
    }
}

if (!extension_loaded('curl') || !extension_loaded('pdo_sqlite') || !function_exists('proc_open')) {
    $required = getenv('GALLERY_SESSION_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " session contention requires curl, pdo_sqlite and proc_open\n";
    exit($required ? 1 : 0);
}

$directory = '';
$owner = bin2hex(random_bytes(16));
$servers = [];
$stage = 'fixture ownership';
$exit = 0;
try {
    $directory = sys_get_temp_dir() . '/gallery-session-' . $owner;
    check(mkdir($directory, 0700), 'Could not create owned session fixture.');
    check(file_put_contents($directory . '/.session-owner', $owner) !== false, 'Could not create session fixture marker.');
    $directory = owned_directory($directory, $owner);
    check(mkdir($directory . '/sessions', 0700), 'Could not create disposable session store.');
    $stage = 'independent workers';
    for ($index = 0; $index < WORKER_COUNT; $index++) {
        $servers[] = start_server($directory, $owner, $index);
    }
    $stage = 'fixture authentication';
    $cookieJars = [];
    foreach (['shared', 'independent'] as $role) {
        $handle = request_handle($servers[0]['origin'], '/login', $owner);
        $body = curl_exec($handle);
        check(is_string($body), 'Fixture session issuance transport failed.');
        $result = response_metrics($handle, $body);
        check($result['status'] === 200 && ($result['json']['ok'] ?? false), 'Fixture session issuance failed.');
        $cookieJars[$role] = curl_getinfo($handle, CURLINFO_COOKIELIST);
        check(is_array($cookieJars[$role]) && count($cookieJars[$role]) === 1, 'Fixture session cookie missing.');
    }
    check($cookieJars['shared'] !== $cookieJars['independent'], 'Control must use an independent session.');
    $anonymous = request_handle($servers[1]['origin'], '/suggest', $owner);
    $response = curl_exec($anonymous);
    check(is_string($response) && response_metrics($anonymous, $response)['status'] === 401,
        'Actual title controller must refuse anonymous access.');

    $measurements = [];
    for ($sample = 0; $sample < SAMPLE_COUNT; $sample++) {
        foreach (['held', 'released'] as $mode) {
            $stage = $mode . ' session comparison';
            $measurements[] = session_contention_sample($servers, $directory, $owner, $mode . '-' . $sample,
                $cookieJars['shared'], $cookieJars['independent']);
        }
    }
    $stage = 'persisted session state';
    foreach ($cookieJars as $cookies) {
        $handle = request_handle($servers[1]['origin'], '/state', $owner, $cookies);
        $body = curl_exec($handle);
        check(is_string($body), 'Fixture session state transport failed.');
        $result = response_metrics($handle, $body);
        check($result['status'] === 200 && ($result['json']['ok'] ?? false), 'Fixture auth, CSRF or preference state changed.');
    }
    echo 'MEASURE session contention ' . json_encode(['php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
        'handler' => 'files', 'workers' => count($servers), 'samples' => $measurements,
        'measurement_note' => 'session_start_ms includes lock acquisition, storage I/O and decode; not pure lock wait.',
        'scope' => 'Synthetic session issuance; real session bootstrap, current_user, title controller, service and SQLite model reads.'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    echo "PASS session contention shared and independent cookies with held and released fixture locks\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL session contention ' . $stage . "\n");
    if ($exception instanceof Gallery\Tests\SessionContention\FixtureAssertionFailure) {
        fwrite(STDERR, $exception->getMessage() . "\n");
    }
    $exit = 1;
} finally {
    foreach ($servers as $server) {
        proc_terminate($server['process']);
        proc_close($server['process']);
    }
    if ($directory !== '' && is_dir($directory)) {
        try {
            remove_directory($directory, $owner);
            echo "PASS session contention owned fixture cleanup\n";
        } catch (Throwable) {
            fwrite(STDERR, "FAIL session contention owned fixture cleanup\n");
            $exit = 1;
        }
    }
}
exit($exit);
