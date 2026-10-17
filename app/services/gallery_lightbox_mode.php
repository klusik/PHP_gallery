<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_lightbox_mode.php
 * Module Type: Service
 *
 * Purpose:
 *   Resolves public lightbox browsing modes and gallery presentation defaults.
 *
 * Responsibilities:
 *   - Keep the global Theme lightbox mode separate from optional per-gallery overrides
 *   - Normalize mode values before they reach public markup, Admin forms, or database writes
 *   - Keep legacy strip values compatible while storing the public picture_strip value
 *   - Provide readable labels for Theme and gallery editor controls
 *   - Keep upgraded installations safe before the newest gallery column migration is applied
 *   - Normalize versioned playback snapshots and guard explicit preference writes
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

namespace Gallery\Services;

/**
 * Normalize versioned defaults without changing existing gallery behavior.
 * @param array{version?:int,enabled?:bool,autoplay?:bool,music?:bool,interval?:string,
 *     transition?:string}|string|null $value Stored JSON, decoded settings,
 *     or null for the existing enabled/music/medium defaults.
 * @return array{version:int,enabled:bool,autoplay:bool,music:bool,interval:string,
 *     transition:string} Complete bounded presentation preferences.
 */
function gallery_presentation_normalize(array|string|null $value): array
{
    $defaults = ['version' => 1, 'enabled' => true, 'autoplay' => true, 'music' => true,
        'interval' => 'medium', 'transition' => 'medium'];
    if (is_string($value)) $value = json_decode($value, true);
    if (!is_array($value) || ($value['version'] ?? null) !== 1) {
        // Permanent pre-settings/unknown-version compatibility. This owner
        // preserves the established launcher, music and medium timings.
        return $defaults;
    }
    foreach (['enabled', 'autoplay', 'music'] as $key) {
        if (is_bool($value[$key] ?? null)) $defaults[$key] = $value[$key];
    }
    foreach (['interval', 'transition'] as $key) {
        if (in_array($value[$key] ?? null, ['slow', 'medium', 'fast'], true)) {
            $defaults[$key] = $value[$key];
        }
    }
    return $defaults;
}

/**
 * Resolve physical-gallery defaults from its already fetched row.
 * @param array{presentation_json?:string|null} $gallery Authorized gallery row.
 * @return array{version:int,enabled:bool,autoplay:bool,music:bool,interval:string,
 *     transition:string} Defaults without an additional public schema probe.
 */
function gallery_presentation_settings(array $gallery): array
{
    return gallery_presentation_normalize($gallery['presentation_json'] ?? null);
}

/**
 * Project complete supported preferences into shareable playback intent.
 * @param array{version:int,enabled:bool,autoplay:bool,music:bool,interval:string,
 *     transition:string} $settings Normalized physical-gallery preferences.
 * @return array{presentation:string,autoplay:string,
 *     presentation_fullscreen:string,presentation_music:string,
 *     presentation_interval:string,presentation_transition:string}
 *     Bounded URL values; controllers add the destination and active language.
 */
function gallery_presentation_query(array $settings): array
{
    return [
        'presentation' => '1',
        'autoplay' => $settings['autoplay'] ? '1' : '0',
        'presentation_fullscreen' => '1',
        'presentation_music' => $settings['music'] ? '1' : '0',
        'presentation_interval' => $settings['interval'],
        'presentation_transition' => $settings['transition'],
    ];
}

/**
 * Determine whether the editor can offer persisted presentation controls.
 * @return bool True only after the shared observer verifies the new column.
 */
function gallery_presentation_schema_ready(): bool
{
    return presentation_schema_render_available(
        presentation_gallery_settings_schema_status(),
        'gallery_presentation_render'
    );
}

/**
 * Refuse explicit preference writes before any database or filesystem effects.
 * @param string $operation Stable editor/import operation for safe diagnostics.
 * @return void Continue only with verified current presentation storage.
 * @throws PresentationSchemaUnavailableException On missing or unknown schema.
 */
function gallery_presentation_assert_writable(string $operation): void
{
    presentation_schema_assert_write_available(
        presentation_gallery_settings_schema_status(),
        $operation,
        t('presentation.migration')
    );
}

/**
 * Return true when per-gallery lightbox browsing-mode overrides can be stored.
 *
 * Runtime schema checks intentionally match the existing gallery display helpers.
 * This lets the public site continue to render with the Theme default when code
 * is uploaded before the administrator runs the new database migration.
 *
 * @return bool True when the condition matches.
 */
function gallery_lightbox_browsing_mode_schema_ready(): bool
{
    return presentation_schema_render_available(presentation_lightbox_override_schema_status(), 'lightbox_override_render');
}

/**
 * Return public lightbox modes supported by the browser renderer.
 *
 * The values are persisted and emitted as data-lightbox-browsing-mode. Keep them
 * short, stable, and independent from translated labels.
 *
 * @return array Structured result data for the caller.
 */
function gallery_lightbox_browsing_mode_options(): array
{
    if (function_exists('Gallery\\Services\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('lightbox_modes')) {
        return ['single'];
    }
    return ['single', 'picture_strip', '3d_carousel'];
}

/**
 * Normalize a submitted, stored, or sidecar lightbox browsing-mode value.
 *
 * @param mixed $value Value to process.
 * @param string $fallback Fallback value.
 * @return string Text result for the caller.
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
 *
 * @return string Text result for the caller.
 */
function theme_lightbox_browsing_mode(): string
{
    if (function_exists('Gallery\\Services\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('lightbox_modes')) {
        return 'single';
    }
    return gallery_lightbox_browsing_mode_normalize(app_setting('theme_lightbox_browsing_mode', 'single'), 'single');
}

/**
 * Normalize a per-gallery override for database storage.
 *
 * A null return value means the gallery inherits the global Theme setting. This
 * mirrors description-layout and count-badge persistence instead of storing a
 * separate boolean flag.
 *
 * @param mixed $value Value to process.
 * @return ?string Text result for the caller.
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
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function gallery_effective_lightbox_browsing_mode(array $gallery): string
{
    if (function_exists('Gallery\\Services\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('lightbox_modes')) {
        return 'single';
    }
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
 *
 * @param string $mode Mode value.
 * @return string Text result for the caller.
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
 *
 * @param string $value Value to process.
 * @return string Text result for the caller.
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
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function gallery_lightbox_browsing_mode_source_label(array $gallery): string
{
    if (gallery_lightbox_browsing_mode_schema_ready() && gallery_lightbox_browsing_mode_storage_value($gallery['lightbox_browsing_mode'] ?? null) !== null) {
        return t('admin.gallery_editor.lightbox_mode_source_gallery', 'gallery override');
    }
    return t('admin.gallery_editor.lightbox_mode_source_theme', 'Theme default');
}
