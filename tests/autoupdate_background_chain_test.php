<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/autoupdate_background_chain_test.php
 * Module Type: Regression Test
 * Purpose: Drain a real updater cleanup job through single-use worker handoffs.
 * Responsibilities: Prove one visitor's multi-hop completion, replay/lease/lock
 *   protection, busy-worker yielding, manual-job isolation and dispatch recovery.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Resolve the isolated installer's explicit capability state.
     * @param string $key Canonical capability requested by the real updater.
     * @return bool True for the enabled fixture installer only.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return (
            $key === 'built_in_update_installer'
                && empty($GLOBALS['update_chain_fixture_disabled'])
        );
    }

    /**
     * Supply absent optional recovery settings without a database.
     * @param string $key Setting requested by the actual cleanup owner.
     * @param string $default Missing-value fallback.
     * @return string Provided default; no beta backup exists in this fixture.
     */
    function app_setting(string $key, string $default = ''): string
    {
        return $default;
    }
}

namespace {
    /**
     * Require a concrete continuation or filesystem postcondition.
     * @param bool $condition Required observation from the actual owner.
     * @param string $message Failure explanation.
     * @return void Throws when the observation is absent.
     */
    function update_chain_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Write disposable prune debt large enough to force independent worker hops.
     * @param string $root Random fixture installation root.
     * @return void Writes 720 payloads beneath the updater's private prune queue.
     */
    function update_chain_seed_debt(string $root): void
    {
        $trash = \Gallery\Services\application_update_prune_trash_root($root);
        \Gallery\Services\application_update_ensure_dir($trash);
        // Four 180-entry production deletion caps require multiple worker hops.
        // Type: int. Units: files. Scope: this disposable fixture only.
        // Consumers: the real cleanup worker. Rationale: 720 exceeds one slice
        // while keeping the fixture far below the production twenty-slice cap.
        $files = 720;
        for ($index = 0; $index < $files; $index++) {
            file_put_contents($trash . '/payload-' . $index, 'fixture');
        }
    }

    $root =
        sys_get_temp_dir()
        . '/gallery-update-chain-'
        . bin2hex(random_bytes(8));
    mkdir($root, 0700, true);
    // Existing canonical fixture override confines every real updater operation.
    // Type: string. Units: absolute path. Scope: this standalone test process.
    // Consumers: canonical updater filesystem root and all real worker slices.
    // Rationale: the random owned temporary root isolates every mutation from
    // the actual installation while exercising production persistence code.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    require_once __DIR__ . '/../app/services/updates.php';
    $queue = [];
    $deliver = static function (string $ticket) use (&$queue): bool {
        $queue[] = $ticket;
        return true;
    };
    $jobId = '20261019000000-abcdef123456';
    $job = [
        'id' => $jobId,
        'operation' => 'stable_update',
        'trigger' => 'background',
        'status' => 'running',
        'stage' => 'cleanup',
        'attempts' => 0,
        'parameters' => [],
        'checkpoints' => [],
        'result' => [],
        'progress' => [],
    ];
    $path =
        \Gallery\Services\application_update_jobs_root()
        . '/background-chain.json';
    /**
     * Bound this fixture's failure watchdog without limiting production jobs.
     * Type: int. Units: requests. Scope: the 720-file cleanup scenario only.
     * Consumers: queued fixture worker loop. Rationale: twenty hops exceed the
     *   expected cleanup debt while detecting non-terminating regressions.
     */
    const UPDATE_CHAIN_FIXTURE_MAX_HOPS = 20;

    try {
        \Gallery\Services\application_update_save_job($job);
        \Gallery\Services\application_update_set_active_job($jobId);
        update_chain_seed_debt($root);
        $started =
            \Gallery\Services\application_update_background_chain_start(
                $deliver,
            );
        update_chain_assert(
            $started['queued'] && count($queue) === 1,
            'One visitor did not reserve exactly one worker handoff.',
        );
        $first = $queue[0];
        $record = \Gallery\Services\application_update_read_json($path);
        update_chain_assert(
            $record['last_arrival_at'] === 0
                && !str_contains(file_get_contents($path), $first),
            'A dispatch was advertised as arrival or the plaintext token was persisted.',
        );
        update_chain_assert(
            \Gallery\Services\application_update_background_chain_pending(
                $jobId,
            ),
            'Ordinary visits cannot observe the live server-chain lease.',
        );
        \Gallery\Services\application_update_background_chain_start($deliver);
        update_chain_assert(
            count($queue) === 1,
            'A second visitor duplicated the queued chain.',
        );
        update_chain_assert(
            !\Gallery\Services\application_update_background_chain_run(
                str_repeat('0', 64),
                $deliver,
            )['ok'],
            'A guessed token advanced an actual update.',
        );
        $lock = \Gallery\Services\application_update_acquire_lock(
            dirname($path) . '/background-chain.lock',
        );
        update_chain_assert(
            \Gallery\Services\application_update_background_chain_run(
                $first,
                $deliver,
            )['reason'] === 'update_chain_active',
            'A second contender entered the owned chain.',
        );
        \Gallery\Services\application_update_release_lock($lock);
        $hops = 0;
        while ($queue !== []) {
            $ticket = array_shift($queue);
            $result = \Gallery\Services\application_update_background_chain_run(
                $ticket,
                $deliver,
            );
            update_chain_assert(
                $result['ok'],
                'A real cleanup slice failed: ' . json_encode($result),
            );
            update_chain_assert(
                !\Gallery\Services\application_update_background_chain_run(
                    $ticket,
                    $deliver,
                )['ok'],
                'A consumed token was replayable.',
            );
            $hops++;
            update_chain_assert(
                $hops <= UPDATE_CHAIN_FIXTURE_MAX_HOPS,
                'The real cleanup chain did not reach its terminal checkpoint.',
            );
        }
        $completed = \Gallery\Services\application_update_load_job($jobId);
        update_chain_assert(
            $hops > 1
                && $completed['status'] === 'completed'
                && \Gallery\Services\application_update_active_job() === null
                && !\Gallery\Services\application_update_background_chain_pending(
                    $jobId,
                ),
            'One visitor did not complete multiple real worker slices and release ownership.',
        );

        // Restart only a disposable cleanup job; active installation files never change.
        \Gallery\Services\application_update_save_job($job);
        \Gallery\Services\application_update_set_active_job($jobId);
        unlink($path);
        \Gallery\Services\application_update_background_chain_start($deliver);
        $expired = array_shift($queue);
        $record = \Gallery\Services\application_update_read_json($path);
        $record['queued_until'] = time() - 1;
        $record['last_dispatch_at'] =
            time() - \Gallery\Services\APPLICATION_UPDATE_CHAIN_LEASE_SECONDS;
        \Gallery\Services\application_update_write_json_atomic($path, $record);
        update_chain_assert(
            !\Gallery\Services\application_update_background_chain_run(
                $expired,
                $deliver,
            )['ok'],
            'An expired handoff remained usable.',
        );
        update_chain_assert(
            \Gallery\Services\application_update_background_chain_start(
                $deliver,
            )['queued'],
            'A later visitor did not recover a dead worker lease.',
        );
        $workerLock = \Gallery\Services\application_update_acquire_lock(
            \Gallery\Services\application_update_job_dir($jobId)
                . '/worker.lock',
        );
        $busy = \Gallery\Services\application_update_background_chain_run(
            array_shift($queue),
            $deliver,
        );
        \Gallery\Services\application_update_release_lock($workerLock);
        update_chain_assert(
            !$busy['queued'] && $busy['reason'] === 'update_chain_stopped',
            'An occupied worker caused a rapid succession of empty HTTP hops.',
        );

        unlink($path);
        $failedDeliver = static fn(string $ticket): bool => false;
        update_chain_assert(
            !\Gallery\Services\application_update_background_chain_start(
                $failedDeliver,
            )['ok'],
            'A failed dispatch was advertised as a successful background worker.',
        );
        $record = \Gallery\Services\application_update_read_json($path);
        update_chain_assert(
            $record['ticket_hash'] === ''
                && $record['queued_until'] === 0
                && $record['last_error']
                    === 'update_chain_dispatch_unavailable',
            'Unavailable transport did not leave recoverable, redacted evidence.',
        );
        update_chain_assert(
            \Gallery\Services\application_update_background_chain_start(
                $deliver,
            )['reason'] === 'update_chain_throttled',
            'Failed handoffs were retried on every visit.',
        );
        unlink($path);
        $job['trigger'] = 'admin';
        \Gallery\Services\application_update_save_job($job);
        update_chain_assert(
            !\Gallery\Services\application_update_background_chain_start(
                $deliver,
            )['queued'],
            'A visitor chained a manual Admin job.',
        );
        $job['trigger'] = 'background';
        $job['operation'] = 'beta_install';
        \Gallery\Services\application_update_save_job($job);
        update_chain_assert(
            !\Gallery\Services\application_update_background_chain_start(
                $deliver,
            )['queued'],
            'A visitor chained a pinned beta operation.',
        );
        $job['operation'] = 'stable_update';
        \Gallery\Services\application_update_save_job($job);
        \Gallery\Services\application_update_background_chain_start($deliver);
        $GLOBALS['update_chain_fixture_disabled'] = true;
        $off = \Gallery\Services\application_update_background_chain_run(
            array_shift($queue),
            $deliver,
        );
        update_chain_assert(
            !$off['queued']
                && \Gallery\Services\application_update_load_job(
                    $jobId,
                )['attempts'] === 0,
            'A valid ticket ignored installer capability OFF.',
        );
        echo
            "Visitor-started updater chain, real bounded slices and handoff safety: PASS\n";
    } finally {
        // The canonical bounded remover receives only this random owned root.
        while (is_dir($root)) {
            \Gallery\Services\application_update_delete_tree_slice($root);
        }
    }
}
