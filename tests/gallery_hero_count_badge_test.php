<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_hero_count_badge_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the opened-gallery branch image count badge integration contract.
 *
 * Responsibilities:
 *   - Require the existing effective count-badge policy before branch counting
 *   - Require the canonical branch image counter and public render profiling span
 *   - Verify accessible translated hero markup and responsive presentation
 *   - Verify all maintained translation catalogs contain the hero strings
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
 *   2026-09-02
 */

declare(strict_types=1);

/**
 * Require a source fragment to remain present.
 *
 * @param string $source Source text.
 * @param string $needle Required fragment.
 * @param string $label Assertion label.
 */
function assert_gallery_hero_count_contains(string $source, string $needle, string $label): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' is missing required source fragment: ' . $needle);
    }
}

$controller = file_get_contents(__DIR__ . '/../app/controllers/public_gallery_page.php');
$styles = file_get_contents(__DIR__ . '/../public/assets/styles/utilities.css');
if (!is_string($controller) || !is_string($styles)) {
    throw new RuntimeException('Unable to read gallery hero count badge sources.');
}

assert_gallery_hero_count_contains($controller, '$showHeroCountBadge = gallery_effective_count_badge_enabled($gallery);', 'effective badge policy');
assert_gallery_hero_count_contains($controller, '$showHeroCountBadge' . "\n" . "        ? public_render_profile_span('gallery_hero_branch_image_count'", 'disabled-state query guard and profile span');
assert_gallery_hero_count_contains($controller, 'gallery_branch_image_count((int) $gallery[\'id\'], $publicOnly)', 'canonical branch counter');
assert_gallery_hero_count_contains($controller, 'if ($showHeroCountBadge) {', 'conditional hero rendering');
assert_gallery_hero_count_contains($controller, 'gallery.hero.branch_image_count_aria', 'translated accessible label');
assert_gallery_hero_count_contains($controller, 'gallery.hero.branch_image_count_hint', 'translated explanatory title');
assert_gallery_hero_count_contains($controller, 'gallery-hero-count-badge', 'hero count badge markup');
assert_gallery_hero_count_contains($controller, 'subgallery-stack-icon', 'shared stacked-picture icon');

assert_gallery_hero_count_contains($styles, '.public-page .gallery-hero-count-badge', 'hero badge styling');
assert_gallery_hero_count_contains($styles, 'display: inline-flex;', 'in-flow hero badge layout');
assert_gallery_hero_count_contains($styles, 'font-variant-numeric: tabular-nums;', 'stable count typography');

foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $catalogPath = __DIR__ . '/../app/lang/' . $language . '.json';
    $catalogJson = file_get_contents($catalogPath);
    if (!is_string($catalogJson)) {
        throw new RuntimeException('Unable to read translation catalog: ' . $catalogPath);
    }
    $catalog = json_decode($catalogJson, true, 512, JSON_THROW_ON_ERROR);
    foreach (['gallery.hero.branch_image_count_aria', 'gallery.hero.branch_image_count_hint'] as $key) {
        if (!isset($catalog[$key]) || trim((string) $catalog[$key]) === '') {
            throw new RuntimeException($language . ' catalog is missing translation key: ' . $key);
        }
    }
}

fwrite(STDOUT, "Gallery hero count badge contracts passed.\n");
