<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/theme_layout_settings_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the shared safe Theme layout owner used by the Theme editor and Setup Wizard.
 *
 * Responsibilities:
 *   - Check normalized current layout, card, grid, GPS, and lightbox values
 *   - Reject values outside the explicit safe scalar allowlist and bounds
 *   - Verify capability gating and public-content revision side effects
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services {
    use InvalidArgumentException;

    const CMS_PAGINATION_DEFAULT_COLUMNS = 4;
    const CMS_PAGINATION_DEFAULT_ROWS = 5;
    const CMS_PAGINATION_MAX_COLUMNS = 12;
    const CMS_PAGINATION_MAX_ROWS = 50;

    /** Return deterministic Theme settings for the owner fixture. @return array<string,string> Fixture Theme values. */
    function theme_settings(): array
    {
        return [
            'gps_pin_enabled' => '1',
            'gps_pin_background_enabled' => '0',
            'gps_pin_size' => '30',
            'gps_pin_background_size' => '18',
        ];
    }

    /** Return deterministic pagination settings. @return array{enabled:bool,columns:int,rows:int} Fixture pagination values. */
    function pagination_global_settings(): array
    {
        return ['enabled' => true, 'columns' => 6, 'rows' => 8];
    }

    /** Return deterministic home-grid settings. @return array{columns:int,rows:int} Fixture home-grid values. */
    function main_page_gallery_grid_settings(): array
    {
        return ['columns' => 5, 'rows' => 7];
    }

    /** Return deterministic tag-grid settings. @return array{columns:int,rows:int} Fixture tag-grid values. */
    function tag_page_gallery_grid_settings(): array
    {
        return ['columns' => 3, 'rows' => 4];
    }

    /** Normalize a fixture GPS pin size using the production bounds. @param mixed $value Candidate size. @return int Safe size. */
    function theme_gps_pin_size_value(mixed $value): int
    {
        return max(14, min(48, (int) $value));
    }

    /** Normalize a fixture GPS background size using the production bounds. @param mixed $value Candidate size. @return int Safe size. */
    function theme_gps_pin_background_size_value(mixed $value): int
    {
        return max(0, min(48, (int) $value));
    }

    /** Return the current global gallery-card layout. @return string Fixture layout. */
    function theme_gallery_description_layout(): string
    {
        return (string) ($GLOBALS['theme_layout_test_values']['theme_gallery_description_layout'] ?? 'vertical');
    }

    /** Return the shared stable breadcrumb style metadata for the isolated Theme test.
     * @return array<string,array{label:string,class:string}> Supported breadcrumb style definitions.
     */
    function breadcrumb_style_registry(): array
    {
        return [
            'minimal' => ['label' => 'Minimal', 'class' => 'breadcrumbs--minimal'],
            'chevron' => ['label' => 'Chevron', 'class' => 'breadcrumbs--chevron'],
            'pills' => ['label' => 'Pills', 'class' => 'breadcrumbs--pills'],
            'surface' => ['label' => 'Surface', 'class' => 'breadcrumbs--surface'],
            'ribbon' => ['label' => 'Ribbon', 'class' => 'breadcrumbs--ribbon'],
            'nodes' => ['label' => 'Connected nodes', 'class' => 'breadcrumbs--nodes'],
            'tabs' => ['label' => 'Tabs', 'class' => 'breadcrumbs--tabs'],
            'tiles' => ['label' => 'Tiles', 'class' => 'breadcrumbs--tiles'],
            'gradient' => ['label' => 'Gradient', 'class' => 'breadcrumbs--gradient'],
        ];
    }

    /** Normalize a Theme or gallery breadcrumb value against the isolated shared registry.
     * @param scalar|array<array-key,mixed>|object|resource|null $value Submitted or persisted style identifier.
     * @param bool $allowInherit Whether the gallery inheritance sentinel is accepted.
     * @return string Registered style or safe inheritance/default fallback.
     */
    function breadcrumb_style_normalize(mixed $value, bool $allowInherit = false): string
    {
        if (!is_string($value) && !is_int($value)) {
            return $allowInherit ? 'inherit' : 'chevron';
        }
        $style = strtolower(trim((string) $value));
        if ($allowInherit && $style === 'inherit') {
            return 'inherit';
        }
        return array_key_exists($style, breadcrumb_style_registry())
            ? $style
            : ($allowInherit ? 'inherit' : 'chevron');
    }

    /** Return the current safe global breadcrumb style for the isolated Theme test.
     * @return string Supported style from the shared breadcrumb contract.
     */
    function theme_breadcrumb_style(): string
    {
        return breadcrumb_style_normalize($GLOBALS['theme_layout_test_values']['theme_breadcrumb_style'] ?? 'chevron');
    }

    /** Return whether gallery count badges are enabled. @return bool Fixture badge state. */
    function theme_gallery_count_badge_enabled(): bool
    {
        return true;
    }

    /** Return the current tag-page gallery-card layout. @return string Fixture tag layout. */
    function tag_page_gallery_description_layout(): string
    {
        return 'horizontal';
    }

    /** Return the current hero-tag visible limit. @return int Fixture limit. */
    function theme_hero_tag_visible_limit(): int
    {
        return (int) ($GLOBALS['theme_layout_test_values']['theme_hero_tag_visible_limit'] ?? 20);
    }

    /** Return whether every hero tag is shown. @return bool Fixture state. */
    function theme_hero_tag_display_all_enabled(): bool
    {
        return (string) ($GLOBALS['theme_layout_test_values']['theme_hero_tag_display_all'] ?? '0') === '1';
    }

    /** Return whether hero-tag scrolling is enabled. @return bool Fixture state. */
    function theme_hero_tag_scrollbar_enabled(): bool
    {
        return (string) ($GLOBALS['theme_layout_test_values']['theme_hero_tag_scrollbar_enabled'] ?? '1') !== '0';
    }

    /** Return the hero-tag scrollbar row threshold. @return int Fixture row count. */
    function theme_hero_tag_scrollbar_rows(): int
    {
        return (int) ($GLOBALS['theme_layout_test_values']['theme_hero_tag_scrollbar_rows'] ?? 5);
    }

    /** Return the current hero-tag sort mode. @return string Fixture mode. */
    function theme_hero_tag_sort_mode(): string
    {
        return (string) ($GLOBALS['theme_layout_test_values']['theme_hero_tag_sort_mode'] ?? 'usage');
    }

    /** Return the current global lightbox mode. @return string Fixture lightbox mode. */
    function theme_lightbox_browsing_mode(): string
    {
        return (string) ($GLOBALS['theme_layout_test_values']['theme_lightbox_browsing_mode'] ?? 'single');
    }

    /** Return the supported gallery-card layouts. @return list<string> Supported layouts. */
    function gallery_description_layout_options(): array
    {
        return ['vertical', 'horizontal'];
    }

    /** Return the supported lightbox modes. @return list<string> Supported modes. */
    function gallery_lightbox_browsing_mode_options(): array
    {
        return ['single', 'continuous'];
    }

    /** Return one fixture feature-capability state. @param string $key Capability key. @param array<int,string> $resolving Unused dependency stack. @return bool Effective state. */
    function feature_capability_effective_enabled(string $key, array $resolving = []): bool
    {
        return !empty($GLOBALS['theme_layout_test_capabilities'][$key]);
    }

    /** Capture one app-setting write. @param string $key Setting key. @param string $value Persisted value. @return void */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['theme_layout_test_writes'][] = [$key, $value];
        $GLOBALS['theme_layout_test_values'][$key] = $value;
    }

    require_once __DIR__ . '/../app/services/theme_layout_settings.php';
}

namespace Gallery\Tests\ThemeLayoutSettings {
    use InvalidArgumentException;
    use function Gallery\Services\breadcrumb_style_registry;
    use function Gallery\Services\theme_layout_safe_normalize;
    use function Gallery\Services\theme_layout_safe_save;
    use function Gallery\Services\theme_layout_safe_setting_available;
    use function Gallery\Services\theme_layout_safe_settings;

    /** Fail the focused contract with a readable assertion. @param bool $condition Assertion result. @param string $message Failure message. @return void */
    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /** Assert one invalid value is rejected by the safe Theme owner. @param callable():mixed $operation Operation expected to fail. @return void */
    function expect_invalid(callable $operation): void
    {
        try {
            $operation();
        } catch (InvalidArgumentException) {
            return;
        }
        throw new \RuntimeException('Expected invalid Theme layout value was accepted.');
    }

    $GLOBALS['theme_layout_test_capabilities'] = ['gallery_maps' => true, 'lightbox_modes' => true];
    $GLOBALS['theme_layout_test_values'] = [];
    $GLOBALS['theme_layout_test_writes'] = [];

    $settings = theme_layout_safe_settings();
    assert_true($settings['theme_gps_pin_size'] === '30', 'GPS pin size did not use the canonical normalized value.');
    assert_true($settings['pagination_columns'] === '6' && $settings['home_gallery_grid_columns'] === '5', 'Grid settings did not preserve owner values.');
    assert_true($settings['tag_page_gallery_description_layout'] === 'horizontal', 'Tag card layout did not preserve owner value.');
    assert_true($settings['theme_hero_tag_visible_limit'] === '20' && $settings['theme_hero_tag_sort_mode'] === 'usage', 'Hero-tag settings did not preserve owner values.');
    assert_true($settings['theme_breadcrumb_style'] === 'chevron', 'Breadcrumb style did not use its safe Theme default.');
    assert_true(theme_layout_safe_setting_available('theme_gps_pin_enabled'), 'Enabled GPS capability was treated as unavailable.');
    assert_true(theme_layout_safe_setting_available('theme_lightbox_browsing_mode'), 'Enabled lightbox capability was treated as unavailable.');

    assert_true(theme_layout_safe_normalize('pagination_columns', '12') === '12', 'Maximum grid columns were not accepted.');
    assert_true(theme_layout_safe_normalize('theme_gps_pin_background_size', '0') === '0', 'Zero GPS background size was not accepted.');
    assert_true(theme_layout_safe_normalize('theme_gallery_description_layout', 'horizontal') === 'horizontal', 'Supported card layout was not accepted.');
    foreach (array_keys(breadcrumb_style_registry()) as $styleId) {
        assert_true(theme_layout_safe_normalize('theme_breadcrumb_style', $styleId) === $styleId, 'Registered breadcrumb style was not accepted: ' . $styleId);
    }
    assert_true(theme_layout_safe_normalize('theme_breadcrumb_style', []) === 'chevron', 'Malformed breadcrumb style did not fall back safely.');
    assert_true(theme_layout_safe_normalize('theme_hero_tag_visible_limit', '200') === '200', 'Maximum hero-tag visible limit was not accepted.');
    assert_true(theme_layout_safe_normalize('theme_hero_tag_sort_mode', 'alphabetical') === 'alphabetical', 'Supported hero-tag sort mode was not accepted.');
    expect_invalid(static fn (): string => theme_layout_safe_normalize('pagination_columns', '13'));
    expect_invalid(static fn (): string => theme_layout_safe_normalize('theme_gps_pin_size', '49'));
    expect_invalid(static fn (): string => theme_layout_safe_normalize('theme_gallery_description_layout', 'diagonal'));
    expect_invalid(static fn (): string => theme_layout_safe_normalize('theme_hero_tag_visible_limit', '201'));
    expect_invalid(static fn (): string => theme_layout_safe_normalize('theme_hero_tag_scrollbar_rows', '13'));
    expect_invalid(static fn (): string => theme_layout_safe_normalize('theme_hero_tag_sort_mode', 'random'));
    expect_invalid(static fn (): string => theme_layout_safe_normalize('theme_header_branding', 'x'));

    $GLOBALS['theme_layout_test_capabilities']['gallery_maps'] = false;
    assert_true(!theme_layout_safe_setting_available('theme_gps_pin_enabled'), 'Disabled GPS capability remained wizard-editable.');
    expect_invalid(static fn (): mixed => theme_layout_safe_save('theme_gps_pin_enabled', '1'));
    $GLOBALS['theme_layout_test_capabilities']['gallery_maps'] = true;

    theme_layout_safe_save('theme_gallery_description_layout', 'horizontal');
    assert_true($GLOBALS['theme_layout_test_writes'][0] === ['theme_gallery_description_layout', 'horizontal'], 'Card layout did not persist through app settings.');
    assert_true(($GLOBALS['theme_layout_test_writes'][1][0] ?? '') === 'theme_public_content_revision', 'Card layout change did not bump the public-content revision.');

    $GLOBALS['theme_layout_test_writes'] = [];
    theme_layout_safe_save('theme_lightbox_browsing_mode', 'continuous');
    assert_true($GLOBALS['theme_layout_test_writes'][0] === ['theme_lightbox_browsing_mode', 'continuous'], 'Lightbox mode did not persist through app settings.');
    assert_true(($GLOBALS['theme_layout_test_writes'][1][0] ?? '') === 'theme_public_content_revision', 'Lightbox mode change did not bump the public-content revision.');

    $GLOBALS['theme_layout_test_writes'] = [];
    theme_layout_safe_save('theme_hero_tag_sort_mode', 'alphabetical');
    assert_true($GLOBALS['theme_layout_test_writes'][0] === ['theme_hero_tag_sort_mode', 'alphabetical'], 'Hero-tag sort mode did not persist through app settings.');
    assert_true(($GLOBALS['theme_layout_test_writes'][1][0] ?? '') === 'theme_public_content_revision', 'Hero-tag changes did not bump the public-content revision.');

    $GLOBALS['theme_layout_test_writes'] = [];
    theme_layout_safe_save('theme_breadcrumb_style', 'surface');
    assert_true($GLOBALS['theme_layout_test_writes'][0] === ['theme_breadcrumb_style', 'surface'], 'Breadcrumb style did not persist through app settings.');
    assert_true(($GLOBALS['theme_layout_test_writes'][1][0] ?? '') === 'theme_public_content_revision', 'Breadcrumb style change did not bump the public-content revision.');

    $GLOBALS['theme_layout_test_writes'] = [];
    theme_layout_safe_save('home_gallery_grid_rows', '9');
    assert_true($GLOBALS['theme_layout_test_writes'] === [['home_gallery_grid_rows', '9']], 'Plain grid persistence must not create unrelated revision writes.');

    echo "theme_layout_settings_test: PASS\n";
}
