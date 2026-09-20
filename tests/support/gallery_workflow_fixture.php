<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_workflow_fixture.php
 * Module Type: Test Fixture
 * Purpose: Own disposable workflow database and application-copy lifecycle.
 * Responsibilities:
 *   - Create and clean only explicitly isolated fixture resources.
 * Author: Rudolf Klusal
 * Own the lifecycle of a disposable database and application copy.
 */
declare(strict_types=1);

namespace GalleryWorkflow;

require_once __DIR__ . '/gallery_workflow_safety.php';

/** A fresh database and copied application; no active config or writable installation data is read. */
final class Fixture
{
    public string $token;
    public string $directory;
    public string $database;
    public string $url;
    public array $options;
    private ?\PDO $server = null;
    private bool $createdDatabase = false;
    private mixed $httpProcess = null;

    /** Validate explicit inputs before allocating any disposable resource. */
    public function __construct(private string $source, array $environment)
    {
        $this->options = databaseOptions($environment);
        foreach (['pdo_mysql', 'curl', 'gd', 'zip', 'dom', 'mbstring'] as $extension) {
            check(extension_loaded($extension), 'Required workflow PHP extension unavailable: ' . $extension);
        }
        $this->token = bin2hex(random_bytes(12));
        $this->database = 'gallery_workflow_' . $this->token;
        $this->directory = sys_get_temp_dir() . '/gallery-workflow-' . $this->token;
    }

    /** Create an owned database, migrate and seed the clone, and start its loopback server. */
    public function start(): void
    {
        check(@mkdir($this->directory, 0700), 'Could not allocate disposable fixture directory.');
        file_put_contents($this->directory . '/.workflow-owner', $this->token);
        validateFixture($this->directory, $this->token);
        $this->copyApplication();
        validateDatabaseName($this->database);
        $this->server = new \PDO('mysql:host=127.0.0.1;port=' . $this->options['port'] . ';charset=utf8mb4',
            $this->options['user'], $this->options['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        // Deliberately no IF NOT EXISTS: a collision must fail before acquiring cleanup ownership.
        $this->server->exec('CREATE DATABASE `' . $this->database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->createdDatabase = true;
        $port = freePort();
        $this->url = 'http://127.0.0.1:' . $port;
        $configuration = [
            'database' => $this->options + ['name' => $this->database],
            'base_url' => $this->url,
            'galleries_root' => $this->directory . '/galleries',
            'zip_cache_path' => $this->directory . '/cache/zips',
            'admin_session_name' => 'workflow_' . $this->token,
            'visitor_vote_secret' => bin2hex(random_bytes(32)),
            'setup_key' => bin2hex(random_bytes(32)),
            'auth' => ['persistent_login_enabled' => false, 'persistent_login_default_checked' => false],
            'google_login' => ['enabled' => false],
            'password_reset' => ['enabled' => false],
            'viewer_accounts' => ['enabled' => false],
            'language' => ['default' => 'en', 'available' => ['en']],
        ];
        file_put_contents($this->directory . '/config.php', "<?php\nreturn " . var_export($configuration, true) . ";\n");
        @chmod($this->directory . '/config.php', 0600);
        $this->run([PHP_BINARY, $this->source . '/tests/support/gallery_workflow_seed.php', $this->directory, $this->token], 120, 'migration and seed', true);
        copy($this->source . '/tests/support/gallery_workflow_router.php', $this->directory . '/router.php');
        copy($this->source . '/tests/support/gallery_workflow_browser.js', $this->directory . '/browser.js');
        $this->httpProcess = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1',
            '-d', 'session.save_path=' . $this->directory . '/sessions', '-d', 'disable_functions=mail',
            '-S', '127.0.0.1:' . $port, '-t', $this->directory . '/public', $this->directory . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/http.log', 'a'], 2 => ['file', $this->directory . '/http.log', 'a']],
            $pipes, $this->directory, $this->environment(), ['bypass_shell' => true]);
        check(is_resource($this->httpProcess), 'Could not start isolated PHP HTTP server.');
        fclose($pipes[0]);
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
            if ($socket) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(50000);
        }
        check($ready, 'Isolated PHP HTTP server did not become ready.');
        $probe = curl_init($this->url . '/__workflow_' . $this->token . '/health');
        curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '']);
        $identity = curl_exec($probe);
        curl_close($probe);
        check(is_string($identity) && hash_equals($this->token, $identity), 'Isolated HTTP server identity mismatch.');
        file_put_contents($this->directory . '/endpoint.json', json_encode(['url' => $this->url], JSON_THROW_ON_ERROR));
    }

    /** Copy executable source without production configuration or runtime data. */
    private function copyApplication(): void
    {
        // Copy source directories only. Reject links and omit site-specific assets, config and runtime data.
        foreach (['app', 'public', 'database'] as $subdirectory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->source . '/' . $subdirectory,
                \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                check(!$entry->isLink(), 'Application source contains a link; isolated copy refused.');
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($this->source) + 1));
                if ($relative === 'public/assets/custom.css' || str_starts_with($relative, 'public/assets/custom/')) {
                    continue;
                }
                $target = $this->directory . '/' . $relative;
                if ($entry->isDir()) {
                    if (!is_dir($target)) check(mkdir($target, 0700, true), 'Could not create fixture source directory.');
                } else {
                    if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
                    check(copy($entry->getPathname(), $target), 'Could not copy application source.');
                }
            }
        }
        foreach (['galleries', 'cache', 'data', 'sessions'] as $relative) mkdir($this->directory . '/' . $relative, 0700);
        copy($this->source . '/index.php', $this->directory . '/index.php');
    }

    /** Pass generated fixture identities and database settings to isolated children. */
    public function environment(): array
    {
        return array_merge(getenv(), [
            'GALLERY_WORKFLOW_FIXTURE' => $this->directory,
            'GALLERY_WORKFLOW_TOKEN' => $this->token,
            'GALLERY_TEST_MYSQL_DSN' => 'mysql:host=127.0.0.1;port=' . $this->options['port'] . ';dbname=' . $this->database . ';charset=utf8mb4',
            'GALLERY_TEST_MYSQL_USER' => $this->options['user'],
            'GALLERY_TEST_MYSQL_PASSWORD' => $this->options['password'],
        ]);
    }

    /** Child output stays private: SQL/PDO/HTTP failures may contain credentials or CSRF values. */
    public function run(array $command, int $timeout, string $label, bool $showSafeOutput = false): void
    {
        $log = $this->directory . '/child.log';
        // A shared file descriptor also avoids separate Windows stdout/stderr offsets overwriting evidence.
        $stream = fopen($log, 'w+b');
        check(is_resource($stream), 'Could not allocate private child diagnostic stream.');
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $stream, 2 => $stream],
            $pipes, $this->source, $this->environment(), ['bypass_shell' => true]);
        check(is_resource($process), 'Could not launch ' . $label . '.');
        fclose($pipes[0]);
        $deadline = microtime(true) + $timeout;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(50000);
        } while (microtime(true) < $deadline);
        if ($status['running']) proc_terminate($process);
        proc_close($process);
        fclose($stream);
        $output = (string) file_get_contents($log);
        if ($showSafeOutput) {
            // Only our deliberately bounded assertion records are eligible for console output.
            foreach (explode("\n", $output) as $line) {
                if (preg_match('/^(PASS|FAIL|SKIP|BLOCKED) gallery workflow [a-zA-Z0-9 .:_()-]+$/D', trim($line))) echo trim($line) . "\n";
            }
        }
        check(!$status['running'] && $status['exitcode'] === 0, $label . ' failed (private child output suppressed).');
    }

    /** Stop the owned server before dropping its database and removing its temporary directory. */
    public function close(): void
    {
        if (is_resource($this->httpProcess)) {
            proc_terminate($this->httpProcess);
            proc_close($this->httpProcess);
            $this->httpProcess = null;
        }
        if ($this->createdDatabase && $this->server) {
            validateFixture($this->directory, $this->token);
            validateDatabaseName($this->database);
            $this->server->exec('DROP DATABASE `' . $this->database . '`');
            $this->createdDatabase = false;
        }
        $this->server = null;
        if (is_dir($this->directory)) removeFixture($this->directory, $this->token);
    }
}

/** Obtain a currently free loopback port; startup must still verify the listener's identity. */
function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    check(is_resource($socket), 'Could not reserve a loopback port.');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}
