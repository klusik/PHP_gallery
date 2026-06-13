<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/favorite_galleries_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies favorite gallery shortcut normalization and header rendering.
 *
 * Responsibilities:
 *   - Cover zero, one, and three favorite shortcut rendering cases
 *   - Cover duplicate, missing, invalid, and over-limit submitted IDs
 *   - Cover public visibility filtering for anonymous navigation
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
 *   2026-06-04
 */

declare(strict_types=1);

use function Gallery\Services\theme_favorite_gallery_entries_from_form;
use function Gallery\Services\theme_favorite_gallery_existing_ids_from_rows;
use function Gallery\Services\theme_favorite_gallery_ids_encode;
use function Gallery\Services\theme_favorite_gallery_ids_normalize;
use function Gallery\Services\theme_favorite_gallery_navigation_items_from_rows;
use function Gallery\Views\view_favorite_gallery_nav_html;

if (!function_exists('e')) {
        /**
     * Minimal HTML escaping shim for view tests.
     *
     * @param ?string $value Value to process.
     * @return string Text result for the caller.
     */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('t')) {
        /**
     * Minimal translation shim for service tests.
     *
     * @param string $key Lookup key.
     * @param string|array|null $fallback Fallback value.
     * @param array $parameters Parameters value.
     * @return string Text result for the caller.
     */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        $text = is_string($fallback) ? $fallback : $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

if (!function_exists('url_for')) {
        /**
     * Deterministic route URL shim for navigation model tests.
     *
     * @param string $route Route value.
     * @param array $params Params value.
     * @return string Text result for the caller.
     */
    function url_for(string $route, array $params = []): string
    {
        return $route === 'home' ? '/' : '/?page=' . rawurlencode($route);
    }
}

if (!function_exists('gallery_public_url')) {
        /**
     * Deterministic gallery URL shim for navigation model tests.
     *
     * @param array $gallery Gallery row or gallery data.
     * @return string Text result for the caller.
     */
    function gallery_public_url(array $gallery): string
    {
        return '/gallery/' . rawurlencode(trim((string) ($gallery['url_path'] ?? $gallery['folder_path'] ?? $gallery['slug'] ?? 'gallery'), '/')) . '/';
    }
}

if (!function_exists('gallery_is_public_listed')) {
        /**
     * Minimal public listing rule used by anonymous navigation tests.
     *
     * @param array $gallery Gallery row or gallery data.
     * @return bool True when the condition matches.
     */
    function gallery_is_public_listed(array $gallery): bool
    {
        return (string) ($gallery['visibility'] ?? 'public') === 'public'
            && (string) ($gallery['access_listing'] ?? 'listed') === 'listed';
    }
}

require_once __DIR__ . '/support/namespaced_shims.php';
require_once __DIR__ . '/../app/services/favorite_galleries.php';
require_once __DIR__ . '/../app/views/layout.php';

/**
 * Throw when a favorite-gallery expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Label value.
 */
function assert_favorite_galleries_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Throw when a rendered string does not contain an expected substring.
 *
 * @param string $needle Needle value.
 * @param string $haystack Haystack value.
 * @param string $label Label value.
 */
function assert_favorite_galleries_contains(string $needle, string $haystack, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($label . ' did not contain ' . var_export($needle, true) . '. HTML: ' . $haystack);
    }
}

/**
 * Throw when a rendered string contains an unexpected substring.
 *
 * @param string $needle Needle value.
 * @param string $haystack Haystack value.
 * @param string $label Label value.
 */
function assert_favorite_galleries_not_contains(string $needle, string $haystack, string $label): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($label . ' unexpectedly contained ' . var_export($needle, true) . '. HTML: ' . $haystack);
    }
}

$rows = [
    10 => ['id' => 10, 'title' => 'Aircraft', 'folder_path' => 'Flying/Aircraft', 'url_path' => 'flying/aircraft', 'slug' => 'aircraft', 'visibility' => 'public', 'access_listing' => 'listed'],
    20 => ['id' => 20, 'title' => 'Railways', 'folder_path' => 'Railways', 'url_path' => 'railways', 'slug' => 'railways', 'visibility' => 'public', 'access_listing' => 'listed'],
    30 => ['id' => 30, 'title' => 'Private Notes', 'folder_path' => 'Private', 'url_path' => 'private', 'slug' => 'private', 'visibility' => 'private', 'access_listing' => 'listed'],
    40 => ['id' => 40, 'title' => 'Unlisted Trip', 'folder_path' => 'Trips/Hidden', 'url_path' => 'trips/hidden', 'slug' => 'hidden', 'visibility' => 'public', 'access_listing' => 'unlisted'],
];

assert_favorite_galleries_same([], theme_favorite_gallery_ids_normalize(''), 'empty value normalizes to no favorites');
assert_favorite_galleries_same([10, 20, 30], theme_favorite_gallery_ids_normalize('[10,"20",20,0,-5,30,40]'), 'JSON input removes duplicates and clamps to three');
assert_favorite_galleries_same(['home', 10, 20], theme_favorite_gallery_ids_normalize(['home', '10', 'home', '20', '30']), 'array input keeps one main page shortcut and clamps to three');
assert_favorite_galleries_same('["home",10,20]', theme_favorite_gallery_ids_encode(['home', 10, 10, 20, 30]), 'encoded setting is stable JSON with main page shortcut');
assert_favorite_galleries_same(['home', 20], theme_favorite_gallery_entries_from_form(['home', 'gallery', '', 'gallery'], ['', '20', '30', '40']), 'form slot parser pairs shortcut types with gallery IDs');

assert_favorite_galleries_same(['home', 10, 20], theme_favorite_gallery_existing_ids_from_rows(['home', 10, 999, 20, 20], $rows), 'missing and duplicate submitted shortcuts are dropped before save');

$zeroHtml = view_favorite_gallery_nav_html(theme_favorite_gallery_navigation_items_from_rows([], $rows, true));
assert_favorite_galleries_same('', $zeroHtml, 'zero favorites render no header gallery button');
assert_favorite_galleries_not_contains('Galleries', $zeroHtml, 'zero favorites do not fall back to the legacy Galleries label');

$homeItem = theme_favorite_gallery_navigation_items_from_rows(['home'], $rows, true);
$homeHtml = view_favorite_gallery_nav_html($homeItem);
assert_favorite_galleries_same(1, substr_count($homeHtml, 'class="nav-favorite-gallery"'), 'main page shortcut renders one shortcut');
assert_favorite_galleries_contains('Main page', $homeHtml, 'main page shortcut uses translated label');
assert_favorite_galleries_contains('href="/"', $homeHtml, 'main page shortcut links to home route');

$oneItem = theme_favorite_gallery_navigation_items_from_rows([10], $rows, true);
$oneHtml = view_favorite_gallery_nav_html($oneItem);
assert_favorite_galleries_same(1, substr_count($oneHtml, 'class="nav-favorite-gallery"'), 'one configured favorite renders one shortcut');
assert_favorite_galleries_contains('Aircraft', $oneHtml, 'one favorite uses gallery title');
assert_favorite_galleries_contains('/gallery/flying%2Faircraft/', $oneHtml, 'one favorite links to gallery URL');

$threeItems = theme_favorite_gallery_navigation_items_from_rows(['home', 20, 30], $rows, false);
$threeHtml = view_favorite_gallery_nav_html($threeItems);
assert_favorite_galleries_same(3, substr_count($threeHtml, 'class="nav-favorite-gallery"'), 'three configured favorites render three shortcuts for admin navigation');
assert_favorite_galleries_contains('Main page', $threeHtml, 'admin navigation can include the main page shortcut');
assert_favorite_galleries_contains('Private Notes', $threeHtml, 'admin navigation can include existing private configured favorites');

$publicItems = theme_favorite_gallery_navigation_items_from_rows([10, 30, 20], $rows, true);
$publicHtml = view_favorite_gallery_nav_html($publicItems);
assert_favorite_galleries_same(2, substr_count($publicHtml, 'class="nav-favorite-gallery"'), 'anonymous navigation filters private and unlisted favorites');
assert_favorite_galleries_contains('Aircraft', $publicHtml, 'public favorite remains visible');
assert_favorite_galleries_contains('Railways', $publicHtml, 'later public favorite remains visible after filtered rows');
assert_favorite_galleries_not_contains('Private Notes', $publicHtml, 'private favorite is hidden from anonymous navigation');
assert_favorite_galleries_not_contains('Unlisted Trip', $publicHtml, 'unlisted favorite is hidden from anonymous navigation');

$escapedHtml = view_favorite_gallery_nav_html([
    ['title' => 'A < B', 'url' => '/gallery/a?x=1&y=2'],
]);
assert_favorite_galleries_contains('A &lt; B', $escapedHtml, 'favorite title is escaped');
assert_favorite_galleries_contains('/gallery/a?x=1&amp;y=2', $escapedHtml, 'favorite URL is escaped');

echo "Favorite gallery shortcut model tests passed.\n";
