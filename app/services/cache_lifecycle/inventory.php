<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle/inventory.php
 * Module Type: Service Part
 * Purpose: Measure cache categories and construct streamed proof-based reclamation previews.
 * Responsibilities: Resume durable directory catalogs, bound admission and report partial observations honestly.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Start a read-only cache inventory, refusing to discard an unfinished deletion cursor.
 * @param bool $scheduled Whether configured automatic retention owns this scan; manual previews await explicit approval.
 * @return array<string,mixed> Initial lifecycle state with schema/id/phase/started_at, compact stack/totals and zero candidate/reclamation counters; busy state is returned unchanged.
 */
function cache_lifecycle_inventory_start(bool $scheduled = false): array
{
    return cache_lifecycle_with_lock(static function () use ($scheduled): array {
        $control = cache_lifecycle_control_directory();
        foreach (['work','work/trash','work/catalogs','work/queued','work/coverage','work/plan.jsonl','retired'] as $relative) {
            cache_lifecycle_assert_path($control . '/' . $relative);
        }
        $old = cache_lifecycle_read_json($control . '/state.json');
        if (!$scheduled && ($old['phase'] ?? '') === 'inventory' && !empty($old['scheduled'])) {
            return ['ok'=>false,'busy'=>true,'reason'=>'scheduled_cache_inventory_active'];
        }
        if (($old['phase'] ?? '') === 'inventory' && (!$scheduled || !empty($old['scheduled']))) {
            if (!$scheduled) { $old['last_manual_at'] = cache_lifecycle_now(); cache_lifecycle_write_json($control . '/state.json', $old); }
            return ['ok'=>true] + $old;
        }
        if (($old['phase'] ?? '') === 'cleanup' || is_array($old['current'] ?? null) || is_dir($control . '/work/trash')) {
            return ['ok'=>false,'busy'=>true,'reason'=>'cache_cleanup_pending'];
        }
        if (is_dir($control . '/work')) {
            $retired = $control . '/retired';
            if (!is_dir($retired)) mkdir($retired, 0775, true);
            if (!rename($control . '/work', $retired . '/' . bin2hex(random_bytes(16)))) {
                throw new \RuntimeException('Old cache inventory journals could not be retired.');
            }
        }
        mkdir($control . '/work/catalogs', 0775, true);
        mkdir($control . '/work/queued', 0775, true);
        mkdir($control . '/work/coverage', 0775, true);
        $totals = [];
        foreach (cache_lifecycle_policies() as $owner => $policy) {
            $totals[$owner] = ['bytes'=>0,'files'=>0,'dirs'=>0,'oldest'=>0,'newest'=>0,'reclaimable_bytes'=>0,
                'age_24h_bytes'=>0,'age_7d_bytes'=>0,'age_older_bytes'=>0];
        }
        $state = ['schema'=>1,'id'=>bin2hex(random_bytes(16)),'phase'=>'inventory','started_at'=>cache_lifecycle_now(),'scheduled'=>$scheduled,
            'completed_at'=>0,'stack'=>[cache_lifecycle_frame('')],'totals'=>$totals,'errors'=>0,'blocked'=>0,
            'candidates'=>0,'candidate_bytes'=>0,'plan_offset'=>0,'plan_write_offset'=>0,'current'=>null,'cursor'=>[],
            'removed'=>0,'bytes_removed'=>0,'skipped'=>0,'settled_bytes'=>0,'settled_candidates'=>0,
            'last_manual_at'=>$scheduled ? 0 : cache_lifecycle_now(),'last_observed_at'=>0,'metadata_bytes'=>0,'queue_index_ready'=>true];
        if ($scheduled && !empty($old['id']) && empty($old['scheduled']) && in_array($old['phase'] ?? '', ['inventory','preview'], true)) {
            $state['superseded_manual_id'] = $old['id'];
            $state['superseded_manual_at'] = cache_lifecycle_now();
        }
        file_put_contents($control . '/work/plan.jsonl', '');
        cache_lifecycle_write_json($control . '/state.json', $state);
        return ['ok'=>true] + $state;
    });
}

/**
 * Persist a directory-name catalog once, refusing dense/slow directories rather than replaying their prefix.
 * @param string $relative Guarded cache-relative directory.
 * @param string $destination Private catalog destination under the current work directory.
 * @param float $deadline Admission deadline in microtime(true) seconds.
 * @param int $maxBytes Remaining private metadata allowance in bytes; refused catalogs never consume committed budget.
 * @return array{ok:bool,observations:int,reason:string} Complete stable name journal or density/deadline/unreadable refusal; never persists a half-catalog as complete.
 */
function cache_lifecycle_catalog(string $relative, string $destination, float $deadline, int $maxBytes = CACHE_LIFECYCLE_METADATA_BYTES): array
{
    cache_lifecycle_assert_path($destination);
    cache_lifecycle_assert_path($destination . '.tmp');
    $handle = @opendir(cache_lifecycle_path($relative));
    if ($handle === false) return ['ok'=>false,'observations'=>0,'reason'=>'unreadable'];
    $names = [];
    $bytes = 0;
    $ok = false;
    try {
        while (count($names) <= CACHE_LIFECYCLE_DIRECTORY_NAMES && microtime(true) < $deadline) {
            $name = readdir($handle);
            if ($name === false) { $ok = true; break; }
            if ($name === '.' || $name === '..') continue;
            $bytes += strlen(json_encode($name, JSON_THROW_ON_ERROR)) + 1;
            if ($bytes > $maxBytes) return ['ok'=>false,'observations'=>count($names)+1,'reason'=>'metadata_limit'];
            $names[] = $name;
        }
    } finally {
        closedir($handle);
    }
    if (!$ok || count($names) > CACHE_LIFECYCLE_DIRECTORY_NAMES) return ['ok'=>false,'observations'=>count($names),
        'reason'=>count($names) > CACHE_LIFECYCLE_DIRECTORY_NAMES ? 'density' : 'deadline'];
    $stream = fopen($destination . '.tmp', 'wb');
    if ($stream === false) throw new \RuntimeException('Cache inventory catalog is unavailable.');
    try {
        foreach ($names as $name) {
            $line = json_encode($name, JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($stream, $line) !== strlen($line)) throw new \RuntimeException('Cache inventory catalog write failed.');
        }
    } finally {
        fclose($stream);
    }
    if (!rename($destination . '.tmp', $destination)) throw new \RuntimeException('Cache inventory catalog commit failed.');
    return ['ok'=>true,'observations'=>count($names),'reason'=>''];
}

/**
 * Append one whole-subtree producer-approved preview without retaining an unbounded candidate list in memory.
 * @param array<string,mixed> $state Current lifecycle schema, updated candidate counts and category reclaimable bytes.
 * @param array{path:string,offset:int,catalog:string,catalog_ok:bool,catalog_attempts:int,bytes:int,files:int,dirs:int,newest:int,pinned:bool,unsafe:bool} $frame Fully measured traversal frame.
 * @param string $owner Validated producer policy key.
 * @return void Appends a private plan record with path/owner, measured bytes/files, proof hash and root metadata identity.
 */
function cache_lifecycle_record_candidate(array &$state, array $frame, string $owner): void
{
    $path = cache_lifecycle_path($frame['path']);
    $stat = @lstat($path);
    if (!is_array($stat)) return;
    // Observation and queue admission are separate: a fresh round measures an
    // eligible root again without duplicating its committed deletion record.
    $state['totals'][$owner]['reclaimable_bytes'] += $frame['bytes'];
    $identity = cache_lifecycle_control_directory() . '/work/queued/' . hash('sha256', $frame['path']) . '.json';
    if (cache_lifecycle_queue_contains($identity, $state)) return;
    $coverage = cache_lifecycle_control_directory() . '/work/coverage/';
    if (cache_lifecycle_queue_contains($coverage . hash('sha256', $frame['path']) . '.json', $state)) return;
    $parents = [];
    for ($parent = dirname($frame['path']); $parent !== '.'; $parent = dirname($parent)) {
        if (cache_lifecycle_queue_contains(dirname($identity) . '/' . hash('sha256', $parent) . '.json', $state)) return;
        $parents[] = $coverage . hash('sha256', $parent) . '.json';
    }
    $proof = in_array($owner, ['github','drafts'], true) ? $frame['path']
        : $frame['path'] . '/' . ($owner === 'ci_diagnostics' ? 'latest.json' : ($owner === 'updates' ? 'job.json' : 'report.json'));
    if ($owner === 'downloads') $proof = dirname($frame['path']) . '/metadata.json';
    if ($owner === 'workspaces') $proof = cache_lifecycle_workspace_age_path($frame['path']);
    $record = ['path'=>$frame['path'],'owner'=>$owner,'bytes'=>$frame['bytes'],'files'=>$frame['files'],
        'newest'=>$frame['newest'],'root_mtime'=>(int) $stat['mtime'],'root_ctime'=>(int) $stat['ctime'],
        'proof'=>$proof,'proof_hash'=>hash('sha256', json_encode(cache_lifecycle_read_json(cache_lifecycle_path($proof)), JSON_THROW_ON_ERROR))];
    $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
    $identityRecord = ['id'=>$state['id'],'offset'=>(int) $state['plan_write_offset']];
    $identityBytes = strlen(json_encode($identityRecord, JSON_THROW_ON_ERROR)) * (count($parents) + 1);
    if ((int) ($state['metadata_bytes'] ?? 0) + strlen($line) + $identityBytes > CACHE_LIFECYCLE_METADATA_BYTES) {
        $state['blocked']++;
        $state['last_error'] = 'inventory_metadata_limit';
        return;
    }
    cache_lifecycle_assert_path(cache_lifecycle_control_directory() . '/work/plan.jsonl');
    // A marker left before the state commit is not proof of queue membership:
    // its offset is at/after the persisted write fence and will be replaced.
    $indexDirectory = dirname($identity);
    cache_lifecycle_assert_path($indexDirectory);
    if (!is_dir($indexDirectory)) mkdir($indexDirectory, 0775, true);
    cache_lifecycle_write_json($identity, $identityRecord);
    $coverageDirectory = rtrim($coverage, '/');
    cache_lifecycle_assert_path($coverageDirectory);
    if (!is_dir($coverageDirectory)) mkdir($coverageDirectory, 0775, true);
    foreach ($parents as $marker) {
        if (!cache_lifecycle_queue_contains($marker, $state)) cache_lifecycle_write_json($marker, $identityRecord);
    }
    if (file_put_contents(cache_lifecycle_control_directory() . '/work/plan.jsonl', $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
        throw new \RuntimeException('Cache preview journal could not be appended.');
    }
    $state['candidates']++;
    $state['candidate_bytes'] += $frame['bytes'];
    $state['plan_write_offset'] += strlen($line);
    $state['metadata_bytes'] = (int) ($state['metadata_bytes'] ?? 0) + strlen($line) + $identityBytes;
    $state['sample'] ??= [];
    if (count($state['sample']) < CACHE_LIFECYCLE_PREVIEW_SAMPLES) {
        $state['sample'][] = ['path'=>$record['path'],'owner'=>$owner,'bytes'=>$frame['bytes']];
    }
}

/**
 * Validate compact queue membership against the durable plan write fence.
 * @param string $path Guarded private path/ancestor marker destination.
 * @param array<string,mixed> $state Cycle id and committed plan_write_offset; uncommitted crash markers must not suppress resumed admission.
 * @return bool True for committed membership, false for absent/uncommitted markers; invalid existing evidence throws to prevent duplicate or overlapping admission.
 */
function cache_lifecycle_queue_contains(string $path, array $state): bool
{
    cache_lifecycle_assert_path($path);
    if (!file_exists($path)) return false;
    $marker = cache_lifecycle_read_json($path);
    if (!is_string($marker['id'] ?? null) || !is_int($marker['offset'] ?? null) || $marker['offset'] < 0) {
        throw new \RuntimeException('Cache queue identity is invalid.');
    }
    return $marker['id'] === $state['id'] && $marker['offset'] < (int) $state['plan_write_offset'];
}

/**
 * Continue a bounded, non-destructive scan using durable catalog offsets.
 * @param string $expectedId Optional exact scan identity; scheduled calls omit it, browser continuation must match it.
 * @param float $maxSeconds Admission time in seconds, capped by the canonical slice limit; visitor calls lower it.
 * @param int $maxEntries Maximum observations, capped by the canonical slice limit; catalogs reserve their complete bounded name allowance.
 * @return array<string,mixed> Lifecycle state with measured totals, candidate counts and explicit errors/blocked directories; preview phase means traversal ended, not that partial data became exact.
 */
function cache_lifecycle_inventory_slice(string $expectedId = '', float $maxSeconds = CACHE_LIFECYCLE_SLICE_SECONDS, int $maxEntries = CACHE_LIFECYCLE_SLICE_ENTRIES): array
{
    return cache_lifecycle_with_lock(static function () use ($expectedId, $maxSeconds, $maxEntries): array {
        $control = cache_lifecycle_control_directory();
        $state = cache_lifecycle_read_json($control . '/state.json');
        if ($expectedId !== '' && !hash_equals((string) ($state['id'] ?? ''), $expectedId)) return ['ok'=>false,'reason'=>'stale_cache_preview'];
        if (($state['phase'] ?? '') !== 'inventory') return ['ok'=>true] + $state;
        if (empty($state['scheduled'])) $state['last_manual_at'] = cache_lifecycle_now();
        // A live scan can span a deployment that registers another owner. Keep
        // its accumulated counters without inventing observations for prior slices.
        foreach (cache_lifecycle_policies() as $owner => $policy) {
            $state['totals'][$owner] ??= ['bytes'=>0,'files'=>0,'dirs'=>0,'oldest'=>0,'newest'=>0,'reclaimable_bytes'=>0,
                'age_24h_bytes'=>0,'age_7d_bytes'=>0,'age_older_bytes'=>0];
        }
        cache_lifecycle_assert_path($control . '/work/plan.jsonl');
        // Roll back an append left behind by a crash before its state commit.
        $plan = fopen($control . '/work/plan.jsonl', 'c+b');
        if ($plan === false) throw new \RuntimeException('Cache preview journal is unavailable.');
        try {
            $stat = fstat($plan);
            if (!is_array($stat) || $stat['size'] < (int) ($state['plan_write_offset'] ?? 0)) {
                throw new \RuntimeException('Cache preview journal lost committed records.');
            }
            if (!ftruncate($plan, (int) ($state['plan_write_offset'] ?? 0))) {
                throw new \RuntimeException('Cache preview journal recovery failed.');
            }
        } finally {
            fclose($plan);
        }
        $maxSeconds = max(0.01, min(CACHE_LIFECYCLE_SLICE_SECONDS, $maxSeconds));
        $maxEntries = max(1, min(CACHE_LIFECYCLE_SLICE_ENTRIES, $maxEntries));
        $deadline = microtime(true) + $maxSeconds;
        $observations = 0;
        while ($state['stack'] !== [] && $observations < $maxEntries && microtime(true) < $deadline) {
            $index = count($state['stack']) - 1;
            $frame = $state['stack'][$index];
            try {
                if ($frame['catalog'] === '') {
                    // Reserve the entire bounded catalog before starting it. A
                    // narrow remaining operation budget must not falsely mark a
                    // directory unsafe or repeatedly replay an admitted prefix.
                    if ($maxEntries - $observations < CACHE_LIFECYCLE_DIRECTORY_NAMES + 1
                        || $deadline - microtime(true) < min(CACHE_LIFECYCLE_CATALOG_MIN_SECONDS, $maxSeconds / 3)) break;
                    $frame['catalog'] = hash('sha256', $frame['path']) . '.jsonl';
                    $catalog = (int) ($state['metadata_bytes'] ?? 0) >= CACHE_LIFECYCLE_METADATA_BYTES
                        ? ['ok'=>false,'observations'=>0,'reason'=>'metadata_limit']
                        : cache_lifecycle_catalog($frame['path'], $control . '/work/catalogs/' . $frame['catalog'], $deadline,
                            CACHE_LIFECYCLE_METADATA_BYTES - (int) ($state['metadata_bytes'] ?? 0));
                    if ($catalog['ok']) $state['metadata_bytes'] = (int) ($state['metadata_bytes'] ?? 0)
                        + (int) filesize($control . '/work/catalogs/' . $frame['catalog']);
                    $observations += $catalog['observations'];
                    $frame['catalog_attempts'] = (int) ($frame['catalog_attempts'] ?? 0) + 1;
                    if (!$catalog['ok'] && $catalog['reason'] === 'deadline'
                        && $frame['catalog_attempts'] < CACHE_LIFECYCLE_CATALOG_ATTEMPTS) {
                        $frame['catalog'] = '';
                        $state['stack'][$index] = $frame;
                        break;
                    }
                    $frame['catalog_ok'] = $catalog['ok'];
                    $stat = @lstat(cache_lifecycle_path($frame['path']));
                    $frame['newest'] = is_array($stat) ? max((int) $stat['mtime'], (int) $stat['ctime']) : cache_lifecycle_now();
                    if (!$catalog['ok']) { $frame['unsafe'] = true; $state['blocked']++; $state['last_error'] = 'directory_catalog_' . $catalog['reason']; }
                    $state['stack'][$index] = $frame;
                }
                if ($frame['catalog_ok']) cache_lifecycle_assert_path($control . '/work/catalogs/' . $frame['catalog']);
                $stream = $frame['catalog_ok'] ? @fopen($control . '/work/catalogs/' . $frame['catalog'], 'rb') : false;
                $line = false;
                if (is_resource($stream)) {
                    try {
                        if (fseek($stream, $frame['offset']) !== 0) throw new \RuntimeException('Cache catalog seek failed.');
                        $line = fgets($stream, 8192);
                        $offset = ftell($stream);
                        if ($offset === false || ($line === false && !feof($stream))) throw new \RuntimeException('Cache catalog read failed.');
                        $frame['offset'] = $offset;
                    } finally {
                        fclose($stream);
                    }
                } elseif ($frame['catalog_ok']) {
                    $frame['unsafe'] = true;
                    $state['errors']++;
                }
                if ($line === false) {
                    array_pop($state['stack']);
                    if ($state['stack'] !== []) {
                        $parent = count($state['stack']) - 1;
                        foreach (['bytes','files','dirs'] as $key) $state['stack'][$parent][$key] += $frame[$key];
                        $state['stack'][$parent]['newest'] = max($state['stack'][$parent]['newest'], $frame['newest']);
                        $state['stack'][$parent]['pinned'] = $state['stack'][$parent]['pinned'] || $frame['pinned'];
                        $state['stack'][$parent]['unsafe'] = $state['stack'][$parent]['unsafe'] || $frame['unsafe'];
                    }
                    cache_lifecycle_workspace_observe($frame['path']);
                    $owner = cache_lifecycle_directory_candidate($frame['path'], $frame, cache_lifecycle_now());
                    if ($owner !== null) cache_lifecycle_record_candidate($state, $frame, $owner);
                    continue;
                }
                $name = json_decode($line, true, 2, JSON_THROW_ON_ERROR);
                if (!is_string($name) || basename($name) !== $name || in_array($name, ['.','..'], true)) throw new \RuntimeException('Cache inventory catalog name is invalid.');
                $relative = ($frame['path'] !== '' ? $frame['path'] . '/' : '') . $name;
                $state['stack'][$index] = $frame;
                $observations++;
                if ($relative === 'cache-lifecycle') continue;
                $path = cache_lifecycle_path($relative);
                $stat = @lstat($path);
                if (!is_array($stat)) { $state['errors']++; $state['stack'][$index]['unsafe'] = true; continue; }
                $owner = cache_lifecycle_owner($relative);
                $newest = max((int) $stat['mtime'], (int) $stat['ctime']);
                $state['stack'][$index]['newest'] = max($state['stack'][$index]['newest'], $newest);
                if ($name === '.keep') $state['stack'][$index]['pinned'] = true;
                if (is_dir($path)) {
                    $state['totals'][$owner]['dirs']++;
                    if (count($state['stack']) >= 64) { $state['blocked']++; $state['stack'][$index]['unsafe'] = true; continue; }
                    $child = cache_lifecycle_frame($relative);
                    $child['dirs'] = 1;
                    $state['stack'][] = $child;
                } elseif (is_file($path)) {
                    $bytes = max(0, (int) $stat['size']);
                    $state['totals'][$owner]['bytes'] += $bytes;
                    $state['totals'][$owner]['files']++;
                    $state['totals'][$owner]['oldest'] = min($state['totals'][$owner]['oldest'] ?: (int) $stat['mtime'], (int) $stat['mtime']);
                    $state['totals'][$owner]['newest'] = max($state['totals'][$owner]['newest'], (int) $stat['mtime']);
                    $age = max(0, $state['started_at'] - (int) $stat['mtime']);
                    $bucket = $age < 86400 ? 'age_24h_bytes' : ($age < 604800 ? 'age_7d_bytes' : 'age_older_bytes');
                    $state['totals'][$owner][$bucket] += $bytes;
                    $state['stack'][$index]['bytes'] += $bytes;
                    $state['stack'][$index]['files']++;
                    $disposable = cache_lifecycle_file_candidate($relative, cache_lifecycle_now());
                    if ($disposable !== null) {
                        $fileFrame = cache_lifecycle_frame($relative);
                        $fileFrame['bytes'] = $bytes;
                        $fileFrame['files'] = 1;
                        $fileFrame['newest'] = $newest;
                        cache_lifecycle_record_candidate($state, $fileFrame, $disposable);
                    }
                }
            } catch (\Throwable $exception) {
                // Do not commit traversal past a partial append. The next scan
                // truncates its uncommitted journal tail before admitting work.
                if ($exception->getMessage() === 'Cache preview journal could not be appended.') throw $exception;
                $state['errors']++;
                if (isset($state['stack'][$index])) {
                    $state['stack'][$index]['unsafe'] = true;
                    $state['stack'][$index]['catalog_ok'] = false;
                    if ($state['stack'][$index]['catalog'] === '') $state['stack'][$index]['catalog'] = 'unavailable';
                }
            }
        }
        if ($state['stack'] === []) { $state['phase'] = 'preview'; $state['completed_at'] = cache_lifecycle_now(); }
        $state['last_observed_at'] = cache_lifecycle_now();
        cache_lifecycle_write_json($control . '/state.json', $state);
        return ['ok'=>true,'slice_observations'=>$observations] + $state;
    });
}
