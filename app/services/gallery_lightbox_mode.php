<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_lightbox_mode.php
 * Module Type: Service
 *
 * Purpose:
 *   Resolves public lightbox browsing mode settings.
 *
 * Responsibilities:
 *   - Keep the global Theme lightbox mode separate from optional per-gallery overrides
 *   - Normalize mode values before they reach public markup, Admin forms, or database writes
 *   - Keep legacy strip values compatible while storing the public picture_strip value
 *   - Provide readable labels for Theme and gallery editor controls
 *   - Keep upgraded installations safe before the newest gallery column migration is applied
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
 *   2026-06-01
 */

declare(strict_types=1);

/**
 * Return true when per-gallery lightbox browsing-mode overrides can be stored.
 *
 * Runtime schema checks intentionally match the existing gallery display helpers.
 * This lets the public site continue to render with the Theme default when code
 * is uploaded before the administrator runs the new database migration.
 */
function gallery_lightbox_browsing_mode_schema_ready(): bool
{
    return db_column_exists('galleries', 'lightbox_browsing_mode');
}

/**
 * Return public lightbox modes supported by the browser renderer.
 *
 * The values are persisted and emitted as data-lightbox-browsing-mode. Keep them
 * short, stable, and independent from translated labels.
 */
function gallery_lightbox_browsing_mode_options(): array
{
    return ['single', 'picture_strip', '3d_carousel'];
}

/**
 * Normalize a submitted, stored, or sidecar lightbox browsing-mode value.
 */
function gallery_lightbox_browsing_mode_normalize(mixed $value, string $fallback = 'single'): string
{
    // $mode stores the lowercase database, sidecar, or form value before validation.
    $mode = strtolower(trim((string) $value));
    // Earlier builds stored the picture-strip mode as strip. Accept it for settings, sidecars, and existing rows.
    if ($mode === 'strip') {
        return 'picture_strip';
    }
    if (in_array($mode, gallery_lightbox_browsing_mode_options(), true)) {
        return $mode;
    }
    // $fallbackMode receives the same legacy normalization so invalid values can still fall back to picture_strip.
    $fallbackMode = strtolower(trim((string) $fallback));
    if ($fallbackMode === 'strip') {
        $fallbackMode = 'picture_strip';
    }
    return in_array($fallbackMode, gallery_lightbox_browsing_mode_options(), true) ? $fallbackMode : 'single';
}

/**
 * Return the global Theme fallback for galleries without a lightbox override.
 */
function theme_lightbox_browsing_mode(): string
{
    return gallery_lightbox_browsing_mode_normalize(app_setting('theme_lightbox_browsing_mode', 'single'), 'single');
}

/**
 * Normalize a per-gallery override for database storage.
 *
 * A null return value means the gallery inherits the global Theme setting. This
 * mirrors description-layout and count-badge persistence instead of storing a
 * separate boolean flag.
 */
function gallery_lightbox_browsing_mode_storage_value(mixed $value): ?string
{
    // $mode stores the raw form or gallery.json value before inheritance is resolved.
    $mode = strtolower(trim((string) $value));
    if ($mode === '' || $mode === 'inherit') {
        return null;
    }
    // Convert the original strip storage value to the final public value before any new write.
    if ($mode === 'strip') {
        return 'picture_strip';
    }
    return in_array($mode, gallery_lightbox_browsing_mode_options(), true) ? $mode : null;
}

/**
 * Resolve the mode that should be emitted to the public lightbox for one gallery.
 *
 * Resolution order:
 * 1. The gallery row, when the migration exists and an explicit override is set.
 * 2. The global Theme default stored in app_settings.
 * 3. The legacy single-image mode as a hard fallback.
 */
function gallery_effective_lightbox_browsing_mode(array $gallery): string
{
    if (gallery_lightbox_browsing_mode_schema_ready()) {
        // $storedMode stores the optional gallery-level override. NULL means inherit.
        $storedMode = gallery_lightbox_browsing_mode_storage_value($gallery['lightbox_browsing_mode'] ?? null);
        if ($storedMode !== null) {
            return $storedMode;
        }
    }
    return theme_lightbox_browsing_mode();
}

/**
 * Return a translated label for one public lightbox browsing mode.
 */
function gallery_lightbox_browsing_mode_label(string $mode): string
{
    return match (gallery_lightbox_browsing_mode_normalize($mode)) {
        'picture_strip' => t('gallery.lightbox_mode.picture_strip', 'Picture strip'),
        '3d_carousel' => t('gallery.lightbox_mode.3d_carousel', '3D carousel'),
        default => t('gallery.lightbox_mode.single', 'Single image'),
    };
}

/**
 * Return a translated label for one gallery override select option.
 */
function gallery_lightbox_browsing_mode_override_label(string $value): string
{
    if ($value === 'inherit') {
        return t('gallery.lightbox_mode.inherit', 'Inherit from Theme');
    }
    return gallery_lightbox_browsing_mode_label($value);
}

/**
 * Return a readable summary of the current lightbox mode source for Admin forms.
 */
function gallery_lightbox_browsing_mode_source_label(array $gallery): string
{
    if (gallery_lightbox_browsing_mode_schema_ready() && gallery_lightbox_browsing_mode_storage_value($gallery['lightbox_browsing_mode'] ?? null) !== null) {
        return t('admin.gallery_editor.lightbox_mode_source_gallery', 'gallery override');
    }
    return t('admin.gallery_editor.lightbox_mode_source_theme', 'Theme default');
}
