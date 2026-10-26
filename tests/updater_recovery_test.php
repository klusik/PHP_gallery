<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/updater_recovery_test.php
 * Module Type: Updater Integration Test
 * Purpose: Execute recovery with real jobs, locks, file activation and migrations.
 * Responsibilities: Confine interrupted/failing jobs and database evidence to
 *   owned temporary resources while testing adverse recovery admission.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return the explicit disposable database. @return \PDO Isolated connection. */
    function db(): \PDO { return $GLOBALS['recovery_pdo']; }
    /** Return the synthetic installed version. @return string Fixture version. */
    function cms_current_version(): string { return '1.0.0'; }
    /** Return migration audit time. @return string UTC SQL timestamp. */
    function now_sql(): string { return gmdate('Y-m-d H:i:s'); }
    /** Trusted fixture source identity; no network is performed. */
    const CMS_GITHUB_REPOSITORY = 'fixture/gallery';
    /** Only the fixture stable branch may be selected. */
    const CMS_UPDATE_BRANCHES = ['main'];
}

namespace Gallery\Services {
    /** Resolve isolated safe text. @param string $key Catalog key. @param string|array<string,string>|null $fallback Text or replacements. @param array<string,string> $parameters Replacements. @return string Fixture text. */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    { return is_string($fallback) ? $fallback : $key; }
    /** Report no provider wait. @return array{active:bool} Explicit fixture admission. */
    function cms_github_api_wait_state(): array { return ['active' => false]; }
    /** Serve confined stable discovery and commit responses without HTTP.
     * @param string $url Production allowlisted endpoint.
     * @param int $timeoutSeconds Bounded caller deadline.
     * @param list<string> $headers Requested provider media types.
     * @param bool $strict Whether transport errors should throw.
     * @return array{body:string,status:int,headers:array<string,string>} Synthetic provider response.
     */
    function cms_github_api_get(string $url, int $timeoutSeconds, array $headers = [], bool $strict = false): array
    {
        $GLOBALS['recovery_remote_calls'] = (int) ($GLOBALS['recovery_remote_calls'] ?? 0) + 1;
        $version = (string) ($GLOBALS['recovery_remote_version'] ?? '1.2.0');
        $body = str_contains($url, '/commits/')
            ? json_encode(['sha' => $GLOBALS['recovery_remote_commit'] ?? str_repeat('a', 40)], JSON_THROW_ON_ERROR)
            : (str_contains($url, 'PATCH_NOTES.md') ? '## ' . $version
                : "<?php const CMS_VERSION = '" . $version . "';");
        return ['body' => $body, 'status' => 200, 'headers' => []];
    }
    /** Read isolated updater preferences. @param string $key Setting name. @param string $default Missing fallback. @return string Stored value. */
    function app_setting(string $key, string $default = ''): string
    { return (string) ($GLOBALS['recovery_settings'][$key] ?? $default); }
    /** Store isolated updater preferences. @param string $key Name. @param string $value Value. @return void Saves the fixture setting. */
    function set_app_setting(string $key, string $value): void
    { $GLOBALS['recovery_settings'][$key] = $value; }
    /** Remove isolated preferences. @param list<string> $keys Names. @return void Deletes fixture values. */
    function delete_app_settings(array $keys): void
    { foreach ($keys as $key) { unset($GLOBALS['recovery_settings'][$key]); } }
    /** Inspect fixture installer ownership. @param string $key Capability. @return bool Fixture enablement. */
    function feature_capability_effective_enabled(string $key): bool
    { return $key === 'built_in_update_installer' && empty($GLOBALS['recovery_disabled']); }
    /** Observe explicit schema state without caching. @return array{state:string} Fixture readiness. */
    function application_update_activation_schema_status(): array
    { return ['state' => $GLOBALS['recovery_schema_state'] ?? 'available']; }
    /** Assert known schema for the actual activation gate. @param array{state:string} $status Observation. @param string $operation Operation. @param string $message Safe refusal. @return void Throws for unknown evidence. */
    function mutation_schema_assert_known(array $status, string $operation, string $message): void
    { if ($status['state'] === 'unknown') { throw new \RuntimeException($message); } }
    /** Capture safe updater logs without external persistence. @param string $level Severity. @param string $event Name. @param string $message Safe message. @param array<string,scalar|list<string>|null> $context Context. @param array<string,string> $options Log options. @return void Retains isolated structured evidence. */
    function admin_log_event(string $level, string $event, string $message, array $context = [], array $options = []): void
    { $GLOBALS['recovery_log_events'][] = ['event' => $event, 'context' => $context]; }
    /** Capture the real worker diagnostic transport without external persistence.
     * @param string $level Event severity.
     * @param string $event Owner event identity.
     * @param string $message Safe explanation.
     * @param array<string,scalar|null> $context Safe structured worker evidence.
     * @param array<string,string> $options Log category/severity.
     * @return array{ok:bool} Controlled insertion receipt.
     */
    function admin_log_event_result(string $level, string $event, string $message, array $context = [], array $options = []): array
    {
        $GLOBALS['recovery_log_events'][] = ['event' => $event, 'context' => $context];
        return ['ok' => empty($GLOBALS['recovery_log_unavailable'])];
    }
    /** Simulate unavailable hosting logging without losing ordinary fallback tests.
     * @param string $message Safe worker diagnostic payload.
     * @return bool Native delivery result; throws for the disabled-host vector.
     */
    function error_log(string $message): bool
    {
        if (!empty($GLOBALS['recovery_php_log_disabled'])) {
            throw new \RuntimeException('Hosting PHP logging is disabled.');
        }
        return \error_log($message);
    }
}

namespace {
    require_once __DIR__ . '/support/gallery_workflow_safety.php';

    /**
     * Match the shared fixture's ownership identity.
     * Type: int. Units: random bytes. Scope: this private installation.
     * Consumers: directory/database naming and verified cleanup.
     * Rationale: twelve bytes produce the required 24 hexadecimal characters.
     */
    const RECOVERY_FIXTURE_TOKEN_BYTES = 12;
    /**
     * Restrict temporary fixture directories to the executing account.
     * Type: int. Units: POSIX mode bits. Scope: owned fixture directories.
     * Consumers: mkdir. Rationale: 0700 keeps isolated update evidence private.
     */
    const RECOVERY_FIXTURE_DIRECTORY_MODE = 0700;
    /**
     * Bound the independent worker watchdog without affecting production budgets.
     * Type: int. Units: seconds. Scope: one confined contention child.
     * Consumers: recovery_worker_probe.
     * Rationale: ten seconds exceeds a lock-busy read while detecting deadlock.
     */
    const RECOVERY_FIXTURE_CHILD_SECONDS = 10;
    /**
     * Yield while observing the child process without busy polling.
     * Type: int. Units: microseconds. Scope: the contention watchdog only.
     * Consumers: recovery_worker_probe.
     * Rationale: 20000 microseconds keep the fixture responsive with little CPU.
     */
    const RECOVERY_FIXTURE_POLL_US = 20000;

    /**
     * Bound complete rollback execution in this disposable integration fixture.
     * Type: int. Units: worker slices. Scope: recovery_drain watchdog.
     * Consumers: actual rollback state-machine loop.
     * Rationale: 40 exceeds thirteen stages and the twenty-slice cleanup cap,
     * while detecting a deadlock without weakening production worker budgets.
     */
    const RECOVERY_FIXTURE_DRAIN_SLICES = 40;

    if (in_array($argv[1] ?? '', ['--worker-probe', '--admission-probe'], true)) {
        $childRoot = \GalleryWorkflow\validateFixture((string) ($argv[2] ?? ''),
            (string) ($argv[3] ?? ''));
        define('CACHE_LIFECYCLE_TEST_ROOT', $childRoot);
        if ($argv[1] === '--admission-probe') {
            require_once dirname(__DIR__) . '/app/early_runtime.php';
            ob_start();
            register_shutdown_function(static function (): void {
                $body = (string) ob_get_clean();
                echo json_encode(['status' => (string) http_response_code(),
                    'body' => $body,
                    'runtime_lease_held' => isset($GLOBALS['gallery_updater_runtime_lease'])],
                    JSON_THROW_ON_ERROR);
            });
            \Gallery\EarlyRuntime\updater_runtime_enter($childRoot);
            echo 'Ordinary request admitted.';
            exit(0);
        }
        require_once dirname(__DIR__) . '/app/services/updates.php';
        echo json_encode(\Gallery\Services\application_update_process_job(
            (string) ($argv[4] ?? ''),
        ), JSON_THROW_ON_ERROR);
        exit(0);
    }

    /** Adapt only MySQL schema syntax/metadata on the portable SQLite owner. */
    final class UpdaterRecoveryPdo extends \PDO
    {
        /** Execute real SQL with SQLite's equivalent ledger DDL.
         * @param string $statement Production or fixture SQL.
         * @return int|false Affected rows or native failure.
         */
        public function exec(string $statement): int|false
        {
            if ($this->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $statement = (string) preg_replace('/\) ENGINE=InnoDB.*$/s', ')', $statement);
            }
            return parent::exec($statement);
        }
        /** Execute metadata inventory with SQLite's actual schema equivalent.
         * @param string $query Production prepared SQL.
         * @param array{} $options Empty native options.
         * @return \PDOStatement|false Real statement or native failure.
         */
        public function prepare(string $query, array $options = []): \PDOStatement|false
        {
            if ($this->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                && str_contains($query, 'information_schema.COLUMNS')
                && str_contains($query, 'COLUMN_NAME = ?')) {
                // Observe the actual SQLite column; do not stub schema readiness.
                return parent::prepare('SELECT 1 FROM pragma_table_info(?) WHERE name = ? LIMIT 1');
            }
            if ($this->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                && str_contains($query, 'information_schema.')) {
                $query = "SELECT name,sql FROM sqlite_master WHERE type='table' ORDER BY name LIMIT ?";
            }
            return parent::prepare($query, $options);
        }
    }

    /** Require an actual persisted or physical postcondition.
     * @param bool $ok Required observation. @param string $message Failure label.
     * @return void Throws on a missing observation.
     */
    function recovery_check(bool $ok, string $message): void
    { if (!$ok) { throw new \RuntimeException($message); } }

    /** Require a safety refusal from an actual owner.
     * @param callable():void $action Unsafe candidate operation.
     * @param string $message Missing-refusal explanation.
     * @return void Verifies a thrown RuntimeException.
     */
    function recovery_refuses(callable $action, string $message): void
    {
        $refused = false;
        try { $action(); } catch (\RuntimeException) { $refused = true; }
        recovery_check($refused, $message);
    }

    /**
     * Invoke the actual job worker from an independent confined PHP process.
     * @param string $root Verified disposable installation root.
     * @param string $token Matching fixture ownership token.
     * @param string $jobId Job whose kernel worker lock is held by the parent.
     * @param bool $probeAdmission Whether to probe ordinary runtime admission instead of processing a worker.
     * @return array{status:string,body?:string,runtime_lease_held?:bool,...<string,mixed>} Public worker state or isolated runtime response.
     */
    function recovery_worker_probe(string $root, string $token, string $jobId, bool $probeAdmission = false): array
    {
        $process = proc_open([PHP_BINARY, __FILE__,
            $probeAdmission ? '--admission-probe' : '--worker-probe', $root, $token, $jobId],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        recovery_check(is_resource($process), 'Cannot start confined worker.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + RECOVERY_FIXTURE_CHILD_SECONDS;
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $state = proc_get_status($process);
            if (!$state['running']) { break; }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                break;
            }
            usleep(RECOVERY_FIXTURE_POLL_US);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        $exit = !$state['running'] && $state['exitcode'] >= 0 ? $state['exitcode'] : $closed;
        recovery_check($exit === 0 && $stderr === '', 'Independent worker failed or exceeded its deadline.');
        return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Make an isolated failed job through the production creation owner.
     * @param string $stage Persisted interruption boundary.
     * @param string $trigger Explicit job ownership.
     * @param string $operation Stable, beta, restore or clean-reinstall operation.
     * @return array<string,mixed> Private failed source.
     */
    function recovery_source(string $stage = 'package_validate', string $trigger = 'background', string $operation = 'stable_update'): array
    {
        $parameters = $operation === 'beta_install' ? ['commit' => str_repeat('b', 40)]
            : ['branch' => 'main', 'target_version' => '1.1.0'];
        $job = \Gallery\Services\application_update_create_job($operation, $parameters, $trigger);
        $job['stage'] = $stage;
        $job['status'] = 'failed';
        $job['error'] = ['reference' => 'FIXTURE', 'message' => 'Stopped.',
            'retryable' => true, 'at' => time()];
        \Gallery\Services\application_update_save_job($job);
        \Gallery\Services\application_update_set_active_job($job['id']);
        return $job;
    }

    /** Build a complete small recovery manifest/inventory using authored bytes.
     * @param string $root Owned extracted package root.
     * @param string $version Synthetic package version.
     * @param string $payload Synthetic managed application content.
     * @return void Writes synthetic package metadata and actual hashes.
     */
    function recovery_package(string $root, string $version = '1.2.0', string $payload = 'verified replacement'): void
    {
        \Gallery\Services\application_update_ensure_dir($root . '/app');
        \Gallery\Services\application_update_ensure_dir($root . '/public/assets');
        file_put_contents($root . '/app/bootstrap.php', "<?php const CMS_VERSION = '" . $version . "';");
        file_put_contents($root . '/app/payload.php', '<?php /* ' . $payload . ' */');
        // Binary bytes deliberately include CRLF so normalized-text hashing
        // cannot be mistaken for exact-byte integrity of resources/snapshots.
        file_put_contents($root . '/public/assets/recovery-proof.bin', "\x00\r\n\xffresource");
        $files = ['app/bootstrap.php', 'app/core-manifest.json', 'app/payload.php',
            'app/production-files.json', 'public/assets/recovery-proof.bin'];
        file_put_contents($root . '/app/production-files.json', json_encode([
            'schema_version' => 1, 'production_files' => $files,
            'updater_files' => $files, 'source_review_files' => [],
        ], JSON_THROW_ON_ERROR));
        $hashes = [];
        foreach (['app/bootstrap.php', 'app/payload.php', 'app/production-files.json'] as $relative) {
            $hashes[$relative] = \Gallery\Services\application_update_manifest_hash($root . '/' . $relative);
        }
        file_put_contents($root . '/app/core-manifest.json', json_encode([
            'version' => $version, 'algorithm' => 'sha256',
            'hash_mode' => 'normalized-text-sha256', 'files' => $hashes,
        ], JSON_THROW_ON_ERROR));
    }

    /** Make a caught failure old enough after all bounded retries.
     * @param array<string,mixed> $job Original durable source.
     * @return array<string,mixed> Persisted mature failed source.
     */
    function recovery_mature(array $job): array
    {
        $job['auto_retries'] = \Gallery\Services\APPLICATION_UPDATE_AUTO_RETRY_LIMIT;
        $job['error']['at'] = time() - \Gallery\Services\APPLICATION_UPDATE_RECOVERY_TIMEOUT_SECONDS - 1;
        $job['result']['first_failure'] = $job['error'];
        $job['failure_first_at'] = $job['error']['at'];
        $job['recovery_not_before'] = $job['error']['at']
            + \Gallery\Services\APPLICATION_UPDATE_RECOVERY_TIMEOUT_SECONDS;
        \Gallery\Services\application_update_save_job($job);
        return $job;
    }

    /** Prepare actual backup bytes before simulating failed activation.
     * @param string $root Owned installation root.
     * @param string $trigger Original API/Admin/background launch mode.
     * @param string $operation Original stable/beta/restore/reinstall operation.
     * @param bool $partial Whether the original activation gate remains active.
     * @return array<string,mixed> Mature source with complete production backup metadata.
     */
    function recovery_activated_source(string $root, string $trigger, string $operation, bool $partial = false): array
    {
        recovery_package($root, '1.0.0', 'before update');
        if (is_file($root . '/app/new_only.php')) { unlink($root . '/app/new_only.php'); }
        file_put_contents($root . '/app/old_only.php', 'previous obsolete file');
        $source = recovery_source('backup', $trigger, $operation);
        @unlink(\Gallery\Services\application_update_jobs_root() . '/rollback-window.json');
        @unlink(\Gallery\Services\application_update_activation_gate_path());
        $source['checkpoints']['activation_files'] = [
            'app/bootstrap.php', 'app/core-manifest.json', 'app/payload.php',
            'app/production-files.json', 'app/new_only.php',
        ];
        $source['checkpoints']['obsolete_paths'] = ['app/old_only.php'];
        $source['checkpoints']['backup_items'] = \Gallery\Services\application_update_backup_items_for_plan(
            $root, $source['checkpoints']['activation_files'], ['app/old_only.php'],
        );
        $source['checkpoints']['database_before_activation'] =
            \Gallery\Services\application_update_recovery_database_evidence($root);
        recovery_check(\Gallery\Services\application_update_job_backup_slice($source,
            \Gallery\Services\application_update_time_budget()), 'Cannot prepare real source backup.');
        $candidate = $root . '/runtime-package';
        recovery_package($candidate, '1.1.0');
        copy(dirname(__DIR__) . '/app/early_runtime.php', $candidate . '/app/early_runtime.php');
        file_put_contents($candidate . '/app/bootstrap.php',
            "\n// updater_runtime_enter( is the package protocol entry.\n", FILE_APPEND);
        $source['checkpoints']['source_root'] = $candidate;
        $source['checkpoints']['validated_version'] = '1.1.0';
        $runtimeLock = \Gallery\Services\application_update_acquire_lock(
            \Gallery\Services\application_update_jobs_root() . '/runtime-use.lock');
        recovery_check($runtimeLock !== null, 'Cannot isolate fixture activation.');
        try { \Gallery\Services\application_update_runtime_window_begin($source); }
        finally { \Gallery\Services\application_update_release_lock($runtimeLock); }
        recovery_package($root, '1.1.0', 'bad active release');
        unlink($root . '/app/old_only.php');
        file_put_contents($root . '/app/new_only.php', 'new release file');
        $source['stage'] = $partial ? 'activate' : 'migrate';
        $source['checkpoints']['activation_complete'] = !$partial;
        $source['checkpoints']['archive_commit'] = str_repeat('b', 40);
        $source = recovery_mature($source);
        if ($partial) { \Gallery\Services\application_update_activation_gate_begin($source); }
        return $source;
    }

    /** Prepare the old producer's activate checkpoint before its first rename.
     * @param string $root Owned installation containing original bytes.
     * @return array<string,mixed> Mature legacy-style source without a DB window.
     */
    function recovery_pristine_source(string $root): array
    {
        @unlink(\Gallery\Services\application_update_activation_gate_path());
        @unlink(\Gallery\Services\application_update_jobs_root() . '/rollback-window.json');
        recovery_package($root, '1.0.0', 'unchanged original');
        @unlink($root . '/app/not_created.php');
        file_put_contents($root . '/app/obsolete.php', 'unchanged obsolete');
        $source = recovery_source('activate', 'admin');
        $source['checkpoints']['activation_files'] = [
            'app/bootstrap.php', 'app/core-manifest.json', 'app/not_created.php',
        ];
        $source['checkpoints']['obsolete_paths'] = ['app/obsolete.php'];
        $source['checkpoints']['backup_items'] = \Gallery\Services\application_update_backup_items_for_plan(
            $root, $source['checkpoints']['activation_files'], $source['checkpoints']['obsolete_paths'],
        );
        recovery_check(\Gallery\Services\application_update_job_backup_slice($source,
            \Gallery\Services\application_update_time_budget()), 'Cannot prepare untouched activation backup.');
        return recovery_mature($source);
    }

    /** Run the actual bounded rollback engine to its terminal result.
     * @param string $jobId Active rollback identity.
     * @return array<string,mixed> Actual public terminal result.
     */
    function recovery_drain(string $jobId): array
    {
        for ($slice = 0; $slice < RECOVERY_FIXTURE_DRAIN_SLICES; $slice++) {
            $job = \Gallery\Services\application_update_process_job($jobId);
            if (($job['status'] ?? '') !== 'running') { return $job; }
        }
        throw new \RuntimeException('Rollback worker exceeded its bounded fixture watchdog.');
    }

    // Twelve random bytes match the existing fixture helper's ownership format.
    $token = bin2hex(random_bytes(RECOVERY_FIXTURE_TOKEN_BYTES));
    $root = sys_get_temp_dir() . '/gallery-workflow-' . $token;
    mkdir($root, RECOVERY_FIXTURE_DIRECTORY_MODE, true);
    file_put_contents($root . '/.workflow-owner', $token);
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    $GLOBALS['recovery_settings'] = [];
    $options = getenv('GALLERY_WORKFLOW_FIXTURE')
        ? \GalleryWorkflow\databaseOptions(getenv()) : null;
    $server = null;
    $database = 'gallery_workflow_' . $token;
    try {
        if ($options !== null) {
            \GalleryWorkflow\validateDatabaseName($database);
            $server = new \PDO('mysql:host=127.0.0.1;port=' . $options['port'] . ';charset=utf8mb4',
                $options['user'], $options['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
            $pdo = new UpdaterRecoveryPdo('mysql:host=127.0.0.1;port=' . $options['port']
                . ';dbname=' . $database . ';charset=utf8mb4', $options['user'], $options['password']);
        } else {
            $pdo = new UpdaterRecoveryPdo('sqlite::memory:');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $GLOBALS['recovery_pdo'] = $pdo;
        mkdir($root . '/app', RECOVERY_FIXTURE_DIRECTORY_MODE, true);
        mkdir($root . '/database/migrations', RECOVERY_FIXTURE_DIRECTORY_MODE, true);
        foreach (['migrations.php', 'migration_definitions.php'] as $file) {
            copy(dirname(__DIR__) . '/app/' . $file, $root . '/app/' . $file);
        }
        require_once $root . '/app/migrations.php';
        require_once dirname(__DIR__) . '/app/models/schema_inspection.php';
        require_once dirname(__DIR__) . '/app/services/schema_inspection.php';
        require_once dirname(__DIR__) . '/app/services/updates.php';
        $pdo->exec('CREATE TABLE schema_migrations (version VARCHAR(64) PRIMARY KEY,applied_at DATETIME NOT NULL)');
        $localExtension = $root . '/app/local_extension.php';
        file_put_contents($localExtension, '<?php /* unmanaged local extension */');

        $contended = recovery_source('completed');
        $contended['status'] = 'running';
        $contended['error'] = null;
        \Gallery\Services\application_update_save_job($contended);
        $held = \Gallery\Services\application_update_acquire_lock(
            \Gallery\Services\application_update_job_dir($contended['id']) . '/worker.lock');
        try {
            recovery_check(recovery_worker_probe($root, $token, $contended['id'])['status'] === 'running',
                'An independent worker ignored the held kernel lock.');
            recovery_check(\Gallery\Services\application_update_load_job($contended['id'])['attempts'] === 0,
                'A contending worker changed durable progress.');
        } finally { \Gallery\Services\application_update_release_lock($held); }

        $interrupted = recovery_source('package_validate', 'admin');
        $interrupted['status'] = 'running';
        $interrupted['slice_open'] = true;
        $interrupted['slice_recovery_at'] = time() - 1;
        \Gallery\Services\application_update_save_job($interrupted);
        $interruptedResult = \Gallery\Services\application_update_continue_background_job();
        recovery_check($interruptedResult['status'] === 'failed'
            && !\Gallery\Services\application_update_load_job($interrupted['id'])['slice_open']
            && \Gallery\Services\application_update_load_job($interrupted['id'])['auto_retries'] === 0,
            'An interrupted manual slice was replayed indefinitely or rolled back without backoff.');

        foreach (['background', 'api', 'admin'] as $trigger) {
            $before = recovery_source('package_validate', $trigger);
            $artifact = \Gallery\Services\application_update_job_dir($before['id']) . '/package.zip';
            file_put_contents($artifact, 'retain failed package');
            recovery_check(!\Gallery\Services\application_update_failed_recovery_due($before, time()),
                'A fresh failure skipped retry backoff.');
            $before['auto_retries'] = \Gallery\Services\APPLICATION_UPDATE_AUTO_RETRY_LIMIT;
            $before['result']['first_failure'] = ['at' => time()
                - \Gallery\Services\APPLICATION_UPDATE_RECOVERY_TIMEOUT_SECONDS + 1];
            recovery_check(!\Gallery\Services\application_update_failed_recovery_due($before, time()),
                'Rollback started before the ten-minute failure timeout.');
            $before = recovery_mature($before);
            $released = \Gallery\Services\application_update_recover_failed_job($before['id']);
            recovery_check($released['status'] === 'failed' && !empty($released['result']['recovery_released'])
                && \Gallery\Services\application_update_active_job() === null && is_file($artifact),
                'Pre-activation recovery changed files, erased evidence or retained ownership.');
        }

        // Match the incident's old producer: activate, explicit no migrations,
        // complete backup, no DB baseline/window/gate and no activation receipt.
        $pristine = recovery_pristine_source($root);
        $reader = \Gallery\Services\application_update_acquire_lock(
            \Gallery\Services\application_update_jobs_root() . '/runtime-use.lock');
        try {
            recovery_check(\Gallery\Services\application_update_recover_failed_job($pristine['id'])['id'] === $pristine['id']
                && \Gallery\Services\application_update_active_job() !== null,
                'Unchanged activation ignored exclusive runtime contention.');
        } finally { \Gallery\Services\application_update_release_lock($reader); }
        $released = \Gallery\Services\application_update_recover_failed_job($pristine['id']);
        recovery_check(!empty($released['result']['activation_unchanged_verified'])
            && !empty($released['result']['recovery_released'])
            && \Gallery\Services\application_update_active_job() === null
            && file_get_contents($root . '/app/obsolete.php') === 'unchanged obsolete',
            'Untouched activate checkpoint was mistaken for partial activation.');
        recovery_check(\Gallery\Services\application_update_recover_failed_job($pristine['id'])['id'] === $pristine['id'],
            'Repeated unchanged recovery allocated another job.');
        foreach (['changed', 'created', 'backup', 'metadata', 'gate', 'intent', 'migration'] as $kind) {
            $pristine = recovery_pristine_source($root);
            if ($kind === 'changed') { file_put_contents($root . '/app/bootstrap.php', 'partial activation'); }
            elseif ($kind === 'created') { file_put_contents($root . '/app/not_created.php', 'partial activation'); }
            elseif ($kind === 'backup') {
                unlink(\Gallery\Services\application_update_job_dir($pristine['id']) . '/rollback/original/app/bootstrap.php');
            } elseif ($kind === 'metadata') {
                file_put_contents(\Gallery\Services\application_update_job_dir($pristine['id']) . '/rollback/metadata.json', '{}');
            } elseif ($kind === 'gate') { \Gallery\Services\application_update_activation_gate_begin($pristine); }
            elseif ($kind === 'intent') { $pristine['checkpoints']['activation_started'] = true; }
            else { $pristine['checkpoints']['migrations_started'] = true; }
            \Gallery\Services\application_update_save_job($pristine);
            $blocked = \Gallery\Services\application_update_recover_failed_job($pristine['id']);
            recovery_check(empty($blocked['result']['recovery_released'])
                && \Gallery\Services\application_update_active_job()['id'] === $pristine['id'],
                'Unproven activate state was released: ' . $kind);
        }
        @unlink(\Gallery\Services\application_update_activation_gate_path());

        \Gallery\Services\application_update_set_active_job(null);
        \Gallery\EarlyRuntime\updater_runtime_enter($root);
        recovery_check(\Gallery\Services\application_update_acquire_lock(
            \Gallery\Services\application_update_jobs_root() . '/runtime-use.lock') === null,
            'Initial Admin-style bootstrap did not hold its ordinary read lease.');
        // Match the authenticated controller boundary, before the first job.
        \Gallery\EarlyRuntime\updater_runtime_leave();
        \Gallery\EarlyRuntime\updater_runtime_leave();
        $exclusive = \Gallery\Services\application_update_acquire_lock(
            \Gallery\Services\application_update_jobs_root() . '/runtime-use.lock');
        recovery_check($exclusive !== null, 'Updater retained its own request read lease.');
        \Gallery\Services\application_update_release_lock($exclusive);

        $modes = [
            ['background', 'stable_update', false],
            ['api', 'stable_update', false],
            ['admin', 'stable_update', true],
            ['api', 'beta_install', false],
            ['api', 'stable_restore', false],
            ['admin', 'clean_reinstall', false],
        ];
        foreach ($modes as [$trigger, $operation, $partial]) {
            $GLOBALS['recovery_settings'] = [];
            if ($operation === 'stable_restore') {
                $GLOBALS['recovery_settings']['application_update_channel'] = 'beta';
                $GLOBALS['recovery_settings']['application_update_beta_commit'] = str_repeat('c', 40);
            }
            if ($trigger === 'api' && $operation === 'stable_update') {
                $GLOBALS['recovery_settings']['application_autoupdate_enabled'] = '0';
            }
            $source = recovery_activated_source($root, $trigger, $operation, $partial);
            if ($operation === 'clean_reinstall') {
                recovery_check(\Gallery\Services\application_update_job_migrate_slice($source),
                    'Empty actual migration runner did not complete.');
                recovery_check(($source['result']['migrations'] ?? null) === [],
                    'Empty migration runner invented migration effects.');
            }
            $artifact = \Gallery\Services\application_update_job_dir($source['id']) . '/package.zip';
            file_put_contents($artifact, 'original failed package');
            $lock = \Gallery\Services\application_update_acquire_lock(
                \Gallery\Services\application_update_job_dir($source['id']) . '/worker.lock');
            recovery_check(\Gallery\Services\application_update_recover_failed_job($source['id'])['id'] === $source['id'],
                'Recovery ignored a source worker lease.');
            \Gallery\Services\application_update_release_lock($lock);
            $rollback = \Gallery\Services\application_update_recover_failed_job($source['id']);
            recovery_check($rollback['operation'] === 'rollback' && $rollback['id'] !== $source['id'],
                'Mature failure did not create a separate automatic rollback.');
            recovery_check(\Gallery\Services\application_update_recover_failed_job($source['id'])['id'] === $rollback['id'],
                'Repeated recovery created another rollback.');
            if ($trigger === 'background') {
                // Simulate death after reserving the identity, before child state
                // creation/link publication. The same reservation must be reused.
                $journal = \Gallery\Services\application_update_load_job($source['id']);
                unset($journal['result']['superseded_by']);
                \Gallery\Services\application_update_save_job($journal);
                unlink(\Gallery\Services\application_update_job_state_path($rollback['id']));
                \Gallery\Services\application_update_set_active_job($source['id']);
                recovery_check(\Gallery\Services\application_update_recover_failed_job($source['id'])['id'] === $rollback['id'],
                    'Interrupted rollback creation allocated another identity.');
            }
            $complete = recovery_drain($rollback['id']);
            recovery_check($complete['status'] === 'completed',
                'Actual rollback pipeline did not complete: ' . (string) ($complete['error']['reference'] ?? ''));
            recovery_check(file_get_contents($root . '/app/payload.php') === '<?php /* before update */'
                && \Gallery\Services\application_update_version_from_local_bootstrap($root . '/app/bootstrap.php') === '1.0.0'
                && file_get_contents($root . '/app/old_only.php') === 'previous obsolete file'
                && !file_exists($root . '/app/new_only.php'),
                'Rollback failed to restore overwritten/deleted files or remove newly created files.');
            recovery_check(\Gallery\Services\application_update_active_job() === null
                && is_file($artifact)
                && \Gallery\Services\application_update_load_job($source['id'])['status'] === 'failed',
                'Rollback erased failure history or kept the installation blocked.');
            recovery_check(file_get_contents($localExtension) === '<?php /* unmanaged local extension */',
                'An extra local PHP file blocked recovery or was overwritten.');
            if ($operation === 'stable_restore') {
                recovery_check(\Gallery\Services\application_update_beta_active(),
                    'Rollback lost the original beta channel and commit.');
            }
            if ($trigger === 'api' && $operation === 'stable_update') {
                recovery_check(!\Gallery\Services\application_autoupdate_enabled(),
                    'Repairing a manual attempt enabled automatic installation.');
            }
        }

        $GLOBALS['recovery_settings'] = [];
        $observed = recovery_activated_source($root, 'admin', 'stable_update');
        \Gallery\EarlyRuntime\updater_runtime_enter($root);
        recovery_check(is_file(\Gallery\Services\application_update_job_dir($observed['id'])
            . '/runtime-used.json'), 'Normal runtime admission did not leave a durable use witness.');
        recovery_check(\Gallery\Services\application_update_acquire_lock(
            \Gallery\Services\application_update_jobs_root() . '/runtime-use.lock') === null,
            'Activation ignored a live shared runtime lease.');
        $readerContended = $observed;
        $readerContended['status'] = 'running';
        $readerContended['stage'] = 'activate';
        \Gallery\Services\application_update_save_job($readerContended);
        recovery_check(recovery_worker_probe($root, $token, $observed['id'])['status'] === 'running',
            'An independent activation worker ignored a live runtime reader.');
        \Gallery\Services\application_update_save_job($observed);
        \Gallery\EarlyRuntime\updater_runtime_leave();
        recovery_refuses(static function () use ($observed): void {
            \Gallery\Services\application_update_assert_rollback_database($observed);
        }, 'Unchanged schema falsely proved safety after ordinary runtime use.');
        $blockedUse = \Gallery\Services\application_update_recover_failed_job($observed['id']);
        recovery_check($blockedUse['id'] === $observed['id']
            && !empty(\Gallery\Services\application_update_load_job($observed['id'])['recovery_blocked']),
            'Ordinary database writers did not block automatic file rollback.');
        $legacy = recovery_activated_source($root, 'admin', 'stable_update');
        unset($legacy['checkpoints']['runtime_guard_protocol']);
        recovery_refuses(static function () use ($legacy): void {
            \Gallery\Services\application_update_assert_rollback_database($legacy);
        }, 'A package without the isolation protocol was treated as verified.');

        $gated = recovery_activated_source($root, 'background', 'stable_update', true);
        $gated['status'] = 'running';
        $gated['checkpoints']['activation_complete'] = true;
        \Gallery\Services\application_update_save_job($gated);
        $marker = \Gallery\Services\application_update_read_json(
            \Gallery\Services\application_update_activation_gate_path());
        recovery_check(!\Gallery\EarlyRuntime\activation_job_is_complete($root, $marker),
            'Protocol-1 gate reopened before migrations and job completion.');
        $ticket = str_repeat('d', 64); // Fixed hexadecimal transport vector, never a real credential.
        $chain = ['job_id' => $gated['id'], 'ticket_hash' => hash('sha256', $ticket),
            'queued_until' => time() + \Gallery\Services\APPLICATION_UPDATE_CHAIN_LEASE_SECONDS];
        \Gallery\Services\application_update_write_json_atomic(
            \Gallery\Services\application_update_jobs_root() . '/background-chain.json', $chain);
        $_GET = ['page' => 'site_maintenance_cron'];
        $_POST = ['update_ticket' => $ticket];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        recovery_check(\Gallery\EarlyRuntime\updater_worker_allowed($root, $marker),
            'A valid job-bound private continuation could not traverse the gate.');
        $_POST['update_ticket'] = str_repeat('e', 64);
        recovery_check(!\Gallery\EarlyRuntime\updater_worker_allowed($root, $marker),
            'A wrong private ticket traversed the gate.');
        $_POST = ['update_ticket' => $ticket, 'mixed' => '1'];
        recovery_check(!\Gallery\EarlyRuntime\updater_worker_allowed($root, $marker),
            'A mixed worker envelope traversed the gate.');
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'CLI';

        $GLOBALS['recovery_settings'] = [];
        $source = recovery_activated_source($root, 'api', 'stable_update');
        $rollback = \Gallery\Services\application_update_recover_failed_job($source['id']);
        recovery_check(recovery_drain($rollback['id'])['status'] === 'completed', 'Cannot establish restored source.');
        $GLOBALS['recovery_remote_version'] = '1.1.0';
        \Gallery\Services\application_autoupdate_run_installing_check(true);
        recovery_check(\Gallery\Services\application_update_active_job() === null,
            'Automatic discovery reinstalled the known failed stable version.');
        $GLOBALS['recovery_remote_version'] = '1.2.0';
        \Gallery\Services\application_autoupdate_run_installing_check(true);
        $newer = \Gallery\Services\application_update_active_job();
        recovery_check($newer !== null && $newer['parameters']['target_version'] === '1.2.0',
            'Restored installation did not admit a newer stable release.');
        $GLOBALS['recovery_remote_commit'] = str_repeat('b', 40);
        $quarantined = \Gallery\Services\application_update_process_job($newer['id']);
        recovery_check($quarantined['status'] === 'failed' && !$quarantined['can_resume']
            && !is_file(\Gallery\Services\application_update_job_dir($newer['id']) . '/package.zip'),
            'A previously failed commit was downloaded again automatically.');
        unset($GLOBALS['recovery_remote_commit']);

        foreach (['missing', 'corrupt', 'metadata'] as $kind) {
            $source = recovery_activated_source($root, 'admin', 'stable_update');
            $path = \Gallery\Services\application_update_job_dir($source['id']) . '/rollback/original/app/payload.php';
            if ($kind === 'missing') { unlink($path); }
            elseif ($kind === 'metadata') {
                $metadataPath = \Gallery\Services\application_update_job_dir($source['id']) . '/rollback/metadata.json';
                $metadata = \Gallery\Services\application_update_read_json($metadataPath);
                $metadata['created_paths'][] = 'app/payload.php';
                \Gallery\Services\application_update_write_json_atomic($metadataPath, $metadata);
            } else { file_put_contents($path, 'corrupt'); }
            $rollback = \Gallery\Services\application_update_recover_failed_job($source['id']);
            if ($rollback['id'] !== $source['id']) {
                $failedRollback = recovery_drain($rollback['id']);
                recovery_check($failedRollback['status'] === 'failed', 'Bad backup unexpectedly completed rollback.');
                $attempts = $failedRollback['attempts'];
                \Gallery\Services\application_update_continue_background_job();
                recovery_check(\Gallery\Services\application_update_load_job($rollback['id'])['attempts'] === $attempts,
                    'Failed rollback created an automatic retry/recovery loop.');
            } else {
                recovery_check(!empty(\Gallery\Services\application_update_load_job($source['id'])['recovery_blocked']),
                    'Missing backup was not explained.');
            }
            recovery_check(file_get_contents($root . '/app/payload.php') === '<?php /* bad active release */',
                'An unverified backup modified the installation.');
        }
        foreach (['unknown', 'changed'] as $kind) {
            $source = recovery_activated_source($root, 'api', 'stable_update');
            if ($kind === 'unknown') { $GLOBALS['recovery_schema_state'] = 'unknown'; }
            else { $pdo->exec('CREATE TABLE incompatible_change (id INTEGER PRIMARY KEY)'); }
            $blocked = \Gallery\Services\application_update_recover_failed_job($source['id']);
            recovery_check($blocked['id'] === $source['id'] && $blocked['status'] === 'failed',
                'Unknown/changed database authorized rollback.');
            $GLOBALS['recovery_schema_state'] = 'available';
            if ($kind === 'changed') { $pdo->exec('DROP TABLE incompatible_change'); }
        }

        $priorVersion = '202610250000_prior_recovery';
        $priorFile = $root . '/database/migrations/' . $priorVersion . '.php';
        file_put_contents($priorFile, "<?php /* original applied migration */\r\n");
        $insert = $pdo->prepare('INSERT INTO schema_migrations (version,applied_at) VALUES (?,?)');
        $insert->execute([$priorVersion, gmdate('Y-m-d H:i:s')]);
        $source = recovery_activated_source($root, 'api', 'stable_update');
        $legacyWitness = $source;
        $legacyWitness['checkpoints']['database_before_activation']['hashes'][$priorVersion] =
            \Gallery\Services\application_update_manifest_hash($priorFile);
        unset($legacyWitness['checkpoints']['database_before_activation']['format'],
            $legacyWitness['checkpoints']['database_before_activation']['ledger'],
            $legacyWitness['checkpoints']['database_before_activation']['migration_manifest']);
        \Gallery\Services\application_update_assert_rollback_database($legacyWitness);
        $futureWitness = $source;
        $futureWitness['checkpoints']['database_before_activation']['format'] = 999;
        recovery_refuses(static function () use ($futureWitness): void {
            \Gallery\Services\application_update_assert_rollback_database($futureWitness);
        }, 'Unknown evidence format bypassed rollback admission.');
        $originalAppliedAt = (string) $pdo->query('SELECT applied_at FROM schema_migrations')->fetchColumn();
        $pdo->prepare('UPDATE schema_migrations SET applied_at=? WHERE version=?')
            ->execute(['2000-01-01 00:00:00', $priorVersion]);
        recovery_refuses(static function () use ($source): void {
            \Gallery\Services\application_update_assert_rollback_database($source);
        }, 'Changed ledger audit fields bypassed rollback admission.');
        $pdo->prepare('UPDATE schema_migrations SET applied_at=? WHERE version=?')
            ->execute([$originalAppliedAt, $priorVersion]);
        file_put_contents($priorFile, "<?php /* original applied migration */\n");
        recovery_refuses(static function () use ($source): void {
            \Gallery\Services\application_update_assert_rollback_database($source);
        }, 'Changed migration line-ending bytes bypassed exact-byte evidence.');
        file_put_contents($priorFile, '<?php /* changed applied migration */');
        recovery_refuses(static function () use ($source): void {
            \Gallery\Services\application_update_assert_rollback_database($source);
        }, 'Changed applied migration bytes bypassed rollback integrity.');
        $delete = $pdo->prepare('DELETE FROM schema_migrations WHERE version=?');
        $delete->execute([$priorVersion]);
        unlink($priorFile);

        $retired = '202606100001_experimental_client_upload_settings';
        $insert->execute([$retired, '2026-06-10 00:00:00']);
        $source = recovery_activated_source($root, 'admin', 'stable_update', true);
        $witness = $source['checkpoints']['database_before_activation'];
        recovery_check($witness['versions'] === [$retired]
            && array_key_exists($retired, $witness['hashes'])
            && $witness['hashes'][$retired] === null,
            'Historical absence lost the full ledger or invented file bytes.');
        \Gallery\Services\application_update_assert_rollback_database($source);
        $incompleteLegacy = $source;
        unset($incompleteLegacy['checkpoints']['database_before_activation']['format'],
            $incompleteLegacy['checkpoints']['database_before_activation']['ledger'],
            $incompleteLegacy['checkpoints']['database_before_activation']['migration_manifest']);
        recovery_refuses(static function () use ($incompleteLegacy): void {
            \Gallery\Services\application_update_assert_rollback_database($incompleteLegacy);
        }, 'Legacy evidence acquired invented historical-absence support.');
        $pdo->prepare('UPDATE schema_migrations SET version=? WHERE version=?')
            ->execute(['202606100002_other_retired', $retired]);
        recovery_refuses(static function () use ($source): void {
            \Gallery\Services\application_update_assert_rollback_database($source);
        }, 'Changed historical ledger bypassed rollback admission.');
        $pdo->prepare('UPDATE schema_migrations SET version=? WHERE version=?')
            ->execute([$retired, '202606100002_other_retired']);
        $rollback = \Gallery\Services\application_update_recover_failed_job($source['id']);
        recovery_check($rollback['operation'] === 'rollback'
            && recovery_drain($rollback['id'])['status'] === 'completed',
            'Historical missing migration prevented real rollback completion.');
        $requiredPath = 'database/migrations/' . $retired . '.php';
        $manifestPath = $root . '/app/core-manifest.json';
        $originalManifest = file_get_contents($manifestPath);
        $manifest = json_decode($originalManifest, true, flags: JSON_THROW_ON_ERROR);
        $manifest['files'][$requiredPath] = 'sha256:' . str_repeat('a', 64);
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        recovery_refuses(static function () use ($root): void {
            \Gallery\Services\application_update_recovery_database_evidence($root);
        }, 'Missing declared current migration was treated as historical absence.');
        file_put_contents($root . '/' . $requiredPath, '<?php /* unexpected bytes */');
        recovery_refuses(static function () use ($root): void {
            \Gallery\Services\application_update_recovery_database_evidence($root);
        }, 'Corrupt declared current migration was accepted.');
        unlink($root . '/' . $requiredPath);
        unlink($manifestPath);
        recovery_refuses(static function () use ($root): void {
            \Gallery\Services\application_update_recovery_database_evidence($root);
        }, 'Missing installed inventory manufactured historical-absence proof.');
        file_put_contents($manifestPath, $originalManifest);
        $delete->execute([$retired]);

        $package = $root . '/candidate';
        recovery_package($package);
        $verified = \Gallery\Services\application_update_create_job('stable_update',
            ['branch' => 'main', 'target_version' => '1.2.0'], 'background');
        $verified['checkpoints']['validated_version'] = '1.2.0';
        recovery_check(!\Gallery\Services\application_update_recovery_verify_package($verified, $package,
            ['deadline' => microtime(true) - 1, 'seconds' => 0.0]), 'Expired hashing claimed completion.');
        $progress = \Gallery\Services\application_update_job_public_state($verified)['progress'];
        recovery_check($progress['percent'] === 0 && $progress['current'] === 0
            && $progress['total'] > 0 && $progress['message'] === 'Verifying package file integrity.',
            'Integrity verification inherited extraction completion or a stale message.');
        recovery_check(\Gallery\Services\application_update_recovery_verify_package($verified, $package,
            \Gallery\Services\application_update_time_budget()), 'Hashing did not resume.');
        $binary = 'public/assets/recovery-proof.bin';
        recovery_check(str_starts_with($verified['checkpoints']['recovery_hashes'][$binary], 'sha256-raw:'),
            'A legitimate non-core resource lacked an exact-byte identity.');
        $normalizedBefore = \Gallery\Services\application_update_manifest_hash($package . '/' . $binary);
        file_put_contents($package . '/' . $binary, "\x00\n\xffresource");
        recovery_check($normalizedBefore === \Gallery\Services\application_update_manifest_hash($package . '/' . $binary),
            'The binary corruption vector no longer exercises text-normalization collision.');
        $verified['checkpoints']['source_root'] = $package;
        $verified['checkpoints']['activation_files'] = [$binary];
        recovery_refuses(static function () use (&$verified): void {
            \Gallery\Services\application_update_job_stage_files_slice($verified,
                \Gallery\Services\application_update_time_budget());
        }, 'Changed binary bytes bypassed actual staging checks.');
        file_put_contents($package . '/app/payload.php', 'tampered');
        $verified['checkpoints']['recovery_verify_index'] = 0;
        recovery_refuses(static function () use (&$verified, $package): void {
            \Gallery\Services\application_update_recovery_verify_package($verified, $package,
                \Gallery\Services\application_update_time_budget());
        }, 'A bad newer package bypassed complete manifest verification.');

        $migration = $root . '/database/migrations/202610250001_recovery_probe.php';
        copy(__DIR__ . '/fixtures/updater_recovery_migration.php', $migration);
        $broken = recovery_source('migrate', 'api');
        $broken['status'] = 'running';
        $GLOBALS['recovery_migration_failure'] = 'undefined';
        \Gallery\Services\application_update_save_job($broken);
        $failed = \Gallery\Services\application_update_process_job($broken['id']);
        $privateFailure = \Gallery\Services\application_update_load_job($broken['id']);
        recovery_check(($privateFailure['last_failure_diagnostic']['stage'] ?? '') === 'migrate'
            && ($privateFailure['last_failure_diagnostic']['reference'] ?? '') === $failed['error']['reference']
            && !array_key_exists('last_failure_diagnostic', $failed),
            'Worker diagnostics were lost or exposed through the public job.');
        $migrationDiagnostic = new \Gallery\Services\ApplicationUpdateMigrationEvidenceException(
            'missing_file', '202610250001_recovery_probe');
        \Gallery\Services\application_update_record_failure_diagnostic($privateFailure,
            $migrationDiagnostic, 'DIAGNOSTIC');
        recovery_check($privateFailure['last_failure_diagnostic']['migration_version'] === '202610250001_recovery_probe'
            && $privateFailure['last_failure_diagnostic']['migration_evidence'] === 'missing_file',
            'Diagnostic omitted the exact validated migration identity.');
        $previousLog = ini_get('error_log');
        ini_set('error_log', $root . '/worker-diagnostics.log');
        $GLOBALS['recovery_log_unavailable'] = true;
        try {
            \Gallery\Services\application_update_record_failure_diagnostic($privateFailure,
                new \RuntimeException('secret-password sql-token /private/path'), 'SAFEFALLBACK');
            $fallbackLog = (string) file_get_contents($root . '/worker-diagnostics.log');
            recovery_check(str_contains($fallbackLog, 'SAFEFALLBACK')
                && !str_contains($fallbackLog, 'secret-password')
                && !str_contains($fallbackLog, '/private/path'),
                'Unavailable Admin logging lost diagnostics or leaked raw exception data.');
            $GLOBALS['recovery_php_log_disabled'] = true;
            \Gallery\Services\application_update_record_failure_diagnostic($privateFailure,
                new \RuntimeException('private original failure'), 'DISABLEDLOG');
            \Gallery\Services\application_update_save_job($privateFailure);
            recovery_check(\Gallery\Services\application_update_load_job($privateFailure['id'])
                ['last_failure_diagnostic']['reference'] === 'DISABLEDLOG',
                'Disabled Admin and PHP logging prevented the durable failure checkpoint.');
        } finally {
            unset($GLOBALS['recovery_log_unavailable'], $GLOBALS['recovery_php_log_disabled']);
            ini_set('error_log', (string) $previousLog);
        }
        recovery_check($failed['status'] === 'failed' && !$failed['can_resume'],
            'Actual deterministic migration failure did not stop.');
        $broken = recovery_mature(\Gallery\Services\application_update_load_job($broken['id']));
        $attempts = $broken['attempts'];
        \Gallery\Services\application_update_recover_failed_job($broken['id']);
        recovery_check(\Gallery\Services\application_update_load_job($broken['id'])['attempts'] === $attempts
            && \Gallery\Services\application_update_active_job()['id'] === $broken['id'],
            'Unverified migration was retried or rolled back.');
        $GLOBALS['recovery_remote_version'] = '1.2.0';
        \Gallery\Services\application_autoupdate_run_installing_check(true);
        $available = json_decode($GLOBALS['recovery_settings']['application_update_check_status_json'], true);
        recovery_check($available['latest_version'] === '1.2.0'
            && \Gallery\Services\application_update_active_job()['id'] === $broken['id'],
            'Blocked migration hid newer availability or changed ownership.');

        $transient = recovery_source('migrate', 'admin');
        $transient['status'] = 'running';
        $GLOBALS['recovery_migration_failure'] = 'transient';
        \Gallery\Services\application_update_save_job($transient);
        $failed = \Gallery\Services\application_update_process_job($transient['id']);
        recovery_check($failed['status'] === 'failed' && $failed['can_resume'], 'Transient error lost retry.');
        $transient = \Gallery\Services\application_update_load_job($transient['id']);
        $transient['error']['at'] = 0;
        \Gallery\Services\application_update_save_job($transient);
        recovery_check(\Gallery\Services\application_update_retry_job($transient['id'], true)['status'] === 'running',
            'Transient manual job did not admit an automatic retry.');
        recovery_check(!empty(\Gallery\Services\application_update_load_job($transient['id'])['automatic_repair_continuation'])
            && \Gallery\Services\application_update_load_job($transient['id'])['trigger'] === 'admin',
            'Automatic repair lost continuation permission or changed manual ownership.');
        $GLOBALS['recovery_migration_failure'] = '';
        $transient = \Gallery\Services\application_update_load_job($transient['id']);
        recovery_check(\Gallery\Services\application_update_job_migrate_slice($transient)
            && \Gallery\Services\application_update_job_migrate_slice($transient)
            && (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 1,
            'Actual interrupted DDL/callback replay failed or duplicated its ledger.');
        $bounded = recovery_source('migrate');
        for ($retry = 0; $retry < \Gallery\Services\APPLICATION_UPDATE_AUTO_RETRY_LIMIT; $retry++) {
            $bounded['error']['at'] = 0;
            \Gallery\Services\application_update_save_job($bounded);
            recovery_check(\Gallery\Services\application_update_retry_job($bounded['id'], true)['status'] === 'running',
                'A permitted transient retry was refused.');
            $bounded = \Gallery\Services\application_update_load_job($bounded['id']);
            $bounded['status'] = 'failed';
            $bounded['error'] = ['retryable' => true, 'reference' => 'TRANSIENT', 'at' => 0];
        }
        \Gallery\Services\application_update_save_job($bounded);
        recovery_check(\Gallery\Services\application_update_retry_job($bounded['id'], true)['status'] === 'failed',
            'Automatic retry exceeded its lifetime bound.');
        $paused = recovery_source('completed', 'api');
        $paused['status'] = 'running';
        \Gallery\Services\application_update_save_job($paused);
        recovery_check(\Gallery\Services\application_update_continue_background_job() === null,
            'An ordinary paused manual checkpoint was taken over.');
        $paused['automatic_repair_continuation'] = true;
        \Gallery\Services\application_update_save_job($paused);
        require_once dirname(__DIR__) . '/app/services/updates_background.php';
        recovery_check(\Gallery\Services\application_update_background_job_eligible($paused),
            'A previously admitted manual repair could not hand off further slices.');
        $GLOBALS['recovery_disabled'] = true;
        recovery_check(!\Gallery\Services\application_update_background_job_eligible($paused),
            'Repair continuation bypassed installer capability OFF.');
        unset($GLOBALS['recovery_disabled']);
        recovery_check(\Gallery\Services\application_update_continue_background_job()['status'] === 'completed'
            && \Gallery\Services\application_update_load_job($paused['id'])['trigger'] === 'api',
            'Admitted manual repair could not finish its next durable checkpoint.');
        foreach (\Gallery\Services\application_update_job_stages() as $stage) {
            $pauseVector = recovery_source($stage, 'admin');
            $pauseVector['status'] = 'running';
            \Gallery\Services\application_update_save_job($pauseVector);
            \Gallery\Services\application_update_control_pause($pauseVector['id']);
            $pausedState = \Gallery\Services\application_update_process_job($pauseVector['id']);
            recovery_check($pausedState['status'] === 'paused'
                && $pausedState['stage'] === $stage,
                'Pause changed or executed the requested checkpoint: ' . $stage);
        }
        \Gallery\Services\application_update_control_pause($pauseVector['id'], true);
        recovery_check(\Gallery\Services\application_update_process_job($pauseVector['id'])['status'] === 'completed',
            'Explicit resume did not finish the same paused job.');

        // Overlay the complete actual updater file set, preserving local extras
        // and old job data. Release metadata is synthetic in this owned root.
        $repositoryRoot = dirname(__DIR__);
        foreach (\Gallery\Core\release_file_policy_paths($repositoryRoot, 'updater') as $relative) {
            \Gallery\Services\application_update_ensure_dir(dirname($root . '/' . $relative));
            copy($repositoryRoot . '/' . $relative, $root . '/' . $relative);
        }
        $sourceVersion = \Gallery\Services\application_update_version_from_local_bootstrap($root . '/app/bootstrap.php');
        file_put_contents($root . '/app/bootstrap.php', str_replace(
            "'" . $sourceVersion . "'", "'1.2.0'",
            (string) file_get_contents($root . '/app/bootstrap.php'),
        ));
        $overlayManifest = json_decode((string) file_get_contents($root . '/app/core-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $overlayManifest['version'] = '1.2.0';
        $overlayManifest['files']['app/bootstrap.php'] = \Gallery\Services\application_update_manifest_hash($root . '/app/bootstrap.php');
        file_put_contents($root . '/app/core-manifest.json', json_encode($overlayManifest, JSON_THROW_ON_ERROR));
        $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (glob($root . '/database/migrations/*.php') as $migrationFile) {
            $version = basename($migrationFile, '.php');
            if (!in_array($version, $applied, true)) {
                $insert->execute([$version, gmdate('Y-m-d H:i:s')]);
            }
        }
        $old = recovery_source('activate', 'admin');
        unset($old['installed_version_at_start']);
        $old['checkpoints']['validated_version'] = '1.1.0';
        $old['status'] = 'running';
        \Gallery\Services\application_update_save_job($old);
        recovery_check(\Gallery\Services\application_update_process_job($old['id'])['status'] === 'failed',
            'An old package resumed over an FTP replacement.');
        $old = \Gallery\Services\application_update_load_job($old['id']);
        @unlink(\Gallery\Services\application_update_activation_gate_path());
        \Gallery\Services\application_update_activation_gate_begin($old);
        \Gallery\Services\application_update_write_json_atomic(
            \Gallery\Services\application_update_jobs_root() . '/rollback-window.json', ['job_id' => $old['id']]);
        $oldProof = \Gallery\Services\application_update_job_dir($old['id']) . '/rollback/original/preserved-proof.txt';
        file_put_contents($oldProof, 'preserved original backup');
        $manual = \Gallery\Services\application_update_start_manual_deployment();
        recovery_check(\Gallery\Services\application_update_start_manual_deployment()['id'] === $manual['id'],
            'Repeated FTP completion allocated another owner.');
        $cssPath = $root . '/public/assets/styles/utilities.css';
        file_put_contents($cssPath, "\n/* Local hosting stylesheet difference. */\n", FILE_APPEND);
        $manualResult = recovery_drain($manual['id']);
        recovery_check($manualResult['status'] === 'completed'
            && $manualResult['result']['version'] === '1.2.0'
            && $manualResult['result']['files_copied'] === 0
            && $manualResult['result']['backup'] === ''
            && file_get_contents($localExtension) === '<?php /* unmanaged local extension */'
            && file_get_contents($oldProof) === 'preserved original backup'
            && $manualResult['integrity_warnings'] === []
            && !is_file(\Gallery\Services\application_update_activation_gate_path()),
            'FTP overlay completion deleted extras/backups, restored old code or retained the gate.');

        // A real ordinary reader must not prevent Reset from closing admission.
        // Only migration waits for that reader; missing publisher metadata is
        // diagnostic and cannot strand the newly allocated forward recovery.
        $reader = fopen(\Gallery\Services\application_update_jobs_root()
            . '/runtime-use.lock', 'c+');
        recovery_check(is_resource($reader) && flock($reader, LOCK_SH | LOCK_NB),
            'Cannot establish the ordinary reader for reset takeover.');
        $savedManifest = file_get_contents($root . '/app/core-manifest.json');
        unlink($root . '/app/core-manifest.json');
        try {
            $readerReset = \Gallery\Services\application_update_start_manual_deployment(true);
            $readerState = \Gallery\Services\application_update_load_job($readerReset['id']);
            $admission = \Gallery\Services\application_update_read_json(
                \Gallery\Services\application_update_activation_gate_path());
            recovery_check($readerReset['status'] === 'running'
                && empty($readerReset['replacement_waiting'])
                && empty($readerState['checkpoints']['runtime_guard_open'])
                && $admission['job_id'] === $readerReset['id']
                && !empty($admission['recovery_isolation']),
                'An ordinary reader starved reset before admission could close.');
            $readerWait = \Gallery\Services\application_update_process_job($readerReset['id']);
            recovery_check($readerWait['status'] === 'running'
                && $readerWait['runtime_waiting']
                && $readerWait['integrity_warnings'] === [],
                'FTP metadata stopped recovery or migration ignored an existing reader.');
            $warningEvents = array_values(array_filter($GLOBALS['recovery_log_events'],
                static fn(array $event): bool => $event['event'] === 'update.recovery_validation_warning'
                    && $event['context']['job_id'] === $readerReset['id']));
            recovery_check($warningEvents === []
                && !isset(\Gallery\Services\application_update_load_job($readerReset['id'])
                    ['checkpoints']['recovery_verify_index']),
                'Disabled publisher verification still ran a diagnostic sweep.');
        } finally {
            flock($reader, LOCK_UN);
            fclose($reader);
        }
        recovery_check(recovery_drain($readerReset['id'])['status'] === 'completed',
            'Missing manifest or drained runtime reader prevented FTP completion.');
        file_put_contents($root . '/app/core-manifest.json', $savedManifest);

        // A paused migration from an unknown/newer old target and a malformed
        // gate cannot veto an explicit operator forward repair. Preserve its
        // exact evidence instead of trying to prove a historical rollback.
        $interrupted = recovery_source('migrate', 'admin', 'manual_deploy');
        $interrupted['status'] = 'paused';
        $interrupted['checkpoints']['migrations_started'] = true;
        $interrupted['checkpoints']['validated_version'] = '9.9.0';
        \Gallery\Services\application_update_save_job($interrupted);
        $brokenMarker = '{"job_id":"interrupted legacy marker';
        file_put_contents(\Gallery\Services\application_update_activation_gate_path(), $brokenMarker);
        $reset = \Gallery\Services\application_update_start_manual_deployment(true);
        recovery_check($reset['id'] !== $interrupted['id'] && $reset['attempts'] === 0
            && \Gallery\Services\application_update_load_job($interrupted['id'])['status'] === 'cancelled'
            && file_get_contents(\Gallery\Services\application_update_job_dir($reset['id'])
                . '/superseded-activation.json') === $brokenMarker,
            'Reset retained a stuck owner or discarded malformed historical evidence.');

        // Cover both takeover crash boundaries: an initial zero-slice child
        // lacking its link, and a linked child already owning only one marker.
        foreach ([false, true] as $partialMarker) {
            $predecessor = \Gallery\Services\application_update_active_job();
            $reserved = \Gallery\Services\application_update_create_job('manual_deploy', [], 'admin');
            $predecessor['replacement_reservations']['manual_deploy'] = $reserved['id'];
            \Gallery\Services\application_update_save_job($predecessor);
            $preservedWindow = '';
            if ($partialMarker) {
                $reserved['replaces_job_id'] = $predecessor['id'];
                $reserved['recovery_override'] = true;
                $reserved['stage'] = 'package_validate';
                \Gallery\Services\application_update_save_job($reserved);
                $windowPath = \Gallery\Services\application_update_jobs_root() . '/rollback-window.json';
                $preservedWindow = (string) file_get_contents($windowPath);
                file_put_contents(\Gallery\Services\application_update_job_dir($reserved['id'])
                    . '/superseded-rollback-window.json', $preservedWindow);
                \Gallery\Services\application_update_write_json_atomic($windowPath,
                    ['job_id' => $reserved['id']]);
            }
            $continued = \Gallery\Services\application_update_start_manual_deployment(true);
            recovery_check($continued['id'] === $reserved['id']
                && $continued['status'] === 'running'
                && \Gallery\Services\application_update_load_job($predecessor['id'])['status'] === 'cancelled',
                'Interrupted takeover lost its reservation or cancelled its own child.');
            if ($partialMarker) {
                recovery_check(file_get_contents(\Gallery\Services\application_update_job_dir($reserved['id'])
                    . '/superseded-rollback-window.json') === $preservedWindow,
                    'Replayed takeover overwrote the original marker receipt.');
            }
        }

        // A failed FTP verification retries installed files, never a download.
        $retry = \Gallery\Services\application_update_start_manual_deployment(true);
        $retryState = \Gallery\Services\application_update_load_job($retry['id']);
        $retryState['status'] = 'failed';
        $retryState['checkpoints']['recovery_manifest_identity'] = 'old-upload';
        $retryState['checkpoints']['manual_deployment_verified'] = true;
        $retryState['checkpoints']['manual_deployment_accepted'] = true;
        $retryState['error'] = ['message' => 'Previous upload differed.', 'reference' => 'FF5BC089547B'];
        \Gallery\Services\application_update_save_job($retryState);
        $retried = \Gallery\Services\application_update_retry_job($retry['id']);
        $retryState = \Gallery\Services\application_update_load_job($retry['id']);
        recovery_check($retried['stage'] === 'package_validate'
            && !isset($retryState['checkpoints']['recovery_manifest_identity'])
            && empty($retryState['checkpoints']['manual_deployment_verified'])
            && empty($retryState['checkpoints']['manual_deployment_accepted']),
            'FTP retry resumed a download or trusted old upload verification.');

        // Explicit replacement must wait for the real predecessor worker lock,
        // then own both gates without resuming the old installer.
        $worker = \Gallery\Services\application_update_acquire_lock(
            \Gallery\Services\application_update_job_dir($retry['id']) . '/worker.lock');
        $waiting = \Gallery\Services\application_update_start_recovery_replacement('stable_restore');
        recovery_check(!empty($waiting['replacement_waiting'])
            && $waiting['replacement_action'] === 'beta_revert'
            && is_file(\Gallery\Services\application_update_job_dir($retry['id']) . '/pause-requested.json'),
            'Stable recovery raced an in-flight worker or silently continued it.');
        \Gallery\Services\application_update_release_lock($worker);
        $stable = \Gallery\Services\application_update_start_recovery_replacement('stable_restore');
        recovery_check($stable['id'] !== $retry['id']
            && \Gallery\Services\application_update_load_job($retry['id'])['status'] === 'cancelled'
            && \Gallery\Services\application_update_process_job($retry['id'])['status'] === 'cancelled'
            && !empty(\Gallery\Services\application_update_load_job($stable['id'])['recovery_override']),
            'Explicit stable installation retained the old active job.');

        // Confine the transport boundary: seed a complete real source tree with
        // a small synthetic inventory. The actual validation, plan, backup,
        // staged-byte verification, activation and migration owners still run.
        $stableRoot = \Gallery\Services\application_update_job_dir($stable['id']) . '/extract/release';
        foreach (\Gallery\Core\release_file_policy_paths($repositoryRoot, 'updater') as $relative) {
            \Gallery\Services\application_update_ensure_dir(dirname($stableRoot . '/' . $relative));
            copy($repositoryRoot . '/' . $relative, $stableRoot . '/' . $relative);
        }
        $stablePaths = ['app/bootstrap.php', 'app/core-manifest.json',
            'app/production-files.json', 'index.php', 'public/assets/styles/utilities.css'];
        file_put_contents($stableRoot . '/app/production-files.json', json_encode([
            'schema_version' => 1, 'production_files' => $stablePaths,
            'updater_files' => $stablePaths, 'source_review_files' => [],
        ], JSON_THROW_ON_ERROR));
        // The same version is deliberate: Install stable must still overwrite.
        copy($root . '/app/bootstrap.php', $stableRoot . '/app/bootstrap.php');
        $stableHashes = [];
        foreach ($stablePaths as $relative) {
            if ($relative !== 'app/core-manifest.json') {
                $stableHashes[$relative] = \Gallery\Services\application_update_manifest_hash($stableRoot . '/' . $relative);
            }
        }
        file_put_contents($stableRoot . '/app/core-manifest.json', json_encode([
            'version' => '1.2.0', 'algorithm' => 'sha256',
            'hash_mode' => 'normalized-text-sha256', 'files' => $stableHashes,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($stableRoot . '/public/assets/styles/utilities.css', "\n/* Publisher difference. */\n", FILE_APPEND);
        $strictJob = \Gallery\Services\application_update_load_job($stable['id']);
        $strictJob['checkpoints']['validated_version'] = '1.2.0';
        $strictRoot = \Gallery\Services\application_update_job_dir($stable['id']) . '/strict';
        recovery_package($strictRoot);
        file_put_contents($strictRoot . '/app/payload.php', '<?php /* changed publisher bytes */');
        try {
            \Gallery\Services\application_update_recovery_verify_package($strictJob, $strictRoot,
                \Gallery\Services\application_update_time_budget());
            throw new \RuntimeException('Retained opt-in verifier accepted corrupt bytes.');
        } catch (\Gallery\Services\ApplicationUpdateFileIntegrityException $difference) {
            \Gallery\Services\application_update_record_failure_diagnostic($strictJob,
                $difference, 'INTEGRITY');
            recovery_check($strictJob['last_failure_diagnostic']['integrity_file'] === 'app/payload.php'
                && $strictJob['last_failure_diagnostic']['expected_hash'] !== $strictJob['last_failure_diagnostic']['actual_hash']
                && str_contains(\Gallery\Services\application_update_safe_error($difference)['message'], 'app/payload.php'),
                'Integrity failure lost actionable relative file/hash evidence.');
        }
        $stableState = \Gallery\Services\application_update_load_job($stable['id']);
        require_once dirname(__DIR__) . '/app/services/gallery_edit_concurrency.php';
        recovery_check(\Gallery\Services\gallery_edit_schema_state() === 'missing',
            'The stable fixture must expose missing revision enforcement before provisioning.');
        // Earlier fixtures need only the ledger. Stable activation additionally
        // requires the already-applied gallery revision schema, so provision its
        // real table rather than labelling an absent column as available.
        $pdo->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY, edit_revision BIGINT NOT NULL DEFAULT 1)');
        \Gallery\Services\schema_inspection_reset_request_cache();
        recovery_check(\Gallery\Services\gallery_edit_schema_state() === 'available',
            'The stable fixture did not observe its actual revision column.');
        $stableState['stage'] = 'package_validate';
        // Explicit stable installation also survives malformed publisher
        // metadata: copy these actual bytes, log a warning, finish forward.
        file_put_contents($stableRoot . '/app/core-manifest.json', '{invalid manifest');
        \Gallery\Services\application_update_save_job($stableState);
        $stableResult = recovery_drain($stable['id']);
        $terminalState = \Gallery\Services\application_update_load_job($stable['id']);
        recovery_check($stableResult['status'] === 'completed',
            'Stable replacement terminal state: ' . json_encode([
                'status' => $stableResult['status'], 'stage' => $stableResult['stage'],
                'error' => $stableResult['error'],
                'diagnostic' => $terminalState['last_failure_diagnostic'] ?? null,
            ], JSON_THROW_ON_ERROR));
        recovery_check($stableResult['status'] === 'completed'
            && $stableResult['result']['files_copied'] === count($stablePaths)
            && $stableResult['integrity_warnings'] === []
            && file_get_contents($root . '/public/assets/styles/utilities.css')
                === file_get_contents($stableRoot . '/public/assets/styles/utilities.css')
            && file_get_contents($oldProof) === 'preserved original backup'
            && is_file($localExtension)
            && !is_file(\Gallery\Services\application_update_activation_gate_path())
            && !is_file(\Gallery\Services\application_update_jobs_root() . '/rollback-window.json')
            && !$stableResult['can_rollback'],
            'Stable replacement failed, skipped same-version files, deleted evidence or left isolation open.');

        // Reproduce the actual ordinary background path, without recovery
        // override. A pre-existing reader may finish, but new readers must
        // receive 503 before taking another shared lease. The disabled sweep
        // must not run even when publisher hashes differ from selected bytes.
        file_put_contents($root . '/app/core-manifest.json', $savedManifest);
        $ordinary = \Gallery\Services\application_update_create_job('stable_update',
            ['branch' => 'main', 'target_version' => '1.2.1'], 'background');
        $ordinaryRoot = \Gallery\Services\application_update_job_dir($ordinary['id'])
            . '/extract/release';
        foreach (\Gallery\Core\release_file_policy_paths($repositoryRoot, 'updater') as $relative) {
            \Gallery\Services\application_update_ensure_dir(dirname($ordinaryRoot . '/' . $relative));
            copy($repositoryRoot . '/' . $relative, $ordinaryRoot . '/' . $relative);
        }
        file_put_contents($ordinaryRoot . '/app/bootstrap.php', str_replace(
            "'1.2.0'", "'1.2.1'", (string) file_get_contents($root . '/app/bootstrap.php')));
        $ordinaryManifest = json_decode($savedManifest, true, flags: JSON_THROW_ON_ERROR);
        $ordinaryManifest['version'] = '1.2.1';
        file_put_contents($ordinaryRoot . '/app/core-manifest.json',
            json_encode($ordinaryManifest, JSON_THROW_ON_ERROR));
        file_put_contents($ordinaryRoot . '/public/assets/styles/utilities.css',
            "\n/* Archive content accepted without the publisher sweep. */\n", FILE_APPEND);
        $ordinary['stage'] = 'package_validate';
        recovery_check(session_status() !== PHP_SESSION_ACTIVE
            && $ordinary['admin_session_name'] === session_name()
            && $ordinary['admin_session_name'] !== '',
            'Post-response job creation lost the configured Admin session name.');
        \Gallery\Services\application_update_save_job($ordinary);
        \Gallery\Services\application_update_set_active_job($ordinary['id']);
        $beforeActivation = file_get_contents($root . '/app/bootstrap.php');
        $ordinaryReader = fopen(\Gallery\Services\application_update_jobs_root()
            . '/runtime-use.lock', 'c+');
        recovery_check(is_resource($ordinaryReader) && flock($ordinaryReader, LOCK_SH | LOCK_NB),
            'Cannot hold the ordinary request preceding automatic activation.');
        try {
            for ($slice = 0; $slice < RECOVERY_FIXTURE_DRAIN_SLICES; $slice++) {
                $ordinaryWait = \Gallery\Services\application_update_process_job($ordinary['id']);
                recovery_check($ordinaryWait['status'] === 'running',
                    'Ordinary background preparation stopped: ' . json_encode($ordinaryWait['error']));
                if ($ordinaryWait['runtime_waiting']) { break; }
            }
            $ordinaryState = \Gallery\Services\application_update_load_job($ordinary['id']);
            $admission = \Gallery\Services\application_update_read_json(
                \Gallery\Services\application_update_activation_gate_path());
            recovery_check($ordinaryWait['runtime_waiting']
                && $ordinaryWait['stage'] === 'activate'
                && $admission['job_id'] === $ordinary['id']
                && $admission['admin_session_name'] === $ordinary['admin_session_name']
                && !empty($admission['recovery_isolation'])
                && empty($ordinaryState['checkpoints']['activation_started'])
                && !isset($ordinaryState['checkpoints']['recovery_verify_index'])
                && !isset($ordinaryState['checkpoints']['recovery_ready_index'])
                && !empty($ordinaryState['checkpoints']['publisher_integrity_disabled'])
                && file_get_contents($root . '/app/bootstrap.php') === $beforeActivation,
                'Ordinary activation failed to close admission before waiting or ran a disabled sweep.');
            $blockedReader = recovery_worker_probe($root, $token, $ordinary['id'], true);
            recovery_check($blockedReader['status'] === '503'
                && !$blockedReader['runtime_lease_held']
                && str_contains($blockedReader['body'], 'Service temporarily unavailable'),
                'A new ordinary reader prolonged the activation drain.');
        } finally {
            flock($ordinaryReader, LOCK_UN);
            fclose($ordinaryReader);
        }
        // Successful ordinary cleanup removes extract/. Capture the expected
        // publisher bytes before draining instead of rereading deleted staging.
        $expectedOrdinaryCss = file_get_contents(
            $ordinaryRoot . '/public/assets/styles/utilities.css');
        // Drive the actual critical owners to their durable commit, then pause
        // cleanup. Another process must already be able to use the website.
        $ordinaryState = \Gallery\Services\application_update_load_job($ordinary['id']);
        recovery_check(\Gallery\Services\application_update_job_activate($ordinaryState),
            'Prepared ordinary activation did not acquire the drained runtime.');
        recovery_refuses(static function () use (&$ordinaryState): void {
            \Gallery\Services\application_update_runtime_commit($ordinaryState);
        }, 'Runtime reopened before migrations and finalization.');
        recovery_check(is_file($root . '/maintenance.lock'),
            'Critical activation stopped using the sole root maintenance marker.');
        $migrated = false;
        for ($slice = 0; $slice < RECOVERY_FIXTURE_DRAIN_SLICES; $slice++) {
            if (\Gallery\Services\application_update_job_migrate_slice($ordinaryState)) {
                $migrated = true;
                break;
            }
        }
        recovery_check($migrated && is_file($root . '/maintenance.lock'),
            'Migration completion lost maintenance before finalization.');
        \Gallery\Services\application_update_job_finalize($ordinaryState);
        \Gallery\Services\application_update_runtime_commit($ordinaryState);
        \Gallery\Services\application_update_runtime_commit($ordinaryState);
        recovery_check($ordinaryState['stage'] === 'cleanup'
            && !empty($ordinaryState['checkpoints']['runtime_committed'])
            && !is_file($root . '/maintenance.lock')
            && is_dir($ordinaryRoot)
            && is_file(\Gallery\Services\application_update_job_dir($ordinary['id'])
                . '/runtime-window-closed.json'),
            'The usable runtime was not committed before physical cleanup.');
        \Gallery\Services\application_update_control_pause($ordinary['id']);
        $pausedCleanup = \Gallery\Services\application_update_process_job($ordinary['id']);
        $publicCleanup = recovery_worker_probe($root, $token, $ordinary['id'], true);
        recovery_check($pausedCleanup['status'] === 'paused'
            && $pausedCleanup['stage'] === 'cleanup'
            && $publicCleanup['body'] === 'Ordinary request admitted.'
            && $publicCleanup['runtime_lease_held'],
            'Paused background cleanup still closed ordinary website requests.');
        recovery_refuses(static function () use ($ordinaryState): void {
            \Gallery\Services\application_update_assert_rollback_database($ordinaryState);
        }, 'Runtime commit retained an unverified isolated rollback claim.');
        \Gallery\Services\application_update_control_pause($ordinary['id'], true);
        $ordinaryResult = recovery_drain($ordinary['id']);
        recovery_check($ordinaryResult['status'] === 'completed'
            && $ordinaryResult['result']['version'] === '1.2.1'
            && file_get_contents($root . '/public/assets/styles/utilities.css')
                === $expectedOrdinaryCss
            && !is_file(\Gallery\Services\application_update_activation_gate_path()),
            'Ordinary background activation failed to finish after its reader drained: '
                . json_encode($ordinaryResult['error']));
        \Gallery\Services\application_update_process_job($ordinary['id']);
        $completionEvents = array_values(array_filter($GLOBALS['recovery_log_events'],
            static fn(array $event): bool => $event['event'] === 'update.autoupdate_completed'
                && $event['context']['job_id'] === $ordinary['id']));
        recovery_check(count($completionEvents) === 1
            && $completionEvents[0]['context']['installed_version'] === '1.2.1'
            && $completionEvents[0]['context']['previous_version'] === '1.2.0',
            'Automatic completion lacks exact versions or was logged again on a passive terminal read.');
        echo "Updater recovery integration tests passed.\n";
    } finally {
        $GLOBALS['recovery_pdo'] = null;
        if ($server !== null) { $server->exec('DROP DATABASE IF EXISTS `' . $database . '`'); }
        \GalleryWorkflow\removeFixture($root, $token);
    }
}
