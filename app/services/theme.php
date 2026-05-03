<?php

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
        'radius' => app_setting('theme_radius', $defaults['radius']),
        'font' => app_setting('theme_font', $defaults['font']),
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
        'radius' => app_setting('theme_radius'),
        'font' => app_setting('theme_font'),
    ];
    return array_filter($settings, static fn (?string $value): bool => $value !== null && $value !== '');
}

/**
 * Remove saved slider/font overrides so the active CSS skin becomes the source.
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
        'theme_radius',
        'theme_font',
    ]);
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
