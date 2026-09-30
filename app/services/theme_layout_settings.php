<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/theme_layout_settings.php
 * Module Type: Service
 *
 * Purpose:
 *   Owns the bounded scalar Theme layout settings shared by the Theme editor and Setup Wizard.
 *
 * Responsibilities:
 *   - Expose normalized current values for safe layout, grid, card, hero-tag, lightbox, and GPS controls
 *   - Validate one explicitly allowlisted Theme layout scalar without accepting arbitrary setting keys
 *   - Persist through the existing app-settings owner while preserving public-content revision side effects
 *   - Report capability-gated availability for optional Theme controls
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
 *   - Uploads, credentials, raw CSS, favorite-gallery selectors, and destructive actions are intentionally excluded.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;

/**
 * Return normalized scalar Theme layout values that are safe to stage outside the specialized Theme page.
 *
 * @return array<string,string> Current values keyed by canonical setting id.
 */
function theme_layout_safe_settings(): array
{
    $theme = theme_settings();
    $pagination = pagination_global_settings();
    $homeGrid = main_page_gallery_grid_settings();
    $tagGrid = tag_page_gallery_grid_settings();

    return [
        'theme_gps_pin_enabled' => ((string) ($theme['gps_pin_enabled'] ?? '1')) !== '0' ? '1' : '0',
        'theme_gps_pin_background_enabled' => ((string) ($theme['gps_pin_background_enabled'] ?? '1')) !== '0' ? '1' : '0',
        'theme_gps_pin_size' => (string) theme_gps_pin_size_value($theme['gps_pin_size'] ?? null),
        'theme_gps_pin_background_size' => (string) theme_gps_pin_background_size_value($theme['gps_pin_background_size'] ?? null),
        'theme_gallery_description_layout' => theme_gallery_description_layout(),
        'theme_gallery_count_badge_enabled' => theme_gallery_count_badge_enabled() ? '1' : '0',
        'pagination_enabled' => !empty($pagination['enabled']) ? '1' : '0',
        'pagination_columns' => (string) ($pagination['columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS),
        'pagination_rows' => (string) ($pagination['rows'] ?? CMS_PAGINATION_DEFAULT_ROWS),
        'home_gallery_grid_columns' => (string) ($homeGrid['columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS),
        'home_gallery_grid_rows' => (string) ($homeGrid['rows'] ?? CMS_PAGINATION_DEFAULT_ROWS),
        'tag_page_gallery_grid_columns' => (string) ($tagGrid['columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS),
        'tag_page_gallery_grid_rows' => (string) ($tagGrid['rows'] ?? CMS_PAGINATION_DEFAULT_ROWS),
        'tag_page_gallery_description_layout' => tag_page_gallery_description_layout(),
        'theme_hero_tag_visible_limit' => (string) theme_hero_tag_visible_limit(),
        'theme_hero_tag_display_all' => theme_hero_tag_display_all_enabled() ? '1' : '0',
        'theme_hero_tag_scrollbar_enabled' => theme_hero_tag_scrollbar_enabled() ? '1' : '0',
        'theme_hero_tag_scrollbar_rows' => (string) theme_hero_tag_scrollbar_rows(),
        'theme_hero_tag_sort_mode' => theme_hero_tag_sort_mode(),
        'theme_lightbox_browsing_mode' => theme_lightbox_browsing_mode(),
    ];
}

/**
 * Return whether one safe Theme layout scalar is currently available to edit.
 *
 * Optional capability switches are evaluated by effective state so a disabled or
 * unknown capability cannot be staged and later persisted by the wizard.
 *
 * @param string $id Canonical safe Theme layout setting id.
 * @return bool True when the control may be edited in the current runtime.
 */
function theme_layout_safe_setting_available(string $id): bool
{
    if (in_array($id, [
        'theme_gps_pin_enabled',
        'theme_gps_pin_background_enabled',
        'theme_gps_pin_size',
        'theme_gps_pin_background_size',
    ], true)) {
        return !function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled')
            || feature_capability_effective_enabled('gallery_maps');
    }
    if ($id === 'theme_lightbox_browsing_mode') {
        return !function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled')
            || feature_capability_effective_enabled('lightbox_modes');
    }
    return array_key_exists($id, theme_layout_safe_settings());
}

/**
 * Normalize one explicitly allowlisted Theme layout scalar.
 *
 * @param string $id Canonical safe Theme layout setting id.
 * @param scalar|array<array-key,mixed>|object|null $value Submitted value, including invalid types to reject.
 * @return non-empty-string Canonical persisted value.
 */
function theme_layout_safe_normalize(string $id, mixed $value): string
{
    if (!array_key_exists($id, theme_layout_safe_settings())) {
        throw new InvalidArgumentException('Unknown Theme layout setting.');
    }
    if (!is_scalar($value) || is_float($value)) {
        throw new InvalidArgumentException('Enter a valid Theme layout value.');
    }

    if (in_array($id, [
        'theme_gps_pin_enabled',
        'theme_gps_pin_background_enabled',
        'theme_gallery_count_badge_enabled',
        'pagination_enabled',
        'theme_hero_tag_display_all',
        'theme_hero_tag_scrollbar_enabled',
    ], true)) {
        if ($value === true || $value === 1 || $value === '1') {
            return '1';
        }
        if ($value === false || $value === 0 || $value === '0') {
            return '0';
        }
        throw new InvalidArgumentException('Choose enabled or disabled.');
    }

    $text = trim((string) $value);
    if ($id === 'theme_gps_pin_size') {
        if (filter_var($text, FILTER_VALIDATE_INT) === false || (int) $text < 14 || (int) $text > 48) {
            throw new InvalidArgumentException('Choose a GPS pin size from 14 to 48 pixels.');
        }
        return (string) (int) $text;
    }
    if ($id === 'theme_gps_pin_background_size') {
        if (filter_var($text, FILTER_VALIDATE_INT) === false || (int) $text < 0 || (int) $text > 48) {
            throw new InvalidArgumentException('Choose a GPS pin background size from 0 to 48 pixels.');
        }
        return (string) (int) $text;
    }
    if (in_array($id, ['theme_gallery_description_layout', 'tag_page_gallery_description_layout'], true)) {
        if (!in_array($text, gallery_description_layout_options(), true)) {
            throw new InvalidArgumentException('Choose a supported gallery-card layout.');
        }
        return $text;
    }
    if (in_array($id, ['pagination_columns', 'home_gallery_grid_columns', 'tag_page_gallery_grid_columns'], true)) {
        if (filter_var($text, FILTER_VALIDATE_INT) === false || (int) $text < 1 || (int) $text > CMS_PAGINATION_MAX_COLUMNS) {
            throw new InvalidArgumentException('Choose a supported grid column count.');
        }
        return (string) (int) $text;
    }
    if (in_array($id, ['pagination_rows', 'home_gallery_grid_rows', 'tag_page_gallery_grid_rows'], true)) {
        if (filter_var($text, FILTER_VALIDATE_INT) === false || (int) $text < 1 || (int) $text > CMS_PAGINATION_MAX_ROWS) {
            throw new InvalidArgumentException('Choose a supported grid row count.');
        }
        return (string) (int) $text;
    }
    if ($id === 'theme_hero_tag_visible_limit') {
        if (filter_var($text, FILTER_VALIDATE_INT) === false || (int) $text < 1 || (int) $text > 200) {
            throw new InvalidArgumentException('Choose a hero-tag visible limit from 1 to 200.');
        }
        return (string) (int) $text;
    }
    if ($id === 'theme_hero_tag_scrollbar_rows') {
        if (filter_var($text, FILTER_VALIDATE_INT) === false || (int) $text < 1 || (int) $text > 12) {
            throw new InvalidArgumentException('Choose a hero-tag scrollbar threshold from 1 to 12 rows.');
        }
        return (string) (int) $text;
    }
    if ($id === 'theme_hero_tag_sort_mode') {
        if (!in_array($text, ['usage', 'alphabetical'], true)) {
            throw new InvalidArgumentException('Choose a supported hero-tag sort mode.');
        }
        return $text;
    }
    if ($id === 'theme_lightbox_browsing_mode') {
        if (!theme_layout_safe_setting_available($id) || !in_array($text, gallery_lightbox_browsing_mode_options(), true)) {
            throw new InvalidArgumentException('Choose a supported lightbox browsing mode.');
        }
        return $text;
    }

    throw new InvalidArgumentException('Unknown Theme layout setting.');
}

/**
 * Persist one normalized safe Theme layout scalar through its canonical owner path.
 *
 * @param string $id Canonical safe Theme layout setting id.
 * @param scalar $value Submitted scalar value.
 * @return void
 */
function theme_layout_safe_save(string $id, mixed $value): void
{
    if (!theme_layout_safe_setting_available($id)) {
        throw new InvalidArgumentException('Theme layout setting is unavailable.');
    }
    $normalized = theme_layout_safe_normalize($id, $value);

    if ($id === 'theme_gallery_description_layout') {
        $previous = theme_gallery_description_layout();
        set_app_setting($id, $normalized);
        if ($normalized !== $previous) {
            set_app_setting('theme_public_content_revision', (string) time());
        }
        return;
    }
    if ($id === 'theme_lightbox_browsing_mode') {
        $previous = theme_lightbox_browsing_mode();
        set_app_setting($id, $normalized);
        if ($normalized !== $previous) {
            set_app_setting('theme_public_content_revision', (string) time());
        }
        return;
    }
    if (str_starts_with($id, 'theme_hero_tag_')) {
        $previous = match ($id) {
            'theme_hero_tag_visible_limit' => (string) theme_hero_tag_visible_limit(),
            'theme_hero_tag_display_all' => theme_hero_tag_display_all_enabled() ? '1' : '0',
            'theme_hero_tag_scrollbar_enabled' => theme_hero_tag_scrollbar_enabled() ? '1' : '0',
            'theme_hero_tag_scrollbar_rows' => (string) theme_hero_tag_scrollbar_rows(),
            'theme_hero_tag_sort_mode' => theme_hero_tag_sort_mode(),
            default => throw new InvalidArgumentException('Unknown hero-tag setting.'),
        };
        set_app_setting($id, $normalized);
        if ($normalized !== $previous) {
            set_app_setting('theme_public_content_revision', (string) time());
        }
        return;
    }

    set_app_setting($id, $normalized);
}
