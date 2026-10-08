<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_original_asset_authorization_test.php
 * Module Type: Regression Test
 * Purpose: Require administrator authorization before streaming retained theme background originals.
 * Responsibilities: Exercise anonymous original denial, administrator original access, and public derivative delivery.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Provide the isolated administrator identity for theme asset authorization.
     *
     * @return array<string,int>|false The current fixture administrator or false.
     */
    function current_user(): array|false
    {
        return $GLOBALS['original_asset_test_user'] ?? false;
    }
}

namespace Gallery\Services {
    /**
     * Resolve the fixture original only after the controller authorization check.
     *
     * @return string|null Configured original file path or null.
     */
    function theme_background_original_path(): ?string
    {
        $GLOBALS['original_asset_test_original_reads']++;
        return $GLOBALS['original_asset_test_original_path'] ?? null;
    }

    /**
     * Resolve the independently public derivative for this fixture.
     *
     * @return string|null Configured derivative file path or null.
     */
    function theme_background_served_path(): ?string
    {
        $GLOBALS['original_asset_test_served_reads']++;
        return $GLOBALS['original_asset_test_served_path'] ?? null;
    }

    /**
     * Model the supported PNG image kind for this isolated authorization fixture.
     *
     * @param string $mime Media type of the synthetic asset.
     * @return string|null The PNG extension or null for unsupported media.
     */
    function gallery_branding_mime_extension(string $mime): ?string
    {
        return $mime === 'image/png' ? 'png' : null;
    }
}

namespace Gallery\Controllers {
    /**
     * Assign the expected safe raster MIME to the synthetic fixture bytes.
     *
     * @param string $filename Local fixture path.
     * @return string The fixture's PNG MIME type.
     */
    function mime_content_type(string $filename): string
    {
        return 'image/png';
    }

    /**
     * Record the expected opaque 404 without rendering a production error page.
     *
     * @return void Records the requested failure.
     */
    function cms_not_found(): void
    {
        $GLOBALS['original_asset_test_not_found']++;
    }

    /**
     * Capture cache policy without altering the isolated process headers.
     *
     * @param string $policy Header value selected for the asset.
     * @return void Records the cache policy.
     */
    function send_asset_cache_control(string $policy): void
    {
        $GLOBALS['original_asset_test_cache'] = $policy;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/controllers/theme_assets.php';

    /**
     * Throw when the theme asset controller violates one of its access rules.
     *
     * @param bool $value Expected test condition.
     * @param string $label Regression case label.
     * @return void No return value for a passing assertion.
     */
    function theme_original_asset_assert(bool $value, string $label): void
    {
        if (!$value) {
            throw new \RuntimeException($label);
        }
    }

    $repositoryRoot = dirname(__DIR__);
    $fixtureName = 'theme-original-access-' . bin2hex(random_bytes(8));
    $originalRelative = 'cache/' . $fixtureName . '-original.png';
    $servedRelative = 'cache/' . $fixtureName . '-served.png';
    $original = $repositoryRoot . '/' . $originalRelative;
    $served = $repositoryRoot . '/' . $servedRelative;
    if (!is_dir($repositoryRoot . '/cache')) {
        throw new \RuntimeException('Disposable cache directory is unavailable.');
    }
    if (file_put_contents($original, 'private original fixture') === false || file_put_contents($served, 'public derivative fixture') === false) {
        throw new \RuntimeException('Unable to create isolated theme background files.');
    }

    $GLOBALS['original_asset_test_original_path'] = $originalRelative;
    $GLOBALS['original_asset_test_served_path'] = $servedRelative;
    try {
        $_GET = ['variant' => 'original'];
        $GLOBALS['original_asset_test_user'] = false;
        $GLOBALS['original_asset_test_not_found'] = 0;
        $GLOBALS['original_asset_test_original_reads'] = 0;
        $GLOBALS['original_asset_test_served_reads'] = 0;
        $GLOBALS['original_asset_test_cache'] = '';
        ob_start();
        \Gallery\Controllers\cms_theme_background_asset();
        $response = ob_get_clean();
        theme_original_asset_assert($response === '' && $GLOBALS['original_asset_test_not_found'] === 1, 'Anonymous original must return a 404 without body.');
        theme_original_asset_assert($GLOBALS['original_asset_test_original_reads'] === 0 && $GLOBALS['original_asset_test_served_reads'] === 0, 'Unauthorized original request must not resolve either background path.');

        $GLOBALS['original_asset_test_user'] = ['id' => 1];
        $GLOBALS['original_asset_test_not_found'] = 0;
        ob_start();
        \Gallery\Controllers\cms_theme_background_asset();
        $response = ob_get_clean();
        theme_original_asset_assert($response === 'private original fixture' && $GLOBALS['original_asset_test_not_found'] === 0, 'Authenticated administrator must retrieve original.');
        theme_original_asset_assert($GLOBALS['original_asset_test_cache'] === 'private, no-cache, max-age=0, must-revalidate', 'Original asset must remain privately cached.');

        $_GET = [];
        $GLOBALS['original_asset_test_user'] = false;
        $GLOBALS['original_asset_test_not_found'] = 0;
        $GLOBALS['original_asset_test_cache'] = '';
        ob_start();
        \Gallery\Controllers\cms_theme_background_asset();
        $response = ob_get_clean();
        theme_original_asset_assert($response === 'public derivative fixture' && $GLOBALS['original_asset_test_not_found'] === 0, 'Anonymous visitors must retain the public theme derivative.');
        theme_original_asset_assert($GLOBALS['original_asset_test_cache'] === 'public, max-age=31536000, immutable', 'Public derivative must remain publicly cacheable.');
        echo "Theme original asset access contract passed.\n";
    } finally {
        @unlink($original);
        @unlink($served);
    }
}
