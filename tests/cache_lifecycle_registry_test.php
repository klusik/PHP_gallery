<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cache_lifecycle_registry_test.php
 * Module Type: Regression Test
 * Purpose: Verify persistent rotating cache work and truthful retained observations.
 * Responsibilities: Exercise real DB scheduling, resumable journals, path replacement, bounded rows, retry and passive status on disposable data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Enable only the isolated automatic cache capability unless the fixture opts out.
     * @param string $key Requested canonical capability key.
     * @return bool True for enabled automatic cleanup in this disposable fixture.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $key === 'automatic_cache_cleanup' && empty($GLOBALS['cache_registry_test_off']);
    }
    /**
     * Supply isolated preferences without querying any live application database.
     * @param string $key Requested preference identity.
     * @param string|null $default Missing preference fallback.
     * @return string|null Empty recovery reference, explicit fixture opt-out, or fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        if ($key === 'application_update_beta_backup_path') return '';
        return $key === 'feature_flag.automatic_cache_cleanup.enabled' && !empty($GLOBALS['cache_registry_test_off']) ? '0' : $default;
    }
}
namespace {
    /**
     * Require one behavioral registry postcondition.
     * @param bool $condition Observed invariant.
     * @param string $message Failure explanation.
     * @return void Throws when the persistent contract is violated.
     */
    function cache_registry_assert(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }
    $root = sys_get_temp_dir() . '/gallery-cache-registry-' . bin2hex(random_bytes(8));
    // Keep DB, journals and payloads within one disposable owner.
    // Type: string. Units: absolute directory. Scope: this regression and its reopened connection.
    // Consumers: production path guards. Rationale: no fixture operation may reach live cache.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    mkdir($root . '/cache', 0775, true);
    file_put_contents($root . '/.cache-registry-owner', 'owned');
    $GLOBALS['cache_lifecycle_test_now'] = time() + 172800;
    require_once __DIR__ . '/support/cache_registry_fixture.php';
    $database = cache_registry_fixture_database($root);
    $GLOBALS['cache_lifecycle_test_database'] = $database;
    require_once __DIR__ . '/../app/services/cache_lifecycle.php';
    try {
        $now = \Gallery\Services\cache_lifecycle_now();
        foreach (['A'=>120,'B'=>3600,'C'=>1200] as $name => $age) {
            \Gallery\Models\cache_registry_model_register(hash('sha256', $name), $name, 'unknown', 'order', $now - $age);
            $query = $database->prepare('UPDATE cache_lifecycle_regions SET last_completed_at = ?, next_due_at = ? WHERE relative_path = ?');
            $query->execute([$now - $age, $now - $age, $name]);
        }
        foreach (['B','C','A'] as $expected) {
            $due = \Gallery\Models\cache_registry_model_due($now);
            cache_registry_assert($due['relative_path'] === $expected, 'Oldest due area was not selected first.');
            $database->prepare('UPDATE cache_lifecycle_regions SET next_due_at = ? WHERE region_key = ?')->execute([$now + 30, $due['region_key']]);
        }
        cache_registry_assert(\Gallery\Models\cache_registry_model_due($now) === null,
            'Ordinary work bypassed future due timestamps.');
        cache_registry_assert(\Gallery\Models\cache_registry_model_due($now, true)['relative_path'] === 'B',
            'Explicit force did not retain oldest-completed ordering for equally due regions.');
        $database->prepare('UPDATE cache_lifecycle_regions SET retry_at = ? WHERE relative_path = ?')->execute([$now + 900, 'B']);
        cache_registry_assert(\Gallery\Models\cache_registry_model_due($now, true)['relative_path'] === 'C',
            'Explicit force bypassed a failed region retry backoff.');
        $database->exec('DELETE FROM cache_lifecycle_regions');
        mkdir($root . '/cache/@files');
        file_put_contents($root . '/cache/@files/original', 'reserved-name original');
        mkdir($root . '/cache/big');
        for ($i = 0; $i < 1200; $i++) {
            mkdir($root . '/cache/big/part-' . $i);
            file_put_contents($root . '/cache/big/part-' . $i . '/original', str_repeat('x', 100));
        }
        $workspace = 'map-browser-profile-Small1';
        mkdir($root . '/cache/' . $workspace);
        file_put_contents($root . '/cache/' . $workspace . '/cache-owner.json', json_encode([
            'schema_version'=>1,'producer'=>'php_gallery_browser_fixture','directory'=>$workspace,'created_at'=>$now - 86400], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/cache/' . $workspace . '/payload', 'expired');
        \Gallery\Services\cache_lifecycle_registry_initialize(['removed'=>7,'bytes_removed'=>1234,'completed_at'=>$now - 600,
            'last_observed_at'=>$now - 600,'totals'=>['unknown'=>['bytes'=>900000000,'files'=>10,'dirs'=>2]]]);
        $transition = \Gallery\Services\cache_lifecycle_status();
        cache_registry_assert($transition['bytes'] === 900000000 && $transition['partial'],
            'Upgrade lost its historical observation before readable regional coverage existed.');
        cache_registry_assert($transition['last_removed_at'] === 0,
            'A legacy completion timestamp was invented as the last actual removal time.');
        $sawPartial = false;
        $cursor = null;
        $bigKey = hash('sha256', 'region:big');
        for ($step = 0; $step < 100; $step++) {
            $result = \Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron', 8);
            cache_registry_assert(!empty($result['ok']), 'A healthy registry worker failed.');
            $row = \Gallery\Models\cache_registry_model_find($bigKey);
            $state = $row === null ? [] : \Gallery\Services\cache_lifecycle_registry_decode((string) $row['checkpoint_json']);
            if (($state['phase'] ?? '') === 'inventory' && count($state['stack'] ?? []) > 0) {
                $sawPartial = true;
                $nextCursor = json_encode($state['stack'], JSON_THROW_ON_ERROR);
                if ($cursor !== null) cache_registry_assert($nextCursor !== $cursor, 'Large region repeatedly replayed its committed prefix.');
                $cursor = $nextCursor;
                cache_registry_assert((int) $row['last_completed_at'] === 0, 'A partial region was labelled successfully completed.');
            }
            if (!is_dir($root . '/cache/' . $workspace) && $row !== null && (int) $row['last_completed_at'] > 0 && ($state['phase'] ?? '') === 'complete') break;
            $GLOBALS['cache_lifecycle_test_now'] += 30;
        }
        cache_registry_assert($sawPartial && !is_dir($root . '/cache/' . $workspace), 'Large region starved a separate eligible workspace.');
        $status = \Gallery\Services\cache_lifecycle_status();
        cache_registry_assert($status['bytes_removed'] > 0 && $status['last_removed_at'] > 0, 'Confirmed deletion receipt was lost.');
        cache_registry_assert(\Gallery\Models\cache_registry_model_find(hash('sha256', 'region:@files')) !== null,
            'A filesystem name collided with an internal control identity.');
        $observed = $status['bytes'];
        cache_registry_assert($observed < 900000000, 'Transition-only legacy measurement survived completed regional coverage.');
        cache_registry_assert($observed >= 120000, 'Completed large observation was absent.');
        $rowCount = (int) $database->query('SELECT COUNT(*) FROM cache_lifecycle_regions')->fetchColumn();
        // Close and reopen the real DB connection to exercise process-independent persistence.
        unset($GLOBALS['cache_lifecycle_test_database']);
        $database = cache_registry_fixture_database($root, false);
        $GLOBALS['cache_lifecycle_test_database'] = $database;
        cache_registry_assert(\Gallery\Services\cache_lifecycle_status()['bytes'] === $observed, 'Reopening storage lost retained findings.');
        $child = proc_open([PHP_BINARY, __DIR__ . '/fixtures/cache_registry_restart.php', $root,
            (string) \Gallery\Services\cache_lifecycle_now()], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        cache_registry_assert(is_resource($child), 'Independent registry reader did not start.');
        fclose($pipes[0]);
        $childOutput = stream_get_contents($pipes[1]);
        $childError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $childExit = proc_close($child);
        cache_registry_assert($childExit === 0, 'Independent registry read failed: ' . $childError);
        $restarted = json_decode($childOutput, true, 64, JSON_THROW_ON_ERROR);
        cache_registry_assert($restarted['bytes'] === $observed && $restarted['bytes_removed'] === $status['bytes_removed'],
            'Process restart lost completed findings or confirmed removal receipts.');
        for ($i = 0; $i < 1100; $i++) unlink($root . '/cache/big/part-' . $i . '/original');
        $GLOBALS['cache_lifecycle_test_now'] += 3601;
        \Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron', 2);
        cache_registry_assert(\Gallery\Services\cache_lifecycle_status()['bytes'] === $observed, 'New partial work replaced completed bytes with partial zeros.');
        // A reused path with fresh producer proof cannot inherit old deletion permission.
        mkdir($root . '/cache/' . $workspace);
        file_put_contents($root . '/cache/' . $workspace . '/cache-owner.json', json_encode([
            'schema_version'=>1,'producer'=>'php_gallery_browser_fixture','directory'=>$workspace,'created_at'=>\Gallery\Services\cache_lifecycle_now()], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/cache/' . $workspace . '/new-original', 'fresh');
        mkdir($root . '/cache/new-region');
        file_put_contents($root . '/cache/new-region/original', 'new');
        for ($step = 0; $step < 100; $step++) {
            $GLOBALS['cache_lifecycle_test_now'] += 30;
            \Gallery\Services\cache_lifecycle_scheduled_run(1, 'cli_cron', 8);
            $current = \Gallery\Services\cache_lifecycle_status();
            if ($current['bytes'] < $observed && \Gallery\Models\cache_registry_model_find(hash('sha256', 'region:new-region')) !== null) break;
        }
        cache_registry_assert($current['bytes'] !== 900000000, 'New discovery resurrected a retired legacy measurement.');
        cache_registry_assert($current['bytes'] < $observed, 'A legitimately smaller completed measurement could not replace the old one.');
        cache_registry_assert(is_file($root . '/cache/' . $workspace . '/new-original'), 'Reused path inherited stale deletion permission.');
        cache_registry_assert($current['bytes_removed'] >= $status['bytes_removed'], 'Later observations erased confirmed deletion receipts.');
        cache_registry_assert((int) $database->query('SELECT COUNT(*) FROM cache_lifecycle_regions')->fetchColumn() <= $rowCount + 1, 'Repeated checks created historical rows.');
        $before = $database->query('SELECT checkpoint_json FROM cache_lifecycle_regions ORDER BY region_key')->fetchAll(\PDO::FETCH_COLUMN);
        \Gallery\Services\cache_lifecycle_status();
        cache_registry_assert($before === $database->query('SELECT checkpoint_json FROM cache_lifecycle_regions ORDER BY region_key')->fetchAll(\PDO::FETCH_COLUMN),
            'Passive status advanced a worker checkpoint.');
        $crashPath = 'map-browser-profile-Crash1';
        mkdir($root . '/cache/' . $crashPath);
        file_put_contents($root . '/cache/' . $crashPath . '/cache-owner.json', json_encode([
            'schema_version'=>1,'producer'=>'php_gallery_browser_fixture','directory'=>$crashPath,
            'created_at'=>\Gallery\Services\cache_lifecycle_now() - 86400], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/cache/' . $crashPath . '/payload', 'detached payload');
        $crashKey = hash('sha256', 'region:' . $crashPath);
        \Gallery\Models\cache_registry_model_register($crashKey, $crashPath, 'workspaces', 'crash', \Gallery\Services\cache_lifecycle_now());
        $GLOBALS['cache_lifecycle_registry_context'] = \Gallery\Models\cache_registry_model_find($crashKey);
        $crash = \Gallery\Services\cache_lifecycle_inventory_start(true);
        for ($step = 0; $step < 20 && $crash['phase'] === 'inventory'; $step++) $crash = \Gallery\Services\cache_lifecycle_inventory_slice();
        $work = \Gallery\Services\cache_lifecycle_job_directory();
        $candidate = json_decode(file($work . '/work/plan.jsonl')[0], true, 16, JSON_THROW_ON_ERROR);
        $target = $work . '/work/trash/' . hash('sha256', $crashPath);
        cache_registry_assert(\Gallery\Services\cache_lifecycle_stage_candidate($candidate, $target), 'DB crash fixture could not detach reviewed payload.');
        // Simulate an unlink before its DB cursor/receipt commit. Only remaining
        // physical removals may be confirmed on restart.
        // The deletion owner's observation budget includes '.' and '..'; the
        // third observation reaches a payload, matching the existing scheduler fixture.
        $lost = ['removed'=>0,'cursor'=>[]];
        for ($step = 0; $step < 10 && $lost['removed'] === 0; $step++) $lost = \Gallery\Services\application_update_delete_tree_slice($target, 3, 0.1, $lost['cursor']);
        cache_registry_assert($lost['removed'] > 0, 'DB crash fixture did not cross the unlink/checkpoint boundary.');
        mkdir($root . '/cache/' . $crashPath);
        file_put_contents($root . '/cache/' . $crashPath . '/new-original', 'replacement');
        unset($GLOBALS['cache_lifecycle_registry_context']);
        $GLOBALS['cache_lifecycle_test_database'] = cache_registry_fixture_database($root, false);
        $GLOBALS['cache_lifecycle_registry_context'] = \Gallery\Models\cache_registry_model_find($crashKey);
        $crash = \Gallery\Services\cache_lifecycle_cleanup_start($crash['id']);
        for ($step = 0; $step < 20 && $crash['phase'] === 'cleanup'; $step++) $crash = \Gallery\Services\cache_lifecycle_cleanup_slice();
        cache_registry_assert($crash['phase'] === 'complete' && !file_exists($target)
            && $crash['bytes_removed'] <= $candidate['bytes'] && is_file($root . '/cache/' . $crashPath . '/new-original'),
            'DB restart duplicated removal receipts, lost detached work or removed a replacement payload.');
        unset($GLOBALS['cache_lifecycle_registry_context']);
        $reused = 'map-browser-profile-Reused';
        mkdir($root . '/cache/' . $reused);
        $copiedMarker = ['schema_version'=>1,'producer'=>'php_gallery_browser_fixture','directory'=>$reused,
            'created_at'=>\Gallery\Services\cache_lifecycle_now() - 86400];
        file_put_contents($root . '/cache/' . $reused . '/cache-owner.json', json_encode($copiedMarker, JSON_THROW_ON_ERROR));
        \Gallery\Services\cache_lifecycle_workspace_observe($reused);
        rename($root . '/cache/' . $reused, $root . '/old-owned-workspace');
        mkdir($root . '/cache/' . $reused);
        file_put_contents($root . '/cache/' . $reused . '/cache-owner.json', json_encode($copiedMarker, JSON_THROW_ON_ERROR));
        \Gallery\Services\cache_lifecycle_workspace_observe($reused);
        $replacementAge = \Gallery\Services\cache_lifecycle_read_json($root . '/cache/'
            . \Gallery\Services\cache_lifecycle_workspace_age_path($reused));
        cache_registry_assert($replacementAge['created_at'] === \Gallery\Services\cache_lifecycle_now(),
            'A replacement inode with copied expired proof inherited old expiry.');
        $GLOBALS['cache_registry_test_off'] = true;
        cache_registry_assert(\Gallery\Services\cache_lifecycle_scheduled_slice()['reason'] === 'automatic_cache_cleanup_disabled', 'OFF admitted automatic work.');
        $GLOBALS['cache_registry_test_off'] = false;
        // Reproduce a production-sized flat directory without allocating a large payload.
        // Its contents are unproven and must be measured, never removed.
        mkdir($root . '/cache/dense');
        for ($entry = 0; $entry < 4097; $entry++) file_put_contents($root . '/cache/dense/name-' . $entry, 'x');
        $denseKey = hash('sha256', 'region:dense');
        $database->prepare('UPDATE cache_lifecycle_regions SET next_due_at = ?')->execute([\Gallery\Services\cache_lifecycle_now() + 3600]);
        \Gallery\Models\cache_registry_model_register($denseKey, 'dense', 'unknown', 'dense', \Gallery\Services\cache_lifecycle_now());
        $denseRun = \Gallery\Services\cache_lifecycle_scheduled_run(2, 'cli_cron', 8);
        $denseRow = \Gallery\Models\cache_registry_model_find($denseKey);
        $denseState = \Gallery\Services\cache_lifecycle_registry_decode($denseRow['checkpoint_json']);
        cache_registry_assert($denseRun['slices'] > 1 && $denseRun['slices'] <= 8
            && $denseState['totals']['unknown']['files'] > 0 && empty($denseState['blocked']) && empty($denseState['errors']),
            'One admitted dense region did not continue within the existing bounded burst.');
        // Filesystem latency varies; prove exact completion across bounded opportunities.
        for ($attempt = 0; $attempt < 8 && $denseState['phase'] !== 'complete'; $attempt++) {
            $GLOBALS['cache_lifecycle_test_now'] += 30;
            \Gallery\Services\cache_lifecycle_scheduled_run(2, 'cli_cron', 8);
            $denseRow = \Gallery\Models\cache_registry_model_find($denseKey);
            $denseState = \Gallery\Services\cache_lifecycle_registry_decode($denseRow['checkpoint_json']);
        }
        cache_registry_assert($denseState['phase'] === 'complete' && empty($denseState['blocked']) && empty($denseState['errors'])
            && $denseState['totals']['unknown']['files'] === 4097 && $denseState['totals']['unknown']['bytes'] === 4097
            && is_file($root . '/cache/dense/name-4096'),
            'Dense streamed catalog did not finish its exact protected measurement.');
        $GLOBALS['cache_lifecycle_test_now'] += 3601;
        foreach (['retire-old'=>[true,604801],'retain-recent'=>[true,60],'retain-pending'=>[false,604801]] as $path => [$drained,$age]) {
            $key = hash('sha256', $path);
            \Gallery\Models\cache_registry_model_register($key, $path, 'unknown', 'absent', \Gallery\Services\cache_lifecycle_now());
            $database->prepare('UPDATE cache_lifecycle_regions SET drained = ?, missing_since = ? WHERE region_key = ?')
                ->execute([$drained ? 1 : 0, \Gallery\Services\cache_lifecycle_now() - $age, $key]);
            mkdir($root . '/cache/cache-lifecycle/regions/' . $key . '/work/catalogs', 0775, true);
            file_put_contents($root . '/cache/cache-lifecycle/regions/' . $key . '/work/catalogs/names', 'owned catalog');
        }
        $GLOBALS['cache_lifecycle_registry_context'] = \Gallery\Models\cache_registry_model_find(hash('sha256', '@discovery'));
        for ($step = 0; $step < 20; $step++) {
            $discovery = \Gallery\Services\cache_lifecycle_registry_discover(0.18);
            if ($discovery['phase'] === 'complete') break;
        }
        unset($GLOBALS['cache_lifecycle_registry_context']);
        cache_registry_assert(\Gallery\Models\cache_registry_model_find(hash('sha256', 'retire-old')) === null
            && is_dir($root . '/cache/cache-lifecycle/retired/region-' . hash('sha256', 'retire-old')),
            'Old drained tombstone was not retired with its private journals.');
        cache_registry_assert(\Gallery\Models\cache_registry_model_find(hash('sha256', 'retain-recent')) !== null
            && \Gallery\Models\cache_registry_model_find(hash('sha256', 'retain-pending')) !== null,
            'Retention removed a recent or unfinished identity.');
        $database->prepare('UPDATE cache_lifecycle_regions SET checkpoint_json = ?, next_due_at = 0, retry_at = 0 WHERE region_key = ?')->execute(['{broken', $bigKey]);
        $result = \Gallery\Services\cache_lifecycle_registry_slice(0.1);
        cache_registry_assert(!$result['ok'], 'Corrupt checkpoint was treated as a successful empty region.');
        $broken = \Gallery\Models\cache_registry_model_find($bigKey);
        cache_registry_assert((int) $broken['retry_at'] > \Gallery\Services\cache_lifecycle_now(), 'Broken region has no retry backoff.');
        $failedStatus = \Gallery\Services\cache_lifecycle_status();
        cache_registry_assert($failedStatus['partial'] && $failedStatus['errors'] > 0 && $failedStatus['bytes'] > 0,
            'A failed newer checkpoint hid uncertainty or erased the last completed measurement.');
        cache_registry_assert(\Gallery\Models\cache_registry_model_due(\Gallery\Services\cache_lifecycle_now())['region_key'] !== $bigKey,
            'Broken oldest region starved other due areas.');
        $discoveryKey = hash('sha256', '@discovery');
        $database->prepare('UPDATE cache_lifecycle_regions SET checkpoint_json = ?, next_due_at = -1, retry_at = 0 WHERE region_key = ?')
            ->execute(['{broken', $discoveryKey]);
        $result = \Gallery\Services\cache_lifecycle_registry_slice(0.1);
        $failedStatus = \Gallery\Services\cache_lifecycle_status();
        cache_registry_assert(!$result['ok'] && $failedStatus['partial'] && $failedStatus['errors'] >= 2,
            'An earlier complete discovery concealed a newer failed discovery attempt.');
        $retainedBytes = $failedStatus['bytes'];
        \Gallery\Services\cache_lifecycle_inventory_start();
        \Gallery\Services\cache_lifecycle_inventory_slice();
        $manualStatus = \Gallery\Services\cache_lifecycle_status();
        cache_registry_assert(isset($manualStatus['operation']) && $manualStatus['operation']['phase'] === 'inventory'
            && $manualStatus['operation']['bytes_removed'] === 0 && $manualStatus['bytes'] === $retainedBytes
            && $manualStatus['bytes_removed'] > 0,
            'Current manual-cycle progress was replaced with retained regional observations or lifetime removal receipts.');
        echo "Persistent rotating cache registry scheduling, checkpoints, findings, discovery and safe retry: PASS\n";
    } finally {
        unset($GLOBALS['cache_lifecycle_registry_context'], $GLOBALS['cache_lifecycle_test_database']);
        unset($query, $child);
        $database = null;
        for ($step = 0; $step < 200 && is_dir($root); $step++) \Gallery\Services\application_update_delete_tree_slice($root, 5000, 0.5);
    }
}
