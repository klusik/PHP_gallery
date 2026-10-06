<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/generate_source_debt_baseline.php
 * Module Type: Source Debt Maintenance CLI
 * Purpose: Capture reviewed historical counts or lower existing reliable debt budgets.
 * Responsibilities: Refuse implicit initialization, budget increases, and incomplete reports.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();

/**
 * Parse explicit maintenance modes without accepting a caller-selected output path.
 * @param list<string> $arguments Process arguments including the script name.
 * @return array{mode:string,base:string,help:bool} One mode and optional initial provenance ref.
 */
function source_debt_options(array $arguments): array
{
    $options = ['mode' => 'check', 'base' => '', 'help' => false];
    $modeSeen = false;
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--help') {
            $options['help'] = true;
        } elseif (in_array($argument, ['--check', '--initialize', '--refresh'], true)) {
            if ($modeSeen) {
                throw new InvalidArgumentException('Select exactly one maintenance mode.');
            }
            $modeSeen = true;
            $options['mode'] = substr($argument, 2);
        } elseif (str_starts_with($argument, '--provenance-base=')) {
            if ($options['base'] !== '') {
                throw new InvalidArgumentException('Specify initial provenance once.');
            }
            $options['base'] = substr($argument, strlen('--provenance-base='));
        } else {
            throw new InvalidArgumentException('Unknown source debt maintenance option.');
        }
    }
    if (!$options['help'] && (($options['mode'] === 'initialize') !== ($options['base'] !== ''))) {
        throw new InvalidArgumentException('Only initialization requires --provenance-base=REF.');
    }
    return $options;
}

/**
 * Resolve reviewed provenance through the existing read-only Git transport.
 * @param string $root Repository root containing the reviewed source tree.
 * @param string $reference Commit-ish selected for provenance, never executed by a shell.
 * @return string Full immutable commit SHA; unavailable or unsafe references throw.
 */
function source_debt_commit(string $root, string $reference): string
{
    if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_\/.^~{}@-]*$/D', $reference) !== 1) {
        throw new InvalidArgumentException('Unsupported provenance reference.');
    }
    $result = PhpGallery\SourceContracts\source_git_read(['rev-parse', '--verify', $reference . '^{commit}'], $root);
    $sha = trim($result['stdout']);
    if ($result['status'] !== 0 || preg_match('/^[0-9a-f]{40,64}$/D', $sha) !== 1) {
        throw new RuntimeException('Reviewed Git provenance is unavailable.');
    }
    return $sha;
}

/**
 * Atomically refresh an existing baseline, or exclusively create its first file.
 * @param string $path Repository-owned baseline path, never supplied through the CLI.
 * @param array<string,mixed> $baseline Validated deterministic category baseline.
 * @param bool $initialize Whether creation must refuse an existing destination.
 * @return void Persists checked JSON; a failed write never silently creates a new budget.
 */
function source_debt_write(string $path, array $baseline, bool $initialize): void
{
    $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if ($initialize) {
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Initialization refuses an existing or unwritable baseline.');
        }
        $completed = false;
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Baseline initialization could not complete.');
            }
            $completed = true;
        } finally {
            fclose($handle);
            // Only this exclusive creation is owned here; failed initialization must not strand corrupt budgets.
            if (!$completed && is_file($path)) {
                unlink($path);
            }
        }
        return;
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
    try {
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $path)) {
            throw new RuntimeException('Baseline refresh could not complete.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

try {
    $options = source_debt_options($argv);
    if ($options['help']) {
        echo "Usage: php scripts/generate_source_debt_baseline.php --check | --refresh | --initialize --provenance-base=REF\n";
        echo "Check never writes; refresh only lowers reliable caps; initialize requires an absent baseline and reviewed provenance.\n";
        exit(0);
    }
    require_once __DIR__ . '/check_source_documentation.php';
    require_once __DIR__ . '/check_policy_constants.php';
    require_once __DIR__ . '/source_contracts/debt_ratchet.php';
    $root = dirname(__DIR__);
    $path = __DIR__ . '/source_contract_debt_baseline.json';
    if ($options['mode'] === 'initialize' ? file_exists($path) : !is_file($path)) {
        throw new RuntimeException('Baseline state does not permit the requested maintenance mode.');
    }
    $baseline = null;
    if ($options['mode'] !== 'initialize') {
        $baseline = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($baseline)) {
            throw new RuntimeException('Baseline must contain a JSON object.');
        }
        PhpGallery\SourceDebt\validate_baseline($baseline);
    }
    $initialBaseSha = $options['mode'] === 'initialize' ? source_debt_commit($root, $options['base']) : '';
    $checkpointSha = $options['mode'] === 'initialize' ? source_debt_commit($root, 'HEAD') : '';
    $reports = [
        'documentation' => PhpGallery\SourceContracts\documentation_report($root),
        'policy' => PhpGallery\SourceContracts\policy_report($root),
    ];
    if ($options['mode'] === 'initialize') {
        $baseline = PhpGallery\SourceDebt\create_initial_baseline($reports, $initialBaseSha, $checkpointSha);
        source_debt_write($path, $baseline, true);
    } elseif ($options['mode'] === 'refresh') {
        $baseline = PhpGallery\SourceDebt\refresh_decrease_only($baseline, $reports);
        source_debt_write($path, $baseline, false);
    }
    $evaluation = PhpGallery\SourceDebt\evaluate($baseline, $reports);
    echo json_encode($evaluation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($evaluation['status'] === 'PASS' ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Source debt baseline BLOCKED: ' . $error->getMessage() . "\n");
    exit(2);
}
