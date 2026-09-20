<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report/format.php
 * Module Type: Service
 *
 * Purpose:
 *   Pure value formatting and labelling helpers for report output.
 *
 * Responsibilities:
 *   - Escape and format numbers, byte sizes, percentages, and durations
 *   - Derive compact labels, ISO buckets, and file extensions
 *   - Keep formatting free of database and filesystem access
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
 *   - Loaded by app/services/admin_gallery_report.php; do not require this file directly.
 *   - The module entry point loads immutable Core policy; consuming parts import their required definitions.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;

/**
 * Return whether a database datetime looks meaningful.
 *
 * @param string $value Date value.
 * @return bool True for usable values.
 */
function admin_gallery_report_valid_datetime(string $value): bool
{
    $value = trim($value);
    return $value !== '' && $value !== '0000-00-00 00:00:00' && $value > '1000-01-01 00:00:00';
}

/**
 * Return normalized extension for grouping.
 *
 * @param string $filename Filename value.
 * @return string Extension.
 */
function admin_gallery_report_file_extension(string $filename): string
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return $extension !== '' ? $extension : 'unknown';
}

/**
 * Normalize a human label.
 *
 * @param string $label Label value.
 * @return string Compacted label.
 */
function admin_gallery_report_compact_label(string $label): string
{
    return trim((string) preg_replace('/\s+/', ' ', $label));
}

/**
 * Return ISO bucket label.
 *
 * @param int $iso ISO value.
 * @return string Bucket label.
 */
function admin_gallery_report_iso_bucket(int $iso): string
{
    if ($iso <= 100) {
        return t('admin.gallery_report.export.iso_100_or_lower', 'ISO 100 or lower');
    }
    if ($iso <= 400) {
        return t('admin.gallery_report.export.iso_101_400', 'ISO 101-400');
    }
    if ($iso <= 800) {
        return t('admin.gallery_report.export.iso_401_800', 'ISO 401-800');
    }
    if ($iso <= 1600) {
        return t('admin.gallery_report.export.iso_801_1600', 'ISO 801-1600');
    }
    if ($iso <= 3200) {
        return t('admin.gallery_report.export.iso_1601_3200', 'ISO 1601-3200');
    }
    return t('admin.gallery_report.export.iso_3201_plus', 'ISO 3201+');
}
