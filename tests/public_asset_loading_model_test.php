<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_asset_loading_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies that anonymous public pages use the reduced public asset set while
 *   admin and logged-in public views keep the legacy admin asset set.
 *
 * Responsibilities:
 *   - Prevent anonymous public pages from loading admin-only stylesheets by default
 *   - Confirm shared public CSS stays present for footer, pagination, votes, tags, and hero controls
 *   - Confirm admin pages and logged-in public tooling still receive the full legacy entrypoint
 *   - Keep the test database-free for plain PHP execution
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-06-09
 */

declare(strict_types=1);

if (!function_exists('e')) {
    /**
     * Minimal HTML escaping shim for layout helper tests.
     */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

require_once __DIR__ . '/../app/views/layout.php';

/**
 * Throw when two values are not identical.
 */
function assert_public_asset_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Throw when an array does not contain an expected value.
 */
function assert_public_asset_contains(string $needle, array $haystack, string $label): void
{
    if (!in_array($needle, $haystack, true)) {
        throw new RuntimeException($label . ' did not contain ' . var_export($needle, true) . '. Assets: ' . implode(', ', $haystack));
    }
}

/**
 * Throw when an array contains an unexpected value.
 */
function assert_public_asset_not_contains(string $needle, array $haystack, string $label): void
{
    if (in_array($needle, $haystack, true)) {
        throw new RuntimeException($label . ' unexpectedly contained ' . var_export($needle, true) . '. Assets: ' . implode(', ', $haystack));
    }
}

$anonymousPublicStyles = view_stylesheet_files_for_context('public-page', null, false);
assert_public_asset_contains('assets/styles/public-shared.css', $anonymousPublicStyles, 'anonymous public styles include extracted shared public CSS');
assert_public_asset_contains('assets/styles/public.css', $anonymousPublicStyles, 'anonymous public styles include public CSS');
assert_public_asset_contains('assets/styles/lightbox.css', $anonymousPublicStyles, 'anonymous public styles include lightbox CSS');

foreach (view_admin_stylesheet_files() as $styleFile) {
    if (in_array($styleFile, ['assets/styles/base.css', 'assets/styles/public.css', 'assets/styles/lightbox.css', 'assets/styles/utilities.css', 'assets/styles.css'], true)) {
        continue;
    }
    assert_public_asset_not_contains($styleFile, $anonymousPublicStyles, 'anonymous public styles exclude admin-only CSS');
}

$loggedInUser = ['id' => 1, 'username' => 'admin'];
$loggedInPublicStyles = view_stylesheet_files_for_context('public-page', $loggedInUser, false);
assert_public_asset_contains('assets/styles/admin.css', $loggedInPublicStyles, 'logged-in public styles keep admin CSS');
assert_public_asset_contains('assets/styles/side-panel.css', $loggedInPublicStyles, 'logged-in public styles keep side panel CSS');

$anonymousPreviewStyles = view_stylesheet_files_for_context('public-page', $loggedInUser, true);
assert_public_asset_same($anonymousPublicStyles, $anonymousPreviewStyles, 'anonymous preview uses the anonymous public CSS set');

$adminStyles = view_stylesheet_files_for_context('admin-page', $loggedInUser, false);
assert_public_asset_contains('assets/styles/admin.css', $adminStyles, 'admin pages include admin CSS');
assert_public_asset_contains('assets/styles/admin-layout.css', $adminStyles, 'admin pages include admin layout CSS');
assert_public_asset_contains('assets/styles/side-panel.css', $adminStyles, 'admin pages include side panel CSS');

assert_public_asset_same('assets/public-gallery.js', view_script_asset_for_context(false, null, false), 'anonymous public pages use the public entrypoint');
assert_public_asset_same('assets/gallery.js', view_script_asset_for_context(false, $loggedInUser, false), 'logged-in public pages keep the full entrypoint');
assert_public_asset_same('assets/public-gallery.js', view_script_asset_for_context(false, $loggedInUser, true), 'anonymous preview uses the public entrypoint');
assert_public_asset_same('assets/gallery.js', view_script_asset_for_context(true, null, false), 'admin pages keep the full entrypoint');

echo "Public asset loading model tests passed.\n";
