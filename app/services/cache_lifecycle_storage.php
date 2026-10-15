<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle_storage.php
 * Module Type: Service
 * Purpose: Share lightweight cache ownership, guarded checkpoints and passive status.
 * Responsibilities: Coordinate producer leases and report lifecycle evidence without loading inventory or reclamation workers.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

require_once __DIR__ . '/updates_path_safety.php';
require_once __DIR__ . '/cache_lifecycle_registry_storage.php';

/**
 * Bound compact control records before JSON decoding.
 * Type: int. Units: bytes. Scope: lifecycle state and producer proof reads.
 * Consumers: state/proof readers.
 * Rationale: one MiB accommodates aggregate counters and traversal frames; file catalogs and candidates stay in streamed private journals.
 */
const CACHE_LIFECYCLE_STATE_BYTES = 1048576;

/**
 * Limit the authenticated preview's illustrative candidate list.
 * Type: int. Units: candidates. Scope: compact state and browser summary.
 * Consumers: inventory plan writer and Admin sample disclosure.
 * Rationale: sixteen relative identities make the plan reviewable without serializing an unbounded deletion list.
 */
const CACHE_LIFECYCLE_PREVIEW_SAMPLES = 16;

/**
 * Refresh finished observations without restarting unfinished scans.
 * Type: int. Units: seconds. Scope: lifecycle observation refresh and stale evidence.
 * Consumers: scheduled admission and authenticated status.
 * Rationale: thirty-second observations keep sparse-traffic installations responsive; the server chain supplies subsequent opportunities without another visitor.
 */
const CACHE_LIFECYCLE_REFRESH_SECONDS = 30;

/**
 * Allow three observation intervals before reporting missing worker evidence.
 * Type: int. Units: seconds. Scope: authenticated scheduler health and observation age.
 * Consumers: passive lifecycle status. Rationale: ninety seconds allows bounded work and transport latency without calling every timer boundary an outage.
 */
const CACHE_LIFECYCLE_HEALTH_SECONDS = 90;

/**
 * Bound one autonomous worker's admission independently of its waiting period.
 * Type: int. Units: seconds. Scope: one token-authenticated cache-chain request.
 * Consumers: chained worker admission. Rationale: five seconds advances sparse-traffic backlogs while retaining durable slice checkpoints and foreground priority.
 */
const CACHE_LIFECYCLE_CHAIN_WORK_SECONDS = 5;

/**
 * Bound a timer handoff before issuing another independent HTTP request.
 * Type: int. Units: seconds. Scope: one autonomous timer hop.
 * Consumers: cache-chain waiting. Rationale: at most twenty seconds of waiting plus five seconds of work leaves room within a typical thirty-second request limit; lower declared limits reduce the wait further.
 */
const CACHE_LIFECYCLE_CHAIN_WAIT_SECONDS = 20;

/**
 * Expire a queued request that never arrives without duplicating a live worker.
 * Type: int. Units: seconds. Scope: one private chain ticket.
 * Consumers: chain startup and authenticated continuation. Rationale: ninety seconds tolerates queued HTTP work; a separate kernel lock still excludes an overlapping live request after the lease expires.
 */
const CACHE_LIFECYCLE_CHAIN_LEASE_SECONDS = 90;

/**
 * Coalesce actual reclamation into occasional Admin log entries.
 * Type: int. Units: seconds. Scope: one installation-wide reclamation summary.
 * Consumers: cache log aggregation. Rationale: a five-minute minimum separates successful summaries even across thirty-second scan cycles; empty scans and individual paths are never logged.
 */
const CACHE_LIFECYCLE_LOG_SECONDS = 300;

/**
 * Expire an abandoned manual preview without approving its original identity.
 * Type: int. Units: seconds. Scope: manual-preview idle time.
 * Consumers: scheduled lifecycle admission.
 * Rationale: preserve the existing fifteen-minute review grace independently of the thirty-second automatic cadence.
 */
const CACHE_LIFECYCLE_MANUAL_IDLE_SECONDS = 900;

/**
 * Refuse timer chains on built-in servers whose only worker would block public requests.
 * @param string $sapi PHP server interface; production defaults to the actual interface.
 * @param string|null $workers Declared built-in server pool size, or null to read PHP_CLI_SERVER_WORKERS.
 * @param bool $windows Whether the runtime lacks supported built-in server multiprocessing; defaults to the actual platform.
 * @return bool False for single-worker cli-server and every Windows cli-server; true for other server interfaces or a supported pool of at least two workers. Short visitor opportunities remain available on refusal.
 */
function cache_lifecycle_chain_runtime_available(string $sapi = PHP_SAPI, ?string $workers = null, bool $windows = DIRECTORY_SEPARATOR === '\\'): bool
{
    if ($sapi !== 'cli-server') return true;
    return !$windows && (int) ($workers ?? getenv('PHP_CLI_SERVER_WORKERS')) >= 2;
}

/**
 * Resolve the installation root with an explicit disposable-fixture override.
 * @return string Canonical installation directory; tests can select CACHE_LIFECYCLE_TEST_ROOT.
 */
function cache_lifecycle_project_root(): string
{
    return defined('CACHE_LIFECYCLE_TEST_ROOT') ? (string) constant('CACHE_LIFECYCLE_TEST_ROOT') : dirname(__DIR__, 2);
}

/**
 * Validate every ancestor of a cache-relative path before reading or mutating it.
 * @param string $relative Portable relative path beneath cache.
 * @return string Guarded absolute path, or throws for unsafe links/redirects/traversal.
 */
function cache_lifecycle_path(string $relative): string
{
    $root = cache_lifecycle_project_root();
    application_update_assert_safe_target($root, 'cache' . ($relative !== '' ? '/' . $relative : ''), true);
    return $root . '/cache' . ($relative !== '' ? '/' . $relative : '');
}

/**
 * Reject an absolute I/O destination unless every existing ancestor remains inside the guarded cache root.
 * @param string $path Absolute cache file or directory being read, written or renamed.
 * @return void Throws for outside paths, links and redirecting ancestors before I/O starts.
 */
function cache_lifecycle_assert_path(string $path): void
{
    $prefix = str_replace('\\', '/', cache_lifecycle_project_root()) . '/cache/';
    $normalized = str_replace('\\', '/', $path);
    if (!str_starts_with($normalized, $prefix)) throw new \RuntimeException('Cache lifecycle path is outside its owner.');
    cache_lifecycle_path(substr($normalized, strlen($prefix)));
}

/**
 * Resolve the lifecycle clock with a deterministic disposable-fixture override.
 * @return int Unix seconds; production reads the system clock, disposable fixtures may advance a gated test clock global.
 */
function cache_lifecycle_now(): int
{
    if (defined('CACHE_LIFECYCLE_TEST_ROOT') && isset($GLOBALS['cache_lifecycle_test_now'])) return (int) $GLOBALS['cache_lifecycle_test_now'];
    return defined('CACHE_LIFECYCLE_TEST_NOW') ? (int) constant('CACHE_LIFECYCLE_TEST_NOW') : time();
}

/**
 * Read bounded JSON control/proof data without trusting empty or oversized input.
 * @param string $path Guarded absolute control/proof path.
 * @return array<string,mixed> Decoded producer-defined record, or empty for absent/unreadable/oversized/invalid data; consumers validate its schema.
 */
function cache_lifecycle_read_json(string $path): array
{
    cache_lifecycle_assert_path($path);
    if (cache_lifecycle_registry_state_path($path)) return cache_lifecycle_registry_checkpoint();
    if (!is_file($path) || is_link($path)) return [];
    $json = @file_get_contents($path, false, null, 0, CACHE_LIFECYCLE_STATE_BYTES + 1);
    $control = str_replace('\\', '/', $path) === str_replace('\\', '/', cache_lifecycle_project_root()) . '/cache/cache-lifecycle/state.json';
    if (!is_string($json) || strlen($json) > CACHE_LIFECYCLE_STATE_BYTES) {
        if ($control) throw new \RuntimeException('Cache lifecycle checkpoint is unreadable.');
        return [];
    }
    $decoded = json_decode($json, true);
    if ($control && (!is_array($decoded) || !isset($decoded['schema'], $decoded['id'], $decoded['phase']))) {
        throw new \RuntimeException('Cache lifecycle checkpoint is invalid.');
    }
    return is_array($decoded) ? $decoded : [];
}

/**
 * Atomically commit bounded control state while its owner holds the worker lock.
 * @param string $path Guarded absolute destination.
 * @param array<string,mixed> $record Lifecycle schema with id/phase, bounded stack/totals, journal offsets and actual reclamation counters; producer leases use their own scalar metadata.
 * @return void Commits a complete record or throws without replacing existing state.
 */
function cache_lifecycle_write_json(string $path, array $record): void
{
    cache_lifecycle_assert_path($path);
    if (cache_lifecycle_registry_state_path($path)) { cache_lifecycle_registry_store($record); return; }
    cache_lifecycle_assert_path($path . '.tmp');
    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($json) > CACHE_LIFECYCLE_STATE_BYTES) throw new \RuntimeException('Cache lifecycle state exceeded its bound.');
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !@rename($temporary, $path)) {
        throw new \RuntimeException('Cache lifecycle checkpoint could not be committed.');
    }
}

/**
 * Resolve/create the private control directory without enumerating the cache.
 * @return string Guarded absolute lifecycle state directory.
 */
function cache_lifecycle_control_directory(): string
{
    $directory = cache_lifecycle_path('cache-lifecycle');
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new \RuntimeException('Cache lifecycle control directory is unavailable.');
    }
    return $directory;
}

/**
 * Hold a producer's shared lease outside its payload so exclusive GC cannot detach live work.
 * @param string $relative Exact cache-relative producer payload identity, identical to its reclamation plan path.
 * @return resource|null Shared locked handle for the caller to retain/close, or null when exclusive reclamation owns it.
 */
function cache_lifecycle_producer_lease(string $relative): mixed
{
    $leasePath = cache_lifecycle_lease_path($relative);
    $handle = @fopen($leasePath, 'c');
    if ($handle === false) return null;
    if (!flock($handle, LOCK_SH | LOCK_NB)) { fclose($handle); return null; }
    return $handle;
}

/**
 * Resolve one of 256 stable producer lease slots instead of accumulating one lock inode per report.
 * @param string $relative Exact guarded cache-relative producer identity.
 * @return string Guarded absolute bucket lock path; collisions conservatively protect unrelated payloads while a producer is active.
 */
function cache_lifecycle_lease_path(string $relative): string
{
    cache_lifecycle_path($relative);
    $directory = cache_lifecycle_control_directory() . '/leases';
    cache_lifecycle_assert_path($directory);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new \RuntimeException('Cache producer lease directory is unavailable.');
    }
    // Stable two-hex-digit buckets cap live coordination at 256 inodes.
    // Type: string. Units: one SHA-256 prefix of two hexadecimal characters.
    // Scope: cache producer/GC lease identity. Consumers: audit/GitHub producers and reclamation.
    // Rationale: collisions safely defer GC; lock files remain stable across process death and never grow with report history.
    $bucket = substr(hash('sha256', $relative), 0, 2);
    $path = $directory . '/' . $bucket . '.lock';
    cache_lifecycle_assert_path($path);
    return $path;
}

/**
 * Recognize the native historical flat ZIP and temporary-output namespaces.
 * @param string $relative Cache-relative payload path.
 * @return array{base:string,partial:bool}|null Completed archive identity and partial flag, or null outside the exact historical producer formats; this classification alone never permits deletion.
 */
function cache_lifecycle_flat_download_identity(string $relative): ?array
{
    if (preg_match('#^(zips/(?:(?:gallery|smart-gallery|selected)-[1-9][0-9]*-|all-)(?:[a-f0-9]{40}|[a-f0-9]{64})\.zip)(?:(\.partial-[a-f0-9]{16}|\.[a-zA-Z0-9]{6}(?:\.part)?))?$#D', $relative, $match) !== 1) return null;
    return ['base'=>$match[1],'partial'=>isset($match[2]) && $match[2] !== ''];
}

/**
 * Protect a historical flat ZIP while its existing producer writes or serves it.
 * @param string $path Absolute archive or native temporary-output path selected by the download owner.
 * @return resource|null Shared base-identity lease, or null outside the guarded installation cache; throws when reclamation owns that identity.
 */
function cache_lifecycle_download_lease(string $path): mixed
{
    $prefix = str_replace('\\', '/', cache_lifecycle_project_root()) . '/cache/';
    $normalized = str_replace('\\', '/', $path);
    if (!str_starts_with($normalized, $prefix)) return null;
    $identity = cache_lifecycle_flat_download_identity(substr($normalized, strlen($prefix)));
    if ($identity === null) return null;
    $lease = cache_lifecycle_producer_lease($identity['base']);
    if (!is_resource($lease)) throw new \RuntimeException('Download cache reclamation owns this archive.');
    return $lease;
}

/**
 * Return bounded public progress and aggregate category measurements without scanning files.
 * @return array{ok:bool,id:string,phase:string,complete:bool,partial:bool,bytes:int,files:int,dirs:int,candidates:int,candidate_bytes:int,removed:int,bytes_removed:int,skipped:int,errors:int,blocked:int,last_error:string,excluded:string,scheduled:bool,pending_bytes:int,pending_candidates:int,last_attempt_at:int,last_progress_at:int,last_observed_at:int,next_due:int,stale:bool,stalled:bool,scheduler_health:string,scheduler_reason:string,scheduler_source:string,chain_health:string,chain_arrival_at:int,chain_next_start_at:int,snapshot_at?:int,worker_state?:string,operation?:array{id:string,phase:string,files:int,bytes:int,candidates:int,candidate_bytes:int,pending_bytes:int,removed:int,bytes_removed:int,skipped:int,errors:int,blocked:int,partial:bool},unverified_paths?:list<array{path:string,reason:string}>,interrupted:bool,superseded_manual_at:int,measurement_available:bool,registry?:bool,registry_unavailable?:bool,regions_total?:int,regions_completed?:int,regions_working?:int,regions_unverified?:int,oldest_completed_at?:int,last_removed_at?:int,working_bytes?:int,legacy_measurement?:bool,regions_sample?:list<array{path:string,phase:string,round:int,last_serviced_at:int,last_completed_at:int,next_due_at:int,retry_at:int,failures:int,cursor_path:string,cursor_offset:int}>,sample:list<array{path:string,owner:string,bytes:int}>,policies:array<string,array{label:string,seconds:int|null,mode:string,reason:string}>,totals:array<string,array<string,int>>} Authenticated summary: historical observations differ from pending debt and real progress; chain fields disclose safe arrival/timing evidence but never tickets, URLs or credentials.
 */
function cache_lifecycle_status(): array
{
    $state = cache_lifecycle_read_json(cache_lifecycle_path('cache-lifecycle/state.json'));
    $totals = is_array($state['totals'] ?? null) ? $state['totals'] : [];
    $bytes = $files = $dirs = 0;
    foreach ($totals as $total) {
        $bytes += max(0, (int) ($total['bytes'] ?? 0));
        $files += max(0, (int) ($total['files'] ?? 0));
        $dirs += max(0, (int) ($total['dirs'] ?? 0));
    }
    $scheduler = cache_lifecycle_read_json(cache_lifecycle_path('cache-lifecycle/scheduler.json'));
    $chain = cache_lifecycle_read_json(cache_lifecycle_path('cache-lifecycle/chain.json'));
    $now = cache_lifecycle_now();
    $attempt = (int) ($scheduler['last_attempt_at'] ?? 0);
    $previous = (int) ($scheduler['previous_attempt_at'] ?? 0);
    $progress = (int) ($scheduler['last_progress_at'] ?? 0);
    $pending = max(0, (int) ($state['candidate_bytes'] ?? 0) - (int) ($state['settled_bytes'] ?? 0));
    if (is_array($state['current'] ?? null)) $pending = max(0, $pending - min((int) ($state['current']['bytes'] ?? 0), (int) ($state['bytes_removed'] ?? 0) - (int) ($state['current']['removed_before'] ?? $state['bytes_removed'] ?? 0)));
    $scheduled = !empty($state['scheduled']);
    if (($state['phase'] ?? '') === 'complete') $pending = 0;
    $summary = ['ok'=>true,'id'=>(string) ($state['id'] ?? ''),'phase'=>(string) ($state['phase'] ?? 'idle'),
        'complete'=>in_array($state['phase'] ?? '', ['preview','cleanup','complete'], true),'partial'=>!empty($state['errors']) || !empty($state['blocked']),
        'bytes'=>$bytes,'files'=>$files,'dirs'=>$dirs,'candidates'=>(int) ($state['candidates'] ?? 0),
        'candidate_bytes'=>(int) ($state['candidate_bytes'] ?? 0),'removed'=>(int) ($state['removed'] ?? 0),
        'bytes_removed'=>(int) ($state['bytes_removed'] ?? 0),'skipped'=>(int) ($state['skipped'] ?? 0),
        'errors'=>(int) ($state['errors'] ?? 0),'blocked'=>(int) ($state['blocked'] ?? 0),
        'last_error'=>(string) ($state['last_error'] ?? ''),'excluded'=>'cache-lifecycle',
        'scheduled'=>$scheduled,'pending_bytes'=>$pending,
        'pending_candidates'=>($state['phase'] ?? '') === 'complete' ? 0 : max(0, (int) ($state['candidates'] ?? 0) - (int) ($state['settled_candidates'] ?? 0)),
        'last_attempt_at'=>$attempt,'last_progress_at'=>$progress,
        'last_observed_at'=>(int) ($state['last_observed_at'] ?? 0),'next_due'=>($state['phase'] ?? '') === 'complete'
            ? (int) ($state['completed_at'] ?? 0) + CACHE_LIFECYCLE_REFRESH_SECONDS : (int) ($state['next_due'] ?? 0),
        'stale'=>empty($state['last_observed_at']) || $now - (int) $state['last_observed_at'] > CACHE_LIFECYCLE_HEALTH_SECONDS,
        'stalled'=>$attempt > 0 && !in_array($state['phase'] ?? '', ['complete','preview'], true)
            && $now - ($progress ?: (int) ($scheduler['first_attempt_at'] ?? $attempt)) > CACHE_LIFECYCLE_HEALTH_SECONDS,
        'scheduler_health'=>$attempt === 0 ? 'unobserved' : ($now - $attempt > CACHE_LIFECYCLE_HEALTH_SECONDS ? 'missing'
            : ($previous > 0 && $attempt > $previous && $attempt - $previous <= CACHE_LIFECYCLE_HEALTH_SECONDS ? 'arrivals_observed' : 'single_arrival')),
        'scheduler_reason'=>(string) app_setting('feature_flag.automatic_cache_cleanup.enabled', '1') === '0'
            ? 'automatic_cache_cleanup_disabled' : ((string) ($chain['last_error'] ?? '') ?: (string) ($scheduler['reason'] ?? '')),'scheduler_source'=>(string) ($scheduler['source'] ?? ''),
        'chain_health'=>$chain === [] ? 'unstarted' : ((int) ($chain['queued_until'] ?? 0) > $now
            ? (!empty($chain['running']) ? 'waiting' : 'queued') : 'stalled'),
        'chain_arrival_at'=>(int) ($chain['last_arrival_at'] ?? 0),'chain_next_start_at'=>(int) ($chain['next_start_at'] ?? 0),
        'interrupted'=>!empty($scheduler['interrupted']),
        'superseded_manual_at'=>(int) ($state['superseded_manual_at'] ?? 0),
        'sample'=>array_slice(is_array($state['sample'] ?? null) ? $state['sample'] : [], 0, CACHE_LIFECYCLE_PREVIEW_SAMPLES),
        'policies'=>cache_lifecycle_policies(),'totals'=>$totals,'measurement_available'=>(int) ($state['last_observed_at'] ?? 0) > 0];
    if (!$scheduled && ($state['id'] ?? '') !== '') {
        // Keep manual-cycle progress separate from retained regional/lifetime measurements.
        $summary['operation'] = array_intersect_key($summary, array_flip(['id','phase','files','bytes','candidates','candidate_bytes','pending_bytes','removed','bytes_removed','skipped','errors','blocked','partial']));
    }
    if (cache_lifecycle_registry_enabled()) {
        try { $registry = cache_lifecycle_registry_summary(); }
        catch (\Throwable) {
            return array_replace($summary, ['ok'=>false,'partial'=>true,'registry_unavailable'=>true,'last_error'=>'cache_registry_unavailable']);
        }
        // Manual previews retain their explicit controls; registry findings never grant approval.
        if ($registry['regions_total'] > 0) {
            $manual = $state !== [] && ($state['phase'] ?? '') !== 'complete';
            $controls = $manual ? array_intersect_key($summary, array_flip(['id','phase','scheduled','candidates','candidate_bytes','sample'])) : [];
            $summary = array_replace($summary, $registry, $controls);
            $summary['stale'] = $registry['oldest_completed_at'] === 0
                || $now - $registry['oldest_completed_at'] > CACHE_REGISTRY_RECHECK_SECONDS;
        }
    }
    $summary['snapshot_at'] = $now;
    $summary['stalled'] = empty($summary['registry']) ? $summary['stalled'] : $attempt > 0 && (int) ($summary['regions_working'] ?? 0) > 0
        && $now - ($progress ?: (int) ($scheduler['first_attempt_at'] ?? $attempt)) > CACHE_LIFECYCLE_HEALTH_SECONDS;
    $summary['worker_state'] = match (true) {
        $summary['scheduler_reason'] === 'automatic_cache_cleanup_disabled' => 'disabled',
        empty($summary['ok']) => 'unavailable',
        $summary['scheduler_health'] === 'missing' || $summary['scheduler_health'] === 'unobserved' => 'missing',
        $summary['stalled'] => 'stalled',
        !empty($summary['partial']) => 'partial',
        (int) ($summary['regions_working'] ?? 0) > 0 || $summary['phase'] === 'inventory' => 'working',
        default => 'waiting',
    };
    return $summary;
}

/**
 * Define stable cache categories with producer-specific retention rather than a blanket TTL.
 * @return array<string,array{label:string,seconds:int|null,mode:string,reason:string}> Stable owner keys, lifetime in seconds when time-based, and protection rationale.
 */
function cache_lifecycle_policies(): array
{
    // These lifetimes are explicit producer policies, not mtime permission to delete.
    // Type: array<string,array{label:string,seconds:int|null,mode:string,reason:string}>.
    // Units: seconds or null for contextual/durable ownership. Scope: cache lifecycle.
    // Consumers: preview classification, scheduled reclamation and the Admin policy table.
    // Rationale: completed diagnostics need one day, downloads seven days, and recovery/user data must outlive time-based caches.
    return [
        'audits' => ['label'=>'Test audits','seconds'=>86400,'mode'=>'expiry','reason'=>'Completed producer-verified reports; active leases and explicit pins survive.'],
        'ci_diagnostics' => ['label'=>'Downloaded CI diagnostics','seconds'=>86400,'mode'=>'expiry','reason'=>'Verified completed audit exports; unknown exports and pinned evidence survive.'],
        'admin_tests' => ['label'=>'Admin test reports','seconds'=>86400,'mode'=>'expiry','reason'=>'Finalized producer-owned reports, never live request recording.'],
        'workspaces' => ['label'=>'Disposable browser and release workspaces','seconds'=>86400,'mode'=>'expiry','reason'=>'Fixed lifetime from creation or a persisted legacy age anchor; use does not renew expiry. Pins and unsafe paths survive.'],
        'downloads' => ['label'=>'Download ZIPs','seconds'=>604800,'mode'=>'owner','reason'=>'Existing download identity, serving leases and build reservations remain authoritative.'],
        'manifests' => ['label'=>'Download manifests','seconds'=>86400,'mode'=>'owner','reason'=>'Physical metadata one day; dynamic smart metadata fifteen minutes.'],
        'partials' => ['label'=>'Download partials','seconds'=>21600,'mode'=>'owner','reason'=>'Six hours, only after exclusive build ownership permits reclamation.'],
        'github' => ['label'=>'GitHub response cache','seconds'=>86400,'mode'=>'expiry','reason'=>'Rebuildable response metadata with valid producer shape and expired cache timestamp.'],
        'drafts' => ['label'=>'SimBrief preview drafts','seconds'=>1860,'mode'=>'expiry','reason'=>'Existing thirty-minute preview expiry plus a one-minute safety margin.'],
        'updates' => ['label'=>'Updater versions and rollback','seconds'=>null,'mode'=>'context','reason'=>'Keep current/last successful recovery ownership and active jobs; reclaim older verified terminal work.'],
        'uploads' => ['label'=>'Upload idempotency and resume state','seconds'=>null,'mode'=>'owner','reason'=>'Keep unfinished upload state until the upload owner establishes completion/expiry.'],
        'media' => ['label'=>'Thumbnails and favicon derivatives','seconds'=>null,'mode'=>'context','reason'=>'Invalidate by source/version through the media owner, never by age alone.'],
        'durable' => ['label'=>'Theme, branding, Trash and persistent state','seconds'=>null,'mode'=>'preserve','reason'=>'These may contain originals, selected assets or recovery state despite their cache location.'],
        'control' => ['label'=>'Maintenance journals and live control state','seconds'=>null,'mode'=>'preserve','reason'=>'Owned jobs, locks and inventory checkpoints support safe recovery.'],
        'unknown' => ['label'=>'Unregistered cache content','seconds'=>null,'mode'=>'preserve','reason'=>'Report actual bytes; no deletion based only on a directory name or age.'],
    ];
}
