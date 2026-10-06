<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/check_python_import_policy.php
 * Module Type: Python Import Policy CLI
 * Purpose: Forbid postponed annotation imports throughout admitted Python sources.
 * Responsibilities:
 *   - Enforce the whole-tree policy independently of Git history or legacy debt.
 *   - Reuse isolated AST parsing and discovery privacy boundaries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

require_once __DIR__ . '/cli_guard.php';
\gallery_guard_cli_entrypoint(__FILE__);

require_once __DIR__ . '/source_contracts/inventory.php';
require_once __DIR__ . '/source_contracts/python.php';

/**
 * Inspect every admitted Python/PYW source for forbidden annotation imports.
 * @param string $root Repository or disposable fixture root, never request input.
 * @param list<string> $paths Optional exact admitted Python paths for regression fixtures.
 * @return array{status:string,summary:array{source_files:int,finding_count:int},findings:list<array{path:string,line:int,rule:string}>,blocked:list<array{path:string,reason:string}>,coverage:array<string,string>} Whole-tree policy status with bounded source locations.
 */
function python_import_policy_report(string $root, array $paths = []): array
{
    $report = ['status' => 'BLOCKED', 'summary' => ['source_files' => 0, 'finding_count' => 0],
        'findings' => [], 'blocked' => [], 'coverage' => [
            'scope' => 'All admitted .py/.pyw sources including scripts and tests, irrespective of Git changes; existing private/generated/vendor exclusions apply.',
            'policy' => 'AST ImportFrom(__future__) containing annotations is forbidden, including aliases, multiline and combined imports. Comments and strings are inert; concurrent.futures and other future features are allowed.',
            'runtime' => 'Developer/CI audit only. Normal gallery web requests do not invoke Python. Missing Python or invalid source blocks coverage.',
        ]];
    try {
        $pythonFiles = [];
        foreach (inventory($root)['files'] as $path => $extension) {
            if (in_array($extension, ['py', 'pyw'], true)) {
                $pythonFiles[$path] = $extension;
            }
        }
        $selected = $paths === [] ? $pythonFiles : array_intersect_key($pythonFiles, array_fill_keys($paths, true));
        if (array_diff($paths, array_keys($selected)) !== []) {
            throw new \RuntimeException('Selected Python source is absent or excluded.');
        }
        $report['summary']['source_files'] = count($selected);
        $sources = [];
        foreach ($selected as $path => $extension) {
            $source = file_get_contents($root . '/' . $path);
            if (!is_string($source)) {
                throw new \RuntimeException('Admitted Python source is unreadable.');
            }
            $sources[$path] = $source;
        }
        python_source_cache(array_values($sources));
        foreach ($sources as $path => $source) {
            foreach (python_import_findings($source) as $finding) {
                $report['findings'][] = ['path' => $path, 'line' => $finding['line'], 'rule' => $finding['rule']];
            }
        }
        $report['summary']['finding_count'] = count($report['findings']);
        $report['status'] = $report['findings'] === [] ? 'PASS' : 'FAIL';
    } catch (\Throwable $error) {
        $report['blocked'][] = ['path' => '', 'reason' => 'Python source/import inspection could not complete; coverage unknown.'];
    }
    return $report;
}

/**
 * Run the whole-tree Python policy without a changed-only or baseline bypass.
 * @param list<string> $argv CLI arguments including the script name.
 * @return int Zero for compliance, one for forbidden imports, two for incomplete coverage.
 */
function python_import_policy_main(array $argv): int
{
    $root = dirname(__DIR__);
    $json = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--json') {
            $json = true;
        } elseif (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root='));
        } else {
            fwrite(STDERR, "Expected --json or --root=PATH; Python import policy always scans the complete admitted tree.\n");
            return 2;
        }
    }
    $report = python_import_policy_report($root);
    if ($json) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    } else {
        echo 'Python import policy ', $report['status'], ': ', $report['summary']['source_files'],
            ' files; ', $report['summary']['finding_count'], " forbidden imports.\n";
        foreach ($report['findings'] as $finding) {
            echo $finding['path'], ':', $finding['line'], ' ', $finding['rule'], "\n";
        }
        foreach ($report['blocked'] as $blocked) {
            echo 'BLOCKED ', $blocked['reason'], "\n";
        }
    }
    return match ($report['status']) { 'PASS' => 0, 'FAIL' => 1, default => 2 };
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(python_import_policy_main($argv));
}
