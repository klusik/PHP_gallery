<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_upload_replacement_safety_test.php
 * Module Type: Regression Test
 * Purpose: Keep existing Theme media intact when a replacement upload fails.
 * Responsibilities: Drive isolated upload services with fault-injected move and rename operations.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Return a stored Theme setting from an isolated in-memory map.
     *
     * @param string $key Setting identifier.
     * @param string $default Fallback for an unset key.
     * @return string Stored fixture value.
     */
    function app_setting(string $key, string $default = ''): string
    {
        return (string) ($GLOBALS['theme_replace_settings'][$key] ?? $default);
    }

    /**
     * Store a Theme preference without opening a database.
     *
     * @param string $key Setting identifier.
     * @param string $value Saved value.
     * @return void No result.
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['theme_replace_settings'][$key] = $value;
    }

    /**
     * Translate errors and asset names within the isolated fixture.
     *
     * @param string $key Translation key.
     * @param string $fallback Optional English translation.
     * @return string Deterministic message.
     */
    function t(string $key, string $fallback = ''): string
    {
        return $fallback !== '' ? $fallback : $key;
    }

    /**
     * Simulate verified uploaded-file provenance for disposable fixture paths.
     *
     * @param string $path Disposable incoming asset.
     * @return bool Whether the owned fixture file exists.
     */
    function is_uploaded_file(string $path): bool
    {
        return is_file($path);
    }

    /**
     * Simulate a browser-owned upload move with a controllable storage failure.
     *
     * @param string $source Disposable uploaded file.
     * @param string $destination Test-owned temporary stage.
     * @return bool True when the fixture file was transferred.
     */
    function move_uploaded_file(string $source, string $destination): bool
    {
        return empty($GLOBALS['theme_replace_fail_move']) && \rename($source, $destination);
    }

    /**
     * Inject failure only at the final install rename, after successful upload staging.
     *
     * @param string $source Staged Theme file.
     * @param string $destination Final Theme filename.
     * @return bool True when the actual filesystem rename succeeded.
     */
    function rename(string $source, string $destination): bool
    {
        return empty($GLOBALS['theme_replace_fail_rename']) && \rename($source, $destination);
    }

    /**
     * Provide safe decoded image metadata from fixture-controlled incoming bytes.
     *
     * The production controller validates actual file pixels before invoking the
     * service. This test isolates the failure ordering in the storing service.
     *
     * @param string $path Fixture upload filename.
     * @return array{mime:string,0:int,1:int} Supported raster-image metadata.
     */
    function getimagesize(string $path): array
    {
        return ['mime' => 'image/png', 0 => 1, 1 => 1];
    }
}

namespace {
    /**
     * Fail when a Theme replacement violates an existing-asset preservation rule.
     *
     * @param bool $condition Expected media or setting invariant.
     * @param string $message Failure description.
     * @return void No result when the invariant holds.
     */
    function theme_replacement_check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Create an isolated incoming image descriptor for one upload attempt.
     *
     * @param string $path Owned incoming upload path.
     * @param string $payload Synthetic asset bytes.
     * @return array{name:string,tmp_name:string,error:int,size:int} PHP upload descriptor.
     */
    function theme_replacement_upload(string $path, string $payload): array
    {
        file_put_contents($path, $payload);
        return [
            'name' => 'replacement.png',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($payload),
        ];
    }

    /**
     * Require a replacement service to reject injected filesystem failure.
     *
     * @param callable():string $operation One Theme upload attempt.
     * @param string $message Failure description.
     * @return void No value after expected rejection.
     */
    function theme_replacement_refused(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (RuntimeException) {
            return;
        }
        throw new RuntimeException($message . ': the injected failure was accepted.');
    }

    $root = sys_get_temp_dir() . '/php-gallery-theme-replace-' . bin2hex(random_bytes(10));
    $serviceDir = $root . '/app/services';
    $backgroundDir = $root . '/cache/theme-background';
    $brandingDir = $root . '/cache/theme-branding';
    foreach ([$serviceDir, $backgroundDir, $brandingDir] as $directory) {
        if (!mkdir($directory, 0700, true)) {
            throw new RuntimeException('Unable to create disposable Theme module fixture.');
        }
    }
    if (!mkdir($serviceDir . '/gallery_backgrounds', 0700, true)) {
        throw new RuntimeException('Unable to create the disposable Theme background module part directory.');
    }
    foreach (['gallery_backgrounds.php', 'gallery_branding.php'] as $module) {
        if (!copy(dirname(__DIR__) . '/app/services/' . $module, $serviceDir . '/' . $module)) {
            throw new RuntimeException('Unable to stage isolated Theme service.');
        }
    }
    if (!copy(dirname(__DIR__) . '/app/services/gallery_backgrounds/visual_css_save.php', $serviceDir . '/gallery_backgrounds/visual_css_save.php')) {
        throw new RuntimeException('Unable to stage the isolated Theme background module part.');
    }
    file_put_contents($serviceDir . '/gallery_edit_concurrency.php', "<?php\n");
    require $serviceDir . '/gallery_backgrounds.php';
    require $serviceDir . '/gallery_branding.php';

    $oldOriginal = $backgroundDir . '/background-original.png';
    $oldDerivative = $backgroundDir . '/background-optimized.webp';
    $oldBanner = $brandingDir . '/banner.png';
    $oldBannerWebp = $brandingDir . '/banner.webp';
    $incoming = $root . '/incoming.png';
    $GLOBALS['theme_replace_settings'] = [
        'theme_background_original_path' => 'cache/theme-background/background-original.png',
        'theme_background_path' => 'cache/theme-background/background-original.png',
        'theme_background_optimized_path' => 'cache/theme-background/background-optimized.webp',
        'theme_branding_banner_path' => 'cache/theme-branding/banner.png',
    ];
    $oldSettings = $GLOBALS['theme_replace_settings'];
    try {
        file_put_contents($oldOriginal, 'previous-original');
        file_put_contents($oldDerivative, 'previous-optimized');
        file_put_contents($oldBanner, 'previous-banner');
        file_put_contents($oldBannerWebp, 'previous-banner-format');

        $GLOBALS['theme_replace_fail_move'] = true;
        theme_replacement_refused(
            static fn (): string => \Gallery\Services\store_uploaded_theme_background(theme_replacement_upload($incoming, 'new-original')),
            'Failed background upload'
        );
        theme_replacement_refused(
            static fn (): string => \Gallery\Services\store_uploaded_theme_branding_asset('banner', theme_replacement_upload($incoming, 'new-banner')),
            'Failed branding upload'
        );
        theme_replacement_check(
            file_get_contents($oldOriginal) === 'previous-original'
                && file_get_contents($oldDerivative) === 'previous-optimized'
                && file_get_contents($oldBanner) === 'previous-banner'
                && file_get_contents($oldBannerWebp) === 'previous-banner-format'
                && $GLOBALS['theme_replace_settings'] === $oldSettings,
            'Failure before staging must preserve all previous Theme media and settings.'
        );

        $GLOBALS['theme_replace_fail_move'] = false;
        $GLOBALS['theme_replace_fail_rename'] = true;
        theme_replacement_refused(
            static fn (): string => \Gallery\Services\store_uploaded_theme_background(theme_replacement_upload($incoming, 'new-original')),
            'Failed background finalize'
        );
        theme_replacement_refused(
            static fn (): string => \Gallery\Services\store_uploaded_theme_branding_asset('banner', theme_replacement_upload($incoming, 'new-banner')),
            'Failed branding finalize'
        );
        theme_replacement_check(
            file_get_contents($oldOriginal) === 'previous-original'
                && file_get_contents($oldDerivative) === 'previous-optimized'
                && file_get_contents($oldBanner) === 'previous-banner'
                && file_get_contents($oldBannerWebp) === 'previous-banner-format'
                && $GLOBALS['theme_replace_settings'] === $oldSettings,
            'Failed replacement rename must not discard existing Theme images or settings.'
        );
        theme_replacement_check(
            (glob($backgroundDir . '/.background-upload-*') ?: []) === []
                && (glob($brandingDir . '/.upload-banner-*') ?: []) === [],
            'Failed Theme replacement must clean temporary staging files.'
        );

        $GLOBALS['theme_replace_fail_rename'] = false;
        \Gallery\Services\store_uploaded_theme_branding_asset('banner', theme_replacement_upload($incoming, 'new-banner'));
        theme_replacement_check(
            file_get_contents($oldBanner) === 'new-banner'
                && !is_file($oldBannerWebp)
                && $GLOBALS['theme_replace_settings']['theme_branding_banner_path'] === 'cache/theme-branding/banner.png',
            'Successful branding replacement must update the file and remove the obsolete format.'
        );

        \Gallery\Services\store_uploaded_theme_background(theme_replacement_upload($incoming, 'new-original'));
        theme_replacement_check(
            file_get_contents($oldOriginal) === 'new-original'
                && !is_file($oldDerivative)
                && $GLOBALS['theme_replace_settings']['theme_background_original_path'] === 'cache/theme-background/background-original.png',
            'Successful Theme background replacement must update the original after staging.'
        );

        echo "Theme asset replacement failure preservation passed.\n";
    } finally {
        foreach (glob($backgroundDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        foreach (glob($backgroundDir . '/.*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (glob($brandingDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        foreach (glob($brandingDir . '/.*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (['gallery_backgrounds.php', 'gallery_backgrounds/visual_css_save.php', 'gallery_branding.php', 'gallery_edit_concurrency.php'] as $module) {
            @unlink($serviceDir . '/' . $module);
        }
        @unlink($incoming);
        @rmdir($serviceDir . '/gallery_backgrounds');
        @rmdir($serviceDir);
        @rmdir($root . '/app');
        @rmdir($backgroundDir);
        @rmdir($brandingDir);
        @rmdir($root . '/cache');
        @rmdir($root);
    }
}
