<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/php_source.php
 * Module Type: Regression Test Support
 * Purpose: Compare PHP source contracts across harmless formatter changes.
 * Responsibilities: Preserve literal/operator tokens while allowing whitespace
 *   and optional trailing commas before closing parentheses or brackets.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

/**
 * Encode significant PHP tokens without changing literals or expression groups.
 *
 * @param string $source Complete PHP source, or an open-tag-prefixed fragment.
 * @return string Delimited token identities; whitespace, comments and optional
 *     trailing commas are omitted, and all other tokens remain exact.
 */
function php_source_contract_tokens(string $source): string
{
    $tokens = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            // Native token tuples contain kind, literal bytes and source line.
            if (in_array(
                $token[0],
                [
                    T_OPEN_TAG,
                    T_WHITESPACE,
                    T_COMMENT,
                    T_DOC_COMMENT,
                ],
                true,
            )) {
                continue;
            }
            $tokens[] = [$token[0], $token[1]];
        } else {
            $tokens[] = [null, $token];
        }
    }

    $encoded = [];
    foreach ($tokens as $index => $token) {
        // One-token lookahead recognizes PHP's optional terminal comma only.
        if (
            $token[1] === ','
            && in_array($tokens[$index + 1][1] ?? null, [')', ']'], true)
        ) {
            continue;
        }
        $encoded[] = json_encode($token, JSON_THROW_ON_ERROR);
    }
    return "\0" . implode("\0", $encoded) . "\0";
}

/**
 * Find an exact source fragment or its equivalent significant PHP token sequence.
 *
 * Raw matching retains existing partial-string/markup assertions. Token matching
 * accepts formatter whitespace and trailing commas, preserving argument order,
 * operators, parentheses and literal contents. It never evaluates source code.
 *
 * @param string $source Complete PHP source, an extracted PHP body, or raw markup.
 * @param string $fragment Required literal fragment or complete PHP token span.
 * @return bool True when the raw fragment or its significant tokens occur.
 */
function php_source_contains(string $source, string $fragment): bool
{
    return (
        str_contains($source, $fragment)
            || str_contains(
                php_source_contract_tokens(
                    str_contains($source, '<?') ? $source : '<?php ' . $source,
                ),
                php_source_contract_tokens('<?php ' . $fragment),
            )
    );
}
