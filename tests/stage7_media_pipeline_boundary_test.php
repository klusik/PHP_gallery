<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage7_media_pipeline_boundary_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the Stage 7 upload, thumbnail, AI, media, download, and automation MVC boundaries.
 *
 * Responsibilities:
 *   - Keep persistence and SQL construction out of Stage 7 services
 *   - Keep request globals and HTTP response emission out of reusable Stage 7 services
 *   - Preserve dedicated model ownership for durable queue/cache/media state
 *   - Cover split browser-upload service modules as part of the same boundary
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
 * Stage 7 keeps persistence in models, reusable media/filesystem orchestration
 * in services, and request/response transport in controllers/Core HTTP helpers.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$serviceFiles = [
    'app/services/uploads.php',
    'app/services/browser_uploads.php',
    'app/services/upload_automation.php',
    'app/services/thumbnail_metadata.php',
    'app/services/thumbnail_generation.php',
    'app/services/thumbnail_maintenance.php',
    'app/services/thumbnail_bounds.php',
    'app/services/ai_image_analysis.php',
    'app/services/downloads.php',
    'app/services/download_artifact_cache.php',
    'app/services/download_manifest_cache.php',
    'app/services/download_signatures.php',
    'app/services/media_renamer.php',
    'app/services/mobile_webdav.php',
    'app/services/flight_maps.php',
    'app/services/lightbox_metadata.php',
    'app/services/public_gallery_media_manifest.php',
];

$browserUploadDirectory = $root . '/app/services/browser_uploads';
if (is_dir($browserUploadDirectory)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($browserUploadDirectory, FilesystemIterator::SKIP_DOTS)
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
function stage7_media_looks_like_sql(string $value): bool
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
function stage7_media_next_token_index(array $tokens, int $index): ?int
{
    for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        return $cursor;
    }
    return null;
}

/** Return whether one T_STRING token is a direct function call. */
function stage7_media_is_function_call(array $tokens, int $index): bool
{
    $next = stage7_media_next_token_index($tokens, $index);
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
$transportFunctions = [
    'header',
    'http_response_code',
    'setcookie',
    'session_start',
    'session_regenerate_id',
    'session_destroy',
];
$transportVariables = ['$_SESSION', '$_COOKIE', '$_SERVER', '$_REQUEST', '$_POST', '$_GET', '$_FILES'];

foreach ($serviceFiles as $relativePath) {
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) {
        $violations[] = 'Stage 7 service is unavailable: ' . $relativePath;
        continue;
    }
    $source = file_get_contents($path);
    if ($source === false) {
        $violations[] = 'Unable to read Stage 7 service: ' . $relativePath;
        continue;
    }
    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) continue;
        [$tokenId, $text, $line] = $token;
        if ($tokenId === T_STRING && strtolower($text) === 'db' && stage7_media_is_function_call($tokens, $index)) {
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
        if ($tokenId === T_STRING && in_array(strtolower($text), $transportFunctions, true)
            && stage7_media_is_function_call($tokens, $index)) {
            $violations[] = "$relativePath:$line direct transport call $text()";
            continue;
        }
        if ($tokenId === T_VARIABLE && in_array($text, $transportVariables, true)) {
            $violations[] = "$relativePath:$line direct request/session variable $text";
            continue;
        }
        if (in_array($tokenId, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && stage7_media_looks_like_sql($text)) {
            $violations[] = "$relativePath:$line SQL literal";
        }
    }
}

foreach ([
    'app/models/ai_image_analysis.php',
    'app/models/download_signatures.php',
    'app/models/downloads.php',
    'app/models/flight_maps.php',
    'app/models/lightbox_metadata.php',
    'app/models/media_renamer.php',
    'app/models/mobile_webdav.php',
    'app/models/thumbnail_maintenance.php',
    'app/models/thumbnail_metadata.php',
    'app/models/upload_automation.php',
] as $modelPath) {
    if (!is_file($root . '/' . $modelPath)) $violations[] = 'Stage 7 model is unavailable: ' . $modelPath;
}

if ($violations !== []) {
    throw new RuntimeException("Stage 7 media-pipeline boundary violations:\n  - " . implode("\n  - ", $violations));
}

fwrite(STDOUT, "Stage 7 media-pipeline boundary checks passed.\n");
