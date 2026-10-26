<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/updates_background.php
 * Module Type: Service
 * Purpose: Complete visitor-started stable updates through independent HTTP slices.
 * Responsibilities: Lease single-use handoffs, serialize chains, preserve worker
 *   budgets and retain redacted dispatch/arrival evidence without browser polling.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Determine whether an active job belongs to unattended stable installation.
 * @param array{id?:string,trigger?:string,operation?:string,status?:string,automatic_rollback?:bool,automatic_repair_continuation?:bool}|null $job Active private job, or null when idle.
 * @return bool True for running automatic stable/rollback work or an admitted failed-job repair, with the installer capability enabled.
 */
function application_update_background_job_eligible(?array $job): bool
{
    return $job !== null
        && (!empty($job['automatic_repair_continuation'])
            || (($job['trigger'] ?? '') === 'background'
                && (($job['operation'] ?? '') === 'stable_update'
                    || (($job['operation'] ?? '') === 'rollback' && !empty($job['automatic_rollback'])))))
        && ($job['status'] ?? '') === 'running'
        && feature_capability_effective_enabled('built_in_update_installer');
}

/**
 * Deliver a reserved ticket and retain dispatch failure without altering a successor.
 * @param string $ticket Private sixty-four-character hexadecimal handoff token.
 * @param callable(string):bool $deliver Configured-origin transport accepting only this ticket.
 * @return bool True after a complete request write; false records safe dispatch failure, never worker success.
 */
function application_update_background_chain_deliver(
    string $ticket,
    callable $deliver,
): bool {
    try {
        if ($deliver($ticket)) {
            return true;
        }
    } catch (\Throwable) {
        // Provider messages may contain private origins or credentials.
    }
    $lock = application_update_acquire_lock(
        application_update_jobs_root() . '/background-chain.lock',
    );
    if ($lock === null) {
        return false;
    }
    try {
        $path = application_update_jobs_root() . '/background-chain.json';
        $record = application_update_read_json($path);
        if (hash_equals((string) ($record['ticket_hash'] ?? ''), hash(
            'sha256',
            $ticket,
        ))) {
            $record['ticket_hash'] = '';
            $record['queued_until'] = 0;
            $record['last_error'] = 'update_chain_dispatch_unavailable';
            application_update_write_json_atomic($path, $record);
        }
    } finally {
        application_update_release_lock($lock);
    }
    return false;
}

/**
 * Start or recover the active stable job's continuation after a successful visit.
 * @param callable(string):bool $deliver Configured-origin, non-waiting ticket transport.
 * @return array{ok:bool,queued:bool,reason:string} Queued receipt, existing owner, idle/manual state or redacted dispatch refusal.
 */
function application_update_background_chain_start(callable $deliver): array
{
    $idle = ['ok' => true, 'queued' => false, 'reason' => 'update_chain_idle'];
    $lock = application_update_acquire_lock(
        application_update_jobs_root() . '/background-chain.lock',
    );
    if ($lock === null) {
        return [
            'ok' => true,
            'queued' => false,
            'reason' => 'update_chain_active',
        ];
    }
    try {
        $job = application_update_active_job();
        if (!application_update_background_job_eligible($job)) {
            return $idle;
        }
        $path = application_update_jobs_root() . '/background-chain.json';
        $record = application_update_read_json($path);
        $now = time();
        if (($record['job_id'] ?? '') === $job['id']) {
            if ((int) ($record['queued_until'] ?? 0) > $now) {
                return [
                    'ok' => true,
                    'queued' => false,
                    'reason' => 'update_chain_queued',
                ];
            }
            if (
                ($now - (int) ($record['last_dispatch_at'] ?? 0))
                < APPLICATION_UPDATE_CHAIN_RETRY_SECONDS
            ) {
                return [
                    'ok' => true,
                    'queued' => false,
                    'reason' => 'update_chain_throttled',
                ];
            }
        }
        // 32 random bytes form the fixed 256-bit, 64-hex-character wire token.
        $ticket = bin2hex(random_bytes(32));
        $record = [
            'job_id' => (string) $job['id'],
            'ticket_hash' => hash('sha256', $ticket),
            'queued_until' => $now + APPLICATION_UPDATE_CHAIN_LEASE_SECONDS,
            'last_dispatch_at' => $now,
            'last_arrival_at' => ($record['job_id'] ?? '') === $job['id']
                ? (int) ($record['last_arrival_at'] ?? 0)
                : 0,
            'last_error' => '',
        ];
        application_update_write_json_atomic($path, $record);
    } finally {
        application_update_release_lock($lock);
    }
    $queued = application_update_background_chain_deliver($ticket, $deliver);
    return [
        'ok' => $queued,
        'queued' => $queued,
        'reason' => $queued
            ? 'update_chain_queued'
            : 'update_chain_dispatch_unavailable',
    ];
}

/**
 * Consume one authenticated handoff and advance only its original active job.
 * @param string $ticket Private single-use token normalized by the HTTP controller.
 * @param callable(string):bool $deliver Configured-origin transport for the next reserved token.
 * @return array{ok:bool,queued:bool,reason:string} Safe admission/worker result without tokens, paths or raw exceptions; queued only proves request delivery.
 */
function application_update_background_chain_run(
    string $ticket,
    callable $deliver,
): array {
    $refused = [
        'ok' => false,
        'queued' => false,
        'reason' => 'update_chain_ticket_refused',
    ];
    if (preg_match('/^[a-f0-9]{64}$/D', $ticket) !== 1) {
        return $refused;
    }
    $lock = application_update_acquire_lock(
        application_update_jobs_root() . '/background-chain.lock',
    );
    if ($lock === null) {
        return [
            'ok' => true,
            'queued' => false,
            'reason' => 'update_chain_active',
        ];
    }
    $nextTicket = '';
    try {
        $path = application_update_jobs_root() . '/background-chain.json';
        $record = application_update_read_json($path);
        if (
            (int) ($record['queued_until'] ?? 0) <= time()
            || !hash_equals((string) ($record['ticket_hash'] ?? ''), hash(
                'sha256',
                $ticket,
            ))
        ) {
            return $refused;
        }
        // Commit consumption before work: replay cannot advance a second slice.
        $record['ticket_hash'] = '';
        $record['queued_until'] =
            time() + APPLICATION_UPDATE_CHAIN_LEASE_SECONDS;
        $record['last_arrival_at'] = time();
        application_update_write_json_atomic($path, $record);
        $job = application_update_active_job();
        if (
            !application_update_background_job_eligible($job)
            || ($record['job_id'] ?? '') !== $job['id']
        ) {
            $record['queued_until'] = 0;
            application_update_write_json_atomic($path, $record);
            return [
                'ok' => true,
                'queued' => false,
                'reason' => 'update_chain_idle',
            ];
        }
        $attempts = (int) ($job['attempts'] ?? 0);
        application_update_continue_background_job(
            APPLICATION_UPDATE_CHAIN_SLICE_SECONDS,
        );
        $job = application_update_active_job();
        $continues =
            application_update_background_job_eligible($job)
            && empty($job['checkpoints']['runtime_lease_busy'])
            && ($record['job_id'] ?? '') === $job['id']
            && (int) ($job['attempts'] ?? 0) > $attempts;
        $record['queued_until'] = 0;
        $reason = 'update_chain_stopped';
        if ($continues) {
            $nextTicket = bin2hex(random_bytes(32));
            $record['ticket_hash'] = hash('sha256', $nextTicket);
            $record['queued_until'] =
                time() + APPLICATION_UPDATE_CHAIN_LEASE_SECONDS;
            $record['last_dispatch_at'] = time();
            $reason = 'update_chain_queued';
        }
        // Failed/busy work stops this chain. Later opportunities use bounded
        // retry backoff or separate due discovery; no timer keeps PHP waiting.
        $record['last_error'] = ($job['status'] ?? '') === 'failed'
            ? 'update_chain_worker_failed'
            : '';
        if ($record['last_error'] !== '') {
            $reason = $record['last_error'];
        }
        application_update_write_json_atomic($path, $record);
    } catch (\Throwable) {
        if (isset($record, $path)) {
            $record['ticket_hash'] = '';
            $record['queued_until'] = 0;
            $record['last_error'] = 'update_chain_worker_exception';
            application_update_write_json_atomic($path, $record);
        }
        return [
            'ok' => false,
            'queued' => false,
            'reason' => 'update_chain_worker_exception',
        ];
    } finally {
        application_update_release_lock($lock);
    }
    // Release the chain lock before a successor can arrive; never wait for it.
    $queued =
        $nextTicket !== ''
        && application_update_background_chain_deliver($nextTicket, $deliver);
    return [
        'ok' => $record['last_error'] === '' && ($nextTicket === '' || $queued),
        'queued' => $queued,
        'reason' => $nextTicket !== '' && !$queued
            ? 'update_chain_dispatch_unavailable'
            : $reason,
    ];
}
