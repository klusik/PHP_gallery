<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/readability_tokens.php
 * Module Type: Advisory Token Adapter
 * Purpose: Reuse existing source tokenizers without executing inspected code.
 * Responsibilities:
 *   - Remove literal/comment contents from readability decisions.
 *   - Retain declaration boundaries and canonical discovery exclusions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\Readability;

require_once __DIR__ . '/cli_guard.php';
\gallery_guard_cli_entrypoint(__FILE__);
require_once __DIR__ . '/source_contracts/inventory.php';
require_once __DIR__ . '/source_contracts/php.php';
require_once __DIR__ . '/source_contracts/javascript.php';

/**
 * Bound JSON nesting accepted from the reporter's token-adapter request.
 * @var int
 * Units: nested JSON levels. Scope: one adapter stdin request.
 * Consumers: the json_decode() request boundary below.
 * Rationale: retain PHP's standard 512-level decoder budget; source text is
 * string data, so nested source syntax does not consume this request budget.
 */
const READABILITY_REQUEST_JSON_DEPTH = 512;

/**
 * Prepare code-only lines and named declaration spans for one source blob.
 * @param string $source Complete immutable source text.
 * @param string $language PHP, JS or MJS language identifier.
 * @return array{code:list<string>,literals:array<int,list<string>>,embedded_lines:list<int>,declarations:list<array{start:int,end:int,anchor:int,name:string}>} Code skeletons, literal fragments, zero-based mixed HTML lines and named spans.
 */
function source_structure(string $source, string $language): array
{
    $php = $language === 'php';
    $tokens = $php
        ? \PhpGallery\SourceContracts\php_tokens($source)
        : \PhpGallery\SourceContracts\javascript_tokens($source);
    $records = $php
        ? \PhpGallery\SourceContracts\php_declarations($source)
        : \PhpGallery\SourceContracts\javascript_declarations($source);
    $lines = explode("\n", $source);
    $code = array_fill(0, count($lines), '');
    $literals = [];
    $embedded = [];
    $inString = false;
    foreach ($tokens as $token) {
        $id = $token['id'];
        $text = $token['text'];
        if ($id === T_INLINE_HTML) {
            foreach (explode("\n", $text) as $offset => $part) {
                if (trim($part) !== '') {
                    $embedded[$token['line'] + $offset - 1] = true;
                }
            }
        }
        if (in_array(
            $id,
            [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG],
            true,
        )) {
            continue;
        }
        // Interpolated PHP strings and heredocs stay inert, including variables.
        $delimiter = $php && ($id === T_START_HEREDOC || $text === '"');
        $literal =
            $inString
            || $delimiter
            || in_array(
                $id,
                [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE],
                true,
            );
        foreach (explode("\n", $text) as $offset => $part) {
            $line = $token['line'] + $offset - 1;
            if ($literal) {
                $literals[$line][] = $part;
                $code[$line] .= ' L ';
            } else {
                $code[$line] .= $part . ' ';
            }
        }
        if ($delimiter || ($php && $id === T_END_HEREDOC)) {
            $inString = !$inString;
        }
    }
    $declarations = [];
    foreach ($records as $record) {
        if (
            str_starts_with($record['name'], '(')
            || in_array(
                $record['kind'],
                ['property', 'callback', 'bound_callback'],
                true,
            )
        ) {
            continue;
        }
        $start = $tokens[$record['start_token']]['line'];
        $endToken = $tokens[$record['end_token']];
        $anchor = $start;
        if ($record['doc'] !== '') {
            for ($index = $record['start_token'] - 1; $index >= 0; $index--) {
                if ($tokens[$index]['text'] === $record['doc']) {
                    $anchor = $tokens[$index]['line'];
                    break;
                }
                if (in_array($tokens[$index]['text'], [';', '{', '}'], true)) {
                    break;
                }
            }
        }
        $declarations[] = [
            'start' => $start,
            'end' => $endToken['line'] + substr_count($endToken['text'], "\n"),
            'anchor' => $anchor,
            'name' => $record['name'],
        ];
    }
    return [
        'code' => $code,
        'literals' => $literals,
        'embedded_lines' => array_keys($embedded),
        'declarations' => $declarations,
    ];
}

$request = json_decode(
    stream_get_contents(STDIN),
    true,
    READABILITY_REQUEST_JSON_DEPTH,
    JSON_THROW_ON_ERROR,
);
$files = [];
foreach ($request as $path => $input) {
    $files[$path] = source_structure($input['source'], $input['language']);
}
echo json_encode([
    'excluded_directories' =>
        \PhpGallery\SourceContracts\source_excluded_directories(),
    'files' => $files,
], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
