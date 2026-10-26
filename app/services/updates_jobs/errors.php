<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs/errors.php
 * Module Type: Service
 *
 * Purpose:
 *   Reduces update failures to safe, referencable messages.
 *
 * Responsibilities:
 *   - Produce a stable error reference instead of leaking internals
 *   - Reduce an exception or error to a bounded safe payload
 *   - Classify whether a failed job may be retried
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
 *   - Loaded by app/services/updates_jobs.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/updates_jobs.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\run_migrations_bounded;

/**
 * Return a short safe reference for an internal updater failure.
 *
 * @param string $message Internal exception message.
 * @return string Stable diagnostic reference.
 */
function application_update_error_reference(string $message): string
{
    return strtoupper(substr(hash('sha256', $message), 0, 12));
}

/**
 * Return bounded recovery guidance for a package-publisher integrity failure.
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
 * @param scalar|array<array-key,mixed>|object{}|resource|null $error A Throwable contributes its message; any other value is string-cast and used only to derive a private diagnostic reference and retryability classification. Sensitive internal details are excluded from the returned payload.
 * @return array{message:string,reference:string,retryable:bool} Admin-safe message, opaque diagnostic reference, and whether retrying may succeed; internal exception details are excluded.
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
    if (
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

/**
 * Return whether a failed update job may benefit from retrying the same source.
 *
 * Older persisted jobs predate the explicit retryable field. Their bounded
 * reference can still identify the three deterministic package-publisher
 * failures without retaining or exposing the original exception text.
 *
 * @param array{error?:array{reference?:string,retryable?:bool},...<string,mixed>} $job Durable job with optional stored safe error details.
 * @return bool Whether retrying the same update source is considered useful.
 */
function application_update_job_error_retryable(array $job): bool
{
    if (!empty($job['result']['superseded_by'])
        || !empty($job['result']['recovery_released'])
        || !empty($job['automatic_rollback_reservation'])) {
        return false;
    }
    $error = isset($job['error']) && is_array($job['error'])
        ? $job['error']
        : [];
    // This known legacy undefined-function failure was stored as retryable by
    // the old reducer. It cannot repair the package by repeating its callback.
    if (strtoupper((string) ($error['reference'] ?? '')) === 'F05D71A153A2') {
        return false;
    }
    if (in_array((string) ($job['stage'] ?? ''),
        ['download', 'archive_validate', 'extract', 'package_validate'], true)
        && !empty($job['checkpoints']['archive_url'])
        && empty($job['checkpoints']['archive_commit'])) {
        return false;
    }
    if (array_key_exists('retryable', $error)) {
        return $error['retryable'] !== false;
    }

    $reference = strtoupper((string) ($error['reference'] ?? ''));
    $nonRetryableReferences = [
        application_update_error_reference(
            'Downloaded update archive failed core-manifest integrity validation.',
        ),
        application_update_error_reference(
            'Downloaded update archive contains an installable file that is missing from the core manifest.',
        ),
        application_update_error_reference(
            'Downloaded update package version markers do not agree.',
        ),
    ];
    return !in_array($reference, $nonRetryableReferences, true);
}


// Recovery responsibilities stay with their existing updater domain owner.

/**
 * Decide whether an unattended retry is still useful and due.
 * @param array<string,mixed> $job Private durable job and failure counters.
 * @param int $now Unix time used for backoff admission.
 * @return bool True for an eligible failed update or automatic rollback after its backoff.
 */
function application_update_auto_retry_due(array $job, int $now): bool
{
    $retries = max(0, (int) ($job['auto_retries'] ?? 0));
    return ($job['status'] ?? '') === 'failed'
        && empty($job['result']['recovery_released'])
        && empty($job['recovery_blocked'])
        && (($job['operation'] ?? '') !== 'rollback' || !empty($job['automatic_rollback']))
        && application_update_job_error_retryable($job)
        && $retries < APPLICATION_UPDATE_AUTO_RETRY_LIMIT
        && $now >= (int) ($job['error']['at'] ?? $job['updated_at'] ?? 0)
            + APPLICATION_UPDATE_AUTO_RETRY_DELAY_SECONDS * (2 ** $retries);
}

/**
 * Return whether a failed job has an automatic action due now.
 * @param array<string,mixed> $job Durable failed job and recovery receipts.
 * @param int $now Current Unix timestamp.
 * @return bool True for an eligible retry or a mature original failure.
 */
function application_update_failed_recovery_due(array $job, int $now): bool
{
    if (($job['status'] ?? '') !== 'failed'
        || !empty($job['result']['recovery_released'])
        || !empty($job['recovery_blocked'])) {
        return false;
    }
    if (application_update_auto_retry_due($job, $now)) {
        return true;
    }
    if (($job['operation'] ?? '') === 'rollback'
        || (application_update_job_error_retryable($job)
            && (int) ($job['auto_retries'] ?? 0) < APPLICATION_UPDATE_AUTO_RETRY_LIMIT)) {
        return false;
    }
    $first = (int) ($job['failure_first_at'] ?? $job['result']['first_failure']['at']
        ?? $job['error']['at'] ?? $job['updated_at'] ?? $now);
    if ($first <= 0) {
        $first = (int) ($job['updated_at'] ?? $now);
    }
    return $first > 0 && $now >= $first + APPLICATION_UPDATE_RECOVERY_TIMEOUT_SECONDS;
}

/**
 * Explain a stopped job with a concrete safe administrator action.
 * @param array<string,mixed> $job Failed durable job and recovery classification.
 * @return string Localized safe guidance without private paths or SQL.
 */
function application_update_recovery_guidance(array $job): string
{
    $parameters = [];
    if (!empty($job['result']['recovery_released'])) {
        $key = 'admin.updates.recovery_restored';
        $message = 'The failed attempt has been released and the previous application files are in place. Its failed version or commit is excluded from automatic installation; enabled automatic checks can install a newer stable release.';
    } elseif (!empty($job['automatic_rollback'])) {
        $key = 'admin.updates.recovery_limit';
        $message = 'Automatic rollback could not finish. Preserve both jobs and backups. Correct backup integrity or hosting permissions. Use Retry only when it is offered; otherwise deploy a verified compatible release in maintenance mode before another update.';
    } elseif (!empty($job['result']['superseded_by'])) {
        $key = 'admin.updates.recovery_started';
        $message = 'Automatic rollback has a separate job: {job}. Reopen Updates to follow that job. The original failure and its evidence remain preserved.';
        $parameters = ['job' => (string) $job['result']['superseded_by']];
    } elseif (($job['recovery_blocked'] ?? '') === 'backup_unverified') {
        $key = 'admin.updates.recovery_backup_blocked';
        $message = 'Automatic rollback stopped: the backup is missing, incomplete or corrupt. Preserve the job and backup. The administrator must recover a complete verified snapshot or deploy a compatible release in maintenance mode.';
    } elseif (!empty($job['recovery_blocked']) || !in_array(
        application_update_recovery_boundary($job), ['pre_activation', 'rollback_safe'], true,
    )) {
        $key = 'admin.updates.recovery_database_blocked';
        $message = 'Automatic rollback stopped: database migration effects or compatibility are unverified. Preserve the job and backups. The administrator must inspect the database and interrupted migration, then deploy a verified compatible release in maintenance mode and continue forward migrations. Database migrations are not reversed.';
    } else {
        $key = 'admin.updates.recovery_waiting';
        $message = 'Update stopped. Recovery allows up to three retries and, after a ten-minute failure timeout, restores previous files only with verified database safety. Keep the job and backups. An authenticated Updates request or configured hosting CLI worker must provide a later opportunity while the site is gated.';
    }
    // Direct CLI entry consumers permanently retain safe English fallback text.
    return function_exists(__NAMESPACE__ . '\\t') ? t($key, $message, $parameters)
        : strtr($message, ['{job}' => (string) ($parameters['job'] ?? '')]);
}
