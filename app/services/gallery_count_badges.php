<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_count_badges.php
 * Module Type: Service
 *
 * Purpose:
 *   Resolves public gallery-card contained-picture count badge visibility.
 *
 * Responsibilities:
 *   - Keep the global Theme default separate from optional per-gallery overrides
 *   - Normalize count badge values before they reach templates or database writes
 *   - Provide admin-facing labels for the Theme and gallery editor forms
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
 *   2026-05-12
 */

declare(strict_types=1);

/**
 * Return true when per-gallery count badge overrides can be stored.
 */
function gallery_count_badge_schema_ready(): bool
{
    return db_column_exists('galleries', 'count_badge_visibility');
}

/**
 * Return the override values supported on one gallery row.
 */
function gallery_count_badge_override_values(): array
{
    return ['inherit', 'show', 'hide'];
}

/**
 * Normalize the global Theme default. The feature is enabled by default.
 */
function theme_gallery_count_badge_enabled(): bool
{
    return app_setting('theme_gallery_count_badge_enabled', '1') !== '0';
}

/**
 * Normalize a per-gallery override for database storage.
 *
 * A null return value means the gallery inherits the global Theme setting.
 */
function gallery_count_badge_storage_value(mixed $value): ?string
{
    // $visibility stores the raw form or sidecar value before validation.
    $visibility = strtolower(trim((string) $value));
    if ($visibility === '' || $visibility === 'inherit') {
        return null;
    }
    return in_array($visibility, ['show', 'hide'], true) ? $visibility : null;
}

/**
 * Return whether one gallery card should show the contained-picture badge.
 */
function gallery_effective_count_badge_enabled(array $gallery): bool
{
    if (gallery_count_badge_schema_ready()) {
        // $storedVisibility stores the optional per-gallery override.
        $storedVisibility = gallery_count_badge_storage_value($gallery['count_badge_visibility'] ?? null);
        if ($storedVisibility !== null) {
            return $storedVisibility === 'show';
        }
    }
    return theme_gallery_count_badge_enabled();
}

/**
 * Return a translated label for one override select option.
 */
function gallery_count_badge_override_label(string $value): string
{
    return match ($value) {
        'show' => t('gallery.count_badge.show', 'Show'),
        'hide' => t('gallery.count_badge.hide', 'Hide'),
        default => t('gallery.count_badge.inherit', 'Inherit from Theme'),
    };
}

/**
 * Return a translated label for the effective public badge state.
 */
function gallery_count_badge_state_label(bool $enabled): string
{
    return $enabled ? t('gallery.count_badge.shown', 'shown') : t('gallery.count_badge.hidden', 'hidden');
}

/**
 * Return a readable summary of the current count badge source for Admin forms.
 */
function gallery_count_badge_source_label(array $gallery): string
{
    if (gallery_count_badge_schema_ready() && gallery_count_badge_storage_value($gallery['count_badge_visibility'] ?? null) !== null) {
        return t('admin.gallery_editor.count_badge_source_gallery', 'gallery override');
    }
    return t('admin.gallery_editor.count_badge_source_theme', 'Theme default');
}
