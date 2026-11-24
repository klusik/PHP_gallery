<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/activation_maintenance_response_test.php
 * Module Type: Regression Test
 * Purpose: Verify HTTP availability during interrupted in-place updates and ZIP overlays.
 * Responsibilities:
 *   - Run the actual public entry point and installer against an isolated tree
 *   - Terminate a worker between real file replacements and prove forward replay
 *   - Keep legacy markers, lock files and media outside release ownership
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Describe the fixture's empty set of pending migrations.
     *
     * @param int $maximumFiles Production migration slice limit.
     * @return array{ran:list<string>,remaining:int} Zero migration files in this site.
     * @throws \RuntimeException When the test simulates an interrupted migration.
     */
    function run_migrations_bounded(int $maximumFiles): array
    {
        if (!empty($GLOBALS['online_update_interrupt_migration'])) {
            throw new \RuntimeException('Fixture migration interrupted.');
        }
        return ['ran' => [], 'remaining' => 0];
    }
}

namespace Gallery\Services {
    /**
     * Provide the known schema observation for this fixture's existing installation.
     *
     * @return array{state:string} Available schema without a database in this fixture.
     */
    function application_update_activation_schema_status(): array
    {
        return ['state' => 'available'];
    }

    /**
     * Require a known schema result before allowing fixture application writes.
     *
     * @param array{state:string} $status Schema observation.
     * @param string $operation Update operation.
     * @param string $message Safe failure to expose when verification fails.
     * @return void Allows the fixture's known state or throws.
     */
    function mutation_schema_assert_known(array $status, string $operation, string $message): void
    {
        if ($status['state'] !== 'available') {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Accept deletion of update metadata in the fixture without a settings store.
     *
     * @param list<string> $keys Settings named by the production worker.
     * @return void Discards fixture settings.
     */
    function delete_app_settings(array $keys): void
    {
    }

    /**
     * Supply fallback settings for original-file backup metadata.
     *
     * @param string $key Setting named by the production worker.
     * @param string $default Default supplied by the worker.
     * @return string The default for this disposable installation.
     */
    function app_setting(string $key, string $default = ''): string
    {
        return $default;
    }

    /**
     * Replace a file using native rename, then hold the interruptible worker.
     *
     * @param string $from Temporary file produced by the production owner.
     * @param string $to Owned destination in the test installation.
     * @return bool The actual rename result; the interrupted worker waits after its first file.
     */
    function rename(string $from, string $to): bool
    {
        $result = \rename($from, $to);
        $root = $GLOBALS['online_update_interrupt_root'] ?? null;
        if ($result && is_string($root) && basename($to) === 'gallery-label.php') {
            file_put_contents($root . '/replacement-observed', 'first file renamed');
            $barrier = fopen($root . '/interrupt.lock', 'rb');
            if (is_resource($barrier)) {
                flock($barrier, LOCK_EX);
                fclose($barrier);
            }
        }
        return $result;
    }
}

namespace {
    /**
     * Bound startup, request and worker observation in the private installation.
     * Type: int. Units: seconds. Scope: this HTTP fixture only.
     * Consumers: server polling, wire requests and interruption watchdog.
     * Rationale: ten seconds accommodates process startup and exposes a hang.
     */
    const ONLINE_UPDATE_TEST_TIMEOUT_SECONDS = 10;

    /**
     * Pace observation while waiting for another PHP process.
     * Type: int. Units: microseconds. Scope: fixture polling only.
     * Consumers: readiness and replacement observation.
     * Rationale: twenty milliseconds avoids busy polling.
     */
    const ONLINE_UPDATE_TEST_POLL_US = 20_000;

    /**
     * Require a fixture-authored HTTP or durable state outcome.
     *
     * @param bool $condition Expected observation.
     * @param string $message Failure explanation.
     * @return void Throws if the condition fails.
     */
    function online_update_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Send one real HTTP request to the owned server.
     *
     * @param string $url Absolute loopback fixture URL.
     * @param string $method HTTP verb, including HEAD.
     * @return array{body:string,headers:list<string>} Actual body and lowercase headers.
     */
    function online_update_http(string $url, string $method = 'GET'): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => ONLINE_UPDATE_TEST_TIMEOUT_SECONDS,
        ]]);
        $body = file_get_contents($url, false, $context);
        clearstatcache();
        return [
            'body' => (string) $body,
            'headers' => array_map('strtolower', $http_response_header ?? []),
        ];
    }

    /**
     * Require the actual front controller to serve navigation, JSON and media.
     *
     * @param string $base Absolute loopback fixture origin.
     * @param string $title Expected first PHP file output.
     * @param string $summary Expected second PHP file output.
     * @param string $photo Original GIF bytes owned by the installation.
     * @return void Throws on any transport or response mismatch.
     */
    function online_update_assert_site(string $base, string $title, string $summary, string $photo): void
    {
        foreach ([
            ['/', 'GET', '<main><h1>' . $title . '</h1><p>' . $summary . '</p></main>'],
            ['/api', 'GET', json_encode(['title' => $title, 'description' => $summary], JSON_THROW_ON_ERROR)],
            ['/photo', 'GET', $photo],
            ['/', 'HEAD', ''],
        ] as [$path, $method, $expected]) {
            $response = online_update_http($base . $path, $method);
            online_update_assert(
                str_contains($response['headers'][0] ?? '', ' 200 ')
                    && $response['body'] === $expected
                    && !str_contains(implode("\n", $response['headers']), 'retry-after:'),
                'Normal HTTP response failed for ' . $method . ' ' . $path
                    . ' at ' . $title . '/' . $summary . ': '
                    . ($response['headers'][0] ?? 'missing HTTP status')
                    . ' body=' . json_encode($response['body'], JSON_INVALID_UTF8_SUBSTITUTE),
            );
        }
    }

    require_once __DIR__ . '/../app/services/updates.php';
    if (($argv[1] ?? '') === '--interrupt-worker') {
        $root = $argv[2] ?? '';
        online_update_assert($root !== '' && is_file($root . '/owned-fixture'),
            'Worker is outside its disposable installation.');
        define('CACHE_LIFECYCLE_TEST_ROOT', $root);
        $GLOBALS['online_update_interrupt_root'] = $root;
        \Gallery\Services\application_update_process_job((string) ($argv[3] ?? ''));
        exit(0);
    }

    $root = sys_get_temp_dir() . '/gallery-online-update-' . bin2hex(random_bytes(8));
    \Gallery\Services\application_update_ensure_dir($root . '/cache/updates');
    \Gallery\Services\application_update_ensure_dir($root . '/galleries');
    file_put_contents($root . '/owned-fixture', 'isolated online update test');
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    ini_set('error_log', $root . '/worker.log');
    $updates = $root . '/cache/updates';
    $markerPath = $root . '/maintenance.lock';
    $legacyPath = $updates . '/activation.json';
    $jobId = '20261121000000-abcdef123456';
    $stuck = \Gallery\Services\application_update_create_job('stable_update', [], 'background');
    $stuck['installed_version_at_start'] = '1.4.2';
    $stuck['status'] = 'failed';
    $stuck['stage'] = 'migrate';
    $stuck['checkpoints'] = [
        'validated_version' => '1.4.3',
        'activation_complete' => true,
        'migrations_started' => true,
    ];
    \Gallery\Services\application_update_save_job($stuck);
    \Gallery\Services\application_update_set_active_job($stuck['id']);
    $oldJobPath = \Gallery\Services\application_update_job_state_path($stuck['id']);
    $oldJobBytes = file_get_contents($oldJobPath);
    $marker = json_encode(['job_id' => $stuck['id'], 'recovery_isolation' => true], JSON_THROW_ON_ERROR);
    file_put_contents($markerPath, $marker);
    file_put_contents($legacyPath, $marker);
    file_put_contents($root . '/config.php', '<?php /* local installation */');
    // A transparent single-pixel GIF is sufficient for byte-for-byte media readback.
    $photo = (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
    file_put_contents($root . '/galleries/photo.gif', $photo);
    online_update_assert(
        !\Gallery\Core\release_file_policy_is_production_path('maintenance.lock')
            && !\Gallery\Core\release_file_policy_is_updater_path('maintenance.lock')
            && \Gallery\Core\release_file_policy_is_protected_path('maintenance.lock')
            && !\Gallery\Core\release_file_policy_is_integrity_path('maintenance.lock'),
        'The legacy installation marker entered package or updater ownership.',
    );

    $server = null;
    $worker = null;
    $barrier = null;
    $legacyLease = null;
    try {
        // Unpack the real entry point and early runtime over existing local
        // state, using the same overwrite operation as an administrator.
        $repository = dirname(__DIR__);
        $archive = new ZipArchive();
        $zip = $root . '/overlay.zip';
        online_update_assert($archive->open($zip, ZipArchive::CREATE) === true,
            'Could not create the disposable ZIP overlay.');
        foreach (['index.php', 'public/index.php', 'app/early_runtime.php',
            'app/diagnostics/admin_test_run_early.php'] as $relative) {
            online_update_assert($archive->addFile($repository . '/' . $relative, $relative),
                'Could not package the real runtime file ' . $relative);
        }
        online_update_assert($archive->addFile(__DIR__ . '/fixtures/early_runtime_probe.php', 'app/bootstrap.php')
            && $archive->addFromString('app/gallery-label.php', "<?php return 'old title';")
            && $archive->addFromString('app/gallery-summary.php', "<?php return 'old summary';")
            && $archive->close(), 'Could not build the disposable site package.');
        online_update_assert($archive->open($zip) === true && $archive->extractTo($root)
            && $archive->close(), 'Could not apply the overwrite-only ZIP.');

        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        online_update_assert(is_resource($reservation), 'Could not reserve a disposable HTTP port.');
        $address = stream_socket_get_name($reservation, false);
        fclose($reservation);
        $base = 'http://' . $address;
        // Observe physical renames directly in the HTTP process. php -S uses
        // cli-server, where the CLI-only OPcache setting does not apply.
        $server = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-S', $address, $root . '/index.php'], [
            0 => ['pipe', 'r'],
            1 => ['file', $root . '/server.log', 'a'],
            2 => ['file', $root . '/server.log', 'a'],
        ], $serverPipes, $root);
        online_update_assert(is_resource($server), 'Could not start the disposable HTTP server.');
        fclose($serverPipes[0]);
        $deadline = microtime(true) + ONLINE_UPDATE_TEST_TIMEOUT_SECONDS;
        $ready = false;
        do {
            $connection = @stream_socket_client('tcp://' . $address);
            if (is_resource($connection)) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(ONLINE_UPDATE_TEST_POLL_US);
        } while (microtime(true) < $deadline);
        online_update_assert($ready, 'Disposable HTTP server did not start: '
            . (string) @file_get_contents($root . '/server.log'));

        $legacyLease = fopen($updates . '/runtime-use.lock', 'c+b');
        online_update_assert(is_resource($legacyLease) && flock($legacyLease, LOCK_EX | LOCK_NB),
            'Could not hold the old runtime lock.');
        online_update_assert_site($base, 'old title', 'old summary', $photo);
        online_update_assert(!is_file($markerPath) && file_get_contents($oldJobPath) === $oldJobBytes
            && file_get_contents($legacyPath) === $marker,
            'An overlay with an interrupted migration must render and retire the old public marker.');
        file_put_contents($markerPath, '');
        file_put_contents($legacyPath, '{interrupted');
        online_update_assert_site($base, 'old title', 'old summary', $photo);
        file_put_contents($markerPath, '{interrupted');
        online_update_assert_site($base, 'old title', 'old summary', $photo);
        online_update_assert(
            !is_file($markerPath) && file_get_contents($legacyPath) === '{interrupted'
                && file_get_contents($root . '/config.php') === '<?php /* local installation */'
                && file_get_contents($oldJobPath) === $oldJobBytes,
            'The ZIP overlay required or mutated installation-owned state.',
        );
        $retired = \Gallery\Services\application_update_continue_background_job();
        online_update_assert($retired['id'] === $stuck['id'] && $retired['status'] === 'cancelled'
            && \Gallery\Services\application_update_active_job() === null && is_file($oldJobPath),
            'The old migration resumed over the newer ZIP or lost its evidence.');

        $job = \Gallery\Services\application_update_create_job('stable_update', [], 'background', $jobId);
        $job['stage'] = 'activate';
        $job['recovery_override'] = true;
        $jobDir = \Gallery\Services\application_update_job_dir($jobId);
        $files = [
            'app/gallery-label.php' => "<?php return 'new title';",
            'app/gallery-summary.php' => "<?php return 'new summary';",
        ];
        \Gallery\Services\application_update_ensure_dir($jobDir . '/ready/app');
        foreach ($files as $path => $bytes) {
            file_put_contents($jobDir . '/ready/' . $path, $bytes);
            $job['checkpoints']['recovery_hashes'][$path] = \Gallery\Services\application_update_recovery_raw_hash(
                $jobDir . '/ready/' . $path,
            );
        }
        $job['checkpoints']['source_root'] = $jobDir . '/extract';
        $job['checkpoints']['activation_files'] = array_keys($files);
        $job['checkpoints']['backup_items'] = array_keys($files);
        $job['checkpoints']['recovery_ready_verified'] = true;
        online_update_assert(\Gallery\Services\application_update_job_backup_slice(
            $job,
            \Gallery\Services\application_update_time_budget(),
        ), 'Could not persist the real original-file backup.');
        \Gallery\Services\application_update_set_active_job($jobId);
        $barrier = fopen($root . '/interrupt.lock', 'c+b');
        online_update_assert(is_resource($barrier) && flock($barrier, LOCK_EX | LOCK_NB),
            'Could not establish the test interruption barrier.');
        $worker = proc_open([PHP_BINARY, __FILE__, '--interrupt-worker', $root, $jobId], [
            0 => ['pipe', 'r'],
            1 => ['file', $root . '/interrupt.log', 'a'],
            2 => ['file', $root . '/interrupt.log', 'a'],
        ], $workerPipes, $root);
        online_update_assert(is_resource($worker), 'Could not start the isolated activation worker.');
        fclose($workerPipes[0]);
        $deadline = microtime(true) + ONLINE_UPDATE_TEST_TIMEOUT_SECONDS;
        do {
            clearstatcache();
            if (is_file($root . '/replacement-observed')) {
                break;
            }
            usleep(ONLINE_UPDATE_TEST_POLL_US);
        } while (microtime(true) < $deadline && proc_get_status($worker)['running']);
        online_update_assert(
            is_file($root . '/replacement-observed') && proc_get_status($worker)['running']
                && !empty(\Gallery\Services\application_update_load_job($jobId)['slice_open']),
            'Worker did not hold after its first real rename: ' . file_get_contents($root . '/interrupt.log'),
        );
        online_update_assert(
            file_get_contents($root . '/app/gallery-label.php') === $files['app/gallery-label.php']
                && file_get_contents($root . '/app/gallery-summary.php') === "<?php return 'old summary';",
            'The worker did not expose the expected final bytes of exactly one replacement.',
        );
        online_update_assert_site($base, 'new title', 'old summary', $photo);
        $busy = \Gallery\Services\application_update_process_job($jobId);
        online_update_assert($busy['attempts'] === 1 && $busy['stage'] === 'activate',
            'A second worker advanced the active installer.');
        online_update_assert(proc_terminate($worker), 'Could not interrupt the first worker.');
        $exit = proc_close($worker);
        $worker = null;
        online_update_assert($exit !== 0, 'The interrupted worker exited as completed.');
        online_update_assert_site($base, 'new title', 'old summary', $photo);

        $stopped = \Gallery\Services\application_update_process_job($jobId);
        online_update_assert($stopped['status'] === 'failed' && $stopped['can_resume']
            && !$stopped['can_rollback'], 'Killed activation did not become a forward-retry job.');
        $unfinished = \Gallery\Services\application_update_load_job($jobId);
        $refused = false;
        try {
            \Gallery\Services\application_update_runtime_commit($unfinished);
        } catch (RuntimeException) {
            $refused = true;
        }
        online_update_assert($refused, 'Partial activation was marked as completed.');
        $retry = \Gallery\Services\application_update_retry_job($jobId);
        online_update_assert($retry['status'] === 'running' && $retry['id'] === $jobId,
            'Retry did not keep the same prepared job.');
        $GLOBALS['online_update_interrupt_migration'] = true;
        $migrationFailure = \Gallery\Services\application_update_process_job($jobId);
        unset($GLOBALS['online_update_interrupt_migration']);
        $storedMigration = \Gallery\Services\application_update_load_job($jobId);
        online_update_assert($migrationFailure['status'] === 'failed'
            && $migrationFailure['stage'] === 'migrate' && $migrationFailure['can_resume']
            && !empty($storedMigration['checkpoints']['migrations_started'])
            && empty($storedMigration['checkpoints']['migrations_complete']),
            'The simulated migration did not stop at the production checkpoint.');
        online_update_assert_site($base, 'new title', 'new summary', $photo);
        online_update_assert(!is_file($markerPath), 'A failed migration created a public marker.');
        \Gallery\Services\application_update_retry_job($jobId);
        $finished = \Gallery\Services\application_update_process_job($jobId);
        online_update_assert($finished['status'] === 'completed'
            && $finished['result']['files_copied'] === count($files)
            && !$finished['runtime_waiting']
            && \Gallery\Services\application_update_active_job() === null,
            'The original activation did not complete through normal replay: '
                . json_encode($finished['error'], JSON_THROW_ON_ERROR));
        online_update_assert_site($base, 'new title', 'new summary', $photo);
        online_update_assert(!is_file($markerPath)
            && file_get_contents($legacyPath) === '{interrupted'
            && file_get_contents($jobDir . '/rollback/original/app/gallery-label.php') === "<?php return 'old title';",
            'Online activation rewrote old markers or lost its original bytes.');
    } finally {
        foreach ([$worker, $server] as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        foreach ([$barrier, $legacyLease] as $lock) {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
        if (is_file($root . '/owned-fixture')) {
            \Gallery\Services\application_update_remove_path($root);
        }
    }
    echo "PASS: overwrite-only ZIP and real HTTP during interrupted in-place activation\n";
}
