<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/custom_css.php
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

namespace Gallery\Services;

use function Gallery\Core\asset_url;

/**
 * Resolve the active custom CSS file path.
 *
 * This module lives in app/services/, so project-root paths must use
 * dirname(__DIR__, 2). Keeping this calculation local prevents the path
 * regression that previously happened when theme-adjacent helpers were moved
 * out of app/services.php.
 *
 * @return string Text result for the caller.
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
 *
 * @return string Text result for the caller.
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
 *
 * @return array Structured result data for the caller.
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
 *
 * @param string $filename Filename value.
 * @return ?string Text result for the caller.
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
 *
 * @return ?string Text result for the caller.
 */
function custom_css_url(): ?string
{
    return is_file(custom_css_path()) ? asset_url('assets/custom.css') : null;
}

/**
 * Remove the active user stylesheet after the controller's Admin/CSRF checks.
 * @return void Clears the selected preset only after the file is absent.
 * @throws \RuntimeException When a present stylesheet cannot be removed.
 */
function custom_css_reset(): void
{
    $path = custom_css_path();
    if (is_file($path) && !unlink($path)) {
        throw new \RuntimeException('The custom stylesheet could not be removed.');
    }
    set_app_setting('custom_css_preset', '');
}

/**
 * Apply one changed, catalogued stylesheet preset without resetting unchanged settings.
 * @param string $preset Plain preset filename supplied by the authenticated controller.
 * @return bool True only after a changed preset was copied and recorded.
 */
function custom_css_apply_preset(string $preset): bool
{
    $source = custom_css_preset_path($preset);
    if ($source === null || $preset === (string) app_setting('custom_css_preset', '')) {
        return false;
    }
    if (!copy($source, custom_css_path())) {
        throw new \RuntimeException('The selected stylesheet could not be installed.');
    }
    set_app_setting('custom_css_preset', $preset);
    return true;
}

/**
 * Install an explicitly supplied CSS upload; no request globals are read here.
 * @param array{name?:string,tmp_name?:string,error?:int} $file Controller-selected upload descriptor.
 * @return bool True after the uploaded stylesheet and its selection marker are stored.
 */
function custom_css_store_uploaded(array $file): bool
{
    $temporary = (string) ($file['tmp_name'] ?? '');
    if ($temporary === '' || !is_uploaded_file($temporary)
        || !str_ends_with(strtolower((string) ($file['name'] ?? '')), '.css')) {
        return false;
    }
    if (!move_uploaded_file($temporary, custom_css_path())) {
        throw new \RuntimeException('The uploaded stylesheet could not be installed.');
    }
    set_app_setting('custom_css_preset', 'uploaded');
    return true;
}
