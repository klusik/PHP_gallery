<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/image_decode_pipeline_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercise GD admission and thumbnail repair using disposable small images.
 *
 * Responsibilities:
 *   - Count decoder calls to prove extreme metadata is rejected before allocation
 *   - Verify refusal preserves source and existing cache bytes
 *   - Cover real small GD decodes and bounded simulated processing failures
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
 *   - All sources and targets are disposable; no application bootstrap/database.
 *   - Extreme dimensions are metadata overrides, never native image allocations.
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply central defaults and isolated test overrides without reading config.php.
     *
     * @param string $key Stable runtime-limit key.
     * @return int|float Canonical or fixture-specified numeric limit.
     */
    function cms_runtime_limit(string $key): int|float
    {
        $defaults = require __DIR__ . '/../app/configuration_defaults.php';
        return $GLOBALS['image_decode_fixture_limits'][$key] ?? $defaults['runtime_limits'][$key];
    }
}

namespace Gallery\Services {
    /**
     * Provide synthetic metadata only for explicitly selected disposable sources.
     *
     * @param string $path Disposable fixture source or thumbnail path.
     * @return array<mixed>|false Real metadata or a controlled header observation.
     */
    function getimagesize(string $path): array|false
    {
        if (array_key_exists($path, $GLOBALS['image_decode_fixture_metadata'] ?? [])) {
            return $GLOBALS['image_decode_fixture_metadata'][$path];
        }
        return \getimagesize($path);
    }

    /**
     * Count native PNG entry and simulate recoverable failure on a tiny source.
     *
     * @param string $path Disposable PNG source.
     * @return \GdImage|false Real decoded image or injected ordinary failure.
     */
    function imagecreatefrompng(string $path): \GdImage|false
    {
        $GLOBALS['image_decode_fixture_decoder_calls']++;
        if (!empty($GLOBALS['image_decode_fixture_forbid_decoder'])) {
            throw new \RuntimeException('The metadata fixture must never enter a decoder.');
        }
        if (($GLOBALS['image_decode_fixture_decoder_mode'] ?? '') === 'throw') {
            throw new \RuntimeException('/private/source.png?token=fixture-secret');
        }
        if (($GLOBALS['image_decode_fixture_decoder_mode'] ?? '') === 'false') {
            return false;
        }
        return \imagecreatefrompng($path);
    }

    /**
     * Verify translation routing without loading catalogs or live settings.
     *
     * @param string $key Translation key selected by the service.
     * @param string $fallback Safe English fallback.
     * @return string Visible sentinel and fallback text for assertions.
     */
    function t(string $key, string $fallback): string
    {
        return 'translated:' . $key . ':' . $fallback;
    }

    /**
     * Record the schema boundary while avoiding a live database.
     *
     * @param string $operation Stable thumbnail operation identifier.
     * @return void
     */
    function thumbnail_metadata_preflight_write_schema(string $operation): void
    {
        $GLOBALS['image_decode_fixture_schema_calls'][] = $operation;
    }

    /**
     * Resolve the current source exclusively inside the disposable fixture.
     *
     * @param array<string,mixed> $image Synthetic image identity.
     * @param array<string,mixed> $gallery Synthetic gallery identity.
     * @return string Current disposable source path.
     */
    function image_abs_path(array $image, array $gallery): string
    {
        return $GLOBALS['image_decode_fixture_source'];
    }

    /**
     * Keep the integration fixture on the ordinary raster path.
     *
     * @param array<string,mixed> $image Synthetic image identity.
     * @return bool Always false; separate RAW converters are outside this fixture.
     */
    function image_uses_dng_display_derivatives(array $image): bool
    {
        return false;
    }

    /**
     * Resolve/create only the disposable thumbnail directory.
     *
     * @param array<string,mixed> $gallery Synthetic gallery identity.
     * @param bool $create Whether creation is requested by the generator.
     * @return string Disposable directory path.
     */
    function gallery_thumbs_dir(array $gallery, bool $create = false): string
    {
        $path = $GLOBALS['image_decode_fixture_root'] . '/thumbs';
        if ($create && !is_dir($path)) {
            mkdir($path);
        }
        return $path;
    }

    /**
     * Keep generation deterministic without requiring optional WebP encoding.
     *
     * @param string $sourcePath Disposable source path.
     * @param string $mime Observed source format.
     * @return list<string> JPEG-only fixture targets.
     */
    function thumbnail_target_formats_for_source(string $sourcePath, string $mime): array
    {
        return ['jpg'];
    }

    /**
     * Supply a bounded two-size generation policy.
     *
     * @return list<int> Allowed fixture thumbnail sides.
     */
    function thumbnail_sizes(): array
    {
        return [32, 64];
    }

    /**
     * Omit optional WebP accounting in a JPEG-only generation fixture.
     *
     * @param string $sourcePath Disposable source path.
     * @param string $mime Observed source format.
     * @return int No intentionally skipped WebP targets.
     */
    function thumbnail_intentionally_skipped_webp_count(string $sourcePath, string $mime): int
    {
        return 0;
    }

    /**
     * Resolve one target exclusively within the disposable thumbnail directory.
     *
     * @param array<string,mixed> $image Synthetic image identity.
     * @param array<string,mixed> $gallery Synthetic gallery identity.
     * @param int $size Fixture thumbnail side.
     * @param string $format Target format extension.
     * @return string Disposable thumbnail filename.
     */
    function thumbnail_abs_path(array $image, array $gallery, int $size, string $format): string
    {
        return gallery_thumbs_dir($gallery) . '/' . $size . '.' . $format;
    }
}

namespace {
    use function Gallery\Services\create_image_thumbnails_result;
    use function Gallery\Services\image_create_from_path;
    use function Gallery\Services\image_decode_gd_path_result;
    use function Gallery\Services\thumbnail_ensure_image_thumbnail_variant_file;

    require_once __DIR__ . '/../app/services/thumbnail_generation.php';

    /**
     * Fail this fixture with an actionable contract diagnostic.
     *
     * @param bool $condition Whether the expected behavior occurred.
     * @param string $message Safe assertion description.
     * @return void
     */
    function image_decode_pipeline_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!extension_loaded('gd') || !function_exists('imagejpeg') || !function_exists('imagepng')) {
        echo "SKIP: Image decode pipeline fixture requires GD with JPEG/PNG support.\n";
        exit(0);
    }

    $root = sys_get_temp_dir() . '/gallery-image-decode-' . bin2hex(random_bytes(8));
    mkdir($root);
    $GLOBALS['image_decode_fixture_root'] = $root;
    $GLOBALS['image_decode_fixture_source'] = $root . '/source.png';
    $GLOBALS['image_decode_fixture_limits'] = [];
    $GLOBALS['image_decode_fixture_metadata'] = [];
    $GLOBALS['image_decode_fixture_decoder_calls'] = 0;
    $GLOBALS['image_decode_fixture_schema_calls'] = [];
    $GLOBALS['image_decode_fixture_forbid_decoder'] = false;
    $sourcePath = $GLOBALS['image_decode_fixture_source'];
    $source = imagecreatetruecolor(64, 48);
    imagefilledrectangle($source, 0, 0, 63, 47, imagecolorallocate($source, 30, 120, 180));
    imagepng($source, $sourcePath);
    imagedestroy($source);
    $sourceHash = hash_file('sha256', $sourcePath);
    $image = ['id' => 1, 'filename' => 'source.png'];
    $gallery = ['id' => 2];

    try {
        // Native decoding is forbidden even if an admission bug reaches this seam.
        $GLOBALS['image_decode_fixture_forbid_decoder'] = true;
        $GLOBALS['image_decode_fixture_metadata'][$sourcePath] = [PHP_INT_MAX, PHP_INT_MAX, 'mime' => 'image/png'];
        $extreme = image_decode_gd_path_result($sourcePath, 'image/png', 32);
        image_decode_pipeline_assert(!$extreme['image'] && $extreme['status']['reason'] === 'dimension_limit', 'Extreme source headers must be refused.');
        image_decode_pipeline_assert($GLOBALS['image_decode_fixture_decoder_calls'] === 0, 'Extreme dimensions reached the PNG decoder.');
        image_decode_pipeline_assert(image_create_from_path($sourcePath, 'image/png') === false, 'Legacy decoder callers must share admission.');
        $publicRepair = thumbnail_ensure_image_thumbnail_variant_file($image, $gallery, 32, 'jpg');
        image_decode_pipeline_assert($publicRepair === null && $GLOBALS['image_decode_fixture_decoder_calls'] === 0, 'Public cache repair must refuse before native allocation.');
        image_decode_pipeline_assert(!is_dir($root . '/thumbs'), 'Refused repair must not prepare new derivative storage.');

        $GLOBALS['image_decode_fixture_metadata'] = [];
        $GLOBALS['image_decode_fixture_limits'] = ['image_decode.max_memory_bytes' => 1];
        $lowMemory = create_image_thumbnails_result($image, $gallery);
        image_decode_pipeline_assert($lowMemory['failed'] === 2 && $lowMemory['decode_status']['reason'] === 'memory_budget', 'Every requested variant must be counted on memory refusal.');
        image_decode_pipeline_assert(str_contains($lowMemory['message'], 'translated:thumbnail.decode.memory_budget'), 'Decode refusal must use the translation boundary.');
        image_decode_pipeline_assert($GLOBALS['image_decode_fixture_decoder_calls'] === 0, 'Low memory reached a native decoder.');

        $GLOBALS['image_decode_fixture_metadata'][$sourcePath] = false;
        $unknown = create_image_thumbnails_result($image, $gallery);
        image_decode_pipeline_assert($unknown['failed'] > 0 && $unknown['decode_status']['reason'] === 'metadata_unavailable', 'Unreadable image metadata must not look like successful zero work.');
        $GLOBALS['image_decode_fixture_metadata'] = [];
        $GLOBALS['image_decode_fixture_limits'] = [];
        $GLOBALS['image_decode_fixture_forbid_decoder'] = false;

        foreach (['throw', 'false'] as $mode) {
            $GLOBALS['image_decode_fixture_decoder_mode'] = $mode;
            $failed = create_image_thumbnails_result($image, $gallery, [32]);
            image_decode_pipeline_assert($failed['failed'] === 1 && $failed['decode_status']['reason'] === 'processing_failed', 'Recoverable decoder failures must have their own bounded status.');
            image_decode_pipeline_assert($failed['errors'] === ['source_decode_failed'], 'Ordinary decode failure must retain its historical machine error.');
            image_decode_pipeline_assert(!str_contains(json_encode($failed), 'fixture-secret') && !str_contains(json_encode($failed), '/private/'), 'Native exceptions must not leak into thumbnail diagnostics.');
        }
        $GLOBALS['image_decode_fixture_decoder_mode'] = '';
        $generated = create_image_thumbnails_result($image, $gallery, [32]);
        image_decode_pipeline_assert($generated['created'] === 1 && $generated['failed'] === 0, 'A real small PNG must generate a JPEG thumbnail.');
        $target = $root . '/thumbs/32.jpg';
        $geometry = getimagesize($target);
        image_decode_pipeline_assert($geometry[0] === 32 && $geometry[1] === 24, 'Admitted generation must retain source aspect ratio.');
        image_decode_pipeline_assert($GLOBALS['image_decode_fixture_schema_calls'] !== [], 'Generation must preserve the existing schema preflight.');

        $callsBeforeHit = $GLOBALS['image_decode_fixture_decoder_calls'];
        $GLOBALS['image_decode_fixture_limits'] = ['image_decode.max_memory_bytes' => 1];
        $cached = create_image_thumbnails_result($image, $gallery, [32]);
        image_decode_pipeline_assert($cached['skipped'] === 1 && $cached['failed'] === 0, 'A valid cached thumbnail must remain usable below the decode budget.');
        image_decode_pipeline_assert($GLOBALS['image_decode_fixture_decoder_calls'] === $callsBeforeHit, 'A cache hit must not decode the original.');

        // Present an invalid-ratio cache file and ensure refusal cannot remove it.
        $square = imagecreatetruecolor(8, 8);
        imagejpeg($square, $target);
        imagedestroy($square);
        touch($target, (int) filemtime($sourcePath) + 10);
        $targetHash = hash_file('sha256', $target);
        $blockedRepair = create_image_thumbnails_result($image, $gallery, [32]);
        image_decode_pipeline_assert($blockedRepair['failed'] === 1 && $blockedRepair['invalid_geometry_deleted'] === 0, 'Refused generation must defer invalid-geometry cleanup.');
        image_decode_pipeline_assert(hash_file('sha256', $target) === $targetHash, 'Refusal altered an existing derivative.');
        image_decode_pipeline_assert(hash_file('sha256', $sourcePath) === $sourceHash, 'Refusal or decode failure altered the accepted original.');
        $GLOBALS['image_decode_fixture_limits'] = [];
        unlink($target);
        $repaired = thumbnail_ensure_image_thumbnail_variant_file($image, $gallery, 32, 'jpg');
        image_decode_pipeline_assert(is_array($repaired) && is_file($repaired['path']), 'Public repair should succeed again after memory policy admits it.');

        // Ordinary supported codecs are exercised with 64x48 disposable sources only.
        $smallSource = imagecreatetruecolor(64, 48);
        foreach (['jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'] as $format => $mime) {
            $writer = 'image' . $format;
            $decoder = 'imagecreatefrom' . $format;
            if (!function_exists($writer) || !function_exists($decoder)) {
                continue;
            }
            $path = $root . '/codec.' . $format;
            image_decode_pipeline_assert($writer($smallSource, $path), 'Could not create small codec fixture.');
            $decoded = image_create_from_path($path, $mime);
            image_decode_pipeline_assert($decoded instanceof GdImage && imagesx($decoded) === 64 && imagesy($decoded) === 48, 'An admitted ordinary codec failed: ' . $format);
            imagedestroy($decoded);
        }
        imagedestroy($smallSource);

        // A real malformed, tiny PNG keeps metadata readable but fails normal decoding.
        $broken = $root . '/broken.png';
        file_put_contents($broken, substr(file_get_contents($sourcePath), 0, 33));
        $brokenResult = image_decode_gd_path_result($broken, 'image/png', 32, false);
        image_decode_pipeline_assert($brokenResult['image'] === false && $brokenResult['status']['reason'] === 'processing_failed', 'Malformed image data must return a bounded processing failure.');

        // These are source call-chain checks, not HTTP/browser/database acceptance.
        foreach (['admin_uploads.php', 'upload_automation.php'] as $controller) {
            $controllerSource = file_get_contents(__DIR__ . '/../app/controllers/' . $controller);
            image_decode_pipeline_assert(str_contains($controllerSource, 'create_image_thumbnails_result($image, $gallery)'), 'Upload fallback must retain the shared thumbnail boundary: ' . $controller);
            image_decode_pipeline_assert(!preg_match('/imagecreatefrom(?:jpeg|png|gif|webp)\s*\(/', $controllerSource), 'An upload controller bypasses the shared decoder.');
        }
        echo "Image decode pipeline tests passed (small native GD fixtures, injected metadata, public repair, upload call-chain checks).\n";
    } finally {
        // Only files created in this unique disposable directory can be removed.
        foreach ([$root . '/thumbs', $root] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (glob($directory . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }
}
