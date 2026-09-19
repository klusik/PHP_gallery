<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/release_qualification/preview.php
 * Module Type: Release Qualification PDF Previews
 *
 * Purpose:
 *   Produces repeatable PDF page images in ignored cache for human inspection.
 *
 * Responsibilities:
 *   - Retain renderer failures and never infer visual approval from generated output
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace PhpGallery\ReleaseQualification;

use RuntimeException;
use function PhpGallery\Audit\run_process;
use function PhpGallery\Release\write_text;

/** Parse bounded, one-based physical PDF pages, not printed page labels. */
function preview_pages(string $selection): array
{
    $pages = [];
    foreach (explode(',', $selection) as $range) {
        if (!preg_match('/^([1-9][0-9]{0,3})(?:-([1-9][0-9]{0,3}))?$/D', trim($range), $match)) {
            throw new RuntimeException('Pages must use one-based numbers/ranges, for example 1-3,120-122.');
        }
        $first = (int) $match[1];
        $last = (int) ($match[2] ?? $match[1]);
        if ($last < $first || $last - $first > 199) {
            throw new RuntimeException('Each preview request is limited to 200 pages.');
        }
        foreach (range($first, $last) as $page) {
            $pages[$page] = $page;
        }
        if (count($pages) > 200) {
            throw new RuntimeException('Each preview request is limited to 200 pages.');
        }
    }
    sort($pages, SORT_NUMERIC);
    return array_values($pages);
}

/**
 * Render immutable PDF bytes to fresh preview files using Poppler pdftoppm.
 * A unique attempt directory preserves earlier previews and any failure diagnostics.
 */
function render_previews(string $root, array $snapshot, array $pages, int $dpi, string $renderer): array
{
    if ($dpi < 72 || $dpi > 200 || $renderer === '' || $pages === []
        || preview_pages(implode(',', $pages)) !== $pages) {
        throw new RuntimeException('Use a pdftoppm executable, sorted pages, and 72-200 DPI.');
    }
    $prefix = $snapshot['version'] . '/' . $snapshot['fingerprint'] . '/previews/'
        . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $metadataPath = cache_path($root, $prefix . '/preview.json', true);
    $pdf = $root . '/docs/PHP_Gallery_Manual.pdf';
    $copy = cache_path($root, $prefix . '/manual.pdf');
    if (!copy($pdf, $copy)
        || content_hash($copy) !== $snapshot['files']['docs/PHP_Gallery_Manual.pdf']) {
        throw new RuntimeException('Manual changed while preparing previews; rerun render.');
    }
    $result = [
        'version' => $snapshot['version'], 'fingerprint' => $snapshot['fingerprint'],
        'pdf_sha256' => content_hash($copy), 'dpi' => $dpi, 'pages' => $pages,
        'renderer' => $renderer, 'status' => 'pending', 'outputs' => [],
    ];
    write_text($metadataPath, encode($result));
    try {
        foreach ($pages as $page) {
            $name = sprintf('page-%04d', $page);
            $target = cache_path($root, $prefix . '/' . $name);
            $command = [$renderer, '-f', (string) $page, '-l', (string) $page, '-r', (string) $dpi,
                '-png', '-singlefile', $copy, $target];
            $process = run_process($command, $root, 30);
            write_text($target . '.log', $process['stdout'] . "\n" . $process['stderr']);
            $image = is_file($target . '.png') ? @getimagesize($target . '.png') : false;
            if ($process['exit_code'] !== 0 || $process['timed_out'] || $image === false
                || ($image[2] ?? null) !== IMAGETYPE_PNG) {
                throw new RuntimeException('PDF page ' . $page . ' could not be rendered; see ' . $target . '.log');
            }
            $result['outputs'][$name . '.png'] = content_hash($target . '.png');
        }
        assert_fingerprint(snapshot($root, $snapshot['version']), $snapshot['fingerprint']);
        $result['status'] = 'rendered';
    } catch (\Throwable $exception) {
        $result['status'] = 'failed';
        $result['error'] = $exception->getMessage();
        write_text($metadataPath, encode($result));
        throw $exception;
    }
    write_text($metadataPath, encode($result));
    return $result + ['directory' => dirname($metadataPath)];
}
