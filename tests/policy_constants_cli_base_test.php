<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/policy_constants_cli_base_test.php
 * Module Type: Policy Gate CLI Fixture
 * Purpose:
 *   Prove the policy CLI applies explicit and environment-selected immutable Git comparison bases.
 * Responsibilities:
 *   - Build and remove an isolated two-commit Git fixture.
 *   - Verify an earlier base is honored and explicit CLI selection overrides the environment.
 * Author:
 *   Rudolf Klusal
 * License:
 *   MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

require_once dirname(__DIR__) . '/scripts/check_policy_constants.php';

$previousBase = getenv('PHP_GALLERY_SOURCE_BASE');
$root = sys_get_temp_dir() . '/gallery-policy-cli-base-' . bin2hex(random_bytes(8));
$repository = $root . '/repo';
$directory = $repository . '/app';
mkdir($root);
mkdir($repository);
mkdir($directory);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$runGit = static function (array $arguments) use ($repository): string {
    $command = array_merge(['git', '-C', $repository], $arguments);
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start disposable Git fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new \RuntimeException('Disposable Git fixture command failed.');
    }
    return is_string($stdout) ? $stdout : '';
};

$runPolicy = static function (array $arguments): array {
    ob_start();
    $status = policy_main($arguments);
    $output = (string) ob_get_clean();
    $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    return ['exit' => $status, 'report' => $report];
};

try {
    $runGit(['init', '-q']);
    $runGit(['config', 'user.name', 'Policy Fixture']);
    $runGit(['config', 'user.email', 'policy-fixture@example.invalid']);
    $path = $directory . '/policy_fixture.php';
    file_put_contents($path, "<?php\nconst EXISTING_SETTING = 1;\n");
    $runGit(['add', 'app/policy_fixture.php']);
    $runGit(['commit', '-q', '-m', 'baseline without new policy site']);

    file_put_contents($path, "<?php\nconst EXISTING_SETTING = 1;\nconst WORKER_LIMIT = 4;\n");
    $runGit(['add', 'app/policy_fixture.php']);
    $runGit(['commit', '-q', '-m', 'add an unexplained runtime policy site']);

    putenv('PHP_GALLERY_SOURCE_BASE=HEAD^');
    $environmentBase = $runPolicy(['check_policy_constants.php', '--changed', '--json', '--root=' . $repository]);
    $assert($environmentBase['exit'] === 1 && $environmentBase['report']['status'] === 'FAIL'
        && $environmentBase['report']['summary']['base'] === 'HEAD^',
        'The policy CLI must apply the environment-selected earlier base.');

    $explicitBase = $runPolicy([
        'check_policy_constants.php',
        '--changed',
        '--json',
        '--root=' . $repository,
        '--base=HEAD',
    ]);
    $assert($explicitBase['exit'] === 0 && $explicitBase['report']['status'] === 'PASS'
        && $explicitBase['report']['summary']['base'] === 'HEAD',
        'An explicit --base must override PHP_GALLERY_SOURCE_BASE.');
} finally {
    if ($previousBase === false) {
        putenv('PHP_GALLERY_SOURCE_BASE');
    } else {
        putenv('PHP_GALLERY_SOURCE_BASE=' . $previousBase);
    }
    if (is_dir($repository)) {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repository, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir()) {
                @chmod($path, 0777);
                rmdir($path);
            } else {
                @chmod($path, 0666);
                unlink($path);
            }
        }
        @chmod($repository, 0777);
        rmdir($repository);
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}

echo "PASS policy_constants_cli_base: environment and explicit Git comparison bases.\n";
