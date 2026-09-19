<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_navigation_loading_regression_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects responsive lightbox and fullscreen navigation during repeated photo changes.
 *
 * Responsibilities:
 *   - Keep navigation feedback on the shared progress bar instead of the legacy spinner
 *   - Preserve already-running adjacent preview preloads across ordinary next/previous steps
 *   - Keep hard cancellation for close, teardown, and hidden-document cleanup
 *   - Debounce automatic full-quality promotion so rapid navigation does not churn downloads
 *   - Require localized navigation-loading copy in every supported public language
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

$root = dirname(__DIR__);
$lightboxSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$lightboxStyles = (string) file_get_contents($root . '/public/assets/styles/lightbox.css');

/**
 * Throw when a navigation-loading regression contract fails.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 */
function lightbox_navigation_loading_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

lightbox_navigation_loading_assert(
    str_contains($lightboxSource, 'function setLightboxNavigationLoading(loading)')
        && str_contains($lightboxSource, 'setLightboxNavigationLoading(true);')
        && str_contains($lightboxSource, 'setLightboxNavigationLoading(false);'),
    'Navigation loading must use one explicit shared progress-state helper.'
);
lightbox_navigation_loading_assert(
    !str_contains($lightboxStyles, 'lightbox-navigation-spinner')
        && !str_contains($lightboxStyles, '.lightbox.is-navigation-loading:not(.is-initial-loading) .lightbox-stage-link::after')
        && str_contains($lightboxStyles, '.lightbox.is-navigation-loading:not(.is-initial-loading) .lightbox-quality-progress')
        && str_contains($lightboxStyles, 'animation: lightbox-navigation-progress'),
    'Repeated navigation must use an indeterminate progress bar and must not restore the center spinner.'
);

$resetStart = strpos($lightboxSource, 'function resetLightboxPreloadQueue(options = {})');
$resetEnd = strpos($lightboxSource, 'function clearLightboxHiddenCleanupTimer()', $resetStart === false ? 0 : $resetStart);
lightbox_navigation_loading_assert($resetStart !== false && $resetEnd !== false, 'Preload reset helper with lifecycle options is missing.');
$resetSource = substr($lightboxSource, (int) $resetStart, (int) $resetEnd - (int) $resetStart);
lightbox_navigation_loading_assert(
    str_contains($resetSource, 'const abortActive = options.abortActive !== false;')
        && str_contains($resetSource, 'if (abortActive) {')
        && str_contains($resetSource, 'lightboxPreloadAbortController.abort();'),
    'Preload reset must distinguish queue invalidation from hard cancellation of active requests.'
);

$transactionStart = strpos($lightboxSource, 'function beginLightboxNavigationTransaction(index)');
$transactionEnd = strpos($lightboxSource, 'function finalizeLightboxNavigationTransaction(', $transactionStart === false ? 0 : $transactionStart);
lightbox_navigation_loading_assert($transactionStart !== false && $transactionEnd !== false, 'Lightbox navigation transaction helper is missing.');
$transactionSource = substr($lightboxSource, (int) $transactionStart, (int) $transactionEnd - (int) $transactionStart);
lightbox_navigation_loading_assert(
    str_contains($transactionSource, 'resetLightboxPreloadQueue({abortActive: false});'),
    'Ordinary photo navigation must preserve already-running adjacent preview preloads.'
);

$finalizeStart = strpos($lightboxSource, 'function finalizeLightboxNavigationTransaction(');
$finalizeEnd = strpos($lightboxSource, 'function readLightboxTimingSetting(', $finalizeStart === false ? 0 : $finalizeStart);
lightbox_navigation_loading_assert($finalizeStart !== false && $finalizeEnd !== false, 'Navigation terminal-state helper is missing.');
$finalizeSource = substr($lightboxSource, (int) $finalizeStart, (int) $finalizeEnd - (int) $finalizeStart);
lightbox_navigation_loading_assert(
    str_contains($finalizeSource, 'scheduleLightboxQualityUpgrade();')
        && !str_contains($finalizeSource, 'scheduleLightboxQualityUpgrade(0);'),
    'Automatic quality promotion must keep the configured debounce window during manual navigation.'
);
lightbox_navigation_loading_assert(
    str_contains($lightboxStyles, '.lightbox.is-navigation-error:not(.is-initial-loading) .lightbox-quality-progress'),
    'A terminal current-navigation failure must replace permanent busy state with recoverable feedback.'
);

foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $catalogPath = $root . '/app/lang/' . $language . '.json';
    $catalog = json_decode((string) file_get_contents($catalogPath), true);
    lightbox_navigation_loading_assert(
        is_array($catalog) && isset($catalog['lightbox.image_loading']) && trim((string) $catalog['lightbox.image_loading']) !== '',
        'Missing lightbox.image_loading translation in ' . $language . '.json.'
    );
}

fwrite(STDOUT, "Lightbox navigation loading regression checks passed.\n");
