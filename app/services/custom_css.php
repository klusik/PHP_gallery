<?php

declare(strict_types=1);

/**
 * Resolve the active custom CSS file path.
 *
 * This module lives in app/services/, so project-root paths must use
 * dirname(__DIR__, 2). Keeping this calculation local prevents the path
 * regression that previously happened when theme-adjacent helpers were moved
 * out of app/services.php.
 */
function custom_css_path(): string
{
    return dirname(__DIR__, 2) . '/public/assets/custom.css';
}

/**
 * Return the folder containing selectable custom CSS skins.
 *
 * Preset files stay outside public/assets/ on purpose. The admin page copies
 * a selected preset into the active public stylesheet instead of serving the
 * preset directory directly.
 */
function custom_css_preset_dir(): string
{
    return dirname(__DIR__, 2) . '/custom_css';
}

/**
 * Return selectable custom CSS files from the preset folder.
 *
 * The returned array keeps the filename as the stable UI key and the absolute
 * file path as the copy source. This preserves the existing admin behavior
 * while moving the path handling into a dedicated service.
 */
function custom_css_presets(): array
{
    // $dir stores an intermediate value used by the surrounding gallery workflow.
    $dir = custom_css_preset_dir();
    if (!is_dir($dir)) {
        return [];
    }

    // $files stores an intermediate value used by the surrounding gallery workflow.
    $files = glob($dir . '/*.css') ?: [];
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    // $presets stores an intermediate value used by the surrounding gallery workflow.
    $presets = [];
    foreach ($files as $file) {
        $presets[basename($file)] = $file;
    }
    return $presets;
}

/**
 * Resolve one preset filename to a path inside the custom CSS preset folder.
 *
 * Only plain filenames ending in .css are accepted. This prevents directory
 * traversal while keeping the existing admin form contract unchanged.
 */
function custom_css_preset_path(string $filename): ?string
{
    if ($filename === '' || basename($filename) !== $filename || !str_ends_with(strtolower($filename), '.css')) {
        return null;
    }

    // $presets stores an intermediate value used by the surrounding gallery workflow.
    $presets = custom_css_presets();
    return $presets[$filename] ?? null;
}

/**
 * Return the custom CSS URL only when a custom file exists.
 *
 * The actual file is still public/assets/custom.css. This helper only decides
 * whether the optional stylesheet should be advertised to the browser.
 */
function custom_css_url(): ?string
{
    return is_file(custom_css_path()) ? asset_url('assets/custom.css') : null;
}
