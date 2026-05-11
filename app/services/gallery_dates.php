<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_dates.php
 * Module Type: Service
 *
 * Purpose:
 *   Handles optional manual dates assigned to galleries by admins.
 *
 * Responsibilities:
 *   - Normalize date input from admin forms
 *   - Validate YYYY-MM-DD storage values
 *   - Format stored gallery dates for public display
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
 *   2026-05-11
 */

declare(strict_types=1);

/**
 * Return true when gallery rows can store the optional manual gallery date.
 */
function gallery_date_schema_ready(): bool
{
    return db_column_exists('galleries', 'gallery_date');
}

/**
 * Normalize an admin-submitted gallery date for database storage.
 */
function gallery_date_storage_value(mixed $value): ?string
{
    // $date stores the trimmed form value before strict validation.
    $date = trim((string) $value);
    if ($date === '') {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException(t('admin.gallery_editor.invalid_gallery_date', 'Enter a valid gallery date.'));
    }
    return $date;
}


/**
 * Normalize a sidecar gallery date without letting malformed legacy metadata stop scanning.
 */
function gallery_date_sidecar_value(mixed $value): ?string
{
    try {
        return gallery_date_storage_value($value);
    } catch (InvalidArgumentException) {
        return null;
    }
}

/**
 * Return a valid input[type=date] value for admin forms.
 */
function gallery_date_input_value(mixed $value): string
{
    try {
        return gallery_date_storage_value($value) ?? '';
    } catch (InvalidArgumentException) {
        return '';
    }
}

/**
 * Format a stored gallery date for public display.
 */
function gallery_date_display_value(mixed $value): string
{
    try {
        $date = gallery_date_storage_value($value);
    } catch (InvalidArgumentException) {
        return '';
    }
    if ($date === null) {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed) {
        return '';
    }
    return $parsed->format('j. n. Y');
}

/**
 * Render the optional public gallery date when one was manually assigned.
 */
function render_gallery_date(array $gallery, string $class = 'gallery-date'): void
{
    if (!gallery_date_schema_ready()) {
        return;
    }
    // $displayDate stores the visitor-facing date string. Empty values are intentionally silent.
    $displayDate = gallery_date_display_value($gallery['gallery_date'] ?? null);
    if ($displayDate === '') {
        return;
    }
    echo '<time class="' . e($class) . '" datetime="' . e((string) $gallery['gallery_date']) . '">' . e($displayDate) . '</time>';
}
