<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage8_operational_persistence_boundary_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the Stage 8 logs, telemetry, diagnostics, reporting, and maintenance persistence boundaries.
 *
 * Responsibilities:
 *   - Keep direct application persistence and SQL construction out of Stage 8 services
 *   - Keep operational database access in dedicated model modules
 *   - Keep complete Gallery Report HTML export rendering in the view layer
 *   - Cover split Admin Gallery Report service modules as one architectural boundary
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

/**
 * Stage 8 keeps operational persistence in models and leaves diagnostics,
 * report assembly, maintenance policy, filesystem work, and privacy-safe
 * query-shape classification in services.
 */

declare(strict_types=1);

require_once __DIR__ . '/support/module_source.php';

$root = dirname(__DIR__);
$serviceFiles = [
    'app/services/logs.php',
    'app/services/telemetry.php',
    'app/services/telemetry_rollup.php',
    'app/services/telemetry_settings.php',
    'app/services/admin_dashboard.php',
    'app/services/admin_database_usage.php',
    'app/services/admin_storage_statistics.php',
    'app/services/admin_gallery_discovery.php',
    'app/services/database_maintenance.php',
    'app/services/database_helpers.php',
    'app/services/schema_inspection.php',
    'app/services/database_observer.php',
    'app/services/site_maintenance.php',
];

$optionalServiceFiles = [
    'app/services/admin_log_archives.php',
    'app/services/admin_test_run.php',
];
foreach ($optionalServiceFiles as $relativePath) {
    if (is_file($root . '/' . $relativePath)) {
        $serviceFiles[] = $relativePath;
    }
}

$galleryReportDirectory = $root . '/app/services/admin_gallery_report';
if (is_dir($galleryReportDirectory)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($galleryReportDirectory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo instanceof SplFileInfo && $fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'php') {
            $serviceFiles[] = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($root) + 1));
        }
    }
}
$serviceFiles = array_values(array_unique($serviceFiles));
sort($serviceFiles);

/** Return whether one PHP string token contains executable SQL syntax. */
function stage8_operational_looks_like_sql(string $value): bool
{
    foreach ([
        '/\bSELECT\b[\s\S]{0,500}\bFROM\b/i',
        '/\bINSERT\s+(?:IGNORE\s+)?INTO\b/i',
        '/\bREPLACE\s+INTO\b/i',
        '/\bUPDATE\s+[`A-Za-z_][`A-Za-z0-9_]*\s+SET\b/i',
        '/\bDELETE\s+FROM\b/i',
        '/\bCREATE\s+TABLE\b/i',
        '/\bALTER\s+TABLE\b/i',
        '/\bDROP\s+TABLE\b/i',
        '/\bTRUNCATE\s+TABLE\b/i',
        '/\b(?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN\s+[`A-Za-z_][`A-Za-z0-9_]*\s+ON\b/i',
        '/\bFOR\s+UPDATE\b/i',
    ] as $pattern) {
        if (preg_match($pattern, $value) === 1) return true;
    }
    return false;
}

/** Return the next significant token index after one token. */
function stage8_operational_next_token_index(array $tokens, int $index): ?int
{
    for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        return $cursor;
    }
    return null;
}

/** Return whether one T_STRING token is a direct function call. */
function stage8_operational_is_function_call(array $tokens, int $index): bool
{
    $next = stage8_operational_next_token_index($tokens, $index);
    if ($next === null || $tokens[$next] !== '(') return false;
    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        return !(is_array($token) && in_array($token[0], [T_FUNCTION, T_NEW], true));
    }
    return true;
}

$violations = [];
$pdoMethods = ['prepare', 'query', 'exec', 'beginTransaction', 'commit', 'rollBack'];

foreach ($serviceFiles as $relativePath) {
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) {
        $violations[] = 'Stage 8 service is unavailable: ' . $relativePath;
        continue;
    }
    $source = module_source($path);
    if ($source === '') {
        $violations[] = 'Unable to read Stage 8 service: ' . $relativePath;
        continue;
    }
    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) continue;
        [$tokenId, $text, $line] = $token;
        if ($tokenId === T_STRING && strtolower($text) === 'db' && stage8_operational_is_function_call($tokens, $index)) {
            $violations[] = "$relativePath:$line direct db() call";
            continue;
        }
        if ($tokenId === T_STRING && in_array($text, $pdoMethods, true)) {
            for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                $previous = $tokens[$cursor];
                if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                if ($previous === '->' || (is_array($previous) && $previous[0] === T_OBJECT_OPERATOR)) {
                    $violations[] = "$relativePath:$line PDO method $text()";
                }
                break;
            }
            continue;
        }
        if (in_array($tokenId, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && stage8_operational_looks_like_sql($text)) {
            $violations[] = "$relativePath:$line SQL literal";
        }
    }
}

$requiredModels = [
    'app/models/admin_dashboard.php',
    'app/models/admin_database_usage.php',
    'app/models/admin_gallery_discovery.php',
    'app/models/admin_gallery_report.php',
    'app/models/admin_logs.php',
    'app/models/admin_storage_statistics.php',
    'app/models/database_helpers.php',
    'app/models/database_maintenance.php',
    'app/models/schema_inspection.php',
    'app/models/site_maintenance.php',
    'app/models/telemetry.php',
];
foreach ($requiredModels as $modelPath) {
    if (!is_file($root . '/' . $modelPath)) {
        $violations[] = 'Stage 8 model is unavailable: ' . $modelPath;
    }
}

$reportView = $root . '/app/views/admin_gallery_report_export.php';
if (!is_file($reportView)) {
    $violations[] = 'Admin Gallery Report export view is unavailable.';
} else {
    $reportViewSource = (string) file_get_contents($reportView);
    if (!str_contains($reportViewSource, 'function view_render_admin_gallery_report_export_html(')) {
        $violations[] = 'Admin Gallery Report export view does not own the standalone HTML renderer.';
    }
}

$legacyRenderService = $root . '/app/services/admin_gallery_report/render.php';
if (is_file($legacyRenderService)) {
    $legacySource = (string) file_get_contents($legacyRenderService);
    if (preg_match('/\bfunction\s+admin_gallery_report_render_/i', $legacySource) === 1) {
        $violations[] = 'Legacy Admin Gallery Report service still declares presentation renderers.';
    }
}

$reportController = $root . '/app/controllers/admin_gallery_report.php';
if (!is_file($reportController)) {
    $violations[] = 'Admin Gallery Report controller is unavailable.';
} else {
    $controllerSource = (string) file_get_contents($reportController);
    if (!str_contains($controllerSource, 'view_render_admin_gallery_report_export_html')) {
        $violations[] = 'Admin Gallery Report controller does not apply the export view renderer.';
    }
}

if ($violations !== []) {
    throw new RuntimeException("Stage 8 operational-persistence boundary violations:\n  - " . implode("\n  - ", $violations));
}

fwrite(STDOUT, "Stage 8 operational-persistence boundary checks passed.\n");
