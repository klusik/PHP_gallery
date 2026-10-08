<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/login_return_target_security_test.php
 * Module Type: Regression Test
 * Purpose: Keep post-login redirects on the current origin in browser URL semantics.
 * Responsibilities: Exercise safe relative return paths and unsafe slash/backslash authority forms.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/helpers_request.php';

use function Gallery\Core\sanitize_login_return_target;

/**
 * Assert one login return target against the explicit fallback contract.
 *
 * @param string $input Candidate supplied login return location.
 * @param string $expected Expected local URL or fallback URL.
 * @param string $fallback Safe local fallback URL.
 * @return void No value when the redirect policy is correct.
 */
function login_return_target_assert(string $input, string $expected, string $fallback): void
{
    $actual = sanitize_login_return_target($input, $fallback);
    if ($actual !== $expected) {
        throw new RuntimeException('Login return target was not normalized or refused as expected: ' . json_encode($input));
    }
}

$fallback = '/index.php?page=admin';
foreach ([
    '/index.php?page=gallery&gallery_id=8' => '/index.php?page=gallery&gallery_id=8',
    'galleries/flowers' => '/galleries/flowers',
    '?page=home' => '/?page=home',
    '/viewer/shared/123' => '/viewer/shared/123',
] as $candidate => $expected) {
    login_return_target_assert($candidate, $expected, $fallback);
}

foreach ([
    '//evil.example/steal',
    '///evil.example/steal',
    '/\evil.example/steal',
    '/\\\\evil.example/steal',
    '\evil.example/steal',
    '\\\\evil.example/steal',
    '/index.php?page=gallery\evil.example',
    'https://evil.example/steal',
    "https://gallery.example/\r\nLocation: https://evil.example",
    '/index.php?page=admin_logout',
    '/setup',
] as $candidate) {
    login_return_target_assert($candidate, $fallback, $fallback);
}

login_return_target_assert('', $fallback, $fallback);
echo "Login return target redirect safety passed.\n";
