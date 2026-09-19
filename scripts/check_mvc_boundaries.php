<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/check_mvc_boundaries.php
 * Module Type: Architecture Contract CLI
 *
 * Purpose:
 *   Enforces the repository MVC dependency and responsibility boundaries while
 *   the legacy codebase is migrated incrementally toward strict MVC.
 *
 * Responsibilities:
 *   - Tokenize application PHP files without treating comments/docblocks as code
 *   - Detect forbidden cross-layer dependencies and responsibility leakage
 *   - Compare current violations with a reviewed, explicit legacy baseline
 *   - Reject every new violation and require resolved baseline entries to be removed
 *   - Inventory the complete first-party PHP runtime for historical architecture hotspots
 *   - Emit a bounded machine-readable JSON report for follow-up remediation
 *   - Provide controlled baseline creation and reduction workflows
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
 *   - The baseline is migration debt, not an allowlist for new code.
 *   - --refresh-baseline may only remove resolved entries. It refuses new violations.
 *
 * Last Updated:
 *   2026-09-19
 */

declare(strict_types=1);

namespace PhpGallery\MvcBoundary;

use RuntimeException;

const DEFAULT_BASELINE = 'scripts/mvc_boundary_baseline.json';

/**
 * Return the project layer for one relative PHP path.
 *
 * @param string $relativePath Project-relative file path.
 * @return ?string Layer name or null for files outside the MVC layer roots.
 */
function layer_for_path(string $relativePath): ?string
{
    $relativePath = str_replace('\\', '/', $relativePath);
    foreach (['models', 'services', 'controllers', 'views'] as $layer) {
        if (str_starts_with($relativePath, 'app/' . $layer . '/')) {
            return $layer;
        }
    }
    return null;
}

/**
 * Return the next non-whitespace, non-comment token index.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $index Current token index.
 * @return ?int Next significant token index.
 */
function next_significant_token_index(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($cursor = $index + 1; $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $cursor;
    }
    return null;
}

/**
 * Normalize one source line for stable baseline fingerprints.
 *
 * @param string $line Source line.
 * @return string Stable normalized snippet.
 */
function normalize_snippet(string $line): string
{
    $line = trim($line);
    return preg_replace('/\s+/u', ' ', $line) ?? $line;
}

/**
 * Add one violation if the same exact occurrence was not already recorded.
 *
 * @param array<int, array<string, mixed>> $violations Violation accumulator.
 * @param string $path Project-relative path.
 * @param string $rule Stable rule identifier.
 * @param int $line Source line.
 * @param string $snippet Human-readable source excerpt.
 */
function add_violation(array &$violations, string $path, string $rule, int $line, string $snippet): void
{
    $normalized = normalize_snippet($snippet);
    $signature = hash('sha256', $path . "\n" . $rule . "\n" . $normalized);
    $dedupeKey = $signature . ':' . $line;
    foreach ($violations as $existing) {
        if (($existing['_dedupe'] ?? '') === $dedupeKey) {
            return;
        }
    }
    $violations[] = [
        'signature' => $signature,
        'path' => $path,
        'rule' => $rule,
        'line' => $line,
        'snippet' => $normalized,
        '_dedupe' => $dedupeKey,
    ];
}

/**
 * Return whether one string token looks like an SQL statement or SQL fragment.
 *
 * @param string $value String token value.
 * @return bool True for SQL syntax owned by persistence code.
 */
function looks_like_sql(string $value): bool
{
    $patterns = [
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
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $value) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * Return whether one token is a direct function call rather than a declaration.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $index Token index.
 * @return bool True when the token is followed by an opening parenthesis.
 */
function token_is_function_call(array $tokens, int $index): bool
{
    $next = next_significant_token_index($tokens, $index);
    if ($next === null || $tokens[$next] !== '(') {
        return false;
    }

    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $previous = $tokens[$cursor];
        if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_NEW], true)) {
            return false;
        }
        break;
    }
    return true;
}

/**
 * Return source lines with safe 1-based lookup.
 *
 * @param string $source PHP source.
 * @return array<int, string> 1-based source lines.
 */
function source_lines(string $source): array
{
    $raw = preg_split('/\R/u', $source) ?: [];
    $lines = [];
    foreach ($raw as $index => $line) {
        $lines[$index + 1] = $line;
    }
    return $lines;
}

/**
 * Inspect one PHP source file for MVC boundary violations.
 *
 * @param string $source PHP source.
 * @param string $relativePath Project-relative path.
 * @return array<int, array<string, mixed>> Detected violations.
 */
function scan_source(string $source, string $relativePath): array
{
    $layer = layer_for_path($relativePath);
    if ($layer === null) {
        return [];
    }

    $tokens = token_get_all($source);
    $lines = source_lines($source);
    $violations = [];
    $requestGlobals = ['$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_COOKIE', '$_SERVER'];
    $viewGlobals = array_merge($requestGlobals, ['$_SESSION']);
    $responseFunctions = ['header', 'http_response_code', 'setcookie', 'setrawcookie'];
    $filesystemMutationFunctions = ['file_put_contents', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'chmod', 'chown', 'touch', 'symlink', 'link'];
    $pdoMethods = ['prepare', 'query', 'exec', 'beginTransaction', 'commit', 'rollBack'];
    $line = 1;

    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }
        [$tokenId, $value, $tokenLine] = $token;
        $line = $tokenLine;
        $snippet = $lines[$line] ?? $value;

        if ($tokenId === T_VARIABLE) {
            $forbiddenGlobals = $layer === 'views' ? $viewGlobals : $requestGlobals;
            if (($layer === 'models' || $layer === 'services' || $layer === 'views') && in_array($value, $forbiddenGlobals, true)) {
                add_violation($violations, $relativePath, $layer . '.request_global', $line, $snippet);
            }
        }

        if ($tokenId === T_STRING && token_is_function_call($tokens, $index)) {
            $name = strtolower($value);
            if (($layer === 'services' || $layer === 'controllers' || $layer === 'views') && $name === 'db') {
                add_violation($violations, $relativePath, $layer . '.direct_db', $line, $snippet);
            }
            if (($layer === 'models' || $layer === 'services' || $layer === 'views') && in_array($name, $responseFunctions, true)) {
                add_violation($violations, $relativePath, $layer . '.http_response', $line, $snippet);
            }
            if ($layer === 'views' && in_array($name, $filesystemMutationFunctions, true)) {
                add_violation($violations, $relativePath, 'views.filesystem_mutation', $line, $snippet);
            }
        }

        if ($tokenId === T_STRING && in_array($value, $pdoMethods, true)) {
            for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                $previous = $tokens[$cursor];
                if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($previous === '->' || (is_array($previous) && $previous[0] === T_OBJECT_OPERATOR)) {
                    if ($layer === 'services' || $layer === 'controllers' || $layer === 'views') {
                        add_violation($violations, $relativePath, $layer . '.pdo_method', $line, $snippet);
                    }
                }
                break;
            }
        }

        if (in_array($tokenId, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && looks_like_sql($value)) {
            if ($layer === 'services' || $layer === 'controllers' || $layer === 'views') {
                add_violation($violations, $relativePath, $layer . '.sql_literal', $line, $snippet);
            }
        }

        // String-based namespace probes such as function_exists('Gallery\\Services\\...')
        // are still real layer dependencies. Detect them explicitly so a View cannot
        // bypass the import-based dependency rule and later fail with an undefined
        // namespaced function after the import is removed during MVC refactoring.
        if ($layer === 'views' && $tokenId === T_CONSTANT_ENCAPSED_STRING) {
            $stringValue = trim($value, "'\"");
            $stringValue = str_replace('\\\\', '\\', $stringValue);
            $servicePrefix = 'Gallery\\Services\\';
            if (str_starts_with($stringValue, $servicePrefix)) {
                $serviceSymbol = substr($stringValue, strlen($servicePrefix));
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $serviceSymbol) === 1 && strtolower($serviceSymbol) !== 't') {
                    add_violation($violations, $relativePath, 'views.service_dependency', $line, $snippet);
                }
            }
        }

        if ($tokenId === T_ECHO || $tokenId === T_PRINT) {
            if ($layer === 'models' || $layer === 'services') {
                add_violation($violations, $relativePath, $layer . '.presentation_output', $line, $snippet);
            } elseif ($layer === 'controllers') {
                $window = implode("\n", array_slice($lines, max(1, $line) - 1, 3, true));
                if (preg_match('/<[A-Za-z!][^>]*>|&(?:nbsp|times|#\d+);/i', $window) === 1) {
                    add_violation($violations, $relativePath, 'controllers.html_output', $line, $snippet);
                }
            }
        }

        $nameTokenIds = [T_STRING];
        if (defined('T_NAME_QUALIFIED')) {
            $nameTokenIds[] = T_NAME_QUALIFIED;
        }
        if (defined('T_NAME_FULLY_QUALIFIED')) {
            $nameTokenIds[] = T_NAME_FULLY_QUALIFIED;
        }
        if (in_array($tokenId, $nameTokenIds, true)) {
            $qualified = ltrim($value, '\\');
            if ($layer === 'models' && preg_match('/^Gallery\\\\(?:Services|Controllers|Views)\\\\/i', $qualified) === 1) {
                add_violation($violations, $relativePath, 'models.upward_dependency', $line, $snippet);
            }
            if ($layer === 'services' && preg_match('/^Gallery\\\\(?:Controllers|Views)\\\\/i', $qualified) === 1) {
                add_violation($violations, $relativePath, 'services.upward_dependency', $line, $snippet);
            }
            if ($layer === 'controllers' && preg_match('/^Gallery\\\\Models\\\\/i', $qualified) === 1) {
                add_violation($violations, $relativePath, 'controllers.model_dependency', $line, $snippet);
            }
            if ($layer === 'views' && preg_match('/^Gallery\\\\Models\\\\/i', $qualified) === 1) {
                add_violation($violations, $relativePath, 'views.model_dependency', $line, $snippet);
            }
            if ($layer === 'views' && preg_match('/^Gallery\\\\Services\\\\(.+)$/i', $qualified, $matches) === 1) {
                $serviceSymbol = strtolower((string) preg_replace('/^.*\\\\/', '', $matches[1]));
                if ($serviceSymbol !== 't') {
                    add_violation($violations, $relativePath, 'views.service_dependency', $line, $snippet);
                }
            }
        }
    }

    foreach ($violations as &$violation) {
        unset($violation['_dedupe']);
    }
    unset($violation);
    return $violations;
}

/**
 * Return every PHP file under the MVC layer roots.
 *
 * @param string $root Project root.
 * @return array<int, string> Absolute file paths.
 */
function mvc_php_files(string $root): array
{
    $files = [];
    foreach (['models', 'services', 'controllers', 'views'] as $layer) {
        $directory = $root . '/app/' . $layer;
        if (!is_dir($directory)) {
            continue;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files, SORT_STRING);
    return $files;
}


/**
 * Return every first-party PHP file that participates in the web runtime.
 *
 * Tests, CLI-only scripts, migration definitions under database/migrations, and
 * configuration examples are intentionally excluded. They have different
 * architectural ownership rules and are audited by their dedicated suites.
 *
 * @param string $root Project root.
 * @return array<int, string> Absolute PHP runtime paths.
 */
function runtime_php_files(string $root): array
{
    $files = [];
    $appDirectory = $root . '/app';
    if (is_dir($appDirectory)) {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    foreach (['index.php', 'install.php', 'reset.php', 'setup-gallery.php', 'public/index.php'] as $relativePath) {
        $path = $root . '/' . $relativePath;
        if (is_file($path)) {
            $files[] = $path;
        }
    }

    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Classify one runtime file into a coarse architecture role.
 *
 * The role is descriptive. Only the canonical MVC roots are currently hard
 * enforcement boundaries; the other roles feed the machine-readable historical
 * inventory so legacy responsibilities can be migrated without guessing.
 *
 * @param string $relativePath Project-relative path.
 * @return string Stable architecture role.
 */
function architecture_role_for_path(string $relativePath): string
{
    $relativePath = str_replace('\\', '/', $relativePath);
    $layer = layer_for_path($relativePath);
    if ($layer !== null) {
        return rtrim($layer, 's');
    }
    if (str_starts_with($relativePath, 'app/bootstrap/') || $relativePath === 'app/bootstrap.php' || $relativePath === 'app/early_runtime.php') {
        return 'bootstrap';
    }
    if (in_array($relativePath, ['app/database.php', 'app/migrations.php', 'app/migration_definitions.php', 'app/migration_repairs.php'], true)) {
        return 'infrastructure';
    }
    if (in_array($relativePath, ['app/models.php', 'app/services.php', 'app/controllers.php', 'app/views.php'], true)) {
        return 'loader';
    }
    if ($relativePath === 'app/request_data.php' || $relativePath === 'app/helpers_request.php') {
        return 'request_adapter';
    }
    if ($relativePath === 'app/security.php') {
        return 'security_compatibility';
    }
    if (str_starts_with($relativePath, 'app/helpers')) {
        return 'helper';
    }
    if (str_starts_with($relativePath, 'app/diagnostics/')) {
        return 'diagnostics';
    }
    if (str_starts_with($relativePath, 'app/lang/')) {
        return 'localization';
    }
    if ($relativePath === 'public/index.php' || $relativePath === 'index.php') {
        return 'entrypoint';
    }
    if (in_array($relativePath, ['install.php', 'reset.php', 'setup-gallery.php'], true)) {
        return 'setup';
    }
    if (str_starts_with($relativePath, 'app/')) {
        return 'core';
    }
    return 'other';
}

/**
 * Record one bounded architecture signal occurrence.
 *
 * @param array<string, array{count:int,evidence:array<int,array{line:int,snippet:string}>}> $signals Signal accumulator.
 * @param string $name Stable signal name.
 * @param int $line Source line.
 * @param string $snippet Human-readable source excerpt.
 * @param int $evidenceLimit Maximum retained evidence rows per signal.
 */
function record_architecture_signal(array &$signals, string $name, int $line, string $snippet, int $evidenceLimit = 8): void
{
    if (!isset($signals[$name])) {
        $signals[$name] = ['count' => 0, 'evidence' => []];
    }
    $signals[$name]['count']++;
    if (count($signals[$name]['evidence']) < $evidenceLimit) {
        $signals[$name]['evidence'][] = [
            'line' => $line,
            'snippet' => normalize_snippet($snippet),
        ];
    }
}

/**
 * Return the count of one architecture signal.
 *
 * @param array<string, array{count:int,evidence:array<int,array{line:int,snippet:string}>}> $signals Signal map.
 * @param string $name Signal name.
 * @return int Occurrence count.
 */
function architecture_signal_count(array $signals, string $name): int
{
    return (int) ($signals[$name]['count'] ?? 0);
}

/**
 * Inspect one runtime PHP source as text/tokens and return architecture signals.
 *
 * This function never includes or executes the inspected file. It deliberately
 * captures neutral evidence rather than trying to infer every historical intent.
 * Strict MVC enforcement remains in scan_source(); these signals provide the
 * broader dataset used to find and prioritize legacy responsibilities.
 *
 * @param string $source PHP source.
 * @param string $relativePath Project-relative path.
 * @return array<string, mixed> File inventory record.
 */
function scan_architecture_source(string $source, string $relativePath): array
{
    $tokens = token_get_all($source);
    $lines = source_lines($source);
    $signals = [];
    $requestGlobals = ['$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_COOKIE', '$_SERVER'];
    $responseFunctions = ['header', 'http_response_code', 'setcookie', 'setrawcookie'];
    $filesystemMutationFunctions = ['file_put_contents', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'chmod', 'chown', 'touch', 'symlink', 'link'];
    $pdoMethods = ['prepare', 'query', 'exec', 'beginTransaction', 'commit', 'rollBack'];
    $nameTokenIds = [T_STRING];
    if (defined('T_NAME_QUALIFIED')) {
        $nameTokenIds[] = T_NAME_QUALIFIED;
    }
    if (defined('T_NAME_FULLY_QUALIFIED')) {
        $nameTokenIds[] = T_NAME_FULLY_QUALIFIED;
    }

    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }
        [$tokenId, $value, $line] = $token;
        $snippet = $lines[$line] ?? $value;

        if ($tokenId === T_VARIABLE) {
            if (in_array($value, $requestGlobals, true)) {
                record_architecture_signal($signals, 'request_global', $line, $snippet);
            } elseif ($value === '$_SESSION') {
                record_architecture_signal($signals, 'session_global', $line, $snippet);
            }
        }

        if ($tokenId === T_STRING && token_is_function_call($tokens, $index)) {
            $name = strtolower($value);
            if ($name === 'db') {
                record_architecture_signal($signals, 'direct_db', $line, $snippet);
            }
            if (in_array($name, $responseFunctions, true)) {
                record_architecture_signal($signals, 'http_response', $line, $snippet);
            }
            if (in_array($name, $filesystemMutationFunctions, true)) {
                record_architecture_signal($signals, 'filesystem_mutation', $line, $snippet);
            }
        }

        if ($tokenId === T_STRING && in_array($value, $pdoMethods, true)) {
            for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                $previous = $tokens[$cursor];
                if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($previous === '->' || (is_array($previous) && $previous[0] === T_OBJECT_OPERATOR)) {
                    record_architecture_signal($signals, 'pdo_method', $line, $snippet);
                }
                break;
            }
        }

        if (in_array($tokenId, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && looks_like_sql($value)) {
            record_architecture_signal($signals, 'sql_literal', $line, $snippet);
        }

        if ($tokenId === T_ECHO || $tokenId === T_PRINT || $tokenId === T_INLINE_HTML) {
            if ($tokenId !== T_INLINE_HTML || trim($value) !== '') {
                record_architecture_signal($signals, 'presentation_output', $line, $snippet);
            }
        }

        if (in_array($tokenId, [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            record_architecture_signal($signals, 'include_require', $line, $snippet);
        }

        if (in_array($tokenId, $nameTokenIds, true)) {
            $qualified = ltrim($value, '\\');
            if (preg_match('/^Gallery\\\\Models\\\\/i', $qualified) === 1) {
                record_architecture_signal($signals, 'dependency_model', $line, $snippet);
            } elseif (preg_match('/^Gallery\\\\Services\\\\/i', $qualified) === 1) {
                record_architecture_signal($signals, 'dependency_service', $line, $snippet);
            } elseif (preg_match('/^Gallery\\\\Controllers\\\\/i', $qualified) === 1) {
                record_architecture_signal($signals, 'dependency_controller', $line, $snippet);
            } elseif (preg_match('/^Gallery\\\\Views\\\\/i', $qualified) === 1) {
                record_architecture_signal($signals, 'dependency_view', $line, $snippet);
            }
        }
    }

    ksort($signals, SORT_STRING);
    $score = 0;
    foreach ($signals as $signal) {
        $score += (int) ($signal['count'] ?? 0);
    }

    return [
        'path' => str_replace('\\', '/', $relativePath),
        'role' => architecture_role_for_path($relativePath),
        'lines' => count($lines),
        'signal_count' => $score,
        'signals' => $signals,
    ];
}

/**
 * Convert neutral per-file signals into bounded architecture review candidates.
 *
 * Review candidates are deliberately advisory. They cover historical runtime
 * areas that do not yet have hard ownership rules in scan_source(). Once the
 * project is cleaned, individual candidate rules can be promoted to strict
 * enforcement without changing the evidence collector.
 *
 * @param array<string, mixed> $record File inventory record.
 * @return array<int, array<string, mixed>> Candidate rows.
 */
function architecture_review_candidates(array $record): array
{
    $path = (string) ($record['path'] ?? '');
    $role = (string) ($record['role'] ?? 'other');
    $signals = is_array($record['signals'] ?? null) ? $record['signals'] : [];
    $candidates = [];

    $append = static function (string $rule, string $signal, string $reason) use (&$candidates, $path, $role, $signals): void {
        $count = architecture_signal_count($signals, $signal);
        if ($count <= 0) {
            return;
        }
        if (!isset($candidates[$rule])) {
            $candidates[$rule] = [
                'path' => $path,
                'role' => $role,
                'rule' => $rule,
                'count' => 0,
                'reason' => $reason,
                'signals' => [],
            ];
        }
        $candidates[$rule]['count'] += $count;
        $candidates[$rule]['signals'][$signal] = [
            'count' => $count,
            'evidence' => array_values($signals[$signal]['evidence'] ?? []),
        ];
    };

    if (!in_array($role, ['model', 'infrastructure', 'setup'], true)) {
        foreach (['direct_db', 'pdo_method', 'sql_literal'] as $signal) {
            $append('architecture.persistence_outside_model', $signal, 'Persistence syntax exists outside the canonical model/infrastructure ownership boundary.');
        }
    }
    if (in_array($role, ['service', 'view', 'helper', 'security_compatibility', 'core', 'diagnostics'], true)) {
        $append('architecture.request_outside_http_boundary', 'request_global', 'Request globals are read outside controller/bootstrap/request-adapter ownership.');
    }
    if (in_array($role, ['model', 'service', 'view', 'helper', 'security_compatibility', 'core', 'diagnostics'], true)) {
        $append('architecture.session_state_outside_http_boundary', 'session_global', 'Session state is accessed outside the canonical HTTP boundary and should be reviewed for adapter/controller extraction.');
    }
    if (in_array($role, ['model', 'controller', 'view', 'helper', 'security_compatibility', 'core', 'diagnostics'], true)) {
        $append('architecture.filesystem_mutation_outside_service', 'filesystem_mutation', 'Filesystem mutation exists outside service/infrastructure ownership.');
    }
    if (in_array($role, ['model', 'service', 'view', 'helper', 'core', 'diagnostics'], true)) {
        $append('architecture.response_outside_controller', 'http_response', 'HTTP response manipulation exists outside controller/bootstrap ownership.');
    }
    if (in_array($role, ['model', 'service', 'helper', 'security_compatibility', 'core', 'diagnostics'], true)) {
        $append('architecture.presentation_outside_view', 'presentation_output', 'Presentation output exists outside view/controller response ownership.');
    }

    return array_values($candidates);
}

/**
 * Scan the complete first-party PHP web runtime without executing source files.
 *
 * @param string $root Project root.
 * @return array{files:array<int,array<string,mixed>>,candidates:array<int,array<string,mixed>>,roles:array<string,int>,signals:array<string,int>}
 */
function scan_runtime_architecture(string $root): array
{
    $normalizedRoot = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    $records = [];
    $candidates = [];
    $roles = [];
    $signalTotals = [];

    foreach (runtime_php_files($normalizedRoot) as $absolutePath) {
        $normalizedPath = str_replace('\\', '/', $absolutePath);
        $relativePath = ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
        $source = file_get_contents($absolutePath);
        if ($source === false) {
            throw new RuntimeException('Unable to read runtime source: ' . $relativePath);
        }
        $record = scan_architecture_source($source, $relativePath);
        $records[] = $record;
        $role = (string) $record['role'];
        $roles[$role] = ($roles[$role] ?? 0) + 1;
        foreach (($record['signals'] ?? []) as $signal => $definition) {
            $signalTotals[$signal] = ($signalTotals[$signal] ?? 0) + (int) ($definition['count'] ?? 0);
        }
        array_push($candidates, ...architecture_review_candidates($record));
    }

    usort($records, static fn(array $left, array $right): int => [$right['signal_count'], $left['path']] <=> [$left['signal_count'], $right['path']]);
    usort($candidates, static fn(array $left, array $right): int => [$right['count'], $left['path'], $left['rule']] <=> [$left['count'], $right['path'], $right['rule']]);
    ksort($roles, SORT_STRING);
    ksort($signalTotals, SORT_STRING);

    return [
        'files' => $records,
        'candidates' => $candidates,
        'roles' => $roles,
        'signals' => $signalTotals,
    ];
}

/**
 * Write the machine-readable strict-MVC and historical-runtime report.
 *
 * @param string $path Destination path.
 * @param string $root Project root.
 * @param array<int,array<string,mixed>> $current Current strict violations.
 * @param array<int,array<string,mixed>> $baseline Reviewed strict baseline.
 * @param array{new:array<int,array<string,mixed>>,resolved:array<int,array<string,mixed>>} $comparison Strict comparison.
 */
function write_architecture_report(string $path, string $root, array $current, array $baseline, array $comparison): void
{
    $runtime = scan_runtime_architecture($root);
    $hotspots = array_values(array_filter(
        array_slice($runtime['files'], 0, 100),
        static fn(array $record): bool => (int) ($record['signal_count'] ?? 0) > 0
    ));
    $payload = [
        'schema_version' => 1,
        'generated_at' => date(DATE_ATOM),
        'scope' => 'first-party PHP web runtime; source is tokenized and never included/executed',
        'strict_mvc' => [
            'scanned_files' => count(mvc_php_files($root)),
            'current_violation_count' => count($current),
            'baseline_violation_count' => count($baseline),
            'new_violation_count' => count($comparison['new']),
            'resolved_baseline_count' => count($comparison['resolved']),
            'violations' => array_values($current),
            'new' => array_values($comparison['new']),
            'resolved' => array_values($comparison['resolved']),
        ],
        'runtime_inventory' => [
            'scanned_files' => count($runtime['files']),
            'review_candidate_count' => count($runtime['candidates']),
            'roles' => $runtime['roles'],
            'signal_totals' => $runtime['signals'],
            'review_candidates' => array_values($runtime['candidates']),
            'hotspots' => $hotspots,
        ],
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode architecture report JSON.');
    }
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create architecture report directory: ' . $directory);
    }
    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Unable to write architecture report: ' . $path);
    }
}

/**
 * Scan the project MVC layer roots.
 *
 * @param string $root Project root.
 * @return array<int, array<string, mixed>> All current violations.
 */
function scan_project(string $root): array
{
    $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    $violations = [];
    foreach (mvc_php_files($root) as $absolutePath) {
        $normalizedPath = str_replace('\\', '/', $absolutePath);
        $relativePath = ltrim(substr($normalizedPath, strlen($root)), '/');
        $source = file_get_contents($absolutePath);
        if ($source === false) {
            throw new RuntimeException('Unable to read MVC source: ' . $relativePath);
        }
        array_push($violations, ...scan_source($source, $relativePath));
    }
    usort($violations, static fn(array $left, array $right): int => [$left['path'], $left['line'], $left['rule']] <=> [$right['path'], $right['line'], $right['rule']]);
    return $violations;
}

/**
 * Collapse violations into signature counts.
 *
 * @param array<int, array<string, mixed>> $violations Violation list.
 * @return array<string, int> Signature counts.
 */
function violation_counts(array $violations): array
{
    $counts = [];
    foreach ($violations as $violation) {
        $signature = (string) ($violation['signature'] ?? '');
        if ($signature === '') {
            continue;
        }
        $counts[$signature] = ($counts[$signature] ?? 0) + 1;
    }
    ksort($counts, SORT_STRING);
    return $counts;
}

/**
 * Read and validate the reviewed MVC baseline.
 *
 * @param string $path Baseline path.
 * @return array<int, array<string, mixed>> Baseline entries.
 */
function read_baseline(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('MVC baseline is missing: ' . $path);
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !is_array($decoded['violations'] ?? null)) {
        throw new RuntimeException('MVC baseline has invalid JSON structure: ' . $path);
    }
    return $decoded['violations'];
}

/**
 * Write a stable, human-reviewable MVC baseline.
 *
 * @param string $path Baseline path.
 * @param array<int, array<string, mixed>> $violations Current violations.
 */
function write_baseline(string $path, array $violations): void
{
    $payload = [
        'format' => 1,
        'generated_at' => '2026-09-13',
        'policy' => 'Migration debt only. New signatures are forbidden. Refresh may only remove resolved signatures.',
        'violation_count' => count($violations),
        'violations' => array_values($violations),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Unable to write MVC baseline: ' . $path);
    }
}

/**
 * Compare current violations with baseline counts.
 *
 * @param array<int, array<string, mixed>> $current Current violations.
 * @param array<int, array<string, mixed>> $baseline Baseline violations.
 * @return array{new: array<int, array<string, mixed>>, resolved: array<int, array<string, mixed>>}
 */
function compare_with_baseline(array $current, array $baseline): array
{
    $currentCounts = violation_counts($current);
    $baselineCounts = violation_counts($baseline);
    $new = [];
    $resolved = [];

    $currentBySignature = [];
    foreach ($current as $violation) {
        $currentBySignature[(string) $violation['signature']][] = $violation;
    }
    $baselineBySignature = [];
    foreach ($baseline as $violation) {
        $baselineBySignature[(string) $violation['signature']][] = $violation;
    }

    foreach ($currentCounts as $signature => $count) {
        $baselineCount = $baselineCounts[$signature] ?? 0;
        if ($count > $baselineCount) {
            $items = $currentBySignature[$signature] ?? [];
            array_push($new, ...array_slice($items, $baselineCount, $count - $baselineCount));
        }
    }
    foreach ($baselineCounts as $signature => $count) {
        $currentCount = $currentCounts[$signature] ?? 0;
        if ($count > $currentCount) {
            $items = $baselineBySignature[$signature] ?? [];
            array_push($resolved, ...array_slice($items, $currentCount, $count - $currentCount));
        }
    }
    return ['new' => $new, 'resolved' => $resolved];
}

/**
 * Print bounded violation details.
 *
 * @param string $label Heading label.
 * @param array<int, array<string, mixed>> $violations Violation list.
 * @param int $limit Maximum printed rows.
 */
function print_violations(string $label, array $violations, int $limit = 30): void
{
    if ($violations === []) {
        return;
    }
    fwrite(STDOUT, $label . ' (' . count($violations) . "):\n");
    foreach (array_slice($violations, 0, $limit) as $violation) {
        fwrite(STDOUT, '  - ' . $violation['path'] . ':' . $violation['line'] . ' [' . $violation['rule'] . '] ' . $violation['snippet'] . "\n");
    }
    if (count($violations) > $limit) {
        fwrite(STDOUT, '  ... ' . (count($violations) - $limit) . " more\n");
    }
}

/**
 * Execute the CLI boundary checker.
 *
 * @param array<int, string> $argv CLI arguments.
 * @return int Process exit code.
 */
function main(array $argv): int
{
    $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $baselinePath = $root . '/' . DEFAULT_BASELINE;
    $quiet = false;
    $createBaseline = false;
    $refreshBaseline = false;
    $reportJsonPath = null;

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--quiet') {
            $quiet = true;
        } elseif ($argument === '--create-baseline') {
            $createBaseline = true;
        } elseif ($argument === '--refresh-baseline') {
            $refreshBaseline = true;
        } elseif (str_starts_with($argument, '--root=')) {
            $rootArgument = substr($argument, strlen('--root='));
            $root = realpath($rootArgument) ?: $rootArgument;
            $baselinePath = rtrim($root, '/\\') . '/' . DEFAULT_BASELINE;
        } elseif (str_starts_with($argument, '--baseline=')) {
            $baselineArgument = substr($argument, strlen('--baseline='));
            $baselinePath = str_starts_with($baselineArgument, '/') ? $baselineArgument : rtrim($root, '/\\') . '/' . $baselineArgument;
        } elseif (str_starts_with($argument, '--report-json=')) {
            $reportArgument = substr($argument, strlen('--report-json='));
            if ($reportArgument === '') {
                fwrite(STDERR, "--report-json requires a path.\n");
                return 2;
            }
            $reportJsonPath = str_starts_with($reportArgument, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $reportArgument) === 1
                ? $reportArgument
                : rtrim($root, '/\\') . '/' . $reportArgument;
        } elseif ($argument === '--help' || $argument === '-h') {
            fwrite(STDOUT, "Usage: php scripts/check_mvc_boundaries.php [--quiet] [--create-baseline|--refresh-baseline] [--root=PATH] [--baseline=PATH] [--report-json=PATH]\n");
            return 0;
        } else {
            fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
            return 2;
        }
    }

    if ($createBaseline && $refreshBaseline) {
        fwrite(STDERR, "Choose only one baseline operation.\n");
        return 2;
    }

    try {
        $current = scan_project($root);
        if ($createBaseline) {
            if (is_file($baselinePath)) {
                fwrite(STDERR, "MVC baseline already exists. Refusing to replace it with --create-baseline.\n");
                return 2;
            }
            write_baseline($baselinePath, $current);
            fwrite(STDOUT, 'MVC baseline created with ' . count($current) . " reviewed migration-debt occurrences.\n");
            return 0;
        }

        $baseline = read_baseline($baselinePath);
        $comparison = compare_with_baseline($current, $baseline);
        if ($reportJsonPath !== null) {
            write_architecture_report($reportJsonPath, $root, $current, $baseline, $comparison);
        }

        if ($refreshBaseline) {
            if ($comparison['new'] !== []) {
                print_violations('New MVC violations block baseline refresh', $comparison['new']);
                fwrite(STDERR, "Refusing to refresh baseline while new MVC violations exist.\n");
                return 1;
            }
            write_baseline($baselinePath, $current);
            fwrite(STDOUT, 'MVC baseline reduced from ' . count($baseline) . ' to ' . count($current) . " occurrences.\n");
            return 0;
        }

        if (!$quiet) {
            fwrite(STDOUT, 'PHP Gallery MVC boundaries | Current: ' . count($current) . ' | Baseline: ' . count($baseline) . "\n");
        }
        print_violations('New MVC violations', $comparison['new']);
        print_violations('Resolved baseline entries requiring refresh', $comparison['resolved']);

        if ($comparison['new'] !== [] || $comparison['resolved'] !== []) {
            fwrite(STDOUT, 'Result: FAIL | new ' . count($comparison['new']) . ' | resolved-but-not-refreshed ' . count($comparison['resolved']) . "\n");
            return 1;
        }

        fwrite(STDOUT, 'Result: PASS | ' . count($current) . " legacy occurrences are contained by the reviewed baseline.\n");
        return 0;
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'MVC boundary check failed: ' . $exception->getMessage() . "\n");
        return 2;
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(main($argv));
}
