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
 *   - Shared constants for this module live in app/services/admin_gallery_report.php.
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

/**
 * Escape HTML text.
 *
 * @param mixed $value Raw value.
 * @return string Escaped text.
 */
function admin_gallery_report_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format a number.
 *
 * @param mixed $value Numeric value.
 * @param int $precision Decimal precision.
 * @return string Formatted number.
 */
function admin_gallery_report_n(mixed $value, int $precision = 0): string
{
    return number_format((float) $value, $precision, '.', ' ');
}

/**
 * Format bytes.
 *
 * @param mixed $bytes Byte value.
 * @return string Human-readable bytes.
 */
function admin_gallery_report_bytes(mixed $bytes): string
{
    $value = max(0.0, (float) $bytes);
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $index = 0;
    while ($value >= 1024.0 && $index < count($units) - 1) {
        $value /= 1024.0;
        $index++;
    }
    return number_format($value, $index === 0 ? 0 : 1, '.', ' ') . ' ' . $units[$index];
}

/**
 * Format physical server memory information.
 *
 * @param array $memory Memory summary.
 * @return string Human-readable memory status.
 */
function admin_gallery_report_server_memory_label(array $memory): string
{
    if (empty($memory['available'])) {
        return 'not exposed by host';
    }
    $parts = ['total ' . admin_gallery_report_bytes($memory['total_bytes'] ?? 0)];
    if ((int) ($memory['available_bytes'] ?? 0) > 0) {
        $parts[] = 'available ' . admin_gallery_report_bytes($memory['available_bytes']);
    }
    if ((int) ($memory['swap_total_bytes'] ?? 0) > 0) {
        $parts[] = 'swap ' . admin_gallery_report_bytes($memory['swap_total_bytes']) . ' total, ' . admin_gallery_report_bytes($memory['swap_free_bytes'] ?? 0) . ' free';
    }
    return implode(', ', $parts);
}

/**
 * Format server load average information.
 *
 * @param array $load Load average values.
 * @return string Human-readable load average.
 */
function admin_gallery_report_load_average_label(array $load): string
{
    if ($load === []) {
        return 'not exposed by host';
    }
    $values = [];
    foreach (array_slice($load, 0, 3) as $value) {
        $values[] = admin_gallery_report_n($value, 2);
    }
    return implode(' / ', $values);
}

/**
 * Format GD library information.
 *
 * @param array $gdInfo GD information.
 * @return string Human-readable GD label.
 */
function admin_gallery_report_gd_label(array $gdInfo): string
{
    if ($gdInfo === []) {
        return 'not loaded';
    }
    return (string) ($gdInfo['GD Version'] ?? 'loaded');
}

/**
 * Format percentage.
 *
 * @param mixed $part Part value.
 * @param mixed $total Total value.
 * @return string Formatted percent.
 */
function admin_gallery_report_percent(mixed $part, mixed $total): string
{
    $totalValue = (float) $total;
    if ($totalValue <= 0) {
        return '0.0 %';
    }
    return number_format(((float) $part / $totalValue) * 100.0, 1, '.', ' ') . ' %';
}

/**
 * Format duration.
 *
 * @param int $seconds Duration in seconds.
 * @return string Formatted duration.
 */
function admin_gallery_report_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining = $seconds % 60;
    if ($hours > 0) {
        return $hours . ' h ' . $minutes . ' min ' . $remaining . ' s';
    }
    if ($minutes > 0) {
        return $minutes . ' min ' . $remaining . ' s';
    }
    return $remaining . ' s';
}

/**
 * Return a compact date string.
 *
 * @param string $value Datetime value.
 * @return string Date text.
 */
function admin_gallery_report_short_date(string $value): string
{
    if (!admin_gallery_report_valid_datetime($value)) {
        return '';
    }
    return substr($value, 0, 10);
}
