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

namespace Gallery\Services;

/**
 * Return true when per-gallery count badge overrides can be stored.
 *
 * @return bool True when the condition matches.
 */
function gallery_count_badge_schema_ready(): bool
{
    return db_column_exists('galleries', 'count_badge_visibility');
}

/**
 * Return the override values supported on one gallery row.
 *
 * @return list<string> Canonical per-gallery selection values.
 */
function gallery_count_badge_override_values(): array
{
    return ['inherit', 'public', 'all', 'both', 'hide'];
}

/**
 * Normalize the global Theme default. The feature is enabled by default.
 *
 * @return bool True when the condition matches.
 */
function theme_gallery_count_badge_enabled(): bool
{
    return theme_gallery_count_badge_mode() !== 'hidden';
}

/**
 * Normalize a per-gallery override for database storage.
 *
 * A null return value means the gallery inherits the global Theme setting.
 *
 * @param scalar|array<array-key,mixed>|object|null $value Untrusted stored override.
 * @return ?string Text result for the caller.
 */
function gallery_count_badge_storage_value(mixed $value): ?string
{
    // $visibility stores the raw form or sidecar value before validation.
    if (!is_scalar($value)) {
        return null;
    }
    $visibility = strtolower(trim((string) $value));
    if ($visibility === '' || $visibility === 'inherit') {
        return null;
    }
    // The original SQL enum values remain valid across upgrades.
    return match ($visibility) {
        'public', 'public_only' => 'public',
        'all', 'all_only' => 'all',
        'both', 'show' => $visibility,
        'hide', 'hidden' => 'hide',
        default => null,
    };
}

/**
 * Return whether one gallery card should show the contained-picture badge.
 *
 * @param array<string,mixed> $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function gallery_effective_count_badge_enabled(array $gallery): bool
{
    return gallery_effective_count_badge_mode($gallery) !== 'hidden';
}

/**
 * Return a translated label for one override select option.
 *
 * @param string $value Value to process.
 * @return string Text result for the caller.
 */
function gallery_count_badge_override_label(string $value): string
{
    return match ($value) {
        'public' => t('gallery.count_badge.public_only', 'Public only'),
        'all' => t('gallery.count_badge.all_only', 'All only'),
        'both', 'show' => t('gallery.count_badge.both', 'Both'),
        'hide' => t('gallery.count_badge.mode_hidden', 'Hidden'),
        default => t('gallery.count_badge.inherit', 'Inherit from Theme'),
    };
}

/**
 * Return a translated label for the effective public badge state.
 *
 * @param bool $enabled Enabled flag.
 * @return string Text result for the caller.
 */
function gallery_count_badge_state_label(bool $enabled): string
{
    return $enabled
        ? t('gallery.count_badge.shown', 'shown')
        : t('gallery.count_badge.hidden', 'hidden');
}

/**
 * Return a readable summary of the current count badge source for Admin forms.
 *
 * @param array<string,mixed> $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function gallery_count_badge_source_label(array $gallery): string
{
    if (
        gallery_count_badge_schema_ready()
        && gallery_count_badge_storage_value(
            $gallery['count_badge_visibility'] ?? null,
        ) !== null
    ) {
        return t(
            'admin.gallery_editor.count_badge_source_gallery',
            'gallery override',
        );
    }
    return t('admin.gallery_editor.count_badge_source_theme', 'Theme default');
}

/**
 * Normalize a Theme count-badge mode independently of legacy checkbox values.
 *
 * The new feature defaults to Both even when the old Theme toggle was off.
 * Existing explicit new settings take precedence on every subsequent upgrade.
 *
 * @param scalar|array<array-key,mixed>|object|null $value Untrusted stored mode.
 * @return string Canonical public/all/both/hidden value.
 */
function gallery_count_badge_mode_normalize(mixed $value): string
{
    return in_array($value, ['public', 'all', 'both', 'hidden'], true)
        ? $value
        : 'both';
}

/**
 * Return the Theme count display mode with an explicit first-upgrade default.
 *
 * @return string Canonical mode.
 */
function theme_gallery_count_badge_mode(): string
{
    return gallery_count_badge_mode_normalize(app_setting(
        'theme_gallery_count_badge_mode',
        'both',
    ));
}

/**
 * Return the independent administrator icon preference.
 *
 * @return bool True unless the administrator has explicitly disabled icons.
 */
function theme_gallery_count_badge_icons_enabled(): bool
{
    return app_setting('theme_gallery_count_badge_icons_enabled', '1') !== '0';
}

/**
 * Resolve Theme plus optional per-gallery public/all/both/hidden override.
 *
 * @param array<string,mixed> $gallery Gallery data including the optional original enum field.
 * @return string Canonical display mode.
 */
function gallery_effective_count_badge_mode(array $gallery): string
{
    if (gallery_count_badge_schema_ready()) {
        $override = gallery_count_badge_storage_value(
            $gallery['count_badge_visibility'] ?? null,
        );
        if ($override !== null) {
            return match ($override) {
                'public' => 'public',
                'all' => 'all',
                'hide' => 'hidden',
                default => 'both',
            };
        }
    }
    return theme_gallery_count_badge_mode();
}

/**
 * Return the localized label of the effective Admin count mode.
 *
 * @param string $mode Canonical display mode.
 * @return string Translated label.
 */
function gallery_count_badge_mode_label(string $mode): string
{
    return gallery_count_badge_override_label(
        $mode === 'hidden' ? 'hide' : $mode,
    );
}

/**
 * Decide whether this Admin request requires a privileged total aggregation.
 *
 * @param array<string,mixed> $gallery Gallery row.
 * @param bool $isAdmin True only for a server-verified historical Admin session.
 * @return bool Whether all-visibility image counts must be computed.
 */
function gallery_count_badge_needs_total(array $gallery, bool $isAdmin): bool
{
    return $isAdmin
        && in_array(
            gallery_effective_count_badge_mode($gallery),
            ['all', 'both'],
            true,
        );
}

/**
 * Build a count-badge view model without serializing privileged totals to guests.
 *
 * The caller MUST derive isAdmin from the canonical server-side Admin session,
 * not Viewer Accounts or client-provided fields.
 *
 * @param array<string,mixed> $gallery Gallery settings.
 * @param bool $isAdmin Server-verified administrator.
 * @param int $publicCount Existing visitor-accessible branch count.
 * @param ?int $totalCount All live registered branch images, for Admin only.
 * @return array{visible:bool,admin:bool,mode:string,icons:bool,public_count:int,all_count:?int} Presentation.
 */
function gallery_count_badge_presentation(
    array $gallery,
    bool $isAdmin,
    int $publicCount,
    ?int $totalCount,
): array {
    $mode = gallery_effective_count_badge_mode($gallery);
    if (!$isAdmin && $mode !== 'hidden') {
        $mode = 'public';
    }
    if (
        $mode === 'both'
        && $totalCount !== null
        && $publicCount === $totalCount
    ) {
        $mode = 'all';
    }
    return [
        'visible' => $mode !== 'hidden',
        'admin' => $isAdmin,
        'mode' => $mode,
        'icons' => $isAdmin && theme_gallery_count_badge_icons_enabled(),
        'public_count' => max(0, $publicCount),
        'all_count' =>
            $isAdmin
            && in_array($mode, ['all', 'both'], true)
            && $totalCount !== null
                ? max(0, $totalCount)
                : null,
    ];
}
