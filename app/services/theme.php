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
 *   2026-05-04
 */

declare(strict_types=1);

/**
 * Theme setting service helpers.
 *
 * This module is intentionally limited to color, radius, font, and CSS default
 * resolution. Runtime assets such as favicon files, custom CSS files, and
 * background images stay in their own services so path handling remains easy to
 * audit after the services.php split.
 */

/**
 * Theme settings are stored in the DB so the visual preset can be changed
 * without editing PHP or CSS files.
 */
function theme_settings(): array
{
    // $defaults stores an intermediate value used by the surrounding gallery workflow.
    $defaults = theme_css_defaults();
    return [
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
        'gallery_description_layout' => function_exists('theme_gallery_description_layout') ? theme_gallery_description_layout() : 'vertical',
        'gallery_count_badge_enabled' => !function_exists('theme_gallery_count_badge_enabled') || theme_gallery_count_badge_enabled() ? '1' : '0',
    ];
}

/**
 * Return only DB-backed theme overrides.
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
        'gallery_description_layout' => app_setting('theme_gallery_description_layout'),
        'gallery_count_badge_enabled' => app_setting('theme_gallery_count_badge_enabled'),
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
 * Read theme defaults from the built-in stylesheet and then from active custom CSS.
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
 */
function theme_font_mode_from_css(string $fontFamily): string
{
    // $fontFamily stores an intermediate value used by the surrounding gallery workflow.
    $fontFamily = strtolower($fontFamily);
    return str_contains($fontFamily, 'sans') || str_contains($fontFamily, 'system-ui') || str_contains($fontFamily, 'arial') ? 'sans' : 'serif';
}

/**
 * Validate a six-digit hex color from theme settings.
 */
function sanitize_hex_color(string $value, string $fallback): string
{
    // $value stores an intermediate value used by the surrounding gallery workflow.
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
}
