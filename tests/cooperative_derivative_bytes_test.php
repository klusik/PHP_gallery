<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_derivative_bytes_test.php
 * Module Type: Regression Test
 * Purpose: Verify bounded metadata-free cooperative derivative containers.
 * Responsibilities: Exercise real image round trips and crafted hostile containers without live state.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/cooperative_galleries.php';
require_once dirname(__DIR__) . '/app/services/cooperative_content.php';

/** Assert one isolated derivative behavior.
 * @param bool $condition Expected result.
 * @param string $message Safe failure explanation.
 * @return void No return value.
 */
function derivative_check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

/** Require bounded refusal without a raw image fallback.
 * @param string $bytes Untrusted crafted container.
 * @param string $mime Expected type.
 * @return void No return value.
 */
function derivative_refuses(string $bytes, string $mime): void
{
    try {
        \Gallery\Services\cooperative_derivative_sanitize($bytes, $mime);
    } catch (\Gallery\Services\CooperativeException $error) {
        derivative_check($error->reason === 'content_unavailable', 'Unexpected derivative refusal.');
        return;
    }
    throw new RuntimeException('Malformed derivative accepted.');
}

/** Require a stale-file refusal after a simulated concurrent mutation.
 * @param string $path Previously resolved fixture path.
 * @param string $root Original authorized fixture root.
 * @param string $sourceHash Fingerprint from the original derivative read.
 * @return void No return value.
 */
function derivative_source_refuses(string $path, string $root, string $sourceHash): void
{
    try {
        \Gallery\Services\cooperative_derivative_assert_unchanged($path, $root, $sourceHash);
    } catch (\Gallery\Services\CooperativeException $error) {
        derivative_check($error->reason === 'content_unavailable', 'Unexpected stale-source refusal.');
        return;
    }
    throw new RuntimeException('Changed derivative source accepted.');
}

/** Build a length-prefixed JPEG segment.
 * @param int $marker Segment marker byte.
 * @param string $payload Segment data.
 * @return string Encoded marker segment.
 */
function derivative_segment(int $marker, string $payload): string
{
    return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
}

/** Build a padded RIFF chunk.
 * @param string $type Four-byte chunk identifier.
 * @param string $payload Chunk data.
 * @return string Encoded RIFF chunk.
 */
function derivative_chunk(string $type, string $payload): string
{
    return $type . pack('V', strlen($payload)) . $payload . (strlen($payload) % 2 ? "\0" : '');
}

/** Build one exact WebP container.
 * @param string $chunks Encoded chunk sequence.
 * @return string RIFF WebP container.
 */
function derivative_riff(string $chunks): string
{
    return 'RIFF' . pack('V', strlen($chunks) + 4) . 'WEBP' . $chunks;
}

$frame = derivative_segment(194, "\x08\0\x01\0\x01\x01\x01\x11\0");
$scan = derivative_segment(218, "\x01\x01\0\0\x3F\0");
$entropy = "\x22\xFF\0\x33\xFF\xD0\x44";
$jpeg = "\xFF\xD8" . $frame . $scan . $entropy . $scan . $entropy . "\xFF\xD9";
$dirty = "\xFF\xD8" . derivative_segment(225, 'EXIF GPS private') . $frame . $scan . $entropy
    . derivative_segment(254, 'private comment') . derivative_segment(226, 'XMP private')
    . $scan . $entropy . "\xFF\xD9trailing private";
$clean = \Gallery\Services\cooperative_derivative_sanitize($dirty, 'image/jpeg');
derivative_check($clean['bytes'] === $jpeg && $clean['mime'] === 'image/jpeg', 'JPEG scan or metadata stripping changed.');
$adobe = derivative_segment(238, "Adobe\0\x64\0\0\0\0\x01");
$adobeJpeg = substr($jpeg, 0, 2) . $adobe . substr($jpeg, 2);
derivative_check(\Gallery\Services\cooperative_derivative_sanitize($adobeJpeg, 'image/jpeg')['bytes'] === $adobeJpeg,
    'Exact Adobe color-transform marker was removed.');
$adobeMetadata = substr($jpeg, 0, 2) . derivative_segment(238, "Adobe\0\x64\0\0\0\0\x01private") . substr($jpeg, 2);
derivative_check(\Gallery\Services\cooperative_derivative_sanitize($adobeMetadata, 'image/jpeg')['bytes'] === $jpeg,
    'Arbitrary data survived in an Adobe-like marker.');
foreach (['', "\xFF\xD8\xFF\xD9", substr($jpeg, 0, -2), "\xFF\xD8\xFF\xE1\0\x01",
    "\xFF\xD8" . $frame . $scan . "\xFF\xD9",
    str_repeat('x', \Gallery\Services\COOPERATIVE_DERIVATIVE_MAX_BYTES + 1)] as $bad) {
    derivative_refuses($bad, 'image/jpeg');
}
derivative_refuses($jpeg, 'image/webp');
derivative_refuses($jpeg, 'image/png');

// A minimal static lossless header is enough to exercise container policy independently of a decoder.
$lossless = derivative_chunk('VP8L', "\x2F\0\0\0\0\0");
$vp8x = derivative_chunk('VP8X', "\x2C\0\0\0\0\0\0\0\0\0");
$webp = derivative_riff($vp8x . derivative_chunk('EXIF', 'GPS private') . $lossless
    . derivative_chunk('XMP ', 'XMP private') . derivative_chunk('ICCP', 'ICC private'));
$sanitized = \Gallery\Services\cooperative_derivative_sanitize($webp, 'image/webp')['bytes'];
derivative_check($sanitized === derivative_riff(derivative_chunk('VP8X', str_repeat("\0", 10)) . $lossless), 'WebP metadata flags or chunks survived.');
foreach ([$webp . 'trailing', substr($webp, 0, -1), derivative_riff('EXIF' . pack('V', 0xFFFFFFFF)),
    derivative_riff(derivative_chunk('ANIM', 'private') . $lossless),
    derivative_riff(derivative_chunk('JUNK', 'private') . $lossless),
    derivative_riff(derivative_chunk('VP8X', "\x02" . str_repeat("\0", 9)) . $lossless),
    derivative_riff($lossless . $lossless), derivative_riff('')] as $bad) {
    derivative_refuses($bad, 'image/webp');
}

$temp = tempnam(sys_get_temp_dir(), 'cooperative-derivative-');
derivative_check(is_string($temp), 'Temporary fixture unavailable.');
try {
    file_put_contents($temp, $dirty);
    $before = hash_file('sha256', $temp);
    $read = \Gallery\Services\cooperative_derivative_read($temp, 'image/jpeg');
    derivative_check($read['bytes'] === $jpeg && $read['source_hash'] === hash('sha256', $dirty), 'Bounded file read or raw fingerprint failed.');
    derivative_check(hash_file('sha256', $temp) === $before, 'GET derivative read wrote its source.');
    $resolved = realpath($temp);
    $root = dirname($resolved);
    \Gallery\Services\cooperative_derivative_assert_unchanged($resolved, $root, $read['source_hash']);
    derivative_source_refuses($resolved, $root . DIRECTORY_SEPARATOR . 'unrelated-root', $read['source_hash']);
    file_put_contents($temp, $dirty . 'concurrent appended metadata');
    derivative_source_refuses($resolved, $root, $read['source_hash']);
    file_put_contents($temp, str_repeat('x', \Gallery\Services\COOPERATIVE_DERIVATIVE_MAX_BYTES + 1));
    derivative_source_refuses($resolved, $root, $read['source_hash']);
    try {
        \Gallery\Services\cooperative_derivative_read($temp, 'image/jpeg');
        throw new RuntimeException('Oversized derivative read accepted.');
    } catch (\Gallery\Services\CooperativeException $error) {
        derivative_check($error->reason === 'content_unavailable', 'Oversized read returned an unexpected refusal.');
    }
    unlink($temp);
    derivative_source_refuses($resolved, $root, $read['source_hash']);
} finally { if (is_file($temp)) { unlink($temp); } }

if (function_exists('imagecreatetruecolor')) {
    $image = imagecreatetruecolor(3, 2);
    foreach (['image/jpeg' => 'imagejpeg', 'image/webp' => 'imagewebp'] as $mime => $encoder) {
        if (!function_exists($encoder)) { continue; }
        ob_start();
        $encoder($image);
        $original = ob_get_clean();
        $dirtyReal = $mime === 'image/jpeg'
            ? substr($original, 0, 2) . derivative_segment(225, 'GPS private') . substr($original, 2)
            : derivative_riff(substr($original, 12) . derivative_chunk('EXIF', 'GPS private'));
        $cleanReal = \Gallery\Services\cooperative_derivative_sanitize($dirtyReal, $mime)['bytes'];
        $decoded = @imagecreatefromstring($cleanReal);
        derivative_check($decoded !== false && imagesx($decoded) === 3 && imagesy($decoded) === 2
            && !str_contains($cleanReal, 'GPS private'), 'Real derivative failed round-trip decoding.');
        imagedestroy($decoded);
    }
    imagedestroy($image);
}
echo "Cooperative derivative byte contracts passed.\n";
