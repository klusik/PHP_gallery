<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/image_decode_policy_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Verify decoder admission arithmetic without allocating native image surfaces.
 *
 * Responsibilities:
 *   - Cover hostile metadata, rotation, finite/unlimited/unknown memory limits
 *   - Check realistic large-photo admission and bounded configuration fallbacks
 *   - Avoid application bootstrap, live configuration, storage, or database access
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

require_once __DIR__ . '/../app/services/image_decode_policy.php';

use function Gallery\Services\image_decode_admission;
use function Gallery\Services\image_decode_limits;
use function Gallery\Services\image_decode_memory_limit_bytes;
use function Gallery\Services\image_decode_product;
use function Gallery\Services\image_decode_status;

/**
 * Fail the isolated admission contract with a concrete diagnostic.
 *
 * @param bool $condition Whether the expected behavior occurred.
 * @param string $message Safe assertion diagnostic.
 * @return void
 */
function image_decode_policy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$defaults = image_decode_limits([]);
$small = [64, 48, 'mime' => 'image/png', 'source_bytes' => 4096];
$photo = [6000, 4000, 'mime' => 'image/jpeg', 'source_bytes' => 8 * 1024 * 1024];
$usage = 16 * 1024 * 1024;

foreach (['128M' => 134217728, ' 64m ' => 67108864, '1024K' => 1048576, '+1G' => 1073741824, '4096' => 4096, '-1' => -1] as $input => $expected) {
    image_decode_policy_assert(image_decode_memory_limit_bytes((string) $input) === $expected, 'Memory parser rejected ' . $input);
}
foreach ([false, '', '0', '-2', '1.5G', '128MB', 'nonsense', str_repeat('9', 40) . 'G', (string) PHP_INT_MAX . 'G'] as $input) {
    image_decode_policy_assert(image_decode_memory_limit_bytes($input) === null, 'Unknown/overflowing memory limit must not become unlimited.');
}

image_decode_policy_assert(image_decode_product(PHP_INT_MAX, 2) === null, 'Pixel multiplication must reject overflow before multiplication.');
image_decode_policy_assert(image_decode_product(PHP_INT_MAX, 0) === 0, 'Zero multiplication must remain exact.');
$ordinary = image_decode_admission($photo, 1600, false, '512M', $usage);
$rotated = image_decode_admission($photo, 1600, true, '512M', $usage);
image_decode_policy_assert($ordinary['allowed'] && $rotated['allowed'], 'A representative 24 MP JPEG must fit both ordinary and rotated 512 MiB admission.');
image_decode_policy_assert($rotated['estimated_bytes'] - $ordinary['estimated_bytes'] === 24000000 * 8, 'Rotation must reserve another entire conservative source surface.');
image_decode_policy_assert(image_decode_admission([8000, 6000, 'mime' => 'image/jpeg'], 1600, false, '512M', $usage)['allowed'], 'A 48 MP photo without rotation should remain admissible with sufficient headroom.');
image_decode_policy_assert(!image_decode_admission([8000, 6000, 'mime' => 'image/jpeg'], 1600, true, '512M', $usage)['allowed'], 'A 48 MP rotation must respect its larger demand.');
image_decode_policy_assert(image_decode_admission($photo, 1600, false, '64M', $usage)['reason'] === 'memory_budget', 'A small PHP limit must constrain normal photos.');
image_decode_policy_assert(image_decode_admission($small, 32, false, '16M', $usage)['reason'] === 'memory_budget', 'Current usage and reserve must consume exhausted headroom.');
image_decode_policy_assert(image_decode_admission($small, 32, false, false, 0)['reason'] === 'memory_limit_unknown', 'Unavailable memory limits must defer native decoding.');
image_decode_policy_assert(image_decode_admission($small, 32, false, '-1', -1)['reason'] === 'memory_limit_unknown', 'Invalid memory observations must fail closed.');

$unlimited = image_decode_admission($photo, 1600, false, '-1', $usage);
image_decode_policy_assert($unlimited['allowed'] && $unlimited['memory_ceiling_bytes'] === $defaults['max_memory_bytes'], 'Unlimited PHP memory must retain the central application ceiling.');
image_decode_policy_assert(image_decode_admission($photo, null, true, '-1', $usage)['reason'] === 'memory_budget', 'Legacy full-size working buffers must not silently become unbounded.');
image_decode_policy_assert(image_decode_admission($small, 32, false, '-1', $defaults['max_memory_bytes'])['reason'] === 'memory_budget', 'Unlimited PHP must still subtract current request usage.');

$noInputCopies = image_decode_admission([6000, 4000, 'mime' => 'image/jpeg'], 1600, false, '512M', $usage);
image_decode_policy_assert($ordinary['estimated_bytes'] - $noInputCopies['estimated_bytes'] === 16 * 1024 * 1024, 'Packed source bytes must contribute working memory.');
$imagick = image_decode_admission($photo, 1600, true, '-1', 0, ['max_memory_bytes' => 1073741824], 'imagick');
$retained = image_decode_admission($photo, 1600, true, '-1', 0, ['max_memory_bytes' => 1073741824], 'imagick', true);
image_decode_policy_assert($imagick['allowed'] && $retained['allowed'], 'Generous bounded memory should admit the optional Imagick path.');
image_decode_policy_assert($retained['estimated_bytes'] - $imagick['estimated_bytes'] === 24000000 * 8, 'Imagick must account for the already-live GD source.');
$exactCeiling = $ordinary['estimated_bytes'] + $usage + $defaults['memory_reserve_bytes'];
image_decode_policy_assert(image_decode_admission($photo, 1600, false, '-1', $usage, ['max_memory_bytes' => $exactCeiling])['allowed'], 'Exact memory headroom must admit the request.');
image_decode_policy_assert(image_decode_admission($photo, 1600, false, '-1', $usage, ['max_memory_bytes' => $exactCeiling - 1])['reason'] === 'memory_budget', 'One byte below required headroom must refuse the request.');

foreach ([null, [], [64, 48]] as $metadata) {
    image_decode_policy_assert(image_decode_admission($metadata, 32, false, '512M', 0)['reason'] === 'metadata_unavailable', 'Missing metadata must never grant admission.');
}
foreach ([[0, 10], [-1, 1], [1.5, 10], ['100', 10]] as $dimensions) {
    image_decode_policy_assert(image_decode_admission($dimensions + ['mime' => 'image/png'], 32, false, '512M', 0)['reason'] === 'invalid_dimensions', 'Dimensions must be positive exact integers.');
}
image_decode_policy_assert(image_decode_admission([1, 1, 'mime' => 'image/svg+xml'], 32, false, '512M', 0)['reason'] === 'unsupported_format', 'Unsupported formats need their own bounded explanation.');
image_decode_policy_assert(image_decode_admission([PHP_INT_MAX, PHP_INT_MAX, 'mime' => 'image/png'], 32, true, '-1', 0)['reason'] === 'dimension_limit', 'Extreme header dimensions must be refused without a decoder.');
image_decode_policy_assert(image_decode_admission([10000, 10000, 'mime' => 'image/png'], 32, false, '-1', 0)['reason'] === 'pixel_limit', 'A bounded dimension alone must not bypass the pixel limit.');
$veryLargeLimits = ['max_dimension' => PHP_INT_MAX, 'max_pixels' => PHP_INT_MAX, 'max_memory_bytes' => PHP_INT_MAX, 'memory_reserve_bytes' => 1];
image_decode_policy_assert(image_decode_admission([PHP_INT_MAX, 2, 'mime' => 'image/png'], 32, false, '-1', 0, $veryLargeLimits)['reason'] === 'arithmetic_overflow', 'Operator overrides must not defeat overflow checks.');
image_decode_policy_assert(image_decode_admission([PHP_INT_MAX, 1, 'mime' => 'image/png'], 32, false, '-1', 0, $veryLargeLimits)['reason'] === 'arithmetic_overflow', 'Surface byte multiplication must refuse integer overflow.');
image_decode_policy_assert(image_decode_admission($small + ['unused' => true], 32, false, '512M', 0, ['memory_reserve_bytes' => PHP_INT_MAX])['reason'] === 'memory_budget', 'Oversized reserves must saturate safely.');
image_decode_policy_assert(image_decode_admission([64, 48, 'mime' => 'image/png', 'source_bytes' => PHP_INT_MAX], 32, false, '-1', 0)['reason'] === 'arithmetic_overflow', 'Packed byte estimates must not overflow.');
$sumOverflowBytes = intdiv(PHP_INT_MAX, 2);
image_decode_policy_assert(image_decode_admission([64, 48, 'mime' => 'image/png', 'source_bytes' => $sumOverflowBytes], 32, false, '-1', 0)['reason'] === 'arithmetic_overflow', 'Adding individually valid buffers must still reject overflow.');

foreach ([0, -1, INF, 12.5, 'unlimited', '9999999999999999999999999999'] as $value) {
    $normalized = image_decode_limits(array_fill_keys(array_keys($defaults), $value));
    image_decode_policy_assert($normalized === $defaults, 'Invalid overrides must fall back to central positive defaults.');
}
image_decode_policy_assert(image_decode_limits(['max_pixels' => 1000])['max_pixels'] === 1000, 'Valid deployment bounds must be honored.');
image_decode_policy_assert(image_decode_status('/private/token=secret')['reason'] === 'processing_failed', 'Untrusted reason values must collapse to the closed vocabulary.');
image_decode_policy_assert(!str_contains(json_encode(image_decode_status('/private/token=secret')), 'secret'), 'Failure status must not expose input paths or secrets.');

echo "Image decode policy tests passed (metadata arithmetic only; no native surfaces).\n";
