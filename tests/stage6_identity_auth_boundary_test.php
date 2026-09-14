<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage6_identity_auth_boundary_test.php
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
 * Protect the Stage 6 identity/auth service boundaries.
 *
 * Stage 6 moves durable Admin/viewer identity persistence into app/models and
 * request/session/cookie transport into the Core viewer/admin identity adapters.
 * The listed services may own credential verification, authorization policy,
 * token generation/hash comparison, rate-limit policy, security-event policy,
 * lifecycle orchestration, and workflow decisions, but they must not regain
 * SQL/PDO ownership or direct PHP request/session/cookie transport access.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$serviceFiles = [
    'app/services/auth_persistence.php',
    'app/services/auth_throttle.php',
    'app/services/viewer_accounts.php',
    'app/services/viewer_admin_accounts.php',
    'app/services/viewer_authentication.php',
    'app/services/viewer_collection_shares.php',
    'app/services/viewer_collections.php',
    'app/services/viewer_content_foundations.php',
    'app/services/viewer_http.php',
    'app/services/viewer_lifecycle.php',
    'app/services/viewer_maintenance.php',
    'app/services/viewer_rate_limits.php',
    'app/services/viewer_registration.php',
    'app/services/viewer_security_events.php',
    'app/services/viewer_security_operations.php',
    'app/services/viewer_tokens.php',
];

/** Return whether one PHP string token contains executable SQL syntax. */
function stage6_identity_looks_like_sql(string $value): bool
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
function stage6_identity_next_token_index(array $tokens, int $index): ?int
{
    for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
        return $cursor;
    }
    return null;
}

/** Return whether one T_STRING token is a direct function call. */
function stage6_identity_is_function_call(array $tokens, int $index): bool
{
    $next = stage6_identity_next_token_index($tokens, $index);
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
    'setcookie',
    'session_start',
    'session_regenerate_id',
    'session_destroy',
    'session_id',
    'session_status',
];
$transportVariables = ['$_SESSION', '$_COOKIE', '$_SERVER', '$_REQUEST', '$_POST', '$_GET', '$_FILES'];

foreach ($serviceFiles as $relativePath) {
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) throw new RuntimeException('Stage 6 service is unavailable: ' . $relativePath);
    $source = file_get_contents($path);
    if ($source === false) throw new RuntimeException('Unable to read Stage 6 service: ' . $relativePath);
    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) continue;
        [$tokenId, $text, $line] = $token;
        if ($tokenId === T_STRING && strtolower($text) === 'db' && stage6_identity_is_function_call($tokens, $index)) {
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
            && stage6_identity_is_function_call($tokens, $index)) {
            $violations[] = "$relativePath:$line direct transport call $text()";
            continue;
        }
        if ($tokenId === T_VARIABLE && in_array($text, $transportVariables, true)) {
            $violations[] = "$relativePath:$line direct request/session variable $text";
            continue;
        }
        if (in_array($tokenId, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && stage6_identity_looks_like_sql($text)) {
            $violations[] = "$relativePath:$line SQL literal";
        }
    }
}

foreach ([
    'app/models/auth.php',
    'app/models/auth_throttle.php',
    'app/models/viewer_accounts.php',
    'app/models/viewer_authentication.php',
    'app/models/viewer_collection_shares.php',
    'app/models/viewer_collections.php',
    'app/models/viewer_lifecycle.php',
    'app/models/viewer_maintenance.php',
    'app/models/viewer_rate_limits.php',
    'app/models/viewer_registration.php',
    'app/models/viewer_security_events.php',
    'app/models/viewer_security_operations.php',
    'app/models/viewer_tokens.php',
] as $modelPath) {
    if (!is_file($root . '/' . $modelPath)) $violations[] = 'Stage 6 model is unavailable: ' . $modelPath;
}

$identityAdapterPath = $root . '/app/bootstrap/viewer_identity_context.php';
$identityAdapter = is_file($identityAdapterPath) ? (string) file_get_contents($identityAdapterPath) : '';
if ($identityAdapter === ''
    || !str_contains($identityAdapter, 'function viewer_identity_session_get(')
    || !str_contains($identityAdapter, 'function viewer_identity_session_set(')
    || !str_contains($identityAdapter, 'function viewer_identity_session_regenerate(')) {
    $violations[] = 'Core viewer identity request/session adapter is incomplete';
}

$securityPath = $root . '/app/security.php';
$securitySource = is_file($securityPath) ? (string) file_get_contents($securityPath) : '';
if ($securitySource === ''
    || !str_contains($securitySource, 'function admin_auth_remember_cookie_value(')
    || !str_contains($securitySource, 'function admin_auth_apply_remember_cookie_instruction(')) {
    $violations[] = 'Core Admin identity request/cookie adapter is incomplete';
}

if ($violations !== []) {
    throw new RuntimeException("Stage 6 identity/auth boundary violations:\n  - " . implode("\n  - ", $violations));
}

fwrite(STDOUT, "Stage 6 identity/auth boundary checks passed.\n");
