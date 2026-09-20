<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect stabilized progressive-search interaction behavior.
 * Responsibilities:
 *   - Check final UX and MVC contracts across search phases.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_search_progressive_stage7_test.php
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
/**
 * Protect Stage 7 progressive public-search UX stabilization and MVC completion.
 */

declare(strict_types=1);

/**
 * Assert one Stage 7 public-search contract.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function public_search_progressive_stage7_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$browserSource = (string) file_get_contents($root . '/public/assets/gallery-modules/public-home-search.js');
$styleSource = (string) file_get_contents($root . '/public/assets/styles/public.css');
$viewSource = (string) file_get_contents($root . '/app/views/public_search.php');
$compatibilityServiceSource = (string) file_get_contents($root . '/app/services/public_search.php');
$compatibilityModelSource = (string) file_get_contents($root . '/app/models/public_search.php');
$modelsLoader = (string) file_get_contents($root . '/app/models.php');
$galleryEntrySource = (string) file_get_contents($root . '/public/assets/gallery.js');
$publicGalleryEntrySource = (string) file_get_contents($root . '/public/assets/public-gallery.js');

public_search_progressive_stage7_assert(
    str_contains($browserSource, "querySelectorAll('[data-public-home-search-result-key]')")
        && str_contains($browserSource, 'list.append(link);')
        && str_contains($browserSource, 'renderedResultKeys = nextKeys;'),
    'Stage 7 must reconcile stable result DOM nodes instead of recreating the complete result list on every phase.'
);
public_search_progressive_stage7_assert(
    !str_contains($browserSource, 'requestCompatibilitySearch')
        && !str_contains($browserSource, 'compatibilityController'),
    'Dead monolithic compatibility browser request code must be removed after the progressive flow becomes authoritative.'
);
public_search_progressive_stage7_assert(
    str_contains($browserSource, "link.classList.add('is-new')")
        && str_contains($styleSource, '.public-home-search-result.is-new')
        && str_contains($styleSource, '@media (prefers-reduced-motion: reduce)'),
    'Progressive result insertion must have a subtle animation with a reduced-motion override.'
);
public_search_progressive_stage7_assert(
    str_contains($viewSource, 'aria-busy="false"')
        && str_contains($viewSource, 'role="region"')
        && str_contains($browserSource, "status.setAttribute('role', 'status')")
        && str_contains($browserSource, "root.setAttribute('aria-busy', searchPending ? 'true' : 'false')"),
    'Progressive search must expose stable busy/status accessibility state.'
);
public_search_progressive_stage7_assert(
    str_contains($modelsLoader, "'/models/public_search.php'")
        && str_contains($compatibilityModelSource, 'public_search_model_compatibility_gallery_rows')
        && str_contains($compatibilityModelSource, 'public_search_model_compatibility_image_rows'),
    'Legacy/no-phase public-search SQL must have an explicit model owner.'
);
public_search_progressive_stage7_assert(
    !str_contains($compatibilityServiceSource, 'db()->')
        && !str_contains($compatibilityServiceSource, '->prepare(')
        && !str_contains($compatibilityServiceSource, 'SELECT '),
    'Compatibility public-search service must remain free of SQL/PDO access.'
);
public_search_progressive_stage7_assert(
    str_contains($galleryEntrySource, '20260913-progressive-search-v4')
        && str_contains($publicGalleryEntrySource, '20260913-progressive-search-v4'),
    'Stage 7 browser module cache-busting revision must be synchronized across both public entrypoints.'
);

fwrite(STDOUT, "Progressive public-search Stage 7 checks passed.\n");
