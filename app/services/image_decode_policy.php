<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/image_decode_policy.php
 * Module Type: Service
 *
 * Purpose:
 *   Refuse unsafe raster decoding before native image surfaces are allocated.
 *
 * Responsibilities:
 *   - Own overflow-safe image dimensions and conservative memory admission
 *   - Preserve a bounded policy even when PHP has no memory limit
 *   - Return safe failure reasons without paths or native exception messages
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
 *   - Loaded explicitly by thumbnail_generation.php, including standalone callers.
 *   - Estimates are admission policy, not a native allocator or process sandbox.
 */

declare(strict_types=1);

namespace Gallery\Services;

use GdImage;
use Throwable;
use function Gallery\Core\cms_runtime_limit;

use const Gallery\Core\IMAGE_DECODE_GD_BYTES_PER_PIXEL;
use const Gallery\Core\IMAGE_DECODE_IMAGICK_BYTES_PER_PIXEL;
use const Gallery\Core\IMAGE_DECODE_COMPRESSED_COPY_COUNT;
use const Gallery\Core\IMAGE_DECODE_FIXED_OVERHEAD_BYTES;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * Resolve positive integer policy values from the canonical runtime defaults.
 *
 * Standalone thumbnail consumers need no bootstrap or live configuration file.
 * Explicit overrides are a pure-policy test seam, never request input.
 *
 * @param array<string,mixed>|null $overrides Optional isolated policy overrides.
 * @return array<string,int> Validated image policy values keyed without the prefix.
 */
function image_decode_limits(?array $overrides = null): array
{
    static $defaults = null;
    if ($defaults === null) {
        $configuration = require dirname(__DIR__) . '/configuration_defaults.php';
        $defaults = [];
        foreach ($configuration['runtime_limits'] as $key => $value) {
            if (str_starts_with($key, 'image_decode.')) {
                $defaults[substr($key, strlen('image_decode.'))] = $value;
            }
        }
    }
    $limits = [];
    foreach ($defaults as $key => $default) {
        $value = $overrides !== null
            ? ($overrides[$key] ?? $default)
            : (function_exists('Gallery\\Core\\cms_runtime_limit') ? cms_runtime_limit('image_decode.' . $key) : $default);
        $limits[$key] = is_int($value) && $value > 0 ? $value : $default;
    }
    return $limits;
}

/**
 * Parse PHP's ordinary byte/K/M/G limit without float conversion or overflow.
 *
 * @param string|false $value Observed memory_limit value; false means unavailable.
 * @return int|null Positive byte limit, -1 for unlimited, or null for unknown/invalid.
 */
function image_decode_memory_limit_bytes(string|false $value): ?int
{
    if ($value === false) {
        return null;
    }
    $value = trim($value);
    if ($value === '-1') {
        return -1;
    }
    if (!preg_match('/^\+?([0-9]+)([KMG]?)$/iD', $value, $matches)) {
        return null;
    }
    $digits = ltrim($matches[1], '0');
    $maximum = (string) PHP_INT_MAX;
    if ($digits === '' || strlen($digits) > strlen($maximum)
        || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
        return null;
    }
    $multiplier = match (strtoupper($matches[2])) {
        'K' => 1024,
        'M' => 1024 * 1024,
        'G' => 1024 * 1024 * 1024,
        default => 1,
    };
    $bytes = (int) $digits;
    return $bytes <= intdiv(PHP_INT_MAX, $multiplier) ? $bytes * $multiplier : null;
}

/**
 * Multiply nonnegative quantities while keeping overflow out of PHP floats.
 *
 * @param int $left First nonnegative factor.
 * @param int $right Second nonnegative factor.
 * @return int|null Exact product, or null for invalid/overflowing input.
 */
function image_decode_product(int $left, int $right): ?int
{
    if ($left < 0 || $right < 0 || ($right !== 0 && $left > intdiv(PHP_INT_MAX, $right))) {
        return null;
    }
    return $left * $right;
}

/**
 * Build a closed, translatable decode status with no source or exception details.
 *
 * @param string $reason Internal reason from the policy's closed vocabulary.
 * @return array{allowed:bool,reason:string,message:string} Safe status for callers.
 */
function image_decode_status(string $reason): array
{
    $fallback = match ($reason) {
        'allowed' => '',
        'unsupported_format' => 'This image format cannot be decoded by the server.',
        'metadata_unavailable', 'invalid_dimensions' => 'Image dimensions could not be verified. Thumbnail generation was deferred.',
        'dimension_limit', 'pixel_limit', 'arithmetic_overflow', 'memory_budget' => 'This image exceeds the safe server image-processing budget. Thumbnail generation was deferred.',
        'memory_limit_unknown' => 'The server image-processing memory budget could not be verified. Thumbnail generation was deferred.',
        default => 'Image processing failed. The original image was retained; thumbnail generation can be retried.',
    };
    if (!in_array($reason, ['allowed', 'unsupported_format', 'metadata_unavailable', 'invalid_dimensions', 'dimension_limit', 'pixel_limit', 'arithmetic_overflow', 'memory_budget', 'memory_limit_unknown', 'processing_failed'], true)) {
        $reason = 'processing_failed';
    }
    return [
        'allowed' => $reason === 'allowed',
        'reason' => $reason,
        'message' => $fallback !== '' && function_exists('Gallery\\Services\\t')
            ? t('thumbnail.decode.' . $reason, $fallback)
            : $fallback,
    ];
}

/**
 * Decide admission from metadata without allocating or reading image pixels.
 *
 * The estimate includes source/codec work, an optional full rotation buffer,
 * one largest target (writers run sequentially), two compressed-input copies,
 * fixed overhead, and any GD source retained by the optional Imagick writer.
 *
 * @param array<mixed>|null $metadata getimagesize shape with optional source_bytes.
 * @param int|null $targetMaxSide Largest requested output side; null reserves a full-size target.
 * @param bool $mayRotate Whether the caller can allocate a second full-size source.
 * @param string|false $memoryLimit Observed PHP memory_limit, passed explicitly for testability.
 * @param int $memoryUsage Current allocated PHP memory, in bytes.
 * @param array<string,mixed>|null $limits Optional pure-policy overrides.
 * @param string $backend Native backend, gd or imagick.
 * @param bool $retainGdSource Whether Imagick runs while a GD source remains alive.
 * @return array<string,mixed> Safe decision and bounded numeric admission evidence.
 */
function image_decode_admission(?array $metadata, ?int $targetMaxSide, bool $mayRotate, string|false $memoryLimit, int $memoryUsage, ?array $limits = null, string $backend = 'gd', bool $retainGdSource = false): array
{
    if ($metadata === null || !isset($metadata[0], $metadata[1], $metadata['mime'])) {
        return image_decode_status('metadata_unavailable');
    }
    if (!in_array($metadata['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
        || !in_array($backend, ['gd', 'imagick'], true)) {
        return image_decode_status('unsupported_format');
    }
    $width = $metadata[0];
    $height = $metadata[1];
    $sourceBytes = $metadata['source_bytes'] ?? 0;
    if (!is_int($width) || !is_int($height) || $width <= 0 || $height <= 0
        || !is_int($sourceBytes) || $sourceBytes < 0 || ($targetMaxSide !== null && $targetMaxSide <= 0)) {
        return image_decode_status('invalid_dimensions');
    }
    $limits = image_decode_limits($limits);
    if ($width > $limits['max_dimension'] || $height > $limits['max_dimension']) {
        return image_decode_status('dimension_limit');
    }
    $pixels = image_decode_product($width, $height);
    if ($pixels === null) {
        return image_decode_status('arithmetic_overflow');
    }
    if ($pixels > $limits['max_pixels']) {
        return image_decode_status('pixel_limit');
    }
    // ceil intentionally reserves at least the rounded dimensions used by GD/Imagick.
    $scale = $targetMaxSide === null ? 1.0 : min(1.0, $targetMaxSide / max($width, $height));
    $targetWidth = $scale === 1.0 ? $width : max(1, (int) ceil($width * $scale));
    $targetHeight = $scale === 1.0 ? $height : max(1, (int) ceil($height * $scale));
    $targetPixels = image_decode_product($targetWidth, $targetHeight);
    $bytesPerPixel = $backend === 'gd' ? IMAGE_DECODE_GD_BYTES_PER_PIXEL : IMAGE_DECODE_IMAGICK_BYTES_PER_PIXEL;
    $parts = [
        image_decode_product($pixels, $bytesPerPixel),
        $mayRotate ? image_decode_product($pixels, $bytesPerPixel) : 0,
        $targetPixels !== null ? image_decode_product($targetPixels, $bytesPerPixel) : null,
        image_decode_product($sourceBytes, IMAGE_DECODE_COMPRESSED_COPY_COUNT),
        $retainGdSource ? image_decode_product($pixels, IMAGE_DECODE_GD_BYTES_PER_PIXEL) : 0,
        IMAGE_DECODE_FIXED_OVERHEAD_BYTES,
    ];
    $estimated = 0;
    foreach ($parts as $part) {
        if ($part === null || $part > PHP_INT_MAX - $estimated) {
            return image_decode_status('arithmetic_overflow');
        }
        $estimated += $part;
    }
    $phpLimit = image_decode_memory_limit_bytes($memoryLimit);
    if ($phpLimit === null || $memoryUsage < 0) {
        return image_decode_status('memory_limit_unknown');
    }
    $ceiling = $phpLimit === -1 ? $limits['max_memory_bytes'] : min($phpLimit, $limits['max_memory_bytes']);
    $available = max(0, $ceiling - min($ceiling, $memoryUsage));
    $available = max(0, $available - min($available, $limits['memory_reserve_bytes']));
    return image_decode_status($estimated <= $available ? 'allowed' : 'memory_budget') + [
        'width' => $width,
        'height' => $height,
        'pixels' => $pixels,
        'estimated_bytes' => $estimated,
        'available_bytes' => $available,
        'memory_ceiling_bytes' => $ceiling,
        'backend' => $backend,
    ];
}

/**
 * Inspect a local source immediately before native decode admission.
 *
 * @param string $path Already-authorized local source; authorization remains with the caller.
 * @param string $mime Expected image MIME type, checked against observed metadata.
 * @param int|null $targetMaxSide Largest target side, or null for a full-size buffer.
 * @param bool $mayRotate Whether a full-size orientation/intermediate buffer is needed.
 * @param string $backend Native backend whose working estimate applies.
 * @param bool $retainGdSource Whether an earlier GD decode remains live.
 * @return array<string,mixed> Safe admission evidence without the local path.
 */
function image_decode_path_admission(string $path, string $mime, ?int $targetMaxSide = null, bool $mayRotate = true, string $backend = 'gd', bool $retainGdSource = false): array
{
    try {
        // A prior request-local stat must not understate a replaced source's packed bytes.
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return image_decode_status('metadata_unavailable');
        }
        $metadata = @getimagesize($path);
        $sourceBytes = @filesize($path);
        if (!is_array($metadata) || $sourceBytes === false) {
            return image_decode_status('metadata_unavailable');
        }
        if (($metadata['mime'] ?? '') !== $mime) {
            return image_decode_status('unsupported_format');
        }
        $metadata['source_bytes'] = $sourceBytes;
        return image_decode_admission($metadata, $targetMaxSide, $mayRotate, ini_get('memory_limit'), memory_get_usage(true), null, $backend, $retainGdSource);
    } catch (Throwable) {
        return image_decode_status('metadata_unavailable');
    }
}

/**
 * Decode an admitted GD source and contain ordinary native processing failures.
 *
 * @param string $path Already-authorized source path.
 * @param string $mime Expected source MIME type.
 * @param int|null $targetMaxSide Largest output side; omitted legacy callers reserve a full-size target.
 * @param bool $mayRotate Whether to reserve an additional full-size source surface.
 * @return array{image:GdImage|false,status:array<string,mixed>} Decoded source or bounded failure.
 */
function image_decode_gd_path_result(string $path, string $mime, ?int $targetMaxSide = null, bool $mayRotate = true): array
{
    $status = image_decode_path_admission($path, $mime, $targetMaxSide, $mayRotate);
    if (!$status['allowed']) {
        return ['image' => false, 'status' => $status];
    }
    $decoder = match ($mime) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/gif' => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
        default => '',
    };
    if ($decoder === '' || !function_exists($decoder)) {
        return ['image' => false, 'status' => image_decode_status('unsupported_format')];
    }
    try {
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
        };
        if ($source instanceof GdImage) {
            if (imagesx($source) === $status['width'] && imagesy($source) === $status['height']) {
                return ['image' => $source, 'status' => $status];
            }
            imagedestroy($source);
        }
    } catch (Throwable) {
        // Native OOM/worker termination cannot be caught; admission is preventive.
    }
    return ['image' => false, 'status' => image_decode_status('processing_failed')];
}
