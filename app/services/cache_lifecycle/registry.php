<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle/registry.php
 * Module Type: Service Part
 * Purpose: Rotate due cache regions through the existing guarded inventory and deletion owners.
 * Responsibilities: Discover new roots, yield partial work, preserve legacy debt and retire bounded drained tombstones.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Initialize only the fixed registry identities after legacy work is fully drained.
 * @param array{removed?:int,bytes_removed?:int,last_removed_at?:int,totals?:array<string,CacheRegistryOwnerTotals>,last_observed_at?:int} $legacy Completed historical JSON cycle, or empty for a fresh installation; extra legacy checkpoint fields remain owned by the existing worker.
 * @return void Registers bounded singleton owners under the existing worker lock; no schema is created.
 */
function cache_lifecycle_registry_initialize(array $legacy): void
{
    cache_lifecycle_with_lock(static function () use ($legacy): array {
        $now = cache_lifecycle_now();
        foreach (['@history','@discovery','@files'] as $path) {
            $key = hash('sha256', $path);
            if (\Gallery\Models\cache_registry_model_find($key) !== null) continue;
            \Gallery\Models\cache_registry_model_register($key, $path, 'control', '', $now, substr($path, 1));
            if ($path === '@history') {
                $receipt = ['removed'=>(int) ($legacy['removed'] ?? 0),'bytes_removed'=>(int) ($legacy['bytes_removed'] ?? 0),
                    'last_removed_at'=>(int) ($legacy['last_removed_at'] ?? 0),
                    'legacy_totals'=>$legacy['totals'] ?? [],'legacy_observed_at'=>(int) ($legacy['last_observed_at'] ?? 0)];
                \Gallery\Models\cache_registry_model_save($key, ['checkpoint_json'=>'{}','finding_json'=>json_encode($receipt, JSON_THROW_ON_ERROR),
                    'completed_json'=>'{}','last_serviced_at'=>0,'last_completed_at'=>0,'next_due_at'=>$now,'retry_at'=>0,'failures'=>0,'drained'=>1]);
            }
        }
        return ['ok'=>true];
    });
}

/**
 * Discover top-level directories with a durable root catalog and one root-files region.
 * @param float $seconds Remaining filesystem admission, capped by the ordinary inventory slice.
 * @return CacheLifecycleCheckpoint Committed discovery checkpoint or a busy marker; only a complete readable catalog establishes absence.
 */
function cache_lifecycle_registry_discover(float $seconds): array
{
    return cache_lifecycle_with_lock(static function () use ($seconds): array {
        $directory = cache_lifecycle_job_directory();
        $state = cache_lifecycle_registry_checkpoint();
        $before = $state;
        $now = cache_lifecycle_now();
        if ($state === [] || $state['phase'] === 'complete') {
            $state = ['schema'=>2,'id'=>bin2hex(random_bytes(16)),'phase'=>'inventory','offset'=>0,
                'observation_round'=>(int) ($state['observation_round'] ?? 0) + 1,
                'catalog_ready'=>false,'started_at'=>$now,'last_observed_at'=>0,'errors'=>0,'blocked'=>0,
                'removed'=>0,'bytes_removed'=>0,'totals'=>[],'region_count'=>count(\Gallery\Models\cache_registry_model_summaries(CACHE_REGISTRY_REGION_LIMIT))];
            cache_lifecycle_registry_store($state);
        }
        $deadline = microtime(true) + max(0.01, min(CACHE_LIFECYCLE_SLICE_SECONDS, $seconds));
        $catalogPath = $directory . '/discovery.jsonl';
        if (!$state['catalog_ready']) {
            $catalog = cache_lifecycle_catalog('', $catalogPath, $deadline);
            if (!$catalog['ok']) throw new \RuntimeException('Cache root discovery is incomplete.');
            $state['catalog_ready'] = true;
            cache_lifecycle_registry_store($state);
        }
        cache_lifecycle_assert_path($catalogPath);
        $stream = fopen($catalogPath, 'rb');
        if ($stream === false || fseek($stream, $state['offset']) !== 0) throw new \RuntimeException('Cache discovery catalog is unavailable.');
        $done = false;
        try {
            while (microtime(true) < $deadline) {
                $line = fgets($stream, 8192);
                if ($line === false) {
                    if (!feof($stream)) throw new \RuntimeException('Cache discovery catalog read failed.');
                    $done = true;
                    break;
                }
                $name = json_decode($line, true, 2, JSON_THROW_ON_ERROR);
                if (!is_string($name) || basename($name) !== $name || in_array($name, ['.','..'], true)) throw new \RuntimeException('Cache discovery identity is invalid.');
                if ($name !== 'cache-lifecycle') {
                    try {
                        $path = cache_lifecycle_path($name);
                        if (is_dir($path)) {
                            $key = hash('sha256', 'region:' . $name);
                            if (\Gallery\Models\cache_registry_model_find($key) === null) {
                                if ($state['region_count'] >= CACHE_REGISTRY_REGION_LIMIT) throw new \RuntimeException('Cache registry admission is full.');
                                $state['region_count']++;
                            }
                            \Gallery\Models\cache_registry_model_register($key, $name, cache_lifecycle_owner($name), $state['id'], $now);
                        }
                    } catch (\Throwable) {
                        // Unsafe or unreadable roots remain unknown; a partial discovery cannot establish disappearance.
                        $state['errors']++;
                    }
                }
                $offset = ftell($stream);
                if ($offset === false) throw new \RuntimeException('Cache discovery cursor is unavailable.');
                $state['offset'] = $offset;
            }
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
        $state['last_observed_at'] = $now;
        if ($done) {
            $state['phase'] = 'complete';
            if (empty($state['errors'])) \Gallery\Models\cache_registry_model_mark_missing($state['id'], $now);
            // Retire filesystem metadata before removing its drained DB owner.
            // If interrupted, an absent retired directory is safe to reconcile on retry.
            $rows = \Gallery\Models\cache_registry_model_summaries(CACHE_REGISTRY_REGION_LIMIT);
            $retired = 0;
            foreach ($rows as $row) {
                if ($retired >= 16 || microtime(true) >= $deadline) break;
                if ((int) $row['missing_since'] <= 0 || (int) $row['missing_since'] > $now - CACHE_REGISTRY_RETENTION_SECONDS || empty($row['drained'])) continue;
                $source = cache_lifecycle_control_directory() . '/regions/' . $row['region_key'];
                cache_lifecycle_assert_path($source);
                $destination = cache_lifecycle_control_directory() . '/retired/region-' . $row['region_key'];
                cache_lifecycle_assert_path($destination);
                if (is_dir($source)) {
                    if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0775, true);
                    if (!rename($source, $destination)) continue;
                }
                \Gallery\Models\cache_registry_model_prune((string) $row['region_key'], $now - CACHE_REGISTRY_RETENTION_SECONDS);
                $retired++;
            }
        }
        cache_lifecycle_registry_store($state);
        $progressed = false;
        foreach (['id','offset','phase','catalog_ready'] as $field) {
            if (($before[$field] ?? null) !== ($state[$field] ?? null)) $progressed = true;
        }
        return ['ok'=>true,'registry'=>true,'registry_progressed'=>$progressed] + $state;
    });
}

/**
 * Run one due region while preserving its independent checkpoint between fair turns.
 * @param float $seconds Combined filesystem admission available to this opportunity.
 * @param bool $force Whether an explicit maintainer call bypasses due timestamps while retaining retry backoff and all deletion guards.
 * @return CacheLifecycleCheckpoint Safe inventory/discovery outcome, busy/not-due marker or blocked failure; corrupt state never means an empty cache.
 */
function cache_lifecycle_registry_slice(float $seconds, bool $force = false): array
{
    $retired = cache_lifecycle_retired_slice(min(CACHE_LIFECYCLE_SLICE_SECONDS, $seconds / 4));
    if (empty($retired['ok'])) return $retired;
    $row = \Gallery\Models\cache_registry_model_due(cache_lifecycle_now(), $force);
    if ($row === null) return ['ok'=>true,'skipped'=>true,'registry'=>true,'reason'=>'cache_lifecycle_not_due'];
    $GLOBALS['cache_lifecycle_registry_context'] = $row;
    try {
        $retired = cache_lifecycle_retired_slice(min(CACHE_LIFECYCLE_SLICE_SECONDS, $seconds / 4));
        if (empty($retired['ok'])) return $retired;
        if ($row['kind'] === 'discovery') return cache_lifecycle_registry_discover($seconds);
        $state = cache_lifecycle_registry_checkpoint();
        $before = $state;
        if ($state === [] || $state['phase'] === 'complete') $state = cache_lifecycle_inventory_start(true);
        if (empty($state['ok']) && !isset($state['schema'])) return $state;
        if ($state['phase'] === 'inventory') {
            $debt = !empty($state['current']) || (int) $state['plan_offset'] < (int) $state['plan_write_offset'];
            $state = $debt && ($state['turn'] ?? 'scan') === 'cleanup'
                ? cache_lifecycle_cleanup_slice('', $seconds) : cache_lifecycle_inventory_slice('', $seconds);
        } elseif ($state['phase'] === 'preview') $state = cache_lifecycle_cleanup_start((string) $state['id']);
        elseif ($state['phase'] === 'cleanup') $state = cache_lifecycle_cleanup_slice('', $seconds);
        cache_lifecycle_with_lock(static function (): array {
            $current = cache_lifecycle_registry_checkpoint();
            $current['turn'] = ($current['turn'] ?? 'scan') === 'scan' ? 'cleanup' : 'scan';
            cache_lifecycle_registry_store($current);
            return ['ok'=>true];
        });
        cache_lifecycle_with_lock(static function (): array {
            $history = \Gallery\Models\cache_registry_model_find(hash('sha256', '@history'));
            $receipt = $history === null ? [] : cache_lifecycle_registry_decode((string) $history['finding_json']);
            if (isset($receipt['legacy_totals'])) {
                $summary = cache_lifecycle_registry_summary();
                if ($summary['complete']) {
                    unset($receipt['legacy_totals'], $receipt['legacy_observed_at']);
                    \Gallery\Models\cache_registry_model_retire_legacy(json_encode($receipt, JSON_THROW_ON_ERROR));
                }
            }
            return ['ok'=>true];
        });
        $after = cache_lifecycle_registry_checkpoint();
        $progressed = false;
        foreach (['stack','totals','plan_offset','cursor','phase','removed','bytes_removed'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) $progressed = true;
        }
        return ['registry'=>true,'registry_progressed'=>$progressed,'region_key'=>$row['region_key']] + $state;
    } catch (\Throwable $exception) {
        cache_lifecycle_log_failure('region_slice', 'region_worker', $exception);
        // Preserve the exact checkpoint and apply retry metadata without interpreting corruption as completion.
        cache_lifecycle_with_lock(static function () use ($row): array {
            $current = \Gallery\Models\cache_registry_model_find((string) $row['region_key']);
            if ($current !== null) \Gallery\Models\cache_registry_model_save((string) $row['region_key'], [
                'checkpoint_json'=>(string) $current['checkpoint_json'],'finding_json'=>(string) $current['finding_json'],
                'completed_json'=>(string) $current['completed_json'],'last_serviced_at'=>cache_lifecycle_now(),
                'last_completed_at'=>(int) $current['last_completed_at'],'next_due_at'=>(int) $current['next_due_at'],
                'retry_at'=>cache_lifecycle_now() + CACHE_REGISTRY_RETRY_SECONDS,'failures'=>(int) $current['failures'] + 1,'drained'=>(int) $current['drained'],
            ]);
            return ['ok'=>true];
        });
        return ['ok'=>false,'blocked'=>true,'registry'=>true,'reason'=>'cache_registry_region_unverified'];
    } finally {
        unset($GLOBALS['cache_lifecycle_registry_context']);
    }
}
