<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_edit_writer_source.php
 * Module Type: Test Support
 * Purpose: Read complete writer function bodies for ownership and stale-snapshot contracts.
 * Responsibilities:
 *   - Preserve executable source while ignoring braces inside strings and comments
 *   - Compile selected function bodies with explicit in-memory test adapters
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/**
 * Reject a broken source or execution contract without relying on PHP assertion settings.
 *
 * @param bool $condition Whether the contract holds.
 * @param string $message Bounded failure description.
 * @return void
 */
function gallery_writer_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Read named function declarations and bodies without counting braces inside strings/comments.
 *
 * @param string $source Complete entry-point and ordered part-file source.
 * @return array<string,array{declaration:string,body:string,doc:string}> Named function source.
 */
function gallery_writer_functions(string $source): array
{
    $tokens = token_get_all($source);
    $functions = [];
    $doc = '';
    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        $token = $tokens[$index];
        if (is_array($token) && $token[0] === T_DOC_COMMENT) {
            $doc = $token[1];
        }
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $cursor = $index + 1;
        while (isset($tokens[$cursor]) && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE) {
            $cursor++;
        }
        if (!isset($tokens[$cursor]) || !is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_STRING) {
            continue;
        }
        $name = $tokens[$cursor][1];
        $declaration = '';
        for ($cursor = $index; $cursor < $count && $tokens[$cursor] !== '{'; $cursor++) {
            $declaration .= is_array($tokens[$cursor]) ? $tokens[$cursor][1] : $tokens[$cursor];
        }
        $body = '';
        $depth = 0;
        for (; $cursor < $count; $cursor++) {
            $part = $tokens[$cursor];
            if ($part === '{' || (is_array($part) && in_array($part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($part === '}') {
                $depth--;
            }
            $body .= is_array($part) ? $part[1] : $part;
            if ($depth === 0) {
                break;
            }
        }
        gallery_writer_check($depth === 0 && $body !== '', 'Unbalanced function: ' . $name);
        gallery_writer_check(!isset($functions[$name]), 'Duplicate function: ' . $name);
        $functions[$name] = ['declaration' => trim($declaration), 'body' => $body, 'doc' => $doc];
        $index = $cursor;
        $doc = '';
    }
    return $functions;
}

/**
 * Normalize executable tokens while retaining strings and ignoring comments/formatting.
 *
 * @param string $source Function body or declaration fragment.
 * @return string Comparable executable source.
 */
function gallery_writer_compact(string $source): string
{
    $result = '';
    foreach (token_get_all('<?php ' . $source) as $token) {
        if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $result .= is_array($token) ? $token[1] : $token;
    }
    return $result;
}

/**
 * Compile an actual service function with explicit read/write adapters for a unit fixture.
 *
 * This does not load application bootstrap or configuration. Only specified
 * function calls are substituted; branches, semantic arguments and exceptions
 * remain the actual implementation. Ownership itself is exercised separately by
 * the public-wrapper and disposable-MySQL tests.
 *
 * @param string $source Complete service-module source.
 * @param string $name Named service function to exercise.
 * @param array<string,callable(mixed...):mixed> $adapters Explicit named substitutes, each accepting its replaced dependency's semantic arguments and returning that dependency's fixture result.
 * @return Closure(mixed...):mixed Actual service body retaining its original signature, including reference parameters and return type, bound to the supplied adapters.
 */
function gallery_writer_fixture_function(string $source, string $name, array $adapters): Closure
{
    $function = gallery_writer_functions($source)[$name] ?? null;
    gallery_writer_check(is_array($function), 'Missing snapshot function: ' . $name);
    $declaration = str_replace('function ' . $name, 'static function', $function['declaration']);
    $declaration = preg_replace('/\)\s*:/', ') use ($adapters):', $declaration, 1);
    $body = $function['body'];
    foreach (array_keys($adapters) as $call) {
        $body = str_replace($call . '(', '$adapters[' . var_export($call, true) . '](', $body);
    }
    $callable = eval('namespace Gallery\\Services; use RuntimeException; use Throwable; return ' . $declaration . ' ' . $body . ';');
    gallery_writer_check($callable instanceof Closure, 'Snapshot function did not compile: ' . $name);
    return $callable;
}
