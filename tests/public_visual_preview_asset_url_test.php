<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_visual_preview_asset_url_test.php
 * Module Type: Regression Test
 * Purpose: Keep visual-preview stylesheet URLs aligned with the canonical asset root and request mount.
 * Responsibilities:
 *   - Compare real shared-layout preview hrefs with canonical asset URLs across supported document roots.
 *   - Preserve CSS cache revisions, same-origin mounts, audience markers, and ordinary unmarked links.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Provide deterministic Theme stylesheet cache revisions without configuration access.
     * @param array<string,string|int|bool> $theme Theme values whose cache identity is requested.
     * @return string Stable fixture digest for the generated stylesheet query.
     */
    function theme_cache_key(array $theme): string
    {
        return hash('sha256', json_encode($theme, JSON_THROW_ON_ERROR));
    }

    /**
     * Supply the fixture's selected base URL without loading installation configuration.
     * @return array{base_url:string} Configured from global fixture state; absent or non-string values become the empty relative-URL setting.
     */
    function cms_config(): array
    {
        $baseUrl = $GLOBALS['preview_asset_url_config']['base_url'] ?? '';
        return ['base_url' => is_string($baseUrl) ? $baseUrl : ''];
    }
}

namespace Gallery\Services {
    /**
     * Select the routing mode supplied by each deployment fixture.
     * @return bool True when the fixture requests clean URLs, otherwise front-controller URLs.
     */
    function url_rewrite_should_emit_clean_urls(): bool
    {
        return ($GLOBALS['preview_asset_url_config']['rewrite'] ?? false) === true;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_runtime.php';
    require_once dirname(__DIR__) . '/app/helpers_request.php';
    require_once dirname(__DIR__) . '/app/controllers/shared_layout.php';

    /**
     * Fail one observable stylesheet URL contract with a clear behavior explanation.
     * @param bool $condition Whether the expected URL behavior occurred.
     * @param string $message Failure context for the owning URL scenario.
     * @return void Throw when the behavior contract is not met.
     */
    function preview_asset_url_require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Resolve only the URL path component for an application stylesheet href.
     * @param string $url Same-origin href returned by the shared layout.
     * @return string Parsed root-relative path, or an empty string when the URL is malformed.
     */
    function preview_asset_url_path(string $url): string
    {
        $parts = parse_url($url);
        return is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : '';
    }

    /**
     * Resolve the installed CSS assets selected for one layout identity and audience.
     * @param string $root Repository root containing the deployed public asset tree.
     * @param array{id?:int|string,username?:string,email?:string|null}|null $user Authenticated application user, or null for an anonymous visitor.
     * @param bool $anonymousPreview Whether the layout uses anonymous visitor visibility.
     * @return list<string> Canonical asset URL paths in the selected cascade order, including the configured Custom CSS URL and mobile CSS when present.
     */
    function preview_asset_url_expected_asset_paths(string $root, ?array $user, bool $anonymousPreview): array
    {
        $paths = [];
        foreach (\Gallery\Controllers\shared_layout_stylesheet_files_for_context(
            'public-page', $user, $anonymousPreview
        ) as $stylesheetFile) {
            if (is_file($root . '/public/' . $stylesheetFile)) {
                $paths[] = preview_asset_url_path(\Gallery\Core\asset_url($stylesheetFile));
            }
        }
        $paths[] = preview_asset_url_path(\Gallery\Core\asset_url('assets/custom.css'));
        if (is_file($root . '/public/assets/styles/mobile-gallery.css')) {
            $paths[] = preview_asset_url_path(\Gallery\Core\asset_url('assets/styles/mobile-gallery.css'));
        }
        return $paths;
    }

    /**
     * Compare rendered preview and ordinary stylesheet paths to the canonical asset owner.
     * @param list<string> $urls Ordered stylesheet hrefs from shared-layout preparation.
     * @param list<string> $expectedAssetPaths Canonical CSS asset paths expected in the same order.
     * @param string $mount Request mount path, or an empty string at the host root.
     * @param string $expectedOrigin Exact configured same-origin origin required for absolute hrefs, or empty for relative URLs; visual-preview Theme routes stay root-relative.
     * @param bool $visualPreview Whether visual-preview query markers are required.
     * @param bool $anonymous Whether anonymous audience selection is required.
     * @param string $context Deployment, URL mode, and layout audience for actionable failure context.
     * @return void Reject unexpected origin, mount, route, cache revision, or audience state while preserving the distinct preview and ordinary Theme URL forms.
     */
    function preview_asset_url_assert_stylesheets(array $urls, array $expectedAssetPaths, string $mount,
        string $expectedOrigin, bool $visualPreview, bool $anonymous, string $context): void
    {
        $require = static function (bool $condition, string $message) use ($context): void {
            preview_asset_url_require($condition, $context . ': ' . $message);
        };
        $actualAssetPaths = [];
        $themeRouteCount = 0;
        foreach ($urls as $url) {
            $parts = parse_url($url);
            $require(is_array($parts) && !isset($parts['user']) && !isset($parts['pass'])
                && is_string($parts['path'] ?? null), 'A shared stylesheet href must remain a valid same-origin URL.');
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);
            $isThemeCssRoute = ($query['page'] ?? null) === 'theme_css';
            if ($expectedOrigin === '') {
                $require(!isset($parts['scheme']) && !isset($parts['host']),
                    'A relative-base installation must emit root-relative stylesheet URLs.');
            } elseif ($isThemeCssRoute && $visualPreview) {
                $require(!isset($parts['scheme']) && !isset($parts['host']),
                    'The visual-preview Theme stylesheet route must remain root-relative to the current origin.');
            } else {
                $actualOrigin = strtolower((string) ($parts['scheme'] ?? '')) . '://'
                    . strtolower((string) ($parts['host'] ?? ''))
                    . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
                $require($actualOrigin === $expectedOrigin,
                    'A configured absolute base URL must retain its exact same-origin authority.');
            }
            $path = $parts['path'];
            $require($mount === '' ? str_starts_with($path, '/')
                : ($path === $mount || str_starts_with($path, $mount . '/')),
                'A shared stylesheet href must remain inside the current installation mount.');
            if ($visualPreview) {
                $require(($query['preview'] ?? null) === 'visual'
                    && ($anonymous ? ($query['view_as'] ?? null) === 'anonymous' : !array_key_exists('view_as', $query)),
                    'Preview stylesheet hrefs must preserve the selected visual-preview audience.');
            } else {
                $require(!array_key_exists('preview', $query) && !array_key_exists('view_as', $query),
                    'Ordinary stylesheet hrefs must remain free of preview markers.');
            }

            if ($isThemeCssRoute) {
                $themeRouteCount++;
                $require($path === rtrim($mount, '/') . '/index.php'
                    && is_string($query['v'] ?? null) && $query['v'] !== '',
                    'The generated Theme stylesheet must keep its mounted front-controller route and cache revision.');
                continue;
            }
            $require(is_string($query['v'] ?? null) && ctype_digit($query['v']),
                'A linked CSS asset must retain its file or content cache revision.');
            $actualAssetPaths[] = $path;
        }
        $require($themeRouteCount === 1, 'The shared layout must emit exactly one generated Theme stylesheet route.');
        $require($actualAssetPaths === $expectedAssetPaths,
            'CSS asset paths must match the canonical asset URL owner and cascade order for ' . $context . '.');
    }

    $root = dirname(__DIR__);
    $deploymentLayouts = [
        ['name' => 'repository-root router', 'script_name' => '/index.php', 'script_filename' => 'D:\\gallery\\index.php', 'mount' => '', 'asset_prefix' => '/public'],
        ['name' => 'public document root', 'script_name' => '/index.php', 'script_filename' => 'D:\\gallery\\public\\index.php', 'mount' => '', 'asset_prefix' => ''],
        ['name' => 'public path front controller', 'script_name' => '/public/index.php', 'script_filename' => 'D:\\gallery\\public\\index.php', 'mount' => '', 'asset_prefix' => '/public'],
        ['name' => 'subdirectory repository-root router', 'script_name' => '/sub/index.php', 'script_filename' => 'D:\\gallery\\index.php', 'mount' => '/sub', 'asset_prefix' => '/sub/public'],
        ['name' => 'subdirectory public document root', 'script_name' => '/sub/index.php', 'script_filename' => 'D:\\gallery\\public\\index.php', 'mount' => '/sub', 'asset_prefix' => '/sub'],
        ['name' => 'subdirectory public path front controller', 'script_name' => '/sub/public/index.php', 'script_filename' => 'D:\\gallery\\public\\index.php', 'mount' => '/sub', 'asset_prefix' => '/sub/public'],
    ];
    $scenarios = [];
    foreach ($deploymentLayouts as $deploymentLayout) {
        $mountBaseUrl = 'http://gallery.example' . $deploymentLayout['mount'];
        foreach ([['base_url' => '', 'origin' => ''],
            ['base_url' => $mountBaseUrl, 'origin' => 'http://gallery.example']] as $urlMode) {
            $scenarios[] = array_merge($deploymentLayout, $urlMode, [
                'name' => $deploymentLayout['name'] . ($urlMode['base_url'] === '' ? ' with relative base URL' : ' with absolute base URL'),
            ]);
        }
    }

    $originalGet = $_GET;
    $originalServer = $_SERVER;
    $hadOriginalConfig = array_key_exists('preview_asset_url_config', $GLOBALS);
    $originalConfig = $GLOBALS['preview_asset_url_config'] ?? null;
    try {
        foreach ($deploymentLayouts as $layout) {
            foreach (['localhost', 'localhost:8888', 'gallery.example'] as $host) {
                foreach ([false, true] as $https) {
                    foreach ([false, true] as $rewrite) {
                        foreach (['', 'https://foreign.example/unrelated', '/wrong-mount', 'https://' . $host . ':9443/wrong-mount'] as $base) {
                            $GLOBALS['preview_asset_url_config'] = ['base_url' => $base, 'rewrite' => $rewrite];
                            $_GET = [];
                            $_SERVER = ['SCRIPT_NAME' => $layout['script_name'], 'SCRIPT_FILENAME' => $layout['script_filename'],
                                'HTTP_HOST' => $host, 'HTTPS' => $https ? 'on' : 'off', 'REMOTE_ADDR' => ''];
                            $home = $layout['mount'] . ($rewrite ? '/' : '/index.php?page=home');
                            foreach ([false, true] as $anonymous) {
                                $expected = $home . ($rewrite ? '?' : '&') . 'preview=visual' . ($anonymous ? '&view_as=anonymous' : '');
                                preview_asset_url_require(\Gallery\Core\public_visual_preview_home_url($anonymous) === $expected,
                                    'Protected Home must use the actual mount, routing mode and audience independently of configured origin: '
                                    . $layout['name'] . ', host=' . $host . ', https=' . (int) $https . ', rewrite=' . (int) $rewrite . ', base=' . $base);
                            }
                            if ($base === 'https://foreign.example/unrelated') {
                                $oldUrl = \Gallery\Core\public_visual_preview_url(\Gallery\Core\url_for('home'));
                                preview_asset_url_require(!str_contains($oldUrl, 'preview=visual'),
                                    'The original configured-origin pipeline must reproduce its refused, unmarked URL.');
                            }
                            $_GET = ['preview' => 'visual', 'view_as' => 'anonymous'];
                            $asset = \Gallery\Core\asset_url('assets/styles/base.css');
                            preview_asset_url_require($asset === $layout['asset_prefix'] . '/assets/styles/base.css',
                                'App-owned marked-preview assets must use the actual document root even with a foreign configured base.');
                            preview_asset_url_require(\Gallery\Core\url_for('home') === $layout['mount'] . '/index.php?page=home&preview=visual&view_as=anonymous',
                                'Marked-preview navigation must retain its actual origin, mount and anonymous audience.');
                            $urls = \Gallery\Controllers\shared_layout_stylesheet_urls('public-page', ['id' => 1, 'username' => 'fixture'],
                                true, true, [], \Gallery\Core\asset_url('assets/custom.css'), 17);
                            preview_asset_url_assert_stylesheets($urls,
                                preview_asset_url_expected_asset_paths($root, ['id' => 1, 'username' => 'fixture'], true),
                                $layout['mount'], '', true, true, 'configured-origin mismatch preview ' . $layout['name']);
                        }
                    }
                }
            }
        }
        $_SERVER['HTTP_HOST'] = 'user:secret@foreign.example';
        preview_asset_url_require(!str_contains(\Gallery\Core\public_visual_preview_home_url(), 'preview=visual'),
            'An invalid request authority must remain fail-closed rather than bypass server marker validation.');

        foreach ($scenarios as $scenario) {
            $GLOBALS['preview_asset_url_config'] = ['base_url' => $scenario['base_url']];
            $_GET = [];
            $_SERVER = [
                'SCRIPT_NAME' => $scenario['script_name'],
                'SCRIPT_FILENAME' => $scenario['script_filename'],
                'HTTP_HOST' => 'gallery.example',
                'SERVER_PORT' => '80',
                'REMOTE_ADDR' => '',
            ];
            $canonicalBaseCssPath = preview_asset_url_path(\Gallery\Core\asset_url('assets/styles/base.css'));
            preview_asset_url_require($canonicalBaseCssPath === $scenario['asset_prefix'] . '/assets/styles/base.css',
                'The canonical runtime asset owner did not resolve the expected root for ' . $scenario['name'] . '.');

            $previewUser = ['id' => 1, 'username' => 'fixture'];
            foreach ([false, true] as $anonymous) {
                $previewAudience = $anonymous ? 'anonymous preview' : 'signed-in Admin preview';
                $previewContext = $scenario['name'] . ', ' . $previewAudience;
                $previewExpectedAssetPaths = preview_asset_url_expected_asset_paths($root, $previewUser, $anonymous);
                $previewUrls = \Gallery\Controllers\shared_layout_stylesheet_urls(
                    'public-page', $previewUser, $anonymous, true, [],
                    \Gallery\Core\asset_url('assets/custom.css'), 17
                );
                preview_asset_url_assert_stylesheets($previewUrls, $previewExpectedAssetPaths, $scenario['mount'],
                    $scenario['origin'], true, $anonymous, $previewContext);
            }

            foreach ([
                ['name' => 'signed-in Admin page', 'user' => ['id' => 1, 'username' => 'fixture']],
                ['name' => 'anonymous visitor page', 'user' => null],
            ] as $ordinaryContext) {
                $ordinaryExpectedAssetPaths = preview_asset_url_expected_asset_paths($root, $ordinaryContext['user'], false);
                $ordinaryUrls = \Gallery\Controllers\shared_layout_stylesheet_urls(
                    'public-page', $ordinaryContext['user'], false, false, [],
                    \Gallery\Core\asset_url('assets/custom.css'), 17
                );
                preview_asset_url_assert_stylesheets($ordinaryUrls, $ordinaryExpectedAssetPaths, $scenario['mount'],
                    $scenario['origin'], false, false, $scenario['name'] . ', ' . $ordinaryContext['name']);
            }
        }
    } finally {
        $_GET = $originalGet;
        $_SERVER = $originalServer;
        if ($hadOriginalConfig) {
            $GLOBALS['preview_asset_url_config'] = $originalConfig;
        } else {
            unset($GLOBALS['preview_asset_url_config']);
        }
    }

    fwrite(STDOUT, "Public visual preview asset URL contract passed.\n");
}
