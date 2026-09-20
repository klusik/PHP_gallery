<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/runtime_normalization_environment_test.php
 * Module Type: Regression Test
 * Purpose: Verify installed Unicode and ASCII normalization behavior.
 * Responsibilities:
 *   - Exercise real environment capabilities and report bounded diagnostics.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Exercise the actual installed Unicode/ASCII capability with strict diagnostics.
 */
declare(strict_types=1);

/**
 * Turn unsuppressed PHP diagnostics in this runtime smoke test into failures.
 *
 * @param int $severity PHP diagnostic category.
 * @param string $message Private diagnostic text, deliberately discarded.
 * @param string $file Private source path, deliberately discarded.
 * @param int $line Source line, deliberately discarded.
 * @return bool False leaves intentionally suppressed diagnostics to PHP.
 */
function runtime_normalization_error(int $severity, string $message, string $file, int $line): bool
{
    if ((error_reporting() & $severity) !== 0) {
        throw new RuntimeException('Unexpected PHP diagnostic in runtime normalization.');
    }
    return false;
}

set_error_handler('runtime_normalization_error', E_ALL);
$previousReporting = error_reporting(E_ALL);
try {
    require_once dirname(__DIR__) . '/app/services/gallery_picker.php';
    $expected = (string) getenv('GALLERY_RUNTIME_NORMALIZATION');
    $unicode = class_exists(Normalizer::class) && function_exists('mb_strtolower');
    $mode = Gallery\Services\gallery_title_completion_empty_result()['normalization'];
    if (!in_array($expected, ['', 'unicode', 'ascii'], true)
        || ($expected === 'unicode' && !$unicode) || ($expected === 'ascii' && $unicode)
        || $mode !== ($unicode ? 'nfkc-lowercase' : 'ascii-lowercase')) {
        throw new RuntimeException('Configured CI normalization environment does not match installed capabilities.');
    }
    $samples = ['FLIGHT' => 'flight', "\u{FF26}\u{FF2C}\u{FF29}\u{FF27}\u{FF28}\u{FF34}" => $unicode ? 'flight' : null,
        "E\u{0301}cole" => $unicode ? "\u{00E9}cole" : null, "\u{FB00}light" => $unicode ? 'fflight' : null];
    foreach ($samples as $input => $normalized) {
        if (Gallery\Services\gallery_title_completion_normalize($input, $mode) !== $normalized) {
            throw new RuntimeException('Installed runtime normalization differs from its declared mode.');
        }
    }
    echo 'PASS runtime normalization installed mode ' . $mode . " with strict warnings and deprecations\n";
} finally {
    error_reporting($previousReporting);
    restore_error_handler();
}
