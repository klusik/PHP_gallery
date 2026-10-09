<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/breadcrumb_theme_settings_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the global breadcrumb style registry, Theme persistence, and safe fallbacks.
 *
 * Responsibilities:
 *   - Check the stable public style IDs and invalid-value normalization
 *   - Verify Theme saves and public-content revision behavior
 *   - Protect omitted-field compatibility in the Theme POST handler
 *   - Keep the settings inventory's Theme and gallery style lists aligned with the registry
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
    /**
     * Test-only pagination fallback column count.
     *
     * @var int
     * Units: Columns per page.
     * Scope: Isolated Theme settings test fixture only.
     * Consumers: pagination and Theme service stubs declared in this test.
     * Rationale: Provides a deterministic fallback without loading runtime modules.
     */
    const CMS_PAGINATION_DEFAULT_COLUMNS = 4;
    /**
     * Test-only pagination fallback row count.
     *
     * @var int
     * Units: Rows per page.
     * Scope: Isolated Theme settings test fixture only.
     * Consumers: pagination and Theme service stubs declared in this test.
     * Rationale: Provides a deterministic fallback without loading runtime modules.
     */
    const CMS_PAGINATION_DEFAULT_ROWS = 5;
    /**
     * Test-only pagination maximum column count.
     *
     * @var int
     * Units: Columns per page.
     * Scope: Isolated Theme settings test fixture only.
     * Consumers: pagination and Theme service stubs declared in this test.
     * Rationale: Keeps fixture normalization within a predictable bound.
     */
    const CMS_PAGINATION_MAX_COLUMNS = 12;
    /**
     * Test-only pagination maximum row count.
     *
     * @var int
     * Units: Rows per page.
     * Scope: Isolated Theme settings test fixture only.
     * Consumers: pagination and Theme service stubs declared in this test.
     * Rationale: Keeps fixture normalization within a predictable bound.
     */
    const CMS_PAGINATION_MAX_ROWS = 50;

    /** Read a deterministic application setting for this isolated fixture.
     *
     * @param string $key Canonical setting key.
     * @param scalar|array<array-key,mixed>|object|resource|null $default Value returned when the fixture has no saved row.
     * @return scalar|array<array-key,mixed>|object|resource|null Saved or default setting value.
     */
    function app_setting(string $key, mixed $default = null): mixed
    {
        return $GLOBALS['breadcrumb_theme_test_values'][$key] ?? $default;
    }

    /** Return registry labels without loading translation catalogs.
     *
     * @param string $key Translation key.
     * @param string $fallback English fallback label.
     * @param array<string,scalar> $replace Optional replacement values.
     * @return string Fallback label.
     */
    function t(string $key, string $fallback, array $replace = []): string
    {
        return $fallback;
    }

    /** Return the minimal Theme state needed by the layout owner.
     *
     * @return array<string,string> Empty deterministic Theme values.
     */
    function theme_settings(): array
    {
        return [];
    }

    /** Return deterministic pagination state.
     *
     * @return array{enabled:bool,columns:int,rows:int} Pagination fixture.
     */
    function pagination_global_settings(): array
    {
        return ['enabled' => false, 'columns' => 4, 'rows' => 5];
    }

    /** Return deterministic home-grid state.
     *
     * @return array{columns:int,rows:int} Home grid fixture.
     */
    function main_page_gallery_grid_settings(): array
    {
        return ['columns' => 4, 'rows' => 5];
    }

    /** Return deterministic tag-grid state.
     *
     * @return array{columns:int,rows:int} Tag grid fixture.
     */
    function tag_page_gallery_grid_settings(): array
    {
        return ['columns' => 4, 'rows' => 5];
    }

    /** Normalize a fixture GPS pin size.
     *
     * @param scalar|array<array-key,mixed>|object|resource|null $value Any candidate size is ignored by this fixed-result fixture.
     * @return int Supported size.
     */
    function theme_gps_pin_size_value(mixed $value): int
    {
        return 26;
    }

    /** Normalize a fixture GPS background size.
     *
     * @param scalar|array<array-key,mixed>|object|resource|null $value Any candidate size is ignored by this fixed-result fixture.
     * @return int Supported size.
     */
    function theme_gps_pin_background_size_value(mixed $value): int
    {
        return 22;
    }

    /** Return the current fixture gallery-card layout.
     *
     * @return string Default card layout.
     */
    function theme_gallery_description_layout(): string
    {
        return 'vertical';
    }

    /** Report whether the fixture shows gallery-count badges.
     *
     * @return bool Badge visibility.
     */
    function theme_gallery_count_badge_enabled(): bool
    {
        return true;
    }

    /** Return the fixture tag-page card layout.
     *
     * @return string Tag-page card layout.
     */
    function tag_page_gallery_description_layout(): string
    {
        return 'vertical';
    }

    /** Return the fixture hero-tag limit.
     *
     * @return int Number of initially visible tags.
     */
    function theme_hero_tag_visible_limit(): int
    {
        return 20;
    }

    /** Report whether the fixture expands every hero tag.
     *
     * @return bool Expansion state.
     */
    function theme_hero_tag_display_all_enabled(): bool
    {
        return false;
    }

    /** Report whether fixture hero-tag scrolling is enabled.
     *
     * @return bool Scroll state.
     */
    function theme_hero_tag_scrollbar_enabled(): bool
    {
        return true;
    }

    /** Return the fixture hero-tag scrolling threshold.
     *
     * @return int Visible row threshold.
     */
    function theme_hero_tag_scrollbar_rows(): int
    {
        return 5;
    }

    /** Return the fixture hero-tag sort mode.
     *
     * @return string Supported sort mode.
     */
    function theme_hero_tag_sort_mode(): string
    {
        return 'usage';
    }

    /** Return the fixture lightbox mode.
     *
     * @return string Supported lightbox mode.
     */
    function theme_lightbox_browsing_mode(): string
    {
        return 'single';
    }

    /** Return supported fixture gallery-card layouts.
     *
     * @return list<string> Supported layouts.
     */
    function gallery_description_layout_options(): array
    {
        return ['vertical', 'horizontal'];
    }

    /** Return supported fixture lightbox modes.
     *
     * @return list<string> Supported modes.
     */
    function gallery_lightbox_browsing_mode_options(): array
    {
        return ['single', 'picture_strip', '3d_carousel'];
    }

    /** Capture one Theme preference write.
     *
     * @param string $key Canonical setting key.
     * @param string $value New setting value.
     * @return void Updates the in-memory application-setting fixture.
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['breadcrumb_theme_test_writes'][] = [$key, $value];
        $GLOBALS['breadcrumb_theme_test_values'][$key] = $value;
    }

    require_once __DIR__ . '/../app/services/breadcrumbs.php';
    require_once __DIR__ . '/../app/services/theme_layout_settings.php';
}

namespace Gallery\Tests\BreadcrumbThemeSettings {
    use function Gallery\Services\breadcrumb_style_normalize;
    use function Gallery\Services\breadcrumb_style_registry;
    use function Gallery\Services\theme_breadcrumb_style;
    use function Gallery\Services\theme_layout_safe_normalize;
    use function Gallery\Services\theme_layout_safe_save;
    use function Gallery\Services\theme_layout_safe_settings;

    /** Assert one breadcrumb setting contract.
     *
     * @param bool $condition Expected condition.
     * @param string $message Failure description.
     * @return void Throws when the expected contract is absent.
     */
    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    $GLOBALS['breadcrumb_theme_test_values'] = ['theme_breadcrumb_style' => 'unsupported'];
    $GLOBALS['breadcrumb_theme_test_writes'] = [];

    $expectedIds = ['minimal', 'chevron', 'pills', 'surface', 'ribbon', 'nodes', 'tabs', 'tiles', 'gradient'];
    $registry = breadcrumb_style_registry();
    assert_true(array_keys($registry) === $expectedIds, 'Breadcrumb style IDs or their stable order changed.');
    foreach ($registry as $styleId => $definition) {
        assert_true(is_string($definition['label'] ?? null) && is_string($definition['class'] ?? null), 'Registry entries must expose label and class metadata.');
        assert_true(breadcrumb_style_normalize($styleId) === $styleId, 'A registered style ID did not normalize to itself.');
        assert_true(theme_layout_safe_normalize('theme_breadcrumb_style', $styleId) === $styleId, 'A registered Theme style was rejected.');
    }

    // Compare the documented accepted values with the real registry, not a second fixture list.
    $settingsInventory = file_get_contents(__DIR__ . '/../docs/ADMIN_SETTINGS_INVENTORY.md');
    assert_true(is_string($settingsInventory), 'Could not read the Admin settings inventory.');
    assert_true(preg_match('/^\| `theme_breadcrumb_style` \|[^\r\n]+/m', $settingsInventory, $themeInventoryMatch) === 1,
        'The inventory must contain the Theme breadcrumb setting row.');
    $themeInventoryColumns = array_map('trim', explode('|', trim($themeInventoryMatch[0], "| \t\r\n")));
    preg_match_all('/`([^`]+)`/', $themeInventoryColumns[2], $documentedThemeStyles);
    assert_true($documentedThemeStyles[1] === array_keys($registry),
        'The inventory Theme enum must list every registered visual style exactly once, without inherit.');
    preg_match_all('/`([^`]+)`/', $themeInventoryColumns[3], $documentedThemeFallbacks);
    assert_true(array_values(array_unique($documentedThemeFallbacks[1])) === [\Gallery\Services\BREADCRUMB_STYLE_DEFAULT],
        'The inventory Theme fallback must match the shared breadcrumb default.');

    assert_true(preg_match('/^\d+\. `gallery_breadcrumb_style\.<gallery-id>` accepts ([^.]+)\.([^\r\n]+)/m',
        $settingsInventory, $galleryInventoryMatch) === 1,
        'The inventory must document the physical-gallery breadcrumb override.');
    preg_match_all('/`([^`]+)`/', $galleryInventoryMatch[1], $documentedGalleryStyles);
    assert_true($documentedGalleryStyles[1] === [...array_keys($registry), \Gallery\Services\BREADCRUMB_STYLE_INHERIT],
        'The inventory gallery values must list every registered style and the gallery-only inherit sentinel.');
    assert_true(preg_match('/Missing or invalid gallery values inherit `([^`]+)`; missing or invalid Theme values fall back to `([^`]+)`/',
        $galleryInventoryMatch[2], $documentedGalleryFallbacks) === 1,
        'The inventory must document the gallery-to-Theme fallback order.');
    assert_true($documentedGalleryFallbacks[1] === 'theme_breadcrumb_style'
        && $documentedGalleryFallbacks[2] === \Gallery\Services\BREADCRUMB_STYLE_DEFAULT,
        'The inventory gallery fallback must inherit Theme before using the shared default.');

    assert_true(breadcrumb_style_normalize('invalid') === 'chevron', 'Invalid global breadcrumb styles must fall back to chevron.');
    assert_true(theme_breadcrumb_style() === 'chevron', 'Theme getter did not normalize an invalid saved style.');
    assert_true(theme_layout_safe_settings()['theme_breadcrumb_style'] === 'chevron', 'Theme layout settings did not expose the normalized global value.');
    assert_true(theme_layout_safe_normalize('theme_breadcrumb_style', ['invalid']) === 'chevron', 'Malformed Theme values must use the safe style fallback.');

    foreach ($expectedIds as $styleId) {
        $GLOBALS['breadcrumb_theme_test_writes'] = [];
        theme_layout_safe_save('theme_breadcrumb_style', $styleId);
        assert_true(($GLOBALS['breadcrumb_theme_test_values']['theme_breadcrumb_style'] ?? '') === $styleId, 'A registered style ID was not saved.');
        assert_true(($GLOBALS['breadcrumb_theme_test_writes'][0] ?? null) === ['theme_breadcrumb_style', $styleId], 'Theme style save did not use the canonical setting key.');
        assert_true(($GLOBALS['breadcrumb_theme_test_writes'][1][0] ?? '') === 'theme_public_content_revision', 'A changed breadcrumb style did not bump the public-content revision.');
        assert_true(is_numeric($GLOBALS['breadcrumb_theme_test_writes'][1][1] ?? null), 'Public-content revision value was not a timestamp.');
    }

    $lastSavedStyle = $expectedIds[array_key_last($expectedIds)];
    $GLOBALS['breadcrumb_theme_test_writes'] = [];
    theme_layout_safe_save('theme_breadcrumb_style', $lastSavedStyle);
    assert_true($GLOBALS['breadcrumb_theme_test_writes'] === [['theme_breadcrumb_style', $lastSavedStyle]], 'Saving the same style must not bump the public-content revision.');

    $themeController = file_get_contents(__DIR__ . '/../app/controllers/admin_theme_actions.php');
    assert_true(is_string($themeController), 'Could not read the Theme controller source.');
    assert_true(
        preg_match('/if\s*\(\s*array_key_exists\(\s*[\'\"]theme_breadcrumb_style[\'\"]\s*,\s*\$_POST\s*\)\s*\)\s*\{\s*theme_layout_safe_save\(\s*[\'\"]theme_breadcrumb_style[\'\"]\s*,/s', $themeController) === 1,
        'Theme POST must save this setting only when the field is present, preserving omitted-field values.'
    );
    $themeLayoutView = file_get_contents(__DIR__ . '/../app/views/admin_theme.php');
    assert_true(is_string($themeLayoutView)
        && str_contains($themeLayoutView, "view_render_breadcrumb_style_picker((array) (\$viewModel['breadcrumb_style_picker'] ?? []))")
        && !str_contains($themeLayoutView, '<select name="theme_breadcrumb_style"'),
        'Theme layout must render the prepared shared radio-card picker instead of a select.');
    $themeLayoutController = file_get_contents(__DIR__ . '/../app/controllers/admin_theme_layout.php');
    assert_true(is_string($themeLayoutController)
        && str_contains($themeLayoutController, "'field_name' => 'theme_breadcrumb_style'")
        && str_contains($themeLayoutController, 'breadcrumb_style_picker_options()'),
        'Theme controller must prepare the shared picker with its canonical persistence name.');

    echo "breadcrumb_theme_settings_test: PASS\n";
}
