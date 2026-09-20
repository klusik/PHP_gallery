<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/session_contention_database.php
 * Module Type: Test Fixture
 * Purpose: Provision an optional private MySQL daemon for actual-route session evidence.
 * Responsibilities:
 *   - Reuse explicit workflow database guards or allocate only a new owned data directory.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Tests\SessionContention;

require_once __DIR__ . '/session_contention.php';
require_once __DIR__ . '/gallery_workflow_fixture.php';

/** Own only this test's optional daemon; application clones use the unchanged fixture allocator. */
final class RouteDatabase
{
    /**
     * Pass disposable database inputs privately to the clone allocator and workers.
     * @var array<string,string> Child environment, including ephemeral credentials; never printed.
     */
    public array $environment;
    /**
     * Identify this instance's cleanup root independently of application storage.
     * @var string Newly allocated direct temporary child, or empty for existing runner inputs.
     */
    private string $directory = '';
    /**
     * Bind deletion authority to the marker in the newly created temporary directory.
     * @var string Random owner token retained inside the fixture, never printed.
     */
    private string $owner = '';
    /**
     * Retain the only daemon process this instance may terminate.
     * @var resource|null Exact owned child handle, or null when reusing dedicated runner inputs.
     */
    private mixed $process = null;
    /**
     * Permit shutdown only after the private server's data directory identity is verified.
     * @var ?\PDO Identity-verified disposable connection, never a site connection; null before verification.
     */
    private ?\PDO $server = null;

    /**
     * Retain explicit disposable inputs without connecting to any database.
     * @param array<string,string> $environment Caller environment with test opt-in.
     * @return void Startup is explicit.
     */
    public function __construct(array $environment)
    {
        $this->environment = $environment;
    }

    /**
     * Prefer dedicated runner inputs; otherwise initialize a fresh private MySQL directory.
     * @return void The private account is created only after verifying @@datadir.
     */
    public function start(): void
    {
        if (($this->environment['GALLERY_WORKFLOW_ENABLE'] ?? '') === 'disposable-only') {
            \GalleryWorkflow\databaseOptions($this->environment);
            return;
        }
        check(($this->environment['GALLERY_SESSION_ENABLE'] ?? '') === 'disposable-only', 'Explicit session database opt-in required.');
        $binary = (string) ($this->environment['GALLERY_SESSION_MYSQL_BIN'] ?? '');
        check(is_file($binary) && in_array(strtolower(basename($binary)), ['mysqld.exe', 'mysqld'], true), 'Private session MySQL executable unavailable.');
        $this->owner = bin2hex(random_bytes(16));
        $this->directory = sys_get_temp_dir() . '/gallery-session-' . $this->owner;
        check(mkdir($this->directory, 0700), 'Private session database directory creation failed.');
        check(file_put_contents($this->directory . '/.session-owner', $this->owner) !== false, 'Private session database marker creation failed.');
        $this->directory = owned_directory($this->directory, $this->owner);
        $stream = fopen($this->directory . '/initialize.log', 'w+b');
        check(is_resource($stream), 'Private session database initialization log failed.');
        $initializer = proc_open([$binary, '--no-defaults', '--initialize-insecure', '--datadir=' . $this->directory . '/mysql-data'],
            [0 => ['pipe', 'r'], 1 => $stream, 2 => $stream], $pipes, $this->directory, $this->environment, ['bypass_shell' => true]);
        check(is_resource($initializer), 'Private session database initializer failed.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 120;
        do {
            $status = proc_get_status($initializer);
            if (!$status['running']) {
                break;
            }
            usleep(POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($initializer);
        }
        proc_close($initializer);
        fclose($stream);
        check(!$status['running'] && $status['exitcode'] === 0, 'Private session database initialization failed.');
        $port = \GalleryWorkflow\freePort();
        $this->process = proc_open([$binary, '--no-defaults', '--datadir=' . $this->directory . '/mysql-data',
            '--port=' . $port, '--bind-address=127.0.0.1', '--mysqlx=0', '--skip-log-bin',
            '--socket=' . $this->directory . '/mysql.sock', '--pid-file=' . $this->directory . '/mysql.pid',
            '--log-error=' . $this->directory . '/server.log'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/daemon.log', 'a'],
                2 => ['file', $this->directory . '/daemon.log', 'a']], $pipes, $this->directory, $this->environment, ['bypass_shell' => true]);
        check(is_resource($this->process), 'Private session database launch failed.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 20;
        do {
            try {
                $connection = new \PDO('mysql:host=127.0.0.1;port=' . $port, 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                check(realpath((string) $connection->query('SELECT @@datadir')->fetchColumn()) === realpath($this->directory . '/mysql-data'),
                    'Private session database identity mismatch.');
                $this->server = $connection;
                break;
            } catch (\PDOException) {
                usleep(POLL_MICROSECONDS);
            }
        } while (microtime(true) < $deadline);
        check($this->server instanceof \PDO, 'Private session database readiness failed.');
        $password = bin2hex(random_bytes(24));
        $this->server->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY " . $this->server->quote(bin2hex(random_bytes(24))));
        $this->server->exec("CREATE USER 'gallery_workflow_runner'@'localhost' IDENTIFIED BY " . $this->server->quote($password));
        $this->server->exec('GRANT ALL PRIVILEGES ON ' . chr(96) . 'gallery\\_workflow\\_%' . chr(96) . ".* TO 'gallery_workflow_runner'@'localhost'");
        $this->environment = array_merge($this->environment, [
            'GALLERY_WORKFLOW_ENABLE' => 'disposable-only', 'GALLERY_WORKFLOW_DB_HOST' => '127.0.0.1',
            'GALLERY_WORKFLOW_DB_PORT' => (string) $port, 'GALLERY_WORKFLOW_DB_USER' => 'gallery_workflow_runner',
            'GALLERY_WORKFLOW_DB_PASSWORD' => $password,
        ]);
    }

    /**
     * Stop only the verified owned daemon and remove its marker-validated directory.
     * @return void An externally supplied dedicated runner is never stopped.
     */
    public function close(): void
    {
        if ($this->server !== null) {
            try {
                $this->server->exec('SHUTDOWN');
            } catch (\Throwable) {
                // Fall through to the exact owned process handle, never an enumerated daemon.
            }
            $this->server = null;
        }
        if (is_resource($this->process)) {
            $deadline = microtime(true) + 15;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
                usleep(POLL_MICROSECONDS);
            }
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
            $this->process = null;
        }
        if ($this->directory !== '' && is_dir($this->directory)) {
            remove_directory($this->directory, $this->owner);
        }
    }
}
