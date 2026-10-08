<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/theme.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   2026-10-03
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;

/**
 * Theme setting service helpers.
 *
 * This module owns normalized site-level visual settings and lightweight public
 * presentation policy, including hero-tag disclosure defaults. Runtime assets
 * such as favicon files, custom CSS files, and background images stay in their
 * own services so path handling remains easy to audit after the services.php split.
 */

/**
 * Theme settings are stored in the DB so the visual preset can be changed
 * without editing PHP or CSS files.
 *
 * @return array<string,string|int|bool> Theme values keyed by setting name, with normalized layout dimensions and switches.
 */
function theme_settings(): array
{
    // $defaults stores an intermediate value used by the surrounding gallery workflow.
    $defaults = theme_css_defaults();
    return array_merge([
        'accent' => app_setting('theme_accent', $defaults['accent']),
        'accent_dark' => app_setting('theme_accent_dark', $defaults['accent_dark']),
        'paper' => app_setting('theme_paper', $defaults['paper']),
        'panel' => app_setting('theme_panel', $defaults['panel']),
        'gallery_panel' => app_setting('theme_gallery_panel', $defaults['gallery_panel']),
        'header_text' => app_setting('theme_header_text', '#0f172a'),
        'hero_text' => app_setting('theme_hero_text', '#0f172a'),
        'background_opacity' => app_setting('theme_background_opacity', '65'),
        'background_path' => app_setting('theme_background_path', ''),
        'background_original_path' => app_setting('theme_background_original_path', ''),
        'background_optimized_path' => app_setting('theme_background_optimized_path', ''),
        'background_optimized_max_side' => app_setting('theme_background_optimized_max_side', '1920'),
        'gps_pin_enabled' => app_setting('theme_gps_pin_enabled', '1'),
        'gps_pin_background_enabled' => app_setting('theme_gps_pin_background_enabled', '1'),
        'gps_pin_size' => app_setting('theme_gps_pin_size', '26'),
        'gps_pin_background_size' => app_setting('theme_gps_pin_background_size', '22'),
        'radius' => app_setting('theme_radius', $defaults['radius']),
        'font' => app_setting('theme_font', $defaults['font']),
        'page_width' => theme_page_width_mode((string) app_setting('theme_page_width', 'default')),
        'page_width_custom' => theme_page_width_custom_value(app_setting('theme_page_width_custom')),
        'branding_separator_width' => theme_branding_separator_width_value(app_setting('theme_branding_separator_width')),
        'branding_separator_height' => theme_branding_separator_height_value(app_setting('theme_branding_separator_height')),
        'branding_separator_stretch' => theme_branding_separator_stretch_enabled(app_setting('theme_branding_separator_stretch')),
        'gallery_description_layout' => function_exists('Gallery\\Services\\theme_gallery_description_layout') ? theme_gallery_description_layout() : 'vertical',
        'gallery_count_badge_enabled' => !function_exists('Gallery\\Services\\theme_gallery_count_badge_enabled') || theme_gallery_count_badge_enabled() ? '1' : '0',
        'lightbox_browsing_mode' => function_exists('Gallery\\Services\\theme_lightbox_browsing_mode') ? theme_lightbox_browsing_mode() : 'single',
        'gallery_info_motion_ms' => (string) theme_gallery_info_motion_ms(),
        'admin_side_panel_motion_ms' => (string) theme_admin_side_panel_motion_ms(),
    ], theme_advanced_appearance_settings());
}

/**
 * Describe the bounded public appearance controls owned by Theme.
 * @return array<string,array{default:string,min:int,max:int,unit:string}> Stable keys and their persisted defaults and numeric limits; selectors use zero numeric bounds.
 */
function theme_advanced_appearance_definitions(): array
{
    // Keep the original 1rem gap/padding, existing elevation, 100% type and existing header backdrop by default.
    // Type: array<string,array{default:string,min:int,max:int,unit:string}>.
    // Units: CSS pixels for spacing, percent for type, named presets and boolean digits for selectors.
    // Scope: public Theme appearance only. Consumers: normalization and the Appearance editor.
    // Rationale: 0-64px gaps, 0-48px padding and 80-140% type allow useful adjustments without unbounded layouts.
    return [
        'gallery_grid_gap' => ['default' => '16', 'min' => 0, 'max' => 64, 'unit' => 'px'],
        'gallery_card_padding' => ['default' => '16', 'min' => 0, 'max' => 48, 'unit' => 'px'],
        'card_shadow' => ['default' => 'default', 'min' => 0, 'max' => 0, 'unit' => ''],
        'public_type_scale' => ['default' => '100', 'min' => 80, 'max' => 140, 'unit' => '%'],
        'header_transparent' => ['default' => '0', 'min' => 0, 'max' => 1, 'unit' => ''],
    ];
}

/**
 * Normalize one advanced public appearance value with a safe default for corrupt storage.
 * @param string $key Canonical short Theme key from the advanced registry.
 * @param string|null $value Stored or controller-validated scalar submission; null means unset.
 * @return string Canonical bounded integer, shadow preset or boolean digit; throws for an unknown key.
 */
function theme_advanced_appearance_value(string $key, ?string $value): string
{
    $definition = theme_advanced_appearance_definitions()[$key] ?? null;
    if ($definition === null) {
        throw new InvalidArgumentException('Unknown advanced appearance setting.');
    }
    $value = trim($value ?? '');
    if ($key === 'card_shadow') {
        return in_array($value, ['default', 'none', 'soft', 'raised'], true) ? $value : $definition['default'];
    }
    if ($key === 'header_transparent') {
        return $value === '1' ? '1' : '0';
    }
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        return $definition['default'];
    }
    return (string) max($definition['min'], min($definition['max'], (int) $value));
}

/**
 * Resolve normalized advanced appearance state without changing existing preferences.
 * @return array<string,string> Canonical short keys mapped to safe persisted/default values.
 */
function theme_advanced_appearance_settings(): array
{
    $settings = [];
    foreach (theme_advanced_appearance_definitions() as $key => $definition) {
        $settings[$key] = theme_advanced_appearance_value($key, app_setting('theme_' . $key));
    }
    return $settings;
}

/**
 * Save only explicitly supplied advanced settings through the existing settings owner.
 * @param array<string,string> $values Controller-validated scalar values keyed by canonical short name.
 * @return void Persists normalized values independently of installed CSS and manual overrides.
 */
function theme_advanced_appearance_save(array $values): void
{
    $normalized = [];
    foreach ($values as $key => $value) {
        $normalized[$key] = theme_advanced_appearance_value($key, $value);
    }
    foreach ($normalized as $key => $value) {
        set_app_setting('theme_' . $key, $value);
    }
}

/**
 * Restore one advanced control without clearing any other appearance or stylesheet state.
 * @param string $key Canonical advanced key selected by the controller.
 * @return void Deletes only that persisted preference; unknown keys are refused.
 */
function theme_advanced_appearance_reset(string $key): void
{
    theme_advanced_appearance_value($key, null);
    delete_app_settings(['theme_' . $key]);
}

/**
 * Generate scoped public rules only for advanced values that differ from the historic appearance.
 * @param array<string,string|int|bool> $settings Normalized Theme state containing the advanced short keys.
 * @return string Ordinary cascade rules leaving Admin, hero backdrops and special viewers untouched.
 */
function theme_advanced_appearance_css(array $settings): string
{
    $css = '';
    foreach (['gallery_grid_gap', 'gallery_card_padding'] as $key) {
        $value = theme_advanced_appearance_value($key, isset($settings[$key]) ? (string) $settings[$key] : null);
        if ($value !== '16') {
            $variable = $key === 'gallery_grid_gap' ? '--public-gallery-grid-gap' : '--public-gallery-card-padding';
            $css .= '.public-page{' . $variable . ':' . $value . 'px;}';
            $css .= $key === 'gallery_grid_gap'
                ? '.public-page .site-main .grid{gap:var(--public-gallery-grid-gap);}'
                : '.public-page .site-main .gallery-card .gallery-card-body{padding:var(--public-gallery-card-padding);}';
        }
    }
    $shadow = theme_advanced_appearance_value('card_shadow', isset($settings['card_shadow']) ? (string) $settings['card_shadow'] : null);
    $shadows = ['none' => 'none', 'soft' => '0 4px 12px rgba(54,38,20,.08)', 'raised' => '0 18px 42px rgba(54,38,20,.18)'];
    if (isset($shadows[$shadow])) {
        $css .= '.public-page{--public-card-shadow:' . $shadows[$shadow] . ';}';
        $css .= '.public-page .gallery-card,.public-page .image-card,.public-page .gallery-card:hover,.public-page .gallery-card:focus-within{box-shadow:var(--public-card-shadow);}';
    }
    $scale = theme_advanced_appearance_value('public_type_scale', isset($settings['public_type_scale']) ? (string) $settings['public_type_scale'] : null);
    if ($scale !== '100') {
        $factor = number_format((int) $scale / 100, 2, '.', '');
        $css .= '.public-page .site-main{--public-type-scale:' . $factor . ';font-size:calc(var(--type-body-size,1rem) * var(--public-type-scale));}';
        $css .= '.public-page .hero h1{font-size:calc(clamp(2.05rem,3.6vw,3.2rem) * var(--public-type-scale,1));}';
        $css .= '.public-page .hero p{font-size:calc(.95rem * var(--public-type-scale,1));}';
        // Match the existing orientation rules so scaling preserves their distinct title/copy hierarchy.
        $css .= '.public-page .site-main .gallery-card.is-gallery-description-horizontal .gallery-card-body h2{font-size:calc(clamp(1.15rem,1.6vw,1.45rem) * var(--public-type-scale,1));}';
        $css .= '.public-page .site-main .gallery-card.is-gallery-description-horizontal .gallery-card-description{font-size:calc(.95rem * var(--public-type-scale,1));}';
        $css .= '@media(max-width:760px){.public-page .hero h1{font-size:calc(clamp(1.8rem,9vw,2.5rem) * var(--public-type-scale,1));}}';
    }
    if (($settings['header_transparent'] ?? '0') === '1') {
        $css .= '.public-page .site-header{background:transparent;background-image:none;backdrop-filter:none;-webkit-backdrop-filter:none;border-color:transparent;box-shadow:none;}';
        $css .= '.public-page .site-header::before,.public-page .site-header::after{content:none;background:none;box-shadow:none;backdrop-filter:none;-webkit-backdrop-filter:none;}';
        $css .= '.public-page .site-header .brand{text-shadow:0 0 3px var(--paper),0 1px 2px var(--paper);}';
    }
    return $css;
}

/**
 * Normalize a public gallery-card information-panel animation duration.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $value Stored or submitted duration in milliseconds; invalid types use the default.
 * @return int Duration clamped to the supported 0-800 ms range.
 */
function theme_gallery_info_motion_ms_value(mixed $value): int
{
    if (!is_scalar($value) || is_bool($value)) {
        return 320;
    }
    $value = trim((string) $value);
    if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
        return 320;
    }
    return max(0, min(800, (int) $value));
}

/**
 * Return the configured public gallery-card information-panel animation duration.
 *
 * @return int Duration in milliseconds, defaulting to 320 ms.
 */
function theme_gallery_info_motion_ms(): int
{
    return theme_gallery_info_motion_ms_value(app_setting('theme_gallery_info_motion_ms', '320'));
}

/**
 * Normalize the Admin side-panel animation duration.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $value Stored or submitted duration in milliseconds; invalid types use the default.
 * @return int Duration clamped to the supported 0-800 ms range.
 */
function theme_admin_side_panel_motion_ms_value(mixed $value): int
{
    if (!is_scalar($value) || is_bool($value)) {
        return 260;
    }
    $value = trim((string) $value);
    if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
        return 260;
    }
    return max(0, min(800, (int) $value));
}

/**
 * Return the configured Admin side-panel animation duration.
 *
 * @return int Duration in milliseconds, defaulting to 260 ms.
 */
function theme_admin_side_panel_motion_ms(): int
{
    return theme_admin_side_panel_motion_ms_value(app_setting('theme_admin_side_panel_motion_ms', '260'));
}

/**
 * Return the safe scalar Theme settings that may be edited outside the full Theme form.
 *
 * File uploads, custom CSS, background processing, and other compound Theme actions
 * deliberately remain on the specialized Theme page.
 *
 * @return array<string,string> Normalized basic appearance values keyed by stable setting id.
 */
function theme_basic_appearance_settings(): array
{
    $theme = theme_settings();
    return [
        'theme_accent' => (string) ($theme['accent'] ?? '#a5481c'),
        'theme_accent_dark' => (string) ($theme['accent_dark'] ?? '#713414'),
        'theme_paper' => (string) ($theme['paper'] ?? '#f8f4ec'),
        'theme_panel' => (string) ($theme['panel'] ?? '#fffaf0'),
        'theme_gallery_panel' => (string) ($theme['gallery_panel'] ?? '#fffaf0'),
        'theme_header_text' => (string) ($theme['header_text'] ?? '#0f172a'),
        'theme_hero_text' => (string) ($theme['hero_text'] ?? '#0f172a'),
        'theme_radius' => (string) ($theme['radius'] ?? '16'),
        'theme_font' => (string) ($theme['font'] ?? 'serif'),
        'theme_page_width' => (string) ($theme['page_width'] ?? 'default'),
        'theme_page_width_custom' => (string) ($theme['page_width_custom'] ?? '1440'),
    ];
}

/**
 * Normalize one basic Theme appearance scalar through the Theme domain owner.
 *
 * @param string $id Stable basic Theme setting id.
 * @param scalar|array<array-key,mixed>|object|null $value Submitted value, including invalid non-scalars to reject.
 * @return non-empty-string Canonical persisted value.
 */
function theme_basic_appearance_normalize(string $id, mixed $value): string
{
    if (!is_scalar($value) || is_bool($value)) {
        throw new InvalidArgumentException('Enter a valid appearance value.');
    }
    $value = trim((string) $value);
    if (in_array($id, [
        'theme_accent',
        'theme_accent_dark',
        'theme_paper',
        'theme_panel',
        'theme_gallery_panel',
        'theme_header_text',
        'theme_hero_text',
    ], true)) {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            throw new InvalidArgumentException('Enter a six-digit hexadecimal color.');
        }
        return strtolower($value);
    }
    if ($id === 'theme_radius') {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 32) {
            throw new InvalidArgumentException('Choose a corner radius from 0 to 32 pixels.');
        }
        return (string) (int) $value;
    }
    if ($id === 'theme_font') {
        if (!in_array($value, ['serif', 'sans'], true)) {
            throw new InvalidArgumentException('Choose a supported font style.');
        }
        return $value;
    }
    if ($id === 'theme_page_width') {
        if (!in_array($value, ['default', 'wide', 'custom', 'full'], true)) {
            throw new InvalidArgumentException('Choose a supported page-width mode.');
        }
        return $value;
    }
    if ($id === 'theme_page_width_custom') {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1024 || (int) $value > 2048) {
            throw new InvalidArgumentException('Choose a custom page width from 1024 to 2048 pixels.');
        }
        return (string) (int) $value;
    }
    throw new InvalidArgumentException('Unknown basic appearance setting.');
}

/**
 * Persist one normalized basic appearance scalar through the Theme storage owner.
 *
 * @param string $id Stable basic Theme setting id.
 * @param scalar $value Submitted scalar value.
 * @return void
 */
function theme_basic_appearance_save(string $id, mixed $value): void
{
    $normalized = theme_basic_appearance_normalize($id, $value);
    set_app_setting($id, $normalized);
}

/**
 * Return only DB-backed theme overrides.
 *
 * @return array<string,non-empty-string> Nonempty persisted theme overrides keyed by setting name.
 */
function theme_override_settings(): array
{
    // $settings stores an intermediate value used by the surrounding gallery workflow.
    $settings = [
        'accent' => app_setting('theme_accent'),
        'accent_dark' => app_setting('theme_accent_dark'),
        'paper' => app_setting('theme_paper'),
        'panel' => app_setting('theme_panel'),
        'gallery_panel' => app_setting('theme_gallery_panel'),
        'header_text' => app_setting('theme_header_text'),
        'hero_text' => app_setting('theme_hero_text'),
        'background_opacity' => app_setting('theme_background_opacity'),
        'background_path' => app_setting('theme_background_path'),
        'background_original_path' => app_setting('theme_background_original_path'),
        'background_optimized_path' => app_setting('theme_background_optimized_path'),
        'background_optimized_max_side' => app_setting('theme_background_optimized_max_side'),
        'gps_pin_enabled' => app_setting('theme_gps_pin_enabled'),
        'gps_pin_background_enabled' => app_setting('theme_gps_pin_background_enabled'),
        'gps_pin_size' => app_setting('theme_gps_pin_size'),
        'gps_pin_background_size' => app_setting('theme_gps_pin_background_size'),
        'radius' => app_setting('theme_radius'),
        'font' => app_setting('theme_font'),
        'page_width' => app_setting('theme_page_width'),
        'page_width_custom' => app_setting('theme_page_width_custom'),
        'branding_separator_width' => app_setting('theme_branding_separator_width'),
        'branding_separator_height' => app_setting('theme_branding_separator_height'),
        'branding_separator_stretch' => app_setting('theme_branding_separator_stretch'),
        'gallery_description_layout' => app_setting('theme_gallery_description_layout'),
        'gallery_count_badge_enabled' => app_setting('theme_gallery_count_badge_enabled'),
        'lightbox_browsing_mode' => app_setting('theme_lightbox_browsing_mode'),
        'gallery_info_motion_ms' => app_setting('theme_gallery_info_motion_ms'),
        'admin_side_panel_motion_ms' => app_setting('theme_admin_side_panel_motion_ms'),
    ];
    return array_filter($settings, static fn (?string $value): bool => $value !== null && $value !== '');
}

/**
 * Remove saved slider/font overrides so the active CSS skin becomes the source.
 *
 * Page width is intentionally not deleted here. It is a structural layout
 * preference rather than a color/font skin override, and normal Theme saves can
 * legitimately combine a CSS skin with a wider or full-width public layout.
 * The custom-width pixel value is kept for the same reason, and also so users
 * can switch between presets without losing their tuned custom width.
 */
function clear_theme_overrides(): void
{
    delete_app_settings([
        'theme_accent',
        'theme_accent_dark',
        'theme_paper',
        'theme_panel',
        'theme_gallery_panel',
        'theme_header_text',
        'theme_hero_text',
        'theme_background_opacity',
        'theme_background_source',
        'theme_gps_pin_enabled',
        'theme_gps_pin_background_enabled',
        'theme_gps_pin_size',
        'theme_gps_pin_background_size',
        'theme_radius',
        'theme_font',
    ]);
}

/**
 * Normalize the GPS pin size used in the public image overlay.
 *
 * @param mixed $value Value to process.
 * @return int Integer result for the caller.
 */
function theme_gps_pin_size_value(mixed $value): int
{
    $size = (int) $value;
    if ($size <= 0) {
        return 26;
    }
    return max(14, min(48, $size));
}

/**
 * Normalize the GPS pin background size used in the public image overlay.
 *
 * @param mixed $value Value to process.
 * @return int Integer result for the caller.
 */
function theme_gps_pin_background_size_value(mixed $value): int
{
    $size = (int) $value;
    if ($size <= 0) {
        return 22;
    }
    return max(0, min(48, $size));
}

/**
 * Normalize the configured public page-width mode to one of the supported layout presets.
 *
 * @param string $value Value to process.
 * @return string Text result for the caller.
 */
function theme_page_width_mode(string $value): string
{
    // $mode stores the trimmed user or database value before it is compared with supported presets.
    $mode = trim($value);
    return in_array($mode, ['default', 'wide', 'full', 'custom'], true) ? $mode : 'default';
}

/**
 * Normalize the custom public page-width value used when the Custom preset is selected.
 *
 * The Admin form allows direct number input, so the service clamps everything
 * server-side before the value reaches generated CSS. This keeps the final CSS
 * predictable even if a browser bypasses the slider limits.
 *
 * @param mixed $value Value to process.
 * @return int Integer result for the caller.
 */
function theme_page_width_custom_value(mixed $value): int
{
    // $width stores the requested custom container width in pixels before clamping.
    $width = (int) $value;
    if ($width <= 0) {
        return 1440;
    }
    return max(1024, min(2048, $width));
}


/**
 * Normalize the number of hero tags shown before the browser offers expansion.
 *
 * @param mixed $value Value to process.
 * @return int Integer result for the caller.
 */
function theme_hero_tag_visible_limit_value(mixed $value): int
{
    // $limit stores the requested number of initially visible tags before clamping.
    $limit = (int) $value;
    if ($limit <= 0) {
        return 20;
    }
    return max(1, min(200, $limit));
}

/**
 * Return the configured initial hero-tag limit.
 *
 * @return int Integer result for the caller.
 */
function theme_hero_tag_visible_limit(): int
{
    return theme_hero_tag_visible_limit_value(app_setting('theme_hero_tag_visible_limit', '20'));
}

/**
 * Return whether public gallery heroes should expose every tag immediately.
 *
 * @return bool True when the hero should not collapse its tag collection.
 */
function theme_hero_tag_display_all_enabled(): bool
{
    return ((string) app_setting('theme_hero_tag_display_all', '0')) === '1';
}

/**
 * Return whether long public hero tag collections may become internally scrollable.
 *
 * @return bool True when row-based scrollbar activation is allowed.
 */
function theme_hero_tag_scrollbar_enabled(): bool
{
    return ((string) app_setting('theme_hero_tag_scrollbar_enabled', '1')) !== '0';
}

/**
 * Normalize the visible-row threshold used before hero-tag scrolling is enabled.
 *
 * @param mixed $value Value to process.
 * @return int Integer result for the caller.
 */
function theme_hero_tag_scrollbar_rows_value(mixed $value): int
{
    // $rows stores the requested number of unscrolled visual rows before clamping.
    $rows = (int) $value;
    if ($rows <= 0) {
        return 5;
    }
    return max(1, min(12, $rows));
}

/**
 * Return the configured hero-tag scrollbar row threshold.
 *
 * @return int Integer result for the caller.
 */
function theme_hero_tag_scrollbar_rows(): int
{
    return theme_hero_tag_scrollbar_rows_value(app_setting('theme_hero_tag_scrollbar_rows', '5'));
}

/**
 * Normalize the public hero-tag sorting mode.
 *
 * Supported modes are usage, which sorts highest assignment count first, and
 * alphabetical. Unsupported or empty values intentionally fall back to usage.
 *
 * @param mixed $value Value to process.
 * @return string Text result for the caller.
 */
function theme_hero_tag_sort_mode_normalize(mixed $value): string
{
    // $mode stores the trimmed submitted or database value before validation.
    $mode = trim((string) $value);
    return in_array($mode, ['usage', 'alphabetical'], true) ? $mode : 'usage';
}

/**
 * Return the configured public hero-tag sorting mode.
 *
 * @return string Text result for the caller.
 */
function theme_hero_tag_sort_mode(): string
{
    return theme_hero_tag_sort_mode_normalize(app_setting('theme_hero_tag_sort_mode', 'usage'));
}

/**
 * Normalize the optional public header separator width override.
 *
 * A value of 0 keeps the current responsive page-width behavior. Positive
 * values constrain the separator container to a fixed pixel width while still
 * allowing it to shrink on narrow screens.
 *
 * @param mixed $value Value to process.
 * @return int Integer result for the caller.
 */
function theme_branding_separator_width_value(mixed $value): int
{
    // $width stores the requested public header separator width before clamping.
    $width = (int) $value;
    if ($width <= 0) {
        return 0;
    }
    return max(160, min(3840, $width));
}

/**
 * Normalize the public header separator image height limit.
 *
 * @param mixed $value Value to process.
 * @return int Integer result for the caller.
 */
function theme_branding_separator_height_value(mixed $value): int
{
    // $height stores the requested public header separator image height before clamping.
    $height = (int) $value;
    if ($height <= 0) {
        return 72;
    }
    return max(8, min(512, $height));
}

/**
 * Normalize whether the public header separator should be stretched to the exact box.
 *
 * Disabled keeps the image aspect ratio and treats height as a maximum. Enabled
 * makes the configured width and height an exact render box, allowing intentional
 * horizontal or vertical distortion for decorative separators.
 *
 * @param mixed $value Value to process.
 * @return bool True when the condition matches.
 */
function theme_branding_separator_stretch_enabled(mixed $value): bool
{
    return (string) $value === '1';
}

/**
 * Read theme defaults from the built-in stylesheet and then from active custom CSS.
 *
 * @return array Structured result data for the caller.
 */
function theme_css_defaults(): array
{
    // $variables stores an intermediate value used by the surrounding gallery workflow.
    $variables = [
        '--accent' => '#a5481c',
        '--accent-dark' => '#713414',
        '--paper' => '#f8f4ec',
        '--panel' => '#fffaf0',
        '--radius' => '16px',
        '--font-family' => 'Georgia, Times New Roman, serif',
    ];
    foreach ([dirname(__DIR__, 2) . '/public/assets/styles.css', custom_css_path()] as $path) {
        if (!is_file($path)) {
            continue;
        }
        // $variables stores an intermediate value used by the surrounding gallery workflow.
        $variables = array_merge($variables, css_custom_properties_from_file($path, array_keys($variables)));
    }

    return [
        'accent' => sanitize_hex_color($variables['--accent'], '#a5481c'),
        'accent_dark' => sanitize_hex_color($variables['--accent-dark'], '#713414'),
        'paper' => sanitize_hex_color($variables['--paper'], '#f8f4ec'),
        'panel' => sanitize_hex_color($variables['--panel'], '#fffaf0'),
        'gallery_panel' => sanitize_hex_color($variables['--gallery-panel'] ?? $variables['--panel'], '#fffaf0'),
        'radius' => (string) max(0, min(32, (int) $variables['--radius'])),
        'font' => theme_font_mode_from_css($variables['--font-family']),
    ];
}

/**
 * Extract selected CSS custom properties from a stylesheet.
 *
 * @param string $path Filesystem path.
 * @param array $names Names value.
 * @return array Structured result data for the caller.
 */
function css_custom_properties_from_file(string $path, array $names): array
{
    // $css stores an intermediate value used by the surrounding gallery workflow.
    $css = file_get_contents($path);
    if ($css === false) {
        return [];
    }
    if (!preg_match_all('/:root\s*\{([^}]*)\}/is', $css, $blocks) || empty($blocks[1])) {
        return [];
    }
    // $rootCss stores an intermediate value used by the surrounding gallery workflow.
    $rootCss = implode("\n", $blocks[1]);
    // $found stores an intermediate value used by the surrounding gallery workflow.
    $found = [];
    foreach ($names as $name) {
        // $pattern stores an intermediate value used by the surrounding gallery workflow.
        $pattern = '/' . preg_quote($name, '/') . '\s*:\s*([^;}{]+)\s*;/i';
        if (preg_match_all($pattern, $rootCss, $matches) && !empty($matches[1])) {
            $found[$name] = trim((string) end($matches[1]));
        }
    }
    return $found;
}

/**
 * Map a CSS font stack back to the two modes available in the admin form.
 *
 * @param string $fontFamily Font family value.
 * @return string Text result for the caller.
 */
function theme_font_mode_from_css(string $fontFamily): string
{
    // $fontFamily stores an intermediate value used by the surrounding gallery workflow.
    $fontFamily = strtolower($fontFamily);
    return str_contains($fontFamily, 'sans') || str_contains($fontFamily, 'system-ui') || str_contains($fontFamily, 'arial') ? 'sans' : 'serif';
}

/**
 * Validate a six-digit hex color from theme settings.
 *
 * @param string $value Value to process.
 * @param string $fallback Fallback value.
 * @return string Text result for the caller.
 */
function sanitize_hex_color(string $value, string $fallback): string
{
    // $value stores an intermediate value used by the surrounding gallery workflow.
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
}
