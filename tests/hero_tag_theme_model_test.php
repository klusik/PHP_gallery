<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/hero_tag_theme_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the gallery hero tag Theme model and browser/server integration contracts without a database or browser.
 *
 * Responsibilities:
 *   - Cover safe defaults, clamping, boolean behavior, and sort normalization
 *   - Verify Admin Theme persistence uses the centralized normalizers
 *   - Verify public hero markup keeps every tag server-rendered and exposes browser configuration
 *   - Verify both public browser entrypoints initialize the progressive disclosure module
 *   - Verify full-width tag-list CSS overrides the generic readable-paragraph width limit
 *   - Verify English and Czech JSON catalogs contain the public disclosure strings
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
 *   2026-08-11
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Minimal database accessor used by the tag-usage sorting fixture.
     *
     * @return object Fake PDO-compatible object for the focused aggregate query.
     */
    function db(): object
    {
        return $GLOBALS['hero_tag_theme_test_db'];
    }
}

namespace Gallery\Services {
    $GLOBALS['hero_tag_theme_test_settings'] = [];

    /**
     * Minimal app_settings reader used by the standalone Theme model test.
     *
     * @param string $key Setting key.
     * @param ?string $default Default value.
     * @return ?string Stored or default value.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $GLOBALS['hero_tag_theme_test_settings'])
            ? (string) $GLOBALS['hero_tag_theme_test_settings'][$key]
            : $default;
    }
}

namespace {
    use function Gallery\Services\theme_hero_tag_display_all_enabled;
    use function Gallery\Services\theme_hero_tag_scrollbar_enabled;
    use function Gallery\Services\theme_hero_tag_scrollbar_rows;
    use function Gallery\Services\theme_hero_tag_scrollbar_rows_value;
    use function Gallery\Services\theme_hero_tag_sort_mode;
    use function Gallery\Services\theme_hero_tag_sort_mode_normalize;
    use function Gallery\Services\theme_hero_tag_visible_limit;
    use function Gallery\Services\theme_hero_tag_visible_limit_value;
    use function Gallery\Services\sort_public_hero_tag_groups;
    use function Gallery\Services\tag_usage_counts;

    require_once __DIR__ . '/../app/services/theme.php';
    require_once __DIR__ . '/../app/services/tag_metadata.php';

    /**
     * Throw when two hero-tag model values are not identical.
     *
     * @param mixed $expected Expected value.
     * @param mixed $actual Actual value.
     * @param string $label Assertion label.
     */
    function assert_hero_tag_theme_same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    /**
     * Require one source fragment to remain present as an integration contract.
     *
     * @param string $source Source text.
     * @param string $needle Required fragment.
     * @param string $label Assertion label.
     */
    function assert_hero_tag_source_contains(string $source, string $needle, string $label): void
    {
        if (!str_contains($source, $needle)) {
            throw new RuntimeException($label . ' is missing required source fragment: ' . $needle);
        }
    }

    assert_hero_tag_theme_same(20, theme_hero_tag_visible_limit_value(null), 'missing visible limit uses default');
    assert_hero_tag_theme_same(20, theme_hero_tag_visible_limit_value(0), 'zero visible limit uses default');
    assert_hero_tag_theme_same(1, theme_hero_tag_visible_limit_value(1), 'minimum visible limit accepted');
    assert_hero_tag_theme_same(200, theme_hero_tag_visible_limit_value(999), 'visible limit clamps high');
    assert_hero_tag_theme_same(5, theme_hero_tag_scrollbar_rows_value(null), 'missing scrollbar rows uses default');
    assert_hero_tag_theme_same(1, theme_hero_tag_scrollbar_rows_value(1), 'minimum scrollbar rows accepted');
    assert_hero_tag_theme_same(12, theme_hero_tag_scrollbar_rows_value(99), 'scrollbar rows clamp high');
    assert_hero_tag_theme_same('usage', theme_hero_tag_sort_mode_normalize(null), 'missing sort mode defaults to usage');
    assert_hero_tag_theme_same('usage', theme_hero_tag_sort_mode_normalize('invalid'), 'invalid sort mode defaults to usage');
    assert_hero_tag_theme_same('alphabetical', theme_hero_tag_sort_mode_normalize('alphabetical'), 'alphabetical mode accepted');

    assert_hero_tag_theme_same(20, theme_hero_tag_visible_limit(), 'persisted visible limit default is 20');
    assert_hero_tag_theme_same(false, theme_hero_tag_display_all_enabled(), 'display-all default keeps disclosure enabled');
    assert_hero_tag_theme_same(true, theme_hero_tag_scrollbar_enabled(), 'scrollbar default is enabled');
    assert_hero_tag_theme_same(5, theme_hero_tag_scrollbar_rows(), 'scrollbar row default is five');
    assert_hero_tag_theme_same('usage', theme_hero_tag_sort_mode(), 'usage sort is default');

    $GLOBALS['hero_tag_theme_test_settings'] = [
        'theme_hero_tag_visible_limit' => '37',
        'theme_hero_tag_display_all' => '1',
        'theme_hero_tag_scrollbar_enabled' => '0',
        'theme_hero_tag_scrollbar_rows' => '8',
        'theme_hero_tag_sort_mode' => 'alphabetical',
    ];
    assert_hero_tag_theme_same(37, theme_hero_tag_visible_limit(), 'stored visible limit resolves');
    assert_hero_tag_theme_same(true, theme_hero_tag_display_all_enabled(), 'stored display-all resolves');
    assert_hero_tag_theme_same(false, theme_hero_tag_scrollbar_enabled(), 'stored scrollbar disable resolves');
    assert_hero_tag_theme_same(8, theme_hero_tag_scrollbar_rows(), 'stored scrollbar rows resolve');
    assert_hero_tag_theme_same('alphabetical', theme_hero_tag_sort_mode(), 'stored alphabetical sort resolves');

    // The fake database returns the aggregate rows that the real bounded UNION query would return.
    $GLOBALS['hero_tag_theme_test_db'] = new class {
        /** @var string Last prepared SQL for contract assertions. */
        public string $sql = '';

        /**
         * Return a minimal statement fixture for the usage aggregate.
         *
         * @param string $sql Prepared SQL.
         * @return object Statement-like fixture.
         */
        public function prepare(string $sql): object
        {
            $this->sql = $sql;
            return new class {
                /**
                 * Accept bounded tag-ID parameters.
                 *
                 * @param array $parameters Query parameters.
                 */
                public function execute(array $parameters): void
                {
                    if ($parameters !== [1, 2, 3, 1, 2, 3]) {
                        throw new RuntimeException('Hero tag usage query did not bind the expected bounded tag IDs twice.');
                    }
                }

                /**
                 * Return deterministic aggregate counts for sorting tests.
                 *
                 * @return array<int,array{tag_id:int,usage_count:int}> Aggregate fixture rows.
                 */
                public function fetchAll(): array
                {
                    return [
                        ['tag_id' => 1, 'usage_count' => 2],
                        ['tag_id' => 2, 'usage_count' => 8],
                        ['tag_id' => 3, 'usage_count' => 8],
                    ];
                }
            };
        }
    };
    assert_hero_tag_theme_same([1 => 2, 2 => 8, 3 => 8], tag_usage_counts([3, 1, 2, 2]), 'usage aggregate normalizes IDs and maps counts');
    assert_hero_tag_source_contains($GLOBALS['hero_tag_theme_test_db']->sql, 'FROM gallery_tags', 'usage aggregate includes gallery assignments');
    assert_hero_tag_source_contains($GLOBALS['hero_tag_theme_test_db']->sql, 'FROM image_tags', 'usage aggregate includes photo assignments');

    $unsortedGroups = [
        'gallery' => [
            ['id' => 1, 'name' => 'Zulu'],
            ['id' => 3, 'name' => 'Beta'],
            ['id' => 2, 'name' => 'Alpha'],
        ],
        'contained' => [
            ['id' => 1, 'name' => 'Zulu'],
            ['id' => 2, 'name' => 'Alpha'],
        ],
    ];
    $usageSortedGroups = sort_public_hero_tag_groups($unsortedGroups, 'usage');
    assert_hero_tag_theme_same([2, 3, 1], array_column($usageSortedGroups['gallery'], 'id'), 'usage mode sorts highest counts first with alphabetical ties');
    assert_hero_tag_theme_same([2, 1], array_column($usageSortedGroups['contained'], 'id'), 'usage mode preserves and independently sorts contained group');
    $alphabeticalGroups = sort_public_hero_tag_groups($unsortedGroups, 'alphabetical');
    assert_hero_tag_theme_same(['Alpha', 'Beta', 'Zulu'], array_column($alphabeticalGroups['gallery'], 'name'), 'alphabetical mode sorts natural names');
    assert_hero_tag_theme_same(['Alpha', 'Zulu'], array_column($alphabeticalGroups['contained'], 'name'), 'alphabetical mode preserves contained group boundary');

    $adminThemeSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_theme.php');
    assert_hero_tag_source_contains($adminThemeSource, "theme_hero_tag_visible_limit_value(\$_POST['theme_hero_tag_visible_limit'] ?? null)", 'Admin visible-limit normalization');
    assert_hero_tag_source_contains($adminThemeSource, "theme_hero_tag_sort_mode_normalize(\$_POST['theme_hero_tag_sort_mode'] ?? 'usage')", 'Admin sort normalization');
    assert_hero_tag_source_contains($adminThemeSource, "set_app_setting('theme_hero_tag_scrollbar_enabled'", 'Admin scrollbar persistence');

    $publicGallerySource = (string) file_get_contents(__DIR__ . '/../app/controllers/public_gallery.php');
    assert_hero_tag_source_contains($publicGallerySource, 'sort_public_hero_tag_groups($heroTagGroups, theme_hero_tag_sort_mode())', 'public server-side hero sort');
    assert_hero_tag_source_contains($publicGallerySource, 'data-hero-tags data-hero-tag-visible-limit=', 'public hero configuration markup');
    assert_hero_tag_source_contains($publicGallerySource, "render_tag_list(\$heroTagGroups['gallery'])", 'direct tags remain server-rendered');
    assert_hero_tag_source_contains($publicGallerySource, "render_tag_list(\$heroTagGroups['contained']", 'contained tags remain server-rendered');
    assert_hero_tag_source_contains($publicGallerySource, "t('gallery.show_all_tags', 'Display all tags')", 'public disclosure translation');

    $heroTagBrowserSource = (string) file_get_contents(__DIR__ . '/../public/assets/gallery-modules/hero-tags.js');
    assert_hero_tag_source_contains($heroTagBrowserSource, 'export function setupHeroTagDisclosure()', 'hero browser setup export');
    assert_hero_tag_source_contains($heroTagBrowserSource, 'tag.hidden = !expanded && index >= visibleLimit;', 'pure client-side tag visibility');
    assert_hero_tag_source_contains($heroTagBrowserSource, "toggle.setAttribute('aria-expanded'", 'accessible disclosure state');
    assert_hero_tag_source_contains($heroTagBrowserSource, "'ResizeObserver' in window", 'responsive row measurement');
    assert_hero_tag_source_contains($heroTagBrowserSource, "content.classList.add('is-scrollable')", 'conditional scrollbar activation');

    $anonymousEntrypoint = (string) file_get_contents(__DIR__ . '/../public/assets/public-gallery.js');
    $adminEntrypoint = (string) file_get_contents(__DIR__ . '/../public/assets/gallery.js');
    assert_hero_tag_source_contains($anonymousEntrypoint, "'setupHeroTagDisclosure', '[data-hero-tags]'", 'anonymous hero initialization');
    assert_hero_tag_source_contains($adminEntrypoint, 'setupHeroTagDisclosure();', 'logged-in hero initialization');

    foreach (['public-shared.css', 'admin-layout.css', 'admin.css'] as $stylesheet) {
        $cssSource = (string) file_get_contents(__DIR__ . '/../public/assets/styles/' . $stylesheet);
        assert_hero_tag_source_contains($cssSource, '.public-page .hero .tag-list {', $stylesheet . ' hero tag-list rule');
        assert_hero_tag_source_contains($cssSource, 'max-width: none;', $stylesheet . ' full-width hero tag override');
        assert_hero_tag_source_contains($cssSource, '.public-page .hero-tags-content.is-scrollable {', $stylesheet . ' conditional hero scrollbar rule');
    }

    foreach (['en', 'cs'] as $language) {
        $catalog = json_decode((string) file_get_contents(__DIR__ . '/../app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['gallery.show_all_tags', 'gallery.show_fewer_tags', 'admin.theme.appearance.hero_tag_sort_usage'] as $key) {
            if (!isset($catalog[$key]) || trim((string) $catalog[$key]) === '') {
                throw new RuntimeException($language . ' JSON catalog is missing ' . $key);
            }
        }
    }

    echo "Hero tag Theme model tests passed.\n";
}
