<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/function_documentation_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Enforces repository-wide documentation for named PHP and JavaScript functions.
 *
 * Responsibilities:
 *   - Require a PHPDoc block for every named PHP function and method
 *   - Require a JSDoc block for named JavaScript functions, methods, and arrow functions
 *   - Report exact source locations for maintainers
 *   - Ignore anonymous inline callbacks that have no stable callable name
 */

declare(strict_types=1);

/**
 * Return source files with one of the requested extensions.
 *
 * @param string $root Repository root.
 * @param array<int,string> $extensions Lowercase extensions without dots.
 * @return array<int,string> Sorted absolute paths.
 */
function function_documentation_source_files(string $root, array $extensions): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $item->getPathname());
        $relative = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
        if (str_starts_with($relative, '.git/')
            || str_starts_with($relative, '.claude/')
            || str_starts_with($relative, 'cache/')
            || str_starts_with($relative, 'data/')
            || str_starts_with($relative, 'galleries/')
            || str_starts_with($relative, 'deploy/')) {
            continue;
        }
        if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
            $files[] = $path;
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Return whether the named PHP declaration has an immediately preceding PHPDoc block.
 *
 * @param array<int,mixed> $tokens Tokenized PHP source.
 * @param int $functionIndex Index of the T_FUNCTION token.
 */
function function_documentation_php_has_docblock(array $tokens, int $functionIndex): bool
{
    $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT];
    if (defined('T_READONLY')) {
        $modifiers[] = constant('T_READONLY');
    }
    for ($index = $functionIndex - 1; $index >= 0; $index--) {
        $token = $tokens[$index];
        if (is_array($token) && ($token[0] === T_WHITESPACE || in_array($token[0], $modifiers, true))) {
            continue;
        }
        return is_array($token) && $token[0] === T_DOC_COMMENT;
    }
    return false;
}

/**
 * Find undocumented named PHP functions and methods.
 *
 * @param string $root Repository root.
 * @param array<int,string> $files PHP source paths.
 * @return array{total:int,missing:array<int,string>}
 */
function function_documentation_audit_php(string $root, array $files): array
{
    $total = 0;
    $missing = [];
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            $nameIndex = $index + 1;
            while ($nameIndex < $count) {
                $candidate = $tokens[$nameIndex];
                if ((is_array($candidate) && $candidate[0] === T_WHITESPACE) || $candidate === '&') {
                    $nameIndex++;
                    continue;
                }
                break;
            }
            if ($nameIndex >= $count || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) {
                continue;
            }
            $total++;
            if (!function_documentation_php_has_docblock($tokens, $index)) {
                $relative = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $root)) + 1);
                $missing[] = $relative . ':' . $token[2] . ':' . $tokens[$nameIndex][1];
            }
        }
    }
    return ['total' => $total, 'missing' => $missing];
}

/**
 * Return whether source text before a JavaScript declaration ends in JSDoc.
 *
 * @param string $source Complete JavaScript source.
 * @param int $offset Declaration byte offset.
 */
function function_documentation_js_has_docblock(string $source, int $offset): bool
{
    $prefix = rtrim(substr($source, 0, $offset));
    return preg_match('#/\*\*[\s\S]*?\*/\s*$#', $prefix) === 1;
}

/**
 * Find undocumented named JavaScript functions, methods, and arrow functions.
 *
 * The scanner intentionally targets stable names. Anonymous callbacks are excluded
 * because they are implementation expressions rather than reusable entry points.
 *
 * @param string $root Repository root.
 * @param array<int,string> $files JavaScript source paths.
 * @return array{total:int,missing:array<int,string>}
 */
function function_documentation_audit_javascript(string $root, array $files): array
{
    $patterns = [
        '/^[ \t]*(?:export\s+)?(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/m',
        '/^[ \t]*(?:export\s+)?(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(?:async\s*)?(?:\(\s*(?:[A-Za-z_$][A-Za-z0-9_$]*(?:\s*,\s*[A-Za-z_$][A-Za-z0-9_$]*)*)?\s*\)|[A-Za-z_$][A-Za-z0-9_$]*)\s*=>/m',
        '/^[ \t]*(?:static\s+)?(?:async\s+)?([A-Za-z_$][A-Za-z0-9_$]*)\s*\([^\r\n;{}]*\)\s*\{/m',
    ];
    $reserved = array_fill_keys(['if', 'for', 'while', 'switch', 'catch', 'with', 'function'], true);
    $total = 0;
    $missing = [];
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $declarations = [];
        foreach ($patterns as $patternIndex => $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] ?? [] as $matchIndex => $nameMatch) {
                $name = (string) $nameMatch[0];
                if ($patternIndex === 2 && isset($reserved[$name])) {
                    continue;
                }
                $offset = (int) ($matches[0][$matchIndex][1] ?? 0);
                $declarations[$offset . ':' . $name] = ['offset' => $offset, 'name' => $name];
            }
        }
        foreach ($declarations as $declaration) {
            $total++;
            if (function_documentation_js_has_docblock($source, (int) $declaration['offset'])) {
                continue;
            }
            $offset = (int) $declaration['offset'];
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $relative = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $root)) + 1);
            $missing[] = $relative . ':' . $line . ':' . $declaration['name'];
        }
    }
    return ['total' => $total, 'missing' => $missing];
}

$root = dirname(__DIR__);
$php = function_documentation_audit_php($root, function_documentation_source_files($root, ['php']));
$javascript = function_documentation_audit_javascript($root, function_documentation_source_files($root, ['js']));
$missing = array_merge($php['missing'], $javascript['missing']);

if ($missing !== []) {
    fwrite(STDERR, "Undocumented named functions/methods:\n" . implode("\n", $missing) . "\n");
    fwrite(STDERR, 'PHP declarations: ' . $php['total'] . '; JavaScript declarations: ' . $javascript['total'] . '; missing: ' . count($missing) . "\n");
    exit(1);
}

echo 'Function documentation checks passed: '
    . $php['total'] . ' PHP and '
    . $javascript['total'] . " JavaScript named declarations documented.\n";
