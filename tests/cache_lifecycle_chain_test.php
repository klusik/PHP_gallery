<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cache_lifecycle_chain_test.php
 * Module Type: Regression Test
 * Purpose: Prove autonomous timer ownership, immediate safe reclamation and coalesced logging on disposable storage.
 * Responsibilities: Exercise single-use tickets, thirty-second timing, five-second worker admission, replay refusal, opt-out and failed dispatch without production networking or cache deletion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Resolve the disposable fixture's capability preference.
     * @param string $key Requested canonical capability.
     * @return bool True only for automatic cleanup while the fixture has not opted out.
     */
    function feature_capability_effective_enabled(string $key): bool { return $key === 'automatic_cache_cleanup' && empty($GLOBALS['chain_off']); }
    /**
     * Supply the fixture's absent optional recovery preference.
     * @param string $key Setting identity selected by the real cache owner.
     * @param string|null $default Missing-value fallback.
     * @return string|null Empty recovery path or the provided fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string { return $key === 'application_update_beta_backup_path' ? '' : $default; }
    /**
     * Capture complete dispatches without real network requests.
     * @param string $ticket Committed private continuation identity.
     * @return bool False for the explicit transport-failure scenario; otherwise records the ticket for the next simulated HTTP worker.
     */
    function site_maintenance_fire_cache_chain(string $ticket): bool
    {
        $GLOBALS['chain_deliveries'][] = $ticket;
        return empty($GLOBALS['chain_transport_failed']);
    }
    /**
     * Advance the owned virtual clock instead of sleeping in deterministic tests.
     * @param int $seconds Real owner's bounded wait request.
     * @return int Zero unconsumed seconds after recording the wait and advancing fixture time.
     */
    function sleep(int $seconds): int
    {
        $GLOBALS['chain_waits'][] = $seconds;
        $GLOBALS['cache_lifecycle_test_now'] += $seconds;
        return 0;
    }
    /**
     * Capture confirmed Admin summaries through the real owner's receipt contract.
     * @param string $level Safe event level.
     * @param string $eventKey Stable cache event identity.
     * @param string $message Safe human-readable explanation.
     * @param array{removed_entries?:int,bytes_removed?:int,from_at?:int,to_at?:int,stage?:string,source?:string,exception_class?:string,source_file?:string,source_line?:int,sqlstate?:string,driver_code?:int} $context Coalesced real counters or bounded automatic-failure metadata, without private paths or messages.
     * @param array{category?:string,severity?:string,subject_type?:string,route_name?:string} $options Optional Admin presentation metadata.
     * @return array{ok:bool} Confirmed receipt or the explicit log-storage failure.
     */
    function admin_log_event_result(string $level, string $eventKey, string $message, array $context = [], array $options = []): array
    {
        if (!empty($GLOBALS['chain_log_failed'])) return ['ok'=>false];
        $GLOBALS['chain_logs'][] = ['key'=>$eventKey,'context'=>$context];
        return ['ok'=>true];
    }
}
namespace Gallery\Models {
    /**
     * Resolve the absence of a central mutation owner in this disposable fixture.
     * @param bool $requireKnown Whether the real cache worker requires a known claim observation.
     * @return null No competing central mutation exists.
     */
    function maintenance_center_model_mutation_owner(bool $requireKnown = false): ?array { return null; }
}
namespace {
    /**
     * Require one autonomous worker or logging postcondition.
     * @param bool $condition Observed required behavior.
     * @param string $message Failure explanation.
     * @return void Throws when the postcondition is absent.
     */
    function chain_assert(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    /**
     * Publish one fully written verified expired workspace under the owned root.
     * @param string $root Random disposable installation root.
     * @param string $suffix Six-character producer-recognized suffix.
     * @return string Cache-relative workspace path whose payload can be observed and safely reclaimed.
     */
    function chain_workspace(string $root, string $suffix): string
    {
        $relative = 'map-browser-profile-' . $suffix;
        mkdir($root . '/cache/' . $relative, 0775, true);
        file_put_contents($root . '/cache/' . $relative . '/payload', 'verified expired fixture');
        file_put_contents($root . '/cache/' . $relative . '/cache-owner.json', json_encode(['schema_version'=>1,
            'producer'=>'php_gallery_browser_fixture','directory'=>$relative,
            'created_at'=>$GLOBALS['cache_lifecycle_test_now']-86401], JSON_THROW_ON_ERROR));
        return $relative;
    }
    $root = sys_get_temp_dir() . '/gallery-cache-chain-' . bin2hex(random_bytes(8));
    // Confine all continuation records and reclamation to owned temporary storage.
    // Type: string. Units: absolute path. Scope: this standalone fixture process.
    // Consumers: canonical lifecycle path guards. Rationale: no real installation or remote server participates in deterministic timer tests.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    $GLOBALS['cache_lifecycle_test_now'] = time() + 172800;
    $GLOBALS['chain_deliveries'] = $GLOBALS['chain_waits'] = $GLOBALS['chain_logs'] = [];
    mkdir($root . '/cache/site-maintenance', 0775, true);
    require_once __DIR__ . '/../app/services/cache_lifecycle.php';
    try {
        chain_assert(!\Gallery\Services\cache_lifecycle_chain_runtime_available('cli-server', '1', false)
            && !\Gallery\Services\cache_lifecycle_chain_runtime_available('cli-server', '2', true)
            && \Gallery\Services\cache_lifecycle_chain_runtime_available('cli-server', '2', false)
            && \Gallery\Services\cache_lifecycle_chain_runtime_available('fpm-fcgi', null, false),
            'Runtime admission did not protect a single-worker server or rejected supported server concurrency.');
        $first = chain_workspace($root, 'First1');
        mkdir($root . '/cache/unregistered-original');
        file_put_contents($root . '/cache/unregistered-original/payload', 'protected');
        chain_assert(\Gallery\Services\cache_lifecycle_chain_start()['queued'], 'Initial chain was not dispatched.');
        chain_assert(\Gallery\Services\cache_lifecycle_status()['chain_health'] === 'queued'
            && \Gallery\Services\cache_lifecycle_status()['chain_arrival_at'] === 0, 'A socket receipt was advertised as an actual worker arrival.');
        chain_assert(!\Gallery\Services\cache_lifecycle_chain_start()['queued'] && count($GLOBALS['chain_deliveries']) === 1, 'A second visitor duplicated an unexpired chain ticket.');
        $ticket = $GLOBALS['chain_deliveries'][0];
        $result = \Gallery\Services\cache_lifecycle_chain_run($ticket);
        chain_assert($result['ok'] && $result['worked'] && $result['queued'] && !is_dir($root . '/cache/' . $first), 'An independent worker failed to reclaim eligible data immediately.');
        chain_assert(file_get_contents($root . '/cache/unregistered-original/payload') === 'protected', 'Autonomous deletion touched unproven originals.');
        chain_assert(\Gallery\Services\cache_lifecycle_status()['scheduler_source'] === 'cache_chain', 'Actual autonomous worker identity was lost.');
        chain_assert(\Gallery\Services\cache_lifecycle_chain_run($ticket)['reason'] === 'cache_chain_ticket_refused', 'A consumed ticket was replayable.');
        chain_assert(count($GLOBALS['chain_logs']) === 1 && $GLOBALS['chain_logs'][0]['context']['bytes_removed'] > 0, 'Real reclamation did not produce one confirmed summary.');
        $second = chain_workspace($root, 'Later1');
        $next = end($GLOBALS['chain_deliveries']);
        $wait = \Gallery\Services\cache_lifecycle_chain_run($next);
        chain_assert($wait['queued'] && !$wait['worked'] && is_dir($root . '/cache/' . $second), 'A timer hop ignored the thirty-second cadence.');
        $next = end($GLOBALS['chain_deliveries']);
        $work = \Gallery\Services\cache_lifecycle_chain_run($next);
        chain_assert($work['worked'] && $work['queued'] && !is_dir($root . '/cache/' . $second), 'Work failed to resume without another visitor.');
        chain_assert(array_sum($GLOBALS['chain_waits']) === 30 && max($GLOBALS['chain_waits']) <= 20, 'Declared timer hops did not preserve the interval or wait bound.');
        chain_assert(count($GLOBALS['chain_logs']) === 1, 'Each thirty-second cycle flooded the Admin log.');
        $pendingLog = \Gallery\Services\cache_lifecycle_read_json($root . '/cache/cache-lifecycle/log-summary.json');
        chain_assert($pendingLog['bytes_removed'] > 0, 'Coalesced removals were discarded.');
        $stoppedPayload = chain_workspace($root, 'Stop02');
        $checkpointBeforeOff = file_get_contents($root . '/cache/cache-lifecycle/state.json');
        $GLOBALS['chain_off'] = true;
        $off = \Gallery\Services\cache_lifecycle_chain_run(end($GLOBALS['chain_deliveries']));
        chain_assert($off['reason'] === 'automatic_cache_cleanup_disabled' && !$off['queued'] && !$off['worked']
            && is_dir($root . '/cache/' . $stoppedPayload)
            && file_get_contents($root . '/cache/cache-lifecycle/state.json') === $checkpointBeforeOff, 'A valid pending ticket ignored explicit OFF.');
        $GLOBALS['chain_off'] = false;
        $GLOBALS['cache_lifecycle_test_now'] += 301;
        $GLOBALS['chain_log_failed'] = true;
        \Gallery\Services\cache_lifecycle_log_reclamation([], []);
        chain_assert(\Gallery\Services\cache_lifecycle_read_json($root . '/cache/cache-lifecycle/log-summary.json')['bytes_removed'] === $pendingLog['bytes_removed'], 'A failed log receipt discarded pending counters.');
        $GLOBALS['chain_log_failed'] = false;
        $GLOBALS['cache_lifecycle_test_now'] += 301;
        \Gallery\Services\cache_lifecycle_log_reclamation([], []);
        chain_assert(count($GLOBALS['chain_logs']) === 2, 'Pending summaries were not flushed after the quiet logging interval.');
        chain_assert(array_keys($GLOBALS['chain_logs'][1]['context']) === ['removed_entries','bytes_removed','from_at','to_at'], 'A summary disclosed individual paths or credentials.');
        $third = chain_workspace($root, 'Stop01');
        $checkpoint = file_get_contents($root . '/cache/cache-lifecycle/state.json');
        $GLOBALS['chain_off'] = true;
        $next = end($GLOBALS['chain_deliveries']);
        // The previous virtual advance expires a pending ticket; OFF also
        // prevents a fresh startup, preserving the already committed payload.
        chain_assert(!\Gallery\Services\cache_lifecycle_chain_start()['queued'], 'Explicit OFF restarted the chain.');
        chain_assert(!\Gallery\Services\cache_lifecycle_chain_run($next)['queued']
            && is_dir($root . '/cache/' . $third) && file_get_contents($root . '/cache/cache-lifecycle/state.json') === $checkpoint, 'OFF or a stale ticket changed protected state.');
        $GLOBALS['chain_off'] = false;
        $GLOBALS['chain_transport_failed'] = true;
        chain_assert(!\Gallery\Services\cache_lifecycle_chain_start()['queued'] && \Gallery\Services\cache_lifecycle_status()['chain_health'] === 'stalled', 'Failed transport was reported as an active chain.');
        $databaseFailure = new \PDOException('private SQL and credentials must never be logged');
        $databaseFailure->errorInfo = ['42S02', 1146, 'private SQL and credentials'];
        $failure = new \RuntimeException('private wrapper message', 0, $databaseFailure);
        $logCount = count($GLOBALS['chain_logs']);
        \Gallery\Services\cache_lifecycle_log_failure('visitor_admission', 'cache_chain', $failure);
        \Gallery\Services\cache_lifecycle_log_failure('visitor_admission', 'cache_chain', $failure);
        chain_assert(count($GLOBALS['chain_logs']) === $logCount + 1, 'Repeated worker failure bypassed the existing logging interval.');
        $diagnostic = $GLOBALS['chain_logs'][$logCount];
        chain_assert($diagnostic['key'] === 'cache.automatic_failed' && $diagnostic['context']['source'] === 'cache_chain'
            && $diagnostic['context']['sqlstate'] === '42S02' && $diagnostic['context']['driver_code'] === 1146,
            'Automatic failure diagnostics lost entry-point or safe underlying database evidence.');
        chain_assert(!str_contains(json_encode($diagnostic, JSON_THROW_ON_ERROR), 'private')
            && !str_contains(json_encode($diagnostic, JSON_THROW_ON_ERROR), str_replace('\\', '/', $root)), 'Failure diagnostics exposed exception messages or private paths.');
        $GLOBALS['cache_lifecycle_test_now'] += 301;
        $GLOBALS['chain_log_failed'] = true;
        \Gallery\Services\cache_lifecycle_log_failure('visitor_admission', 'cache_chain', $failure);
        $GLOBALS['chain_log_failed'] = false;
        \Gallery\Services\cache_lifecycle_log_failure('visitor_admission', 'cache_chain', $failure);
        chain_assert(count($GLOBALS['chain_logs']) === $logCount + 1, 'A failed diagnostic insertion caused an immediate retry storm.');
        chain_assert(file_get_contents($root . '/cache/cache-lifecycle/state.json') === $checkpoint, 'Failure diagnostics changed the protected worker checkpoint.');
        echo "Autonomous cache tickets, thirty-second timers, immediate safe removal, opt-out and bounded log receipts: PASS\n";
    } finally {
        for ($step = 0; $step < 100 && is_dir($root); $step++) \Gallery\Services\application_update_delete_tree_slice($root, 5000, 0.5);
    }
}
