<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/updates_job_lookup.php
 * Module Type: Service
 * Purpose: Read active updater job state without loading worker and activation logic.
 * Responsibilities: Preserve safe job lookup APIs for request policy and legacy callers.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;

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
    $job = application_update_read_json(application_update_job_state_path($jobId));
    if ($job === [] || (string) ($job['id'] ?? '') !== $jobId) {
        throw new RuntimeException('Update job state was not found.');
    }
    return $job;
}

/**
 * Return whether a job state no longer requires worker execution.
 *
 * @param array<string,mixed> $job Persisted updater job state.
 * @return bool True for completed or cancelled jobs.
 */
function application_update_job_terminal(array $job): bool
{
    return in_array((string) ($job['status'] ?? ''), ['completed', 'cancelled'], true);
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
        @unlink($pointerPath);
        return null;
    }

    if (application_update_job_terminal($job)) {
        @unlink($pointerPath);
        return null;
    }
    return $job;
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
