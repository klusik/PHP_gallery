<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/updater_cache_gc_test.php
 * Module Type: Regression Test
 * Purpose: Exercise independent updater trash reclamation on a disposable installation.
 * Responsibilities: Verify locks, checkpoint progress, byte accounting and protected data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Resolve the isolated fixture's cache capability and explicit updater-transition authority.
     * @param string $key Capability identity requested by the scheduled owner.
     * @return bool True for the fixture's enabled updater installer, or automatic cleanup unless explicitly disabled; other capabilities remain refused.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $key === 'built_in_update_installer'
            || $key === 'automatic_cache_cleanup' && empty($GLOBALS['cache_gc_disabled']);
    }
    /**
     * Disable thumbnail maintenance while preserving default fixture settings.
     * @param string $key Requested application setting name.
     * @param string|null $default Fallback when the fixture does not override a key.
     * @return string|null Disabled thumbnail preference or the supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $key === 'site_maintenance_enabled' ? '0' : $default;
    }

}

namespace Gallery\Models {
    /**
     * Expose an optional central Maintenance Center claim in this isolated fixture.
     * @return array{id:int,phase:string}|null Fixture claim or null when unowned.
     */
    function maintenance_center_model_mutation_owner(): ?array
    {
        return $GLOBALS['cache_gc_central_owner'] ?? null;
    }
}

namespace {
    require_once __DIR__ . '/../app/release_file_policy.php';
    require_once __DIR__ . '/../app/services/updates_path_safety.php';
    require_once __DIR__ . '/../app/services/updates_filesystem.php';
    require_once __DIR__ . '/../app/services/updates_jobs.php';

    use function Gallery\Services\application_update_cache_cleanup_status;
    use function Gallery\Services\application_update_delete_tree_slice;
    use function Gallery\Services\application_update_run_scheduled_cache_cleanup;
    use function Gallery\Services\site_maintenance_run;

    /**
     * Fail the regression process when a safety or progress invariant is violated.
     * @param bool $condition Observed invariant result.
     * @param string $message Failure explanation without runtime secrets.
     * @return void Continues on success or throws the failed invariant.
     */
    function assert_cache_gc(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root = sys_get_temp_dir() . '/gallery-cache-gc-' . bin2hex(random_bytes(6));
    $GLOBALS['cache_gc_fixture_root'] = $root;
    // Keep the shared lifecycle owner on the updater's disposable storage root.
    // Type: string. Units: absolute directory path. Scope: this updater-GC fixture.
    // Consumers: lifecycle path guards. Rationale: shared ownership checks must never inspect or reclaim installation cache during this test.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    mkdir($root, 0775, true);
    try {
        $preview = application_update_cache_cleanup_status();
        assert_cache_gc(!$preview['pending'] && !file_exists($root . '/cache'), 'Read-only preview created runtime directories.');
        $absent = application_update_run_scheduled_cache_cleanup();
        assert_cache_gc($absent['done'] && $absent['skipped'], 'Absent cache was not safely skipped.');

        $workspace = $root . '/cache/updates';
        $trash = $workspace . '/prune-trash';
        mkdir($trash . '/current/terminal/extract', 0775, true);
        mkdir($workspace . '/jobs/protected/rollback', 0775, true);
        mkdir($root . '/cache/gallery-trash', 0775, true);
        file_put_contents($workspace . '/jobs/protected/rollback/original.zip', 'keep rollback');
        file_put_contents($root . '/cache/gallery-trash/original.jpg', 'keep user content');
        file_put_contents($root . '/cache/unknown.zip', 'keep unknown');
        $expectedBytes = 0;
        for ($index = 0; $index < 10000; $index++) {
            $payload = 'deferred-' . $index;
            $expectedBytes += strlen($payload);
            file_put_contents($trash . '/current/terminal/extract/item-' . $index, $payload);
        }

        file_put_contents($workspace . '/active-job.json', '{broken-pointer');
        $active = application_update_run_scheduled_cache_cleanup(true);
        assert_cache_gc($active['skipped'] && $active['removed'] === 0 && is_dir($trash), 'Unknown active updater state did not block GC.');
        assert_cache_gc(file_get_contents($workspace . '/active-job.json') === '{broken-pointer', 'GC modified an active pointer.');
        $pointerDiagnostic = \Gallery\Services\application_update_cache_blocking_job_diagnostic($root);
        assert_cache_gc($pointerDiagnostic['updater_pointer_state'] === 'pointer_unreadable_or_invalid', 'Corrupt pointer lacks safe diagnostics.');
        $blockingId = '20261014120000-aaaaaaaaaaaa';
        mkdir($workspace . '/jobs/' . $blockingId, 0775, true);
        file_put_contents($workspace . '/jobs/' . $blockingId . '/job.json', json_encode([
            'id' => $blockingId, 'status' => 'completed', 'stage' => 'completed', 'updated_at' => 123, 'secret' => 'PRIVATE_TOKEN']));
        file_put_contents($workspace . '/active-job.json', json_encode(['job_id' => $blockingId]));
        $terminal = \Gallery\Services\application_update_cache_blocking_job_diagnostic($root);
        assert_cache_gc($terminal['updater_job_id'] === $blockingId && $terminal['updater_pointer_state'] === 'terminal_job_requires_owner_review'
            && !str_contains(json_encode($terminal), 'PRIVATE_TOKEN') && is_file($workspace . '/active-job.json'), 'Read-only blocking-job diagnostics changed ownership or exposed state.');
        unlink($workspace . '/active-job.json');
        $lock = fopen($workspace . '/start.lock', 'c');
        assert_cache_gc(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'Cannot hold fixture start lock.');
        $busy = application_update_run_scheduled_cache_cleanup(true);
        assert_cache_gc($busy['skipped'] && $busy['removed'] === 0, 'Concurrent GC bypassed the updater start lock.');
        flock($lock, LOCK_UN);
        fclose($lock);

        $first = application_update_run_scheduled_cache_cleanup(true);
        assert_cache_gc($first['visited'] <= 180 && !$first['done'], 'GC exceeded its entry budget or drained an entire large tree inline.');
        $throttled = application_update_run_scheduled_cache_cleanup();
        assert_cache_gc($throttled['skipped'] && $throttled['removed'] === 0, 'Scheduled GC ignored its due-time throttle.');
        $reclaimed = $first['bytes_removed'];
        for ($slice = 0; $slice < 200 && is_dir($trash); $slice++) {
            // Independent invocations read only the persisted stack, not a PHP iterator.
            $result = application_update_run_scheduled_cache_cleanup(true);
            assert_cache_gc($result['ok'] && $result['visited'] <= 180, 'A continuation slice failed or exceeded its observation budget.');
            $reclaimed += $result['bytes_removed'];
        }
        assert_cache_gc(!is_dir($trash) && $reclaimed === $expectedBytes, 'Deferred queue did not drain with exact actual-byte accounting.');
        $status = application_update_cache_cleanup_status();
        assert_cache_gc(!$status['pending'] && $status['bytes_reclaimed'] === $expectedBytes, 'Committed GC diagnostics lost cumulative reclaimed bytes.');
        assert_cache_gc(is_file($workspace . '/jobs/protected/rollback/original.zip') && is_file($root . '/cache/gallery-trash/original.jpg')
            && is_file($root . '/cache/unknown.zip'), 'GC touched rollback, user content or unknown cache entries.');

        $deepRoot = $root . '/deep';
        $deep = $deepRoot;
        for ($level = 0; $level < 40; $level++) {
            $deep .= '/nested';
        }
        mkdir($deep, 0775, true);
        file_put_contents($deep . '/payload', 'deep payload');
        $cursor = [];
        for ($slice = 0; $slice < 100 && is_dir($deepRoot); $slice++) {
            $result = application_update_delete_tree_slice($deepRoot, 8, 0.18, $cursor);
            assert_cache_gc($result['visited'] <= 8 && !$result['blocked'], 'Deep traversal exceeded its cap or lost forward progress.');
            $cursor = $result['cursor'];
        }
        assert_cache_gc(!is_dir($deepRoot), 'Persistent depth checkpoint did not make progress across short slices.');
        // Keep the real site-maintenance service's fixed-depth lock path inside
        // this disposable installation; execute the original source unchanged.
        mkdir($root . '/app/services', 0775, true);
        copy(__DIR__ . '/../app/services/site_maintenance.php', $root . '/app/services/site_maintenance.php');
        file_put_contents($root . '/app/services/cache_lifecycle.php', '<?php require_once '
            . var_export(dirname(__DIR__) . '/app/services/cache_lifecycle.php', true) . ';');
        file_put_contents($root . '/app/services/cache_lifecycle_storage.php', '<?php require_once '
            . var_export(dirname(__DIR__) . '/app/services/cache_lifecycle_storage.php', true) . ';');
        require $root . '/app/services/site_maintenance.php';
        mkdir($trash, 0775, true);
        file_put_contents($trash . '/cron-debt', 'cron payload');
        unlink($workspace . '/cache-gc.json');
        $cron = site_maintenance_run(['source' => 'fixture_cron', 'time_budget_seconds' => 1]);
        assert_cache_gc($cron['ok'] && ($cron['reason'] ?? '') === 'maintenance_disabled'
            && $cron['cache_gc']['value']['bytes_removed'] === strlen('cron payload') && !is_dir($trash),
            'Disabled thumbnail maintenance prevented independent GC progress.');
        mkdir($trash, 0775, true);
        file_put_contents($trash . '/central-debt', 'preserve while owned');
        $GLOBALS['cache_gc_central_owner'] = ['id' => 12, 'phase' => 'execution'];
        $claimed = site_maintenance_run(['cache_only' => true, 'force' => true]);
        assert_cache_gc($claimed['skipped'] && is_file($trash . '/central-debt'), 'Cron ignored the central mutation claim.');
        unset($GLOBALS['cache_gc_central_owner']);
        $GLOBALS['cache_gc_disabled'] = true;
        $disabled = site_maintenance_run(['cache_only' => true, 'force' => true, 'time_budget_seconds' => 1]);
        assert_cache_gc($disabled['skipped'] && is_file($trash . '/central-debt')
            && ($disabled['cache_lifecycle']['value']['reason'] ?? '') === 'automatic_cache_cleanup_disabled',
            'Explicit OFF did not preserve updater debt through the scheduled cache-only owner.');
        unset($GLOBALS['cache_gc_disabled']);
        $cacheOnly = site_maintenance_run(['cache_only' => true, 'force' => true, 'time_budget_seconds' => 1]);
        assert_cache_gc($cacheOnly['ok'] && $cacheOnly['cache_gc']['value']['done'] && !is_dir($trash), 'Cache-only cron failed to resume approved updater debt.');
        assert_cache_gc($cacheOnly['done'] === (!empty($cacheOnly['cache_gc']['value']['done']) && !empty($cacheOnly['cache_lifecycle']['value']['done'])),
            'Top-level completion did not reflect both independent owners.');
        mkdir($trash, 0775, true);
        file_put_contents($trash . '/safe', 'safe');
        $escaped = application_update_delete_tree_slice($trash, 180, 0.18, ['', '../jobs']);
        assert_cache_gc($escaped['blocked'] && $escaped['removed'] === 0 && is_file($trash . '/safe'), 'Untrusted cursor escaped or mutated the approved queue.');
        assert_cache_gc($escaped['failure_code'] === 'invalid_cursor' && $escaped['failure_operation'] === 'validate_cursor', 'Unsafe cursor lacks an actionable failure reason.');

        if (PHP_OS_FAMILY !== 'Windows') {
            symlink($root . '/cache/gallery-trash', $trash . '/external-link');
            $linked = application_update_run_scheduled_cache_cleanup(true);
            assert_cache_gc($linked['ok'] && is_file($root . '/cache/gallery-trash/original.jpg'), 'GC followed a symlink into user content.');
            mkdir($trash, 0775, true);
            mkdir($trash . '/denied');
            chmod($trash . '/denied', 0000);
            try {
                $denied = application_update_run_scheduled_cache_cleanup(true);
                assert_cache_gc(!$denied['ok'] && $denied['blocked'] && !$denied['done'], 'Permission failure was reported as successful deletion.');
            } finally {
                chmod($trash . '/denied', 0775);
            }
        }
        // Stop uses the existing owner, never an OS kill or unlocked pointer removal.
        $cancelId = '20261014120100-bbbbbbbbbbbb';
        $cancelDir = $workspace . '/jobs/' . $cancelId;
        mkdir($cancelDir . '/extract', 0775, true);
        file_put_contents($cancelDir . '/extract/prepared.txt', 'prepared');
        $cancelState = ['id' => $cancelId, 'status' => 'running', 'stage' => 'unknown_stage', 'checkpoints' => []];
        file_put_contents($cancelDir . '/job.json', json_encode($cancelState));
        file_put_contents($workspace . '/active-job.json', json_encode(['job_id' => $cancelId]));
        // Observe the fresh fixture before cancellation legitimately acquires its worker lock.
        $missingWorker = \Gallery\Services\application_update_job_worker_diagnostic($cancelId, 0);
        assert_cache_gc($missingWorker['worker_lock'] === 'missing' && !file_exists($cancelDir . '/worker.lock'), 'Worker observation created a missing lock file.');
        $cancelRefused = false;
        try {
            \Gallery\Services\application_update_cancel_job($cancelId);
        } catch (\RuntimeException) {
            $cancelRefused = true;
        }
        assert_cache_gc($cancelRefused && is_file($cancelDir . '/extract/prepared.txt') && is_file($workspace . '/active-job.json'), 'Unknown updater stage allowed cancellation or discarded ownership.');
        $cancelState['stage'] = 'extract';
        file_put_contents($cancelDir . '/job.json', json_encode($cancelState));
        $worker = \Gallery\Services\application_update_acquire_lock($cancelDir . '/worker.lock');
        try {
            $lockMetadata = file_get_contents($cancelDir . '/worker.lock');
            $workerDiagnostic = \Gallery\Services\application_update_job_worker_diagnostic($cancelId, time() - 10);
            assert_cache_gc($workerDiagnostic['worker_lock'] === 'held' && $workerDiagnostic['checkpoint_age_seconds'] >= 10
                && file_get_contents($cancelDir . '/worker.lock') === $lockMetadata, 'Worker observation misreported ownership or changed its metadata.');
            $busyCancel = \Gallery\Services\application_update_cancel_job($cancelId);
            assert_cache_gc($busyCancel['status'] === 'running' && is_file($cancelDir . '/extract/prepared.txt'), 'Busy worker was reported stopped or its payload was removed.');
        } finally {
            \Gallery\Services\application_update_release_lock($worker);
        }
        $workerDiagnostic = \Gallery\Services\application_update_job_worker_diagnostic($cancelId, 0);
        assert_cache_gc($workerDiagnostic['worker_lock'] === 'available' && $workerDiagnostic['checkpoint_age_seconds'] === null, 'Released worker lock or unknown timestamp was misreported.');
        $cancelled = \Gallery\Services\application_update_cancel_job($cancelId);
        assert_cache_gc($cancelled['status'] === 'cancelled' && !is_file($workspace . '/active-job.json')
            && is_file($cancelDir . '/job.json') && is_file($root . '/cache/gallery-trash/original.jpg'), 'Safe pre-activation cancellation lost its record or touched user content.');
        $cancelState['status'] = 'failed';
        $cancelState['stage'] = 'plan';
        $cancelState['error'] = ['message' => 'Safe prior failure', 'reference' => 'fixture-ref', 'retryable' => true, 'stage' => 'plan', 'at' => time()];
        file_put_contents($cancelDir . '/job.json', json_encode($cancelState));
        $retried = \Gallery\Services\application_update_retry_job($cancelId);
        assert_cache_gc($retried['status'] === 'running' && $retried['error'] === null
            && $retried['result']['last_failure']['reference'] === 'fixture-ref', 'Retry erased the preceding safe failure evidence.');
        echo "PASS independent updater cache GC\n";
    } finally {
        // Only this known disposable absolute root is eligible for fixture teardown.
        for ($slice = 0; $slice < 1000 && is_dir($root); $slice++) {
            application_update_delete_tree_slice($root, 180, 0.50);
        }
    }
}
