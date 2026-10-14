<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/cache_lifecycle_public_http_fixture.php
 * Module Type: Hosted HTTP Regression Test
 * Purpose: Prove ordinary public requests advance automatic retention without Admin, cron, response finishing or host disk-space functions.
 * Responsibilities: Exercise the actual server trigger on fixed loopback port and disposable storage only; local verification remains the requested installed Codex browser.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Read the parent fixture's preference for passive status evidence.
     * @param string $key Canonical setting identity.
     * @param string|null $default Missing preference fallback.
     * @return string|null Explicit fixture OFF or the supplied fallback; no real database is read.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $key === 'feature_flag.automatic_cache_cleanup.enabled' && is_file(CACHE_LIFECYCLE_TEST_ROOT . '/off') ? '0' : $default;
    }
}
namespace {

/**
 * Pace the bounded hosted-only HTTP server readiness probes.
 * Type: int. Units: microseconds. Scope: up to eighty startup probes.
 * Consumers: disposable cache HTTP fixture startup.
 * Rationale: 25 ms polling permits two seconds of pauses without busy-spinning or changing production timing.
 */
const CACHE_PUBLIC_STARTUP_POLL_US = 25000;

/**
 * Assert one hosted public-request postcondition.
 * @param bool $condition Required observed behavior.
 * @param string $message Failure explanation.
 * @return void Throws for failed HTTP/cache assertions.
 */
function cache_public_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
if (getenv('CI') !== 'true') {
    echo "SKIP hosted-only cache HTTP fixture; local browser verification uses http://localhost/Galerie.\n";
    exit(0);
}
$restrictedCapacity = in_array('--no-disk-free-space', $_SERVER['argv'] ?? [], true);
cache_public_assert(!$restrictedCapacity || !function_exists('disk_free_space'), 'Restricted parent still exposes the native disk-space function.');
$root = sys_get_temp_dir() . '/gallery-cache-public-' . bin2hex(random_bytes(8));
// Isolate all cache reads and deletion inside this owned random temporary root.
// Type: string. Units: absolute path. Scope: this standalone fixture process.
// Consumers: cache lifecycle path guards. Rationale: the shared test seam must never resolve the live installation cache.
define('CACHE_LIFECYCLE_TEST_ROOT', $root);
mkdir($root . '/cache/map-browser-profile-Web001', 0775, true);
file_put_contents($root . '/.visitor-fixture-owner', 'owned');
$now = time();
file_put_contents($root . '/clock', (string) $now);
file_put_contents($root . '/cache/map-browser-profile-Web001/cache-owner.json', json_encode([
    'schema_version'=>1,'producer'=>'php_gallery_browser_fixture','directory'=>'map-browser-profile-Web001',
    'created_at'=>$now-86401], JSON_THROW_ON_ERROR));
file_put_contents($root . '/cache/map-browser-profile-Web001/payload', 'safe disposable bytes');
mkdir($root . '/cache/unregistered-original', 0775, true);
file_put_contents($root . '/cache/unregistered-original/payload', 'protected original');
file_put_contents($root . '/cache/unregistered-original/.keep', 'protected');
$environment = getenv();
$environment['CACHE_VISITOR_HTTP_ROOT'] = $root;
$serverCommand = [PHP_BINARY];
if ($restrictedCapacity) array_push($serverCommand, '-d', 'disable_functions=disk_free_space');
array_push($serverCommand, '-S', '127.0.0.1:18812', dirname(__DIR__) . '/fixtures/cache_lifecycle_public_router.php');
$process = proc_open($serverCommand,
    [0=>['pipe','r'],1=>['file',$root . '/server.log','a'],2=>['file',$root . '/server.log','a']], $pipes, $root, $environment);
try {
    cache_public_assert(is_resource($process), 'Hosted cache HTTP server did not start.');
    fclose($pipes[0]);
    $url = 'http://127.0.0.1:18812/';
    $context = stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>true]]);
    $body = false;
    for ($attempt = 0; $attempt < 80 && $body === false; $attempt++) {
        $body = @file_get_contents($url, false, $context);
        if ($body === false) usleep(CACHE_PUBLIC_STARTUP_POLL_US);
    }
    cache_public_assert($body === '<main>Public gallery fixture</main>', 'Public response was replaced by maintenance output.');
    cache_public_assert(file_get_contents($root . '/disk-space-capability') === ($restrictedCapacity ? '0' : '1'),
        'HTTP server did not inherit the requested real native-function restriction.');
    require_once dirname(__DIR__, 2) . '/app/services/cache_lifecycle.php';
    foreach (['HEAD', 'POST', 'denied'] as $transport) {
        $checkpoint = file_get_contents($root . '/cache/cache-lifecycle/state.json');
        $now += 61;
        file_put_contents($root . '/clock', (string) $now);
        @touch($root . '/cache/site-maintenance/visitor-attempt', time()-61);
        $excluded = stream_context_create(['http'=>['method'=>$transport === 'denied' ? 'GET' : $transport,
            'timeout'=>3,'ignore_errors'=>true]]);
        file_get_contents($url . ($transport === 'denied' ? '?denied=1' : ''), false, $excluded);
        cache_public_assert(file_get_contents($root . '/cache/cache-lifecycle/state.json') === $checkpoint,
            'Excluded HEAD/POST/error response advanced automatic cache work: ' . $transport);
    }
    // Each arrival advances the fixture clock and expires only its throttle;
    // no admin route, token, cron invocation or browser JavaScript is involved.
    for ($visit = 0; $visit < 30; $visit++) {
        $status = \Gallery\Services\cache_lifecycle_status();
        if ($status['phase'] === 'complete') break;
        $now += 61;
        file_put_contents($root . '/clock', (string) $now);
        @touch($root . '/cache/site-maintenance/visitor-attempt', time()-61);
        $start = microtime(true);
        $body = file_get_contents($url, false, $context);
        cache_public_assert($body === '<main>Public gallery fixture</main>' && microtime(true)-$start < 1.5,
            'No-detachment public response was blocked by a long worker.');
    }
    $status = \Gallery\Services\cache_lifecycle_status();
    cache_public_assert($status['phase'] === 'complete' && $status['bytes_removed'] > 0
        && $status['scheduler_source'] === 'public_visit', 'Real public visits did not finish safe cache work without cron/Admin.');
    cache_public_assert(!is_dir($root . '/cache/map-browser-profile-Web001')
        && file_get_contents($root . '/cache/unregistered-original/payload') === 'protected original',
        'Restricted hosting cleanup did not reclaim verified expired data or deleted protected originals.');
    $scheduler = \Gallery\Services\cache_lifecycle_read_json($root . '/cache/cache-lifecycle/scheduler.json');
    cache_public_assert(!$restrictedCapacity || $scheduler['free_space_known'] === false && $scheduler['free_bytes'] === null,
        'Unavailable hosting capacity was reported as a measured free-space value.');
    file_put_contents($root . '/off', '0');
    $state = file_get_contents($root . '/cache/cache-lifecycle/state.json');
    @touch($root . '/cache/site-maintenance/visitor-attempt', time()-61);
    file_get_contents($url, false, $context);
    cache_public_assert(file_get_contents($root . '/cache/cache-lifecycle/state.json') === $state, 'Explicit OFF was ignored by the real HTTP registration.');
    echo "Public HTTP opportunities without Admin, cron, JavaScript or response detachment: PASS\n";
    if ($restrictedCapacity) echo "Native disk_free_space disabled in parent and HTTP worker; automatic deletion and protected data: PASS\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    require_once dirname(__DIR__, 2) . '/app/services/cache_lifecycle.php';
    for ($step = 0; $step < 100 && is_dir($root); $step++) \Gallery\Services\application_update_delete_tree_slice($root, 5000, 0.5);
}
}
