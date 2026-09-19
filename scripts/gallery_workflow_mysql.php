<?php
/**
 * Project: PHP Gallery
 * Author: Rudolf Klusal
 * Start a private MySQL 8 data directory and run only new workflow development checks.
 * Never reads my.ini, connects to port 3306, or uses an existing server data directory.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!is_file(__DIR__ . '/../tests/support/gallery_workflow_fixture.php')) {
    fwrite(STDERR, "BLOCKED gallery workflow source checkout with test support required\n");
    exit(1);
}
require_once __DIR__ . '/../tests/support/gallery_workflow_fixture.php';

use function GalleryWorkflow\check;
use function GalleryWorkflow\freePort;
use function GalleryWorkflow\removeFixture;

/** Run one bounded child without forwarding potentially sensitive server diagnostics. */
function gallery_workflow_mysql_child(array $command, string $directory, array $environment, int $timeout, bool $reports = false): void
{
    $stream = fopen($directory . '/command.log', 'w+b');
    check(is_resource($stream), 'Could not allocate private MySQL diagnostic stream.');
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $stream, 2 => $stream],
        $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true]);
    check(is_resource($process), 'Private MySQL child launch failed.');
    fclose($pipes[0]);
    $deadline = microtime(true) + $timeout;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) break;
        usleep(100000);
    } while (microtime(true) < $deadline);
    if ($status['running']) proc_terminate($process);
    proc_close($process);
    fclose($stream);
    if ($reports) {
        foreach (file($directory . '/command.log', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^(PASS|FAIL|SKIP|BLOCKED) gallery workflow [a-zA-Z0-9 .:_()-]+$/D', trim($line))) echo trim($line) . "\n";
        }
    }
    check(!$status['running'] && $status['exitcode'] === 0, 'Private MySQL child failed.');
}

$directory = '';
$process = null;
$pdo = null;
$serverVerified = false;
$exit = 0;
$stage = 'local MySQL prerequisites';
try {
    check(in_array($argv[1] ?? '', ['--development', '--audit'], true), 'Choose development checks or the central audit.');
    check(getenv('GALLERY_WORKFLOW_ENABLE') === 'disposable-only', 'Explicit disposable opt-in required.');
    $binary = (string) getenv('GALLERY_WORKFLOW_MYSQL_BIN');
    check(is_file($binary) && in_array(strtolower(basename($binary)), ['mysqld.exe', 'mysqld'], true), 'A MySQL 8 server executable is required.');
    $token = bin2hex(random_bytes(12));
    $directory = sys_get_temp_dir() . '/gallery-workflow-' . $token;
    check(mkdir($directory, 0700), 'Could not allocate private MySQL directory.');
    file_put_contents($directory . '/.workflow-owner', $token);
    $stage = 'private MySQL initialization';
    gallery_workflow_mysql_child([$binary, '--no-defaults', '--initialize-insecure', '--datadir=' . $directory . '/mysql-data',
        '--log-error=' . $directory . '/initialize.log'], $directory, getenv(), 120);
    $port = freePort();
    $stage = 'private MySQL startup';
    $process = proc_open([$binary, '--no-defaults', '--datadir=' . $directory . '/mysql-data', '--port=' . $port,
        '--bind-address=127.0.0.1', '--mysqlx=0', '--skip-log-bin',
        '--socket=' . $directory . '/mysql.sock', '--pid-file=' . $directory . '/mysql.pid', '--log-error=' . $directory . '/server.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/daemon.log', 'a'], 2 => ['file', $directory . '/daemon.log', 'a']],
        $pipes, $directory, getenv(), ['bypass_shell' => true]);
    check(is_resource($process), 'Could not launch private MySQL process.');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 200; $attempt++) {
        try {
            $pdo = new PDO('mysql:host=127.0.0.1;port=' . $port, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            break;
        } catch (Throwable) {
            usleep(100000);
        }
    }
    check($pdo instanceof PDO, 'Private MySQL did not become ready.');
    // Prove this is the daemon just initialized before any credential or grant write.
    check(realpath((string) $pdo->query('SELECT @@datadir')->fetchColumn()) === realpath($directory . '/mysql-data'), 'Private MySQL data-directory identity mismatch.');
    $serverVerified = true;
    $stage = 'private MySQL account provisioning';
    $password = bin2hex(random_bytes(24));
    $pdo->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY " . $pdo->quote(bin2hex(random_bytes(24))));
    $pdo->exec("CREATE USER 'gallery_workflow_runner'@'localhost' IDENTIFIED BY " . $pdo->quote($password));
    $pdo->exec("GRANT ALL PRIVILEGES ON `gallery\\_workflow\\_%`.* TO 'gallery_workflow_runner'@'localhost'");
    $environment = array_merge(getenv(), ['GALLERY_WORKFLOW_DB_HOST' => '127.0.0.1', 'GALLERY_WORKFLOW_DB_PORT' => (string) $port,
        'GALLERY_WORKFLOW_DB_USER' => 'gallery_workflow_runner', 'GALLERY_WORKFLOW_DB_PASSWORD' => $password]);
    echo "PASS gallery workflow private MySQL initialized with dedicated account\n";
    $stage = $argv[1] === '--audit' ? 'central audit with disposable database' : 'new workflow development checks';
    gallery_workflow_mysql_child([PHP_BINARY, __DIR__ . '/gallery_workflow_run.php', $argv[1]], $directory, $environment,
        $argv[1] === '--audit' ? 1320 : 480, true);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL gallery workflow ' . $stage . ' at line ' . $exception->getLine() . ' code ' . (string) $exception->getCode() . "\n");
    $exit = 1;
} finally {
    // SHUTDOWN only over the identity-checked connection; terminate only the exact child handle otherwise.
    if ($serverVerified && $pdo instanceof PDO) {
        try { $pdo->exec('SHUTDOWN'); } catch (Throwable) { /* Fall through to owned process termination. */ }
        $pdo = null;
    }
    $pdo = null;
    if (is_resource($process)) {
        $deadline = microtime(true) + 15;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(100000);
        if (proc_get_status($process)['running']) proc_terminate($process);
        proc_close($process);
    }
    if ($directory !== '' && is_dir($directory)) {
        try { removeFixture($directory, $token); echo "PASS gallery workflow private MySQL stopped and removed\n"; }
        catch (Throwable) { fwrite(STDERR, "FAIL gallery workflow private MySQL cleanup\n"); $exit = 1; }
    }
}
exit($exit);
