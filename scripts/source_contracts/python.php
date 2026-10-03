<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/source_contracts/python.php
 * Module Type: Python Source Contract Bridge
 * Purpose: Reuse the central runtime/process owner for isolated Python AST parsing.
 * Responsibilities:
 *   - Batch source text after inventory exclusions and cache immutable parser records.
 *   - Refuse missing runtimes, parser failures and incomplete coverage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

require_once dirname(__DIR__) . '/audit_lib.php';

/**
 * Populate shared immutable Python reports in one bounded AST worker invocation.
 * @param list<string> $sources Source texts already admitted by inventory discovery.
 * @return array<string,array{snapshots:list<array<string,mixed>>,import_findings:list<array{line:int,rule:string}>}> Source identities mapped to AST reports.
 */
function python_source_reports(array $sources): array
{
    static $cache = [];
    static $python = null;
    $missing = [];
    foreach ($sources as $source) {
        $key = hash('sha256', $source);
        if (!isset($cache[$key])) {
            $missing[$key] = $source;
        }
    }
    if ($missing === []) {
        return $cache;
    }
    $python ??= \PhpGallery\Audit\resolve_python_command();
    if ($python === null) {
        throw new \RuntimeException('Python 3 AST runtime unavailable; coverage unknown.');
    }
    $request = tempnam(sys_get_temp_dir(), 'gallery-source-contract-');
    if ($request === false) {
        throw new \RuntimeException('Python AST request unavailable.');
    }
    try {
        if (file_put_contents($request, json_encode($missing, JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException('Python AST request could not be written.');
        }
        $process = \PhpGallery\Audit\run_process(array_merge($python, [__DIR__ . '/python_scan.py', $request]), dirname(__DIR__, 2), 60);
        $reports = json_decode($process['stdout'], true);
        if ($process['timed_out'] || $process['exit_code'] !== 0 || !is_array($reports)
            || array_diff(array_keys($missing), array_keys($reports)) !== []) {
            throw new \RuntimeException('Python AST parsing incomplete; coverage unknown.');
        }
        foreach ($missing as $key => $source) {
            $report = $reports[$key];
            if (!is_array($report) || !isset($report['snapshots'], $report['import_findings'])
                || !is_array($report['snapshots']) || !array_is_list($report['snapshots'])
                || !is_array($report['import_findings']) || !array_is_list($report['import_findings'])) {
                throw new \RuntimeException('Python AST report invalid; coverage unknown.');
            }
            foreach ($report['import_findings'] as $finding) {
                if (!is_array($finding) || !isset($finding['line'], $finding['rule'])
                    || !is_int($finding['line']) || $finding['line'] < 1
                    || $finding['rule'] !== 'python.future_annotations') {
                    throw new \RuntimeException('Python AST import report invalid; coverage unknown.');
                }
            }
        }
        $cache = array_merge($cache, $reports);
    } finally {
        unlink($request);
    }
    return $cache;
}

/**
 * Preserve the declaration-only cache interface over shared AST source reports.
 * @param list<string> $sources Source texts admitted by inventory discovery.
 * @return array<string,list<array<string,mixed>>> Source identities mapped to declaration snapshots.
 */
function python_source_cache(array $sources): array
{
    $snapshots = [];
    foreach (python_source_reports($sources) as $key => $report) {
        $snapshots[$key] = $report['snapshots'];
    }
    return $snapshots;
}

/**
 * Read file-level import policy evidence from the shared immutable AST cache.
 * @param string $source Python/PYW source already admitted by inventory discovery.
 * @return list<array{line:int,rule:string}> Forbidden annotation imports with source coordinates.
 */
function python_import_findings(string $source): array
{
    return python_source_reports([$source])[hash('sha256', $source)]['import_findings'];
}

/**
 * Read cached declaration snapshots without importing the examined Python source.
 * @param string $source Python/PYW source with optional native docstrings.
 * @return list<array<string,mixed>> AST identities, fingerprints and shared contract records.
 */
function python_declaration_snapshots(string $source): array
{
    return python_source_cache([$source])[hash('sha256', $source)];
}
