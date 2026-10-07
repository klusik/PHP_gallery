<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/prepare_candidate.php
 * Module Type: Development Preparation CLI
 * Purpose: Prepare or read-only validate generated artifacts for a source candidate.
 * Responsibilities:
 *   - Compile the reviewed runtime module dependency plan first
 *   - Refresh Git-index-based production membership after authored file changes
 *   - Hash the complete final checkout only after all other generators finish
 *   - Preserve atomic source commits and leave Git, CI, releases, and merges to their owners
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();

/**
 * Execute one canonical generator with inherited CLI output and a fixed argument set.
 *
 * @param string $relativeScript Repository script filename.
 * @param bool $check Whether to perform its strictly read-only freshness check.
 * @return int Child process exit code, or a failure code if execution was unavailable.
 */
function candidate_run_generator(string $relativeScript, bool $check): int
{
    $command = [PHP_BINARY, __DIR__ . '/' . $relativeScript];
    if ($check) {
        $command[] = '--check';
    }
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start canonical generator: ' . $relativeScript);
    }
    $result = proc_close($process);
    return $result >= 0 ? $result : 1;
}

$args = array_slice($argv, 1);
if ($args === ['--help'] || $args === ['-h']) {
    echo "Usage: php scripts/prepare_candidate.php [--check]\n";
    echo "Stage new files before preparing; inventory membership follows the Git index.\n";
    echo "--check performs all canonical freshness checks without writing files.\n";
    exit(0);
}
if ($args !== [] && $args !== ['--check']) {
    fwrite(STDERR, "Usage: php scripts/prepare_candidate.php [--check]\n");
    exit(2);
}

$check = $args === ['--check'];
$steps = [
    'generate_runtime_modules.php' => 'Reviewed runtime module dependencies and routes',
    'generate_production_files.php' => 'Git-index production and source-review inventory',
    'generate_manifest.php' => 'Final complete-byte integrity manifest',
];

echo 'Candidate ' . ($check ? 'verification' : 'preparation') . " (no Git commits, merges, or release publication)\n";
foreach ($steps as $script => $label) {
    echo '[STEP] ' . $label . "\n";
    try {
        $status = candidate_run_generator($script, $check);
    } catch (Throwable $exception) {
        fwrite(STDERR, '[BLOCKED] ' . $script . ': ' . $exception->getMessage() . "\n");
        exit(1);
    }
    if ($status !== 0) {
        fwrite(STDERR, '[FAIL] ' . $script . ' exited with ' . $status . '. '
            . ($check ? 'Run php scripts/prepare_candidate.php after the final edit.' :
                'Fix the generator input, then repeat php scripts/prepare_candidate.php.') . "\n");
        exit($status);
    }
}
echo $check ? "Candidate generated artifacts are current.\n" : "Candidate artifacts prepared. Commit only changed generated files, then qualify the exact commit.\n";
