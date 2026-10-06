<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/runtime_dependencies.php
 * Module Type: Development Tool
 * Purpose: Inspect PHP declarations and static dependencies without loading application files.
 * Responsibilities: Resolve source owners, aliases and static edges and report dynamic dependency sites.
 * Author: Rudolf Klusal
 *
 * This development tool reads source with token_get_all(). It never requires an
 * application file and never performs runtime dependency loading. Its output is
 * advisory input for explicit route and module definitions.
 */

declare(strict_types=1);

namespace Gallery\Tools\RuntimeDependencies;

require_once __DIR__ . '/cli_guard.php';
\gallery_guard_cli_entrypoint(__FILE__);

/**
 * Return a normalized repository-relative path using forward slashes.
 *
 * @param string $path Absolute or repository-relative filesystem path.
 * @param string $root Absolute repository root.
 * @return string Repository-relative path when possible, otherwise the input path.
 */
function relative_path(string $path, string $root): string
{
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    return str_starts_with(strtolower($path), strtolower($root)) ? substr($path, strlen($root)) : $path;
}

/**
 * Convert PHP tokenizer output to token records with stable line numbers.
 *
 * @param string $source PHP source text.
 * @return list<array{id:int|null,text:string,line:int}> Token records preserving original line locations.
 */
function tokenize(string $source): array
{
    $result = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $result[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];
        } else {
            $result[] = ['id' => null, 'text' => $token, 'line' => 0];
        }
    }
    return $result;
}

/**
 * Return whether a tokenizer record is insignificant between PHP tokens.
 *
 * @param array{id:int|null,text:string,line:int} $token Token record.
 * @return bool True when the token is whitespace, a comment, or a PHP tag.
 */
function insignificant(array $token): bool
{
    return $token['id'] === T_WHITESPACE
        || $token['id'] === T_COMMENT
        || $token['id'] === T_DOC_COMMENT
        || $token['id'] === T_OPEN_TAG
        || $token['id'] === T_CLOSE_TAG;
}

/**
 * Find the next significant token index at or after a supplied position.
 *
 * @param list<array{id:int|null,text:string,line:int}> $tokens Token records.
 * @param int $start First index to inspect.
 * @param int|null $end Exclusive upper bound.
 * @return int|null Next significant token index, or null when no token remains.
 */
function next_significant(array $tokens, int $start, ?int $end = null): ?int
{
    $limit = min($end ?? count($tokens), count($tokens));
    for ($index = $start; $index < $limit; $index++) {
        if (!insignificant($tokens[$index])) {
            return $index;
        }
    }
    return null;
}

/**
 * Find the matching closing brace for an opening brace token.
 *
 * @param list<array{id:int|null,text:string,line:int}> $tokens Token records.
 * @param int $openIndex Opening brace index.
 * @return int|null Closing brace index, or null for malformed input.
 */
function matching_brace(array $tokens, int $openIndex): ?int
{
    $depth = 0;
    for ($index = $openIndex, $count = count($tokens); $index < $count; $index++) {
        if ($tokens[$index]['text'] === '{') {
            $depth++;
        } elseif ($tokens[$index]['text'] === '}') {
            $depth--;
            if ($depth === 0) {
                return $index;
            }
        }
    }
    return null;
}

/**
 * Parse a file's namespace and top-level use aliases.
 *
 * Group-use syntax is expanded into individual aliases. Trait-use statements
 * are ignored because they occur inside a class body rather than at file scope.
 *
 * @param list<array{id:int|null,text:string,line:int}> $tokens Token records.
 * @return array{namespace:string,functions:array<string,string>,classes:array<string,string>,constants:array<string,string>} Namespace and separate function, class, and constant import maps.
 */
function parse_file_context(array $tokens): array
{
    $namespace = '';
    $functions = [];
    $classes = [];
    $constants = [];
    $braceDepth = 0;
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if ($token['text'] === '{') {
            $braceDepth++;
            continue;
        }
        if ($token['text'] === '}') {
            $braceDepth = max(0, $braceDepth - 1);
            continue;
        }
        if ($braceDepth !== 0) {
            continue;
        }

        if ($token['id'] === T_NAMESPACE) {
            $name = '';
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                if ($tokens[$cursor]['text'] === ';' || $tokens[$cursor]['text'] === '{') {
                    break;
                }
                $name .= $tokens[$cursor]['text'];
            }
            $namespace = trim($name, " \t\r\n\\");
            continue;
        }

        if ($token['id'] !== T_USE) {
            continue;
        }

        $statement = '';
        $end = $index + 1;
        for (; $end < $count && $tokens[$end]['text'] !== ';'; $end++) {
            $statement .= $tokens[$end]['text'];
        }
        $statement = trim($statement);
        $kind = 'classes';
        if (preg_match('/^function\\b/i', $statement) === 1) {
            $kind = 'functions';
            $statement = trim(substr($statement, strlen('function')));
        } elseif (preg_match('/^const\\b/i', $statement) === 1) {
            $kind = 'constants';
            $statement = trim(substr($statement, strlen('const')));
        }

        $groupStart = strpos($statement, '{');
        if ($groupStart !== false && str_ends_with($statement, '}')) {
            $prefix = trim(substr($statement, 0, $groupStart), " \t\r\n\\");
            $items = explode(',', substr($statement, $groupStart + 1, -1));
            $names = array_map(static fn (string $item): string => $prefix . '\\' . trim($item, " \t\r\n\\"), $items);
        } else {
            $names = explode(',', $statement);
        }

        foreach ($names as $name) {
            $parts = preg_split('/\\s+as\\s+/i', trim($name), 2);
            $target = trim((string) ($parts[0] ?? ''), " \t\r\n\\");
            if ($target === '') {
                continue;
            }
            $targetSegments = explode('\\', $target);
            $alias = isset($parts[1]) ? trim($parts[1]) : end($targetSegments);
            $aliases =& ${$kind};
            $aliases[strtolower((string) $alias)] = $target;
            unset($aliases);
        }
        $index = $end;
    }

    return ['namespace' => $namespace, 'functions' => $functions, 'classes' => $classes, 'constants' => $constants];
}

/**
 * Read one PHP source file and retain its token and import context.
 *
 * @param string $absolutePath Absolute PHP path.
 * @param string $root Absolute repository root.
 * @return array<string,mixed> Parsed path, token, and import metadata.
 */
function read_source_file(string $absolutePath, string $root): array
{
    $source = file_get_contents($absolutePath);
    if (!is_string($source)) {
        return ['path' => relative_path($absolutePath, $root), 'error' => 'read_failed', 'tokens' => [], 'context' => []];
    }
    $tokens = tokenize($source);
    return [
        'path' => relative_path($absolutePath, $root),
        'tokens' => $tokens,
        'context' => parse_file_context($tokens),
        'error' => null,
    ];
}

/**
 * Resolve a PHP name using import aliases and the current namespace.
 *
 * @param string $name Source-level name.
 * @param string $kind One of functions, classes, or constants.
 * @param array{namespace:string,functions:array<string,string>,classes:array<string,string>,constants:array<string,string>} $context File context.
 * @return string Fully qualified candidate name without a leading slash.
 */
function resolve_name(string $name, string $kind, array $context): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    if ($name[0] === '\\') {
        return ltrim($name, '\\');
    }
    if (str_contains($name, '\\')) {
        $firstSegment = strtok($name, '\\') ?: '';
        $first = strtolower($firstSegment);
        $imports = $context[$kind] ?? [];
        if (isset($imports[$first])) {
            return $imports[$first] . substr($name, strlen($firstSegment));
        }
        return ($context['namespace'] !== '' ? $context['namespace'] . '\\' : '') . $name;
    }
    $imports = $context[$kind] ?? [];
    if (isset($imports[strtolower($name)])) {
        return $imports[strtolower($name)];
    }
    return ($context['namespace'] !== '' ? $context['namespace'] . '\\' : '') . $name;
}

/**
 * Return the text of a string literal token when it is a simple PHP literal.
 *
 * @param array{id:int|null,text:string,line:int} $token Token record.
 * @return string|null Decoded literal contents, or null for a nonliteral token.
 */
function literal_string(array $token): ?string
{
    if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING || strlen($token['text']) < 2) {
        return null;
    }
    $quote = $token['text'][0];
    $value = substr($token['text'], 1, -1);
    return $quote === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value) : stripcslashes($value);
}

/**
 * Collect literal file includes and their source line numbers.
 *
 * @param array<string,mixed> $file Parsed source file.
 * @param string $root Absolute repository root.
 * @return list<array{target:string,line:int,kind:string}> Resolved literal include edges inside the repository.
 */
function collect_file_includes(array $file, string $root): array
{
    $edges = [];
    $tokens = $file['tokens'];
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
    foreach ($tokens as $index => $token) {
        if (!in_array($token['id'], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            continue;
        }
        $expression = '';
        for ($cursor = $index + 1; $cursor < count($tokens) && $tokens[$cursor]['text'] !== ';'; $cursor++) {
            if (!insignificant($tokens[$cursor])) {
                $expression .= $tokens[$cursor]['text'];
            }
        }
        $literal = null;
        if (preg_match('/^__DIR__\\.([\'\"])(.*?)\\1$/', $expression, $matches) === 1) {
            $literal = stripcslashes($matches[2]);
            $base = dirname($absolute);
        } elseif (preg_match('/^dirname\\(__DIR__(?:,([0-9]+))?\\)\\.([\'\"])(.*?)\\2$/', $expression, $matches) === 1) {
            $levels = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 1;
            $literal = stripcslashes($matches[3]);
            $base = dirname($absolute, $levels + 1);
        } elseif (preg_match('/^([\'\"])(.*?)\\1$/', $expression, $matches) === 1) {
            $literal = stripcslashes($matches[2]);
            $base = dirname($absolute);
        } else {
            continue;
        }
        $target = null;
        if (str_starts_with($literal, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $literal) === 1) {
            $target = realpath($literal);
        } else {
            $target = realpath($base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $literal));
        }
        if (is_string($target) && str_starts_with(strtolower($target), strtolower($root . DIRECTORY_SEPARATOR))) {
            $edges[] = ['target' => relative_path($target, $root), 'line' => $token['line'], 'kind' => token_name((int) $token['id'])];
        }
    }
    return $edges;
}

/**
 * Find the matching opening brace for a closing brace in a token stream.
 *
 * @param list<array{id:int|null,text:string,line:int}> $tokens Token records.
 * @param int $closeIndex Closing brace index.
 * @return int|null Opening brace index, or null for malformed input.
 */
function opening_brace(array $tokens, int $closeIndex): ?int
{
    $depth = 0;
    for ($index = $closeIndex; $index >= 0; $index--) {
        if ($tokens[$index]['text'] === '}') {
            $depth++;
        } elseif ($tokens[$index]['text'] === '{') {
            $depth--;
            if ($depth === 0) {
                return $index;
            }
        }
    }
    return null;
}

/**
 * Locate named functions, methods, classes, and file constants.
 *
 * @param array<string,mixed> $file Parsed source file.
 * @return array{symbols:list<array<string,mixed>>,classRanges:list<array{start:int,end:int,name:string}>} Declarations and enclosing class ranges.
 */
function collect_declarations(array $file): array
{
    $tokens = $file['tokens'];
    $context = $file['context'];
    $namespace = $context['namespace'];
    $symbols = [];
    $classRanges = [];
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!in_array($token['id'], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }
        $previous = next_significant($tokens, max(0, $index - 2), $index);
        if ($token['id'] === T_CLASS && $previous !== null && $tokens[$previous]['id'] === T_NEW) {
            continue;
        }
        $nameIndex = next_significant($tokens, $index + 1);
        if ($nameIndex === null || $tokens[$nameIndex]['id'] !== T_STRING) {
            continue;
        }
        $open = null;
        for ($cursor = $nameIndex + 1; $cursor < $count; $cursor++) {
            if ($tokens[$cursor]['text'] === '{') {
                $open = $cursor;
                break;
            }
        }
        if ($open === null || ($close = matching_brace($tokens, $open)) === null) {
            continue;
        }
        $fqcn = ($namespace !== '' ? $namespace . '\\' : '') . $tokens[$nameIndex]['text'];
        $classRanges[] = ['start' => $open, 'end' => $close, 'name' => $fqcn];
        $symbols[] = ['id' => strtolower($fqcn), 'name' => $fqcn, 'kind' => token_name((int) $token['id']), 'file' => $file['path'], 'line' => $token['line'], 'start' => $index, 'end' => $close];
        $index = $nameIndex;
    }

    for ($index = 0; $index < $count; $index++) {
        if ($tokens[$index]['id'] !== T_FUNCTION) {
            continue;
        }
        $nameIndex = next_significant($tokens, $index + 1);
        if ($nameIndex !== null && $tokens[$nameIndex]['text'] === '&') {
            $nameIndex = next_significant($tokens, $nameIndex + 1);
        }
        if ($nameIndex === null || $tokens[$nameIndex]['id'] !== T_STRING) {
            continue;
        }
        $open = null;
        for ($cursor = $nameIndex + 1; $cursor < $count; $cursor++) {
            if ($tokens[$cursor]['text'] === '{') {
                $open = $cursor;
                break;
            }
            if ($tokens[$cursor]['text'] === ';') {
                break;
            }
        }
        if ($open === null || ($close = matching_brace($tokens, $open)) === null) {
            continue;
        }
        $ownerClass = null;
        foreach ($classRanges as $range) {
            if ($index > $range['start'] && $index < $range['end']) {
                $ownerClass = $range['name'];
                break;
            }
        }
        $name = $ownerClass !== null
            ? $ownerClass . '::' . $tokens[$nameIndex]['text']
            : (($namespace !== '' ? $namespace . '\\' : '') . $tokens[$nameIndex]['text']);
        $symbols[] = ['id' => strtolower($name), 'name' => $name, 'kind' => $ownerClass !== null ? 'method' : 'function', 'file' => $file['path'], 'line' => $tokenLine = $token['line'], 'start' => $index, 'body_start' => $open + 1, 'end' => $close];
    }

    for ($index = 0; $index < $count; $index++) {
        if ($tokens[$index]['id'] !== T_CONST) {
            continue;
        }
        $previous = next_significant($tokens, max(0, $index - 2), $index);
        if ($previous !== null && $tokens[$previous]['id'] === T_DOUBLE_COLON) {
            continue;
        }
        $nameIndex = next_significant($tokens, $index + 1);
        if ($nameIndex === null || $tokens[$nameIndex]['id'] !== T_STRING) {
            continue;
        }
        $name = ($namespace !== '' ? $namespace . '\\' : '') . $tokens[$nameIndex]['text'];
        $symbols[] = ['id' => strtolower($name), 'name' => $name, 'kind' => 'constant', 'file' => $file['path'], 'line' => $tokens[$index]['line'], 'start' => $index, 'end' => $index];
    }

    return ['symbols' => $symbols, 'classRanges' => $classRanges];
}

/**
 * Build a symbol index keyed by case-insensitive fully qualified name.
 *
 * @param array<string,mixed> $files Parsed source files keyed by relative path.
 * @return array{symbols:array<string,array<string,mixed>>,perFile:array<string,list<array<string,mixed>>>} Symbol lookup table and declarations grouped by file.
 */
function build_symbol_index(array $files): array
{
    $symbols = [];
    $perFile = [];
    foreach ($files as $path => $file) {
        $perFile[$path] = $file['declarations'] ?? [];
        foreach ($perFile[$path] as $symbol) {
            $key = match ($symbol['kind']) {
                'constant' => 'const:' . $symbol['name'],
                'T_CLASS', 'T_INTERFACE', 'T_TRAIT', 'T_ENUM' => 'class:' . $symbol['id'],
                default => $symbol['id'],
            };
            $symbol['index_key'] = $key;
            if (!isset($symbols[$key])) {
                $symbols[$key] = $symbol;
            } else {
                $symbols[$key]['duplicates'][] = ['file' => $symbol['file'], 'line' => $symbol['line']];
            }
            // Keep the legacy fully qualified lookup used by root catalogs while
            // retaining typed keys for same-named functions, classes, constants.
            if (!isset($symbols[$symbol['id']])) {
                $symbols[$symbol['id']] = $symbol;
            }
        }
    }
    return ['symbols' => $symbols, 'perFile' => $perFile];
}

/**
 * Return a known application function/class/constant symbol for one candidate.
 *
 * @param array<string,array<string,mixed>> $symbols Symbol index.
 * @param string $candidate Fully qualified candidate name.
 * @param string $kind Expected symbol kind.
 * @return array<string,mixed>|null Matching indexed declaration, or null when absent.
 */
function find_symbol(array $symbols, string $candidate, string $kind): ?array
{
    $candidate = ltrim($candidate, '\\');
    $key = match ($kind) {
        'classes' => 'class:' . strtolower($candidate),
        'constants' => 'const:' . $candidate,
        default => strtolower($candidate),
    };
    if (!isset($symbols[$key])) {
        return null;
    }
    $symbol = $symbols[$key];
    if ($kind === 'functions' && !in_array($symbol['kind'], ['function', 'method'], true)) {
        return null;
    }
    if ($kind === 'classes' && !in_array($symbol['kind'], ['T_CLASS', 'T_INTERFACE', 'T_TRAIT', 'T_ENUM'], true)) {
        return null;
    }
    if ($kind === 'constants' && $symbol['kind'] !== 'constant') {
        return null;
    }
    return $symbol;
}

/**
 * Resolve a callback-style literal to an application symbol when possible.
 *
 * @param string $value Literal callback or symbol string.
 * @param array{namespace:string,functions:array<string,string>,classes:array<string,string>,constants:array<string,string>} $context File context.
 * @param array<string,array<string,mixed>> $symbols Symbol index.
 * @return array<string,mixed>|null Resolved callback symbol, or null when unknown.
 */
function resolve_literal_symbol(string $value, array $context, array $symbols): ?array
{
    $value = trim($value, " \t\r\n'");
    if ($value === '') {
        return null;
    }
    if (str_contains($value, '::')) {
        [$class, $method] = explode('::', $value, 2);
        $resolvedClass = resolve_name($class, 'classes', $context);
        return find_symbol($symbols, $resolvedClass . '::' . $method, 'functions');
    }
    $candidate = resolve_name($value, 'functions', $context);
    $found = find_symbol($symbols, $candidate, 'functions');
    if ($found !== null) {
        return $found;
    }
    return find_symbol($symbols, $value, 'functions');
}

/**
 * Determine whether a literal string occupies a known API's callback argument.
 *
 * @param array<int,array{id:int|null,text:string,line:int}> $tokens Token records for the declaration.
 * @param int $index Index of the string literal token.
 * @param int $start First token index to inspect.
 * @return bool True when the literal is in a recognized callable argument position.
 */
function is_callable_literal_argument(array $tokens, int $index, int $start): bool
{
    $open = null;
    $parenDepth = 0;
    $bracketDepth = 0;
    $braceDepth = 0;
    for ($cursor = $index - 1; $cursor >= $start; $cursor--) {
        if (insignificant($tokens[$cursor])) {
            continue;
        }
        $text = $tokens[$cursor]['text'];
        if ($text === ')') {
            $parenDepth++;
        } elseif ($text === '(') {
            if ($parenDepth === 0 && $bracketDepth === 0 && $braceDepth === 0) {
                $open = $cursor;
                break;
            }
            $parenDepth = max(0, $parenDepth - 1);
        } elseif ($text === ']') {
            $bracketDepth++;
        } elseif ($text === '[') {
            $bracketDepth = max(0, $bracketDepth - 1);
        } elseif ($text === '}') {
            $braceDepth++;
        } elseif ($text === '{') {
            $braceDepth = max(0, $braceDepth - 1);
        }
    }
    if ($open === null) {
        return false;
    }

    $callee = $open - 1;
    while ($callee >= $start && insignificant($tokens[$callee])) {
        $callee--;
    }
    if ($callee < $start || !in_array($tokens[$callee]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
        return false;
    }

    $calleeName = strtolower(ltrim($tokens[$callee]['text'], '\\'));
    $callbackArgument = [
        'call_user_func' => 1,
        'call_user_func_array' => 1,
        'is_callable' => 1,
        'register_shutdown_function' => 1,
        'set_error_handler' => 1,
        'set_exception_handler' => 1,
        'spl_autoload_register' => 1,
        'array_filter' => 2,
        'array_map' => 1,
        'array_reduce' => 2,
        'array_walk' => 2,
        'array_walk_recursive' => 2,
        'iterator_apply' => 2,
        'preg_replace_callback' => 2,
        'preg_replace_callback_array' => 1,
        'uasort' => 2,
        'uksort' => 2,
        'usort' => 2,
    ][$calleeName] ?? null;
    if ($callbackArgument === null) {
        return false;
    }

    $argumentNumber = 1;
    $depth = 0;
    for ($cursor = $open + 1; $cursor < $index; $cursor++) {
        if (insignificant($tokens[$cursor])) {
            continue;
        }
        $text = $tokens[$cursor]['text'];
        if (in_array($text, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($text, [')', ']', '}'], true)) {
            $depth = max(0, $depth - 1);
        } elseif ($text === ',' && $depth === 0) {
            $argumentNumber++;
        }
    }
    return $argumentNumber === $callbackArgument;
}

/**
 * Collect direct and recognized indirect dependencies from a function body.
 *
 * This recognizes named calls, imported aliases, class construction/static
 * references, imported constants, literal function_exists/callback strings,
 * and the narrow `__NAMESPACE__ . '\\name'` pattern. Variable callables and
 * runtime-composed class/function names are emitted as unresolved hazards.
 *
 * @param array<string,mixed> $file Parsed source file.
 * @param array<string,mixed> $symbol Named declaration.
 * @param array<string,array<string,mixed>> $symbols Symbol index.
 * @return array{edges:list<array{target:string,target_id:string,target_file:string,kind:string,line:int}>,unresolved:list<array{site_key:string,expression:string,line:int,kind:string}>} Direct symbol edges and dynamic references requiring review.
 */
function collect_symbol_dependencies(array $file, array $symbol, array $symbols): array
{
    $tokens = $file['tokens'];
    $context = $file['context'];
    $start = (int) ($symbol['start'] ?? 0);
    $end = (int) ($symbol['end'] ?? $start);
    $edges = [];
    $unresolved = [];
    $seen = [];
    $excludedCalls = [
        'if', 'isset', 'empty', 'unset', 'array', 'list', 'echo', 'print', 'include', 'include_once',
        'require', 'require_once', 'eval', 'exit', 'die', 'clone', 'new', 'match', 'fn', 'function',
    ];

    $addEdge = static function (array $target, string $kind, int $line) use (&$edges, &$seen): void {
        $key = strtolower($target['name']) . ':' . $kind;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $edges[] = ['target' => $target['name'], 'target_id' => $target['index_key'] ?? $target['id'], 'target_file' => $target['file'], 'kind' => $kind, 'line' => $line];
    };
    $addUnresolved = static function (string $expression, int $line, string $kind) use (&$unresolved, &$seen, $symbol, $file): void {
        $key = 'unresolved:' . $kind . ':' . $expression . ':' . $line;
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $siteKey = hash('sha256', strtolower($symbol['name']) . "\0" . $file['path'] . "\0" . $kind . "\0" . $expression);
            $unresolved[] = ['site_key' => $siteKey, 'expression' => $expression, 'line' => $line, 'kind' => $kind];
        }
    };

    for ($index = $start; $index <= $end && isset($tokens[$index]); $index++) {
        $token = $tokens[$index];
        $next = next_significant($tokens, $index + 1, $end + 1);
        $previous = null;
        for ($cursor = $index - 1; $cursor >= $start; $cursor--) {
            if (!insignificant($tokens[$cursor])) {
                $previous = $cursor;
                break;
            }
        }
        $prevText = $previous !== null ? $tokens[$previous]['text'] : '';
        $nextToken = $next !== null ? $tokens[$next] : null;

        if ($token['id'] === T_VARIABLE && $nextToken !== null && $nextToken['text'] === '(' && !in_array($prevText, ['->', '?->', '::', 'function'], true)) {
            $addUnresolved($token['text'] . '()', $token['line'], 'variable_callable');
            continue;
        }
        if ($token['id'] === T_STRING && in_array(strtolower($token['text']), ['call_user_func', 'call_user_func_array', 'is_callable', 'register_shutdown_function', 'set_error_handler'], true) && $nextToken !== null && $nextToken['text'] === '(') {
            $argument = next_significant($tokens, $next + 1, $end + 1);
            if ($argument !== null && $tokens[$argument]['id'] === T_VARIABLE) {
                $addUnresolved($token['text'] . '(' . $tokens[$argument]['text'] . ')', $token['line'], 'dynamic_callback_argument');
            }
        }
        if (in_array($prevText, ['->', '?->'], true) && $nextToken !== null && $nextToken['text'] === '(' && $token['id'] === T_STRING) {
            $className = str_contains($symbol['name'], '::') ? strstr($symbol['name'], '::', true) : '';
            $receiverIndex = null;
            for ($cursor = ($previous ?? $start) - 1; $cursor >= $start; $cursor--) {
                if (!insignificant($tokens[$cursor])) {
                    $receiverIndex = $cursor;
                    break;
                }
            }
            if ($receiverIndex !== null && $tokens[$receiverIndex]['id'] === T_VARIABLE && $tokens[$receiverIndex]['text'] === '$this' && $className !== '') {
                $target = find_symbol($symbols, $className . '::' . $token['text'], 'functions');
                if ($target !== null) {
                    $addEdge($target, 'same_class_method_call', $token['line']);
                }
            } else {
                $addUnresolved($prevText . $token['text'] . '()', $token['line'], 'dynamic_method_call');
            }
        }

        if ($token['id'] === T_STRING && $nextToken !== null && $nextToken['text'] === '(' && !in_array(strtolower($token['text']), $excludedCalls, true)) {
            if (in_array($prevText, ['->', '?->', '::', 'function', 'new'], true)) {
                continue;
            }
            $candidate = resolve_name($token['text'], 'functions', $context);
            $target = find_symbol($symbols, $candidate, 'functions');
            if ($target === null && $context['namespace'] !== '') {
                $target = find_symbol($symbols, $token['text'], 'functions');
            }
            if ($target !== null) {
                $kind = isset($context['functions'][strtolower($token['text'])]) ? 'imported_call' : 'call';
                $addEdge($target, $kind, $token['line']);
            }
        }

        if (in_array($token['id'], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $name = $token['text'];
            $isCall = $nextToken !== null && $nextToken['text'] === '(';
            $isNew = $previous !== null && $tokens[$previous]['id'] === T_NEW;
            $isStatic = $nextToken !== null && $nextToken['id'] === T_DOUBLE_COLON;
            $class = find_symbol($symbols, resolve_name($name, 'classes', $context), 'classes');
            if (($isNew || $isStatic) && $class !== null) {
                $addEdge($class, $isNew ? 'construct' : 'static_class', $token['line']);
            }
            if ($isNew && $class !== null) {
                $constructor = find_symbol($symbols, $class['name'] . '::__construct', 'functions');
                if ($constructor !== null) {
                    $addEdge($constructor, 'constructor_call', $token['line']);
                }
            }
            if ($isStatic && $class !== null) {
                $methodIndex = next_significant($tokens, $next + 1, $end + 1);
                $callIndex = $methodIndex !== null ? next_significant($tokens, $methodIndex + 1, $end + 1) : null;
                if ($methodIndex !== null && $tokens[$methodIndex]['id'] === T_STRING && $callIndex !== null && $tokens[$callIndex]['text'] === '(') {
                    $method = find_symbol($symbols, $class['name'] . '::' . $tokens[$methodIndex]['text'], 'functions');
                    if ($method !== null) {
                        $addEdge($method, 'static_method_call', $token['line']);
                    }
                }
            }
            if ($isCall) {
                $target = find_symbol($symbols, resolve_name($name, 'functions', $context), 'functions');
                if ($target !== null) {
                    $addEdge($target, 'call', $token['line']);
                }
            }
        }

        if ($token['id'] === T_NEW && $nextToken !== null) {
            if ($nextToken['id'] === T_VARIABLE) {
                $addUnresolved('new ' . $nextToken['text'], $token['line'], 'dynamic_class');
            } else {
                $className = $nextToken['text'];
                if ($nextToken['id'] === T_STRING || in_array($nextToken['id'], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $target = find_symbol($symbols, resolve_name($className, 'classes', $context), 'classes');
                    if ($target !== null) {
                        $addEdge($target, 'construct', $token['line']);
                        $constructor = find_symbol($symbols, $target['name'] . '::__construct', 'functions');
                        if ($constructor !== null) {
                            $addEdge($constructor, 'constructor_call', $token['line']);
                        }
                    }
                }
            }
        }

        if ($token['id'] === T_STRING && isset($context['classes'][strtolower($token['text'])])) {
            $target = find_symbol($symbols, $context['classes'][strtolower($token['text'])], 'classes');
            if ($target !== null) {
                $addEdge($target, 'imported_class_reference', $token['line']);
                if ($nextToken !== null && $nextToken['id'] === T_DOUBLE_COLON) {
                    $methodIndex = next_significant($tokens, $next + 1, $end + 1);
                    $callIndex = $methodIndex !== null ? next_significant($tokens, $methodIndex + 1, $end + 1) : null;
                    if ($methodIndex !== null && $tokens[$methodIndex]['id'] === T_STRING && $callIndex !== null && $tokens[$callIndex]['text'] === '(') {
                        $method = find_symbol($symbols, $target['name'] . '::' . $tokens[$methodIndex]['text'], 'functions');
                        if ($method !== null) {
                            $addEdge($method, 'static_method_call', $token['line']);
                        }
                    }
                }
            }
        }
        if ($token['id'] === T_STRING && $nextToken !== null && $nextToken['text'] !== '(' && !in_array($prevText, ['->', '?->', '::', 'function', 'class', 'interface', 'trait', 'enum', 'as'], true)) {
            $target = find_symbol($symbols, resolve_name($token['text'], 'classes', $context), 'classes');
            if ($target !== null) {
                $addEdge($target, 'class_reference', $token['line']);
            }
        }
        if ($token['id'] === T_STRING && isset($context['constants'][strtolower($token['text'])])) {
            $target = find_symbol($symbols, $context['constants'][strtolower($token['text'])], 'constants');
            if ($target !== null) {
                $addEdge($target, 'constant_reference', $token['line']);
            }
        }
        if ($token['id'] === T_STRING && $nextToken !== null && $nextToken['text'] !== '(' && !in_array($prevText, ['->', '?->', '::', 'function', 'const', 'class', 'interface', 'trait', 'enum', 'as'], true)) {
            $target = find_symbol($symbols, resolve_name($token['text'], 'constants', $context), 'constants');
            if ($target !== null) {
                $addEdge($target, isset($context['constants'][strtolower($token['text'])]) ? 'imported_constant' : 'constant_reference', $token['line']);
            }
        }
        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
            $value = literal_string($token);
            $isQualifiedCallable = $value !== null && (str_contains($value, '\\') || str_contains($value, '::'));
            if ($value !== null && ($isQualifiedCallable || is_callable_literal_argument($tokens, $index, $start))) {
                $target = resolve_literal_symbol($value, $context, $symbols);
                if ($target !== null) {
                    $addEdge($target, 'literal_callable', $token['line']);
                }
            }
        }

        if ($token['id'] === T_STRING && strtolower($token['text']) === 'function_exists' && $nextToken !== null && $nextToken['text'] === '(') {
            $arg = next_significant($tokens, $next + 1, $end + 1);
            if ($arg !== null && $tokens[$arg]['id'] === T_CONSTANT_ENCAPSED_STRING) {
                $value = literal_string($tokens[$arg]);
                if ($value !== null) {
                    $target = resolve_literal_symbol($value, $context, $symbols);
                    if ($target !== null) {
                        $addEdge($target, 'optional_function_exists', $token['line']);
                    }
                }
            } elseif ($arg !== null && $tokens[$arg]['id'] === T_NS_C) {
                $suffix = next_significant($tokens, $arg + 1, $end + 1);
                $literal = $suffix !== null ? next_significant($tokens, $suffix + 1, $end + 1) : null;
                if ($suffix !== null && $tokens[$suffix]['text'] === '.' && $literal !== null && ($value = literal_string($tokens[$literal])) !== null) {
                    $target = resolve_literal_symbol($context['namespace'] . $value, $context, $symbols);
                    if ($target !== null) {
                        $addEdge($target, 'optional_function_exists', $token['line']);
                    }
                }
            } else {
                $addUnresolved('function_exists(dynamic)', $token['line'], 'dynamic_function_exists');
            }
        }
    }

    return ['edges' => $edges, 'unresolved' => $unresolved];
}

/**
 * Build the static PHP source inventory under app and scripts.
 *
 * @param string $root Absolute repository root.
 * @return array<string,array<string,mixed>> Parsed PHP file metadata keyed by repository-relative path.
 */
function scan_files(string $root): array
{
    $files = [];
    foreach (['app', 'scripts'] as $directory) {
        $absoluteDirectory = $root . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($absoluteDirectory)) {
            continue;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absoluteDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
                continue;
            }
            $file = read_source_file($entry->getPathname(), $root);
            $declarations = collect_declarations($file);
            $file['declarations'] = $declarations['symbols'];
            unset($file['tokens']);
            $files[$file['path']] = $file;
        }
    }
    ksort($files);
    return $files;
}

/**
 * Resolve a split part file to its nearby module entry when the pair exists.
 *
 * The source include path remains unchanged; this value is only ownership
 * metadata for inventories and closure estimates.
 *
 * @param string $path Repository-relative PHP path.
 * @param array<string,array<string,mixed>> $files Parsed source files.
 * @return string Owning module entry path, or the file itself.
 */
function module_owner(string $path, array $files): string
{
    $segments = explode('/', $path);
    if (count($segments) < 4 || !in_array($segments[1], ['models', 'services', 'views', 'controllers'], true)) {
        return $path;
    }
    $layer = $segments[1];
    $firstPart = $segments[2];
    $entry = 'app/' . $layer . '/' . $firstPart . '.php';
    if (isset($files[$entry])) {
        return $entry;
    }
    return $path;
}

/**
 * Expand one module entry and its literal include graph in PHP execution order.
 *
 * @param string $entry Repository-relative module file.
 * @param array<string,list<array{target:string,line:int,kind:string}>> $includeGraph Literal include edges keyed by source file.
 * @param array<string,true> $seen Files already emitted by an earlier module.
 * @return list<string> First-load order including the entry before its parts.
 */
function expand_module_load_order(string $entry, array $includeGraph, array &$seen = []): array
{
    $order = [];
    $visit = static function (string $path) use (&$visit, &$order, &$seen, $includeGraph): void {
        if (isset($seen[$path])) {
            return;
        }
        $seen[$path] = true;
        $order[] = $path;
        foreach ($includeGraph[$path] ?? [] as $edge) {
            $visit($edge['target']);
        }
    };
    $visit($entry);
    return $order;
}

/**
 * Compute transitive function/class/constant closure for one starting symbol.
 *
 * @param string $start Fully qualified root symbol.
 * @param array<string,array<string,mixed>> $symbols Symbol index.
 * @param array<string,array{edges:list<array{target:string,target_id:string,target_file:string,kind:string,line:int}>,unresolved:list<array{site_key:string,expression:string,line:int,kind:string}>,file:string}> $dependencies Dependency edges keyed by symbol id.
 * @param array<string,array<string,mixed>> $files Parsed source files.
 * @param array<string,list<array{target:string,line:int,kind:string}>> $includeGraph Literal include graph.
 * @return array{symbols:list<string>,files:list<string>,modules:list<string>,load_files:list<string>,unresolved:list<array<string,mixed>>} Reachable declarations, source files, expanded modules, and unresolved references.
 */
function dependency_closure(string $start, array $symbols, array $dependencies, array $files, array $includeGraph = []): array
{
    $startId = strtolower(ltrim($start, '\\'));
    $pending = [$startId];
    $visited = [];
    $sourceFiles = [];
    $unresolved = [];
    while ($pending !== []) {
        $current = array_pop($pending);
        if (isset($visited[$current]) || !isset($symbols[$current])) {
            continue;
        }
        $visited[$current] = true;
        $file = $symbols[$current]['file'];
        $sourceFiles[$file] = true;
        foreach (($dependencies[$current]['edges'] ?? []) as $edge) {
            $targetId = $edge['target_id'] ?? strtolower($edge['target']);
            if (isset($symbols[$targetId])) {
                $pending[] = $targetId;
            }
        }
        foreach (($dependencies[$current]['unresolved'] ?? []) as $item) {
            $unresolved[] = ['symbol' => $symbols[$current]['name']] + $item;
        }
    }
    $moduleFiles = [];
    foreach (array_keys($sourceFiles) as $file) {
        $moduleFiles[module_owner($file, $files)] = true;
    }
    $names = [];
    foreach (array_keys($visited) as $id) {
        $names[] = $symbols[$id]['name'];
    }
    sort($names);
    $sourceFiles = array_keys($sourceFiles);
    $moduleFiles = array_keys($moduleFiles);
    sort($sourceFiles);
    sort($moduleFiles);
    $loadFiles = [];
    $seenFiles = [];
    foreach ($moduleFiles as $moduleFile) {
        $loadFiles = array_merge($loadFiles, expand_module_load_order($moduleFile, $includeGraph, $seenFiles));
    }
    return ['symbols' => $names, 'files' => $sourceFiles, 'modules' => $moduleFiles, 'load_files' => $loadFiles, 'unresolved' => $unresolved];
}

/**
 * Write one stable JSON document to standard output.
 *
 * @param array<string,mixed> $report Static dependency report.
 * @return void Writes the encoded report or exits on encoding failure.
 */
function emit_json(array $report): void
{
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        fwrite(STDERR, "Could not encode runtime dependency inventory.\n");
        exit(2);
    }
    fwrite(STDOUT, $json . PHP_EOL);
}

/**
 * Run the source inventory CLI without including application modules.
 *
 * @param list<string> $arguments Command-line arguments.
 * @return int Process exit code.
 */
function main(array $arguments): int
{
    $root = dirname(__DIR__);
    $handler = null;
    $summaryOnly = false;
    $includeEdges = false;
    $directOnly = false;
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--handler=')) {
            $handler = substr($argument, strlen('--handler='));
        } elseif ($argument === '--summary') {
            $summaryOnly = true;
        } elseif ($argument === '--edges') {
            $includeEdges = true;
        } elseif ($argument === '--handler-direct') {
            $directOnly = true;
        } elseif ($argument === '--help' || $argument === '-h') {
            fwrite(STDOUT, "Usage: php scripts/runtime_dependencies.php [--summary] [--edges] [--handler-direct] [--handler=Gallery\\\\Controllers\\\\cms_home]\n");
            return 0;
        }
    }

    $files = scan_files($root);
    $indexed = build_symbol_index($files);
    $symbols = $indexed['symbols'];
    $dependencies = [];
    foreach ($indexed['perFile'] as $path => $declarations) {
        $sourceFile = read_source_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path), $root);
        foreach ($declarations as $symbol) {
            if (!in_array($symbol['kind'], ['function', 'method'], true)) {
                continue;
            }
            $analysis = collect_symbol_dependencies($sourceFile, $symbol, $symbols);
            $dependencies[$symbol['id']] = $analysis + ['file' => $path];
        }
    }

    $includeGraph = [];
    foreach ($files as $path => $file) {
        $includeGraph[$path] = collect_file_includes(read_source_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path), $root), $root);
    }

    if ($handler !== null) {
        $handlerId = strtolower(ltrim($handler, '\\'));
        if ($directOnly && isset($dependencies[$handlerId])) {
            emit_json(['schema' => 1, 'mode' => 'symbol_direct_dependencies', 'handler' => ltrim($handler, '\\'), 'direct' => $dependencies[$handlerId]]);
            return 0;
        }
        $closure = dependency_closure($handler, $symbols, $dependencies, $files, $includeGraph);
        emit_json([
            'schema' => 1,
            'mode' => 'symbol_closure',
            'handler' => ltrim($handler, '\\'),
            'found' => isset($symbols[$handlerId]),
            'closure' => $closure,
        ]);
        return isset($symbols[$handlerId]) ? 0 : 1;
    }

    $counts = ['files' => count($files), 'symbols' => count($symbols), 'functions' => 0, 'methods' => 0, 'classes' => 0, 'constants' => 0, 'duplicate_symbols' => 0, 'unresolved_dynamic_sites' => 0];
    foreach ($symbols as $symbol) {
        if ($symbol['kind'] === 'function') {
            $counts['functions']++;
        } elseif ($symbol['kind'] === 'method') {
            $counts['methods']++;
        } elseif ($symbol['kind'] === 'constant') {
            $counts['constants']++;
        } else {
            $counts['classes']++;
        }
        if (!empty($symbol['duplicates'])) {
            $counts['duplicate_symbols']++;
        }
    }
    foreach ($dependencies as $dependency) {
        $counts['unresolved_dynamic_sites'] += count($dependency['unresolved']);
    }

    $report = ['schema' => 1, 'mode' => 'inventory', 'counts' => $counts];
    if (!$summaryOnly) {
        $report['files'] = [];
        foreach ($files as $path => $file) {
            $report['files'][$path] = [
                'module_owner' => module_owner($path, $files),
                'namespace' => $file['context']['namespace'],
                'imports' => $file['context'],
                'includes' => $includeGraph[$path],
                'symbols' => array_map(static fn (array $symbol): array => ['name' => $symbol['name'], 'kind' => $symbol['kind'], 'line' => $symbol['line']], $indexed['perFile'][$path]),
            ];
        }
        $report['dependencies'] = $dependencies;
        $report['include_graph'] = $includeGraph;
        if (!$includeEdges) {
            unset($report['include_graph']);
        }
    }
    emit_json($report);
    return 0;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(main(array_slice($argv, 1)));
}
