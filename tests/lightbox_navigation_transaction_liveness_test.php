<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_navigation_transaction_liveness_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects lightbox navigation transaction ownership against permanent busy-state stalls.
 *
 * Responsibilities:
 *   - Require navigation ownership before sparse metadata work begins
 *   - Carry the same navigation token across metadata hydration
 *   - Require terminal cleanup for successful, false, rejected, and metadata-failed presentations
 *   - Keep stale presentation geometry from mutating the current image
 *   - Require owner-checked quality finalization and progress updates
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
$source = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');

/**
 * Throw when a navigation transaction liveness contract fails.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 */
function lightbox_navigation_transaction_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Extract a JavaScript function up to the next named function.
 *
 * @param string $source JavaScript source.
 * @param string $name Function name to locate.
 * @param string $nextName Next function name used as the slice boundary.
 * @return string Function source or an empty string.
 */
function lightbox_navigation_transaction_function(string $source, string $name, string $nextName): string
{
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $end = strpos($source, 'function ' . $nextName . '(', $start + 1);
    return $end === false ? '' : substr($source, $start, $end - $start);
}

$beginSource = lightbox_navigation_transaction_function(
    $source,
    'beginLightboxNavigationTransaction',
    'finalizeLightboxNavigationTransaction'
);
lightbox_navigation_transaction_assert($beginSource !== '', 'Navigation transaction begin helper is missing.');
foreach ([
    'activeLightboxImageToken += 1;',
    'clearLightboxNavigationPending();',
    'clearLightboxNavigationFailure();',
    'clearPendingLightboxQualityUpgrade();',
    'activeLightboxTransitionToken += 1;',
    'removeTransitionImage();',
] as $required) {
    lightbox_navigation_transaction_assert(
        str_contains($beginSource, $required),
        'Navigation intent must retire previous ownership before metadata/image work: ' . $required
    );
}

$openSource = lightbox_navigation_transaction_function($source, 'openAt', 'step');
lightbox_navigation_transaction_assert($openSource !== '', 'openAt() source is missing.');
$beginPosition = strpos($openSource, 'beginLightboxNavigationTransaction(normalizedIndex)');
$missingCardPosition = strpos($openSource, 'if (!card) {');
lightbox_navigation_transaction_assert(
    $beginPosition !== false && $missingCardPosition !== false && $beginPosition < $missingCardPosition,
    'Every navigation intent must own a transaction before the sparse-card metadata branch.'
);
lightbox_navigation_transaction_assert(
    str_contains($openSource, 'navigationIntentToken: imageToken')
        && str_contains($openSource, 'isCurrentLightboxImageRequest(normalizedIndex, requestedResumeToken)'),
    'Sparse metadata resume must carry and validate the original navigation token.'
);
lightbox_navigation_transaction_assert(
    str_contains($openSource, "finalizeLightboxNavigationTransaction(normalizedIndex, imageToken, false, 'metadata')")
        && str_contains($openSource, "finalizeLightboxNavigationTransaction(normalizedIndex, imageToken, false, 'metadata-rejected')")
        && str_contains($openSource, '.then((wasDisplayed) => finalizeLightboxNavigationTransaction(')
        && str_contains($openSource, "'presentation-rejected'"),
    'Metadata and presentation outcomes must all terminate through the owner-checked finalizer.'
);

$finalizeSource = lightbox_navigation_transaction_function(
    $source,
    'finalizeLightboxNavigationTransaction',
    'readLightboxTimingSetting'
);
lightbox_navigation_transaction_assert($finalizeSource !== '', 'Navigation terminal finalizer is missing.');
lightbox_navigation_transaction_assert(
    str_contains($finalizeSource, 'if (!isCurrentLightboxImageRequest(index, token))')
        && str_contains($finalizeSource, 'clearLightboxNavigationPending(token);')
        && str_contains($finalizeSource, 'hideInitialLightboxLoader();')
        && str_contains($finalizeSource, 'showLightboxNavigationFailure(index, token, failureReason);'),
    'Only the current navigation owner may leave busy state or publish a terminal failure.'
);

$showSource = lightbox_navigation_transaction_function($source, 'showLightboxImageSource', 'lightboxQualityCandidatesForCard');
lightbox_navigation_transaction_assert($showSource !== '', 'showLightboxImageSource() source is missing.');
$currentGuardPosition = strpos($showSource, 'if (!isCurrentLightboxImageRequest(index, token))');
$geometryPosition = strpos($showSource, 'const targetMetrics = prepareDecodedLightboxGeometry(decodedImage);');
lightbox_navigation_transaction_assert(
    $currentGuardPosition !== false && $geometryPosition !== false && $currentGuardPosition < $geometryPosition,
    'Stale decoded images must be rejected before they can mutate current presentation geometry.'
);

lightbox_navigation_transaction_assert(
    str_contains($source, 'function ownsLightboxQualityRequest(')
        && str_contains($source, 'function finalizeLightboxQualityRequest(')
        && str_contains($source, 'function setOwnedLightboxQualityProgress('),
    'Quality promotion must use explicit request ownership for cleanup and progress.'
);
lightbox_navigation_transaction_assert(
    substr_count($source, 'finalizeLightboxQualityRequest(qualityToken, qualityAbortController') >= 6,
    'Both passive and explicit quality paths must finalize all current settled outcomes through the owner helper.'
);
lightbox_navigation_transaction_assert(
    substr_count($source, 'onProgress: (loadedBytes, totalBytes) => setOwnedLightboxQualityProgress(') >= 2,
    'Byte progress must not be written by stale quality requests.'
);

lightbox_navigation_transaction_assert(
    str_contains($source, 'navigationStage')
        && str_contains($source, 'navigationFailure')
        && str_contains($source, '`nav t${galleryDevModeState.navigationToken} target ${galleryDevModeState.navigationTarget'),
    'DEV diagnostics must expose active navigation ownership and terminal stage separately from historical source readiness.'
);

fwrite(STDOUT, "Lightbox navigation transaction liveness checks passed.\n");
