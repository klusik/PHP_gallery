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

require_once __DIR__ . '/cli_guard.php';
\gallery_guard_cli_entrypoint(__FILE__);

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
 * Return the previous non-whitespace, non-comment token index.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $index Current token index.
 * @return ?int Previous significant token index.
 */
function previous_significant_token_index(array $tokens, int $index): ?int
{
    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $cursor;
    }
    return null;
}

/**
 * Return the enclosing named/anonymous function range for one token.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $targetIndex Token index to locate.
 * @return array{start:int,end:int}|null Callable source range, or null outside a callable.
 */
function callable_scope_for_token(array $tokens, int $targetIndex): ?array
{
    $selected = null;
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $parameterDepth = 0;
        $bodyOpen = null;
        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $candidate = $tokens[$cursor];
            if ($candidate === '(') {
                $parameterDepth++;
            } elseif ($candidate === ')') {
                $parameterDepth--;
            } elseif ($parameterDepth === 0 && $candidate === ';') {
                break;
            } elseif ($parameterDepth === 0 && $candidate === '{') {
                $bodyOpen = $cursor;
                break;
            }
        }
        if ($bodyOpen === null || $bodyOpen >= $targetIndex) {
            continue;
        }
        $braceDepth = 0;
        $bodyClose = null;
        for ($cursor = $bodyOpen; $cursor < count($tokens); $cursor++) {
            if ($tokens[$cursor] === '{') {
                $braceDepth++;
            } elseif ($tokens[$cursor] === '}') {
                $braceDepth--;
                if ($braceDepth === 0) {
                    $bodyClose = $cursor;
                    break;
                }
            }
        }
        if ($bodyClose !== null && $targetIndex < $bodyClose
            && ($selected === null || $index > $selected['start'])) {
            $selected = ['start' => $index, 'end' => $bodyClose];
        }
    }
    return $selected;
}

/**
 * Return the parameter and expression range for an enclosing arrow function.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $targetIndex Token index to locate.
 * @return array{start:int,parameter_end:int,end:int}|null Arrow range, or null.
 */
function arrow_scope_for_token(array $tokens, int $targetIndex): ?array
{
    $selected = null;
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_FN) {
            continue;
        }
        $open = next_significant_token_index($tokens, $index);
        if ($open === null || $tokens[$open] !== '(') {
            continue;
        }
        $close = matching_token_index($tokens, $open, '(', ')', count($tokens));
        if ($close === null) {
            continue;
        }
        $arrow = next_significant_token_index($tokens, $close);
        while ($arrow !== null && $arrow < count($tokens)
            && (!is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_DOUBLE_ARROW)) {
            $arrow = next_significant_token_index($tokens, $arrow);
        }
        if ($arrow === null) {
            continue;
        }
        $end = arrow_expression_end($tokens, $arrow + 1);
        if ($targetIndex > $arrow && $targetIndex < $end
            && ($selected === null || $index > $selected['start'])) {
            $selected = ['start' => $index, 'parameter_end' => $close, 'end' => $end];
        }
    }
    return $selected;
}

/**
 * Find the matching delimiter in a token stream.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $openIndex Opening delimiter index.
 * @param string $open Opening delimiter.
 * @param string $close Closing delimiter.
 * @param int $limit Exclusive search limit.
 * @return ?int Matching closing delimiter index.
 */
function matching_token_index(array $tokens, int $openIndex, string $open, string $close, int $limit): ?int
{
    $depth = 0;
    for ($index = $openIndex; $index < $limit; $index++) {
        if ($tokens[$index] === $open) {
            $depth++;
        } elseif ($tokens[$index] === $close && --$depth === 0) {
            return $index;
        }
    }
    return null;
}

/**
 * Find the end of an arrow expression at the current nesting level.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $start Expression start index.
 * @return int Exclusive expression end.
 */
function arrow_expression_end(array $tokens, int $start): int
{
    $stack = [];
    for ($index = $start, $count = count($tokens); $index < $count; $index++) {
        $token = $tokens[$index];
        if ($token === '(' || $token === '[' || $token === '{') {
            $stack[] = $token;
        } elseif ($token === ')' || $token === ']' || $token === '}') {
            if ($stack === []) {
                return $index;
            }
            array_pop($stack);
        } elseif ($stack === [] && ($token === ';' || $token === ',')) {
            return $index;
        }
    }
    return count($tokens);
}

/**
 * Return PDO typed parameter names from a callable parameter list.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $start Parameter list start.
 * @param int $end Parameter list end.
 * @param array{namespace:string,class_aliases:array<string,string>,function_aliases:array<string,string>} $context Active symbol context.
 * @param bool $promotedOnly Require a visibility modifier for promoted properties.
 * @return array<int,string> Proven PDO parameter names.
 */
function typed_callable_parameters(array $tokens, int $start, int $end, array $context, bool $promotedOnly = false): array
{
    $names = [];
    for ($index = $start; $index < $end; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_VARIABLE) {
            continue;
        }
        $hasType = false;
        $hasVisibility = false;
        $cursor = $index;
        for ($steps = 0; $steps < 12 && ($cursor = previous_significant_token_index($tokens, $cursor)) !== null && $cursor > $start; $steps++) {
            $candidate = $tokens[$cursor];
            if (!is_array($candidate)) {
                if (in_array($candidate, [',', '(', '=', '&', '...'], true)) {
                    break;
                }
                continue;
            }
            if ($candidate[0] === T_VARIABLE) {
                break;
            }
            if (in_array($candidate[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                $hasVisibility = true;
            }
            if (in_array($candidate[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
                && resolves_to_pdo((string) $candidate[1], $context)) {
                $hasType = true;
            }
        }
        if ($hasType && (!$promotedOnly || $hasVisibility)) {
            $names[] = (string) $tokens[$index][1];
        }
    }
    return $names;
}

/**
 * Return the end of a nested function body for scope-local token scans.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $functionIndex Function token index.
 * @param int $limit Exclusive search limit.
 * @return ?int Function body closing brace index.
 */
function callable_body_end(array $tokens, int $functionIndex, int $limit): ?int
{
    $depth = 0;
    $bodyOpen = null;
    for ($cursor = $functionIndex + 1; $cursor < $limit; $cursor++) {
        if ($tokens[$cursor] === '(') {
            $depth++;
        } elseif ($tokens[$cursor] === ')') {
            $depth--;
        } elseif ($depth === 0 && $tokens[$cursor] === '{') {
            $bodyOpen = $cursor;
            break;
        } elseif ($depth === 0 && $tokens[$cursor] === ';') {
            return null;
        }
    }
    if ($bodyOpen === null) {
        return null;
    }
    $braceDepth = 0;
    for ($cursor = $bodyOpen; $cursor < $limit; $cursor++) {
        if ($tokens[$cursor] === '{') {
            $braceDepth++;
        } elseif ($tokens[$cursor] === '}' && --$braceDepth === 0) {
            return $cursor;
        }
    }
    return null;
}

/**
 * Return the enclosing class-like body range for one token.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $targetIndex Token index to locate.
 * @return array{start:int,end:int}|null Class body range, or null outside a class.
 */
function class_scope_for_token(array $tokens, int $targetIndex): ?array
{
    $classTokens = [T_CLASS, T_TRAIT];
    if (defined('T_ENUM')) {
        $classTokens[] = T_ENUM;
    }
    if (defined('T_ANONYMOUS_CLASS')) {
        $classTokens[] = T_ANONYMOUS_CLASS;
    }
    $selected = null;
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || !in_array($token[0], $classTokens, true)) {
            continue;
        }
        if ($token[0] === T_CLASS) {
            $previous = previous_significant_token_index($tokens, $index);
            if ($previous !== null && is_array($tokens[$previous]) && $tokens[$previous][0] === T_DOUBLE_COLON) {
                continue;
            }
        }
        $parameterDepth = 0;
        $bodyOpen = null;
        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $candidate = $tokens[$cursor];
            if ($candidate === '(') {
                $parameterDepth++;
            } elseif ($candidate === ')') {
                $parameterDepth--;
            } elseif ($parameterDepth === 0 && $candidate === ';') {
                break;
            } elseif ($parameterDepth === 0 && $candidate === '{') {
                $bodyOpen = $cursor;
                break;
            }
        }
        if ($bodyOpen === null || $bodyOpen >= $targetIndex) {
            continue;
        }
        $braceDepth = 0;
        $bodyClose = null;
        for ($cursor = $bodyOpen; $cursor < count($tokens); $cursor++) {
            if ($tokens[$cursor] === '{') {
                $braceDepth++;
            } elseif ($tokens[$cursor] === '}') {
                $braceDepth--;
                if ($braceDepth === 0) {
                    $bodyClose = $cursor;
                    break;
                }
            }
        }
        if ($bodyClose !== null && $targetIndex < $bodyClose
            && ($selected === null || $index > $selected['start'])) {
            $selected = ['start' => $bodyOpen, 'end' => $bodyClose];
        }
    }
    return $selected;
}

/**
 * Resolve the file namespace and simple class/function imports.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $targetIndex Token index that selects the active namespace/import context.
 * @return array{namespace:string,class_aliases:array<string,string>,function_aliases:array<string,string>} Resolved namespace and import aliases active at the token.
 */
function source_symbol_context(array $tokens, int $targetIndex): array
{
    $selected = ['namespace' => '', 'class_aliases' => [], 'function_aliases' => []];
    foreach (source_symbol_contexts($tokens) as $index => $context) {
        if ($index > $targetIndex) {
            break;
        }
        $selected = $context;
    }
    return $selected;
}

/**
 * Index namespace and import context changes in one pass over a source stream.
 *
 * @param array<int, array|string> $tokens Token stream owned by one source scan.
 * @return array<int,array{namespace:string,class_aliases:array<string,string>,function_aliases:array<string,string>}> Contexts keyed by the first token index where each applies.
 */
function source_symbol_contexts(array $tokens): array
{
    $namespace = '';
    $braceDepth = 0;
    $namespaceBodyDepth = 0;
    $classAliases = [];
    $functionAliases = [];
    $contexts = [0 => ['namespace' => '', 'class_aliases' => [], 'function_aliases' => []]];
    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        $token = $tokens[$index];
        if (is_array($token) && $token[0] === T_NAMESPACE) {
            $name = '';
            for ($cursor = $index + 1; $cursor < count($tokens); $cursor++) {
                $part = $tokens[$cursor];
                if ($part === ';') {
                    $namespaceBodyDepth = $braceDepth;
                    break;
                }
                if ($part === '{') {
                    $namespaceBodyDepth = $braceDepth + 1;
                    break;
                }
                if (is_array($part) && in_array($part[0], [T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }
                $name .= is_array($part) ? (string) $part[1] : $part;
            }
            $namespace = strtolower(ltrim(trim($name), '\\'));
            $contexts[$index + 1] = ['namespace' => $namespace, 'class_aliases' => $classAliases, 'function_aliases' => $functionAliases];
            continue;
        }
        if (is_array($token) && $token[0] === T_USE && $braceDepth === $namespaceBodyDepth) {
            $cursor = next_significant_token_index($tokens, $index);
            $kind = 'class';
            if ($cursor !== null && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_FUNCTION) {
                $kind = 'function';
                $cursor = next_significant_token_index($tokens, $cursor);
            }
            if ($cursor !== null && is_array($tokens[$cursor])
                && in_array($tokens[$cursor][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $imported = strtolower(ltrim((string) $tokens[$cursor][1], '\\'));
                $aliasIndex = next_significant_token_index($tokens, $cursor);
                if ($aliasIndex !== null && is_array($tokens[$aliasIndex]) && $tokens[$aliasIndex][0] === T_AS) {
                    $aliasIndex = next_significant_token_index($tokens, $aliasIndex);
                } else {
                    $segments = explode('\\', $imported);
                    $aliasIndex = null;
                    $alias = (string) end($segments);
                }
                if ($aliasIndex !== null && is_array($tokens[$aliasIndex]) && $tokens[$aliasIndex][0] === T_STRING) {
                    $alias = strtolower((string) $tokens[$aliasIndex][1]);
                }
                if ($kind === 'function') {
                    $functionAliases[$alias] = $imported;
                } else {
                    $classAliases[$alias] = $imported;
                }
                $contexts[$index + 1] = ['namespace' => $namespace, 'class_aliases' => $classAliases, 'function_aliases' => $functionAliases];
            }
        }
        if ($token === '{') {
            $braceDepth++;
        } elseif ($token === '}') {
            $braceDepth--;
        }
    }
    return $contexts;
}

/**
 * Return whether a class name resolves to PHP's global PDO class.
 *
 * @param string $name Class name token or PHPDoc name.
 * @param array{namespace:string,class_aliases:array<string,string>,function_aliases:array<string,string>} $context File symbol context.
 * @return bool True only for a proven global PDO reference.
 */
function resolves_to_pdo(string $name, array $context): bool
{
    $trimmed = trim($name);
    $absolute = str_starts_with($trimmed, '\\');
    $normalized = strtolower(ltrim($trimmed, '\\?'));
    if ($absolute) {
        return $normalized === 'pdo';
    }
    if (str_contains($normalized, '\\')) {
        return $normalized === 'pdo';
    }
    if (isset($context['class_aliases'][$normalized])) {
        return $context['class_aliases'][$normalized] === 'pdo';
    }
    return $normalized === 'pdo' && ($absolute || $context['namespace'] === '');
}

/**
 * Return whether a function name resolves to the canonical Core db() helper.
 *
 * @param string $name Function name token.
 * @param array{namespace:string,class_aliases:array<string,string>,function_aliases:array<string,string>} $context File symbol context.
 * @return bool True only for Gallery\Core\db.
 */
function resolves_to_core_db(string $name, array $context): bool
{
    $trimmed = trim($name);
    $absolute = str_starts_with($trimmed, '\\');
    $normalized = strtolower(ltrim($trimmed, '\\'));
    if (str_contains($normalized, '\\')) {
        return $normalized === 'gallery\\core\\db';
    }
    if (isset($context['function_aliases'][$normalized])) {
        return $context['function_aliases'][$normalized] === 'gallery\\core\\db';
    }
    return $normalized === 'db' && !$absolute && $context['namespace'] === 'gallery\\core';
}

/**
 * Collect PDO variables in the current callable before a persistence call.
 *
 * Evidence is limited to an explicit PDO type, a direct PDO constructor, a
 * canonical db() result, or an assignment from an already identified PDO variable.
 * Analysis stops at the method call, so unrelated later assignments cannot lend
 * PDO provenance to an earlier ordinary method call or another function scope.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $methodIndex Method-name token index.
 * @param array{namespace:string,class_aliases:array<string,string>,function_aliases:array<string,string>} $context Active symbol context.
 * @return array<string, true> Proven PDO variable names in this callable.
 */
function pdo_variable_provenance(array $tokens, int $methodIndex, array $context): array
{
    $scope = callable_scope_for_token($tokens, $methodIndex);
    $arrowScope = arrow_scope_for_token($tokens, $methodIndex);
    $scopeStart = $arrowScope['start'] ?? $scope['start'] ?? 0;
    $pdoVariables = [];
    if ($arrowScope !== null) {
        $shadowedParameters = [];
        for ($parameterIndex = $arrowScope['start']; $parameterIndex <= $arrowScope['parameter_end']; $parameterIndex++) {
            if (is_array($tokens[$parameterIndex]) && $tokens[$parameterIndex][0] === T_VARIABLE) {
                $shadowedParameters[(string) $tokens[$parameterIndex][1]] = true;
            }
        }
        foreach (pdo_variable_provenance($tokens, $arrowScope['start'], $context) as $outerName => $_) {
            if (!isset($shadowedParameters[$outerName])) {
                $pdoVariables[$outerName] = true;
            }
        }
        foreach (typed_callable_parameters($tokens, $arrowScope['start'], $arrowScope['parameter_end'], $context) as $name) {
            $pdoVariables[$name] = true;
        }
    }
    for ($index = $scopeStart; $index < $methodIndex; $index++) {
        $token = $tokens[$index];
        if (!is_array($token)) {
            continue;
        }
        [$tokenId, $value] = $token;
        if ($tokenId === T_FUNCTION && $index !== ($scope['start'] ?? -1)) {
            $nestedEnd = callable_body_end($tokens, $index, $methodIndex);
            if ($nestedEnd !== null) {
                $index = $nestedEnd;
            }
            continue;
        }
        if ($tokenId === T_FN) {
            if ($arrowScope === null || $arrowScope['start'] !== $index) {
                $parameterOpen = next_significant_token_index($tokens, $index);
                $parameterClose = $parameterOpen !== null && $tokens[$parameterOpen] === '('
                    ? matching_token_index($tokens, $parameterOpen, '(', ')', $methodIndex)
                    : null;
                $arrowToken = $parameterClose === null ? null : next_significant_token_index($tokens, $parameterClose);
                while ($arrowToken !== null && $arrowToken < $methodIndex
                    && (!is_array($tokens[$arrowToken]) || $tokens[$arrowToken][0] !== T_DOUBLE_ARROW)) {
                    $arrowToken = next_significant_token_index($tokens, $arrowToken);
                }
                if ($arrowToken !== null && $arrowToken < $methodIndex) {
                    $nestedEnd = arrow_expression_end($tokens, $arrowToken + 1);
                    $index = min($nestedEnd - 1, $methodIndex - 1);
                }
            }
            continue;
        }
        if ($tokenId === T_DOC_COMMENT
            && preg_match_all('/@var\s+([^\s*|]+)(?:\|[^\s*]+)?\s+(\$[A-Za-z_][A-Za-z0-9_]*)/i', $value, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                if (resolves_to_pdo($match[1], $context)) {
                    $pdoVariables[$match[2]] = true;
                }
            }
        }
        if ($tokenId !== T_VARIABLE) {
            continue;
        }

        $typed = false;
        $cursor = $index;
        for ($steps = 0; $steps < 8 && ($cursor = previous_significant_token_index($tokens, $cursor)) !== null && $cursor >= $scopeStart; $steps++) {
            $previous = $tokens[$cursor];
            if (!is_array($previous)) {
                if (in_array($previous, ['=', ';', ',', '(', '{', '}', ':'], true)) {
                    break;
                }
                continue;
            }
            if ($previous[0] === T_VARIABLE || in_array($previous[0], [T_FUNCTION, T_FN], true)) {
                break;
            }
            if (resolves_to_pdo((string) $previous[1], $context)) {
                $typed = true;
                break;
            }
        }
        if ($typed) {
            $pdoVariables[$value] = true;
        }

        $next = next_significant_token_index($tokens, $index);
        if ($next === null || $tokens[$next] !== '=') {
            continue;
        }
        $right = next_significant_token_index($tokens, $next);
        if ($right === null) {
            continue;
        }
        $rightToken = $tokens[$right];
        if (is_array($rightToken) && $rightToken[0] === T_NEW) {
            $constructedType = next_significant_token_index($tokens, $right);
            if ($constructedType !== null && is_array($tokens[$constructedType])
                && resolves_to_pdo((string) $tokens[$constructedType][1], $context)) {
                $pdoVariables[$value] = true;
            } else {
                unset($pdoVariables[$value]);
            }
        } elseif (is_array($rightToken) && $rightToken[0] === T_VARIABLE) {
            if (isset($pdoVariables[$rightToken[1]])) {
                $pdoVariables[$value] = true;
            } else {
                unset($pdoVariables[$value]);
            }
        } elseif (is_array($rightToken) && in_array($rightToken[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
            && resolves_to_core_db((string) $rightToken[1], $context)) {
            $callOpen = next_significant_token_index($tokens, $right);
            if ($callOpen !== null && $tokens[$callOpen] === '(') {
                $pdoVariables[$value] = true;
            } else {
                unset($pdoVariables[$value]);
            }
        } else {
            unset($pdoVariables[$value]);
        }
    }

    return $pdoVariables;
}

/**
 * Collect explicitly typed PDO properties from the current class body.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $methodIndex Method-name token index.
 * @param array{namespace:string,class_aliases:array<string,string>,function_aliases:array<string,string>} $context Active symbol context.
 * @return array<string, true> Proven PDO property names.
 */
function pdo_property_provenance(array $tokens, int $methodIndex, array $context): array
{
    $scope = class_scope_for_token($tokens, $methodIndex);
    if ($scope === null) {
        return [];
    }
    $properties = [];
    $braceDepth = 1;
    for ($index = $scope['start'] + 1; $index < $methodIndex && $index < $scope['end']; $index++) {
        $token = $tokens[$index];
        if (is_array($token) && $token[0] === T_FUNCTION) {
            $bodyOpen = null;
            $parameterDepth = 0;
            $methodNameIndex = next_significant_token_index($tokens, $index);
            $parameterOpen = $methodNameIndex;
            while ($parameterOpen !== null && $parameterOpen < $scope['end'] && $tokens[$parameterOpen] !== '(') {
                if ($tokens[$parameterOpen] === '{' || $tokens[$parameterOpen] === ';') {
                    $parameterOpen = null;
                    break;
                }
                $parameterOpen = next_significant_token_index($tokens, $parameterOpen);
            }
            if ($parameterOpen !== null && $tokens[$parameterOpen] === '(') {
                $parameterClose = matching_token_index($tokens, $parameterOpen, '(', ')', $scope['end']);
                if ($parameterClose !== null && is_array($tokens[$methodNameIndex] ?? null)
                    && strtolower((string) $tokens[$methodNameIndex][1]) === '__construct') {
                    foreach (typed_callable_parameters($tokens, $parameterOpen, $parameterClose, $context, true) as $name) {
                        $properties[ltrim($name, '$')] = true;
                    }
                }
            }
            for ($cursor = $index + 1; $cursor < $scope['end']; $cursor++) {
                if ($tokens[$cursor] === '(') {
                    $parameterDepth++;
                } elseif ($tokens[$cursor] === ')') {
                    $parameterDepth--;
                } elseif ($parameterDepth === 0 && $tokens[$cursor] === '{') {
                    $bodyOpen = $cursor;
                    break;
                }
            }
            if ($bodyOpen !== null) {
                $depth = 0;
                for ($cursor = $bodyOpen; $cursor < $scope['end']; $cursor++) {
                    if ($tokens[$cursor] === '{') {
                        $depth++;
                    } elseif ($tokens[$cursor] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $index = $cursor;
                            break;
                        }
                    }
                }
            }
            continue;
        }
        if ($token === '{') {
            $braceDepth++;
            continue;
        }
        if ($token === '}') {
            $braceDepth--;
            continue;
        }
        if ($braceDepth !== 1 || !is_array($token) || $token[0] !== T_VARIABLE) {
            continue;
        }
        $propertyName = ltrim((string) $token[1], '$');
        $typed = false;
        $cursor = $index;
        for ($steps = 0; $steps < 8 && ($cursor = previous_significant_token_index($tokens, $cursor)) !== null && $cursor > $scope['start']; $steps++) {
            $previous = $tokens[$cursor];
            if (!is_array($previous)) {
                if (in_array($previous, [';', '{', '}', '=', ','], true)) {
                    break;
                }
                continue;
            }
            if ($previous[0] === T_VARIABLE) {
                break;
            }
            if (resolves_to_pdo((string) $previous[1], $context)) {
                $typed = true;
                break;
            }
        }
        if ($typed) {
            $properties[$propertyName] = true;
        }
    }
    return $properties;
}

/**
 * Return whether a method call has SQL argument or PDO receiver evidence.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $methodIndex Method-name token index.
 * @param array{namespace:string,class_aliases:array<string,string>,function_aliases:array<string,string>} $context Active symbol context.
 * @return bool True when the call is credibly a PDO persistence operation.
 */
function is_pdo_method_call(array $tokens, int $methodIndex, array $context): bool
{
    $open = next_significant_token_index($tokens, $methodIndex);
    if ($open === null || $tokens[$open] !== '(') {
        return false;
    }
    $depth = 0;
    $arguments = '';
    $argumentLimit = min(count($tokens), $open + 2048);
    for ($cursor = $open; $cursor < $argumentLimit; $cursor++) {
        $token = $tokens[$cursor];
        if ($token === '(') {
            $depth++;
        } elseif ($token === ')') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
        if ($cursor > $open) {
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $arguments .= is_array($token) ? (string) $token[1] : $token;
            }
        }
    }
    if (looks_like_sql($arguments)) {
        return true;
    }

    $pdoVariables = pdo_variable_provenance($tokens, $methodIndex, $context);
    $operator = previous_significant_token_index($tokens, $methodIndex);
    if ($operator === null || !is_array($tokens[$operator])
        || !in_array($tokens[$operator][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
        return false;
    }
    $receiver = previous_significant_token_index($tokens, $operator);
    if ($receiver === null) {
        return false;
    }
    $receiverToken = $tokens[$receiver];
    if (is_array($receiverToken) && $receiverToken[0] === T_VARIABLE) {
        return isset($pdoVariables[$receiverToken[1]]);
    }
    if (is_array($receiverToken) && in_array($receiverToken[0], [T_STRING, T_VARIABLE], true)) {
        $propertyOperator = previous_significant_token_index($tokens, $receiver);
        $propertyOwner = $propertyOperator === null ? null : previous_significant_token_index($tokens, $propertyOperator);
        $properties = pdo_property_provenance($tokens, $methodIndex, $context);
        if ($propertyOperator !== null && is_array($tokens[$propertyOperator])
            && in_array($tokens[$propertyOperator][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && $propertyOwner !== null && is_array($tokens[$propertyOwner])
            && $tokens[$propertyOwner][0] === T_VARIABLE && $tokens[$propertyOwner][1] === '$this') {
            return isset($properties[(string) $receiverToken[1]]);
        }
    }
    if ($receiverToken !== ')') {
        return false;
    }
    $callOpen = null;
    $depth = 0;
    for ($cursor = $receiver; $cursor >= 0; $cursor--) {
        if ($tokens[$cursor] === ')') {
            $depth++;
        } elseif ($tokens[$cursor] === '(') {
            $depth--;
            if ($depth === 0) {
                $callOpen = $cursor;
                break;
            }
        }
    }
    if ($callOpen === null) {
        return false;
    }
    $functionName = previous_significant_token_index($tokens, $callOpen);
    if ($functionName === null || !is_array($tokens[$functionName])) {
        return false;
    }
    $nameToken = $tokens[$functionName];
    return in_array($nameToken[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
        && resolves_to_core_db((string) $nameToken[1], $context);
}

/**
 * Return the final segment of a possibly qualified PHP name in lowercase.
 *
 * @param string $name PHP identifier or qualified name.
 * @return string Lowercase unqualified identifier.
 */
function token_name_leaf(string $name): string
{
    $segments = explode('\\', ltrim($name, '\\'));
    return strtolower((string) end($segments));
}

/**
 * Return whether a response API call mutates transport state.
 *
 * http_response_code() with no argument reads the current response status; the
 * Kernel uses that read while finalizing its request lifecycle observer.
 *
 * @param array<int, array|string> $tokens Token stream.
 * @param int $functionIndex Response function token index.
 * @param string $name Lowercase response function name.
 * @return bool True when the call changes the outgoing response.
 */
function is_http_response_mutation(array $tokens, int $functionIndex, string $name): bool
{
    if ($name !== 'http_response_code') {
        return true;
    }
    $open = next_significant_token_index($tokens, $functionIndex);
    if ($open === null || $tokens[$open] !== '(') {
        return false;
    }
    $argument = next_significant_token_index($tokens, $open);
    return $argument !== null && $tokens[$argument] !== ')';
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
 * Identify compatibility helpers/security modules whose persistence was migrated.
 * Split parts share the entrypoint rule; HTTP/session adapters remain allowed.
 * @param string $relativePath Repository-relative PHP path.
 * @return bool Whether direct SQL/PDO is forbidden at this compatibility boundary.
 */
function core_persistence_boundary_path(string $relativePath): bool
{
    $normalized = str_replace('\\', '/', $relativePath);
    return preg_match('~^app/(?:security|helpers(?:_[a-z0-9_]+)?)(?:\.php$|/)~', $normalized) === 1
        || in_array($normalized, ['app/request_data.php', 'app/session_context.php'], true);
}

/**
 * Reject persistence hidden in compatibility helpers without flagging HTTP work.
 * This narrow promotion reuses the established SQL classifier and fingerprints.
 * It does not interpret request/session/response behavior as a persistence fault.
 * @param string $source PHP source; inspected without execution.
 * @param string $relativePath Repository-relative helper/security source path.
 * @return array<int,array<string,mixed>> Strict database/SQL findings plus converted security filesystem writes.
 */
function scan_core_persistence_source(string $source, string $relativePath): array
{
    $tokens = token_get_all($source);
    $symbolContexts = source_symbol_contexts($tokens);
    $symbolContext = $symbolContexts[0];
    $lines = source_lines($source);
    $violations = [];
    $pdoMethods = ['prepare', 'query', 'exec', 'begintransaction', 'commit', 'rollback'];
    $securityFilesystemMutations = ['file_put_contents', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'chmod', 'chown', 'touch', 'symlink', 'link', 'move_uploaded_file'];
    foreach ($tokens as $index => $token) {
        $symbolContext = $symbolContexts[$index] ?? $symbolContext;
        if (!is_array($token)) {
            continue;
        }
        [$id, $value, $line] = $token;
        $previous = null;
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            if (is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $previous = $tokens[$cursor];
            break;
        }
        $previousId = is_array($previous) ? $previous[0] : null;
        $memberAccess = in_array($previousId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
        $name = strtolower((string) preg_replace('~^.*\\\\~', '', $value));
        if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            if (preg_match('~^app/security(?:\.php$|/)~', $relativePath) === 1 && !$memberAccess
                && in_array($name, $securityFilesystemMutations, true) && token_is_function_call($tokens, $index)) {
                add_violation($violations, $relativePath, 'core.security_filesystem_mutation', $line, $lines[$line] ?? '');
            }
            if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
                && !$memberAccess && token_is_function_call($tokens, $index)
                && resolves_to_core_db($value, $symbolContext)) {
                add_violation($violations, $relativePath, 'core.direct_db', $line, $lines[$line] ?? '');
            }
            if ($previousId === T_NEW && resolves_to_pdo($value, $symbolContext)) {
                add_violation($violations, $relativePath, 'core.pdo_construction', $line, $lines[$line] ?? '');
            }
            if ($memberAccess && in_array($name, $pdoMethods, true) && token_is_function_call($tokens, $index)
                && is_pdo_method_call($tokens, $index, $symbolContext)) {
                add_violation($violations, $relativePath, 'core.pdo_method', $line, $lines[$line] ?? '');
            }
        }
        if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && looks_like_sql($value)) {
            add_violation($violations, $relativePath, 'core.sql_literal', $line, $lines[$line] ?? '');
        }
    }
    foreach ($violations as &$violation) {
        unset($violation['_dedupe']);
    }
    unset($violation);
    return $violations;
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
        return core_persistence_boundary_path($relativePath) ? scan_core_persistence_source($source, $relativePath) : [];
    }

    $tokens = token_get_all($source);
    $symbolContexts = source_symbol_contexts($tokens);
    $symbolContext = $symbolContexts[0];
    $lines = source_lines($source);
    $violations = [];
    $requestGlobals = ['$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_COOKIE', '$_SERVER'];
    $viewGlobals = array_merge($requestGlobals, ['$_SESSION']);
    $responseFunctions = ['header', 'http_response_code', 'setcookie', 'setrawcookie'];
    $filesystemMutationFunctions = ['file_put_contents', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'chmod', 'chown', 'touch', 'symlink', 'link'];
    $pdoMethods = ['prepare', 'query', 'exec', 'beginTransaction', 'commit', 'rollBack'];
    $line = 1;

    foreach ($tokens as $index => $token) {
        $symbolContext = $symbolContexts[$index] ?? $symbolContext;
        if (!is_array($token)) {
            continue;
        }
        [$tokenId, $value, $tokenLine] = $token;
        $line = $tokenLine;
        $snippet = $lines[$line] ?? $value;

        if ($tokenId === T_VARIABLE) {
            if ($value === '$_SESSION' && $layer === 'services') {
                if (preg_match('~^app/services/(admin_gallery_discovery|google_auth|admin_gallery_report/job|duplicate_photo_detector|viewer_anti_automation)(?:\.php$|/)~', $relativePath, $sessionOwner) === 1) {
                    $sessionRule = match ($sessionOwner[1]) {
                        'google_auth' => 'services.google_auth_session_global',
                        'admin_gallery_report/job' => 'services.report_job_session_global',
                        'duplicate_photo_detector' => 'services.duplicate_detector_session_global',
                        'viewer_anti_automation' => 'services.viewer_anti_automation_session_global',
                        default => 'services.discovery_session_global',
                    };
                } else {
                    $sessionRule = 'services.session_global';
                }
                add_violation($violations, $relativePath, $sessionRule, $line, $snippet);
            } elseif ($value === '$_SESSION' && $layer === 'models') {
                add_violation($violations, $relativePath, 'models.session_global', $line, $snippet);
            }
            $forbiddenGlobals = $layer === 'views' ? $viewGlobals : $requestGlobals;
            if (($layer === 'models' || $layer === 'services' || $layer === 'views') && in_array($value, $forbiddenGlobals, true)) {
                add_violation($violations, $relativePath, $layer . '.request_global', $line, $snippet);
            }
        }

        if (preg_match('~^app/controllers/mobile_webdav(?:\.php$|/)~', $relativePath) === 1
            && in_array($tokenId, [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            && in_array(strtolower(ltrim($value, '\\')), $filesystemMutationFunctions, true)
            && token_is_function_call($tokens, $index)) {
            $memberCall = false;
            for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                $previous = $tokens[$cursor];
                if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $memberCall = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
                break;
            }
            if (!$memberCall) {
                add_violation($violations, $relativePath, 'controllers.mobile_webdav_filesystem_mutation', $line, $snippet);
            }
        }
        if (in_array($tokenId, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
            && token_is_function_call($tokens, $index)) {
            $name = strtolower($value);
            if (($layer === 'services' || $layer === 'controllers' || $layer === 'views')
                && in_array($tokenId, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
                && resolves_to_core_db($value, $symbolContext)) {
                add_violation($violations, $relativePath, $layer . '.direct_db', $line, $snippet);
            }
            if (($layer === 'models' || $layer === 'services' || $layer === 'views') && in_array($name, $responseFunctions, true)
                && is_http_response_mutation($tokens, $index, $name)) {
                add_violation($violations, $relativePath, $layer . '.http_response', $line, $snippet);
            }
            if ($layer === 'views' && in_array($name, $filesystemMutationFunctions, true)) {
                add_violation($violations, $relativePath, 'views.filesystem_mutation', $line, $snippet);
            }
        }

        if ($tokenId === T_STRING && in_array(strtolower($value), array_map('strtolower', $pdoMethods), true)
            && token_is_function_call($tokens, $index) && is_pdo_method_call($tokens, $index, $symbolContext)) {
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
 * Return PHP files under MVC roots and the reviewed core persistence boundary.
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
    $normalizedRoot = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    foreach (runtime_php_files($normalizedRoot) as $path) {
        $relative = ltrim(substr(str_replace('\\', '/', $path), strlen($normalizedRoot)), '/');
        if (core_persistence_boundary_path($relative)) {
            $files[] = $path;
        }
    }
    $files = array_values(array_unique($files));
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
    if (in_array($relativePath, ['app/session_context.php', 'app/bootstrap/viewer_identity_context.php'], true)) {
        return 'session_adapter';
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
 * Classify reviewed HTTP, security, diagnostic, and integrity ownership signals.
 *
 * The source inventory still retains every raw signal. These exact path/signal
 * pairs explain established boundary responsibilities separately from actionable
 * advisory candidates; they do not exempt unrelated signals from the same file.
 *
 * @param array<string, mixed> $record File inventory record.
 * @return array<int, array{path:string,signal:string,count:int,reason:string,evidence:array<int,array{line:int,snippet:string}>}> Accepted boundary rows.
 */
function architecture_accepted_boundaries(array $record): array
{
    $path = str_replace('\\', '/', (string) ($record['path'] ?? ''));
    $signals = is_array($record['signals'] ?? null) ? $record['signals'] : [];
    $policies = [
        'app/security.php' => [
            'request_global' => 'Compatibility security owns CSRF request-token checks and cookie/request identity inputs.',
            'session_global' => 'Compatibility security owns PHP-session CSRF and authenticated-principal state.',
            'presentation_output' => 'Compatibility security emits bounded CSRF and error response material at the existing security boundary.',
        ],
        'app/request_data.php' => [
            'request_global' => 'The canonical Core request adapter is the narrow owner of PHP request input bags.',
        ],
        'app/helpers_request.php' => [
            'request_global' => 'The compatibility request helper owns request normalization and login-target interpretation.',
            'http_response' => 'The compatibility request helper applies cookie and header intents for established login and base-URL behavior.',
        ],
        'app/diagnostics/admin_test_run_early.php' => [
            'request_global' => 'Bootstrap-free early diagnostics capture a bounded request fingerprint before the normal runtime loads.',
            'filesystem_mutation' => 'Early diagnostics write only their owned bounded request-observation artifact.',
        ],
        'app/helpers_runtime.php' => [
            'http_response' => 'The compatibility redirect helper owns the Location header and terminating 302 transport response.',
        ],
        'app/integrity.php' => [
            'filesystem_mutation' => 'Integrity/update verification owns its reviewed low-level filesystem checks and repair operations.',
        ],
        'app/runtime/Kernel.php' => [
            'http_response_status_read' => 'The Core Kernel reads the completed HTTP status for request-observer finalization after dispatch.',
            'http_response' => 'The Core Kernel owns response status and headers while completing the HTTP request lifecycle.',
        ],
        'app/session_context.php' => [
            'session_global' => 'The dependency-free Core session adapter is the narrow owner of PHP session storage access.',
        ],
        'app/bootstrap/viewer_identity_context.php' => [
            'request_global' => 'Bootstrap viewer identity restoration reads user-agent and remember-cookie inputs while constructing the request identity snapshot.',
            'http_response' => 'Bootstrap viewer identity restoration applies the remember-cookie response while restoring the HTTP identity context.',
            'session_global' => 'Bootstrap viewer identity restoration owns its narrow session principal snapshot at HTTP initialization.',
        ],
    ];
    $accepted = [];
    foreach ($policies[$path] ?? [] as $signalName => $reason) {
        $signal = $signals[$signalName] ?? null;
        $count = is_array($signal) ? (int) ($signal['count'] ?? 0) : 0;
        if ($count <= 0) {
            continue;
        }
        $accepted[] = [
            'path' => $path,
            'signal' => $signalName,
            'count' => $count,
            'reason' => $reason,
            'evidence' => array_values($signal['evidence'] ?? []),
        ];
    }
    return $accepted;
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
    $symbolContexts = source_symbol_contexts($tokens);
    $symbolContext = $symbolContexts[0];
    $lines = source_lines($source);
    $signals = [];
    $requestGlobals = ['$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_COOKIE', '$_SERVER'];
    $responseFunctions = ['header', 'http_response_code', 'setcookie', 'setrawcookie'];
    $filesystemMutationFunctions = ['file_put_contents', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'chmod', 'chown', 'touch', 'symlink', 'link'];
    $pdoMethods = ['prepare', 'query', 'exec', 'begintransaction', 'commit', 'rollback'];
    $nameTokenIds = [T_STRING];
    if (defined('T_NAME_QUALIFIED')) {
        $nameTokenIds[] = T_NAME_QUALIFIED;
    }
    if (defined('T_NAME_FULLY_QUALIFIED')) {
        $nameTokenIds[] = T_NAME_FULLY_QUALIFIED;
    }

    foreach ($tokens as $index => $token) {
        $symbolContext = $symbolContexts[$index] ?? $symbolContext;
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

        if (in_array($tokenId, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
            && token_is_function_call($tokens, $index)) {
            $name = strtolower($value);
            if (in_array($tokenId, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
                && resolves_to_core_db($value, $symbolContext)) {
                record_architecture_signal($signals, 'direct_db', $line, $snippet);
            }
            if (in_array($name, $responseFunctions, true)) {
                $responseSignal = $name === 'http_response_code' && !is_http_response_mutation($tokens, $index, $name)
                    ? 'http_response_status_read'
                    : 'http_response';
                record_architecture_signal($signals, $responseSignal, $line, $snippet);
            }
            if (in_array($name, $filesystemMutationFunctions, true)) {
                record_architecture_signal($signals, 'filesystem_mutation', $line, $snippet);
            }
        }

        if ($tokenId === T_STRING && in_array(strtolower($value), $pdoMethods, true)
            && token_is_function_call($tokens, $index) && is_pdo_method_call($tokens, $index, $symbolContext)) {
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
    $acceptedSignals = [];
    foreach (architecture_accepted_boundaries($record) as $accepted) {
        $acceptedSignals[(string) $accepted['signal']] = true;
    }
    $candidates = [];

    $append = static function (string $rule, string $signal, string $reason) use (&$candidates, $path, $role, $signals, $acceptedSignals): void {
        if (isset($acceptedSignals[$signal])) {
            return;
        }
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
    if (in_array($role, ['service', 'view', 'helper', 'security_compatibility', 'core', 'diagnostics', 'request_adapter', 'session_adapter'], true)) {
        $append('architecture.request_outside_http_boundary', 'request_global', 'Request globals are read outside controller/bootstrap/request-adapter ownership.');
    }
    if (in_array($role, ['model', 'service', 'view', 'helper', 'security_compatibility', 'core', 'diagnostics', 'request_adapter', 'session_adapter'], true)) {
        $append('architecture.session_state_outside_http_boundary', 'session_global', 'Session state is accessed outside the canonical HTTP boundary and should be reviewed for adapter/controller extraction.');
    }
    if (in_array($role, ['model', 'controller', 'view', 'helper', 'security_compatibility', 'core', 'diagnostics', 'request_adapter', 'session_adapter'], true)) {
        $append('architecture.filesystem_mutation_outside_service', 'filesystem_mutation', 'Filesystem mutation exists outside service/infrastructure ownership.');
    }
    if (in_array($role, ['model', 'service', 'view', 'helper', 'core', 'diagnostics', 'request_adapter', 'session_adapter'], true)) {
        $append('architecture.response_outside_controller', 'http_response', 'HTTP response manipulation exists outside controller/bootstrap ownership.');
    }
    if (in_array($role, ['model', 'service', 'helper', 'security_compatibility', 'core', 'diagnostics', 'request_adapter', 'session_adapter'], true)) {
        $append('architecture.presentation_outside_view', 'presentation_output', 'Presentation output exists outside view/controller response ownership.');
    }

    return array_values($candidates);
}

/**
 * Scan the complete first-party PHP web runtime without executing source files.
 *
 * @param string $root Project root.
 * @return array{files:array<int,array<string,mixed>>,candidates:array<int,array<string,mixed>>,accepted_boundaries:array<int,array{path:string,signal:string,count:int,reason:string,evidence:array<int,array{line:int,snippet:string}>}>,roles:array<string,int>,signals:array<string,int>} Per-file inventory, review candidates, accepted boundaries, roles, and signal totals.
 */
function scan_runtime_architecture(string $root): array
{
    $normalizedRoot = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    $records = [];
    $candidates = [];
    $acceptedBoundaries = [];
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
        array_push($acceptedBoundaries, ...architecture_accepted_boundaries($record));
        array_push($candidates, ...architecture_review_candidates($record));
    }

    usort($records, static fn(array $left, array $right): int => [$right['signal_count'], $left['path']] <=> [$left['signal_count'], $right['path']]);
    usort($candidates, static fn(array $left, array $right): int => [$right['count'], $left['path'], $left['rule']] <=> [$left['count'], $right['path'], $right['rule']]);
    usort($acceptedBoundaries, static fn(array $left, array $right): int => [$left['path'], $left['signal']] <=> [$right['path'], $right['signal']]);
    ksort($roles, SORT_STRING);
    ksort($signalTotals, SORT_STRING);

    return [
        'files' => $records,
        'candidates' => $candidates,
        'accepted_boundaries' => $acceptedBoundaries,
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
 * @return void Write the machine-readable MVC architecture report to the requested path.
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
            'accepted_boundaries' => array_values($runtime['accepted_boundaries']),
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

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(main($argv));
}
