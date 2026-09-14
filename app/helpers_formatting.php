<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/helpers_formatting.php
 * Module Type: Core Helper Module
 *
 * Purpose:
 *   Provides transport- and domain-neutral formatting primitives shared by controllers, services, and views.
 *
 * Responsibilities:
 *   - Format byte counts consistently without coupling presentation code to telemetry services
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
 *   - Keep functions in this module pure and free from request, database, and feature-policy access.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Core;

/**
 * Format a byte count using binary 1024-unit scaling and the gallery's existing labels.
 *
 * @param int|float $bytes Byte count.
 * @param int $precision Decimal precision for units above bytes.
 * @return string Human-readable byte count.
 */
function format_bytes(int|float $bytes, int $precision = 1): string
{
    $bytes = (float) $bytes;
    $units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    if ($index === 0) {
        return number_format($bytes, 0) . ' ' . $units[$index];
    }
    return number_format($bytes, max(0, $precision)) . ' ' . $units[$index];
}
