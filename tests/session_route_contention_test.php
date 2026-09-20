<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/session_route_contention_test.php
 * Module Type: Regression Test
 * Purpose: Measure real authenticated title-route session contention in a disposable application.
 * Responsibilities:
 *   - Exercise actual login/CSRF, cms_run, remember restoration, picker diagnostics and logout.
 *   - Separate worker queues and synthetic slow work from product-route evidence.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/session_contention_application.php';

use Gallery\Tests\SessionContention\RouteDatabase;
use Gallery\Tests\SessionContention\FixtureAssertionFailure;
use GalleryWorkflow\Fixture;
use function Gallery\Tests\SessionContention\application_request;
use function Gallery\Tests\SessionContention\application_environment;
use function Gallery\Tests\SessionContention\application_worker;
use function Gallery\Tests\SessionContention\application_sample;
use function Gallery\Tests\SessionContention\check;
use const Gallery\Tests\SessionContention\SAMPLE_COUNT;
use const Gallery\Tests\SessionContention\WORKER_COUNT;

/**
 * Parse the actual login form's CSRF token; never print it or any response body.
 * @param string $html Real application login response.
 * @return string Private CSRF token retained only for this disposable test.
 */
function session_route_csrf(string $html): string
{
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $field = (new DOMXPath($document))->query('//input[@name="csrf_token"]')->item(0);
    check($field instanceof DOMElement && $field->getAttribute('value') !== '', 'Actual login form CSRF token missing.');
    return $field->getAttribute('value');
}

/**
 * Select or omit one private cookie name without serializing session values into evidence.
 * @param list<string> $cookies In-memory cURL records.
 * @param string $name Exact application-provided cookie name.
 * @param bool $keep True selects the named cookie; false omits it.
 * @return list<string> New isolated cookie jar.
 */
function session_route_cookie_filter(array $cookies, string $name, bool $keep): array
{
    $result = [];
    foreach ($cookies as $cookie) {
        $fields = explode("\t", $cookie);
        if ((($fields[5] ?? '') === $name) === $keep) {
            $result[] = $cookie;
        }
    }
    return $result;
}

$required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1' || getenv('GALLERY_SESSION_ROUTE_REQUIRED') === '1';
$enabled = getenv('GALLERY_WORKFLOW_ENABLE') === 'disposable-only' || getenv('GALLERY_SESSION_ENABLE') === 'disposable-only';
foreach (['pdo_mysql', 'curl', 'gd', 'zip', 'dom', 'mbstring'] as $extension) {
    $enabled = $enabled && extension_loaded($extension);
}
if (!$enabled || !function_exists('proc_open')) {
    echo ($required ? 'BLOCKED' : 'SKIP') . " actual-route session fixture requires explicit disposable MySQL inputs and workflow extensions\n";
    exit($required ? 1 : 0);
}
$database = new RouteDatabase(getenv());
$fixture = null;
$workers = [];
$stage = 'private database';
$exit = 0;
try {
    $database->start();
    $stage = 'fresh application clone';
    $fixture = new Fixture(dirname(__DIR__), $database->environment);
    $fixture->start();
    $directory = GalleryWorkflow\validateFixture($fixture->directory, $fixture->token);
    // This is the freshly generated clone's config, not the active installation.
    $configuration = require $directory . '/config.php';
    $configuration['auth']['persistent_login_enabled'] = true;
    $configuration['language']['available'] = ['en', 'cs'];
    check(file_put_contents($directory . '/config.php', "<?php\nreturn " . var_export($configuration, true) . ";\n") !== false,
        'Disposable authentication configuration failed.');
    // Deliberately exercise a real late-write fallback on one copied catalog only.
    $catalog = json_decode((string) file_get_contents($directory . '/app/lang/cs.json'), true, 512, JSON_THROW_ON_ERROR);
    unset($catalog['gallery_picker.directory']);
    check(file_put_contents($directory . '/app/lang/cs.json', json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) !== false,
        'Disposable translation fallback fixture failed.');
    $traceToken = bin2hex(random_bytes(16));
    $traceDirectory = $directory . '/cache/admin-test-runs/' . $traceToken;
    check(mkdir($traceDirectory, 0700, true), 'Disposable trace directory creation failed.');
    check(file_put_contents($traceDirectory . '/meta.json', json_encode([
        'token' => $traceToken, 'created_at_unix' => time(), 'finalized_at' => null,
    ], JSON_THROW_ON_ERROR)) !== false, 'Disposable trace context creation failed.');
    check(file_put_contents($directory . '/session-trace-token', $traceToken) !== false, 'Disposable trace marker creation failed.');
    $seed = json_decode((string) file_get_contents($directory . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    // Reproduce central nested-runner inputs on every execution, without using any outer fixture.
    $inherited = $database->environment;
    foreach (['GALLERY_WORKFLOW_FIXTURE', 'GALLERY_WORKFLOW_TOKEN', 'GALLERY_TEST_MYSQL_DSN',
        'GALLERY_TEST_MYSQL_USER', 'GALLERY_TEST_MYSQL_PASSWORD'] as $key) {
        $inherited[$key] = 'outer-fixture-sentinel';
    }
    $environment = application_environment($fixture, $inherited);
    $childEnvironment = $fixture->environment();
    foreach (['GALLERY_WORKFLOW_FIXTURE', 'GALLERY_WORKFLOW_TOKEN', 'GALLERY_TEST_MYSQL_DSN',
        'GALLERY_TEST_MYSQL_USER', 'GALLERY_TEST_MYSQL_PASSWORD'] as $key) {
        check($environment[$key] === $childEnvironment[$key], 'Inherited runner authority must not replace child fixture identity or database inputs.');
    }
    echo "PASS actual-route session nested runner child environment isolation\n";
    $stage = 'separate application workers';
    for ($index = 0; $index < WORKER_COUNT; $index++) {
        $workers[] = application_worker($fixture, $environment, $index);
    }
    $origin = $workers[0]['origin'];
    $stage = 'genuine login and CSRF';
    $anonymous = [];
    $denial = application_request($origin, '/index.php?page=admin_gallery_title_completion&q=Workflow', $fixture->token, $anonymous);
    check($denial['status'] === 401, 'Actual anonymous title request must be refused.');
    $jars = [];
    $tokens = [];
    foreach (['shared', 'independent'] as $role) {
        $jars[$role] = [];
        $login = application_request($origin, '/index.php?page=admin_login', $fixture->token, $jars[$role]);
        check($login['status'] === 200, 'Actual login form unavailable.');
        $tokens[$role] = session_route_csrf($login['body']);
        $preLoginCookies = $jars[$role];
        $invalid = application_request($origin, '/index.php?page=admin_login', $fixture->token, $jars[$role], [
            'csrf_token' => 'fixture-invalid-csrf', 'identifier' => $seed['username'], 'password' => $seed['password'],
        ]);
        check($invalid['status'] === 400, 'Actual login must reject invalid CSRF.');
        $login = application_request($origin, '/index.php?page=admin_login', $fixture->token, $jars[$role], [
            'csrf_token' => $tokens[$role], 'identifier' => $seed['username'],
            'password' => $seed['password'], 'remember_login' => '1',
        ]);
        check($login['status'] === 302, 'Actual password and CSRF login failed.');
        $state = application_request($origin, '/control', $fixture->token, $jars[$role]);
        check($state['status'] === 200 && ($state['json']['admin'] ?? false)
            && hash_equals($tokens[$role], $state['json']['csrf_token']), 'Genuine authentication and CSRF state must persist.');
        $sessionName = $state['json']['session_name'];
        $rememberName = $state['json']['remember_name'];
        check(session_route_cookie_filter($preLoginCookies, $sessionName, true) !== session_route_cookie_filter($jars[$role], $sessionName, true),
            'Actual password login must regenerate session identity.');
        check(count(session_route_cookie_filter($jars[$role], $rememberName, true)) === 1, 'Genuine persistent login credential was not issued.');
    }
    check(session_route_cookie_filter($jars['shared'], $sessionName, true) !== session_route_cookie_filter($jars['independent'], $sessionName, true),
        'Control reader must use an independently authenticated session.');
    $stage = 'actual title route contention';
    $samples = [];
    for ($sample = 0; $sample < SAMPLE_COUNT; $sample++) {
        foreach (['held', 'released'] as $mode) {
            $samples[] = application_sample($workers, $fixture, $mode . '-' . $sample, $jars['shared'], $jars['independent'], $tokens['shared']);
        }
    }
    $stage = 'remember-only actual title request';
    $restoredJar = session_route_cookie_filter($jars['shared'], $sessionName, false);
    $restored = application_request($workers[1]['origin'], '/index.php?page=admin_gallery_title_completion&q=Workflow&parent_id=0',
        $fixture->token, $restoredJar);
    check($restored['status'] === 200 && ($restored['json']['ok'] ?? false)
        && count(session_route_cookie_filter($restoredJar, $sessionName, true)) === 1, 'Real remember restoration must establish a PHP session before the title response.');
    $restoredState = application_request($workers[1]['origin'], '/control', $fixture->token, $restoredJar);
    check($restoredState['status'] === 200 && ($restoredState['json']['admin'] ?? false), 'Restored administrator identity must persist to the next request.');
    $restoredToken = $restoredState['json']['csrf_token'];
    $stage = 'late session writes and preference persistence';
    $language = application_request($origin, '/control', $fixture->token, $restoredJar,
        ['action' => 'language', 'csrf_token' => $restoredToken]);
    check($language['status'] === 200 && $language['json']['language'] === 'cs' && $language['json']['missing_count'] === 0, 'Actual language setting and diagnostics reset failed.');
    $title = application_request($origin, '/index.php?page=admin_gallery_title_completion&q=Workflow', $fixture->token, $restoredJar);
    check($title['status'] === 200 && ($title['headers']['x-fixture-late-session-keys'] ?? '') === ''
        && ($title['headers']['x-fixture-other-session-changes'] ?? '') === '0', 'Normal title dispatch unexpectedly changed session fields.');
    $picker = application_request($origin, '/index.php?page=admin_gallery_picker_search&q=Workflow', $fixture->token, $restoredJar);
    check($picker['status'] === 200 && ($picker['json']['ok'] ?? false)
        && ($picker['headers']['x-fixture-late-session-keys'] ?? '') === ''
        && ($picker['headers']['x-fixture-other-session-changes'] ?? '') === '0', 'Normal JSON picker dispatch unexpectedly changed session fields.');
    $htmlPicker = application_request($origin, '/index.php?page=admin_gallery_picker_search&format=html&q=Workflow', $fixture->token, $restoredJar);
    check($htmlPicker['status'] === 200 && str_contains($htmlPicker['headers']['x-fixture-late-session-keys'] ?? '', 'cms_translation_missing'),
        'Actual picker HTML fallback must demonstrate its late translation-diagnostic session write.');
    $after = application_request($origin, '/control', $fixture->token, $restoredJar);
    check($after['status'] === 200 && $after['json']['language'] === 'cs' && $after['json']['missing_count'] > 0
        && hash_equals($restoredToken, $after['json']['csrf_token']), 'Late diagnostics, language preference and CSRF must survive the next request.');
    $stage = 'logout and remember revocation';
    $oldRememberJar = session_route_cookie_filter($restoredJar, $sessionName, false);
    $logout = application_request($origin, '/index.php?page=admin_logout', $fixture->token, $restoredJar);
    check($logout['status'] === 302, 'Actual logout failed.');
    $denial = application_request($origin, '/index.php?page=admin_gallery_title_completion&q=Workflow', $fixture->token, $restoredJar);
    check($denial['status'] === 401, 'Actual logged-out title request must be refused.');
    $denial = application_request($origin, '/index.php?page=admin_gallery_title_completion&q=Workflow', $fixture->token, $oldRememberJar);
    check($denial['status'] === 401, 'Revoked genuine remember credential must not restore title-route authority.');

    echo 'MEASURE actual-route session ' . json_encode([
        'php' => PHP_VERSION, 'handler' => 'files', 'measured_workers' => count($workers),
        'samples' => $samples, 'genuine_password_csrf_logins' => 2, 'invalid_csrf_refusals' => 2,
        'remember_only_restoration' => true, 'logout_and_remember_revocation' => true,
        'title_json_late_keys' => [], 'picker_json_late_keys' => [], 'picker_html_late_keys' => ['cms_translation_missing'],
        'scope' => 'Actual cms_run and migrated MySQL clone; synthetic authenticated slow-work holder only. No real upload workload or production load measured.',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    echo "PASS actual-route session login CSRF contention remember restore late writes logout and preference persistence\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL actual-route session ' . $stage . "\n");
    if ($exception instanceof FixtureAssertionFailure) {
        fwrite(STDERR, $exception->getMessage() . "\n");
    } else {
        fwrite(STDERR, 'Bounded failure category: ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine() . "\n");
    }
    $exit = 1;
} finally {
    foreach ($workers as $worker) {
        proc_terminate($worker['process']);
        proc_close($worker['process']);
    }
    $cleanupOk = true;
    try {
        if ($fixture !== null) {
            $fixture->close();
        }
    } catch (Throwable) {
        $cleanupOk = false;
    }
    try {
        $database->close();
    } catch (Throwable) {
        $cleanupOk = false;
    }
    if ($cleanupOk) {
        echo "PASS actual-route session owned application database workers and files cleaned\n";
    } else {
        fwrite(STDERR, "FAIL actual-route session owned cleanup requires attention\n");
        $exit = 1;
    }
}
exit($exit);
