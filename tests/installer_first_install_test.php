<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/installer_first_install_test.php
 * Module Type: Integration Test
 * Purpose: Complete the standalone browser installer before configuration exists.
 * Responsibilities:
 *   - Exercise actual installer forms and the complete migration chain in owned resources.
 *   - Verify busy-lock refusal, migration replay, administrator creation and installation locking.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_fixture.php';

use function GalleryWorkflow\check;
use function GalleryWorkflow\databaseOptions;
use function GalleryWorkflow\freePort;
use function GalleryWorkflow\removeFixture;
use function GalleryWorkflow\validateDatabaseName;

/**
 * Pace readiness probes for the owned loopback installer server.
 * @var int
 * Units: microseconds per probe. Scope: at most 100 startup probes in this fixture.
 * Consumers: the installer HTTP readiness loop.
 * Rationale: allow five seconds of requested startup pauses without a busy loop.
 */
const INSTALLER_FIXTURE_READY_DELAY_US = 50_000;

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " standalone installer requires disposable MySQL fixture\n";
    exit($required ? 1 : 0);
}

/**
 * Request the actual installer with one cookie-isolated loopback client.
 * @param CurlHandle $client Reusable client with an in-memory cookie jar.
 * @param string $origin Owned literal loopback server origin.
 * @param array<string,string>|null $fields Form bindings, or null for GET.
 * @return array{status:int,body:string,token:string} HTTP evidence and the next rendered CSRF token.
 */
function installer_fixture_request(CurlHandle $client, string $origin, ?array $fields = null): array
{
    curl_setopt($client, CURLOPT_URL, $origin . '/install.php');
    if ($fields === null) {
        curl_setopt($client, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($client, CURLOPT_POST, true);
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = curl_exec($client);
    check(is_string($body), 'Installer fixture HTTP request failed.');
    preg_match('/name="token" value="([a-f0-9]{32})"/', $body, $match);
    return ['status' => (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE), 'body' => $body, 'token' => $match[1] ?? ''];
}

$directory = '';
$server = null;
$pdo = null;
$http = null;
$databaseCreated = false;
$lease = null;
$exit = 0;
try {
    $options = databaseOptions(getenv());
    $token = bin2hex(random_bytes(12));
    $database = 'gallery_workflow_' . $token;
    validateDatabaseName($database);
    $directory = sys_get_temp_dir() . '/gallery-workflow-' . $token;
    check(mkdir($directory, 0700), 'Could not allocate installer fixture.');
    check(file_put_contents($directory . '/.workflow-owner', $token) === strlen($token), 'Installer ownership marker failed.');
    $source = dirname(__DIR__);
    foreach (['app', 'database'] as $part) {
        check(mkdir($directory . '/' . $part, 0700), 'Installer source root copy failed.');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/' . $part,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            check(!$entry->isLink(), 'Installer source copy refuses links.');
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            $target = $directory . '/' . $relative;
            if ($entry->isDir()) {
                check(mkdir($target, 0700, true), 'Installer source directory copy failed.');
            } else {
                check(copy($entry->getPathname(), $target), 'Installer source file copy failed.');
            }
        }
    }
    check(copy($source . '/install.php', $directory . '/install.php'), 'Installer entry-point copy failed.');
    check(mkdir($directory . '/sessions', 0700), 'Installer session storage failed.');
    check(!file_exists($directory . '/config.php'), 'Installer fixture must begin without configuration.');
    $server = new PDO('mysql:host=127.0.0.1;port=' . $options['port'] . ';charset=utf8mb4',
        $options['user'], $options['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $databaseCreated = true;
    $pdo = new PDO('mysql:host=127.0.0.1;port=' . $options['port'] . ';dbname=' . $database . ';charset=utf8mb4',
        $options['user'], $options['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    check($pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'Installer database identity mismatch.');
    $port = freePort();
    $origin = 'http://127.0.0.1:' . $port;
    $http = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-d', 'session.save_path=' . $directory . '/sessions', '-S', '127.0.0.1:' . $port, '-t', $directory],
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/http.log', 'a'], 2 => ['file', $directory . '/http.log', 'a']],
        $pipes, $directory, getenv(), ['bypass_shell' => true]);
    check(is_resource($http), 'Installer HTTP server failed to start.');
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(INSTALLER_FIXTURE_READY_DELAY_US);
    }
    check($ready, 'Installer HTTP readiness timed out.');
    $client = curl_init();
    curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP]);
    $first = installer_fixture_request($client, $origin);
    check($first['status'] === 200 && $first['token'] !== '', 'Fresh installer form did not load.');
    $databaseFields = ['token' => $first['token'], 'installer_action' => 'database_step', 'site_name' => 'Installer fixture',
        'base_url' => $origin, 'db_host' => '127.0.0.1', 'db_port' => (string) $options['port'],
        'db_name' => $database, 'db_user' => $options['user'], 'db_password' => $options['password']];
    $invalid = installer_fixture_request($client, $origin, array_replace($databaseFields, ['token' => 'invalid']));
    check(str_contains($invalid['body'], 'Invalid installer token.') && !is_file($directory . '/config.php'), 'Installer CSRF refusal changed.');
    check($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) === [], 'First installer step changed schema.');
    $verified = installer_fixture_request($client, $origin, $databaseFields);
    check($verified['status'] === 200 && str_contains($verified['body'], 'Verified database') && $verified['token'] !== '',
        'Installer database step did not advance.');
    $password = bin2hex(random_bytes(16));
    $adminFields = ['token' => $verified['token'], 'installer_action' => 'admin_step', 'admin_username' => 'installer_admin',
        'admin_password' => $password, 'admin_password_confirm' => $password];

    // A peer holds exactly the canonical editor lock while earlier DDL persists.
    // The callback must refuse before its writes; the same form then recovers.
    require_once $source . '/app/models/gallery_edit_concurrency.php';
    $lease = \Gallery\Models\gallery_edit_model_lock($pdo);
    check(is_string($lease), 'Installer peer lock could not be acquired.');
    $blocked = installer_fixture_request($client, $origin, $adminFields);
    check(str_contains($blocked['body'], 'Another gallery operation is still running.')
        && !is_file($directory . '/config.php') && !is_file($directory . '/cache/installed.lock'), 'Busy migration did not remain retryable.');
    $version = '202610020001_gallery_description_layout_semantics';
    $ledger = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = ?');
    $ledger->execute([$version]);
    check((int) $ledger->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0,
        'Failed callback recorded completion or created an administrator.');
    \Gallery\Models\gallery_edit_model_release($lease, $pdo);
    $lease = null;
    $finished = installer_fixture_request($client, $origin, $adminFields);
    check($finished['status'] === 200 && str_contains($finished['body'], 'Installation finished.')
        && !str_contains($finished['body'], 'Call to undefined function'), 'Standalone installer failed to complete its migration retry.');
    check(is_file($directory . '/config.php') && is_file($directory . '/cache/installed.lock')
        && is_dir($directory . '/galleries') && is_dir($directory . '/cache/zips'), 'Installer final files or folders are missing.');
    $versions = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
    $expected = array_map(static fn (string $path): string => basename($path, '.php'), glob($directory . '/database/migrations/*.php') ?: []);
    sort($expected, SORT_STRING);
    check($versions === $expected, 'Installer did not record the complete migration chain exactly once.');
    $admin = $pdo->query('SELECT username, password_hash, role FROM users')->fetchAll(PDO::FETCH_ASSOC);
    check(count($admin) === 1 && $admin[0]['username'] === 'installer_admin' && $admin[0]['role'] === 'admin'
        && password_verify($password, $admin[0]['password_hash']), 'Installed administrator credentials are invalid.');
    check($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'gallery_description_layout_semantics_version'")->fetchColumn() === '2'
        && (int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key IN ('theme_gallery_description_layout', 'tag_page_gallery_description_layout')")->fetchColumn() === 0,
        'Fresh installation introduced legacy layout overrides.');
    check((int) $pdo->query('SELECT IS_FREE_LOCK(' . $pdo->quote(hash('sha256', $database . \Gallery\Core\GALLERY_EDIT_LOCK_SUFFIX)) . ')')->fetchColumn() === 1,
        'Installer retained the migration writer lock.');
    $configHash = hash_file('sha256', $directory . '/config.php');
    $locked = installer_fixture_request($client, $origin, $adminFields);
    check($locked['status'] === 403 && str_contains($locked['body'], 'Installer locked')
        && hash_file('sha256', $directory . '/config.php') === $configHash, 'Completed installation could be overwritten.');
    unset($client);
    echo "PASS standalone installer empty database, CSRF, busy callback, retry, complete migrations, admin credentials and locked completion\n";
} catch (Throwable $error) {
    $reason = $error instanceof PDOException ? 'Database operation failed.' : $error->getMessage();
    fwrite(STDERR, 'FAIL standalone installer at line ' . $error->getLine() . ': ' . $reason . "\n");
    $exit = 1;
} finally {
    if (is_resource($http)) {
        proc_terminate($http);
        proc_close($http);
    }
    if ($lease !== null && $pdo instanceof PDO) \Gallery\Models\gallery_edit_model_release($lease, $pdo);
    $pdo = null;
    if ($databaseCreated && $server instanceof PDO) {
        validateDatabaseName($database);
        $server->exec('DROP DATABASE `' . $database . '`');
    }
    $server = null;
    if ($directory !== '' && is_dir($directory)) removeFixture($directory, $token);
}
exit($exit);
