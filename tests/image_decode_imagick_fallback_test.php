<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Exercise optional Imagick admission and GD fallback with tiny disposable images.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/image_decode_imagick_fallback_test.php
 * Module Type: Regression Test
 * Purpose: Verify retained-surface accounting and fallback without a second source decode.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Extreme values exist only in metadata; no live configuration, database or RAW delegate.
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Fixture override or central default.
     *
     * @param string $key Canonical runtime key.
     * @return int Fixture override or central default.
     */
    function cms_runtime_limit(string $key): int
    {
        $defaults = require __DIR__ . '/../app/configuration_defaults.php';
        return $GLOBALS['imagick_budget_limits'][$key] ?? $defaults['runtime_limits'][$key];
    }
}

namespace Gallery\Services {
    /**
     * Injected header or real bounded metadata.
     *
     * @param string $path Tiny disposable source.
     * @return array<array-key,mixed>|false Injected header or real bounded metadata.
     */
    function getimagesize(string $path): array|false
    {
        ++$GLOBALS['imagick_budget_metadata_reads'];
        return $GLOBALS['imagick_budget_metadata'] ?? \getimagesize($path);
    }
    /**
     * Controlled memory-limit observation only.
     *
     * @param string $name Observed PHP setting.
     * @return string|false Controlled memory-limit observation only.
     */
    function ini_get(string $name): string|false
    {
        return $name === 'memory_limit' ? $GLOBALS['imagick_budget_memory_limit'] : \ini_get($name);
    }
    /**
     * Small controlled existing-request allowance.
     *
     * @param bool $realUsage Allocator accounting selector.
     * @return int Small controlled existing-request allowance.
     */
    function memory_get_usage(bool $realUsage = false): int { return 16 * 1024 * 1024; }
    /**
     * Exercise the optional writer admission even without a native Imagick installation.
     *
     * @return bool Exercise the optional writer admission even without a native Imagick installation.
     */
    function thumbnail_imagick_webp_available(): bool { return true; }
    /**
     * Minimal EXIF presence for the real fallback selector.
     *
     * @param string $path Tiny fixture JPEG.
     * @param string|null $sections Requested sections.
     * @param bool $arrays Sectioned output.
     * @param bool $thumbnail Thumbnail output.
     * @return array{IFD0:array{Orientation:int}} Minimal EXIF presence for the real fallback selector.
     */
    function exif_read_data(string $path, ?string $sections = null, bool $arrays = false, bool $thumbnail = false): array
    {
        return ['IFD0' => ['Orientation' => 1]];
    }
    /**
     * No native decoder can be entered by this seam.
     *
     * @param string $path Original source that fallback must not decode again.
     * @return \GdImage|false No native decoder can be entered by this seam.
     */
    function imagecreatefromjpeg(string $path): \GdImage|false
    {
        ++$GLOBALS['imagick_budget_decoder_calls'];
        throw new \RuntimeException('Fallback attempted a second source decode.');
    }
}

namespace {
    require_once __DIR__ . '/../app/services/thumbnail_generation.php';

    if (!class_exists('Imagick')) {
        /** Constructor trap for installations without Imagick; never simulates codec success. */
        final class Imagick
        {
            /**
             * Record forbidden construction and stop safely.
             *
             * @param string $path Tiny source path, never used for allocation.
             * @return void Record forbidden construction and stop safely.
             */
            public function __construct(string $path)
            {
                ++$GLOBALS['imagick_budget_constructor_calls'];
                throw new RuntimeException('Unadmitted Imagick construction.');
            }
        }
    }

    /**
     * Stop without outputting source paths.
     *
     * @param bool $condition Required writer invariant.
     * @param string $message Safe failure explanation.
     * @return void Stop without outputting source paths.
     */
    function imagick_budget_expect(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    if (!extension_loaded('gd') || !function_exists('imagejpeg') || !function_exists('imagewebp') || !function_exists('exif_read_data')) {
        echo "SKIP: Imagick fallback fixture requires GD JPEG/WebP and EXIF for its real selector.\n";
        exit(0);
    }

    $root = sys_get_temp_dir() . '/gallery-imagick-budget-' . bin2hex(random_bytes(8));
    mkdir($root);
    $sourcePath = $root . '/source.jpg';
    $targetPath = $root . '/existing.webp';
    $fallbackPath = $root . '/fallback.webp';
    $source = imagecreatetruecolor(64, 48);
    imagefilledrectangle($source, 0, 0, 63, 47, imagecolorallocate($source, 70, 120, 190));
    imagejpeg($source, $sourcePath);
    file_put_contents($targetPath, 'existing derivative fixture');
    $originalHash = hash_file('sha256', $sourcePath);
    $targetHash = hash_file('sha256', $targetPath);
    $GLOBALS['imagick_budget_limits'] = [];
    $GLOBALS['imagick_budget_memory_limit'] = '512M';
    $GLOBALS['imagick_budget_constructor_calls'] = 0;
    $GLOBALS['imagick_budget_decoder_calls'] = 0;
    $GLOBALS['imagick_budget_metadata_reads'] = 0;

    try {
        foreach ([
            ['metadata' => [PHP_INT_MAX, PHP_INT_MAX, 'mime' => 'image/jpeg'], 'limit' => '512M', 'reason' => 'dimension_limit'],
            ['metadata' => [64, 48, 'mime' => 'image/jpeg'], 'limit' => 'unknown', 'reason' => 'memory_limit_unknown'],
            ['metadata' => [64, 48, 'mime' => 'image/jpeg'], 'limit' => '1M', 'reason' => 'memory_budget'],
        ] as $case) {
            $GLOBALS['imagick_budget_metadata'] = $case['metadata'];
            $GLOBALS['imagick_budget_memory_limit'] = $case['limit'];
            $admission = \Gallery\Services\image_decode_path_admission($sourcePath, 'image/jpeg', 32, true, 'imagick', true);
            imagick_budget_expect(!$admission['allowed'] && $admission['reason'] === $case['reason'], 'Imagick admission lost its bounded reason.');
            $reads = $GLOBALS['imagick_budget_metadata_reads'];
            imagick_budget_expect(!\Gallery\Services\write_resized_webp_with_imagick_exif($sourcePath, 32, $targetPath, true), 'Unadmitted standalone Imagick writer succeeded.');
            imagick_budget_expect($GLOBALS['imagick_budget_metadata_reads'] === $reads + 1, 'Standalone writer bypassed fresh shared admission.');
            imagick_budget_expect(is_file($targetPath) && hash_file('sha256', $targetPath) === $targetHash, 'Admission refusal touched an existing derivative.');
        }

        // Six million synthetic pixels fit Imagick alone but not its retained GD source.
        // The actual source and fallback surfaces remain 64x48 and 32x24 throughout.
        $GLOBALS['imagick_budget_metadata'] = [3000, 2000, 'mime' => 'image/jpeg'];
        $GLOBALS['imagick_budget_memory_limit'] = '-1';
        $GLOBALS['imagick_budget_limits'] = ['image_decode.max_memory_bytes' => 240 * 1024 * 1024];
        $alone = \Gallery\Services\image_decode_path_admission($sourcePath, 'image/jpeg', 32, true, 'imagick', false);
        $retained = \Gallery\Services\image_decode_path_admission($sourcePath, 'image/jpeg', 32, true, 'imagick', true);
        imagick_budget_expect($alone['allowed'] && !$retained['allowed'] && $retained['reason'] === 'memory_budget', 'Retained GD source was not charged to optional Imagick admission.');
        $reads = $GLOBALS['imagick_budget_metadata_reads'];
        $written = \Gallery\Services\write_resized_webp_preserving_exif_when_needed($sourcePath, $source, 64, 48, 32, $fallbackPath, 'image/jpeg', true);
        imagick_budget_expect($written && $GLOBALS['imagick_budget_metadata_reads'] === $reads + 1, 'Optional Imagick refusal did not reach the established GD fallback.');
        $geometry = getimagesize($fallbackPath);
        imagick_budget_expect($geometry[0] === 32 && $geometry[1] === 24, 'GD fallback changed the target geometry.');
        imagick_budget_expect($GLOBALS['imagick_budget_decoder_calls'] === 0 && $GLOBALS['imagick_budget_constructor_calls'] === 0, 'Rejected optional writer decoded the source again.');
        imagick_budget_expect(hash_file('sha256', $sourcePath) === $originalHash && hash_file('sha256', $targetPath) === $targetHash, 'Fallback changed an original or unrelated existing derivative.');
        echo "PASS optional Imagick admission, retained GD accounting and tiny native GD fallback.\n";
    } finally {
        imagedestroy($source);
        foreach ([$sourcePath, $targetPath, $fallbackPath] as $path) {
            if (is_file($path)) { unlink($path); }
        }
        rmdir($root);
    }
}
