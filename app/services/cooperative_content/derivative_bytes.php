<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_content/derivative_bytes.php
 * Module Type: Service
 * Purpose: Remove metadata from existing cooperative derivatives without disk writes.
 * Responsibilities: Validate bounded static containers and return only sanitized image bytes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/** Read an already authorized derivative without generating or modifying it.
 * @param string $path Validated canonical derivative path.
 * @param string $mime Expected canonical derivative MIME type.
 * @return array{bytes:string,mime:string,source_hash:string} Sanitized payload and internal raw-source SHA-256 fingerprint.
 */
function cooperative_derivative_read(string $path, string $mime): array
{
    $bytes = @file_get_contents($path, false, null, 0, COOPERATIVE_DERIVATIVE_MAX_BYTES + 1);
    if (!is_string($bytes)) { throw new CooperativeException('content_unavailable'); }
    return cooperative_derivative_sanitize($bytes, $mime) + ['source_hash' => hash('sha256', $bytes)];
}

/** Refuse a disappeared, replaced or modified derivative after authority revalidation.
 * @param string $path Previously resolved authorized canonical derivative path.
 * @param string $root Previously verified canonical thumbnail root.
 * @param string $sourceHash SHA-256 fingerprint of the bounded original read.
 * @return void No return value; unavailable or changed sources raise a bounded refusal.
 */
function cooperative_derivative_assert_unchanged(string $path, string $root, string $sourceHash): void
{
    clearstatcache(true, $path);
    $currentPath = realpath($path);
    if ($currentPath === false || $currentPath !== $path || !is_file($currentPath)
        || !str_starts_with($currentPath, $root . DIRECTORY_SEPARATOR)) {
        throw new CooperativeException('content_unavailable');
    }
    $currentBytes = @file_get_contents($currentPath, false, null, 0, COOPERATIVE_DERIVATIVE_MAX_BYTES + 1);
    if (!is_string($currentBytes) || $currentBytes === '' || strlen($currentBytes) > COOPERATIVE_DERIVATIVE_MAX_BYTES
        || !hash_equals($sourceHash, hash('sha256', $currentBytes))) {
        throw new CooperativeException('content_unavailable');
    }
}

/** Validate and strip a static derivative container; never return unsanitized fallback bytes.
 * @param string $bytes Existing derivative container bytes.
 * @param string $mime Expected image/jpeg or image/webp type.
 * @return array{bytes:string,mime:string} Metadata-free image payload.
 */
function cooperative_derivative_sanitize(string $bytes, string $mime): array
{
    if ($bytes === '' || strlen($bytes) > COOPERATIVE_DERIVATIVE_MAX_BYTES) {
        throw new CooperativeException('content_unavailable');
    }
    $clean = match ($mime) {
        'image/jpeg' => cooperative_derivative_jpeg($bytes),
        'image/webp' => cooperative_derivative_webp($bytes),
        default => throw new CooperativeException('content_unavailable'),
    };
    $info = @getimagesizefromstring($clean);
    if (!is_array($info) || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1
        || strlen($clean) > COOPERATIVE_DERIVATIVE_MAX_BYTES) {
        throw new CooperativeException('content_unavailable');
    }
    return ['bytes' => $clean, 'mime' => $mime];
}

/** Parse JPEG marker segments and entropy scans, removing all application/comment metadata.
 * @param string $bytes Bounded JPEG input.
 * @return string JPEG bytes ending at the first valid EOI marker.
 */
function cooperative_derivative_jpeg(string $bytes): string
{
    $length = strlen($bytes);
    if (!str_starts_with($bytes, "\xFF\xD8")) { throw new CooperativeException('content_unavailable'); }
    $output = "\xFF\xD8";
    $offset = 2;
    $scanned = false;
    $framed = false;
    while ($offset < $length) {
        if (ord($bytes[$offset++]) !== 255) { throw new CooperativeException('content_unavailable'); }
        while ($offset < $length && ord($bytes[$offset]) === 255) { ++$offset; }
        if ($offset >= $length) { throw new CooperativeException('content_unavailable'); }
        $marker = ord($bytes[$offset++]);
        if ($marker === 217 && $scanned && $framed) { return $output . "\xFF\xD9"; }
        if ($marker < 192 || $marker === 216 || $marker === 217 || ($marker >= 208 && $marker <= 215)) {
            throw new CooperativeException('content_unavailable');
        }
        if ($offset + 2 > $length) { throw new CooperativeException('content_unavailable'); }
        $segmentLength = unpack('n', substr($bytes, $offset, 2))[1];
        if ($segmentLength < 2 || $offset + $segmentLength > $length) { throw new CooperativeException('content_unavailable'); }
        $segment = substr($bytes, $offset, $segmentLength);
        $offset += $segmentLength;
        if (($marker >= 224 && $marker <= 239) || $marker === 254) {
            // Adobe's exact color-transform marker contains no arbitrary metadata.
            if ($marker === 238 && $segmentLength === 14
                && substr($segment, 2, 11) === "Adobe\0\x64\0\0\0\0"
                && ord($segment[13]) <= 2) {
                $output .= "\xFF\xEE" . $segment;
            }
            continue;
        }
        // Baseline/extended/progressive sequential Huffman frames are canonical JPEG derivatives.
        if (in_array($marker, [192, 193, 194], true)) {
            if ($framed || $segmentLength < 11) { throw new CooperativeException('content_unavailable'); }
            $components = ord($segment[7]);
            if ($components < 1 || $components > 4 || $segmentLength !== 8 + 3 * $components) {
                throw new CooperativeException('content_unavailable');
            }
            $framed = true;
        } elseif (!in_array($marker, [196, 218, 219, 221], true)) {
            throw new CooperativeException('content_unavailable');
        }
        $output .= "\xFF" . chr($marker) . $segment;
        if ($marker !== 218) { continue; }
        if (!$framed || $segmentLength < 8 || $segmentLength !== 6 + 2 * ord($segment[2])) {
            throw new CooperativeException('content_unavailable');
        }
        $scanned = true;
        $scanStart = $offset;
        while ($offset < $length) {
            if (ord($bytes[$offset]) !== 255) { ++$offset; continue; }
            $markerStart = $offset++;
            while ($offset < $length && ord($bytes[$offset]) === 255) { ++$offset; }
            if ($offset >= $length) { throw new CooperativeException('content_unavailable'); }
            $next = ord($bytes[$offset]);
            if ($next === 0 || ($next >= 208 && $next <= 215)) { ++$offset; continue; }
            if ($markerStart === $scanStart) { throw new CooperativeException('content_unavailable'); }
            $output .= substr($bytes, $scanStart, $markerStart - $scanStart);
            $offset = $markerStart;
            break;
        }
    }
    throw new CooperativeException('content_unavailable');
}

/** Whitelist static WebP chunks and clear stripped metadata flags.
 * @param string $bytes Bounded RIFF/WebP input.
 * @return string Static WebP without EXIF, XMP or ICC payloads.
 */
function cooperative_derivative_webp(string $bytes): string
{
    $length = strlen($bytes);
    if ($length < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP'
        || unpack('V', substr($bytes, 4, 4))[1] !== $length - 8) { throw new CooperativeException('content_unavailable'); }
    $offset = 12;
    $chunks = '';
    $seen = [];
    while ($offset < $length) {
        if ($offset + 8 > $length) { throw new CooperativeException('content_unavailable'); }
        $type = substr($bytes, $offset, 4);
        $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
        $offset += 8;
        if ($size > $length - $offset || $size + ($size % 2) > $length - $offset) {
            throw new CooperativeException('content_unavailable');
        }
        $payload = substr($bytes, $offset, $size);
        $offset += $size + ($size % 2);
        if (in_array($type, ['EXIF', 'XMP ', 'ICCP'], true)) { continue; }
        if (!in_array($type, ['VP8X', 'VP8 ', 'VP8L', 'ALPH'], true) || isset($seen[$type])) {
            throw new CooperativeException('content_unavailable');
        }
        if ($type === 'VP8X') {
            if ($seen !== [] || $size !== 10 || (ord($payload[0]) & 0xC3) !== 0 || substr($payload, 1, 3) !== "\0\0\0") {
                throw new CooperativeException('content_unavailable');
            }
            $payload[0] = chr(ord($payload[0]) & ~0x2C);
        } elseif ($type === 'ALPH') {
            if (!isset($seen['VP8X']) || isset($seen['VP8 ']) || isset($seen['VP8L']) || $size < 1) {
                throw new CooperativeException('content_unavailable');
            }
        } elseif (isset($seen['VP8 ']) || isset($seen['VP8L']) || $size < 5
            || ($type === 'VP8L' && isset($seen['ALPH']))) {
            throw new CooperativeException('content_unavailable');
        }
        if ($type === 'VP8L' && ($size < 6 || ord($payload[0]) !== 0x2F || (ord($payload[4]) & 0xE0) !== 0)) {
            throw new CooperativeException('content_unavailable');
        }
        if ($type === 'VP8 ' && ($size < 11 || (ord($payload[0]) & 1) !== 0
            || substr($payload, 3, 3) !== "\x9D\x01\x2A")) {
            throw new CooperativeException('content_unavailable');
        }
        $seen[$type] = true;
        $chunks .= $type . pack('V', $size) . $payload . ($size % 2 ? "\0" : '');
    }
    if (!isset($seen['VP8 ']) && !isset($seen['VP8L'])) { throw new CooperativeException('content_unavailable'); }
    return 'RIFF' . pack('V', strlen($chunks) + 4) . 'WEBP' . $chunks;
}
