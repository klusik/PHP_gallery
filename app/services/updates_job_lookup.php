<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/updates_job_lookup.php
 * Module Type: Service
 * Purpose: Read updater state and reduce references without loading worker logic.
 * Responsibilities: Preserve job lookups and stable opaque failure references.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;

/** Retain the installed opaque updater failure-reference protocol.
 * Type: int. Units: hexadecimal SHA-256 characters. Scope: safe diagnostics.
 * Consumers: application_update_error_reference and existing stored job errors.
 * Rationale: 12 preserves correlation with legacy references without messages.
 */
const APPLICATION_UPDATE_ERROR_REFERENCE_LENGTH = 12;

/**
 * Return the updater workspace used for job state and related artifacts.
 *
 * @return string Absolute filesystem path for updater job data.
 */
function application_update_jobs_root(): string
{
    $root = application_update_project_root() . '/cache/updates';
    application_update_ensure_dir($root);
    application_update_ensure_dir($root . '/jobs');
    return $root;
}

/**
 * Load a persisted JSON state file for a lookup or worker operation.
 *
 * @param string $path Absolute or workspace-relative JSON path.
 * @return array<string,mixed> Decoded payload, or an empty array when absent or invalid.
 */
function application_update_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Return the private directory for one validated updater job identifier.
 *
 * @param string $jobId Durable updater job identifier.
 * @return string Absolute job directory path.
 */
function application_update_job_dir(string $jobId): string
{
    if (preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $jobId) !== 1) {
        throw new RuntimeException('Invalid update job identifier.');
    }
    return application_update_jobs_root() . '/jobs/' . $jobId;
}

/**
 * Return the persisted JSON path for one validated updater job identifier.
 *
 * @param string $jobId Durable updater job identifier.
 * @return string Absolute job state path.
 */
function application_update_job_state_path(string $jobId): string
{
    return application_update_job_dir($jobId) . '/job.json';
}

/**
 * Load one persisted job and verify that its state matches the requested identifier.
 *
 * @param string $jobId Durable updater job identifier.
 * @return array<string,mixed> Persisted job state.
 */
function application_update_load_job(string $jobId): array
{
    $job = application_update_read_json(application_update_job_state_path(
        $jobId,
    ));
    if ($job === [] || (string) ($job['id'] ?? '') !== $jobId) {
        throw new RuntimeException('Update job state was not found.');
    }
    return $job;
}

/**
 * Return whether a job state no longer requires worker execution.
 *
 * @param array<string,mixed> $job Persisted updater job state.
 * @return bool True for completed/cancelled work or a failed attempt whose recovery durably released ownership.
 */
function application_update_job_terminal(array $job): bool
{
    return (($job['status'] ?? '') === 'failed'
            && !empty($job['result']['recovery_released'])) || in_array(
        (string) ($job['status'] ?? ''),
        ['completed', 'cancelled'],
        true,
    );
}

/**
 * Return the active updater job, clearing a missing, corrupt, or terminal pointer.
 *
 * @return array<string,mixed>|null Active job state, or null when no active job remains.
 */
function application_update_active_job(): ?array
{
    $pointerPath = application_update_jobs_root() . '/active-job.json';
    $pointer = application_update_read_json($pointerPath);
    $jobId = (string) ($pointer['job_id'] ?? '');
    if ($jobId === '') {
        return null;
    }

    try {
        $job = application_update_load_job($jobId);
    } catch (Throwable) {
        application_update_lookup_release_active($jobId);
        return null;
    }

    if (application_update_job_terminal($job)) {
        application_update_lookup_release_active($jobId);
        return null;
    }
    return $job;
}

/**
 * Release only the observed active identity while excluding new starts.
 * @param string $jobId Identity observed by a lookup or completing worker.
 * @return void Removes only that pointer; a busy start owner leaves it intact.
 */
function application_update_lookup_release_active(string $jobId): void
{
    $lock = fopen(application_update_jobs_root() . '/start.lock', 'c');
    if ($lock === false) {
        return;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return;
    }
    try {
        $path = application_update_jobs_root() . '/active-job.json';
        $pointer = application_update_read_json($path);
        if (hash_equals((string) ($pointer['job_id'] ?? ''), $jobId)) {
            @unlink($path);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Observe a queued or running autonomous chain without advancing its job.
 * @param string $jobId Active job identifier whose continuation ownership is checked.
 * @return bool True while that job's bounded chain lease remains live; an absent, stale or different-job lease permits ordinary visitor recovery.
 */
function application_update_background_chain_pending(string $jobId): bool
{
    $record = application_update_read_json(
        application_update_jobs_root() . '/background-chain.json',
    );
    return (
        ($record['job_id'] ?? '') === $jobId
            && (int) ($record['queued_until'] ?? 0) > time()
    );
}

/**
 * Return the last persisted updater job used by rollback controls.
 *
 * @return array<string,mixed>|null Last job state, or null when the pointer is absent or invalid.
 */
function application_update_last_job(): ?array
{
    $pointerPath = application_update_jobs_root() . '/last-job.json';
    $pointer = application_update_read_json($pointerPath);
    $jobId = (string) ($pointer['job_id'] ?? '');
    if ($jobId === '') {
        return null;
    }
    try {
        return application_update_load_job($jobId);
    } catch (Throwable) {
        @unlink($pointerPath);
        return null;
    }
}

/**
 * Return a short safe reference for an internal updater failure.
 *
 * @param string $message Internal exception message.
 * @return string Stable diagnostic reference.
 */
function application_update_error_reference(string $message): string
{
    return strtoupper(substr(hash('sha256', $message), 0,
        APPLICATION_UPDATE_ERROR_REFERENCE_LENGTH));
}


/** Identify publisher file differences using safe repository-relative paths. */
final class ApplicationUpdateFileIntegrityException extends RuntimeException
{
    /**
     * Carry actionable file evidence without server paths or exception text.
     * @param string $relativeFile Validated updater-owned relative file identity.
     * @param string $expectedHash Manifest SHA-256, or empty for missing coverage.
     * @param string $actualHash Complete observed normalized SHA-256.
     * @return void Initializes bounded evidence and the stable failure reference.
     */
    public function __construct(
        public readonly string $relativeFile,
        public readonly string $expectedHash,
        public readonly string $actualHash,
    ) {
        parent::__construct('Recovery package failed core-manifest integrity validation.');
    }
}


/**
 * Return bounded recovery guidance for a package-publisher integrity failure.
 * @return string Stable safe guidance without private package or server details.
 */
function application_update_manifest_mismatch_message(): string
{
    return 'This release package does not match its integrity manifest. Retrying the same build cannot repair it; cancel this job and select a newer release or beta code.';
}

/**
 * Convert arbitrary updater failures into an Admin-safe message and reference.
 *
 * The returned payload deliberately excludes the exception text, filesystem
 * paths, URLs, SQL, credentials, tokens, and stack traces.
 *
 * @param scalar|array<array-key,mixed>|object{}|resource|null $error A Throwable
 *     contributes its message; other values are string-cast only to derive the
 *     opaque reference and retry classification. Sensitive details are omitted.
 * @return array{message:string,reference:string,retryable:bool} Safe message,
 *     opaque diagnostic reference and retryability, without internal details.
 */
function application_update_safe_error(mixed $error): array
{
    $internal = $error instanceof Throwable
        ? $error->getMessage()
        : (string) $error;
    $reference = application_update_error_reference($internal);
    $lower = strtolower($internal);

    $retryable = !($error instanceof \Error)
        && !str_contains($lower, 'call to undefined function');
    if ($error instanceof ApplicationUpdateFileIntegrityException) {
        $message = application_update_manifest_mismatch_message()
            . ' File: ' . $error->relativeFile . '.';
        $retryable = false;
    } elseif (
        str_contains($lower, 'failed core-manifest integrity validation')
        || str_contains(
            $lower,
            'installable file that is missing from the core manifest',
        )
        || str_contains($lower, 'version markers do not agree')
    ) {
        $message = application_update_manifest_mismatch_message();
        $retryable = false;
    } elseif (str_contains($lower, 'quarantined')) {
        $message = 'This update commit failed previously and is excluded from automatic installation. Select a newer verified release.';
        $retryable = false;
    } elseif (str_contains($lower, 'newer manual deployment')) {
        $message = 'New application files were uploaded manually. Use Finish FTP update in Advanced tools to verify them and complete forward migrations. The older package will not be resumed.';
        $retryable = false;
    } elseif (str_contains($lower, 'recovery')) {
        $message = 'Automatic recovery could not verify the package, database or backup safety. Preserve the jobs and backups and ask the administrator to verify a compatible release in maintenance mode.';
        $retryable = false;
    } elseif (
        str_contains($lower, 'archive')
        || str_contains($lower, 'zip')
        || str_contains($lower, 'extract')
    ) {
        $message = 'The downloaded update package could not be prepared or validated.';
    } elseif (
        str_contains($lower, 'migration')
        || str_contains($lower, 'schema')
    ) {
        $message = 'The database migration stage could not be completed safely.';
    } elseif (
        str_contains($lower, 'download')
        || str_contains($lower, 'http')
        || str_contains($lower, 'curl')
        || str_contains($lower, 'github')
    ) {
        $message = 'The update download could not be completed.';
    } elseif (
        str_contains($lower, 'backup')
        || str_contains($lower, 'rollback')
    ) {
        $message = 'The updater could not prepare or use the rollback data safely.';
    } elseif (
        str_contains($lower, 'activate')
        || str_contains($lower, 'replace')
        || str_contains($lower, 'rename')
    ) {
        $message = 'The prepared update could not be activated safely.';
    } else {
        $message = 'The update job could not continue safely.';
    }

    return [
        'message' => $message,
        'reference' => $reference,
        'retryable' => $retryable,
    ];
}
