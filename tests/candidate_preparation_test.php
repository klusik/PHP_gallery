<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/candidate_preparation_test.php
 * Module Type: Regression Test
 * Purpose: Protect complete-file hashes, generator idempotence, and stale-candidate refusal.
 * Responsibilities:
 *   - Exercise the real manifest CLI against an isolated synthetic package inventory
 *   - Assert complete SHA-256 coverage for a source file larger than GitHub Contents API's 1 MiB limit
 *   - Refuse changed/missing input without silently replacing the previously published manifest
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$root = sys_get_temp_dir() . '/gallery-candidate-' . bin2hex(random_bytes(8));
$script = dirname(__DIR__) . '/scripts/generate_manifest.php';
$largeRelative = 'public/assets/pdf-worker-large.js';
$largePath = $root . '/' . $largeRelative;
$manifestPath = $root . '/app/core-manifest.json';
$previousEpoch = getenv('SOURCE_DATE_EPOCH');

$run = static function (array $options = []) use ($script, $root): array {
    $process = proc_open(
        array_merge([PHP_BINARY, $script, '--root=' . $root], $options),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start isolated manifest generator.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
};

try {
    foreach ([$root . '/app', $root . '/public/assets'] as $directory) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create isolated manifest fixture.');
        }
    }
    $inventory = [
        'schema_version' => 1,
        'production_files' => ['app/bootstrap.php', 'app/production-files.json', $largeRelative],
        'companion_files' => [],
        'updater_files' => ['app/bootstrap.php', 'app/production-files.json', $largeRelative],
        'source_review_files' => [],
    ];
    $assert(file_put_contents($root . '/app/production-files.json',
        json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false,
        'Could not create production inventory fixture.');
    $assert(file_put_contents($root . '/app/bootstrap.php', "<?php\nconst CMS_VERSION = '0.1';\n") !== false,
        'Could not create version fixture.');

    $largeBytes = str_repeat('w', 1074897);
    $assert(file_put_contents($largePath, $largeBytes) === 1074897,
        'Could not create complete large-file fixture.');
    // Deliberately change the requested epoch between two identical inputs.
    // A timestamp-only rewrite would now be observable without a wall-clock sleep.
    putenv('SOURCE_DATE_EPOCH=1700000000');
    $first = $run();
    $assert($first['exit'] === 0, 'Initial manifest preparation failed: ' . $first['stderr']);
    $before = (string) file_get_contents($manifestPath);
    $manifest = json_decode($before, true, 512, JSON_THROW_ON_ERROR);
    $assert(($manifest['files'][$largeRelative] ?? '') === 'sha256:' . hash('sha256', $largeBytes),
        'Large source digest was not calculated from the complete checked-out bytes.');
    $assert(($manifest['files'][$largeRelative] ?? '') !== 'sha256:' . hash('sha256', ''),
        'Large nonempty source received the empty-input digest.');

    putenv('SOURCE_DATE_EPOCH=1700000123');
    $second = $run();
    $assert($second['exit'] === 0 && str_contains($second['stdout'], 'already current'),
        'Unchanged generator invocation should report a no-op.');
    $assert(file_get_contents($manifestPath) === $before,
        'Unchanged input caused a timestamp-only manifest rewrite.');
    $assert($run(['--check'])['exit'] === 0, 'Fresh published manifest failed its read-only check.');

    $changedBytes = $largeBytes . '// changed input';
    $assert(file_put_contents($largePath, $changedBytes) === strlen($changedBytes),
        'Could not modify large-file fixture.');
    $stale = $run(['--check']);
    $assert($stale['exit'] !== 0 && str_contains($stale['stderr'], $largeRelative),
        'Read-only freshness check accepted a changed large file without a useful path diagnostic.');
    $assert(file_get_contents($manifestPath) === $before,
        'Read-only check changed the published candidate.');
    $assert($run()['exit'] === 0, 'Generator could not refresh a changed large file.');
    $updated = (string) file_get_contents($manifestPath);
    $current = json_decode($updated, true, 512, JSON_THROW_ON_ERROR);
    $assert(($current['files'][$largeRelative] ?? '') === 'sha256:' . hash('sha256', $changedBytes),
        'Generator did not hash the changed complete large file.');

    $assert(unlink($largePath), 'Could not remove the large-file fixture.');
    $missing = $run();
    $assert($missing['exit'] !== 0, 'Generator silently accepted missing source content.');
    $assert(file_get_contents($manifestPath) === $updated,
        'Failed preparation overwrote a valid manifest with missing/empty content.');

    $workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/candidate-preparation.yml');
    $assert(str_contains($workflow, "      - 'feature/**'")
        && str_contains($workflow, 'cancel-in-progress: true')
        && str_contains($workflow, 'php scripts/prepare_candidate.php')
        && str_contains($workflow, 'candidate_sha:')
        && str_contains($workflow, 'uses: ./.github/workflows/gallery-workflows.yml')
        && str_contains($workflow, 'git push origin')
        && !str_contains($workflow, 'git push --force')
        && !str_contains($workflow, 'gh pr merge'),
        'Hosted preparation must be stable, serialized by branch, SHA-bound and non-merging.');

    $ci = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/gallery-workflows.yml');
    $assert(str_contains($ci, 'php scripts/audit.php --profile=candidate-preflight')
        && str_contains($ci, 'needs: preflight'),
        'Expensive CI jobs must depend on the central read-only generated-state preflight.');
} finally {
    putenv($previousEpoch === false ? 'SOURCE_DATE_EPOCH' : 'SOURCE_DATE_EPOCH=' . $previousEpoch);
    @unlink($largePath);
    @unlink($manifestPath);
    @unlink($root . '/app/bootstrap.php');
    @unlink($root . '/app/production-files.json');
    @rmdir($root . '/public/assets');
    @rmdir($root . '/public');
    @rmdir($root . '/app');
    @rmdir($root);
}
echo "PASS candidate manifest complete-byte hashing, idempotence, stale refusal and hosted workflow contracts\n";
