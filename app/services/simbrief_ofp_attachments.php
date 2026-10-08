<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/simbrief_ofp_attachments.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Resolve an imported SimBrief OFP PDF associated with a physical gallery.
 *
 * Responsibilities:
 *   - Trust the locally persisted OFP manifest, never a visitor-supplied path
 *   - Refuse symlinks, cross-gallery traversal and malformed PDF attachments
 *   - Keep PDF discoverability independent from editable gallery descriptions
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-10-07
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Determine whether a request may read the original gallery-owned OFP PDF.
 *
 * An authenticated administrator may inspect unpublished/private source
 * galleries. Every other visitor follows the exact direct-gallery visitor
 * access policy: public and unpublished (unlisted) galleries can be visited
 * by direct URL, including their original OFP attachments, but password,
 * share-token and NSFW requirements still apply. Private galleries without
 * a valid visitor grant remain inaccessible. A generated private OFP-page
 * child retains its own independent visibility restrictions.
 *
 * @param array<string,mixed> $gallery Physical gallery containing the PDF.
 * @param bool $isAdmin Whether the caller has verified administrator authority
 *                      outside anonymous-preview mode.
 * @return bool True only when PDF download and inline reading are authorized.
 */
function simbrief_ofp_visitor_access_allowed(array $gallery, bool $isAdmin): bool
{
    if ($isAdmin) {
        return true;
    }
    // This is the same no-admin-bypass decision used by the public gallery.
    // An unpublished gallery is unlisted, not a private gallery: direct URLs
    // can access its PDF whenever the gallery itself is visitor-accessible.
    return visitor_can_access_gallery_without_admin_bypass($gallery);
}

/**
 * Resolve the valid locally stored original OFP PDF for one gallery.
 *
 * The manifest is a local attachment index. Never accept a file or URL supplied
 * by a caller. The safe legacy path is supported for OFPs imported before #103.
 *
 * @param array<string,mixed> $gallery Physical gallery row.
 * @return ?string Validated absolute PDF path or null when unavailable.
 */
function simbrief_ofp_local_pdf_path(array $gallery): ?string
{
    $relative = trim((string) ($gallery['folder_path'] ?? ''));
    if ($relative === '' || (int) ($gallery['id'] ?? 0) <= 0) {
        return null;
    }
    if (!function_exists('Gallery\\Services\\gallery_abs_path')
        || !function_exists('Gallery\\Services\\galleries_root')
        || !function_exists('Gallery\\Core\\path_inside')) {
        return null;
    }
    $root = realpath(gallery_abs_path($relative));
    $galleriesRoot = realpath(galleries_root());
    if ($root === false || $galleriesRoot === false || !\Gallery\Core\path_inside($galleriesRoot, $root)) {
        return null;
    }
    $manifestPath = $root . DIRECTORY_SEPARATOR . 'simbrief-ofp-manifest.json';
    if (!is_file($manifestPath) || is_link($manifestPath) || (int) filesize($manifestPath) > 32768) {
        return null;
    }
    $json = @file_get_contents($manifestPath);
    $manifest = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($manifest)
        || ($manifest['format'] ?? '') !== 'php_gallery_simbrief_ofp_manifest_v1'
        || ($manifest['ofp_pdf_file'] ?? '') !== 'simbrief-ofp.pdf') {
        return null;
    }
    $candidate = $root . DIRECTORY_SEPARATOR . 'simbrief-ofp.pdf';
    if (!is_file($candidate) || is_link($candidate)) {
        return null;
    }
    $path = realpath($candidate);
    $size = $path !== false ? @filesize($path) : false;
    if ($path === false || !\Gallery\Core\path_inside($root, $path)
        || $size === false || $size < 8 || $size > 26214400) {
        return null;
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }
    try {
        $magic = fread($handle, 5);
    } finally {
        fclose($handle);
    }
    return $magic === '%PDF-' ? $path : null;
}

/**
 * Check that a server-side PDF renderer is available without spawning commands.
 *
 * @return bool Whether this host can attempt optional OFP conversion.
 */
function simbrief_ofp_conversion_supported(): bool
{
    if (!class_exists(\Imagick::class)) {
        return false;
    }
    try {
        return in_array('PDF', \Imagick::queryFormats('PDF'), true)
            && in_array('JPEG', \Imagick::queryFormats('JPEG'), true);
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Resolve the source gallery's real directory for a gallery-scoped OFP write.
 *
 * @param array<string,mixed> $gallery Persisted gallery row, not request input.
 * @return ?string Existing physical gallery directory within galleries_root().
 */
function simbrief_ofp_gallery_directory(array $gallery): ?string
{
    if ((int) ($gallery['id'] ?? 0) < 1 || !function_exists('Gallery\\Services\\gallery_abs_path')
        || !function_exists('Gallery\\Services\\galleries_root') || !function_exists('Gallery\\Core\\path_inside')) {
        return null;
    }
    $relative = trim((string) ($gallery['folder_path'] ?? ''));
    if ($relative === '' || str_contains($relative, "\0")) {
        return null;
    }
    $candidate = gallery_abs_path($relative);
    $root = realpath($candidate);
    $base = realpath(galleries_root());
    if ($root === false || $base === false || is_link($candidate)
        || !\Gallery\Core\path_inside($base, $root)) {
        return null;
    }
    return $root;
}

/**
 * Read the existing canonical manifest without trusting a request-supplied path.
 *
 * @param array<string,mixed> $gallery Source gallery.
 * @return array<string,mixed> Valid v1 manifest or an empty array.
 */
function simbrief_ofp_gallery_manifest(array $gallery): array
{
    $root = simbrief_ofp_gallery_directory($gallery);
    if ($root === null) {
        return [];
    }
    $path = $root . '/simbrief-ofp-manifest.json';
    if (!is_file($path) || is_link($path) || (int) @filesize($path) > 32768) {
        return [];
    }
    $json = @file_get_contents($path);
    $manifest = is_string($json) ? json_decode($json, true) : null;
    return is_array($manifest) && ($manifest['format'] ?? '') === 'php_gallery_simbrief_ofp_manifest_v1'
        ? $manifest : [];
}

/**
 * Validate one value before it may be offered as a dispatch prefill.
 *
 * Invalid or ambiguous values are deliberately omitted, not guessed.
 *
 * @param string $field Official Dispatch Redirect query field.
 * @param string $value Local candidate.
 * @return string Normalized validated value, or empty.
 */
function simbrief_ofp_dispatch_value(string $field, string $value): string
{
    $value = strtoupper(trim($value));
    if ($value === '') {
        return '';
    }
    if ($field === 'date') {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
    }
    if ($field === 'fl' && preg_match('/^FL(\d{2,3})$/D', $value, $matched)) {
        $value = $matched[1];
    } elseif ($field === 'fl' && preg_match('/^\d{4,5}$/D', $value)
        && (int) $value % 100 === 0) {
        $value = (string) ((int) $value / 100);
    }
    $patterns = [
        'orig' => '/^[A-Z]{4}$/D',
        'dest' => '/^[A-Z]{4}$/D',
        'type' => '/^[A-Z0-9]{2,8}$/D',
        'airline' => '/^[A-Z0-9]{2,3}$/D',
        'fltnum' => '/^[A-Z0-9]{1,8}$/D',
        'callsign' => '/^[A-Z0-9]{2,12}$/D',
        'reg' => '/^[A-Z0-9-]{2,12}$/D',
        'route' => '/^[A-Z0-9 .\/+()-]{1,500}$/D',
        'altn' => '/^[A-Z]{4}$/D',
        'fl' => '/^\d{1,3}$/D',
        'pax' => '/^\d{1,3}$/D',
        'deph' => '/^\d{1,2}$/D',
        'depm' => '/^\d{1,2}$/D',
    ];
    if (!isset($patterns[$field]) || preg_match($patterns[$field], $value) !== 1) {
        return '';
    }
    if ($field === 'fl' && (int) $value > 600) {
        return '';
    }
    if ($field === 'deph' && (int) $value > 23) {
        return '';
    }
    if ($field === 'depm' && (int) $value > 59) {
        return '';
    }
    return $value;
}

/**
 * Build a strictly local, provenance-labelled prefill for a single gallery.
 *
 * The saved JSON is authoritative flight data where it exists. Gallery dates
 * and route-map points are explicitly marked uncertain, never represented as
 * confirmed original flight-day departure fields. EXIF/titles are not parsed.
 *
 * @param array<string,mixed> $gallery Persisted selected gallery.
 * @param ?array<string,mixed> $flightMap Existing structured map, if loaded.
 * @return array{fields:array<string,array{value:string,source:string,uncertain:bool}>,has_snapshot:bool}
 */
function simbrief_ofp_dispatch_prefill(array $gallery, ?array $flightMap = null): array
{
    $fields = [];
    foreach (['orig', 'dest', 'date', 'deph', 'depm', 'type', 'airline', 'fltnum',
        'callsign', 'reg', 'route', 'fl', 'altn', 'pax'] as $field) {
        $fields[$field] = ['value' => '', 'source' => 'missing', 'uncertain' => false];
    }
    $root = simbrief_ofp_gallery_directory($gallery);
    $snapshot = [];
    if ($root !== null) {
        $path = $root . '/simbrief-ofp.json';
        if (is_file($path) && !is_link($path) && (int) @filesize($path) <= 8388608) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $snapshot = $decoded;
            }
        }
    }
    $paths = [
        'orig' => ['origin.icao_code', 'origin.icao'],
        'dest' => ['destination.icao_code', 'destination.icao'],
        'type' => ['aircraft.icao_code', 'aircraft.icao', 'aircraft.type'],
        'airline' => ['params.airline'],
        'fltnum' => ['params.fltnum'],
        'callsign' => ['params.callsign'],
        'reg' => ['params.reg', 'aircraft.registration'],
        'route' => ['params.route', 'general.route'],
        'fl' => ['params.fl', 'general.initial_altitude'],
        'altn' => ['alternate.icao_code', 'alternate.icao', 'params.altn'],
        'pax' => ['params.pax', 'weights.pax_count_actual'],
        'date' => ['params.date'],
        'deph' => ['params.deph'],
        'depm' => ['params.depm'],
    ];
    foreach ($paths as $field => $candidates) {
        foreach ($candidates as $path) {
            $node = $snapshot;
            foreach (explode('.', $path) as $segment) {
                $node = is_array($node) ? ($node[$segment] ?? null) : null;
            }
            if (!is_string($node) && !is_numeric($node)) {
                continue;
            }
            $value = simbrief_ofp_dispatch_value($field, (string) $node);
            if ($value !== '') {
                $fields[$field] = ['value' => $value, 'source' => 'saved_ofp', 'uncertain' => false];
                break;
            }
        }
    }
    if ($fields['date']['value'] === '') {
        $date = simbrief_ofp_dispatch_value('date', (string) ($gallery['gallery_date'] ?? ''));
        $end = trim((string) ($gallery['gallery_date_end'] ?? ''));
        if ($date !== '' && ($end === '' || $end === $date)) {
            $fields['date'] = ['value' => $date, 'source' => 'gallery_date', 'uncertain' => true];
        }
    }
    if (is_array($flightMap)) {
        if ($fields['route']['value'] === '') {
            $route = simbrief_ofp_dispatch_value('route', (string) ($flightMap['route_text'] ?? ''));
            if ($route !== '') {
                $fields['route'] = ['value' => $route, 'source' => 'route_map', 'uncertain' => true];
            }
        }
        if (function_exists('Gallery\\Services\\gallery_flight_map_points_from_row')) {
            $points = gallery_flight_map_points_from_row($flightMap);
            foreach ($points as $point) {
                if (!is_array($point) || (string) ($point['kind'] ?? '') !== 'airport') {
                    continue;
                }
                $field = ($point['role'] ?? '') === 'start' ? 'orig'
                    : (($point['role'] ?? '') === 'end' ? 'dest' : '');
                if ($field !== '' && $fields[$field]['value'] === '') {
                    $value = simbrief_ofp_dispatch_value($field, (string) ($point['name'] ?? ''));
                    if ($value !== '') {
                        $fields[$field] = ['value' => $value, 'source' => 'route_map', 'uncertain' => true];
                    }
                }
            }
        }
    }
    // Never prefill half of a UTC departure time.
    if (($fields['deph']['value'] === '') !== ($fields['depm']['value'] === '')) {
        $fields['deph'] = ['value' => '', 'source' => 'missing', 'uncertain' => false];
        $fields['depm'] = ['value' => '', 'source' => 'missing', 'uncertain' => false];
    }
    return ['fields' => $fields, 'has_snapshot' => $snapshot !== []];
}

/**
 * Store an explicitly uploaded PDF through the same v1 manifest/resolver as #103.
 *
 * The authenticated controller must first verify is_uploaded_file() and CSRF.
 * This service deliberately accepts a local path to enable isolated fixture
 * testing. It never touches source JSON, gallery metadata, route or images.
 *
 * @param array<string,mixed> $gallery Gallery selected by authenticated ID.
 * @param string $source Local validated upload temporary file.
 * @param string $provenance One of the explicit attachment origins.
 * @param bool $replace Explicit replacement request and confirmation.
 * @return array{sha256:string,bytes:int,provenance:string} Saved attachment summary.
 */
function simbrief_ofp_attach_manual_pdf(array $gallery, string $source, string $provenance, bool $replace = false): array
{
    if (!in_array($provenance, ['retrospective_user_generated', 'manually_supplied'], true)) {
        throw new \RuntimeException('Choose a valid OFP document origin.');
    }
    $root = simbrief_ofp_gallery_directory($gallery);
    if ($root === null) {
        throw new \RuntimeException('The selected gallery folder is unavailable.');
    }
    if (!is_file($source) || is_link($source)) {
        throw new \RuntimeException('Select an uploaded PDF file.');
    }
    $size = @filesize($source);
    if ($size === false || $size < 16 || $size > 26214400) {
        throw new \RuntimeException('The PDF must be between 16 bytes and 25 MiB.');
    }
    $handle = @fopen($source, 'rb');
    if ($handle === false) {
        throw new \RuntimeException('The uploaded PDF cannot be read.');
    }
    try {
        $magic = fread($handle, 5);
        if (fseek($handle, max(0, $size - 4096), SEEK_SET) !== 0) {
            throw new \RuntimeException('The uploaded PDF is incomplete.');
        }
        $tail = stream_get_contents($handle);
    } finally {
        fclose($handle);
    }
    $mime = function_exists('finfo_open') ? (new \finfo(FILEINFO_MIME_TYPE))->file($source) : null;
    if ($magic !== '%PDF-' || !is_string($tail) || !str_contains($tail, '%%EOF')
        || ($mime !== null && !in_array($mime, ['application/pdf', 'application/x-pdf'], true))) {
        throw new \RuntimeException('The file is not a valid PDF document.');
    }

    // This lock inode is kept in place: unlinking it would introduce a race
    // between concurrent administrator requests that acquired different locks.
    $lockPath = $root . '/.simbrief-ofp-upload.lock';
    if (is_link($lockPath)) {
        throw new \RuntimeException('OFP attachment storage is unsafe.');
    }
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new \RuntimeException('OFP attachment storage cannot be locked.');
    }
    @chmod($lockPath, 0600);
    $stagePdf = '';
    $stageManifest = '';
    $backup = '';
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new \RuntimeException('OFP attachment storage is busy.');
        }
        $pdf = $root . '/simbrief-ofp.pdf';
        $manifestPath = $root . '/simbrief-ofp-manifest.json';
        if (is_link($pdf) || is_link($manifestPath)) {
            throw new \RuntimeException('OFP attachment storage is unsafe.');
        }
        $existing = is_file($pdf);
        if ($existing && !$replace) {
            throw new \RuntimeException('An OFP PDF is already attached. Use the separate confirmed replacement action.');
        }
        if (!$existing && $replace) {
            throw new \RuntimeException('No PDF exists to replace. Reload the gallery editor.');
        }
        if (is_file($manifestPath) && simbrief_ofp_gallery_manifest($gallery) === []) {
            throw new \RuntimeException('The existing OFP manifest is invalid and was preserved.');
        }
        $manifest = simbrief_ofp_gallery_manifest($gallery);
        if ($manifest === []) {
            $manifest = ['format' => 'php_gallery_simbrief_ofp_manifest_v1'];
        }
        $manifest['ofp_pdf_file'] = 'simbrief-ofp.pdf';
        $manifest['ofp_pdf_url'] = '';
        $manifest['pdf_provenance'] = $provenance;
        $manifest['pdf_attached_at'] = gmdate('Y-m-d\TH:i:s\Z');
        if (!isset($manifest['ofp_file']) && is_file($root . '/simbrief-ofp.json')) {
            $manifest['ofp_file'] = 'simbrief-ofp.json';
        }
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 32768) {
            throw new \RuntimeException('The OFP manifest cannot be stored safely.');
        }
        $suffix = bin2hex(random_bytes(12));
        $stagePdf = $root . '/.simbrief-ofp-' . $suffix . '.tmp';
        $stageManifest = $root . '/.simbrief-ofp-' . $suffix . '.json.tmp';
        $in = @fopen($source, 'rb');
        $out = @fopen($stagePdf, 'xb');
        if ($in === false || $out === false) {
            if (is_resource($in)) fclose($in);
            if (is_resource($out)) fclose($out);
            throw new \RuntimeException('The PDF cannot be staged.');
        }
        $written = 0;
        try {
            @chmod($stagePdf, 0600);
            while (!feof($in)) {
                $chunk = fread($in, 65536);
                if (!is_string($chunk) || ($chunk === '' && !feof($in))) {
                    throw new \RuntimeException('The PDF upload was interrupted.');
                }
                $written += strlen($chunk);
                if ($written > 26214400 || fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('The PDF could not be fully staged.');
                }
            }
            fflush($out);
        } finally {
            fclose($in);
            fclose($out);
        }
        if ($written !== $size || @file_put_contents($stageManifest, $json . "\n", LOCK_EX) !== strlen($json) + 1) {
            throw new \RuntimeException('The OFP attachment could not be staged completely.');
        }
        @chmod($stageManifest, 0600);
        if ($existing) {
            $backup = $root . '/.simbrief-ofp-' . $suffix . '.bak';
            if (!@copy($pdf, $backup)) {
                throw new \RuntimeException('The original PDF could not be backed up for replacement.');
            }
            @chmod($backup, 0600);
        }
        if (!@rename($stagePdf, $pdf)) {
            throw new \RuntimeException('The new PDF could not be installed.');
        }
        $stagePdf = '';
        if (!@rename($stageManifest, $manifestPath)) {
            if ($backup !== '') {
                @rename($backup, $pdf);
                $backup = '';
            } else {
                @unlink($pdf);
            }
            throw new \RuntimeException('The PDF manifest could not be installed; the previous attachment was retained.');
        }
        $stageManifest = '';
        return ['sha256' => (string) hash_file('sha256', $pdf), 'bytes' => $written, 'provenance' => $provenance];
    } finally {
        foreach ([$stagePdf, $stageManifest, $backup] as $temporary) {
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

