<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/deploy_app_packaging_test.php
 * Module Type: Regression Test
 * Purpose: Prove that deployment wrappers build exact packages from canonical paths.
 * Responsibilities:
 *   - Exercise Bash and PowerShell wrappers against a dirty source fixture.
 *   - Compare folder and ZIP contents with the canonical path list and source hashes.
 *   - Prove missing files fail and existing destinations remain untouched.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/audit_lib.php';

/**
 * Bound each real packaging child beneath the registry's complete-case deadline.
 * Type: int. Units: seconds. Scope: deployment regression subprocesses.
 * Consumers: deploy_test_run(). Rationale: permit real Windows folder/ZIP work while rejecting hung children.
 */
const DEPLOY_TEST_DEFAULT_TIMEOUT_SECONDS = 240;

/**
 * Saturate portable pipe buffers so sequential stream readers cannot complete.
 * Type: int. Units: bytes per stream. Scope: process-capture regression fixture.
 * Consumers: deploy_test_verify_process_capture(). Rationale: exceed pipe capacity with a small bounded payload.
 */
const DEPLOY_TEST_CAPTURE_PAYLOAD_BYTES = 262144;

/**
 * Run one bounded process through the central audit capture owner.
 *
 * @param array<int, string> $command Executable and arguments.
 * @param string $workingDirectory Process working directory.
 * @param int $timeoutSeconds Maximum runtime before the child is terminated.
 * @return array{exit_code:int, stdout:string, stderr:string, timed_out:bool, duration:float} Captured process result.
 */
function deploy_test_run(array $command, string $workingDirectory, int $timeoutSeconds = DEPLOY_TEST_DEFAULT_TIMEOUT_SECONDS): array
{
    $process = \PhpGallery\Audit\run_process($command, $workingDirectory, $timeoutSeconds);
    if (!empty($process['blocked'])) {
        throw new RuntimeException('Could not start a bounded deploy test process.');
    }
    if ($process['timed_out']) {
        throw new RuntimeException('Deploy fixture subprocess timed out after ' . round($process['duration'], 2)
            . ' seconds: ' . basename((string) ($command[0] ?? 'unknown executable')) . '.');
    }
    if (getenv('PHP_GALLERY_DEPLOY_TEST_TIMINGS') === '1') {
        printf("[deploy-process] %s %.2fs exit=%d\n", basename((string) ($command[0] ?? 'unknown executable')),
            $process['duration'], $process['exit_code']);
        fflush(STDOUT);
    }
    return [
        'exit_code' => $process['exit_code'],
        'stdout' => $process['stdout'],
        'stderr' => $process['stderr'],
        'timed_out' => $process['timed_out'],
        'duration' => $process['duration'],
    ];
}

/**
 * Verify the deploy test wrapper drains both process streams and rejects timed-out children.
 *
 * @return void Throws when stream capture or timeout handling regresses.
 */
function deploy_test_verify_process_capture(): void
{
    $payloadBytes = DEPLOY_TEST_CAPTURE_PAYLOAD_BYTES;
    $noisyChild = '$payload = str_repeat("E", ' . $payloadBytes . '); fwrite(STDERR, $payload); fflush(STDERR); '
        . 'fwrite(STDOUT, str_repeat("O", ' . $payloadBytes . '));';
    $captured = deploy_test_run([PHP_BINARY, '-r', $noisyChild], dirname(__DIR__), 5);
    deploy_test_assert($captured['exit_code'] === 0, 'The noisy process-capture regression child failed.');
    deploy_test_assert(strlen($captured['stderr']) === $payloadBytes, 'The deploy test helper did not fully capture stderr written before stdout.');
    deploy_test_assert(strlen($captured['stdout']) === $payloadBytes, 'The deploy test helper did not fully capture stdout after stderr.');
    deploy_test_assert($captured['stderr'] === str_repeat('E', $payloadBytes), 'The captured stderr payload was altered.');
    deploy_test_assert($captured['stdout'] === str_repeat('O', $payloadBytes), 'The captured stdout payload was altered.');

    $timedOut = false;
    try {
        deploy_test_run([PHP_BINARY, '-r', 'usleep(3000000);'], dirname(__DIR__), 1);
    } catch (RuntimeException $exception) {
        $timedOut = str_contains($exception->getMessage(), 'subprocess timed out');
    }
    deploy_test_assert($timedOut, 'A timed-out deploy test child was not rejected explicitly.');
}

/**
 * Require a condition and attach a useful reason to any regression failure.
 *
 * @param bool $condition Condition that must hold.
 * @param string $message Failure description.
 * @return void Throws when the condition is false.
 */
function deploy_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Find an executable by checking explicit paths and the process PATH.
 *
 * @param array<int, string> $candidates Candidate executable names or paths.
 * @return ?string Existing executable path, or null if unavailable.
 */
function deploy_test_find_executable(array $candidates): ?string
{
    $extensions = DIRECTORY_SEPARATOR === '\\' ? ['.exe', '.cmd', '.bat', ''] : ['', '.exe'];
    foreach ($candidates as $candidate) {
        if (str_contains($candidate, DIRECTORY_SEPARATOR) || str_contains($candidate, '/')) {
            foreach ($extensions as $extension) {
                if (is_file($candidate . $extension)) {
                    return $candidate . $extension;
                }
            }
            continue;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $path = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . $candidate . $extension;
                if (is_file($path)) {
                    return $path;
                }
            }
        }
    }

    return null;
}

/**
 * Convert a Windows absolute path to the standard Git Bash drive path.
 *
 * @param string $path Absolute path to convert.
 * @return string Path suitable for a Git Bash process argument.
 */
function deploy_test_git_bash_path(string $path): string
{
    if (preg_match('/^([A-Za-z]):[\\\\\\/](.*)$/', $path, $matches) === 1) {
        return '/' . strtolower($matches[1]) . '/' . str_replace('\\', '/', $matches[2]);
    }

    return str_replace('\\', '/', $path);
}

/**
 * Copy one regular source file into a fixture while preserving its relative path.
 *
 * @param string $sourceRoot Source project root.
 * @param string $destinationRoot Fixture project root.
 * @param string $relativePath Safe relative file path.
 * @return void Creates the fixture file or throws on unsafe source content.
 */
function deploy_test_copy_file(string $sourceRoot, string $destinationRoot, string $relativePath): void
{
    $normalized = str_replace('\\', '/', $relativePath);
    if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.?(/|$)#', $normalized) === 1) {
        throw new RuntimeException('The canonical package list contained an unsafe path.');
    }

    $sourcePath = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    $destinationPath = $destinationRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (is_link($sourcePath) || !is_file($sourcePath)) {
        throw new RuntimeException('Expected package source is missing or linked: ' . $normalized);
    }
    $destinationDirectory = dirname($destinationPath);
    if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0777, true) && !is_dir($destinationDirectory)) {
        throw new RuntimeException('Could not create a fixture directory.');
    }
    if (!copy($sourcePath, $destinationPath)) {
        throw new RuntimeException('Could not copy a fixture source file: ' . $normalized);
    }
}

/**
 * Return all regular package files below a folder as sorted relative paths.
 *
 * @param string $rootPath Package root to inspect.
 * @return array<int, string> Sorted regular file paths using forward slashes.
 */
function deploy_test_directory_files(string $rootPath): array
{
    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            throw new RuntimeException('A built package contains a symbolic link.');
        }
        if ($entry->isFile()) {
            $paths[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($rootPath) + 1));
        }
    }
    sort($paths, SORT_STRING);

    return $paths;
}

/**
 * Return the canonical path list by invoking the shared policy CLI.
 *
 * @param string $phpPath PHP command path.
 * @param string $sourceRoot Source root for the package.
 * @param string $profile Production or source-review package profile.
 * @param bool $includeMedia Whether explicit gallery media is included.
 * @return array<int, string> Sorted canonical relative paths.
 */
function deploy_test_canonical_paths(string $phpPath, string $sourceRoot, string $profile = 'production', bool $includeMedia = false): array
{
    $command = [
        $phpPath,
        $sourceRoot . '/scripts/release_files.php',
        'list',
        '--root=' . $sourceRoot,
        '--profile=' . $profile,
        '--format=json',
    ];
    if ($includeMedia) {
        $command[] = '--include-media';
    }

    $result = deploy_test_run($command, $sourceRoot);
    deploy_test_assert($result['exit_code'] === 0, 'The canonical release-file list failed: ' . $result['stderr']);
    $document = json_decode($result['stdout'], true);
    deploy_test_assert(is_array($document) && is_array($document['files'] ?? null), 'The canonical release-file list was invalid.');
    $paths = array_map('strval', $document['files']);
    sort($paths, SORT_STRING);

    return $paths;
}

/**
 * Compare every package file and SHA-256 digest with the selected source list.
 *
 * @param string $packageRoot Folder package root.
 * @param string $sourceRoot Source root used for the package.
 * @param array<int, string> $expectedPaths Canonical expected relative paths.
 * @return void Throws when a path or file digest differs.
 */
function deploy_test_assert_package_matches(string $packageRoot, string $sourceRoot, array $expectedPaths): void
{
    $actualPaths = deploy_test_directory_files($packageRoot);
    deploy_test_assert($actualPaths === $expectedPaths, 'Built folder file inventory differs from the canonical manifest.');

    foreach ($expectedPaths as $relativePath) {
        $sourcePath = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $packagePath = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        deploy_test_assert(
            hash_file('sha256', $sourcePath) === hash_file('sha256', $packagePath),
            'Built folder hash differs from the source for ' . $relativePath
        );
    }
}

/**
 * Compare ZIP entry names and content digests with the canonical source list.
 *
 * @param string $archivePath ZIP package file.
 * @param string $sourceRoot Source root used for the package.
 * @param array<int, string> $expectedPaths Canonical expected relative paths.
 * @return void Throws when the ZIP contents differ.
 */
function deploy_test_assert_zip_matches(string $archivePath, string $sourceRoot, array $expectedPaths): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZIP package inspection requires the PHP ZipArchive extension.');
    }
    $archive = new ZipArchive();
    if ($archive->open($archivePath) !== true) {
        throw new RuntimeException('The deploy wrapper did not create a readable ZIP.');
    }

    $actualPaths = [];
    $contents = [];
    for ($index = 0; $index < $archive->numFiles; $index++) {
        $relativePath = $archive->getNameIndex($index);
        if (!is_string($relativePath) || str_ends_with($relativePath, '/')) {
            continue;
        }
        $actualPaths[] = $relativePath;
        $contents[$relativePath] = $archive->getFromIndex($index);
    }
    $archive->close();
    sort($actualPaths, SORT_STRING);
    deploy_test_assert($actualPaths === $expectedPaths, 'Built ZIP file inventory differs from the canonical manifest.');

    foreach ($expectedPaths as $relativePath) {
        $sourcePath = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $content = $contents[$relativePath] ?? false;
        deploy_test_assert(
            is_string($content) && hash('sha256', $content) === hash_file('sha256', $sourcePath),
            'Built ZIP hash differs from the source for ' . $relativePath
        );
    }
}

/**
 * Create unrelated local state that must never be copied into production output.
 *
 * @param string $sourceRoot Dirty fixture source root.
 * @return void Creates representative ignored workspace/runtime files.
 */
function deploy_test_add_noise(string $sourceRoot): void
{
    $noise = [
        '.agent-local/session.txt' => 'private agent state',
        '.codex/settings.json' => '{"local":true}',
        '.vscode/settings.json' => '{}',
        'TEMP_local-plan.md' => 'local plan',
        'cache/audit-output.json' => '{}',
        'logs/local.log' => 'local log',
        'tmp/generated.tmp' => 'temporary output',
        'data/runtime-secret.txt' => 'runtime state',
        'config.php' => '<?php $local = true;',
        'app/unlisted-local.bin' => 'unapproved application file',
        'galleries/private/gallery.jpg' => 'private media',
        'winapp/build/generated.py' => 'local build output',
        'winapp/dist/0.0.0/Setup.exe' => 'local installer output',
        'winapp/http_monitor_logs/private.txt' => 'private monitor log',
        'winapp/settings.json' => '{"private":true}',
        'winapp/tests/local_test.py' => 'local test',
        'winapp/uploader/unlisted.py' => 'unlisted local Python module',
    ];
    foreach ($noise as $relativePath => $contents) {
        $path = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create dirty fixture content.');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Could not write dirty fixture content.');
        }
    }
}

/**
 * Create a clean test fixture from canonical package sources plus manifest inputs.
 *
 * @param string $sourceRoot Original project root.
 * @param string $fixtureRoot Destination fixture root.
 * @param array<int, string> $productionPaths Canonical production files.
 * @param array<int, string> $sourceReviewPaths Canonical production and test files.
 * @return void Copies manifest-managed files and package-specific sources.
 */
function deploy_test_create_fixture(string $sourceRoot, string $fixtureRoot, array $productionPaths, array $sourceReviewPaths): void
{
    $manifestPath = $sourceRoot . '/app/core-manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    deploy_test_assert(is_array($manifest) && is_array($manifest['files'] ?? null), 'The core manifest is invalid.');
    $allSourcePaths = array_unique(array_merge(array_keys($manifest['files']), $productionPaths, $sourceReviewPaths, [
        'app/core-manifest.json',
        'scripts/deploy.sh',
        'scripts/deploy.ps1',
        'scripts/release_files.php',
        'scripts/generate_manifest.php',
        'scripts/cli_guard.php',
    ]));
    sort($allSourcePaths, SORT_STRING);
    foreach ($allSourcePaths as $relativePath) {
        deploy_test_copy_file($sourceRoot, $fixtureRoot, (string) $relativePath);
    }
    $manifestRefresh = deploy_test_run([
        PHP_BINARY,
        $fixtureRoot . '/scripts/generate_manifest.php',
        '--root=' . $fixtureRoot,
    ], $fixtureRoot);
    deploy_test_assert($manifestRefresh['exit_code'] === 0, 'Could not refresh the fixture manifest: ' . $manifestRefresh['stderr']);
    deploy_test_add_noise($fixtureRoot);
}

/**
 * Translate shared long-form wrapper options into PowerShell parameter names when needed.
 *
 * @param array<int, string> $wrapperCommand Wrapper executable and fixed arguments.
 * @param array<string, string> $options Option names and values.
 * @return array<int, string> Complete process argument vector.
 */
function deploy_test_wrapper_arguments(array $wrapperCommand, array $options): array
{
    $isPowerShell = in_array('-File', $wrapperCommand, true);
    $isGitBash = !$isPowerShell && str_ends_with(strtolower((string) ($wrapperCommand[0] ?? '')), 'bash.exe');
    foreach ($options as $name => $value) {
        if ($isPowerShell) {
            $powerShellName = '-' . str_replace(' ', '', ucwords(str_replace('-', ' ', substr($name, 2))));
            $wrapperCommand[] = $powerShellName;
        } else {
            $wrapperCommand[] = $name;
        }
        if ($isGitBash && $name === '--deploy-folder' && preg_match('/^[A-Za-z]:[\\\\\/]/', $value) === 1) {
            $value = deploy_test_git_bash_path($value);
        }
        $wrapperCommand[] = $value;
    }

    return $wrapperCommand;
}

/**
 * Run one deploy wrapper and require a successful, fully validated local artifact.
 *
 * @param array<int, string> $wrapperCommand Executable arguments before wrapper options.
 * @param string $sourceRoot Dirty source fixture root.
 * @param string $destination Output destination.
 * @param bool $zip Whether to build a ZIP package.
 * @param bool $includeMedia Whether to include opt-in media.
 * @param bool $includeTests Whether to include local source-review tests.
 * @return array<int, string> Canonical file list used for the artifact.
 */
function deploy_test_build_artifact(
    array $wrapperCommand,
    string $sourceRoot,
    string $destination,
    bool $zip,
    bool $includeMedia = false,
    bool $includeTests = false
): array {
    $arguments = deploy_test_wrapper_arguments($wrapperCommand, [
        '--mode' => 'local',
        '--deploy-folder' => $destination,
        '--upload-media' => $includeMedia ? 'true' : 'false',
        '--make-zip-deploy' => $zip ? 'true' : 'false',
        '--include-tests' => $includeTests ? 'true' : 'false',
    ]);
    $result = deploy_test_run($arguments, $sourceRoot);
    deploy_test_assert(
        $result['exit_code'] === 0,
        'Deploy wrapper ' . ($wrapperCommand[0] ?? '(unknown)') . ' failed: ' . $result['stdout'] . $result['stderr']
    );

    $profile = $includeTests ? 'source-review' : 'production';
    $expectedPaths = deploy_test_canonical_paths(PHP_BINARY, $sourceRoot, $profile, $includeMedia);
    if ($zip) {
        deploy_test_assert_zip_matches($destination . '/php-gallery-deploy.zip', $sourceRoot, $expectedPaths);
    } else {
        deploy_test_assert_package_matches($destination, $sourceRoot, $expectedPaths);
    }

    return $expectedPaths;
}

/**
 * Prove repeated interactive Windows packaging preserves earlier output and selects a fresh destination.
 *
 * @param string $powerShellPath PowerShell executable used by the Windows batch launcher.
 * @param string $fixtureRoot Source fixture containing the real deployment workflow.
 * @param string $outputRoot Owned output directory for collision fixtures.
 * @return void Throws if prompted ZIP/folder packaging overwrites content or cannot complete.
 */
function deploy_test_verify_interactive_windows_packaging(string $powerShellPath, string $fixtureRoot, string $outputRoot): void
{
    $expectedPaths = deploy_test_canonical_paths(PHP_BINARY, $fixtureRoot);
    foreach ([true, false] as $zip) {
        $destination = $outputRoot . DIRECTORY_SEPARATOR . ($zip ? 'interactive zip' : 'interactive folder');
        mkdir($destination, 0777, true);
        $protectedFile = $destination . DIRECTORY_SEPARATOR . ($zip ? 'php-gallery-deploy.zip' : 'keep.txt');
        file_put_contents($protectedFile, 'preserve the earlier package');
        // Pin the clock and occupy its first candidate to exercise same-second repeated launches.
        $occupiedCandidate = $destination . '-20000101-000000';
        mkdir($occupiedCandidate, 0777, true);
        file_put_contents($occupiedCandidate . DIRECTORY_SEPARATOR . 'keep.txt', 'preserve the sibling');

        $destinationLiteral = "'" . str_replace("'", "''", $destination) . "'";
        $scriptLiteral = "'" . str_replace("'", "''", $fixtureRoot . '/scripts/deploy.ps1') . "'";
        $script = '$global:DeployAnswers = [Collections.Generic.Queue[string]]::new(); '
            . "foreach (\$answer in @('local', 'n', 'n', " . $destinationLiteral . ", '" . ($zip ? 'y' : 'n') . "', '')) { \$global:DeployAnswers.Enqueue(\$answer); } "
            . 'function Read-Host { param([string]$Prompt) Write-Host "Prompt: $Prompt"; return $global:DeployAnswers.Dequeue(); } '
            . "function Get-Date { param([string]\$Format) return '20000101-000000'; } "
            . '& ' . $scriptLiteral;
        $result = deploy_test_run([$powerShellPath, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $script], $fixtureRoot);
        deploy_test_assert($result['exit_code'] === 0, 'Interactive Windows packaging failed: ' . $result['stdout'] . $result['stderr']);
        deploy_test_assert(file_get_contents($protectedFile) === 'preserve the earlier package', 'Interactive packaging overwrote earlier output.');
        deploy_test_assert(file_get_contents($occupiedCandidate . DIRECTORY_SEPARATOR . 'keep.txt') === 'preserve the sibling', 'Interactive packaging overwrote a timestamp collision.');
        $newDestination = $occupiedCandidate . '-1';
        deploy_test_assert(str_contains($result['stdout'], $newDestination), 'Interactive packaging did not report the actual output destination.');
        deploy_test_assert(str_contains($result['stdout'], 'Deployment completed successfully.'), 'An Explorer launch hid its success summary.');
        deploy_test_assert(str_contains($result['stdout'], 'Prompt: Press Enter to close this window'), 'An Explorer launch omitted its closing prompt.');
        if ($zip) {
            deploy_test_assert_zip_matches($newDestination . '/php-gallery-deploy.zip', $fixtureRoot, $expectedPaths);
        } else {
            deploy_test_assert_package_matches($newDestination, $fixtureRoot, $expectedPaths);
        }
    }

    $fixtureLiteral = "'" . str_replace("'", "''", $fixtureRoot) . "'";
    $failureScript = '$global:DeployAnswers = [Collections.Generic.Queue[string]]::new(); '
        . "foreach (\$answer in @('local', 'n', 'n', " . $fixtureLiteral . ", 'y', '')) { \$global:DeployAnswers.Enqueue(\$answer); } "
        . 'function Read-Host { param([string]$Prompt) Write-Host "Prompt: $Prompt"; return $global:DeployAnswers.Dequeue(); } '
        . '& ' . $scriptLiteral;
    $failure = deploy_test_run([$powerShellPath, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $failureScript], $fixtureRoot);
    deploy_test_assert($failure['exit_code'] !== 0, 'An interactive launch accepted the project root as its output.');
    deploy_test_assert(str_contains($failure['stdout'], 'Deployment failed. See the error above.'), 'An Explorer launch hid its failure summary.');
    deploy_test_assert(str_contains($failure['stdout'], 'Prompt: Press Enter to close this window'), 'A failed Explorer launch omitted its closing prompt.');
}

/**
 * Build and validate package artifacts from actual installed Bash and PowerShell wrappers.
 *
 * @return void Throws when a dirty workspace leaks, an expected path is missing, or output differs.
 */
function deploy_test_run_behavioral_packaging_proof(): void
{
    $sourceRoot = dirname(__DIR__);
    $phpPath = PHP_BINARY;
    $productionPaths = deploy_test_canonical_paths($phpPath, $sourceRoot);
    $sourceReviewPaths = deploy_test_canonical_paths($phpPath, $sourceRoot, 'source-review');
    $temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-gallery-deploy-test-' . bin2hex(random_bytes(8));
    $fixtureRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'source fixture';
    $outputRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'output packages';
    if (!mkdir($fixtureRoot, 0777, true) && !is_dir($fixtureRoot)) {
        throw new RuntimeException('Could not create a dirty-workspace packaging fixture.');
    }
    if (!mkdir($outputRoot, 0777, true) && !is_dir($outputRoot)) {
        throw new RuntimeException('Could not create a deploy output test directory.');
    }

    try {
        deploy_test_create_fixture($sourceRoot, $fixtureRoot, $productionPaths, $sourceReviewPaths);
        $fixtureProductionPaths = deploy_test_canonical_paths($phpPath, $fixtureRoot);
        deploy_test_assert($fixtureProductionPaths === $productionPaths, 'The fixture canonical inventory differs from the source policy.');
        deploy_test_assert(!in_array('TEMP_local-plan.md', $fixtureProductionPaths, true), 'A dirty TEMP file entered the production inventory.');
        deploy_test_assert(!in_array('app/unlisted-local.bin', $fixtureProductionPaths, true), 'An unlisted application file entered the production inventory.');
        deploy_test_assert(!in_array('galleries/private/gallery.jpg', $fixtureProductionPaths, true), 'Gallery media entered the default production inventory.');
        foreach (['winapp/gallery_watch_upload.pyw', 'winapp/gallery_http_monitor.py', 'winapp/VERSION',
            'winapp/SimConnect.dll', 'winapp/assets/tray-icon.ico', 'winapp/assets/tray-icon.png',
            'winapp/install.bat', 'winapp/run_gallery_watcher.bat', 'winapp/uploader/media.py',
            'winapp/uploader/self_update.py', 'winapp/build_installer.py'] as $winAppPath) {
            deploy_test_assert(in_array($winAppPath, $fixtureProductionPaths, true), 'Deployment omitted a required WinApp source or asset: ' . $winAppPath);
        }
        deploy_test_assert(!in_array('winapp/uploader/unlisted.py', $fixtureProductionPaths, true), 'An unlisted WinApp module entered the production inventory.');

        $bashPath = deploy_test_find_executable([
            (string) getenv('GIT_BASH'),
            'C:\\Program Files\\Git\\bin\\bash.exe',
            'bash',
        ]);
        $powerShellPath = deploy_test_find_executable([
            (string) getenv('PWSH'),
            'pwsh',
            'powershell',
        ]);
        $wrappers = [];
        if ($bashPath !== null) {
            $wrappers['bash'] = [
                $bashPath,
                deploy_test_git_bash_path($fixtureRoot . '/scripts/deploy.sh'),
            ];
        }
        if ($powerShellPath !== null) {
            $wrappers['powershell'] = [
                $powerShellPath,
                '-NoProfile',
                '-File',
                $fixtureRoot . '/scripts/deploy.ps1',
            ];
        }
        deploy_test_assert($wrappers !== [], 'Neither Bash nor PowerShell is available for deploy-wrapper behavior tests.');

        foreach ($wrappers as $name => $wrapperCommand) {
            $folder = $outputRoot . DIRECTORY_SEPARATOR . $name . ' folder with spaces';
            deploy_test_build_artifact($wrapperCommand, $fixtureRoot, $folder, false);

            $zipDirectory = $outputRoot . DIRECTORY_SEPARATOR . $name . ' zip with spaces';
            deploy_test_build_artifact($wrapperCommand, $fixtureRoot, $zipDirectory, true);

            $zipHash = hash_file('sha256', $zipDirectory . '/php-gallery-deploy.zip');
            $zipCollision = deploy_test_run(deploy_test_wrapper_arguments($wrapperCommand, [
                '--mode' => 'local',
                '--deploy-folder' => $zipDirectory,
                '--upload-media' => 'false',
                '--make-zip-deploy' => 'true',
                '--include-tests' => 'false',
            ]), $fixtureRoot);
            deploy_test_assert($zipCollision['exit_code'] !== 0, $name . ' overwrote an explicit ZIP destination.');
            deploy_test_assert(hash_file('sha256', $zipDirectory . '/php-gallery-deploy.zip') === $zipHash, $name . ' modified a rejected existing ZIP.');
            deploy_test_assert(!str_contains($zipCollision['stdout'], 'Press Enter to close this window'), $name . ' paused a scripted invocation.');

            $existingTarget = $outputRoot . DIRECTORY_SEPARATOR . $name . ' existing target';
            mkdir($existingTarget, 0777, true);
            file_put_contents($existingTarget . DIRECTORY_SEPARATOR . 'keep.txt', 'preserve this');
            $collision = deploy_test_run(deploy_test_wrapper_arguments($wrapperCommand, [
                '--mode' => 'local',
                '--deploy-folder' => $existingTarget,
                '--upload-media' => 'false',
                '--make-zip-deploy' => 'false',
                '--include-tests' => 'false',
            ]), $fixtureRoot);
            deploy_test_assert($collision['exit_code'] !== 0, $name . ' replaced an existing destination.');
            deploy_test_assert(
                file_get_contents($existingTarget . DIRECTORY_SEPARATOR . 'keep.txt') === 'preserve this',
                $name . ' modified content in a rejected existing destination.'
            );

            foreach (['.', '..'] as $unsafeTarget) {
                foreach (['false', 'true'] as $zipMode) {
                    $unsafe = deploy_test_run(deploy_test_wrapper_arguments($wrapperCommand, [
                        '--mode' => 'local',
                        '--deploy-folder' => $unsafeTarget,
                        '--upload-media' => 'false',
                        '--make-zip-deploy' => $zipMode,
                        '--include-tests' => 'false',
                    ]), $fixtureRoot);
                    deploy_test_assert(
                        $unsafe['exit_code'] !== 0,
                        $name . ' accepted an unsafe project-root/parent destination (' . $unsafeTarget . ', ZIP=' . $zipMode . ').'
                    );
                }
            }

            $galleryOutput = $fixtureRoot . DIRECTORY_SEPARATOR . 'galleries' . DIRECTORY_SEPARATOR . 'deploy output';
            $galleryContainment = deploy_test_run(deploy_test_wrapper_arguments($wrapperCommand, [
                '--mode' => 'local',
                '--deploy-folder' => $galleryOutput,
                '--upload-media' => 'true',
                '--make-zip-deploy' => 'false',
                '--include-tests' => 'false',
            ]), $fixtureRoot);
            deploy_test_assert($galleryContainment['exit_code'] !== 0, $name . ' allowed media-enabled output inside source galleries.');
        }

        if (isset($wrappers['powershell'])) {
            $windowsPowerShell = deploy_test_find_executable([
                (string) getenv('SystemRoot') . '/System32/WindowsPowerShell/v1.0/powershell.exe',
            ]);
            $interactivePowerShell = $windowsPowerShell ?? $wrappers['powershell'][0];
            deploy_test_verify_interactive_windows_packaging($interactivePowerShell, $fixtureRoot, $outputRoot);
            $junctionPath = $outputRoot . DIRECTORY_SEPARATOR . 'galleries junction';
            $galleryPath = $fixtureRoot . DIRECTORY_SEPARATOR . 'galleries';
            $junctionPathLiteral = "'" . str_replace("'", "''", $junctionPath) . "'";
            $galleryPathLiteral = "'" . str_replace("'", "''", $galleryPath) . "'";
            // Windows junctions avoid symlink privilege requirements; Unix uses native directory symlinks.
            $linkType = DIRECTORY_SEPARATOR === '\\' ? 'Junction' : 'SymbolicLink';
            $junctionScript = "\$ErrorActionPreference = 'Stop'; New-Item -ItemType " . $linkType
                . ' -Path ' . $junctionPathLiteral . ' -Target ' . $galleryPathLiteral
                . " -ErrorAction Stop | Out-Null; \$item = Get-Item -LiteralPath " . $junctionPathLiteral
                . " -Force; \$isReparse = [bool](\$item.Attributes -band [IO.FileAttributes]::ReparsePoint); "
                . "if (-not \$isReparse -or [string]\$item.LinkType -ne '" . $linkType . "') "
                . "{ throw 'Gallery redirect has an unexpected filesystem type.' }; "
                . "[pscustomobject]@{ LinkType = [string]\$item.LinkType; ReparsePoint = \$isReparse } | ConvertTo-Json -Compress";
            $verifiedGalleryLink = false;
            try {
                $junctionCommand = deploy_test_run([
                    $wrappers['powershell'][0],
                    '-NoProfile',
                    '-Command',
                    $junctionScript,
                ], $fixtureRoot);
                deploy_test_assert($junctionCommand['exit_code'] === 0,
                    'PowerShell could not create and verify the galleries redirect: ' . $junctionCommand['stderr']);
                $linkStatus = json_decode($junctionCommand['stdout'], true);
                $realLinkTarget = realpath($junctionPath);
                $realGalleryPath = realpath($galleryPath);
                $phpTargetMatches = is_string($realLinkTarget) && is_string($realGalleryPath)
                    && (DIRECTORY_SEPARATOR === '\\'
                        ? strcasecmp(str_replace('\\', '/', $realLinkTarget), str_replace('\\', '/', $realGalleryPath)) === 0
                        : $realLinkTarget === $realGalleryPath);
                $verifiedGalleryLink = is_array($linkStatus)
                    && ($linkStatus['LinkType'] ?? null) === $linkType
                    && ($linkStatus['ReparsePoint'] ?? false) === true
                    && $phpTargetMatches;
                deploy_test_assert($verifiedGalleryLink,
                    'The galleries redirect was not a verified ' . $linkType . ' resolving to the fixture galleries directory.');

                $junctionTarget = $junctionPath . DIRECTORY_SEPARATOR . 'new media output';
                $junctionResult = deploy_test_run(deploy_test_wrapper_arguments($wrappers['powershell'], [
                    '--mode' => 'local',
                    '--deploy-folder' => $junctionTarget,
                    '--upload-media' => 'true',
                    '--make-zip-deploy' => 'false',
                    '--include-tests' => 'false',
                ]), $fixtureRoot);
                deploy_test_assert($junctionResult['exit_code'] !== 0, 'PowerShell allowed a media output path through a galleries ' . $linkType . '.');
            } finally {
                if ($verifiedGalleryLink) {
                    if (DIRECTORY_SEPARATOR === '\\') {
                        rmdir($junctionPath);
                    } else {
                        unlink($junctionPath);
                    }
                }
            }
        } else {
            echo "SKIP PowerShell junction destination fixture: PowerShell is unavailable.\n";
        }

        $primaryWrapper = reset($wrappers);
        $sourceReviewOutput = $outputRoot . DIRECTORY_SEPARATOR . 'source review';
        $reviewPaths = deploy_test_build_artifact($primaryWrapper, $fixtureRoot, $sourceReviewOutput, false, false, true);
        deploy_test_assert(in_array('tests/deploy_app_packaging_test.php', $reviewPaths, true), 'Local source-review mode omitted the deploy regression test.');

        $mediaOutput = $outputRoot . DIRECTORY_SEPARATOR . 'media opt in';
        $mediaPaths = deploy_test_build_artifact($primaryWrapper, $fixtureRoot, $mediaOutput, false, true, false);
        deploy_test_assert(in_array('galleries/private/gallery.jpg', $mediaPaths, true), 'Explicit media opt-in omitted gallery media.');

        $ftpTests = deploy_test_run(deploy_test_wrapper_arguments($primaryWrapper, [
            '--mode' => 'ftp',
            '--include-tests' => 'true',
            '--host-name' => 'example.invalid',
            '--user-name' => 'unused',
            '--password' => 'unused',
            '--remote-folder' => '/unused',
        ]), $fixtureRoot);
        deploy_test_assert($ftpTests['exit_code'] !== 0, 'A deploy wrapper allowed source-review tests in FTP mode.');

        $missingRelativePath = $productionPaths[0];
        $missingPath = $fixtureRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $missingRelativePath);
        $missingBackup = $missingPath . '.deploy-test-backup';
        if (is_file($missingPath) && rename($missingPath, $missingBackup)) {
            try {
                $missingTarget = $outputRoot . DIRECTORY_SEPARATOR . 'missing expected file';
                $missing = deploy_test_run(deploy_test_wrapper_arguments($primaryWrapper, [
                    '--mode' => 'local',
                    '--deploy-folder' => $missingTarget,
                    '--upload-media' => 'false',
                    '--make-zip-deploy' => 'false',
                    '--include-tests' => 'false',
                ]), $fixtureRoot);
                deploy_test_assert($missing['exit_code'] !== 0, 'A deploy wrapper packaged a source tree with a missing required file.');
                deploy_test_assert(!file_exists($missingTarget), 'A failed missing-file package left a destination behind.');
            } finally {
                rename($missingBackup, $missingPath);
            }
        }

        $linkedRelativePath = $productionPaths[0];
        $linkedPath = $fixtureRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $linkedRelativePath);
        $linkedBackup = $linkedPath . '.deploy-link-backup';
        $outsideLinkTarget = $temporaryRoot . DIRECTORY_SEPARATOR . 'outside source target.txt';
        file_put_contents($outsideLinkTarget, 'outside source');
        $sourceSymlinkFixtureRan = false;
        if (is_file($linkedPath) && rename($linkedPath, $linkedBackup)) {
            try {
                if (@symlink($outsideLinkTarget, $linkedPath)) {
                    $sourceSymlinkFixtureRan = true;
                    $linkedResult = deploy_test_run(deploy_test_wrapper_arguments($primaryWrapper, [
                        '--mode' => 'local',
                        '--deploy-folder' => $outputRoot . DIRECTORY_SEPARATOR . 'linked source',
                        '--upload-media' => 'false',
                        '--make-zip-deploy' => 'false',
                        '--include-tests' => 'false',
                    ]), $fixtureRoot);
                    deploy_test_assert($linkedResult['exit_code'] !== 0, 'A deploy wrapper accepted a canonical source symlink.');
                }
            } finally {
                if (is_link($linkedPath) || is_file($linkedPath)) {
                    unlink($linkedPath);
                }
                rename($linkedBackup, $linkedPath);
            }
        }
        if (!$sourceSymlinkFixtureRan) {
            echo "SKIP source symlink fixture: this host could not create a file symlink.\n";
        }
    } finally {
        $resolvedTemporaryRoot = realpath($temporaryRoot);
        $resolvedSystemTemp = realpath(sys_get_temp_dir());
        if ($resolvedTemporaryRoot !== false && $resolvedSystemTemp !== false
            && str_starts_with($resolvedTemporaryRoot, $resolvedSystemTemp . DIRECTORY_SEPARATOR)
            && basename($resolvedTemporaryRoot) !== '') {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($resolvedTemporaryRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($resolvedTemporaryRoot);
        }
    }
}

try {
    deploy_test_verify_process_capture();
    deploy_test_run_behavioral_packaging_proof();
    echo "Deploy allowlist packaging behavior checks passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
