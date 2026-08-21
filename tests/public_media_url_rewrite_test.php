<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_media_url_rewrite_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies public image media and thumbnail URLs in both rewritten and query-string routing modes.
 *
 * Responsibilities:
 *   - Cover rewrite-disabled image, media, and thumbnail fallback URLs
 *   - Cover request-local public media manifest URLs used by selected-gallery photo cards
 *   - Prevent path suffixes from being appended after index.php query strings
 *   - Keep clean rewritten media and thumbnail URLs unchanged
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

namespace Gallery\Services {
    /**
     * Test shim indicating that hierarchical public paths are installed.
     *
     * @return bool True for this isolated routing test.
     */
    function public_path_schema_ready(): bool
    {
        return true;
    }

    /**
     * Legacy thumbnail route shim retained for the non-public-path branch.
     *
     * @param array $image Image row or image data.
     * @param array $gallery Gallery row or gallery data.
     * @param int $size Thumbnail size.
     * @param string $format Thumbnail format.
     * @return string Legacy thumbnail URL.
     */
    function thumbnail_serving_url(array $image, array $gallery, int $size, string $format = 'jpg'): string
    {
        unset($gallery);
        return \Gallery\Core\url_for('thumb', [
            'id' => (int) ($image['id'] ?? 0),
            'size' => $size,
            'format' => $format,
        ]);
    }
}

namespace {
    use function Gallery\Core\image_public_asset_version;
    use function Gallery\Core\image_public_media_url;
    use function Gallery\Core\image_public_thumbnail_url;
    use function Gallery\Core\image_public_url;
    use function Gallery\Services\public_gallery_media_manifest_image_base_url;
    use function Gallery\Services\public_gallery_media_manifest_media_url;
    use function Gallery\Services\public_gallery_media_manifest_variant_url;

    /**
     * Minimal config shim used by helpers.php in this isolated test process.
     *
     * @return array<string,mixed> Test configuration.
     */
    function cms_config(): array
    {
        return ['base_url' => 'https://example.test'];
    }

    require_once __DIR__ . '/../app/services/app_settings.php';
    require_once __DIR__ . '/../app/helpers.php';
    require_once __DIR__ . '/../app/services/public_gallery_media_manifest.php';

    /**
     * Throw when an expectation fails.
     *
     * @param mixed $expected Expected value.
     * @param mixed $actual Actual value.
     * @param string $label Assertion label.
     */
    function assert_public_media_url_same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    $gallery = [
        'url_path' => 'Test na Macu/VLC snapy',
        'folder_path' => 'Test na Macu/VLC snapy',
        'slug' => 'vlc-snapy',
        'title' => 'VLC snapy',
    ];
    $image = [
        'id' => 42,
        'gallery_id' => 7,
        'url_slug' => 'test na macu vlc snapy 0001',
        'filename' => 'test_na_macu_vlc_snapy_0001.jpg',
        'relative_path_hash' => hash('sha256', 'test_na_macu_vlc_snapy_0001.jpg'),
        'checksum_sha256' => str_repeat('a', 64),
        'modified_at' => '2026-08-21 08:30:00',
        'file_size' => 123456,
        'thumbnail_derivative_version' => 3,
    ];

    $GLOBALS['cms_app_settings_cache'] = ['url_rewrite_enabled' => '0'];
    $queryImageUrl = 'https://example.test/index.php?page=gallery&public_path=Test+na+Macu%2FVLC+snapy%2Ftest-na-macu-vlc-snapy-0001';
    $assetVersion = image_public_asset_version($image);
    $queryMediaUrl = 'https://example.test/index.php?page=public_media&public_path=Test+na+Macu%2FVLC+snapy%2Ftest-na-macu-vlc-snapy-0001&v=' . $assetVersion;
    $queryThumbnailUrl = 'https://example.test/index.php?page=public_thumb&public_path=Test+na+Macu%2FVLC+snapy%2Ftest-na-macu-vlc-snapy-0001&size=300&format=webp&v=' . $assetVersion;

    assert_public_media_url_same($queryImageUrl, image_public_url($image, $gallery), 'rewrite-disabled image URL');
    assert_public_media_url_same($queryMediaUrl, image_public_media_url($image, $gallery), 'rewrite-disabled media URL');
    assert_public_media_url_same($queryThumbnailUrl, image_public_thumbnail_url($image, $gallery, 300, 'webp'), 'rewrite-disabled thumbnail URL');

    $queryBaseUrl = public_gallery_media_manifest_image_base_url($image, $gallery);
    assert_public_media_url_same($queryImageUrl, $queryBaseUrl, 'rewrite-disabled manifest image base URL');
    assert_public_media_url_same($queryMediaUrl, public_gallery_media_manifest_media_url($image, $gallery, $queryBaseUrl), 'rewrite-disabled manifest media URL');
    assert_public_media_url_same($queryThumbnailUrl, public_gallery_media_manifest_variant_url($image, $gallery, $queryBaseUrl, 300, 'webp'), 'rewrite-disabled manifest thumbnail URL');

    $GLOBALS['cms_app_settings_cache'] = ['url_rewrite_enabled' => '1'];
    $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
    $_SERVER['REQUEST_URI'] = '/gallery/Test%20na%20Macu/VLC%20snapy/test-na-macu-vlc-snapy-0001/';
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    $cleanImageUrl = 'https://example.test/gallery/Test%20na%20Macu/VLC%20snapy/test-na-macu-vlc-snapy-0001/';
    $cleanMediaUrl = $cleanImageUrl . 'media?v=' . $assetVersion;
    $cleanThumbnailUrl = $cleanImageUrl . 'thumb-300.webp?v=' . $assetVersion;

    assert_public_media_url_same($cleanImageUrl, image_public_url($image, $gallery), 'rewrite-enabled image URL');
    assert_public_media_url_same($cleanMediaUrl, image_public_media_url($image, $gallery), 'rewrite-enabled media URL');
    assert_public_media_url_same($cleanThumbnailUrl, image_public_thumbnail_url($image, $gallery, 300, 'webp'), 'rewrite-enabled thumbnail URL');

    $cleanBaseUrl = public_gallery_media_manifest_image_base_url($image, $gallery);
    assert_public_media_url_same(rtrim($cleanImageUrl, '/'), $cleanBaseUrl, 'rewrite-enabled manifest image base URL');
    assert_public_media_url_same($cleanMediaUrl, public_gallery_media_manifest_media_url($image, $gallery, $cleanBaseUrl), 'rewrite-enabled manifest media URL');
    assert_public_media_url_same($cleanThumbnailUrl, public_gallery_media_manifest_variant_url($image, $gallery, $cleanBaseUrl, 300, 'webp'), 'rewrite-enabled manifest thumbnail URL');

    $replacement = $image;
    $replacement['id'] = 43;
    assert_public_media_url_same(false, image_public_asset_version($replacement) === $assetVersion, 'delete/re-upload image id changes immutable media cache version');

    $replacement = $image;
    $replacement['thumbnail_derivative_version'] = 4;
    assert_public_media_url_same(false, image_public_asset_version($replacement) === $assetVersion, 'thumbnail invalidation changes immutable media cache version');

    echo "Public media URL rewrite tests passed.\n";
}
