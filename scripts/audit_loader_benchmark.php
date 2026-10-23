<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_loader_benchmark.php
 * Module Type: Diagnostic Tool
 * Purpose: Compare loader phases on disposable public and authenticated requests.
 * Responsibilities: Reuse owned fixtures, validate responses and retain numeric evidence.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();
require_once dirname(__DIR__) . '/tests/support/gallery_workflow_fixture.php';
require_once dirname(__DIR__) . '/tests/support/audit_route_probe_fixture.php';
require_once __DIR__ . '/audit_route_performance.php';

/**
 * Bound warmed samples while retaining every raw observation.
 * Type: int. Units: requests per route and opcode mode. Scope: this diagnostic.
 * Consumers: the repeated request loop below.
 * Rationale: seven samples show variability without turning measurement into load testing.
 */
const LOADER_BENCHMARK_WARM_SAMPLES = 7;

/**
 * Bound a benchmark fixture child below the existing outer workflow deadline.
 * Type: int. Units: seconds. Scope: one CLI route capture.
 * Consumers: Fixture::run() for the existing route probe.
 * Rationale: 180 seconds leaves ample time for nine real route requests and login.
 */
const LOADER_BENCHMARK_ROUTE_TIMEOUT = 180;

/**
 * Bound one loopback measurement request.
 * Type: int. Units: seconds. Scope: this diagnostic's owned HTTP server.
 * Consumers: loader_benchmark_request().
 * Rationale: 45 seconds matches the established workflow HTTP child deadline.
 */
const LOADER_BENCHMARK_HTTP_TIMEOUT = 45;

/**
 * Request an owned registered route or token-guarded benchmark control.
 * @param string $origin Literal loopback origin from the owned fixture.
 * @param string $uri Root-relative URI from the validated reference registry.
 * @param string|null $cookie Owned authenticated session cookie, or null.
 * @return array{status:int,body:string} Complete response without redirect following.
 */
function loader_benchmark_request(string $origin, string $uri, ?string $cookie): array
{
    GalleryWorkflow\check(
        preg_match('~^http://127\\.0\\.0\\.1:[1-9][0-9]{3,4}$~D', $origin) === 1
            && str_starts_with($uri, '/') && !str_starts_with($uri, '//')
            && preg_match('/[\\r\\n\\x00#]/', $uri) !== 1,
        'Invalid benchmark loopback target.',
    );
    $handle = curl_init($origin . $uri);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => LOADER_BENCHMARK_HTTP_TIMEOUT,
        CURLOPT_PROXY => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        CURLOPT_COOKIE => $cookie ?? '',
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    GalleryWorkflow\check(is_string($body), 'Benchmark HTTP transport failed.');
    return ['status' => $status, 'body' => $body];
}

$fixture = null;
$stage = 'prerequisites';
try {
    $mode = (string) getenv('PHP_GALLERY_LOADER_OPCACHE');
    $label = (string) getenv('PHP_GALLERY_LOADER_LABEL');
    GalleryWorkflow\check(
        in_array($mode, ['off', 'on'], true)
            && in_array($label, ['before', 'after'], true),
        'Choose explicit before/after and on/off benchmark identities.',
    );
    putenv('PHP_GALLERY_LOADER_TRACE=1');
    $fixture = new GalleryWorkflow\Fixture(dirname(__DIR__), getenv());
    $options = ['-d', 'opcache.enable=0'];
    if ($mode === 'on') {
        $extension = rtrim((string) ini_get('extension_dir'), '/\\')
            . DIRECTORY_SEPARATOR
            . (PHP_OS_FAMILY === 'Windows' ? 'php_opcache.dll' : 'opcache.so');
        GalleryWorkflow\check(is_file($extension), 'OPcache binary unavailable.');
        // Type: list<string> of PHP INI options. Units: boolean switches and
        // seconds as named. Scope: this owned server. Consumers: Fixture::start.
        // Rationale: enable cache only here, isolate Windows SHM by fixture ID,
        // validate timestamps each request (0 seconds), and admit copied files
        // immediately (0-second protection) without an artificial sleep.
        $options = [
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.cache_id=' . $fixture->token,
            '-d', 'opcache.validate_timestamps=1',
            '-d', 'opcache.revalidate_freq=0',
            // These freshly copied disposable sources must be cacheable immediately.
            '-d', 'opcache.file_update_protection=0',
        ];
        if (!extension_loaded('Zend OPcache')) {
            array_unshift($options, '-d', 'zend_extension=' . $extension);
        }
    }
    $stage = 'disposable fixture startup';
    $fixture->start($options);
    // Existing probe owns context selection, real login and all nine CLI cases.
    $stage = 'reference route capture';
    $fixture->run(
        [PHP_BINARY, __DIR__ . '/audit_route_performance.php', $label],
        LOADER_BENCHMARK_ROUTE_TIMEOUT,
        'loader reference route capture',
    );
    $reference = json_decode((string) file_get_contents(
        dirname(__DIR__) . '/cache/test-audit/issue-69-routes-' . $label . '.json',
    ), true, flags: JSON_THROW_ON_ERROR);
    GalleryWorkflow\check($reference['status'] === 'PASS', 'Reference routes failed.');
    $seed = json_decode((string) file_get_contents(
        $fixture->directory . '/seed.json',
    ), true, flags: JSON_THROW_ON_ERROR);
    $stage = 'benchmark login';
    $admin = new GalleryWorkflow\Http($fixture->url);
    $csrf = $admin->token('/index.php?page=admin_login');
    $login = $admin->request('/index.php?page=admin_login', [
        'csrf_token' => $csrf,
        'identifier' => $seed['username'],
        'password' => $seed['password'],
    ]);
    GalleryWorkflow\check($login['status'] === 302, 'Benchmark login failed.');
    $definitions = require __DIR__ . '/audit_route_probe_registry.php';
    $samples = [];
    foreach ($reference['routes'] as $route) {
        if (!in_array($route['probe'], ['robots', 'home', 'gallery', 'admin', 'admin_telemetry'], true)) {
            continue;
        }
        $reset = loader_benchmark_request(
            $fixture->url, '/__workflow_' . $fixture->token . '/loader-reset', null,
        );
        $resetResult = json_decode($reset['body'], true, flags: JSON_THROW_ON_ERROR);
        GalleryWorkflow\check(
            ($resetResult['reset'] ?? null) === ($mode === 'on'),
            'Opcode reset did not match the requested mode.',
        );
        $cookie = $route['auth_mode'] === 'admin'
            ? 'workflow_' . $fixture->token . '=' . $admin->cookieValue('workflow_' . $fixture->token)
            : null;
        // Index zero is the first observation after the opcode reset boundary.
        for ($sample = 0; $sample <= LOADER_BENCHMARK_WARM_SAMPLES; $sample++) {
            $stage = $route['probe'] . ' sample ' . $sample;
            $response = loader_benchmark_request(
                $fixture->url, $route['request_uri'], $cookie,
            );
            GalleryWorkflow\check(
                $response['status'] === 200
                    && PhpGallery\Audit\route_response_body_matches(
                        $response['body'], $definitions[$route['probe']],
                    ),
                'Benchmark response contract failed: ' . $route['probe'],
            );
            $lines = file($fixture->directory . '/loader-metrics.jsonl', FILE_IGNORE_NEW_LINES);
            $metric = json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
            GalleryWorkflow\check(
                $metric['uri'] === $route['request_uri']
                    && $metric['opcache_enabled'] === ($mode === 'on')
                    && isset($metric['phases']['runtime_loader.validate']),
                'Missing or mismatched server-side loader evidence.',
            );
            $metric['probe'] = $route['probe'];
            $metric['sample'] = $sample;
            $metric['temperature'] = $sample === 0
                ? ($mode === 'on' ? 'opcode-cold' : 'first-observation')
                : 'warm';
            $samples[] = $metric;
        }
    }
    $report = [
        'label' => $label,
        'mode' => $mode,
        'captured_at' => gmdate(DATE_ATOM),
        'source_loader_sha256' => hash_file('sha256', dirname(__DIR__) . '/app/runtime/ModuleLoader.php'),
        'source_plan_sha256' => hash_file('sha256', dirname(__DIR__) . '/app/runtime/modules.php'),
        'limitations' => [
            'Loopback PHP built-in server; no production Apache/FPM or network latency claim.',
            'OS filesystem and database buffers are not flushed; cold means opcode reset only.',
            'Tracing overhead is present in both variants; execute includes includes and bookkeeping.',
            'Peak memory is PHP request allocator memory, not process RSS or OPcache shared memory.',
        ],
        'cli_reference' => $reference,
        'samples' => $samples,
    ];
    $path = dirname(__DIR__) . '/cache/test-audit/loader-' . $label . '-' . $mode . '.json';
    file_put_contents($path, json_encode(
        $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . "\n", LOCK_EX);
    echo "PASS gallery workflow loader benchmark captured\n";
} catch (Throwable $exception) {
    file_put_contents(
        dirname(__DIR__) . '/cache/test-audit/loader-failure.json',
        json_encode([
            'type' => get_class($exception),
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine(),
            'stage' => $stage,
        ], JSON_THROW_ON_ERROR),
    );
    throw $exception;
} finally {
    $fixture?->close();
}
