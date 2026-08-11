<?php

/**
 * Focused regression checks for public tag-page Theme overrides.
 */

declare(strict_types=1);

namespace Gallery\Services {
    $GLOBALS['tag_page_theme_test_settings'] = [];

    /**
     * Return a fixture setting without requiring a database.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $GLOBALS['tag_page_theme_test_settings'])
            ? (string) $GLOBALS['tag_page_theme_test_settings'][$key]
            : $default;
    }

    /**
     * Minimal translation fallback for layout labels loaded by the service.
     */
    function t(string $key, string $fallback = '', array $replace = []): string
    {
        unset($key, $replace);
        return $fallback;
    }
}

namespace {
    use function Gallery\Services\tag_page_gallery_description_layout;
    use function Gallery\Services\tag_page_gallery_grid_settings;

    require_once __DIR__ . '/../app/services/pagination.php';
    require_once __DIR__ . '/../app/services/gallery_description_layout.php';

    /**
     * Fail the script when two focused values differ.
     */
    function assert_tag_page_same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
            exit(1);
        }
    }

    $GLOBALS['tag_page_theme_test_settings'] = [
        'pagination_enabled' => '1',
        'pagination_columns' => '3',
        'pagination_rows' => '4',
        'theme_gallery_description_layout' => 'vertical',
    ];
    $inheritedGrid = tag_page_gallery_grid_settings();
    assert_tag_page_same(3, $inheritedGrid['columns'], 'Unsaved tag grid inherits global columns');
    assert_tag_page_same(4, $inheritedGrid['rows'], 'Unsaved tag grid inherits global rows');
    assert_tag_page_same('vertical', tag_page_gallery_description_layout(), 'Unsaved tag cards inherit global design');

    $GLOBALS['tag_page_theme_test_settings']['tag_page_gallery_grid_columns'] = '5';
    $GLOBALS['tag_page_theme_test_settings']['tag_page_gallery_grid_rows'] = '2';
    $GLOBALS['tag_page_theme_test_settings']['tag_page_gallery_description_layout'] = 'horizontal';
    $overriddenGrid = tag_page_gallery_grid_settings();
    assert_tag_page_same(5, $overriddenGrid['columns'], 'Tag grid overrides global columns');
    assert_tag_page_same(2, $overriddenGrid['rows'], 'Tag grid overrides global rows');
    assert_tag_page_same(10, $overriddenGrid['items_per_page'], 'Tag grid derives page capacity');
    assert_tag_page_same('horizontal', tag_page_gallery_description_layout(), 'Tag cards override global design');

    $publicTagsSource = (string) file_get_contents(__DIR__ . '/../app/controllers/public_tags.php');
    assert_tag_page_same(true, str_contains($publicTagsSource, 'pagination_grid_columns_class($tagGridSettings)'), 'Tag page applies its grid class');
    assert_tag_page_same(true, str_contains($publicTagsSource, "['description_layout' => \$tagCardLayout]"), 'Tag page applies its card design');

    echo "Tag page Theme model tests passed.\n";
}
