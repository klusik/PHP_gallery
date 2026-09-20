<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect relational-feature persistence ownership.
 * Responsibilities:
 *   - Reject SQL/PDO leakage from tags, Smart Galleries, localization and voting services.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage5_relational_features_persistence_boundary_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/**
 * Protect the Stage 5 relational-feature service-to-model persistence boundary.
 *
 * Stage 5 moves database ownership for tags, Smart Galleries, localization,
 * favourites, votes, and Picture Game state into app/models. The listed
 * services may own validation, access policy, ranking, rule normalization,
 * request caches, and orchestration, but they must not regain SQL literals,
 * direct db() calls, PDO methods, or Smart Gallery SQL-fragment APIs.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$serviceFiles = [
    'app/services/tag_metadata.php',
    'app/services/smart_galleries.php',
    'app/services/content_localization.php',
    'app/services/favorite_galleries.php',
    'app/services/viewer_favourites.php',
    'app/services/votes.php',
    'app/services/picture_game.php',
];

/** Return whether one PHP string token contains executable SQL syntax. */
function stage5_relational_looks_like_sql(string $value): bool
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
    ] as $pattern) {
        if (preg_match($pattern, $value) === 1) return true;
    }
    return false;
}

/** Return the next significant token index after one token. */
function stage5_relational_next_token_index(array $tokens, int $index): ?int
{
    for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        return $cursor;
    }
    return null;
}

/** Return whether one T_STRING token is a direct function call. */
function stage5_relational_is_function_call(array $tokens, int $index): bool
{
    $next = stage5_relational_next_token_index($tokens, $index);
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
    if (!is_file($path)) throw new RuntimeException('Stage 5 service is unavailable: ' . $relativePath);
    $source = file_get_contents($path);
    if ($source === false) throw new RuntimeException('Unable to read Stage 5 service: ' . $relativePath);
    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) continue;
        [$tokenId, $text, $line] = $token;
        if ($tokenId === T_STRING && strtolower($text) === 'db' && stage5_relational_is_function_call($tokens, $index)) {
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
            && stage5_relational_looks_like_sql($text)) {
            $violations[] = "$relativePath:$line SQL literal";
        }
    }
}

$smartService = (string) file_get_contents($root . '/app/services/smart_galleries.php');
$smartModel = (string) file_get_contents($root . '/app/models/smart_galleries.php');
foreach (['smart_gallery_compile_rules', 'smart_gallery_compile_node', 'smart_gallery_compile_condition', 'smart_gallery_compile_scalar', 'smart_gallery_result_query', 'smart_gallery_query_where', 'smart_gallery_order_sql'] as $forbiddenFunction) {
    if (str_contains($smartService, 'function ' . $forbiddenFunction . '(')) {
        $violations[] = 'app/services/smart_galleries.php SQL-fragment API ' . $forbiddenFunction . '()';
    }
}
if (!str_contains($smartService, 'function smart_gallery_query_semantics(')
    || !str_contains($smartModel, 'function smart_gallery_model_result_query(')) {
    $violations[] = 'Smart Gallery semantic service/model query boundary is incomplete';
}

foreach ([
    'app/models/smart_galleries.php',
    'app/models/content_localization.php',
    'app/models/favorite_galleries.php',
    'app/models/viewer_favourites.php',
    'app/models/votes.php',
    'app/models/picture_game.php',
    'app/models/tags.php',
] as $modelPath) {
    if (!is_file($root . '/' . $modelPath)) $violations[] = 'Stage 5 model is unavailable: ' . $modelPath;
}

if ($violations !== []) {
    throw new RuntimeException("Stage 5 relational-feature persistence boundary violations:\n  - " . implode("\n  - ", $violations));
}

fwrite(STDOUT, "Stage 5 relational-feature persistence boundary checks passed.\n");
