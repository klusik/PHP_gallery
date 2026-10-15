<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cache_lifecycle_visitor_test.php
 * Module Type: Regression Test
 * Purpose: Prove bounded automatic opportunities with disposable few-file and many-file backlogs.
 * Responsibilities: Exercise fallback budgets, throttling, foreground contention, opt-out, thirty-second due dates and resumed metrics without live cache deletion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Read the fixture's persisted preferences without a production database.
     * @param string $key Canonical preference key.
     * @param string|null $default Missing-value fallback.
     * @return string|null Fixture preference or supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['visitor_settings'][$key] ?? ($key === 'application_update_beta_backup_path' ? '' : $default);
    }
    /**
     * Resolve the fixture's canonical automatic capability preference.
     * @param string $key Canonical capability identity.
     * @return bool True only for automatic cleanup when not explicitly disabled.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $key === 'automatic_cache_cleanup' && app_setting('feature_flag.automatic_cache_cleanup.enabled', '1') !== '0';
    }
}
namespace Gallery\Models {
    /**
     * Expose a disposable central maintenance claim or unknown observation.
     * @param bool $requireKnown Strict observation requested by the real visitor owner.
     * @return array{id:int}|null Fake claim or no claim; throws for simulated unknown storage.
     */
    function maintenance_center_model_mutation_owner(bool $requireKnown = false): ?array
    {
        if (!empty($GLOBALS['visitor_unknown']) && $requireKnown) throw new \RuntimeException('Unknown fixture claim.');
        return !empty($GLOBALS['visitor_claim']) ? ['id'=>1] : null;
    }
}
namespace {
    /**
     * Consume admission headroom deterministically without changing worker budgets.
     * Type: int. Units: microseconds. Scope: this isolated visitor fixture.
     * Consumers: the injected capacity probe for the exhausted-admission assertion.
     * Rationale: 30000 microseconds leaves less than the required 20 ms headroom in a 40 ms opportunity.
     */
    const VISITOR_EXHAUST_ADMISSION_US = 30000;
    /**
     * Require one lifecycle invariant with useful failure context.
     * @param bool $condition Expected invariant.
     * @param string $message Failure description.
     * @return void Throws on a failed assertion.
     */
    function visitor_assert(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }
    /**
     * Create one verified expired browser workspace with a sparse sized payload.
     * @param string $root Disposable installation directory.
     * @param string $name Registered six-character browser-workspace suffix.
     * @param int $bytes File's logical size without allocating a large cache.
     * @param int $files Number of tiny additional files for the operation-bound scenario.
     * @return void Writes producer proof and synthetic files only inside the random temporary root.
     */
    function visitor_workspace(string $root, string $name, int $bytes, int $files = 0): void
    {
        $relative = 'map-browser-profile-' . $name;
        $directory = $root . '/cache/' . $relative;
        mkdir($directory, 0775, true);
        file_put_contents($directory . '/cache-owner.json', json_encode(['schema_version'=>1,
            'producer'=>'php_gallery_browser_fixture','directory'=>$relative,
            'created_at'=>$GLOBALS['cache_lifecycle_test_now']-86400], JSON_THROW_ON_ERROR));
        $stream = fopen($directory . '/payload', 'wb');
        ftruncate($stream, $bytes);
        fclose($stream);
        for ($index = 0; $index < $files; $index++) file_put_contents($directory . '/tiny-' . $index, 'x');
    }
    $root = sys_get_temp_dir() . '/gallery-cache-visitors-' . bin2hex(random_bytes(8));
    // Isolate all cache reads and deletion inside this owned random temporary root.
    // Type: string. Units: absolute path. Scope: this standalone fixture process.
    // Consumers: cache lifecycle path guards. Rationale: the shared test seam must never resolve the live installation cache.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    $GLOBALS['cache_lifecycle_test_now'] = time() + 172800;
    mkdir($root . '/cache/site-maintenance', 0775, true);
    require_once __DIR__ . '/../app/services/cache_lifecycle.php';
    try {
        visitor_workspace($root, 'Small1', 100 * 1024 * 1024);
        $result = \Gallery\Services\cache_lifecycle_visitor_run(true);
        $status = \Gallery\Services\cache_lifecycle_status();
        visitor_assert($result['ok'] && $result['slices'] <= 8 && $result['elapsed_ms'] < 1800, 'Small detached opportunity exceeded admission or made no attempt.');
        visitor_assert($status['phase'] === 'complete' && $status['bytes_removed'] >= 100 * 1024 * 1024, 'Few-file 100 MiB backlog did not finish in one short opportunity.');
        visitor_assert($status['candidate_bytes'] >= $status['bytes_removed'] && $status['pending_bytes'] === 0, 'Historical bytes and real backlog were conflated.');
        visitor_assert($status['next_due'] === $GLOBALS['cache_lifecycle_test_now'] + 30, 'Finished inventory was not due in thirty seconds.');
        visitor_assert(\Gallery\Services\cache_lifecycle_visitor_run(true)['reason'] === 'cache_visitor_throttled', 'Concurrent visitor bypassed installation throttle.');
        $id = $status['id'];
        $GLOBALS['cache_lifecycle_test_now'] += 29;
        visitor_assert(\Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron')['reason'] === 'cache_lifecycle_not_due', 'A worker ignored the thirty-second due date.');
        visitor_assert(\Gallery\Services\cache_lifecycle_status()['id'] === $id, 'A not-due visit restarted the completed scan.');

        // No arrivals means no work; the next suitable arrival resumes the owner.
        $GLOBALS['cache_lifecycle_test_now'] += 7200;
        visitor_workspace($root, 'Large1', 150 * 1024 * 1024, 1400);
        $result = \Gallery\Services\cache_lifecycle_visitor_run(true);
        $status = \Gallery\Services\cache_lifecycle_status();
        $id = $status['id'];
        visitor_assert($status['phase'] !== 'complete' && $result['slices'] <= 8, 'Large many-file backlog was unboundedly drained.');
        $GLOBALS['cache_lifecycle_test_now'] += 7200;
        $result = \Gallery\Services\cache_lifecycle_visitor_run(false);
        visitor_assert($result['slices'] <= 2 && $result['elapsed_ms'] < 1200, 'No-detachment fallback exceeded its short opportunity.');
        visitor_assert(\Gallery\Services\cache_lifecycle_status()['id'] === $id, 'An outage interval discarded the unfinished scan checkpoint.');
        for ($run = 0; $run < 140 && \Gallery\Services\cache_lifecycle_status()['phase'] !== 'complete'; $run++) {
            $GLOBALS['cache_lifecycle_test_now'] += 61;
            $result = \Gallery\Services\cache_lifecycle_visitor_run(true);
            visitor_assert(!empty($result['ok']) && ($result['slices'] ?? 0) <= 8, 'A resumed visitor failed or exceeded slices.');
        }
        visitor_assert(\Gallery\Services\cache_lifecycle_status()['phase'] === 'complete',
            'Large backlog did not resume to completion: ' . json_encode(array_diff_key(\Gallery\Services\cache_lifecycle_status(), ['policies'=>true,'totals'=>true,'sample'=>true]), JSON_THROW_ON_ERROR));

        $GLOBALS['cache_lifecycle_test_now'] += 3601;
        visitor_workspace($root, 'Tiny01', 1024, 1600);
        $result = \Gallery\Services\cache_lifecycle_visitor_run(true);
        visitor_assert(\Gallery\Services\cache_lifecycle_status()['phase'] !== 'complete', 'Small byte size bypassed the many-file operation budget.');
        for ($run = 0; $run < 100 && \Gallery\Services\cache_lifecycle_status()['phase'] !== 'complete'; $run++) {
            $GLOBALS['cache_lifecycle_test_now'] += 61;
            \Gallery\Services\cache_lifecycle_visitor_run(true);
        }
        visitor_assert(\Gallery\Services\cache_lifecycle_status()['phase'] === 'complete', 'Small many-file backlog did not resume.');
        $GLOBALS['cache_lifecycle_test_now'] += 3601;
        $foreground = fopen($root . '/cache/site-maintenance/visitor.lock', 'c');
        flock($foreground, LOCK_SH);
        visitor_assert(\Gallery\Services\cache_lifecycle_visitor_run(true)['reason'] === 'foreground_requests_active', 'Worker did not yield to another foreground visitor.');
        $GLOBALS['cache_lifecycle_test_now'] += 91;
        visitor_workspace($root, 'Starv1', 1024);
        // Slow admission may consume a forty-millisecond opportunity before a slice can start.
        // The existing capacity seam makes that boundary deterministic without widening production budgets.
        $beforeStarved = \Gallery\Services\cache_lifecycle_read_json($root . '/cache/cache-lifecycle/state.json');
        $exhausted = \Gallery\Services\cache_lifecycle_scheduled_burst(0.04, 'cache_chain', 1, static function (string $path): int {
            usleep(VISITOR_EXHAUST_ADMISSION_US);
            return 1073741824;
        }, true);
        visitor_assert(!empty($exhausted['ok']) && $exhausted['slices'] === 0 && empty($exhausted['progressed'])
            && $exhausted['elapsed_ms'] < 1200
            && \Gallery\Services\cache_lifecycle_read_json($root . '/cache/cache-lifecycle/state.json') === $beforeStarved,
            'Exhausted admission invented progress, changed a checkpoint or exceeded its short opportunity.');
        $starvedProgress = false;
        for ($opportunity = 0; $opportunity < 8; $opportunity++) {
            $starved = \Gallery\Services\cache_lifecycle_visitor_run(true, true);
            visitor_assert(!empty($starved['ok']) && ($starved['slices'] ?? 0) <= 1 && $starved['elapsed_ms'] < 1200,
                'A starved opportunity exceeded one short slice: ' . json_encode($starved, JSON_THROW_ON_ERROR));
            if (!empty($starved['progressed'])) { $starvedProgress = true; break; }
            $GLOBALS['cache_lifecycle_test_now'] += 61;
        }
        visitor_assert($starvedProgress, 'Bounded starved opportunities never advanced a durable checkpoint.');
        flock($foreground, LOCK_UN);
        fclose($foreground);
        $GLOBALS['cache_lifecycle_test_now'] += 61;
        $GLOBALS['visitor_claim'] = true;
        visitor_assert(\Gallery\Services\cache_lifecycle_visitor_run(true)['reason'] === 'central_maintenance_active', 'Worker bypassed central maintenance ownership.');
        $GLOBALS['visitor_claim'] = false;
        $GLOBALS['visitor_unknown'] = true;
        $GLOBALS['cache_lifecycle_test_now'] += 61;
        visitor_assert(\Gallery\Services\cache_lifecycle_visitor_run(true)['reason'] === 'cache_visitor_exception', 'Unknown central owner failed open.');
        $GLOBALS['visitor_unknown'] = false;
        $GLOBALS['visitor_settings']['feature_flag.automatic_cache_cleanup.enabled'] = '0';
        visitor_assert(\Gallery\Services\cache_lifecycle_visitor_run(true)['reason'] === 'automatic_cache_cleanup_disabled', 'Explicit OFF did not stop automatic work.');
        visitor_assert(\Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron')['reason'] === 'automatic_cache_cleanup_disabled', 'Optional cron ignored explicit OFF.');
        echo "Visitor cache bounds, thirty-second due dates, fallback, contention, opt-out and real metrics: PASS\n";
    } finally {
        for ($step = 0; $step < 200 && is_dir($root); $step++) \Gallery\Services\application_update_delete_tree_slice($root, 5000, 0.5);
    }
}
