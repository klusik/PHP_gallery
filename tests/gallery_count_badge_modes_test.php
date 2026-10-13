<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_count_badge_modes_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protect the administrator-only dual photo-counter presentation and its
 *   independent Theme mode/icon settings without a live database.
 *
 * Responsibilities:
 *   - Cover first-upgrade defaults, eight mode/icon combinations and inheritance
 *   - Preserve legacy show/hide overrides and the public-visitor privacy boundary
 *   - Verify accessible Admin eye/stacked-photo HTML and equal-count collapse
 *   - Reject malformed settings and protect hidden-mode icon persistence
 *
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Fetch a transient Theme value or the requested canonical default.
     *
     * @param string $key Theme preference key.
     * @param ?string $default Default when no saved setting exists.
     * @return ?string Fixture-only resolved value.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['gallery_count_modes_test_settings'][$key] ?? $default;
    }

    /**
     * Advertise the existing enum schema without connecting to the database.
     *
     * @param string $table Database table identifier.
     * @param string $column Column identifier.
     * @return bool Whether the test fixture exposes the gallery override.
     */
    function db_column_exists(string $table, string $column): bool
    {
        return $table === 'galleries' && $column === 'count_badge_visibility'
            && ($GLOBALS['gallery_count_modes_test_schema'] ?? true);
    }

    /**
     * Resolve fixed English test strings using the real placeholder convention.
     *
     * @param string $key Translation key.
     * @param string $fallback English text when no catalog is loaded.
     * @param array<string,string|int> $params Interpolation values.
     * @return string Readable resolved label.
     */
    function t(string $key, string $fallback = '', array $params = []): string
    {
        foreach ($params as $name => $value) {
            $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
        }
        return $fallback;
    }
}

namespace Gallery\Core {
    /**
     * Escape accessible HTML labels just as the application does.
     *
     * @param string $value Dynamic label text.
     * @return string Escaped HTML text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace {
    require_once __DIR__ . '/../app/services/gallery_count_badges.php';
    require_once __DIR__ . '/../app/views/public_gallery_cards.php';

    /** Reject an invalid counter contract without continuing to later cases. */
    $check = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };

    $GLOBALS['gallery_count_modes_test_settings'] = [
        // This is the OLD boolean preference. It must not block the new first-upgrade default.
        'theme_gallery_count_badge_enabled' => '0',
    ];
    $GLOBALS['gallery_count_modes_test_schema'] = true;
    $inherit = ['count_badge_visibility' => null];
    $check(\Gallery\Services\theme_gallery_count_badge_mode() === 'both',
        'First upgrade must default to Both independently of the old disabled checkbox.');
    $check(\Gallery\Services\theme_gallery_count_badge_icons_enabled(),
        'First upgrade must default icons to ON.');
    $check(\Gallery\Services\gallery_count_badge_storage_value(['malformed']) === null
        && \Gallery\Services\gallery_count_badge_storage_value('unknown') === null,
        'Malformed gallery enum values should fail closed to inheritance.');
    $check(\Gallery\Services\gallery_count_badge_storage_value('show') === 'show'
        && \Gallery\Services\gallery_effective_count_badge_mode(['count_badge_visibility' => 'show']) === 'both',
        'Legacy explicit Show must map to a visible Both counter.');
    $check(\Gallery\Services\gallery_effective_count_badge_mode(['count_badge_visibility' => 'hide']) === 'hidden',
        'Legacy explicit Hide must remain hidden.');
    $check(\Gallery\Services\gallery_effective_count_badge_mode(['count_badge_visibility' => 'public']) === 'public'
        && \Gallery\Services\gallery_effective_count_badge_mode(['count_badge_visibility' => 'all']) === 'all',
        'Explicit per-gallery Public/All overrides must not follow Theme mode.');

    $modes = ['public', 'all', 'both', 'hidden'];
    foreach ($modes as $mode) {
        foreach ([true, false] as $icons) {
            $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_mode'] = $mode;
            $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_icons_enabled'] = $icons ? '1' : '0';
            $badge = \Gallery\Services\gallery_count_badge_presentation($inherit, true, 12, 18);
            $check($badge['visible'] === ($mode !== 'hidden'), 'Wrong admin badge visibility in ' . $mode);
            $check($badge['mode'] === $mode, 'Wrong admin mode in ' . $mode);
            $check($badge['icons'] === $icons, 'Icon setting unexpectedly depends on count mode.');

            if ($badge['visible']) {
                ob_start();
                \Gallery\Views\view_gallery_count_admin_values($badge);
                $html = (string) ob_get_clean();
                $check(str_contains($html, 'gallery-count-number'), 'Admin count is missing a number.');
                $check(($mode === 'both') === str_contains($html, 'gallery-count-separator'),
                    'The separator must appear only for different Public/All counts.');
                $check(($mode !== 'all') === str_contains($html, '>12</span>'),
                    'Public count incorrectly rendered or omitted.');
                $check(($mode !== 'public') === str_contains($html, '>18</span>'),
                    'All count incorrectly rendered or omitted.');
                $check(($icons && $mode !== 'all') === str_contains($html, 'gallery-count-eye-icon'),
                    'Eye icon not matched to enabled Public segment.');
                $check(($icons && $mode !== 'public') === str_contains($html, 'subgallery-stack-icon'),
                    'Stacked-photo icon not matched to enabled All segment.');
                $check(str_contains(\Gallery\Views\view_gallery_count_admin_label($badge), 'subgalleries'),
                    'Accessible description must mention descendants.');
            }
            $guest = \Gallery\Services\gallery_count_badge_presentation($inherit, false, 12, 18);
            $check($guest['mode'] === ($mode === 'hidden' ? 'hidden' : 'public')
                && $guest['all_count'] === null && $guest['icons'] === false,
                'Guest must never receive All or the dual counter for mode ' . $mode);
            $check($guest['visible'] === ($mode !== 'hidden'),
                'Guests should retain one public badge except when Hidden.');
            $check(!\Gallery\Services\gallery_count_badge_needs_total($inherit, false),
                'Guest should never cause an all-visibility branch query.');
        }
    }

    $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_mode'] = 'both';
    $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_icons_enabled'] = '1';
    foreach ([0, 18] as $equal) {
        $badge = \Gallery\Services\gallery_count_badge_presentation($inherit, true, $equal, $equal);
        $check($badge['mode'] === 'all', 'Both equal counts must collapse to All.');
        ob_start();
        \Gallery\Views\view_gallery_count_admin_values($badge);
        $html = (string) ob_get_clean();
        $check(!str_contains($html, 'gallery-count-separator') && !str_contains($html, 'gallery-count-eye-icon')
            && str_contains($html, 'gallery-count-number'), 'Collapsed count must be a single All segment.');
    }
    $diff = \Gallery\Services\gallery_count_badge_presentation($inherit, true, 0, 18);
    $check($diff['mode'] === 'both' && $diff['public_count'] === 0 && $diff['all_count'] === 18,
        '0 / N must not be collapsed.');
    $check(\Gallery\Services\gallery_count_badge_needs_total($inherit, true),
        'Administrator Both mode must request the full branch count.');

    $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_mode'] = 'hidden';
    $check(\Gallery\Services\theme_gallery_count_badge_icons_enabled(),
        'Hidden mode must not clear the independent icon setting.');
    $GLOBALS['gallery_count_modes_test_settings']['theme_gallery_count_badge_mode'] = 'public';
    $check(\Gallery\Services\theme_gallery_count_badge_icons_enabled(),
        'Unhiding the count must preserve the icon setting.');

    // The identity check is deliberately performed in the server-side controllers,
    // not in a rendered HTML/JS attribute or the count formatting service itself.
    $cards = (string) file_get_contents(__DIR__ . '/../app/controllers/public_gallery_cards.php');
    $page = (string) file_get_contents(__DIR__ . '/../app/controllers/public_gallery_page.php');
    $check(str_contains($cards, 'current_user() !== null') && str_contains($cards, 'admin_anonymous_preview_active()'),
        'Card totals must remain bound to the historical authenticated Admin session.');
    $check(str_contains($page, 'gallery_count_badge_needs_total($gallery, $isCountAdmin)'),
        'Hero totals must remain gated on Admin mode and session.');

    echo "gallery_count_badge_modes_test: PASS\n";
}
