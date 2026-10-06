<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/runtime_route_probe_test.php
 * Module Type: Regression Test
 * Purpose: Protect route-lifecycle performance metric identity, shape and hard-limit semantics.
 * Responsibilities: Exercise registered successful/denied routes and reject malformed or unsafe measurements.
 * Author: Rudolf Klusal
 */

declare(strict_types=1);

require_once __DIR__ . '/../scripts/audit_lib.php';
require_once __DIR__ . '/../scripts/audit_route_performance.php';

$root = dirname(__DIR__);
$registry = require $root . '/scripts/audit_route_probe_registry.php';
$expectedProbeIds = ['robots', 'home', 'gallery', 'thumb', 'media', 'admin', 'admin_telemetry', 'admin_denied', 'admin_telemetry_denied'];
$actualProbeIds = array_keys($registry);
sort($actualProbeIds, SORT_STRING);
$sortedExpectedProbeIds = $expectedProbeIds;
sort($sortedExpectedProbeIds, SORT_STRING);
if ($actualProbeIds !== $sortedExpectedProbeIds) {
    throw new RuntimeException('Route performance registry must retain all nine stable probe identities.');
}
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ($registry as $probeId => $definition) {
    $requestUri = strtr($definition['request_uri'], [
        '{gallery_path}' => 'seed',
        '{image_slug}' => 'sample-1',
    ]);
    $status = $definition['expected_outcome'] === 'success' ? 200 : 302;
    $metric = [
        'schema_version' => 1,
        'scope' => 'cms-run-route-lifecycle',
        'probe' => $probeId,
        'route' => $definition['route'],
        'request_uri' => $requestUri,
        'auth_mode' => $definition['auth_mode'],
        'expected_outcome' => $definition['expected_outcome'],
        'actual_outcome' => $definition['expected_outcome'],
        'http_status' => $status,
        'included_php_files' => 3,
        'included_paths' => ['app/bootstrap.php', 'app/bootstrap/dispatch.php', 'public/index.php'],
        'loaded_module_count' => 1,
        'loaded_modules' => ['request-policy'],
        'peak_memory_bytes' => 8388608,
        'wall_ms' => 42.5,
        'php_version' => '8.3.0',
        'php_int_size' => 8,
        'response_body_bytes' => 123,
        'response_body_sha256' => str_repeat('a', 64),
        'response_contract_matches' => true,
        'fatal_error' => false,
    ];
    $expect(\PhpGallery\Audit\route_metric_problems($metric, $probeId, $definition) === [],
        'A valid route measurement must pass: ' . $probeId);

    $observationalWall = $metric;
    $observationalWall['wall_ms'] = 99999999999.0;
    $expect(\PhpGallery\Audit\route_metric_problems($observationalWall, $probeId, $definition) === [],
        'Finite route wall time must remain observational: ' . $probeId);

    $wrongIdentity = $metric;
    $wrongIdentity['route'] = 'wrong-route';
    $expect(\PhpGallery\Audit\route_metric_problems($wrongIdentity, $probeId, $definition) !== [],
        'Route identity mismatch must fail: ' . $probeId);

    $wrongOutcome = $metric;
    $wrongOutcome['actual_outcome'] = $definition['expected_outcome'] === 'success' ? 'denied' : 'success';
    $expect(\PhpGallery\Audit\route_metric_problems($wrongOutcome, $probeId, $definition) !== [],
        'Unexpected success or denial must fail: ' . $probeId);

    $wrongAuth = $metric;
    $wrongAuth['auth_mode'] = $definition['auth_mode'] === 'admin' ? 'anonymous' : 'admin';
    $expect(\PhpGallery\Audit\route_metric_problems($wrongAuth, $probeId, $definition) !== [],
        'Authentication context mismatch must fail: ' . $probeId);
    $emptyBody = $metric;
    $emptyBody['response_body_bytes'] = 0;
    $expect((\PhpGallery\Audit\route_metric_problems($emptyBody, $probeId, $definition) === [])
        === ($definition['expected_outcome'] === 'denied'),
        'A silent success must fail while an empty denial redirect remains valid: ' . $probeId);
    if ($definition['expected_outcome'] === 'success') {
        $expect(!\PhpGallery\Audit\route_response_body_matches('<html><body>Internal error</body></html>', $definition),
            'A generic error page must fail the successful response contract: ' . $probeId);
        $wrongBody = $metric;
        $wrongBody['response_contract_matches'] = false;
        $expect(\PhpGallery\Audit\route_metric_problems($wrongBody, $probeId, $definition) !== [],
            'HTTP 200 must not override a failed product response contract: ' . $probeId);
    }
}

$robots = $registry['robots'];
$base = [
    'schema_version' => 1,
    'scope' => 'cms-run-route-lifecycle',
    'probe' => 'robots',
    'route' => 'robots',
    'request_uri' => '/robots.txt',
    'auth_mode' => 'anonymous',
    'expected_outcome' => 'success',
    'actual_outcome' => 'success',
    'http_status' => 200,
    'included_php_files' => 2,
    'included_paths' => ['app/bootstrap.php', 'public/index.php'],
    'loaded_module_count' => 1,
    'loaded_modules' => ['request-policy'],
    'peak_memory_bytes' => 8388608,
    'wall_ms' => 1.0,
    'php_version' => '8.3.0',
    'php_int_size' => 8,
    'response_body_bytes' => 74,
    'response_body_sha256' => str_repeat('b', 64),
    'response_contract_matches' => true,
    'fatal_error' => false,
];

$overIncludes = $base;
$overIncludes['included_php_files'] = $robots['max_included_php_files'] + 1;
$overIncludes['included_paths'] = ['public/index.php'];
for ($index = 1; $index < $overIncludes['included_php_files']; $index++) {
    $overIncludes['included_paths'][] = 'app/bootstrap/synthetic-' . $index . '.php';
}
$expect(\PhpGallery\Audit\route_metric_problems($overIncludes, 'robots', $robots) !== [],
    'Included-file ceiling and route ratchet must be hard failures.');

$overMemory = $base;
$overMemory['peak_memory_bytes'] = $robots['max_peak_memory_bytes'] + 1;
$expect(\PhpGallery\Audit\route_metric_problems($overMemory, 'robots', $robots) !== [],
    'Peak-memory ceiling and route ratchet must be hard failures.');

$badSchema = $base;
$badSchema['schema_version'] = 2;
$expect(\PhpGallery\Audit\route_metric_problems($badSchema, 'robots', $robots) !== [],
    'Unknown metric schema version must fail.');

$badPath = $base;
$badPath['included_paths'] = ['tests/private.php', 'public/index.php'];
$expect(\PhpGallery\Audit\route_metric_problems($badPath, 'robots', $robots) !== [],
    'Non-application include paths must fail.');

$duplicatePath = $base;
$duplicatePath['included_paths'] = ['app/bootstrap.php', 'app/bootstrap.php'];
$expect(\PhpGallery\Audit\route_metric_problems($duplicatePath, 'robots', $robots) !== [],
    'Duplicate include paths must fail.');

$duplicateModules = $base;
$duplicateModules['loaded_module_count'] = 2;
$duplicateModules['loaded_modules'] = ['request-policy', 'request-policy'];
$expect(\PhpGallery\Audit\route_metric_problems($duplicateModules, 'robots', $robots) !== [],
    'Duplicate loaded logical modules must fail.');

$fatalMetric = $base;
$fatalMetric['fatal_error'] = true;
$expect(\PhpGallery\Audit\route_metric_problems($fatalMetric, 'robots', $robots) !== [],
    'Fatal route execution must fail.');

$badBodyHash = $base;
$badBodyHash['response_body_sha256'] = 'not-a-hash';
$expect(\PhpGallery\Audit\route_metric_problems($badBodyHash, 'robots', $robots) !== [],
    'Malformed response digest must fail.');

$missingBodyContract = $base;
unset($missingBodyContract['response_contract_matches']);
$expect(\PhpGallery\Audit\route_metric_problems($missingBodyContract, 'robots', $robots) !== [],
    'A route without semantic response evidence must fail.');
$expect(\PhpGallery\Audit\route_response_body_matches("User-agent: *\nAllow: /\nDisallow: /admin\nSitemap: http://fixture/sitemap.xml\n", $robots),
    'A valid robots policy must satisfy its content contract.');
$sampleImage = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aLioAAAAASUVORK5CYII=', true);
$expect(is_string($sampleImage) && \PhpGallery\Audit\route_response_body_matches($sampleImage, $registry['thumb']),
    'A genuine raster payload must satisfy the media content contract.');
foreach (['home', 'gallery', 'admin', 'admin_telemetry'] as $htmlProbe) {
    $htmlBody = '<section ' . implode(' ', $registry[$htmlProbe]['response_markers']) . '></section>';
    $expect(\PhpGallery\Audit\route_response_body_matches($htmlBody, $registry[$htmlProbe]),
        'Stable product page markers must satisfy the registered HTML contract: ' . $htmlProbe);
}

$wrongStatus = $base;
$wrongStatus['http_status'] = 404;
$expect(\PhpGallery\Audit\route_metric_problems($wrongStatus, 'robots', $robots) !== [],
    'A denied response cannot satisfy a successful route probe.');

$wrongCount = $base;
$wrongCount['included_php_files'] = 1;
$expect(\PhpGallery\Audit\route_metric_problems($wrongCount, 'robots', $robots) !== [],
    'Include count must match the recorded inventory.');

$badWall = $base;
$badWall['wall_ms'] = INF;
$expect(\PhpGallery\Audit\route_metric_problems($badWall, 'robots', $robots) !== [],
    'Non-finite route wall time must fail schema validation.');

echo "PASS route lifecycle metric schema and deterministic guards\n";
