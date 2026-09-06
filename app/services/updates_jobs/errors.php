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
 * @param Throwable|string $error Internal failure.
 * @return array{message:string,reference:string,retryable:bool}
 */
function application_update_safe_error($error): array
{
    $internal = $error instanceof Throwable ? $error->getMessage() : (string) $error;
    $reference = application_update_error_reference($internal);
    $lower = strtolower($internal);

    $retryable = true;
    if (str_contains($lower, 'failed core-manifest integrity validation')
        || str_contains($lower, 'installable file that is missing from the core manifest')
        || str_contains($lower, 'version markers do not agree')) {
        $message = application_update_manifest_mismatch_message();
        $retryable = false;
    } elseif (str_contains($lower, 'archive') || str_contains($lower, 'zip') || str_contains($lower, 'extract')) {
        $message = 'The downloaded update package could not be prepared or validated.';
    } elseif (str_contains($lower, 'migration') || str_contains($lower, 'schema')) {
        $message = 'The database migration stage could not be completed safely.';
    } elseif (str_contains($lower, 'download') || str_contains($lower, 'http') || str_contains($lower, 'curl') || str_contains($lower, 'github')) {
        $message = 'The update download could not be completed.';
    } elseif (str_contains($lower, 'backup') || str_contains($lower, 'rollback')) {
        $message = 'The updater could not prepare or use the rollback data safely.';
    } elseif (str_contains($lower, 'activate') || str_contains($lower, 'replace') || str_contains($lower, 'rename')) {
        $message = 'The prepared update could not be activated safely.';
    } else {
        $message = 'The update job could not continue safely.';
    }

    return ['message' => $message, 'reference' => $reference, 'retryable' => $retryable];
}

/**
 * Return whether a failed update job may benefit from retrying the same source.
 *
 * Older persisted jobs predate the explicit retryable field. Their bounded
 * reference can still identify the three deterministic package-publisher
 * failures without retaining or exposing the original exception text.
 */
function application_update_job_error_retryable(array $job): bool
{
    $error = isset($job['error']) && is_array($job['error']) ? $job['error'] : [];
    if (array_key_exists('retryable', $error)) {
        return $error['retryable'] !== false;
    }

    $reference = strtoupper((string) ($error['reference'] ?? ''));
    $nonRetryableReferences = [
        application_update_error_reference('Downloaded update archive failed core-manifest integrity validation.'),
        application_update_error_reference('Downloaded update archive contains an installable file that is missing from the core manifest.'),
        application_update_error_reference('Downloaded update package version markers do not agree.'),
    ];
    return !in_array($reference, $nonRetryableReferences, true);
}
