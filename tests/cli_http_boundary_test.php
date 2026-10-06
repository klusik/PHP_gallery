<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cli_http_boundary_test.php
 * Module Type: Regression Test
 * Purpose: Verify CLI entrypoints and internal paths fail closed over HTTP.
 * Responsibilities:
 *   - Exercise real HTTP requests without rewrite routing.
 *   - Keep all fixture files and server state disposable.
 * Author: Rudolf Klusal
 * License: MIT License
 */

declare(strict_types=1);

/** Stop the fixture after recording a failed behavior assertion.
 * @param bool $condition Whether the behavior matched its contract.
 * @param string $message Safe assertion failure description.
 * @return void Does not return when the assertion fails.
 */
function cli_http_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Return an unused loopback TCP port for an isolated test server.
 * @return int Available port number.
 */
function cli_http_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    cli_http_check(is_resource($socket), 'Could not reserve an isolated HTTP port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    cli_http_check(is_string($address) && preg_match('/:(\d+)$/D', $address, $matches) === 1,
        'Could not determine the isolated HTTP port.');
    return (int) $matches[1];
}

/** Recursively copy source files selected by the fixture callback.
 * @param string $source Source directory.
 * @param string $destination Destination directory.
 * @param callable(string):bool $include Predicate for files to copy.
 * @return void Copies only selected regular files.
 */
function cli_http_copy_tree(string $source, string $destination, callable $include): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
        $target = $destination . '/' . $relative;
        if ($entry->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0700, true);
            }
            continue;
        }
        if (!$include($relative)) {
            continue;
        }
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        cli_http_check(copy($entry->getPathname(), $target), 'Could not copy isolated HTTP fixture source.');
    }
}

/** Start a child process and return its owned process resource and stdin pipe.
 * @param array<int,string> $command Process command and arguments.
 * @param string $workingDirectory Child working directory.
 * @param array<string,string>|false $environment Child environment values.
 * @param string $logPath Combined stdout and stderr destination.
 * @return array{0:resource,1:array<int,resource>} Process and its remaining pipes.
 */
function cli_http_start_process(array $command, string $workingDirectory, array $environment, string $logPath): array
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'ab'],
        2 => ['file', $logPath, 'ab'],
    ], $pipes, $workingDirectory, $environment, ['bypass_shell' => true]);
    cli_http_check(is_resource($process), 'Could not start an isolated HTTP server.');
    fclose($pipes[0]);
    return [$process, $pipes];
}

/** Wait until the isolated server accepts loopback connections.
 * @param int $port Listening port.
 * @param resource $process Owned process resource.
 * @param int $attempts Maximum readiness checks.
 * @return void Returns when a connection succeeds or throws on timeout/exit.
 */
function cli_http_wait_ready(int $port, mixed $process, int $attempts = 80): void
{
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $status = proc_get_status($process);
        cli_http_check($status['running'], 'The isolated HTTP server exited before becoming ready.');
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            return;
        }
        usleep(50000);
    }
    throw new RuntimeException('The isolated HTTP server did not become ready.');
}

/** Return the HTTP status and body from a bounded loopback request.
 * @param string $url Loopback URL.
 * @param string $method HTTP method.
 * @return array{status:int,body:string,headers:array<int,string>} Response evidence.
 */
function cli_http_request(string $url, string $method = 'GET'): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'timeout' => 8,
        'ignore_errors' => true,
        'follow_location' => 0,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            $status = (int) $matches[1];
        }
    }
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $headers];
}

/** Remove only a temporary directory carrying this test's exact owner marker.
 * @param string $directory Candidate fixture directory.
 * @param string $token Expected fixture owner token.
 * @return void Removes only the verified owned tree.
 */
function cli_http_remove_owned_tree(string $directory, string $token): void
{
    $marker = $directory . '/.cli-http-owner';
    if (!is_file($marker) || !hash_equals($token, (string) file_get_contents($marker))) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($directory);
}

$root = dirname(__DIR__);
$token = bin2hex(random_bytes(12));
$fixture = sys_get_temp_dir() . '/php-gallery-cli-http-' . $token;
$server = null;
$apache = null;
$testFailed = false;
$apacheCoverageComplete = false;

try {
    cli_http_check(mkdir($fixture, 0700), 'Could not create isolated HTTP fixture directory.');
    file_put_contents($fixture . '/.cli-http-owner', $token);
    foreach (['app', 'scripts'] as $tree) {
        cli_http_copy_tree($root . '/' . $tree, $fixture . '/' . $tree,
            static fn(string $relative): bool => str_ends_with($relative, '.php') || basename($relative) === '.htaccess');
    }
    cli_http_copy_tree($root . '/public', $fixture . '/public',
        static fn(string $relative): bool => str_ends_with($relative, '.php') || basename($relative) === '.htaccess');
    copy($root . '/index.php', $fixture . '/index.php');
    copy($root . '/config.example.php', $fixture . '/config.example.php');
    file_put_contents($fixture . '/query-route-fixture.php', "<?php\nrequire __DIR__ . '/app/bootstrap.php';\n\$route = \\Gallery\\Core\\cms_route_from_request();\nheader('Content-Type: text/plain; charset=utf-8');\necho (string) (\$route['page'] ?? '');\n");
    if (is_file($root . '/.htaccess')) {
        copy($root . '/.htaccess', $fixture . '/.htaccess');
    }
    mkdir($fixture . '/cache', 0700);
    mkdir($fixture . '/data', 0700);
    mkdir($fixture . '/galleries', 0700);
    mkdir($fixture . '/sessions', 0700);

    $bootstrapMarker = $fixture . '/bootstrap-reached.marker';
    file_put_contents($fixture . '/config.php', "<?php\nfile_put_contents(" . var_export($bootstrapMarker, true)
        . ", 'loaded');\nreturn ['base_url' => 'http://127.0.0.1', 'setup_key' => 'fixture-only', 'database' => ['host' => '127.0.0.1', 'port' => 1, 'name' => 'cli_http_fixture_"
        . $token . "', 'user' => 'fixture', 'password' => 'fixture', 'charset' => 'utf8mb4'], 'galleries_root' => __DIR__ . '/galleries', 'zip_cache_path' => __DIR__ . '/cache/zips'];\n");
    $config = file_get_contents($fixture . '/config.php');
    cli_http_check(is_string($config), 'Could not read isolated fixture configuration.');
    $config = str_replace("'host' => '127.0.0.1', 'port' => 1", "'host' => '127.0.0.1;port=1', 'port' => 1", $config);
    file_put_contents($fixture . '/config.php', $config);

    $port = cli_http_free_port();
    [$server] = cli_http_start_process([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-d', 'session.save_path=' . $fixture . '/sessions', '-S', '127.0.0.1:' . $port, '-t', $fixture],
        $fixture, getenv(), $fixture . '/php-server.log');
    cli_http_wait_ready($port, $server);

    $guardedScripts = [];
    $scriptIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture . '/scripts', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($scriptIterator as $scriptFile) {
        if (!$scriptFile->isFile() || strtolower($scriptFile->getExtension()) !== 'php') {
            continue;
        }
        $scriptSource = file_get_contents($scriptFile->getPathname());
        if (is_string($scriptSource)
            && (str_contains($scriptSource, 'gallery_require_cli_sapi();')
                || str_contains($scriptSource, 'gallery_guard_cli_entrypoint(__FILE__)')
                || $scriptFile->getFilename() === 'cli_guard.php')) {
            $guardedScripts[] = str_replace('\\', '/', substr($scriptFile->getPathname(), strlen($fixture) + 1));
        }
    }
    $expectedCliScripts = [
        'application_update.php', 'cooperative_renew.php', 'create_admin.php', 'migrate.php',
        'reconcile_admin_operations.php', 'reconcile_image_moves.php', 'recovery.php', 'site_maintenance.php',
        'telemetry_maintenance.php', 'check_release.php', 'generate_manifest.php', 'prepare_release.php',
        'release_qualification.php', 'audit.php', 'audit_route_probe.php', 'audit_runtime_probe.php',
        'benchmark_title_completion.php', 'check_admin_mutation_contracts.php', 'gallery_workflow_ci.php',
        'gallery_workflow_mysql.php', 'gallery_workflow_run.php', 'audit_route_performance.php',
        'check_mvc_boundaries.php', 'check_policy_constants.php', 'check_python_import_policy.php',
        'check_source_documentation.php', 'generate_source_debt_baseline.php', 'generate_runtime_modules.php', 'runtime_dependencies.php',
        'runtime_dynamic_dependencies.php', 'recovery/cli.php', 'release_qualification/cli.php', 'cli_guard.php',
    ];
    foreach ($expectedCliScripts as $expectedCliScript) {
        cli_http_check(is_file($fixture . '/scripts/' . $expectedCliScript),
            'Documented CLI entrypoint is missing from the fixture: scripts/' . $expectedCliScript . '.');
        $guardedScripts[] = 'scripts/' . $expectedCliScript;
    }
    $guardedScripts = array_values(array_unique($guardedScripts));
    foreach ($guardedScripts as $relativeScript) {
        $blocked = cli_http_request('http://127.0.0.1:' . $port . '/' . $relativeScript);
        cli_http_check($blocked['status'] === 404 && $blocked['body'] === '',
            'Direct HTTP access to guarded CLI script ' . $relativeScript . ' must return an empty 404.');
    }
    cli_http_check(!is_file($bootstrapMarker),
        'A rejected CLI HTTP request must stop before loading config or application bootstrap.');

    proc_terminate($server);
    proc_close($server);
    $server = null;
    $port = cli_http_free_port();
    [$server] = cli_http_start_process([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-d', 'session.save_path=' . $fixture . '/sessions', '-S', '127.0.0.1:' . $port, '-t', $fixture],
        $fixture, getenv(), $fixture . '/php-server-query.log');
    cli_http_wait_ready($port, $server);
    unlink($fixture . '/config.php');
    $public = cli_http_request('http://127.0.0.1:' . $port . '/index.php?page=robots');
    $location = '';
    foreach ($public['headers'] as $header) {
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, strlen('Location:')));
        }
    }
    cli_http_check($public['status'] === 302 && str_ends_with($location, 'install.php'),
        'The public query-string entry must remain reachable without rewrite routing and preserve first-run behavior.');
    $queryRoute = cli_http_request('http://127.0.0.1:' . $port . '/query-route-fixture.php?page=robots');
    cli_http_check($queryRoute['status'] === 200 && trim($queryRoute['body']) === 'robots',
        'The production route parser must resolve a GET query-string route without database startup.');
    $serverStatus = is_resource($server) ? proc_get_status($server) : ['running' => false];
    cli_http_check(!empty($serverStatus['running']),
        'The public fixture server must remain available after query-string requests.');

    $debtBaselinePath = $root . '/scripts/source_contract_debt_baseline.json';
    $debtBaselineBefore = is_file($debtBaselinePath) ? file_get_contents($debtBaselinePath) : false;
    foreach (['generate_manifest.php', 'generate_source_debt_baseline.php'] as $helpScript) {
        $helpProcess = proc_open([PHP_BINARY, $root . '/scripts/' . $helpScript, '--help'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $helpPipes, $root, getenv(), ['bypass_shell' => true]);
        cli_http_check(is_resource($helpProcess), 'Could not start the guarded read-only CLI help.');
        fclose($helpPipes[0]);
        $helpOutput = stream_get_contents($helpPipes[1]) . stream_get_contents($helpPipes[2]);
        fclose($helpPipes[1]);
        fclose($helpPipes[2]);
        cli_http_check(proc_close($helpProcess) === 0 && str_contains($helpOutput, 'Usage: php scripts/' . $helpScript),
            'CLI help must remain usable after the HTTP guard: ' . $helpScript);
    }
    cli_http_check((is_file($debtBaselinePath) ? file_get_contents($debtBaselinePath) : false) === $debtBaselineBefore,
        'Debt CLI help must not initialize or refresh category budgets.');

    if ($server !== null && is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
        $server = null;
    }

    $apachePath = trim((string) (getenv('PHP_GALLERY_APACHE') ?: ''));
    $phpModule = trim((string) (getenv('PHP_GALLERY_APACHE_PHP_MODULE') ?: ''));
    $apacheRequired = getenv('PHP_GALLERY_APACHE_REQUIRED') === '1';
    if ($apachePath === '' || !is_file($apachePath)) {
        if ($apacheRequired) {
            throw new RuntimeException('Apache coverage is required, but PHP_GALLERY_APACHE does not name httpd.');
        }
        $apacheCoverageComplete = false;
    } else {
        $apacheRoot = dirname(dirname($apachePath));
        $modules = trim((string) (getenv('PHP_GALLERY_APACHE_MODULE_DIR') ?: ($apacheRoot . '/modules')));
        $apachePort = cli_http_free_port();
        $probeMarker = $fixture . '/protected-tree-probe-executed.marker';
        $protectedTrees = ['app', 'database', 'scripts', 'cache', 'data', 'logs', 'tmp', 'tests', 'deploy', 'docs', '.github', '.agents', 'winapp'];
        foreach ($protectedTrees as $tree) {
            $treeDirectory = $fixture . '/' . $tree;
            if (!is_dir($treeDirectory)) {
                mkdir($treeDirectory, 0700, true);
            }
            $treeHtaccess = $root . '/' . $tree . '/.htaccess';
            if (is_file($treeHtaccess)) {
                copy($treeHtaccess, $treeDirectory . '/.htaccess');
            }
            file_put_contents($treeDirectory . '/http-boundary-probe.php', "<?php\nfile_put_contents("
                . var_export($probeMarker, true) . ", 'executed');\n");
        }

        $loadModules = [
            'authz_core_module' => 'mod_authz_core.so',
            'authz_host_module' => 'mod_authz_host.so',
            'access_compat_module' => 'mod_access_compat.so',
            'alias_module' => 'mod_alias.so',
            'dir_module' => 'mod_dir.so',
            'mime_module' => 'mod_mime.so',
        ];
        $mpmModules = PHP_OS_FAMILY === 'Windows'
            ? ['mpm_winnt_module' => 'mod_mpm_winnt.so']
            : ['mpm_event_module' => 'mod_mpm_event.so', 'mpm_prefork_module' => 'mod_mpm_prefork.so'];
        foreach ($mpmModules as $module => $file) {
            if (is_file($modules . '/' . $file)) {
                $loadModules = [$module => $file] + $loadModules;
                break;
            }
        }
        $configLines = [
            'ServerRoot "' . str_replace('\\', '/', $apacheRoot) . '"',
            'Listen 127.0.0.1:' . $apachePort,
            'ServerName 127.0.0.1',
            'PidFile "' . str_replace('\\', '/', $fixture . '/httpd.pid') . '"',
            'ErrorLog "' . str_replace('\\', '/', $fixture . '/httpd-error.log') . '"',
            'LogLevel warn',
        ];
        foreach ($loadModules as $module => $file) {
            cli_http_check(is_file($modules . '/' . $file), 'Required Apache module is missing: ' . $file);
            $configLines[] = 'LoadModule ' . $module . ' "' . str_replace('\\', '/', $modules . '/' . $file) . '"';
        }
        if ($phpModule !== '' && is_file($phpModule)) {
            $configLines[] = 'LoadModule php_module "' . str_replace('\\', '/', $phpModule) . '"';
            $configLines[] = 'AddHandler application/x-httpd-php .php';
            $configLines[] = 'PHPIniDir "' . str_replace('\\', '/', dirname($phpModule)) . '"';
        }
        $configLines[] = 'DocumentRoot "' . str_replace('\\', '/', $fixture) . '"';
        $configLines[] = '<Directory "' . str_replace('\\', '/', $fixture) . '">';
        $configLines[] = '    Options FollowSymLinks';
        $configLines[] = '    AllowOverride All';
        $configLines[] = '    Require all granted';
        $configLines[] = '</Directory>';
        $configPath = $fixture . '/httpd.conf';
        file_put_contents($configPath, implode("\n", $configLines) . "\n");

        $apacheEnvironment = getenv();
        if (!is_array($apacheEnvironment)) {
            $apacheEnvironment = [];
        }
        if ($phpModule !== '' && is_file($phpModule)) {
            $apacheEnvironment['PATH'] = dirname($phpModule) . PATH_SEPARATOR . (string) ($apacheEnvironment['PATH'] ?? '');
        }
        $check = proc_open([$apachePath, '-d', $apacheRoot, '-f', $configPath, '-t'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $checkPipes, $fixture, $apacheEnvironment, ['bypass_shell' => true]);
        cli_http_check(is_resource($check), 'Could not validate isolated Apache configuration.');
        fclose($checkPipes[0]);
        $checkOutput = stream_get_contents($checkPipes[1]) . stream_get_contents($checkPipes[2]);
        fclose($checkPipes[1]);
        fclose($checkPipes[2]);
        $checkStatus = proc_close($check);
        $apachePhpAvailable = $phpModule !== '' && is_file($phpModule) && $checkStatus === 0;
        if ($checkStatus !== 0 && $phpModule !== '' && is_file($phpModule)) {
            $configLines = array_values(array_filter($configLines, static fn(string $line): bool =>
                !str_contains($line, 'php_module') && !str_starts_with($line, 'AddHandler application/x-httpd-php')
                && !str_starts_with($line, 'PHPIniDir ')));
            file_put_contents($configPath, implode("\n", $configLines) . "\n");
            $check = proc_open([$apachePath, '-d', $apacheRoot, '-f', $configPath, '-t'], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $checkPipes, $fixture, $apacheEnvironment, ['bypass_shell' => true]);
            cli_http_check(is_resource($check), 'Could not retry Apache static-denial configuration.');
            fclose($checkPipes[0]);
            $checkOutput = stream_get_contents($checkPipes[1]) . stream_get_contents($checkPipes[2]);
            fclose($checkPipes[1]);
            fclose($checkPipes[2]);
            $checkStatus = proc_close($check);
            if ($checkStatus === 0) {
                $apacheCoverageComplete = false;
            }
        }
        if ($checkStatus !== 0) {
            if ($apacheRequired) {
                throw new RuntimeException('Required Apache static-denial fixture could not load its modules: ' . trim($checkOutput));
            }
            $apacheCoverageComplete = false;
        } else {
            [$apache] = cli_http_start_process([$apachePath, '-d', $apacheRoot, '-f', $configPath, '-X'],
                $fixture, $apacheEnvironment, $fixture . '/httpd.log');
            cli_http_wait_ready($apachePort, $apache);

            foreach ($protectedTrees as $tree) {
                $denied = cli_http_request('http://127.0.0.1:' . $apachePort . '/' . $tree . '/http-boundary-probe.php');
                cli_http_check(in_array($denied['status'], [403, 404], true) && !is_file($probeMarker),
                    'Apache must deny direct HTTP access to /' . $tree . '/ without mod_rewrite.');
            }

            if ($apachePhpAvailable) {
                $apachePublic = cli_http_request('http://127.0.0.1:' . $apachePort . '/index.php?page=robots');
                $apacheLocation = '';
                foreach ($apachePublic['headers'] as $header) {
                    if (stripos($header, 'Location:') === 0) {
                        $apacheLocation = trim(substr($header, strlen('Location:')));
                    }
                }
                cli_http_check($apachePublic['status'] === 302 && str_ends_with($apacheLocation, 'install.php'),
                    'Apache must leave the public query-string entry reachable without mod_rewrite.');
                $apacheQuery = cli_http_request('http://127.0.0.1:' . $apachePort . '/query-route-fixture.php?page=robots');
                cli_http_check($apacheQuery['status'] === 200 && trim($apacheQuery['body']) === 'robots',
                    'Apache must preserve GET query-string route parsing while mod_rewrite is absent.');
                $apacheCoverageComplete = true;
            } else {
                if ($apacheRequired) {
                    throw new RuntimeException('Required Apache PHP query routing needs PHP_GALLERY_APACHE_PHP_MODULE.');
                }
                $apacheCoverageComplete = false;
            }
        }
    }

    if ($apacheCoverageComplete) {
        echo "PASS: CLI HTTP boundary blocks all documented CLI scripts, denies protected Apache trees without mod_rewrite, and preserves public query-string entry.\n";
    } else {
        echo "SKIP: Built-in-server CLI SAPI and query-string checks passed; Apache protected-tree behavior requires the configured Apache fixture.\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: CLI HTTP boundary test: ' . $exception->getMessage() . "\n");
    foreach (['php-server.log', 'php-server-query.log'] as $logName) {
        $log = @file_get_contents($fixture . '/' . $logName);
        if (is_string($log) && trim($log) !== '') {
            $safeLog = substr(str_replace($token, '[fixture]', trim($log)), -3000);
            fwrite(STDERR, 'Isolated ' . $logName . " (captured before cleanup):\n" . $safeLog . "\n");
        }
    }
    $testFailed = true;
} finally {
    foreach (['server', 'apache'] as $variable) {
        if (isset($$variable) && is_resource($$variable)) {
            @proc_terminate($$variable);
            @proc_close($$variable);
        }
    }
    cli_http_remove_owned_tree($fixture, $token);
}

if ($testFailed) {
    exit(1);
}
