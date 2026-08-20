<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_benchmark_media_diagnostics_test.php
 * Module Type: Test
 *
 * Purpose:
 *   Protects the benchmark v4.2 layered diagnostics contract.
 *
 * Responsibilities:
 *   - Keep PHP and browser diagnostics versions synchronized
 *   - Require static-vs-PHP probes without wall-clock synchronization
 *   - Require benchmark runtime failures to surface instead of timing out silently
 *   - Preserve PHP-backed media sidecars and lightbox cleanup diagnostics
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/services/gallery_benchmark.php');
$mediaController = file_get_contents($root . '/app/controllers/public_media.php');
$adminController = file_get_contents($root . '/app/controllers/admin_gallery_benchmark.php');
$profiler = file_get_contents($root . '/app/services/public_render_profiler.php');
$dispatch = file_get_contents($root . '/app/bootstrap/dispatch.php');
$adminJs = file_get_contents($root . '/public/assets/gallery-modules/admin-gallery-benchmark.js');
$lightboxJs = file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$galleryJs = file_get_contents($root . '/public/assets/gallery.js');
$staticProbe = file_get_contents($root . '/public/assets/gallery-benchmark-static-probe.txt');

foreach ([$service, $mediaController, $adminController, $profiler, $dispatch, $adminJs, $lightboxJs, $galleryJs, $staticProbe] as $source) {
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "Benchmark v4.2 diagnostics source file could not be read.\n");
        exit(1);
    }
}

$assertions = [
    [str_contains($service, "return '20260820-benchmark-diagnostics-v4.2';"), 'PHP benchmark diagnostics version must be v4.2.'],
    [str_contains($service, "'schema_version' => 4"), 'Benchmark schema version must be 4.'],
    [str_contains($service, 'gallery_benchmark_media_sidecar_directory'), 'Media diagnostics must keep independent sidecar storage.'],
    [str_contains($service, 'outside_php_before_stream_estimate_ms'), 'Media correlation must use duration-difference outside-PHP estimates.'],
    [!str_contains($service, 'server_minus_browser_clock_ms'), 'V4 must not depend on synchronized browser/server clocks.'],
    [str_contains($mediaController, "gallery_benchmark_media_request_begin('public_thumb'"), 'Clean public thumbnails must remain traceable.'],
    [str_contains($mediaController, "gallery_benchmark_media_request_begin('public_media'"), 'Clean public media must remain traceable.'],
    [str_contains($adminController, 'function admin_gallery_benchmark_release_session_lock(): void'), 'Benchmark admin routes must be able to release their session lock.'],
    [str_contains($adminController, 'function cms_admin_gallery_benchmark_probe(): void'), 'Lightweight PHP layer probe endpoint must exist.'],
    [substr_count($adminController, 'admin_gallery_benchmark_release_session_lock();') >= 5, 'Heavy benchmark routes and the probe must release the admin session lock.'],
    [str_contains($dispatch, "'admin_gallery_benchmark_probe'"), 'PHP layer probe route must be dispatchable.'],
    [str_contains($profiler, 'data-php-probe-url=') && str_contains($profiler, 'data-static-probe-url='), 'Benchmark panel must expose both layer probe URLs.'],
    [str_contains($profiler, 'use function Gallery\\Core\\asset_url;'), 'Benchmark panel must import the Gallery Core asset_url helper explicitly.'],
    [str_contains($profiler, "asset_url('assets/gallery-benchmark-static-probe.txt')"), 'Benchmark static probe URL must use the imported Gallery Core asset_url helper.'],
    [!str_contains($profiler, '\\asset_url('), 'Benchmark panel must not call a nonexistent global asset_url helper.'],
    [str_contains($adminJs, "const BENCHMARK_DIAGNOSTICS_VERSION = '20260820-benchmark-diagnostics-v4.2';"), 'Browser benchmark diagnostics version must be v4.2.'],
    [str_contains($adminJs, 'runBenchmarkLayerProbePair'), 'Static-vs-PHP layer probes must be collected.'],
    [str_contains($adminJs, 'outside_php_estimate_ms'), 'PHP layer probe must derive outside-PHP time without clock synchronization.'],
    [!str_contains($adminJs, 'calibrateBenchmarkClock'), 'Unreliable wall-clock calibration must be absent in v4.'],
    [str_contains($adminJs, "targetWindow.addEventListener('error', onWindowError)"), 'Iframe runtime errors must be captured.'],
    [str_contains($adminJs, "targetWindow.addEventListener('unhandledrejection', onUnhandledRejection)"), 'Iframe unhandled rejections must be captured.'],
    [str_contains($adminJs, "async function runBenchmarkLightboxScenario") && str_contains($adminJs, "    let runtimeWindow = null;\n    let runtimeErrorHandler = null;\n    let runtimeRejectionHandler = null;\n    try {\n        const targetWindow = frame.contentWindow;\n        runtimeWindow = targetWindow;"), 'Lightbox runtime cleanup variables must be declared in the scenario function scope.'],
    [str_contains($adminJs, "first_lightbox_image_did_not_load"), 'The benchmark must fail fast when the first lightbox image does not load.'],
    [str_contains($lightboxJs, 'function benchmarkSourceLabel(src)'), 'Benchmark image source labeling helper must be defined before runtime use.'],
    [str_contains($lightboxJs, 'decoded_cache_insert_after_close'), 'Late decoded-cache reinsertion diagnostics must remain observable.'],
    [str_contains($lightboxJs, 'image_load_abort'), 'Detached image-load abort diagnostics must remain observable.'],
    [str_contains($galleryJs, 'admin-gallery-benchmark.js?v=20260820-benchmark-diagnostics-v4.2'), 'Gallery module import must cache-bust benchmark v4.2.'],
    [str_contains($staticProbe, 'benchmark static probe v4.2'), 'Tiny static probe asset must identify v4.2.'],
];

foreach ($assertions as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "Gallery benchmark v4.2 layered diagnostics contract passed.\n");
