<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/session_contention_application.php
 * Module Type: Test Fixture
 * Purpose: Supply owned application workers and cookie-isolated transport for real-route evidence.
 * Responsibilities:
 *   - Reuse the unchanged workflow clone allocator, never its shared listener for measurements.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Tests\SessionContention;

require_once __DIR__ . '/session_contention_database.php';

/**
 * Create an authenticated-loopback-capable handle without following redirects.
 * @param string $origin One owned literal-loopback worker.
 * @param string $path Allowlisted fixture or actual Admin route.
 * @param string $owner Private clone authority passed only in a header.
 * @param list<string> $cookies Private cURL cookie records.
 * @param ?array<string,string> $fields POST fields, or null for GET.
 * @return \CurlHandle Caller owns the transfer and retains response data privately.
 */
function application_handle(string $origin, string $path, string $owner, array $cookies = [], ?array $fields = null): \CurlHandle
{
    check(preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{3,4}$~D', $origin) === 1, 'Application worker origin refused.');
    check(preg_match('~^/(?:health|control|hold\?scenario=(?:held|released)-[0-2]|index\.php\?page=admin_(?:login|logout|gallery_title_completion|gallery_picker_search)(?:&[a-z_]+=[A-Za-z0-9_-]+)*)$~D', $path) === 1, 'Application request target refused.');
    $handle = curl_init();
    curl_setopt_array($handle, [CURLOPT_URL => $origin . $path, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => READY_TIMEOUT_SECONDS, CURLOPT_TIMEOUT => HTTP_TIMEOUT_SECONDS,
        CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        CURLOPT_HTTPHEADER => ['X-Fixture-Owner: ' . $owner]]);
    foreach ($cookies as $cookie) {
        curl_setopt($handle, CURLOPT_COOKIELIST, $cookie);
    }
    if ($fields !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    return $handle;
}

/**
 * Complete one private request and propagate only its cookie jar to later fixture calls.
 * @param string $origin Owned worker origin.
 * @param string $path Fixed fixture/application path.
 * @param string $owner Private clone authority.
 * @param list<string> $cookies Updated in-memory cookie jar.
 * @param ?array<string,string> $fields POST fields, or null for GET.
 * @return array{status:int,body:string,json:mixed,headers:array<string,string>} Private response; never print wholesale.
 */
function application_request(string $origin, string $path, string $owner, array &$cookies, ?array $fields = null): array
{
    $handle = application_handle($origin, $path, $owner, $cookies, $fields);
    $response = curl_exec($handle);
    check(is_string($response), 'Actual-route HTTP transport failed.');
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $headers = [];
    foreach (explode("\r\n", substr($response, 0, $headerSize)) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    $cookies = curl_getinfo($handle, CURLINFO_COOKIELIST);
    $body = substr($response, $headerSize);
    return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body,
        'json' => json_decode($body, true), 'headers' => $headers];
}

/**
 * Bind workers to this child clone even when the central runner owns an outer fixture.
 *
 * Import only the allocator's explicit child identity/database fields; its full
 * environment also inherits outer runner values that must not replace newly
 * provisioned disposable database inputs. No authority value is printed.
 *
 * @param \GalleryWorkflow\Fixture $fixture Fresh clone whose marker and credentials win.
 * @param array<string,string> $environment Private disposable database/runner inputs.
 * @return array<string,string> Child worker environment with this clone's identity and DSN.
 */
function application_environment(\GalleryWorkflow\Fixture $fixture, array $environment): array
{
    \GalleryWorkflow\validateFixture($fixture->directory, $fixture->token);
    $child = $fixture->environment();
    foreach (['GALLERY_WORKFLOW_FIXTURE', 'GALLERY_WORKFLOW_TOKEN', 'GALLERY_TEST_MYSQL_DSN',
        'GALLERY_TEST_MYSQL_USER', 'GALLERY_TEST_MYSQL_PASSWORD'] as $key) {
        check(isset($child[$key]) && is_string($child[$key]), 'Owned application environment field missing.');
        $environment[$key] = $child[$key];
    }
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    return $environment;
}

/**
 * Start one separately owned worker against a fresh clone's dedicated session store.
 * @param \GalleryWorkflow\Fixture $fixture Existing allocator's owned application copy.
 * @param array<string,string> $environment Explicit child environment, including disposable DB opt-in.
 * @param int $index Bounded worker/log identity.
 * @return array{process:resource,origin:string} Exact process to terminate before clone cleanup.
 */
function application_worker(\GalleryWorkflow\Fixture $fixture, array $environment, int $index): array
{
    \GalleryWorkflow\validateFixture($fixture->directory, $fixture->token);
    check($index >= 0 && $index < WORKER_COUNT, 'Application worker identity refused.');
    check(($environment['GALLERY_WORKFLOW_FIXTURE'] ?? '') === $fixture->directory
        && hash_equals($fixture->token, $environment['GALLERY_WORKFLOW_TOKEN'] ?? ''), 'Application worker must inherit its own child fixture authority.');
    $origin = 'http://127.0.0.1:' . \GalleryWorkflow\freePort();
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $process = proc_open([PHP_BINARY, '-d', 'session.save_handler=files', '-d', 'session.save_path=' . $fixture->directory . '/sessions',
        '-d', 'session.gc_probability=0', '-d', 'session.use_strict_mode=1', '-d', 'display_errors=0',
        '-d', 'log_errors=1', '-d', 'disable_functions=mail', '-S', substr($origin, 7),
        '-t', $fixture->directory . '/public', __DIR__ . '/session_contention_application_router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $fixture->directory . '/session-worker-' . $index . '.log', 'a'],
            2 => ['file', $fixture->directory . '/session-worker-' . $index . '.log', 'a']],
        $pipes, $fixture->directory, $environment, ['bypass_shell' => true]);
    check(is_resource($process), 'Application worker launch failed.');
    fclose($pipes[0]);
    try {
        $deadline = microtime(true) + READY_TIMEOUT_SECONDS;
        do {
            $cookies = [];
            try {
                $probe = application_request($origin, '/health', $fixture->token, $cookies);
                if ($probe['status'] === 200 && hash_equals($fixture->token, $probe['body'])) {
                    return ['process' => $process, 'origin' => $origin];
                }
            } catch (FixtureAssertionFailure) {
                // Connection may race only this newly launched worker.
            }
            usleep(POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);
        check(false, 'Application worker readiness failed.');
    } catch (\Throwable $exception) {
        proc_terminate($process);
        proc_close($process);
        throw $exception;
    }
}

/**
 * Measure actual title-route readers behind a synthetic authenticated long-work holder.
 * @param list<array{process:resource,origin:string}> $workers Three independent application workers.
 * @param \GalleryWorkflow\Fixture $fixture Owned clone for barriers only.
 * @param string $scenario Fixed held/released repetition identity.
 * @param list<string> $shared Cookies obtained from the genuine login form.
 * @param list<string> $independent Independently logged-in cookie jar.
 * @param string $csrf Actual logged-in session CSRF token for the private holder POST.
 * @return array{scenario:string,reader_entry_observed:bool,same_completed_before_release:bool,overlap_ms:float,requests:array<string,array<string,mixed>>}
 *   Bounded timings/statuses only; no cookie, CSRF or application response data.
 */
function application_sample(array $workers, \GalleryWorkflow\Fixture $fixture, string $scenario, array $shared, array $independent, string $csrf): array
{
    $multi = curl_multi_init();
    $completed = [];
    $handles = ['holder' => application_handle($workers[0]['origin'], '/hold?scenario=' . $scenario, $fixture->token, $shared, ['csrf_token' => $csrf])];
    try {
        curl_multi_add_handle($multi, $handles['holder']);
        $deadline = microtime(true) + READY_TIMEOUT_SECONDS;
        do {
            pump($multi, $handles, $completed);
            clearstatcache(true, $fixture->directory . '/ready-' . $scenario);
            $ready = is_file($fixture->directory . '/ready-' . $scenario);
        } while (!$ready && microtime(true) < $deadline);
        check($ready && !isset($completed['holder']), 'Authenticated application holder barrier failed.');
        foreach (['same' => $shared, 'independent' => $independent] as $role => $cookies) {
            $path = '/index.php?page=admin_gallery_title_completion&q=Workflow&parent_id=0&scenario=' . $scenario . '&reader=' . $role;
            $handles[$role] = application_handle($workers[$role === 'same' ? 1 : 2]['origin'], $path, $fixture->token, $cookies);
            curl_multi_add_handle($multi, $handles[$role]);
        }
        $released = str_starts_with($scenario, 'released-');
        $deadline = microtime(true) + READY_TIMEOUT_SECONDS;
        do {
            pump($multi, $handles, $completed);
            clearstatcache();
            $entered = is_file($fixture->directory . '/enter-' . $scenario . '-same')
                && is_file($fixture->directory . '/enter-' . $scenario . '-independent');
            $ready = $entered && isset($completed['independent']) && (!$released || isset($completed['same']));
        } while (!$ready && microtime(true) < $deadline);
        check($ready && !isset($completed['holder']), 'Actual readers must enter before the bounded overlap.');
        $started = microtime(true);
        do {
            pump($multi, $handles, $completed);
            $elapsed = (microtime(true) - $started) * MILLISECONDS_PER_SECOND;
        } while ($elapsed < OBSERVATION_MILLISECONDS);
        check(!isset($completed['holder']), 'Application holder ended before observation completed.');
        $sameCompleted = isset($completed['same']);
        check($sameCompleted === $released, 'Actual suggestion ordering disagrees with the fixture holder policy.');
        check(file_put_contents($fixture->directory . '/release-' . $scenario, 'release') !== false, 'Application holder release failed.');
        $deadline = microtime(true) + HTTP_TIMEOUT_SECONDS;
        do {
            pump($multi, $handles, $completed);
        } while (count($completed) < WORKER_COUNT && microtime(true) < $deadline);
        check(count($completed) === WORKER_COUNT, 'Actual-route completion deadline exceeded.');
        foreach ($completed as $role => &$response) {
            check($response['status'] === 200 && ($response['json']['ok'] ?? false), 'Actual authenticated application request failed.');
            if ($role !== 'holder') {
                check(count($response['json']['candidates'] ?? []) > 0, 'Actual title model returned no seeded candidate.');
            }
            unset($response['json']);
        }
        unset($response);
        return ['scenario' => $scenario, 'reader_entry_observed' => true, 'same_completed_before_release' => $sameCompleted,
            'overlap_ms' => round($elapsed, 3), 'requests' => $completed];
    } finally {
        file_put_contents($fixture->directory . '/release-' . $scenario, 'release');
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
        }
    }
}
