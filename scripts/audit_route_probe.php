<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_route_probe.php
 * Module Type: Audit Measurement Child
 * Purpose: Measure a real CMS request lifecycle inside an owned disposable application fixture.
 * Responsibilities: Execute one registered route through the public entrypoint and emit bounded JSON metrics.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();

require_once dirname(__DIR__) . '/tests/support/gallery_workflow_safety.php';
require_once __DIR__ . '/audit_route_performance.php';

use function GalleryWorkflow\check;
use function GalleryWorkflow\validateFixture;

$definitions = require __DIR__ . '/audit_route_probe_registry.php';

$probeId = (string) ($argv[1] ?? '');
$fixtureDirectory = (string) ($argv[2] ?? '');
$fixtureToken = (string) ($argv[3] ?? '');
if (count($argv) !== 4 || !isset($definitions[$probeId])) {
    fwrite(STDERR, "Usage: php scripts/audit_route_probe.php <probe-id> <fixture-directory> <fixture-token>\n");
    exit(2);
}

$definition = $definitions[$probeId];
$definition['uri'] = (string) $definition['request_uri'];
$projectRoot = validateFixture($fixtureDirectory, $fixtureToken);
check(getenv('GALLERY_WORKFLOW_ENABLE') === 'disposable-only', 'Disposable workflow opt-in is required.');
$routeContext = json_decode((string) @file_get_contents($projectRoot . '/route-probe-context.json'), true);
check(is_array($routeContext)
    && preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', (string) ($routeContext['gallery_path'] ?? '')) === 1
    && preg_match('/^[A-Za-z0-9_-]+$/D', (string) ($routeContext['image_slug'] ?? '')) === 1,
    'Fixture route context is unavailable or invalid.');
$definition['uri'] = strtr($definition['uri'], [
    '{gallery_path}' => (string) $routeContext['gallery_path'],
    '{image_slug}' => (string) $routeContext['image_slug'],
]);
$fixtureConfig = require $projectRoot . '/config.php';
check(($fixtureConfig['database']['name'] ?? null) === 'gallery_workflow_' . $fixtureToken,
    'Fixture configuration identity mismatch.');
check(realpath((string) ($fixtureConfig['galleries_root'] ?? '')) === realpath($projectRoot . '/galleries'),
    'Fixture gallery storage identity mismatch.');

$_GET = [];
$_POST = [];
$_COOKIE = [];
$_FILES = [];
$_REQUEST = [];
$_SERVER = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => $definition['uri'],
    'SCRIPT_NAME' => '/index.php',
    'PHP_SELF' => '/index.php',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'HTTP_HOST' => '127.0.0.1',
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_USER_AGENT' => 'PHP-Gallery-route-probe/1',
    'REQUEST_TIME_FLOAT' => microtime(true),
];
if (str_contains($definition['uri'], '?')) {
    parse_str((string) parse_url($definition['uri'], PHP_URL_QUERY), $_GET);
}
ini_set('session.save_path', $projectRoot . '/sessions');
if ($definition['auth_mode'] === 'admin') {
    $sessionIdPath = $projectRoot . '/route-probe-session-id';
    $sessionId = is_file($sessionIdPath) ? trim((string) file_get_contents($sessionIdPath)) : '';
    check(preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sessionId) === 1, 'Owned authenticated fixture session is unavailable.');
    $_COOKIE['workflow_' . $fixtureToken] = $sessionId;
}

$startedAt = hrtime(true);
$includedBefore = get_included_files();
$bodyBytes = 0;
$bodyHash = hash('sha256', '');
$expectedOutcome = $definition['expected_outcome'];
register_shutdown_function(static function () use (&$bodyBytes, &$bodyHash, &$probeFailed, $startedAt,
    $includedBefore, $projectRoot, $probeId, $definition, $expectedOutcome): void {
    register_shutdown_function(static function () use (&$bodyBytes, &$bodyHash, &$probeFailed, $startedAt,
        $includedBefore, $projectRoot, $probeId, $definition, $expectedOutcome): void {
    $body = ob_get_level() > 0 ? (string) ob_get_clean() : '';
    $bodyBytes = strlen($body);
    $bodyHash = hash('sha256', $body);
    $lastError = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $fatal = is_array($lastError) && in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true);

    $paths = [];
    foreach (array_diff(get_included_files(), $includedBefore) as $path) {
        $resolved = realpath($path);
        if (!is_string($resolved) || !str_starts_with(strtolower($resolved), strtolower($projectRoot . DIRECTORY_SEPARATOR))) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($resolved, strlen($projectRoot) + 1));
        if (str_ends_with(strtolower($relative), '.php')
            && (str_starts_with($relative, 'app/') || in_array($relative, ['public/index.php', 'index.php'], true))) {
            $paths[] = $relative;
        }
    }
    sort($paths, SORT_STRING);

    $status = http_response_code();
    if (!is_int($status) || $status < 100) {
        $status = 200;
    }
    $actualOutcome = $fatal ? 'harness_error' : (
        $expectedOutcome === 'success'
            ? ($status >= 200 && $status < 300 ? 'success' : 'unexpected')
            : (in_array($status, [302, 401, 403, 404], true) ? 'denied' : 'unexpected')
    );
    $loadedModules = function_exists('Gallery\\Core\\cms_runtime_kernel')
        ? \Gallery\Core\cms_runtime_kernel()->loadedModules() : [];
    $metric = [
        'schema_version' => 1,
        'scope' => 'cms-run-route-lifecycle',
        'probe' => $probeId,
        'route' => function_exists('Gallery\\Core\\cms_runtime_kernel')
            ? (\Gallery\Core\cms_runtime_kernel()->currentRoute()?->name ?? '') : '',
        'request_uri' => $definition['uri'],
        'auth_mode' => $definition['auth_mode'],
    'expected_outcome' => $expectedOutcome,
        'actual_outcome' => $actualOutcome,
        'http_status' => $status,
        'included_php_files' => count($paths),
        'included_paths' => $paths,
        'loaded_module_count' => count($loadedModules),
        'loaded_modules' => $loadedModules,
        'peak_memory_bytes' => memory_get_peak_usage(true),
        'wall_ms' => round((hrtime(true) - $startedAt) / 1e6, 4),
        'php_version' => PHP_VERSION,
        'php_int_size' => PHP_INT_SIZE,
        'response_body_bytes' => $bodyBytes,
        'response_body_sha256' => $bodyHash,
        'fatal_error' => $fatal,
    ];
    // Validate content after capturing resource metrics so image inspection stays outside the measured lifecycle.
    $metric['response_contract_matches'] = \PhpGallery\Audit\route_response_body_matches($body, $definition);
    $encoded = json_encode($metric, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, $encoded . "\n");
    if ($fatal || $probeFailed) {
        exit(1);
    }
    });
});
ob_start();

try {
    // The fixture copy has its own config, media, session path and migrated DB.
    require $projectRoot . '/public/index.php';
} catch (Throwable) {
    $probeFailed = true;
    http_response_code(500);
}
