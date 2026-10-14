<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cache_lifecycle_scheduler_test.php
 * Module Type: Regression Test
 * Purpose: Qualify browser-independent bounded inventory/reclamation on disposable storage.
 * Responsibilities: Exercise early cleanup, fairness, scheduler gaps, stale manual IDs and crash recovery without touching installation payloads.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Enable the isolated fixture's canonical automatic cache capability.
     * @param string $key Capability identity selected by the lifecycle owner.
     * @return bool True only for the fixture's automatic cache capability.
     */
    function feature_capability_effective_enabled(string $key): bool { return $key === 'automatic_cache_cleanup'; }
    /**
     * Provide the fixture's empty optional beta recovery preference.
     * @param string $key Requested setting identity.
     * @param string|null $default Fallback setting value.
     * @return string|null Empty recovery reference or the supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $key === 'application_update_beta_backup_path' ? '' : $default;
    }
}
namespace {
    /**
     * Assert a scheduled lifecycle safety or liveness invariant.
     * @param bool $condition Observed invariant result.
     * @param string $message Failure explanation.
     * @return void Throws when the expected behavior is absent.
     */
    function scheduler_assert(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }
    $root = sys_get_temp_dir() . '/gallery-cache-scheduler-' . bin2hex(random_bytes(8));
    // Isolate every lifecycle read and deletion from real installation storage.
    // Type: string. Units: absolute directory path. Scope: this disposable process.
    // Consumers: cache lifecycle path guards. Rationale: random temporary ownership permits safe scheduler/crash fixtures.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    $GLOBALS['cache_lifecycle_test_now'] = time() + 172800;
    mkdir($root . '/cache', 0775, true);
    require_once __DIR__ . '/../app/services/cache_lifecycle.php';
    try {
        foreach ([0, \Gallery\Services\CACHE_LIFECYCLE_RESERVE_BYTES - 1] as $lowBytes) {
            $refused = \Gallery\Services\cache_lifecycle_scheduled_burst(\Gallery\Services\CACHE_LIFECYCLE_COMBINED_SECONDS, 'cli_cron', 1,
                static fn(string $path): int => $lowBytes);
            scheduler_assert(!$refused['ok'] && $refused['reason'] === 'cache_checkpoint_space_unavailable'
                && $refused['free_space_known'] && $refused['free_bytes'] === $lowBytes
                && !is_file($root . '/cache/cache-lifecycle/state.json'), 'Known-low capacity started inventory or pretended capacity was unknown.');
        }
        foreach ([static fn(string $path): bool => false,
            static function (string $path): float|false { throw new \RuntimeException('Unavailable fixture capacity.'); }] as $probe) {
            $capacity = \Gallery\Services\cache_lifecycle_checkpoint_capacity($probe);
            scheduler_assert(!$capacity['known'] && $capacity['bytes'] === null && $capacity['admitted'],
                'Unavailable capacity blocked checkpoint-guarded cleanup or invented free bytes.');
        }
        scheduler_assert(\Gallery\Services\cache_lifecycle_checkpoint_capacity(
            static fn(string $path): int => \Gallery\Services\CACHE_LIFECYCLE_RESERVE_BYTES)['admitted'],
            'Exact known capacity threshold was rejected.');
        for ($index = 0; $index < 120; $index++) {
            $directory = 'map-browser-profile-' . sprintf('%06d', $index);
            mkdir($root . '/cache/' . $directory);
            file_put_contents($root . '/cache/' . $directory . '/cache-owner.json', json_encode([
                'schema_version'=>1,'producer'=>'php_gallery_browser_fixture','directory'=>$directory,
                'created_at'=>$GLOBALS['cache_lifecycle_test_now']-86400], JSON_THROW_ON_ERROR));
            for ($file = 0; $file < 45; $file++) file_put_contents($root . '/cache/' . $directory . '/item-' . $file, 'payload');
        }
        $report = ['schema_version'=>1,'status'=>'PASS','started_at'=>'2026-10-01T00:00:00Z','duration_seconds'=>1,
            'source'=>[],'report_files'=>['run_directory'=>'cache/test-audit/20261001-000000-123']];
        mkdir($root . '/cache/cache-cleaning-ci-overlap/child', 0775, true);
        file_put_contents($root . '/cache/cache-cleaning-ci-overlap/child/latest.json', json_encode($report, JSON_THROW_ON_ERROR));
        $manual = \Gallery\Services\cache_lifecycle_inventory_start();
        $manualId = $manual['id'];
        scheduler_assert(\Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron', 2)['reason'] === 'manual_cache_preview_pending', 'Fresh manual scan was silently approved.');
        $GLOBALS['cache_lifecycle_test_now'] += 3601;
        $result = \Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron', 2);
        scheduler_assert(!\Gallery\Services\cache_lifecycle_inventory_slice($manualId)['ok'], 'Expired manual identity stayed valid.');
        scheduler_assert(!\Gallery\Services\cache_lifecycle_cleanup_start($manualId)['ok'], 'Expired manual approval stayed valid.');
        $state = \Gallery\Services\cache_lifecycle_read_json($root . '/cache/cache-lifecycle/state.json');
        for ($step = 0; $step < 100 && $state['phase'] === 'inventory'; $step++) $state = \Gallery\Services\cache_lifecycle_inventory_slice();
        if ($state['phase'] === 'preview') $state = \Gallery\Services\cache_lifecycle_cleanup_start($state['id']);
        scheduler_assert($state['phase'] === 'cleanup', 'Fixture did not reach an approved pending queue.');
        $queuedBefore = (int) $state['candidates'];
        // Changing producer proof between rounds must not allow a parent plan
        // to overlap the already committed child candidate.
        file_put_contents($root . '/cache/cache-cleaning-ci-overlap/latest.json', json_encode($report, JSON_THROW_ON_ERROR));
        $GLOBALS['cache_lifecycle_test_now'] += 3601;
        $result = \Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron', 2);
        $state = \Gallery\Services\cache_lifecycle_read_json($root . '/cache/cache-lifecycle/state.json');
        scheduler_assert((int) ($state['observation_round'] ?? 0) === 2 && $state['candidates'] === $queuedBefore,
            'Long deletion queue blocked fresh observation or duplicated committed candidates.');
        scheduler_assert($state['totals']['workspaces']['reclaimable_bytes'] > 0, 'Fresh observation hid eligible bytes merely because their paths were already queued.');
        $early = false;
        $scanProgress = false;
        $previousFiles = 0;
        for ($run = 0; $run < 180; $run++) {
            $status = \Gallery\Services\cache_lifecycle_status();
            $early = $early || ($status['phase'] === 'inventory' && $status['bytes_removed'] > 0);
            $scanProgress = $scanProgress || ($early && $status['files'] > $previousFiles);
            $previousFiles = $status['files'];
            scheduler_assert($result['slices'] <= \Gallery\Services\CACHE_LIFECYCLE_RUN_SLICES, 'Invocation exceeded its slice cap.');
            if ($status['phase'] === 'complete') break;
            $GLOBALS['cache_lifecycle_test_now'] += 61;
            $result = \Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron', 2);
            scheduler_assert(!empty($result['ok']), 'Scheduled fixture failed safely instead of advancing.');
        }
        scheduler_assert($early && $scanProgress, 'Reclamation waited for full inventory or starved fresh observations.');
        scheduler_assert($status['phase'] === 'complete' && $status['bytes_removed'] > 0 && $status['pending_bytes'] === 0 && $status['pending_candidates'] === 0, 'Completed cleanup retained a fictitious backlog.');
        scheduler_assert($status['candidate_bytes'] > 0 && $status['superseded_manual_at'] > 0, 'Historical measurement or explicit supersession evidence was lost.');
        scheduler_assert(is_file($root . '/cache/cache-cleaning-ci-overlap/latest.json') && is_dir($root . '/cache/cache-cleaning-ci-overlap/child') && $status['skipped'] > 0,
            'Changed parent ownership did not preserve and refuse its previously queued child.');
        $before = file_get_contents($root . '/cache/cache-lifecycle/state.json');
        $notDue = \Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron', 2);
        scheduler_assert(($notDue['skipped'] ?? false) === true && file_get_contents($root . '/cache/cache-lifecycle/state.json') === $before, 'A normal burst globally bypassed refresh throttling.');
        $GLOBALS['cache_lifecycle_test_now'] += 3601;
        scheduler_assert(\Gallery\Services\cache_lifecycle_status()['scheduler_health'] === 'missing', 'Scheduler outage was reported healthy.');
        $lock = fopen($root . '/cache/cache-lifecycle/scheduler.lock', 'c');
        flock($lock, LOCK_EX);
        scheduler_assert(\Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron', 2)['reason'] === 'cache_scheduler_busy', 'Concurrent burst was admitted.');
        flock($lock, LOCK_UN);
        fclose($lock);

        // Simulate death after a committed handoff and atomic detachment but
        // before the current deletion cursor is persisted. Deterministic trash
        // identity must recover the payload even though the source is absent.
        $directory = 'map-browser-profile-Crash1';
        mkdir($root . '/cache/' . $directory);
        file_put_contents($root . '/cache/' . $directory . '/cache-owner.json', json_encode([
            'schema_version'=>1,'producer'=>'php_gallery_browser_fixture','directory'=>$directory,
            'created_at'=>$GLOBALS['cache_lifecycle_test_now']-86400], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/cache/' . $directory . '/payload', 'recover');
        $state = \Gallery\Services\cache_lifecycle_inventory_start(true);
        $marker = $root . '/cache/cache-lifecycle/work/queued/' . hash('sha256', $directory) . '.json';
        \Gallery\Services\cache_lifecycle_write_json($marker, ['id'=>$state['id'],'offset'=>0]);
        scheduler_assert(!\Gallery\Services\cache_lifecycle_queue_contains($marker, $state), 'Uncommitted handoff marker suppressed recovery.');
        for ($step = 0; $step < 30 && $state['phase'] === 'inventory'; $step++) $state = \Gallery\Services\cache_lifecycle_inventory_slice();
        $line = file($root . '/cache/cache-lifecycle/work/plan.jsonl')[0] ?? '';
        $candidate = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        $target = $root . '/cache/cache-lifecycle/work/trash/' . hash('sha256', $candidate['path']);
        scheduler_assert(\Gallery\Services\cache_lifecycle_stage_candidate($candidate, $target), 'Crash fixture detachment failed.');
        // Simulate unlink followed by process death before byte/cursor commit.
        // Resumption must finish the remaining payload without double-counting.
        $lost = ['removed'=>0,'cursor'=>[]];
        for ($step = 0; $step < 20 && $lost['removed'] === 0; $step++) $lost = \Gallery\Services\application_update_delete_tree_slice($target, 3, 0.1, $lost['cursor']);
        scheduler_assert($lost['removed'] > 0, 'Unlink/checkpoint crash fixture made no physical progress.');
        file_put_contents($root . '/cache/cache-lifecycle/work/plan.jsonl', "uncommitted crash tail\n", FILE_APPEND);
        $state = \Gallery\Services\cache_lifecycle_cleanup_start($state['id']);
        for ($step = 0; $step < 30 && $state['phase'] === 'cleanup'; $step++) $state = \Gallery\Services\cache_lifecycle_cleanup_slice();
        scheduler_assert($state['phase'] === 'complete' && !file_exists($target), 'Detached candidate or uncommitted journal tail broke recovery.');
        $schedulerPath = $root . '/cache/cache-lifecycle/scheduler.json';
        $record = \Gallery\Services\cache_lifecycle_read_json($schedulerPath);
        $record['running'] = true;
        \Gallery\Services\cache_lifecycle_write_json($schedulerPath, $record);
        \Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron', 2);
        scheduler_assert(\Gallery\Services\cache_lifecycle_status()['interrupted'], 'Interrupted invocation evidence was not retained on resume.');
        file_put_contents($root . '/cache/cache-lifecycle/state.json', '{broken');
        $refused = false;
        try { \Gallery\Services\cache_lifecycle_inventory_start(true); } catch (\RuntimeException) { $refused = true; }
        scheduler_assert($refused, 'Corrupt state was silently replaced.');
        echo "Scheduled cache lifecycle early cleanup, fairness, bounds, gaps and crash recovery: PASS\n";
    } finally {
        // Only this random disposable root is passed to the existing deletion owner.
        for ($step = 0; $step < 200 && is_dir($root); $step++) \Gallery\Services\application_update_delete_tree_slice($root, 5000, 0.5);
    }
}
