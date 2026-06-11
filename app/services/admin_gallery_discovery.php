<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_discovery.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides browser-driven filesystem gallery discovery for the Admin dashboard.
 *
 * Responsibilities:
 *   - Scan gallery folders in small Ajax batches
 *   - Keep long filesystem discovery away from initial Admin page rendering
 *   - Return import-ready candidate metadata for discovered gallery folders
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-06-11
 */

declare(strict_types=1);

const ADMIN_GALLERY_DISCOVERY_DEFAULT_BATCH_SIZE = 80;
const ADMIN_GALLERY_DISCOVERY_MAX_BATCH_SIZE = 300;
const ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS = 7200;

/**
 * Start a browser-driven Admin gallery discovery job.
 *
 * The job records traversal state in the admin session so every request only
 * scans a bounded number of folders. Imported gallery rows are not rescanned
 * here, because the Discover folders action is a folder inventory check.
 *
 * @return array<string, mixed> Public job state for the Ajax caller.
 */
function admin_gallery_discovery_start_job(): array
{
    admin_gallery_discovery_cleanup_jobs();

    $token = bin2hex(random_bytes(12));
    $known = admin_gallery_discovery_known_gallery_paths();
    $job = [
        'token' => $token,
        'status' => 'running',
        'started_at' => time(),
        'updated_at' => time(),
        'queue' => [''],
        'queued_paths' => ['' => true],
        'queue_index' => 0,
        'known_paths' => $known,
        'candidate_paths' => [],
        'candidates' => [],
        'processed_directories' => 0,
        'discovered_directories' => 1,
        'errors' => [],
    ];

    admin_gallery_discovery_write_job($job);
    return admin_gallery_discovery_public_state($job);
}

/**
 * Process one browser-driven folder discovery batch.
 *
 * @param string $token Session job token supplied by the browser.
 * @param int $batchSize Maximum number of directories to inspect.
 * @return array<string, mixed> Public job state for the Ajax caller.
 */
function admin_gallery_discovery_process_job(string $token, int $batchSize = ADMIN_GALLERY_DISCOVERY_DEFAULT_BATCH_SIZE): array
{
    $job = admin_gallery_discovery_read_job($token);
    if ($job === null) {
        return admin_gallery_discovery_missing_state();
    }

    if ((string) ($job['status'] ?? '') === 'complete') {
        return admin_gallery_discovery_public_state($job, true);
    }

    $root = galleries_root();
    if (!is_dir($root)) {
        $job['status'] = 'error';
        $job['errors'][] = 'The galleries directory does not exist.';
        $job['updated_at'] = time();
        admin_gallery_discovery_write_job($job);
        return admin_gallery_discovery_public_state($job, true);
    }

    $batchSize = max(1, min(ADMIN_GALLERY_DISCOVERY_MAX_BATCH_SIZE, $batchSize));
    $processedThisBatch = 0;

    while ($processedThisBatch < $batchSize && admin_gallery_discovery_has_pending_directory($job)) {
        $relativePath = (string) $job['queue'][(int) $job['queue_index']];
        $job['queue_index'] = (int) $job['queue_index'] + 1;
        admin_gallery_discovery_scan_directory($job, $relativePath);
        $processedThisBatch++;
    }

    $job['processed_directories'] = (int) ($job['queue_index'] ?? 0);
    $job['discovered_directories'] = count((array) ($job['queue'] ?? []));
    $job['updated_at'] = time();

    if (!admin_gallery_discovery_has_pending_directory($job)) {
        $job = admin_gallery_discovery_finish_job($job);
    }

    admin_gallery_discovery_write_job($job);
    return admin_gallery_discovery_public_state($job, (string) ($job['status'] ?? '') === 'complete');
}

/**
 * Return the current public state for an existing discovery job.
 *
 * @param string $token Session job token supplied by the browser.
 * @return array<string, mixed> Public job state for the Ajax caller.
 */
function admin_gallery_discovery_job_status(string $token): array
{
    $job = admin_gallery_discovery_read_job($token);
    if ($job === null) {
        return admin_gallery_discovery_missing_state();
    }

    return admin_gallery_discovery_public_state($job, (string) ($job['status'] ?? '') === 'complete');
}

/**
 * Expand selected folders into an ordered import list without scanning the full gallery root.
 *
 * Import actions use this helper instead of the original full discovery pass.
 * It still preserves the old behavior of importing missing ancestors and valid
 * descendant gallery folders under each selected path.
 *
 * @param array<int, mixed> $folderPaths Folder paths posted by the admin form.
 * @return array<int, string> Ordered normalized folder paths to import.
 */
function admin_gallery_discovery_expand_requested_import_paths(array $folderPaths): array
{
    $expanded = [];
    foreach ($folderPaths as $folderPath) {
        $requestedPath = normalize_relative_path((string) $folderPath);
        if ($requestedPath === '' || !is_dir(gallery_abs_path($requestedPath))) {
            continue;
        }

        foreach (admin_gallery_discovery_ancestor_paths($requestedPath) as $ancestorPath) {
            if (is_dir(gallery_abs_path($ancestorPath))) {
                $expanded[$ancestorPath] = $ancestorPath;
            }
        }

        foreach (admin_gallery_discovery_collect_subtree_candidate_paths($requestedPath) as $candidatePath) {
            $expanded[$candidatePath] = $candidatePath;
        }
    }

    $paths = array_values($expanded);
    usort($paths, static function (string $left, string $right): int {
        $depth = substr_count($left, '/') <=> substr_count($right, '/');
        return $depth !== 0 ? $depth : strnatcasecmp($left, $right);
    });

    return $paths;
}

/**
 * Return known gallery folder paths keyed by normalized path.
 *
 * @return array<string, bool> Known gallery path lookup.
 */
function admin_gallery_discovery_known_gallery_paths(): array
{
    try {
        $paths = db()->query('SELECT folder_path FROM galleries')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return [];
    }

    $known = [];
    foreach ($paths as $path) {
        $normalized = normalize_relative_path((string) $path);
        if ($normalized !== '') {
            $known[$normalized] = true;
        }
    }

    return $known;
}

/**
 * Return whether the job still has a directory waiting in its queue.
 *
 * @param array<string, mixed> $job Discovery job state.
 * @return bool True when another directory can be scanned.
 */
function admin_gallery_discovery_has_pending_directory(array $job): bool
{
    return (int) ($job['queue_index'] ?? 0) < count((array) ($job['queue'] ?? []));
}

/**
 * Scan one directory and update the discovery job state.
 *
 * @param array<string, mixed> $job Discovery job state, updated in place.
 * @param string $relativePath Directory path relative to the gallery root.
 */
function admin_gallery_discovery_scan_directory(array &$job, string $relativePath): void
{
    $absolutePath = $relativePath === '' ? galleries_root() : gallery_abs_path($relativePath);
    if (!is_dir($absolutePath)) {
        return;
    }

    $hasDirectImages = false;
    $hasSidecar = false;

    try {
        $iterator = new DirectoryIterator($absolutePath);
    } catch (Throwable $exception) {
        admin_gallery_discovery_record_error($job, $relativePath, $exception->getMessage());
        return;
    }

    foreach ($iterator as $entry) {
        if (!$entry instanceof DirectoryIterator || $entry->isDot()) {
            continue;
        }

        if ($entry->isDir()) {
            if ($entry->isLink() || admin_gallery_discovery_should_skip_directory($entry)) {
                continue;
            }
            $childPath = normalize_relative_path(($relativePath === '' ? '' : $relativePath . '/') . $entry->getFilename());
            if ($childPath !== '' && empty($job['queued_paths'][$childPath])) {
                $job['queue'][] = $childPath;
                $job['queued_paths'][$childPath] = true;
            }
            continue;
        }

        if (!$entry->isFile()) {
            continue;
        }

        if ($entry->getFilename() === 'gallery.json') {
            $hasSidecar = true;
        }
        if (is_supported_image_path($entry->getFilename())) {
            $hasDirectImages = true;
        }
    }

    if ($hasDirectImages) {
        admin_gallery_discovery_mark_image_candidate_path($job, $relativePath);
    }
    if ($hasSidecar && $relativePath !== '') {
        admin_gallery_discovery_mark_candidate_path($job, $relativePath);
    }
}

/**
 * Return whether a directory should be ignored during discovery.
 *
 * @param SplFileInfo $entry Directory entry inspected by the scanner.
 * @return bool True when the directory is internal, hidden, or generated.
 */
function admin_gallery_discovery_should_skip_directory(SplFileInfo $entry): bool
{
    $name = $entry->getFilename();
    if ($name === '' || str_starts_with($name, '.')) {
        return true;
    }

    return in_array(strtolower($name), admin_gallery_discovery_ignored_directory_names(), true);
}

/**
 * Return directory names ignored by gallery discovery.
 *
 * @return array<int, string> Lowercase directory names.
 */
function admin_gallery_discovery_ignored_directory_names(): array
{
    return ['cache', 'thumbs', 'thumbnail', 'thumbnails', 'preview', 'previews'];
}

/**
 * Mark a directory and its ancestors as candidates because an image was found below them.
 *
 * @param array<string, mixed> $job Discovery job state, updated in place.
 * @param string $relativePath Directory path relative to the gallery root.
 */
function admin_gallery_discovery_mark_image_candidate_path(array &$job, string $relativePath): void
{
    if ($relativePath === '') {
        return;
    }

    foreach (admin_gallery_discovery_ancestor_paths($relativePath, true) as $candidatePath) {
        admin_gallery_discovery_mark_candidate_path($job, $candidatePath);
    }
}

/**
 * Mark one normalized directory as a potential candidate.
 *
 * @param array<string, mixed> $job Discovery job state, updated in place.
 * @param string $relativePath Directory path relative to the gallery root.
 */
function admin_gallery_discovery_mark_candidate_path(array &$job, string $relativePath): void
{
    $normalized = normalize_relative_path($relativePath);
    if ($normalized === '') {
        return;
    }

    if (!isset($job['candidate_paths']) || !is_array($job['candidate_paths'])) {
        $job['candidate_paths'] = [];
    }
    $job['candidate_paths'][$normalized] = true;
}

/**
 * Return ancestor paths for a relative gallery path.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @param bool $includeSelf Include the supplied path in the returned list.
 * @return array<int, string> Ancestor paths in root-to-leaf order.
 */
function admin_gallery_discovery_ancestor_paths(string $relativePath, bool $includeSelf = false): array
{
    $segments = array_values(array_filter(explode('/', normalize_relative_path($relativePath)), static fn (string $segment): bool => $segment !== ''));
    $limit = $includeSelf ? count($segments) : max(0, count($segments) - 1);
    $paths = [];
    $current = [];

    for ($index = 0; $index < $limit; $index++) {
        $current[] = $segments[$index];
        $paths[] = implode('/', $current);
    }

    return $paths;
}

/**
 * Complete a discovery job by building import-ready candidate rows.
 *
 * @param array<string, mixed> $job Discovery job state.
 * @return array<string, mixed> Completed job state.
 */
function admin_gallery_discovery_finish_job(array $job): array
{
    $known = is_array($job['known_paths'] ?? null) ? $job['known_paths'] : [];
    $candidatePaths = array_keys(is_array($job['candidate_paths'] ?? null) ? $job['candidate_paths'] : []);
    usort($candidatePaths, static function (string $left, string $right): int {
        $depth = substr_count($left, '/') <=> substr_count($right, '/');
        return $depth !== 0 ? $depth : strnatcasecmp($left, $right);
    });

    $candidates = [];
    foreach ($candidatePaths as $candidatePath) {
        if (isset($known[$candidatePath]) || !is_dir(gallery_abs_path($candidatePath))) {
            continue;
        }
        $candidate = admin_gallery_discovery_candidate_from_path($candidatePath);
        if ($candidate !== null) {
            $candidates[] = $candidate;
        }
    }

    $job['status'] = 'complete';
    $job['candidates'] = $candidates;
    $job['updated_at'] = time();
    return $job;
}

/**
 * Build import-ready candidate metadata for one folder.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @return array<string, mixed>|null Candidate row, or null when the folder vanished.
 */
function admin_gallery_discovery_candidate_from_path(string $relativePath): ?array
{
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '' || !is_dir(gallery_abs_path($relativePath))) {
        return null;
    }

    $jsonPath = gallery_abs_path($relativePath) . DIRECTORY_SEPARATOR . 'gallery.json';
    $metadata = read_gallery_sidecar($jsonPath);

    return [
        'folder_path' => $relativePath,
        'title' => $metadata['title'] ?? basename($relativePath),
        'description' => $metadata['description'] ?? '',
        'visibility' => gallery_visibility_storage_value((string) ($metadata['visibility'] ?? 'unpublished')),
        'access_mode' => $metadata['access_mode'] ?? 'normal',
        'access_listing' => $metadata['access_listing'] ?? 'listed',
        'banner_image_path' => $metadata['banner_image_path'] ?? null,
        'logo_image_path' => $metadata['logo_image_path'] ?? null,
        'separator_image_path' => $metadata['separator_image_path'] ?? null,
        'sort_order' => (int) ($metadata['sort_order'] ?? 0),
    ];
}

/**
 * Collect valid candidate paths under one selected subtree.
 *
 * @param string $relativePath Directory path relative to the gallery root.
 * @return array<int, string> Candidate paths in the selected subtree.
 */
function admin_gallery_discovery_collect_subtree_candidate_paths(string $relativePath): array
{
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '' || !is_dir(gallery_abs_path($relativePath))) {
        return [];
    }

    $job = [
        'queue' => [$relativePath],
        'queued_paths' => [$relativePath => true],
        'queue_index' => 0,
        'candidate_paths' => [],
        'errors' => [],
    ];

    while (admin_gallery_discovery_has_pending_directory($job)) {
        $currentPath = (string) $job['queue'][(int) $job['queue_index']];
        $job['queue_index'] = (int) $job['queue_index'] + 1;
        admin_gallery_discovery_scan_directory($job, $currentPath);
    }

    $paths = array_keys(is_array($job['candidate_paths'] ?? null) ? $job['candidate_paths'] : []);
    usort($paths, static function (string $left, string $right): int {
        $depth = substr_count($left, '/') <=> substr_count($right, '/');
        return $depth !== 0 ? $depth : strnatcasecmp($left, $right);
    });

    return $paths;
}

/**
 * Record a bounded discovery warning for later display and logging.
 *
 * @param array<string, mixed> $job Discovery job state, updated in place.
 * @param string $relativePath Directory path relative to the gallery root.
 * @param string $message Error message from the filesystem operation.
 */
function admin_gallery_discovery_record_error(array &$job, string $relativePath, string $message): void
{
    if (!isset($job['errors']) || !is_array($job['errors'])) {
        $job['errors'] = [];
    }
    if (count($job['errors']) >= 10) {
        return;
    }

    $job['errors'][] = trim(($relativePath === '' ? '[root]' : $relativePath) . ': ' . $message);
}

/**
 * Return public state for a discovery job.
 *
 * @param array<string, mixed> $job Discovery job state.
 * @param bool $includeCandidates Include candidate rows in the payload.
 * @return array<string, mixed> JSON-safe public state.
 */
function admin_gallery_discovery_public_state(array $job, bool $includeCandidates = false): array
{
    $processed = (int) ($job['processed_directories'] ?? $job['queue_index'] ?? 0);
    $total = max($processed, (int) ($job['discovered_directories'] ?? count((array) ($job['queue'] ?? []))));
    $candidatePaths = is_array($job['candidate_paths'] ?? null) ? $job['candidate_paths'] : [];
    $candidates = is_array($job['candidates'] ?? null) ? $job['candidates'] : [];
    $status = (string) ($job['status'] ?? 'running');
    $done = $status === 'complete' || $status === 'error';
    $candidateCount = $status === 'complete' ? count($candidates) : count($candidatePaths);

    $state = [
        'ok' => $status !== 'error',
        'status' => $status,
        'done' => $done,
        'job_token' => (string) ($job['token'] ?? ''),
        'processed_directories' => $processed,
        'discovered_directories' => $total,
        'queued_directories' => max(0, $total - $processed),
        'candidate_count' => $candidateCount,
        'percent' => $total > 0 ? min(100.0, ($processed / $total) * 100.0) : 0.0,
        'errors' => is_array($job['errors'] ?? null) ? $job['errors'] : [],
    ];

    if ($includeCandidates || $status === 'complete') {
        $state['candidates'] = $candidates;
    }

    return $state;
}

/**
 * Return a standard missing-job response for expired discovery state.
 *
 * @return array<string, mixed> JSON-safe public state.
 */
function admin_gallery_discovery_missing_state(): array
{
    return [
        'ok' => false,
        'status' => 'missing',
        'done' => true,
        'job_token' => '',
        'processed_directories' => 0,
        'discovered_directories' => 0,
        'queued_directories' => 0,
        'candidate_count' => 0,
        'percent' => 0.0,
        'errors' => [],
    ];
}

/**
 * Read one discovery job from the admin session.
 *
 * @param string $token Session job token supplied by the browser.
 * @return array<string, mixed>|null Discovery job state, or null when missing.
 */
function admin_gallery_discovery_read_job(string $token): ?array
{
    $token = preg_replace('/[^A-Fa-f0-9]/', '', $token) ?: '';
    if ($token === '' || empty($_SESSION['admin_gallery_discovery_jobs'][$token]) || !is_array($_SESSION['admin_gallery_discovery_jobs'][$token])) {
        return null;
    }

    $job = $_SESSION['admin_gallery_discovery_jobs'][$token];
    if (time() - (int) ($job['updated_at'] ?? $job['started_at'] ?? 0) > ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS) {
        unset($_SESSION['admin_gallery_discovery_jobs'][$token]);
        return null;
    }

    return $job;
}

/**
 * Write one discovery job into the admin session.
 *
 * @param array<string, mixed> $job Discovery job state.
 */
function admin_gallery_discovery_write_job(array $job): void
{
    $token = preg_replace('/[^A-Fa-f0-9]/', '', (string) ($job['token'] ?? '')) ?: '';
    if ($token === '') {
        return;
    }

    if (!isset($_SESSION['admin_gallery_discovery_jobs']) || !is_array($_SESSION['admin_gallery_discovery_jobs'])) {
        $_SESSION['admin_gallery_discovery_jobs'] = [];
    }
    $_SESSION['admin_gallery_discovery_jobs'][$token] = $job;
}

/**
 * Remove stale discovery jobs from the admin session.
 */
function admin_gallery_discovery_cleanup_jobs(): void
{
    if (empty($_SESSION['admin_gallery_discovery_jobs']) || !is_array($_SESSION['admin_gallery_discovery_jobs'])) {
        $_SESSION['admin_gallery_discovery_jobs'] = [];
        return;
    }

    foreach ($_SESSION['admin_gallery_discovery_jobs'] as $token => $job) {
        if (!is_array($job) || time() - (int) ($job['updated_at'] ?? $job['started_at'] ?? 0) > ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS) {
            unset($_SESSION['admin_gallery_discovery_jobs'][$token]);
        }
    }

    if (count($_SESSION['admin_gallery_discovery_jobs']) <= 5) {
        return;
    }

    uasort($_SESSION['admin_gallery_discovery_jobs'], static function (array $left, array $right): int {
        return (int) ($right['updated_at'] ?? 0) <=> (int) ($left['updated_at'] ?? 0);
    });
    $_SESSION['admin_gallery_discovery_jobs'] = array_slice($_SESSION['admin_gallery_discovery_jobs'], 0, 5, true);
}
