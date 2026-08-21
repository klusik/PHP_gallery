<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_home_clean_url_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Keeps the public site/home link on the clean deployment root whenever URL
 *   rewriting is enabled and usable.
 *
 * Responsibilities:
 *   - Prevent public branding/breadcrumb links from unnecessarily emitting index.php?page=home
 *   - Preserve query-string compatibility when rewrite support is unavailable
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

declare(strict_types=1);

/**
 * Throw when a clean-home URL source contract is not satisfied.
 *
 * @param bool $condition Assertion condition.
 * @param string $label Assertion label.
 */
function assert_public_home_clean_url(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$source = (string) file_get_contents(__DIR__ . '/../app/helpers_request.php');

assert_public_home_clean_url(
    str_contains($source, "if (\$page === 'home' && \$params === [] && url_rewrite_should_emit_clean_urls())")
        && str_contains($source, 'return base_url();'),
    'url_for(home) must prefer the clean deployment root when rewrite support is usable.'
);

assert_public_home_clean_url(
    str_contains($source, "\$params = ['page' => \$page] + \$params;")
        && str_contains($source, "return base_url('index.php?' . http_build_query(\$params));"),
    'The generic index.php query-string fallback must remain available.'
);

fwrite(STDOUT, "Public home clean URL tests passed.\n");
