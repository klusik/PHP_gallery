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
  *
 * Author:
 *   Rudolf Klusal
*/

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_source_documentation.php';

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
    foreach (\PhpGallery\SourceContracts\inventory($root)['files'] as $relative => $extension) {
        if (in_array($extension, $extensions, true)) {
            $files[] = $root . '/' . $relative;
        }
    }
    sort($files, SORT_STRING);
    return $files;
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
        foreach (\PhpGallery\SourceContracts\php_declarations($source) as $record) {
            if ($record['kind'] !== 'callable') {
                continue;
            }
            $total++;
            if ($record['doc'] === '') {
                $relative = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $root)) + 1);
                $missing[] = $relative . ':' . $record['line'] . ':' . $record['name'];
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
 * @return bool Whether the nearest completed comment is an attached JSDoc.
 */
function function_documentation_js_has_docblock(string $source, int $offset): bool
{
    $prefix = rtrim(substr($source, 0, $offset));
    return preg_match('#/\*\*(?:(?!\*/)[\s\S])*\*/\s*$#', $prefix) === 1;
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

/**
 * Enforce the historical named-callable gate using shared source discovery.
 * Broader classes, callbacks, shapes and module variants remain visible through
 * scripts/check_source_documentation.php; this gate does not certify those.
 * @return int Zero when the historical contract passes; one for missing PHPDoc/JSDoc.
 */
function function_documentation_main(): int
{
    $root = dirname(__DIR__);
    $php = function_documentation_audit_php($root, function_documentation_source_files($root, ['php']));
    $javascript = function_documentation_audit_javascript($root, function_documentation_source_files($root, ['js']));
    $missing = array_merge($php['missing'], $javascript['missing']);
    if ($missing !== []) {
        fwrite(STDERR, "Undocumented named functions/methods:\n" . implode("\n", $missing) . "\n");
        fwrite(STDERR, 'PHP declarations: ' . $php['total'] . '; JavaScript declarations: ' . $javascript['total'] . '; missing: ' . count($missing) . "\n");
        return 1;
    }
    echo 'Function documentation checks passed: '
        . $php['total'] . ' PHP and '
        . $javascript['total'] . " JavaScript named declarations documented (presence gate only).\n";
    return 0;
}
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(function_documentation_main());
}
