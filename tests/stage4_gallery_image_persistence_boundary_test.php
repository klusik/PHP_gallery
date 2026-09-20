<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect gallery/image service-to-model ownership.
 * Responsibilities:
 *   - Reject SQL, direct database access and PDO transaction logic in domain services.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage4_gallery_image_persistence_boundary_test.php
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
 * Protect the Stage 4 gallery/image service-to-model persistence boundary.
 *
 * Stage 4 moves database ownership for the core gallery and image domain into
 * app/models. The listed services may orchestrate filesystem work, validation,
 * authorization, schema policy, and rollback intent, but they must not regain
 * SQL literals, direct db() calls, or PDO transaction/query methods.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$serviceFiles = [
    'app/services/gallery_mutations.php',
    'app/services/gallery_lookup.php',
    'app/services/gallery_trash.php',
    'app/services/gallery_access.php',
    'app/services/gallery_covers.php',
    'app/services/gallery_backgrounds.php',
    'app/services/gallery_branding.php',
    'app/services/gallery_dates.php',
    'app/services/gallery_grid.php',
    'app/services/gallery_picker.php',
    'app/services/gallery_sidecars.php',
    'app/services/public_paths.php',
    'app/services/image_scanning.php',
    'app/services/gallery_metadata_organizer.php',
    'app/services/picture_manager.php',
    'app/services/duplicate_photo_detector.php',
    'app/services/duplicate_photo_ledger.php',
    'app/services/exif.php',
];

/** Return whether one PHP string token contains executable SQL syntax. */
function stage4_gallery_image_looks_like_sql(string $value): bool
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
        if (preg_match($pattern, $value) === 1) {
            return true;
        }
    }
    return false;
}

/** Return the next significant token index after one token. */
function stage4_gallery_image_next_token_index(array $tokens, int $index): ?int
{
    for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $cursor;
    }
    return null;
}

/** Return whether one T_STRING token is a direct function call. */
function stage4_gallery_image_is_function_call(array $tokens, int $index): bool
{
    $next = stage4_gallery_image_next_token_index($tokens, $index);
    if ($next === null || $tokens[$next] !== '(') {
        return false;
    }

    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return !(is_array($token) && in_array($token[0], [T_FUNCTION, T_NEW], true));
    }
    return true;
}

$violations = [];
$pdoMethods = ['prepare', 'query', 'exec', 'beginTransaction', 'commit', 'rollBack'];

foreach ($serviceFiles as $relativePath) {
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) {
        throw new RuntimeException('Stage 4 service is unavailable: ' . $relativePath);
    }

    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read Stage 4 service: ' . $relativePath);
    }

    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }
        [$tokenId, $text, $line] = $token;

        if ($tokenId === T_STRING && strtolower($text) === 'db' && stage4_gallery_image_is_function_call($tokens, $index)) {
            $violations[] = "$relativePath:$line direct db() call";
            continue;
        }

        if ($tokenId === T_STRING && in_array($text, $pdoMethods, true)) {
            for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                $previous = $tokens[$cursor];
                if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($previous === '->' || (is_array($previous) && $previous[0] === T_OBJECT_OPERATOR)) {
                    $violations[] = "$relativePath:$line PDO method $text()";
                }
                break;
            }
            continue;
        }

        if (in_array($tokenId, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && stage4_gallery_image_looks_like_sql($text)) {
            $violations[] = "$relativePath:$line SQL literal";
        }
    }
}

if ($violations !== []) {
    throw new RuntimeException(
        "Stage 4 gallery/image persistence boundary violations:\n  - " . implode("\n  - ", $violations)
    );
}

fwrite(STDOUT, "Stage 4 gallery/image persistence boundary checks passed.\n");
