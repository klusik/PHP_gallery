<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle/policy.php
 * Module Type: Service Part
 * Purpose: Define producer-specific lifetimes and proof-based deletion eligibility.
 * Responsibilities: Preserve active, pinned, durable and unknown storage independently of age.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Assign a cache-relative path to its existing producer or protected unknown category.
 * @param string $relative Normalized path relative to cache, never an absolute path.
 * @return string Stable policy owner; classification alone never permits deletion.
 */
function cache_lifecycle_owner(string $relative): string
{
    $root = explode('/', $relative)[0];
    if ($root === 'test-audit') return 'audits';
    if ($root === 'admin-test-runs') return 'admin_tests';
    if ($root === 'github-api') return 'github';
    if ($root === 'simbrief-drafts') return 'drafts';
    if ($root === 'updates') return 'updates';
    if ($root === 'browser_upload_batches') return 'uploads';
    if (cache_lifecycle_disposable_workspace($root)) return 'workspaces';
    if (str_starts_with($root, 'publication-v_') || in_array($root, ['theme-background','theme-branding','favicon','gallery-trash','release-qualification','release-preparation'], true)) return 'durable';
    if (in_array($root, ['cache-lifecycle','site-maintenance','admin-storage-refresh','gallery-migrations'], true)) return 'control';
    if ($root === 'zips') {
        if (str_contains($relative, '/.download-manifests/')) return 'manifests';
        if (str_contains($relative, '.partial-')) return 'partials';
        return 'downloads';
    }
    if (in_array($root, ['thumbnail-warmup','link-favicons'], true)) return 'media';
    if (preg_match('/^(?:ci-|codex-ci-|cache-cleaning-ci-|website-ci-|issue-[0-9]+-ci-)/D', $root) === 1) return 'ci_diagnostics';
    return 'unknown';
}

/**
 * Recognize only registered disposable browser profiles and versioned release working copies.
 * @param string $relative Cache-relative directory; descendants never qualify as independent workspaces.
 * @return bool True for a registered root name, false for persistent release controls or unrelated names.
 */
function cache_lifecycle_disposable_workspace(string $relative): bool
{
    return preg_match('#^(?:map-browser-profile-|panel-lifecycle-browser-|breadcrumb-browser-profile-|workflow-browser-profile-|widget-persistence-browser-profile-|dev-dashboard-profile-|lightbox-race-profile-)[A-Za-z0-9]{6}$#D', $relative) === 1
        || preg_match('#^release-(?:v_)?(?:[0-9]+\.[0-9]+(?:[.-][0-9]+)*|0[0-9]{2,}(?:-[0-9]+)*)(?:-(?:source|manual-build|pdf-(?:preview|review)(?:-final)?))?$#D', $relative) === 1;
}

/**
 * Locate immutable age evidence for one registered disposable root.
 * @param string $relative Cache-relative workspace root identity.
 * @return string Cache-relative private age record, stable across activity and inventory restarts.
 */
function cache_lifecycle_workspace_age_path(string $relative): string
{
    return 'cache-lifecycle/workspace-ages/' . hash('sha256', $relative) . '.json';
}

/**
 * Record a fixed workspace age once instead of extending retention on every write.
 * @param string $relative Registered cache-relative disposable root; other directories are ignored.
 * @return void Persists fixed creation/legacy age bound to the current producer/inode identity; unchanged identities retain their anchor, replacements cannot inherit expiry.
 */
function cache_lifecycle_workspace_observe(string $relative): void
{
    if (!cache_lifecycle_disposable_workspace($relative)) return;
    $destination = cache_lifecycle_path(cache_lifecycle_workspace_age_path($relative));
    $workspacePath = cache_lifecycle_path($relative);
    clearstatcache(true, $workspacePath);
    $stat = @lstat($workspacePath);
    if (!is_array($stat)) return;
    $marker = cache_lifecycle_read_json(cache_lifecycle_path($relative . '/cache-owner.json'));
    $native = !str_starts_with($relative, 'release-')
        && ($marker['schema_version'] ?? null) === 1 && ($marker['producer'] ?? '') === 'php_gallery_browser_fixture'
        && ($marker['directory'] ?? '') === $relative && is_int($marker['created_at'] ?? null)
        && $marker['created_at'] > 0 && $marker['created_at'] <= cache_lifecycle_now();
    $markerHash = $native ? hash('sha256', json_encode($marker, JSON_THROW_ON_ERROR)) : '';
    $identity = hash('sha256', json_encode([(int) $stat['dev'], (int) $stat['ino'], $native ? $marker : null], JSON_THROW_ON_ERROR));
    $previous = cache_lifecycle_read_json($destination);
    if (($previous['identity'] ?? '') === $identity) return;
    // Unix ctime is not creation time. Existing unbound legacy records receive
    // a conservative new anchor; producer identity or inode replacement cannot
    // inherit the preceding payload's expired permission.
    $reusedProof = $previous !== [] && ($previous['producer_marker_hash'] ?? '') === $markerHash;
    $created = $native && !$reusedProof ? $marker['created_at'] : ($previous !== [] ? cache_lifecycle_now()
        : min((int) $stat['mtime'], (int) $stat['ctime'], cache_lifecycle_now()));
    if ($created <= 0) return;
    if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0775, true);
    cache_lifecycle_write_json($destination, ['schema'=>2,'directory'=>$relative,'created_at'=>$created,
        'identity'=>$identity,'producer_marker_hash'=>$markerHash,'source'=>$native && !$reusedProof ? 'producer_creation' : 'legacy_filesystem_anchor']);
}

/**
 * Validate the canonical completed audit report format without reading unbounded content.
 * @param string $relative Cache-relative report JSON path.
 * @return bool True only for a completed report with canonical run identity and validated current or historical producer proof.
 */
function cache_lifecycle_completed_audit(string $relative): bool
{
    $record = cache_lifecycle_read_json(cache_lifecycle_path($relative));
    // Historical schema-1 reports predate source identity. Match their own
    // profile/environment/task contract rather than dropping proof requirements.
    $historical = !array_key_exists('source', $record)
        && in_array($record['profile'] ?? '', ['basic','quick','full','release','release-preflight','release-stage-b'], true)
        && is_array($record['environment'] ?? null) && is_array($record['tasks'] ?? null) && $record['tasks'] !== [];
    if ($historical) {
        foreach ($record['tasks'] as $task) {
            if (!is_array($task) || preg_match('/^[a-z0-9._:-]{1,80}$/D', (string) ($task['id'] ?? '')) !== 1
                || !in_array($task['status'] ?? '', ['PASS','FAIL','SKIP','BLOCKED'], true)) { $historical = false; break; }
        }
    }
    return ($record['schema_version'] ?? null) === 1
        && in_array($record['status'] ?? '', ['PASS','FAIL','BLOCKED'], true)
        && is_string($record['started_at'] ?? null) && strtotime($record['started_at']) !== false
        && is_numeric($record['duration_seconds'] ?? null) && $record['duration_seconds'] >= 0
        && (is_array($record['source'] ?? null) || $historical) && is_array($record['report_files'] ?? null)
        && preg_match('#^cache/test-audit/[0-9]{8}-[0-9]{6}-[0-9]+$#D', (string) ($record['report_files']['run_directory'] ?? '')) === 1
        && (!str_starts_with($relative, 'test-audit/') || $record['report_files']['run_directory'] === 'cache/' . dirname($relative));
}

/**
 * Recognize the inherited audit runner's final in-run publication without changing BASIC authority.
 * @param string $relative Cache-relative native run directory, validated by the caller.
 * @return bool True only for matching completed JSON and the final Markdown footer with no later publication inside this subtree; partial and owner-only runs remain protected.
 */
function cache_lifecycle_native_audit_finished(string $relative): bool
{
    if (!cache_lifecycle_completed_audit($relative . '/report.json')) return false;
    $record = cache_lifecycle_read_json(cache_lifecycle_path($relative . '/report.json'));
    $run = 'cache/' . $relative;
    foreach (['json', 'markdown'] as $kind) {
        $publication = $record['report_files'][$kind] ?? null;
        if (!is_string($publication) || $publication === '' || str_contains($publication, '\\')
            || str_contains($publication, '..') || $publication === $run || str_starts_with($publication, $run . '/')) return false;
    }
    $path = cache_lifecycle_path($relative . '/report.md');
    $stat = @lstat($path);
    if (!is_array($stat) || !is_file($path) || $stat['size'] <= 0 || $stat['size'] > CACHE_LIFECYCLE_STATE_BYTES) return false;
    $markdown = @file_get_contents($path, false, null, 0, CACHE_LIFECYCLE_STATE_BYTES + 1);
    // The inherited runner writes report.md last inside the unique run tree.
    // Its footer proves that write finished; subsequent latest/custom reports
    // are outside this candidate. Interrupted JSON/Markdown writes never qualify.
    $footer = "## Report files\n\n- JSON: `" . $record['report_files']['json']
        . "`\n- Run directory: `" . $run . "`\n";
    return is_string($markdown) && str_starts_with($markdown, "# PHP Gallery Audit\n")
        && str_contains($markdown, '- Result: **' . $record['status'] . '**')
        && str_ends_with($markdown, $footer);
}

/**
 * Determine whether a fully observed directory has positive disposable-producer proof.
 * @param string $relative Cache-relative directory name.
 * @param array{bytes:int,files:int,dirs:int,newest:int,pinned:bool,unsafe:bool} $counts Observed whole-subtree counters; newest includes metadata-change times.
 * @param int $now Consistent inventory timestamp in Unix seconds.
 * @return string|null Eligible producer key, or null for protected, fresh, unknown or unproven content.
 */
function cache_lifecycle_directory_candidate(string $relative, array $counts, int $now): ?string
{
    if ($counts['pinned'] || $counts['unsafe'] || $counts['newest'] <= 0) return null;
    if (cache_lifecycle_disposable_workspace($relative)) {
        cache_lifecycle_workspace_observe($relative);
        $age = cache_lifecycle_read_json(cache_lifecycle_path(cache_lifecycle_workspace_age_path($relative)));
        return ($age['schema'] ?? null) === 2 && ($age['directory'] ?? '') === $relative
            && (int) ($age['created_at'] ?? 0) > 0
            && $age['created_at'] <= $now - cache_lifecycle_policies()['workspaces']['seconds'] ? 'workspaces' : null;
    }
    if (preg_match('#^updates/jobs/([0-9]{14}-[a-f0-9]{12})$#D', $relative, $match) === 1) {
        return cache_lifecycle_update_disposable($match[1]) ? 'updates' : null;
    }
    if ($counts['newest'] >= $now - cache_lifecycle_policies()['audits']['seconds']) return null;
    if (preg_match('#^admin-test-runs/([a-f0-9]{32})$#D', $relative, $match) === 1) {
        $record = cache_lifecycle_read_json(cache_lifecycle_path($relative . '/report.json'));
        if (($record['kind'] ?? '') === 'admin_full_test_run' && !empty($record['finalized_at'])
            && ($record['token_hash'] ?? '') === substr(hash('sha256', $match[1]), 0, 16)) return 'admin_tests';
    }
    if (preg_match('#^test-audit/[0-9]{8}-[0-9]{6}-[0-9]+$#D', $relative) === 1) {
        return cache_lifecycle_native_audit_finished($relative) ? 'audits' : null;
    }
    $components = explode('/', $relative);
    if (cache_lifecycle_owner($relative) === 'ci_diagnostics' && count($components) <= 2
        && cache_lifecycle_completed_audit($relative . '/latest.json')
        && (count($components) === 1 || !cache_lifecycle_completed_audit($components[0] . '/latest.json'))) return 'ci_diagnostics';
    return null;
}

/**
 * Protect current rollback ownership and every active/source/selected updater reference without a TTL.
 * @param string $jobId Validated historical updater job identifier.
 * @return bool True only for an older verified completed/cancelled workspace; missing recovery identity fails closed.
 */
function cache_lifecycle_update_disposable(string $jobId): bool
{
    $last = cache_lifecycle_read_json(cache_lifecycle_path('updates/last-job.json'));
    $lastId = (string) ($last['job_id'] ?? '');
    if (preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $lastId) !== 1) return false;
    $latest = cache_lifecycle_read_json(cache_lifecycle_path('updates/jobs/' . $lastId . '/job.json'));
    if (($latest['id'] ?? '') !== $lastId || ($latest['status'] ?? '') !== 'completed') return false;
    $protected = [$lastId => true];
    foreach ([$latest, cache_lifecycle_read_json(cache_lifecycle_path('updates/active-job.json'))] as $reference) {
        $id = (string) ($reference['job_id'] ?? $reference['id'] ?? '');
        if ($id === '') continue;
        if (preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $id) !== 1) return false;
        $protected[$id] = true;
        $job = cache_lifecycle_read_json(cache_lifecycle_path('updates/jobs/' . $id . '/job.json'));
        if (($job['id'] ?? '') !== $id) return false;
        $source = (string) ($job['parameters']['source_job_id'] ?? '');
        if ($source !== '') $protected[$source] = true;
    }
    if (is_file(cache_lifecycle_path('updates/active-job.json'))
        && cache_lifecycle_read_json(cache_lifecycle_path('updates/active-job.json')) === []) return false;
    // Settings remain owned by their existing service; fixture callers provide an explicit adapter.
    if (!function_exists(__NAMESPACE__ . '\\app_setting')) return false;
    $backup = (string) app_setting('application_update_beta_backup_path', '');
    if (preg_match('#^cache/updates/jobs/([0-9]{14}-[a-f0-9]{12})/rollback/?$#D', str_replace('\\', '/', $backup), $match) === 1) {
        $protected[$match[1]] = true;
    } elseif ($backup !== '') return false;
    if (isset($protected[$jobId])) return false;
    $candidate = cache_lifecycle_read_json(cache_lifecycle_path('updates/jobs/' . $jobId . '/job.json'));
    return ($candidate['id'] ?? '') === $jobId && in_array($candidate['status'] ?? '', ['completed','cancelled'], true);
}

/**
 * Validate the inherited flat download producer without reading archive payloads.
 * @param string $relative Exact cache-relative historical archive or native temporary output.
 * @return array{base:string,partial:bool,bytes:int,mtime:int,ctime:int,header_hash:string,entries:int}|null Physical identity, positive ZIP header and completed-directory evidence, or null for unknown, empty or unreadable content; temporary output needs a native name and local ZIP header.
 */
function cache_lifecycle_flat_download_proof(string $relative): ?array
{
    $identity = cache_lifecycle_flat_download_identity($relative);
    if ($identity === null) return null;
    $path = cache_lifecycle_path($relative);
    $stat = @lstat($path);
    if (!is_array($stat) || !is_file($path) || $stat['size'] < 30 || $stat['mtime'] <= 0) return null;
    $header = @file_get_contents($path, false, null, 0, 30);
    if (!is_string($header) || strlen($header) !== 30 || !str_starts_with($header, "PK\x03\x04")) return null;
    // Validate the native ZIP local-header contract before accepting abandoned output.
    // Type: array<string,int>. Units: ZIP version tenths, flags/method enums and filename/extra bytes.
    // Scope: one historical download proof. Consumers: native completed/temporary ZIP eligibility.
    // Rationale: versions 1.0-6.3, Store/Deflate and names up to 4096 bytes cover this producer while rejecting encrypted, malformed and unbounded headers without reading payload data.
    $fields = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname/vextra', substr($header, 4));
    if (!is_array($fields) || $fields['version'] < 10 || $fields['version'] > 63 || ($fields['flags'] & 1) !== 0
        || !in_array($fields['method'], [0,8], true) || $fields['name'] < 1 || $fields['name'] > 4096
        || $stat['size'] < 30 + $fields['name'] + $fields['extra']) return null;
    $name = @file_get_contents($path, false, null, 30, $fields['name']);
    if (!is_string($name) || strlen($name) !== $fields['name'] || str_contains($name, "\0") || str_contains($name, '\\')
        || str_contains($name, ':') || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) return null;
    $entries = 0;
    if (!$identity['partial']) {
        if (!class_exists(\ZipArchive::class)) return null;
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) return null;
        try {
            $entries = $zip->numFiles;
            if ($entries <= 0 || $entries > CACHE_LIFECYCLE_DIRECTORY_NAMES) return null;
        } finally { $zip->close(); }
    }
    return $identity + ['bytes'=>(int) $stat['size'],'mtime'=>(int) $stat['mtime'],'ctime'=>(int) $stat['ctime'],
        'header_hash'=>hash('sha256', $header . $name),'entries'=>$entries];
}

/**
 * Recognize a historical updater ZIP through its native path and bounded source contents.
 * @param string $relative Cache-relative archive in the historical updater root or backups directory.
 * @return array{version:string,bootstrap_hash:string,bytes:int,mtime:int,ctime:int}|null Source identity and physical metadata, or null for unknown, partial, oversized or unreadable proof; no archive is extracted or executed.
 */
function cache_lifecycle_update_archive_proof(string $relative): ?array
{
    if (preg_match('#^updates/(?:(?:beta|update|stable-restore|rollback)-[0-9]{8}-[0-9]{6}|backups/before-(?:beta|update)-[0-9]{8}-[0-9]{6})\.zip$#D', $relative) !== 1
        || !class_exists(\ZipArchive::class)) return null;
    $path = cache_lifecycle_path($relative);
    $stat = @lstat($path);
    if (!is_array($stat) || !is_file($path) || $stat['size'] <= 0) return null;
    $zip = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::RDONLY) !== true) return null;
    try {
        if ($zip->numFiles < 4 || $zip->numFiles > CACHE_LIFECYCLE_DIRECTORY_NAMES) return null;
        $first = (string) $zip->getNameIndex(0);
        $prefix = preg_match('#^(PHP_gallery-[a-zA-Z0-9._-]+)/#D', $first, $match) === 1 ? $match[1] . '/' : '';
        foreach (['index.php','public/index.php','app/bootstrap.php','app/services/updates.php'] as $required) {
            $entry = $zip->statName($prefix . $required);
            if (!is_array($entry) || $entry['size'] <= 0 || $entry['size'] > CACHE_LIFECYCLE_STATE_BYTES) return null;
        }
        $bootstrap = $zip->getFromName($prefix . 'app/bootstrap.php', CACHE_LIFECYCLE_STATE_BYTES + 1);
        if (!is_string($bootstrap) || !str_starts_with($bootstrap, '<?php')
            || preg_match('/const\s+CMS_VERSION\s*=\s*[\'\"]([0-9]+\.[0-9]+(?:\.[0-9]+)?)[\'\"]\s*;/', $bootstrap, $match) !== 1) return null;
        return ['version'=>$match[1],'bootstrap_hash'=>hash('sha256', $bootstrap),'bytes'=>(int) $stat['size'],
            'mtime'=>(int) $stat['mtime'],'ctime'=>(int) $stat['ctime']];
    } finally { $zip->close(); }
}

/**
 * Retain at most two historical recovery copies, counting the current job rollback and selected legacy backup first.
 * @param string $relative Producer-verified historical updater archive proposed for reclamation.
 * @return bool True only outside the retained set after complete bounded name discovery and recovery validation; active, unknown or incomplete evidence preserves every archive.
 */
function cache_lifecycle_update_archive_disposable(string $relative): bool
{
    if (cache_lifecycle_update_archive_proof($relative) === null || !function_exists(__NAMESPACE__ . '\\app_setting')
        || file_exists(cache_lifecycle_path('updates/active-job.json'))
        || (int) app_setting('application_autoupdate_lock_until', '0') > cache_lifecycle_now()) return false;
    $selected = str_replace('\\', '/', (string) app_setting('application_update_beta_backup_path', ''));
    if ($selected !== '' && !str_starts_with($selected, 'cache/updates/')) return false;
    if ($selected === 'cache/' . $relative || file_exists(cache_lifecycle_path($relative . '.keep'))) return false;
    $last = cache_lifecycle_read_json(cache_lifecycle_path('updates/last-job.json'));
    $lastId = (string) ($last['job_id'] ?? '');
    if (file_exists(cache_lifecycle_path('updates/last-job.json')) && $last === []) return false;
    $retained = [];
    $remaining = CACHE_LIFECYCLE_UPDATE_RECOVERY_COPIES;
    if ($last !== []) {
        if (preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $lastId) !== 1) return false;
        $job = cache_lifecycle_read_json(cache_lifecycle_path('updates/jobs/' . $lastId . '/job.json'));
        if (($job['id'] ?? '') !== $lastId || ($job['status'] ?? '') !== 'completed') return false;
        if (is_dir(cache_lifecycle_path('updates/jobs/' . $lastId . '/rollback'))) $remaining--;
    }
    if (str_starts_with($selected, 'cache/updates/jobs/')) {
        if (preg_match('#^cache/updates/jobs/([0-9]{14}-[a-f0-9]{12})/rollback/?$#D', $selected, $selectedJob) !== 1
            || !is_dir(cache_lifecycle_path(substr($selected, 6)))) return false;
        $selectedState = cache_lifecycle_read_json(cache_lifecycle_path('updates/jobs/' . $selectedJob[1] . '/job.json'));
        if (($selectedState['id'] ?? '') !== $selectedJob[1]) return false;
        if ($selectedJob[1] !== $lastId) $remaining--;
    }
    if ($selected !== '' && !str_starts_with($selected, 'cache/updates/jobs/')) {
        if (cache_lifecycle_update_archive_proof(substr($selected, 6)) === null) return false;
        $retained[] = substr($selected, 6);
        $remaining--;
    }
    $names = [];
    $deadline = microtime(true) + CACHE_LIFECYCLE_SLICE_SECONDS;
    foreach (['updates','updates/backups'] as $directory) {
        $path = cache_lifecycle_path($directory);
        if (!is_dir($path)) continue;
        $stream = @opendir($path);
        if ($stream === false) return false;
        $complete = false;
        try {
            while (count($names) <= CACHE_LIFECYCLE_UPDATE_ARCHIVE_NAMES && microtime(true) < $deadline) {
                $name = readdir($stream);
                if ($name === false) { $complete = true; break; }
                if (preg_match('/^(?:(?:beta|update|stable-restore|rollback)|before-(?:beta|update))-([0-9]{8}-[0-9]{6})\.zip$/D', $name, $match) === 1) {
                    $names[$directory . '/' . $name] = $match[1];
                }
            }
        } finally { closedir($stream); }
        if (!$complete || count($names) > CACHE_LIFECYCLE_UPDATE_ARCHIVE_NAMES) return false;
    }
    arsort($names, SORT_STRING);
    $probes = 0;
    foreach ($names as $path => $stamp) {
        if ($remaining <= 0) break;
        if (microtime(true) >= $deadline || ++$probes > CACHE_LIFECYCLE_UPDATE_ARCHIVE_PROBES) return false;
        if (in_array($path, $retained, true) || cache_lifecycle_update_archive_proof($path) === null) continue;
        $retained[] = $path;
        $remaining--;
    }
    return !in_array($relative, $retained, true);
}

/**
 * Recognize expired native download payloads, historical updater copies and regenerable JSON through positive producer proof.
 * @param string $relative Cache-relative file path.
 * @param int $now Current Unix timestamp in seconds.
 * @return string|null Eligible downloads, partials, updates, GitHub or drafts owner key, or null for fresh, retained, oversized, unknown or unproven content.
 */
function cache_lifecycle_file_candidate(string $relative, int $now): ?string
{
    $flat = cache_lifecycle_flat_download_proof($relative);
    if ($flat !== null) {
        $owner = $flat['partial'] ? 'partials' : 'downloads';
        return $flat['mtime'] < $now - cache_lifecycle_policies()[$owner]['seconds'] ? $owner : null;
    }
    if (str_starts_with($relative, 'updates/') && str_ends_with($relative, '.zip')) {
        return cache_lifecycle_update_archive_disposable($relative) ? 'updates' : null;
    }
    if (preg_match('#^zips/legacy-artifacts/(gallery|smart_gallery)/([1-9][0-9]*)/([a-f0-9]{64})/([a-f0-9]{64})/archive\.zip$#D', $relative, $match) === 1) {
        $record = cache_lifecycle_read_json(cache_lifecycle_path(dirname($relative) . '/metadata.json'));
        $stat = @lstat(cache_lifecycle_path($relative));
        if (!is_array($stat) || ($record['format_version'] ?? null) !== 1 || ($record['resource_type'] ?? '') !== $match[1]
            || (int) ($record['resource_id'] ?? 0) !== (int) $match[2] || ($record['resource_revision'] ?? '') !== $match[3]
            || ($record['build_key'] ?? '') !== $match[4] || (int) ($record['archive_size'] ?? 0) !== (int) $stat['size']
            || (int) ($record['expected_file_count'] ?? -1) < 0 || (int) ($record['created_at'] ?? 0) <= 0) return null;
        // Metadata changes (including chmod) do not renew producer expiry; ctime remains a deletion identity guard.
        return max((int) $record['created_at'], (int) $stat['mtime'])
            < $now - cache_lifecycle_policies()['downloads']['seconds'] ? 'downloads' : null;
    }
    if (preg_match('#^(?:github-api/[a-f0-9]{40}|simbrief-drafts/[a-f0-9]{48})\.json$#D', $relative) !== 1) return null;
    $record = cache_lifecycle_read_json(cache_lifecycle_path($relative));
    if (preg_match('#^github-api/([a-f0-9]{40})\.json$#D', $relative, $match) === 1) {
        $url = $record['url'] ?? null;
        if (is_string($url) && hash_equals($match[1], sha1($url)) && is_string($record['body'] ?? null)
            && is_array($record['headers'] ?? null) && is_int($record['status'] ?? null)
            && (int) ($record['cached_at'] ?? 0) > 0 && $record['cached_at'] < $now - cache_lifecycle_policies()['github']['seconds']) return 'github';
    }
    if (preg_match('#^simbrief-drafts/[a-f0-9]{48}\.json$#D', $relative) === 1
        && (int) ($record['user_id'] ?? 0) > 0 && preg_match('/^[a-f0-9]{64}$/D', (string) ($record['session_hash'] ?? '')) === 1
        && is_array($record['payload'] ?? null) && is_array($record['identifier'] ?? null) && is_array($record['details'] ?? null)
        && (int) ($record['expires_at'] ?? 0) > 0 && $record['expires_at'] < $now - 60) return 'drafts';
    return null;
}
