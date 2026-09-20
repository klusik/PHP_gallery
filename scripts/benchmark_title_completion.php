<?php

/**
 * Project: PHP Gallery
 * Purpose: Measure title-completion queries and ranking on disposable SQLite catalogs.
 * Responsibilities:
 *   - Generate synthetic catalog sizes and report query/matching costs without live configuration.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/benchmark_title_completion.php
 * Module Type: Synthetic Benchmark
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Measure the real title model/service using disposable SQLite catalogs only.
 * Run from a source checkout; never bootstrap config.php or a live database.
 */
declare(strict_types=1);

use function Gallery\Services\gallery_title_completion_candidates;
use function Gallery\Tests\title_completion_fixture_rows;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$titleCompletionFixturePath = dirname(__DIR__) . '/tests/support/gallery_title_completion_fixture.php';
if (!is_file($titleCompletionFixturePath) || !is_readable($titleCompletionFixturePath)) {
    fwrite(STDERR, "BLOCKED: title completion benchmark requires a source checkout with its test fixtures; deployment packages exclude tests.\n");
    exit(1);
}
if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "BLOCKED: benchmark requires pdo_sqlite.\n");
    exit(1);
}

require_once $titleCompletionFixturePath;
require_once dirname(__DIR__) . '/app/models/galleries.php';
require_once dirname(__DIR__) . '/app/services/gallery_picker.php';

/** Measure nine requests; setup allocations and fixture inserts are excluded. */
function title_completion_measure(callable $request): array
{
    $samples = [];
    $peak = null;
    $result = [];
    for ($run = 0; $run < 9; $run++) {
        unset($result);
        $base = memory_get_usage();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $started = hrtime(true);
        $result = $request();
        $samples[] = (hrtime(true) - $started) / 1000000;
        if (function_exists('memory_reset_peak_usage')) {
            $peak = max($peak ?? 0, memory_get_peak_usage() - $base);
        }
    }
    sort($samples);
    return ['median_ms' => round($samples[4], 3), 'peak_extra_bytes' => $peak, 'result' => $result];
}

$report = ['php' => PHP_VERSION, 'database' => 'disposable sqlite::memory:', 'runs' => 9, 'measurements' => []];
foreach ([100, 1000, 10000] as $size) {
    $fixture = new Gallery\Tests\TitleCompletionFixtureDatabase();
    $GLOBALS['title_completion_fixture'] = $fixture;
    $fixture->seed(title_completion_fixture_rows($size));

    /** Reproduce the removed catalog payload with deterministic synthetic paths. */
    $baseline = title_completion_measure(static function () use ($fixture): array {
        $rows = $fixture->pdo->query('SELECT id, parent_id, title, created_at FROM galleries ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = [
                'id' => (int) $row['id'], 'parent_id' => (int) ($row['parent_id'] ?? 0),
                'title' => trim($row['title']), 'path' => 'synthetic/catalog/' . $row['id'], 'created_at' => $row['created_at'],
            ];
        }
        $encoded = json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['catalog_json_bytes' => strlen($encoded), 'embedded_attribute_value_bytes' => strlen(htmlspecialchars($encoded, ENT_QUOTES, 'UTF-8'))];
    });
    $report['measurements'][] = ['size' => $size, 'scenario' => 'removed_catalog_baseline'] + $baseline;

    foreach ([['sibling_match', 'fl', 7], ['sibling_miss', 'missing', 7], ['fallback_miss', 'missing', 99999]] as [$name, $query, $parent]) {
        /** Measure one real service request against the selected synthetic scope. */
        $measurement = title_completion_measure(static function () use ($fixture, $query, $parent): array {
            $fixture->queries = [];
            $response = gallery_title_completion_candidates($query, $parent);
            return [
                'queries' => count($fixture->queries),
                'sql_ms' => round(array_sum(array_column($fixture->queries, 'milliseconds')), 3),
                'fetched_rows' => array_sum(array_column($fixture->queries, 'rows')),
                'candidates' => count($response['candidates']),
                'response_bytes' => strlen(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'truncated' => $response['truncated'],
            ];
        });
        $report['measurements'][] = ['size' => $size, 'scenario' => $name] + $measurement;
        if ($size === 10000 && $name === 'fallback_miss') {
            foreach ($fixture->queries as $queryRecord) {
                $explain = $fixture->pdo->prepare('EXPLAIN QUERY PLAN ' . $queryRecord['sql']);
                $explain->execute($queryRecord['params']);
                $report['plans'][] = ['sql' => $queryRecord['sql'], 'plan' => $explain->fetchAll(PDO::FETCH_ASSOC)];
            }
        }
    }
}
$report['new_form_model_bytes'] = strlen(json_encode(['candidates' => [], 'url' => '/index.php?page=admin_gallery_title_completion'], JSON_UNESCAPED_SLASHES));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
