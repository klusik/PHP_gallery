<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/updater_resumable_state_machine_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Verifies the resumable updater safety model without activating files in the real installation.
 *
 * Responsibilities:
 *   - Check explicit state-machine transitions and bounded request budgets
 *   - Check retry-safe locks, stale-lock recovery, and safe error redaction
 *   - Exercise rollback snapshot copying in isolated temporary directories
 *   - Reject malformed archives before extraction or activation
 *   - Assert stable/beta/background/Admin wiring uses the durable job engine
 *   - Assert browser continuation stays in place and supports dynamically rendered controls
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/updates.php';

use function Gallery\Services\application_update_acquire_lock;
use function Gallery\Services\application_update_activation_priority;
use function Gallery\Services\application_update_backup_items_for_plan;
use function Gallery\Services\application_update_backup_path_to_directory;
use function Gallery\Services\application_update_budget_allows;
use function Gallery\Services\application_update_changed_release_files;
use function Gallery\Services\application_update_job_public_state;
use function Gallery\Services\application_update_job_stages;
use function Gallery\Services\application_update_job_transition_allowed;
use function Gallery\Services\application_update_job_error_retryable;
use function Gallery\Services\application_update_job_validate_archive;
use function Gallery\Services\application_update_manifest_hash;
use function Gallery\Services\application_update_obsolete_paths;
use function Gallery\Services\application_update_release_lock;
use function Gallery\Services\application_update_remote_timeout_seconds;
use function Gallery\Services\application_update_runtime_limits;
use function Gallery\Services\application_update_safe_error;
use function Gallery\Services\application_update_safe_zip_entry;
use function Gallery\Services\application_update_time_budget;

/**
 * Assert an updater state-machine condition.
 */
function assert_updater_resumable(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Recursively remove a temporary updater fixture tree.
 */
function remove_updater_test_tree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

$expectedStages = [
    'download', 'archive_validate', 'extract', 'package_validate', 'plan', 'stage_files',
    'backup', 'ready', 'activate', 'migrate', 'finalize', 'cleanup', 'completed',
];
assert_updater_resumable(application_update_job_stages() === $expectedStages, 'Update stages changed without updating the resumable safety contract.');
foreach ($expectedStages as $index => $stage) {
    assert_updater_resumable(application_update_job_transition_allowed($stage, $stage), 'A stage must permit idempotent same-stage persistence.');
    if (isset($expectedStages[$index + 1])) {
        assert_updater_resumable(application_update_job_transition_allowed($stage, $expectedStages[$index + 1]), 'Adjacent stage transition was rejected: ' . $stage);
    }
    if (isset($expectedStages[$index + 2])) {
        assert_updater_resumable(!application_update_job_transition_allowed($stage, $expectedStages[$index + 2]), 'Unsafe stage skipping was allowed: ' . $stage);
    }
}

$budget = application_update_time_budget(2.0);
assert_updater_resumable((float) $budget['seconds'] >= 1.0 && (float) $budget['seconds'] <= 2.0, 'Worker budget escaped its requested bounded slice.');
assert_updater_resumable(application_update_budget_allows($budget, 0.05), 'Fresh worker budget unexpectedly contains no usable time.');
$limits = application_update_runtime_limits();
assert_updater_resumable(($limits['design_depends_on_timeout_extension'] ?? true) === false, 'Updater correctness must not depend on timeout extension.');
assert_updater_resumable(($limits['proxy_timeout_detectable'] ?? true) === false, 'Runtime diagnostics must not claim to detect an unknowable proxy timeout.');
assert_updater_resumable(application_update_remote_timeout_seconds(microtime(true) - 1.0, 12) === 0, 'Remote metadata I/O starts after its wall-clock budget expired.');
$remoteTimeout = application_update_remote_timeout_seconds(microtime(true) + 2.2, 12);
assert_updater_resumable($remoteTimeout >= 1 && $remoteTimeout <= 2, 'Remote metadata timeout escaped its deadline budget.');

$sensitive = '/home/account/config.php token=secret SELECT password FROM users https://example.invalid/?key=secret';
$safe = application_update_safe_error($sensitive);
assert_updater_resumable(!str_contains(json_encode($safe), '/home/account'), 'Safe update error leaked a filesystem path.');
assert_updater_resumable(!str_contains(json_encode($safe), 'SELECT password'), 'Safe update error leaked SQL.');
assert_updater_resumable(!str_contains(json_encode($safe), 'key=secret'), 'Safe update error leaked a URL secret.');
assert_updater_resumable((bool) preg_match('/^[A-F0-9]{12}$/', (string) $safe['reference']), 'Safe update error reference format changed unexpectedly.');
$manifestMismatch = application_update_safe_error('Downloaded update archive failed core-manifest integrity validation.');
assert_updater_resumable(($manifestMismatch['retryable'] ?? true) === false, 'A deterministic manifest mismatch is still presented as retryable.');
assert_updater_resumable(str_contains((string) ($manifestMismatch['message'] ?? ''), 'cancel this job'), 'Manifest mismatch guidance does not direct the administrator to a recoverable action.');
assert_updater_resumable(application_update_job_error_retryable(['error' => ['reference' => '4B0BFE5C7C13']]) === false, 'A persisted pre-fix manifest mismatch still permits an endless retry loop.');
$legacyManifestJob = application_update_job_public_state(['status' => 'failed', 'stage' => 'package_validate', 'error' => ['message' => 'Old generic error.', 'reference' => '4B0BFE5C7C13']]);
assert_updater_resumable(($legacyManifestJob['can_resume'] ?? true) === false && str_contains((string) ($legacyManifestJob['error']['message'] ?? ''), 'newer release or beta code'), 'Persisted pre-fix manifest mismatch does not receive current recovery guidance.');

assert_updater_resumable(application_update_safe_zip_entry('repo/app/bootstrap.php') === 'repo/app/bootstrap.php', 'Safe archive path normalization failed.');
foreach (['../config.php', '/etc/passwd', 'C:/Windows/test.php', 'repo/./file.php', 'repo/../file.php'] as $unsafePath) {
    $rejected = false;
    try {
        application_update_safe_zip_entry($unsafePath);
    } catch (RuntimeException) {
        $rejected = true;
    }
    assert_updater_resumable($rejected, 'Unsafe ZIP path was accepted: ' . $unsafePath);
}

$tempRoot = sys_get_temp_dir() . '/php-gallery-updater-state-' . bin2hex(random_bytes(6));
mkdir($tempRoot, 0775, true);
try {
    $lockPath = $tempRoot . '/worker.lock';
    $firstLock = application_update_acquire_lock($lockPath, 5);
    assert_updater_resumable(is_resource($firstLock), 'First updater worker could not acquire its lock.');
    $secondLock = application_update_acquire_lock($lockPath, 5);
    assert_updater_resumable($secondLock === null, 'Concurrent updater worker acquired the same lock.');
    application_update_release_lock($firstLock);

    file_put_contents($lockPath, '{"owner":"dead","expires_at":1}');
    $recoveredLock = application_update_acquire_lock($lockPath, 5);
    assert_updater_resumable(is_resource($recoveredLock), 'Stale lock metadata blocked recovery after the OS lock was released.');
    application_update_release_lock($recoveredLock);

    $activeRoot = $tempRoot . '/active';
    $snapshotRoot = $tempRoot . '/snapshot';
    mkdir($activeRoot . '/app/nested', 0775, true);
    file_put_contents($activeRoot . '/app/example.php', "old-file\n");
    file_put_contents($activeRoot . '/app/nested/value.txt', "old-directory-file\n");
    assert_updater_resumable(application_update_backup_path_to_directory($activeRoot, $snapshotRoot, 'app/example.php'), 'Existing file was not included in rollback snapshot.');
    assert_updater_resumable(application_update_backup_path_to_directory($activeRoot, $snapshotRoot, 'app/nested'), 'Existing directory was not included in rollback snapshot.');
    assert_updater_resumable(!application_update_backup_path_to_directory($activeRoot, $snapshotRoot, 'app/new.php'), 'Missing pre-update file was incorrectly reported as backed up.');
    assert_updater_resumable((string) file_get_contents($snapshotRoot . '/app/example.php') === "old-file\n", 'Rollback snapshot file contents changed.');
    assert_updater_resumable((string) file_get_contents($snapshotRoot . '/app/nested/value.txt') === "old-directory-file\n", 'Rollback snapshot directory contents changed.');

    $sourcePlan = $tempRoot . '/source-plan';
    $destinationPlan = $tempRoot . '/destination-plan';
    mkdir($sourcePlan . '/app', 0775, true);
    mkdir($destinationPlan . '/app', 0775, true);
    mkdir($destinationPlan . '/galleries/huge/private', 0775, true);
    file_put_contents($destinationPlan . '/app/stale.php', "stale\n");
    file_put_contents($destinationPlan . '/galleries/huge/private/photo.jpg', str_repeat('x', 1024));
    $obsolete = application_update_obsolete_paths($sourcePlan, $destinationPlan, false);
    assert_updater_resumable(in_array('app/stale.php', $obsolete, true), 'Managed stale application file was not planned for removal.');
    assert_updater_resumable(!array_filter($obsolete, static fn (string $path): bool => str_starts_with($path, 'galleries/')), 'Obsolete planning descended into protected gallery content.');

    file_put_contents($sourcePlan . '/app/same.php', "same\n");
    file_put_contents($destinationPlan . '/app/same.php', "same\n");
    file_put_contents($sourcePlan . '/app/changed.php', "new\n");
    file_put_contents($destinationPlan . '/app/changed.php', "old\n");
    file_put_contents($sourcePlan . '/app/new.php', "new-file\n");
    $changedFiles = application_update_changed_release_files(
        $sourcePlan,
        $destinationPlan,
        ['app/same.php', 'app/changed.php', 'app/new.php']
    );
    assert_updater_resumable($changedFiles === ['app/changed.php', 'app/new.php'], 'Activation preflight did not exclude byte-identical files.');

    if (function_exists('symlink')) {
        $symlinkCreated = @symlink($destinationPlan . '/app/same.php', $destinationPlan . '/app/managed-link.php');
        if ($symlinkCreated) {
            file_put_contents($sourcePlan . '/app/managed-link.php', "replacement\n");
            $symlinkRejected = false;
            try {
                application_update_changed_release_files($sourcePlan, $destinationPlan, ['app/managed-link.php']);
            } catch (RuntimeException) {
                $symlinkRejected = true;
            }
            assert_updater_resumable($symlinkRejected, 'Managed symbolic-link activation destination was not rejected during preflight.');
        }
    }

    mkdir($destinationPlan . '/app/conflict', 0775, true);
    $conflictRejected = false;
    try {
        application_update_backup_items_for_plan($destinationPlan, ['app/conflict'], []);
    } catch (RuntimeException) {
        $conflictRejected = true;
    }
    assert_updater_resumable($conflictRejected, 'File-vs-directory replacement conflict was not rejected before backup.');
} finally {
    remove_updater_test_tree($tempRoot);
}

assert_updater_resumable(application_update_activation_priority('app/service.php') < application_update_activation_priority('app/bootstrap.php'), 'Dependencies must activate before bootstrap.');
assert_updater_resumable(application_update_activation_priority('app/bootstrap.php') < application_update_activation_priority('public/index.php'), 'Bootstrap must activate before public entrypoint.');
assert_updater_resumable(application_update_activation_priority('public/index.php') < application_update_activation_priority('index.php'), 'Public entrypoint must activate before root entrypoint.');

$rollbackable = application_update_job_public_state([
    'id' => '20260812000000-abcdef123456',
    'operation' => 'stable_update',
    'status' => 'failed',
    'stage' => 'activate',
    'checkpoints' => ['backup_complete' => true],
]);
assert_updater_resumable(!empty($rollbackable['can_rollback']), 'Post-activation failure lost its rollback capability.');
$preActivation = application_update_job_public_state([
    'id' => '20260812000000-abcdef123456',
    'operation' => 'stable_update',
    'status' => 'failed',
    'stage' => 'package_validate',
    'checkpoints' => ['backup_complete' => false],
]);
assert_updater_resumable(empty($preActivation['can_rollback']), 'Pre-activation failure should not offer a file rollback.');
assert_updater_resumable(!empty($preActivation['can_cancel']), 'Pre-activation running/failed job should remain cancellable.');
assert_updater_resumable(empty($rollbackable['can_cancel']), 'Post-activation job must not be cancellable.');

if (class_exists(ZipArchive::class)) {
    $jobId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    $jobDir = dirname(__DIR__) . '/cache/updates/jobs/' . $jobId;
    mkdir($jobDir, 0775, true);
    file_put_contents($jobDir . '/package.zip', 'not a zip archive');
    $invalidJob = ['id' => $jobId, 'checkpoints' => []];
    $rejected = false;
    try {
        application_update_job_validate_archive($invalidJob);
    } catch (RuntimeException) {
        $rejected = true;
    } finally {
        remove_updater_test_tree($jobDir);
    }
    assert_updater_resumable($rejected, 'Corrupt update archive was not rejected before extraction.');

    $sliceJobId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    $sliceJobDir = dirname(__DIR__) . '/cache/updates/jobs/' . $sliceJobId;
    mkdir($sliceJobDir, 0775, true);
    $zip = new ZipArchive();
    assert_updater_resumable($zip->open($sliceJobDir . '/package.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Could not create archive-validation fixture.');
    for ($entryIndex = 0; $entryIndex < 620; $entryIndex++) {
        $zip->addFromString('repo/app/fixture-' . $entryIndex . '.php', '<?php // fixture ' . $entryIndex);
    }
    $zip->close();
    $sliceJob = [
        'id' => $sliceJobId,
        'operation' => 'stable_update',
        'status' => 'running',
        'stage' => 'archive_validate',
        'checkpoints' => [],
        'progress' => [],
    ];
    try {
        $firstArchivePass = application_update_job_validate_archive($sliceJob, application_update_time_budget(12.0));
        assert_updater_resumable($firstArchivePass === false, 'Archive validation ignored its bounded 500-entry checkpoint.');
        assert_updater_resumable((int) ($sliceJob['checkpoints']['archive_validate_index'] ?? 0) === 500, 'Archive validation did not persist its first checkpoint index.');
        $secondArchivePass = application_update_job_validate_archive($sliceJob, application_update_time_budget(12.0));
        assert_updater_resumable($secondArchivePass === true, 'Archive validation did not resume from its durable checkpoint.');
        assert_updater_resumable((int) ($sliceJob['checkpoints']['archive_entries'] ?? 0) === 620, 'Archive validation completion metadata is incorrect.');
    } finally {
        remove_updater_test_tree($sliceJobDir);
    }
}

$root = dirname(__DIR__);
$jobsSource = (string) file_get_contents($root . '/app/services/updates_jobs.php');
$installSource = (string) file_get_contents($root . '/app/services/updates_install.php');
$controllerSource = (string) file_get_contents($root . '/app/controllers/updates.php');
$adminAuthSource = (string) file_get_contents($root . '/app/controllers/admin_auth.php');
$browserSource = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-update-jobs.js');
$migrationSource = (string) file_get_contents($root . '/app/migrations.php');
$statusSource = (string) file_get_contents($root . '/app/services/updates_status.php');
$patchNotesSource = (string) file_get_contents($root . '/app/services/updates_patch_notes.php');
$cliSource = (string) file_get_contents($root . '/scripts/application_update.php');
$deployShellSource = (string) file_get_contents($root . '/scripts/deploy.sh');
$deployPowerShellSource = (string) file_get_contents($root . '/scripts/deploy.ps1');
assert_updater_resumable(str_contains($jobsSource, "'download',") && str_contains($jobsSource, "'activate',") && str_contains($jobsSource, "'completed',"), 'Durable state-machine stages are no longer explicit.');
assert_updater_resumable(str_contains($jobsSource, 'flock($handle, LOCK_EX | LOCK_NB)'), 'Updater worker serialization no longer uses a non-blocking OS file lock.');
assert_updater_resumable(str_contains($jobsSource, 'function application_update_clear_active_job_if') && str_contains($jobsSource, 'A non-active job must never mutate application files concurrently'), 'Global active-job ownership protection disappeared.');
assert_updater_resumable(str_contains($jobsSource, "'If-Range: ' . \$ifRange") && str_contains($jobsSource, "['checkpoints']['archive_etag']") && str_contains($jobsSource, "['checkpoints']['archive_url']"), 'Resumable download lost its stable source/HTTP validator protections.');
assert_updater_resumable(str_contains($jobsSource, 'application_update_changed_release_files('), 'Activation planning no longer excludes unchanged files before the critical section.');
assert_updater_resumable(str_contains($jobsSource, 'Updater refuses symbolic links in managed activation paths.'), 'Managed activation symlink preflight protection disappeared.');
assert_updater_resumable(str_contains($jobsSource, 'Managed active file is too large for a bounded rollback snapshot.'), 'Rollback snapshot lost its single-file boundedness guard.');
assert_updater_resumable(str_contains($jobsSource, "'/ready/'") && str_contains($jobsSource, "'/rollback/original'"), 'Staging or rollback data no longer lives in the private update workspace.');
assert_updater_resumable(str_contains($jobsSource, 'Core-manifest verification is temporarily disabled') && str_contains($jobsSource, 'application_update_assert_source_root($sourceRoot);'), 'Temporary manifest bypass no longer preserves source-root validation.');
assert_updater_resumable(str_contains($jobsSource, "if ((string) (\$job['operation'] ?? '') === 'rollback')") && str_contains($jobsSource, 'Rollback does not reverse database migrations.'), 'Rollback migration policy is no longer explicit.');
assert_updater_resumable(str_contains($migrationSource, 'function run_migrations_bounded') && str_contains($migrationSource, 'array_slice($pendingFiles, 0, $maxMigrations)'), 'Migration runner no longer checkpoints at bounded migration-file units.');
assert_updater_resumable(!str_contains($migrationSource, "'preview' =>"), 'Migration diagnostics again expose raw SQL previews.');

foreach (['install_application_update', 'install_application_beta', 'restore_application_stable_release', 'clean_reinstall_current_application_version'] as $entrypoint) {
    $position = strpos($installSource, 'function ' . $entrypoint . '(');
    assert_updater_resumable($position !== false, 'Legacy updater entrypoint disappeared: ' . $entrypoint);
    $snippet = substr($installSource, (int) $position, 700);
    assert_updater_resumable(str_contains($snippet, 'application_update_start_job('), 'Legacy updater entrypoint bypasses durable jobs: ' . $entrypoint);
}
assert_updater_resumable(str_contains($installSource, 'application_update_continue_background_job(3.0)'), 'Background request flow no longer advances bounded durable jobs.');
assert_updater_resumable(str_contains($jobsSource, "time() - (int) (\$job['updated_at'] ?? 0) < 60") && str_contains($jobsSource, "application_update_retry_job((string) \$job['id'])"), 'Background failure recovery bypasses safe retry cleanup or retry backoff.');
assert_updater_resumable(str_contains($statusSource, 'float $requestedBudgetSeconds = 8.0') && str_contains($statusSource, '$branchDeadline'), 'Remote update discovery no longer has a per-request wall-clock budget shared across branches.');
assert_updater_resumable(str_contains($patchNotesSource, "application_update_fetch_github_content(\$branch, 'PATCH_NOTES.md', 5)"), 'Admin patch-note refresh reintroduced a long remote timeout.');
$autoStartPosition = strpos($installSource, 'function application_autoupdate_run_installing_check');
$autoStartEnd = strpos($installSource, 'function application_update_beta_backup_path', (int) $autoStartPosition);
$autoStartSource = substr($installSource, (int) $autoStartPosition, (int) $autoStartEnd - (int) $autoStartPosition);
assert_updater_resumable(!str_contains($autoStartSource, 'application_update_process_job('), 'Automatic discovery and package processing were recombined into one request.');
assert_updater_resumable(str_contains($cliSource, 'Discovery already consumed this invocation') && str_contains($cliSource, 'application_update_continue_background_job($budgetSeconds)'), 'CLI background continuation/discovery no longer uses the bounded safe retry path.');
assert_updater_resumable(stripos($deployShellSource, 'manifest') === false && preg_match('/^\s*php\s/m', $deployShellSource) !== 1, 'Shell deployment regained manifest handling or PHP execution.');
assert_updater_resumable(stripos($deployPowerShellSource, 'manifest') === false && !str_contains($deployPowerShellSource, '& php '), 'PowerShell deployment regained manifest handling or PHP execution.');
assert_updater_resumable(str_contains($jobsSource, "version_compare(\$validatedVersion, \$targetVersion, '<')"), 'Stable package validation no longer enforces the selected target-version floor.');
assert_updater_resumable(str_contains($jobsSource, 'function application_update_cancel_job') && str_contains($jobsSource, 'Update cannot be cancelled after activation has begun.'), 'Pre-activation cancellation boundary disappeared.');
assert_updater_resumable(str_contains($jobsSource, 'Caught failures require application_update_retry_job()') && str_contains($jobsSource, "if (in_array(\$stage, ['download', 'archive_validate', 'extract', 'package_validate'], true))"), 'Failed package jobs can bypass retry cleanup and resume untrusted artifacts directly.');
assert_updater_resumable(str_contains($jobsSource, "application_update_acquire_lock(application_update_jobs_root() . '/start.lock', 15)") && str_contains($jobsSource, 'Another application update job is active.'), 'Retry/cancel paths lost global start-lock serialization.');
assert_updater_resumable(str_contains($controllerSource, 'data-update-job-form') && str_contains($controllerSource, 'job_continue') && str_contains($controllerSource, 'job_retry') && str_contains($controllerSource, 'job_cancel'), 'Admin update controls no longer expose resumable continuation/cancellation actions.');
assert_updater_resumable(substr_count($controllerSource, 'cms_render_update_job_card($activeUpdateJob);') >= 2 && str_contains($controllerSource, 'admin.updates.advanced_progress_hint'), 'Advanced tools no longer renders discoverable update-job progress.');
$resetStart = strpos($adminAuthSource, 'function cms_admin_reset(): void');
$resetSource = substr($adminAuthSource, (int) $resetStart);
assert_updater_resumable(str_contains($resetSource, "application_update_start_job('stable_restore', [], 'admin')"), 'Authenticated reset page bypasses the resumable stable-restore job.');
assert_updater_resumable(str_contains($resetSource, 'application_update_safe_error($exception)') && !str_contains($resetSource, '$exception->getMessage()'), 'Authenticated reset page exposes raw updater exception text.');
assert_updater_resumable(str_contains($browserSource, "document.addEventListener('submit'") && str_contains($browserSource, '[data-update-job-form], [data-update-job-control]'), 'Dynamic Admin update controls are no longer intercepted through event delegation.');
assert_updater_resumable(str_contains($browserSource, 'scheduleContinuation') && str_contains($browserSource, 'data-update-job-status="running"'), 'Browser closure/reopen continuation hook is missing.');
assert_updater_resumable(str_contains($browserSource, 'findUpdateScopes(document)') && str_contains($browserSource, 'renderJobInScope(scope, job)'), 'Status and Advanced updater progress cards are no longer synchronized.');
assert_updater_resumable(str_contains($browserSource, '[data-update-job-cancel]') && str_contains($browserSource, "postJob(window.location.href, csrfToken, 'job_cancel', id)"), 'Dynamically rendered updater cancellation is not intercepted in place.');
assert_updater_resumable(!str_contains($browserSource, 'window.location.reload') && !str_contains($browserSource, 'window.location.href ='), 'JavaScript updater reintroduced page navigation/reload.');

$activationStart = strpos($jobsSource, 'function application_update_job_activate(');
$activationEnd = strpos($jobsSource, 'function application_update_job_migrate_slice(', (int) $activationStart);
$activationSource = substr($jobsSource, (int) $activationStart, (int) $activationEnd - (int) $activationStart);
foreach (['application_update_job_download_slice', 'application_update_job_extract_slice', 'application_update_job_backup_slice', 'run_migrations_bounded'] as $forbiddenActivationWork) {
    assert_updater_resumable(!str_contains($activationSource, $forbiddenActivationWork), 'Activation critical section contains expensive precondition work: ' . $forbiddenActivationWork);
}

$serviceFiles = glob($root . '/app/services/updates_*.php') ?: [];
foreach ($serviceFiles as $serviceFile) {
    if (basename($serviceFile) === 'updates_jobs.php') {
        $source = (string) file_get_contents($serviceFile);
        $source = preg_replace('/function application_update_safe_error\(.*?\n}\n/s', '', $source, 1) ?? $source;
        assert_updater_resumable(!str_contains($source, '->getMessage()'), 'Updater service exposes raw exception messages outside the redaction boundary: ' . basename($serviceFile));
        continue;
    }
    assert_updater_resumable(!str_contains((string) file_get_contents($serviceFile), '->getMessage()'), 'Updater service exposes raw exception messages: ' . basename($serviceFile));
}

echo "Resumable updater state-machine tests passed.\n";
