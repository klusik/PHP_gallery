<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle_registry_storage.php
 * Module Type: Service
 * Purpose: Expose passive registry evidence and a single authoritative checkpoint bridge.
 * Responsibilities: Separate completed findings from working counters; retain confirmed deletion receipts across region retirement.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

require_once dirname(__DIR__) . '/models/cache_lifecycle_registry.php';

/**
 * Describe bounded passive region evidence and retained category measurements.
 * @phpstan-type CacheRegistryOwnerTotals array{bytes:int,files:int,dirs:int,reclaimable_bytes?:int,age_24h_bytes?:int,age_7d_bytes?:int,age_older_bytes?:int,oldest?:int,newest?:int}
 * @phpstan-type CacheLifecycleFrame array{path:string,offset:int,catalog:string,catalog_ok:bool,catalog_attempts:int,bytes:int,files:int,dirs:int,newest:int,pinned:bool,unsafe:bool}
 * @phpstan-type CacheLifecycleCheckpoint array{schema?:int,id?:string,phase?:string,started_at?:int,completed_at?:int,scheduled?:bool,observation_round?:int,stack?:list<CacheLifecycleFrame>,totals?:array<string,CacheRegistryOwnerTotals>,errors?:int,blocked?:int|bool,candidates?:int,candidate_bytes?:int,plan_offset?:int,plan_write_offset?:int,current?:array{target:string,next_offset:int,bytes:int,removed_before:int,workspace_age:string,workspace_path:string}|null,cursor?:list<string>,removed?:int,bytes_removed?:int,last_removed_at?:int,skipped?:int|bool,settled_bytes?:int,settled_candidates?:int,last_manual_at?:int,last_observed_at?:int,metadata_bytes?:int,queue_index_ready?:bool,cleanup_blocked?:bool,last_error?:string,last_skip_reason?:string,superseded_manual_id?:string,superseded_manual_at?:int,next_due?:int,turn?:string,sample?:list<array{path:string,owner:string,bytes:int}>,offset?:int,catalog_ready?:bool,region_count?:int,ok?:bool,busy?:bool,reason?:string,registry?:bool,registry_progressed?:bool,region_key?:string,pending?:bool,slice_observations?:int}
 * @phpstan-type CacheRegistryRegionEvidence array{path:string,phase:string,round:int,last_serviced_at:int,last_completed_at:int,next_due_at:int,retry_at:int,failures:int,cursor_path:string,cursor_offset:int}
 * @phpstan-type CacheRegistrySummary array{id:string,phase:string,scheduled:bool,registry:bool,measurement_available:bool,totals:array<string,CacheRegistryOwnerTotals>,regions_total:int,regions_completed:int,regions_working:int,regions_unverified:int,oldest_completed_at:int,last_observed_at:int,pending_bytes:int,pending_candidates:int,removed:int,bytes_removed:int,last_removed_at:int,errors:int,blocked:int,sample:list<array{path:string,owner:string,bytes:int}>,next_due:int,complete:bool,partial:bool,working_bytes:int,regions_sample:list<CacheRegistryRegionEvidence>,legacy_measurement:bool,bytes:int,files:int,dirs:int,candidates:int,candidate_bytes:int}
 */

/**
 * Bound active region identities and passive summary rows independently of historical checks.
 * Type: int. Units: regions. Scope: one installation.
 * Consumers: discovery admission and passive summary reads.
 * Rationale: 4096 regions match the bounded root catalog; three reserved identities hold discovery, root files and removal receipts.
 */
const CACHE_REGISTRY_REGION_LIMIT = 4099;
/**
 * Retain disappeared drained identities long enough to diagnose path replacement.
 * Type: int. Units: seconds. Scope: absent region tombstones.
 * Consumers: completed discovery pruning. Rationale: seven days preserves recent evidence while row count follows managed paths rather than scan history.
 */
const CACHE_REGISTRY_RETENTION_SECONDS = 604800;
/**
 * Yield partial work fairly while revisiting healthy completed areas less often.
 * Type: int. Units: seconds. Scope: a completed region's next check.
 * Consumers: registry checkpoint completion. Rationale: an hourly refresh retains the original completed-check policy; thirty-second opportunities serve the oldest due work and discovery.
 */
const CACHE_REGISTRY_RECHECK_SECONDS = 3600;
/**
 * Back off failed regions without consuming every worker opportunity.
 * Type: int. Units: seconds. Scope: one region retry.
 * Consumers: checkpoint/error retry admission. Rationale: fifteen minutes bounds retry pressure independently of the age of its last successful check.
 */
const CACHE_REGISTRY_RETRY_SECONDS = 900;

/**
 * Select the database registry for bootstrapped application workers and opted-in disposable fixtures.
 * @return bool True in production; legacy standalone fixtures lacking a database keep their explicit historical API coverage.
 */
function cache_lifecycle_registry_enabled(): bool
{
    return !defined('CACHE_LIFECYCLE_TEST_ROOT') || isset($GLOBALS['cache_lifecycle_test_database']);
}

/**
 * Decode a bounded authoritative DB record; corruption never means an empty successful check.
 * @param string $json Stored checkpoint or compact finding JSON.
 * @return array<string,mixed> Validated object fields; an empty object represents unobserved work.
 */
function cache_lifecycle_registry_decode(string $json): array
{
    if (strlen($json) > CACHE_LIFECYCLE_STATE_BYTES) throw new \RuntimeException('Cache registry record exceeded its bound.');
    $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new \RuntimeException('Cache registry record is invalid.');
    return $value;
}

/**
 * Resolve the worker's private journal directory while leaving producer lease paths unchanged.
 * @return string Guarded existing directory for the current region, or the historical manual/upgrade owner.
 */
function cache_lifecycle_job_directory(): string
{
    $context = $GLOBALS['cache_lifecycle_registry_context'] ?? null;
    if (!is_array($context)) return cache_lifecycle_control_directory();
    $directory = cache_lifecycle_control_directory() . '/regions/' . $context['region_key'];
    cache_lifecycle_assert_path($directory);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new \RuntimeException('Cache region journals are unavailable.');
    return $directory;
}

/**
 * Resolve the admitted region's inventory root.
 * @return string Safe cache-relative directory, empty for root-files-only and legacy whole-cache inventories.
 */
function cache_lifecycle_inventory_root(): string
{
    $path = (string) ($GLOBALS['cache_lifecycle_registry_context']['relative_path'] ?? '');
    return ($GLOBALS['cache_lifecycle_registry_context']['kind'] ?? 'region') === 'region' ? $path : '';
}

/**
 * Match only a region state bridge, never producer proof, leases, timers or manual state.
 * @param string $path Guarded absolute checkpoint candidate.
 * @return bool True only for the active region's synthetic state.json path; that path is never written to disk.
 */
function cache_lifecycle_registry_state_path(string $path): bool
{
    $context = $GLOBALS['cache_lifecycle_registry_context'] ?? null;
    return is_array($context) && str_replace('\\', '/', $path) === str_replace('\\', '/', cache_lifecycle_control_directory())
        . '/regions/' . $context['region_key'] . '/state.json';
}

/**
 * Read the sole authoritative region checkpoint.
 * @return CacheLifecycleCheckpoint Complete inventory/discovery state or an empty unstarted checkpoint; invalid records throw.
 */
function cache_lifecycle_registry_checkpoint(): array
{
    $key = (string) $GLOBALS['cache_lifecycle_registry_context']['region_key'];
    $row = \Gallery\Models\cache_registry_model_find($key);
    if ($row === null) throw new \RuntimeException('Cache region identity disappeared.');
    $state = cache_lifecycle_registry_decode((string) $row['checkpoint_json']);
    if ($state !== [] && !isset($state['schema'], $state['id'], $state['phase'])) throw new \RuntimeException('Cache region checkpoint is invalid.');
    return $state;
}

/**
 * Commit state, retained findings and actual removal receipts atomically through the model.
 * @param CacheLifecycleCheckpoint $state Existing inventory/deletion/discovery state with phase/id, counters, bounded stack and journal fences.
 * @return void Persists one authority in DB; filesystem journals contain names/payloads while every cursor/write fence lives here.
 */
function cache_lifecycle_registry_store(array $state): void
{
    $key = (string) $GLOBALS['cache_lifecycle_registry_context']['region_key'];
    $row = \Gallery\Models\cache_registry_model_find($key);
    if ($row === null) throw new \RuntimeException('Cache region identity disappeared.');
    $previous = cache_lifecycle_registry_decode((string) $row['finding_json']);
    $completed = cache_lifecycle_registry_decode((string) $row['completed_json']);
    $oldState = cache_lifecycle_registry_decode((string) $row['checkpoint_json']);
    $now = cache_lifecycle_now();
    $finished = in_array($state['phase'], ['preview','cleanup','complete'], true);
    $successful = $finished && empty($state['errors']) && empty($state['blocked']);
    $observed = $finished && ($oldState['phase'] ?? '') === 'inventory';
    $pendingBytes = max(0, (int) ($state['candidate_bytes'] ?? 0) - (int) ($state['settled_bytes'] ?? 0));
    if (is_array($state['current'] ?? null)) {
        $pendingBytes = max(0, $pendingBytes - min((int) ($state['current']['bytes'] ?? 0),
            max(0, (int) ($state['bytes_removed'] ?? 0) - (int) ($state['current']['removed_before'] ?? $state['bytes_removed'] ?? 0))));
    }
    $finding = [
        'id'=>$state['id'],'phase'=>$state['phase'],'totals'=>$state['totals'] ?? [],
        'observed_at'=>(int) ($state['last_observed_at'] ?? 0),'started_at'=>(int) ($state['started_at'] ?? 0),
        'partial'=>!empty($state['errors']) || !empty($state['blocked']),
        'pending_bytes'=>$state['phase'] === 'complete' ? 0 : $pendingBytes,
        'pending_candidates'=>$state['phase'] === 'complete' ? 0 : max(0, (int) ($state['candidates'] ?? 0) - (int) ($state['settled_candidates'] ?? 0)),
        'removed'=>(int) ($state['removed'] ?? 0),'bytes_removed'=>(int) ($state['bytes_removed'] ?? 0),
        'errors'=>(int) ($state['errors'] ?? 0),'blocked'=>(int) ($state['blocked'] ?? 0),
        'round'=>(int) ($state['observation_round'] ?? 1),
        'cursor_path'=>(string) ($state['stack'][count($state['stack'] ?? []) - 1]['path'] ?? ''),
        'cursor_offset'=>(int) ($state['stack'][count($state['stack'] ?? []) - 1]['offset'] ?? $state['offset'] ?? 0),'sample'=>$state['sample'] ?? [],
    ];
    // Keep the last completed measurement during a new partial pass. A genuinely
    // smaller completed observation replaces it directly; deletion is not subtracted.
    if ($observed && ($successful || $completed === [])) $completed = $finding + ['completed_at'=>$now,'coverage_complete'=>$successful];
    $deltaEntries = max(0, $finding['removed'] - (int) ($previous['removed'] ?? 0));
    $deltaBytes = max(0, $finding['bytes_removed'] - (int) ($previous['bytes_removed'] ?? 0));
    $history = null;
    if ($deltaEntries > 0 || $deltaBytes > 0) {
        $receiptRow = \Gallery\Models\cache_registry_model_find(hash('sha256', '@history'));
        if ($receiptRow === null) throw new \RuntimeException('Cache removal receipt owner is unavailable.');
        $receipt = cache_lifecycle_registry_decode((string) $receiptRow['finding_json']);
        $receipt['removed'] = (int) ($receipt['removed'] ?? 0) + $deltaEntries;
        $receipt['bytes_removed'] = (int) ($receipt['bytes_removed'] ?? 0) + $deltaBytes;
        $receipt['last_removed_at'] = $now;
        $history = json_encode($receipt, JSON_THROW_ON_ERROR);
    }
    $failed = !empty($state['cleanup_blocked']) || $finished && !$successful;
    $completedAt = $successful && $observed ? $now : (int) $row['last_completed_at'];
    $next = $state['phase'] === 'complete' ? $now + (($GLOBALS['cache_lifecycle_registry_context']['kind'] ?? '') === 'discovery' ? CACHE_LIFECYCLE_REFRESH_SECONDS : CACHE_REGISTRY_RECHECK_SECONDS) : $now + CACHE_LIFECYCLE_REFRESH_SECONDS;
    if ($failed) $next = $now + CACHE_REGISTRY_RETRY_SECONDS;
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($json) > CACHE_LIFECYCLE_STATE_BYTES) throw new \RuntimeException('Cache region checkpoint exceeded its bound.');
    \Gallery\Models\cache_registry_model_save($key, [
        'checkpoint_json'=>$json,'finding_json'=>json_encode($finding, JSON_THROW_ON_ERROR),
        'completed_json'=>json_encode($completed, JSON_THROW_ON_ERROR),'last_serviced_at'=>$now,
        'last_completed_at'=>$completedAt,'next_due_at'=>$next,
        'retry_at'=>$failed ? $now + CACHE_REGISTRY_RETRY_SECONDS : 0,
        'failures'=>$failed ? (int) $row['failures'] + 1 : 0,'drained'=>$state['phase'] === 'complete' ? 1 : 0,
    ], $history);
}

/**
 * Collect passive historical evidence without creating rows, scanning cache or advancing cursors.
 * @return CacheRegistrySummary Aggregated last-known totals, coverage/timing, pending debt, working evidence and persistent actual removal receipts; unavailable DB evidence throws.
 */
function cache_lifecycle_registry_summary(): array
{
    $rows = \Gallery\Models\cache_registry_model_summaries(CACHE_REGISTRY_REGION_LIMIT);
    if (count($rows) > CACHE_REGISTRY_REGION_LIMIT) throw new \RuntimeException('Cache registry region limit exceeded.');
    $result = ['id'=>'registry','phase'=>'idle','scheduled'=>true,'registry'=>true,'totals'=>[],'measurement_available'=>false,'regions_total'=>0,
        'regions_completed'=>0,'regions_working'=>0,'regions_unverified'=>0,'oldest_completed_at'=>0,'last_observed_at'=>0,
        'pending_bytes'=>0,'pending_candidates'=>0,'removed'=>0,'bytes_removed'=>0,'last_removed_at'=>0,
        'errors'=>0,'blocked'=>0,'sample'=>[],'next_due'=>0,'complete'=>false,'partial'=>true,'working_bytes'=>0,'regions_sample'=>[]];
    $discoveryComplete = false;
    $discoveryWorking = false;
    $legacy = [];
    foreach ($rows as $row) {
        $finding = cache_lifecycle_registry_decode((string) $row['finding_json']);
        $completed = cache_lifecycle_registry_decode((string) $row['completed_json']);
        if ($row['kind'] === 'history') {
            $legacy = $finding;
            foreach (['removed','bytes_removed','last_removed_at'] as $field) $result[$field] = (int) ($finding[$field] ?? 0);
            continue;
        }
        if ((int) $row['missing_since'] > 0) continue;
        // A retained successful measurement does not erase a failed newer attempt.
        // Exception paths preserve the checkpoint verbatim and record indexed failures.
        $result['errors'] += max((int) ($finding['errors'] ?? 0), (int) $row['failures'] > 0 ? 1 : 0);
        $result['blocked'] += (int) ($finding['blocked'] ?? 0);
        if ($row['kind'] === 'discovery' && count($result['regions_sample']) >= CACHE_LIFECYCLE_PREVIEW_SAMPLES) array_pop($result['regions_sample']);
        if (count($result['regions_sample']) < CACHE_LIFECYCLE_PREVIEW_SAMPLES) $result['regions_sample'][] = [
            'path'=>$row['relative_path'],'phase'=>$finding['phase'] ?? 'unobserved','round'=>(int) ($finding['round'] ?? 0),
            'last_serviced_at'=>(int) $row['last_serviced_at'],'last_completed_at'=>(int) $row['last_completed_at'],
            'next_due_at'=>(int) $row['next_due_at'],'retry_at'=>(int) $row['retry_at'],'failures'=>(int) $row['failures'],
            'cursor_path'=>(string) ($finding['cursor_path'] ?? ''),'cursor_offset'=>(int) ($finding['cursor_offset'] ?? 0),
        ];
        $due = max((int) $row['next_due_at'], (int) $row['retry_at']);
        $result['next_due'] = $result['next_due'] > 0 ? min($result['next_due'], $due) : $due;
        if ($row['kind'] === 'discovery') {
            $discoveryComplete = (int) $row['last_completed_at'] > 0;
            $discoveryWorking = !in_array($finding['phase'] ?? '', ['complete',''], true);
            if ($discoveryWorking && $result['phase'] === 'idle') $result['phase'] = 'inventory';
            continue;
        }
        $result['regions_total']++;
        $measured = $completed !== [] ? $completed : $finding;
        $result['measurement_available'] = $result['measurement_available'] || (int) ($measured['observed_at'] ?? 0) > 0;
        $successful = !empty($measured['coverage_complete']);
        $result['regions_completed'] += $successful ? 1 : 0;
        $result['regions_unverified'] += $successful ? 0 : 1;
        $active = !in_array($finding['phase'] ?? '', ['complete',''], true);
        $result['regions_working'] += $active ? 1 : 0;
        if ($active && $result['phase'] !== 'cleanup') $result['phase'] = (string) ($finding['phase'] ?? 'inventory');
        foreach ($finding['totals'] ?? [] as $total) $result['working_bytes'] += $active ? (int) ($total['bytes'] ?? 0) : 0;
        foreach ($measured['totals'] ?? [] as $owner => $total) {
            foreach ($total as $field => $value) {
                if (in_array($field, ['oldest','newest'], true)) continue;
                $result['totals'][$owner][$field] = (int) ($result['totals'][$owner][$field] ?? 0) + (int) $value;
            }
        }
        foreach (['pending_bytes','pending_candidates'] as $field) $result[$field] += (int) ($finding[$field] ?? 0);
        $result['last_observed_at'] = max($result['last_observed_at'], (int) ($measured['observed_at'] ?? 0));
        $stamp = (int) ($measured['completed_at'] ?? 0);
        if ($stamp > 0) $result['oldest_completed_at'] = $result['oldest_completed_at'] > 0 ? min($result['oldest_completed_at'], $stamp) : $stamp;
        $result['sample'] = array_slice(array_merge($result['sample'], $finding['sample'] ?? []), 0, CACHE_LIFECYCLE_PREVIEW_SAMPLES);
    }
    $result['partial'] = !$discoveryComplete || $result['regions_unverified'] > 0 || $result['errors'] > 0 || $result['blocked'] > 0;
    $result['complete'] = !$result['partial'];
    // An upgraded installation retains its last whole-cache observation until
    // every newly registered region has a completed measurement. It is explicitly
    // historical and is never combined with partial regional numbers.
    $result['legacy_measurement'] = false;
    if ((!$discoveryComplete || $result['regions_unverified'] > 0) && !empty($legacy['legacy_totals'])) {
        $result['totals'] = $legacy['legacy_totals'];
        $result['legacy_measurement'] = true;
        $result['measurement_available'] = true;
        $result['last_observed_at'] = (int) ($legacy['legacy_observed_at'] ?? 0);
    }
    if ($result['regions_working'] === 0 && !$discoveryWorking && $result['regions_total'] > 0) $result['phase'] = 'complete';
    $result['bytes'] = $result['files'] = $result['dirs'] = 0;
    foreach ($result['totals'] as $total) foreach (['bytes','files','dirs'] as $field) $result[$field] += (int) ($total[$field] ?? 0);
    $result['candidates'] = $result['pending_candidates'];
    $result['candidate_bytes'] = $result['pending_bytes'];
    return $result;
}
