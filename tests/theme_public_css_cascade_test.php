<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_public_css_cascade_test.php
 * Module Type: Regression Test
 * Purpose: Verify the actual shared document head loads manual CSS last and only on public pages.
 * Responsibilities: Cover anonymous, authenticated, preview and Admin asset contexts without installation access.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Provide one fixed CSRF value for the rendered public header form.
     * @return string Disposable token used by the Test run regression.
     */
    function csrf_token(): string
    {
        return 'cascade-fixture-token';
    }

    /**
     * Supply a deterministic Theme version without configuration or database access.
     * @param array<string,string|int|bool> $theme Prepared scalar Theme preferences.
     * @return string Stable digest for the fixture's generated CSS URL.
     */
    function theme_cache_key(array $theme): string
    {
        return hash('sha256', json_encode($theme, JSON_THROW_ON_ERROR));
    }

    /** Supply the disposable mount used by the prepared CSS URL fixture.
     * @return string Fixture mount path.
     */
    function request_script_base_path(): string
    {
        return '/sub';
    }

    /** Build one internal path from the fixture's actual application mount.
     * @param string $path Application path/query suffix, empty for the mounted home directory.
     * @return string Root-relative fixture URL under /sub.
     */
    function request_mount_url(string $path = ''): string
    {
        return request_script_base_path() . '/' . ltrim($path, '/');
    }

    /** Model direct HTTP for the prepared CSS URL fixture.
     * @return bool The fixture is not served over TLS.
     */
    function request_is_https(): bool
    {
        return false;
    }

    /** Add preview markers to a known local fixture URL.
     * @param string $url Same-origin fixture URL.
     * @param bool $anonymous Whether anonymous audience is selected.
     * @return string Fixture URL carrying preview state.
     */
    function public_visual_preview_url(
        string $url,
        bool $anonymous = false,
    ): string {
        $parts = parse_url($url);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['preview'] = 'visual';
        if ($anonymous)
            $query['view_as'] = 'anonymous';
        return (string) ($parts['path'] ?? '') . '?' . http_build_query($query);
    }
}

namespace {
    require __DIR__ . '/support/theme_appearance_fixture.php';
    /**
     * Require an observable rendered asset invariant.
     * @param bool $condition Required predicate.
     * @param string $message Failure context.
     * @return void Throws on regression.
     */
    function cascade_require(bool $condition, string $message): void
    {
        if (!$condition)
            throw new RuntimeException($message);
    }

    /** Render the real shared header for one isolated visitor state.
     * @param string $bodyClass Public or Admin document context.
     * @param array{id:int,username:string}|null $user Disposable authenticated identity or anonymous visitor.
     * @param bool $testRunsEnabled Whether the public Test run action is enabled.
     * @param bool $testRunActive Whether the running-state control replaces its form.
     * @param bool $updatePending Whether Updates carries its pending marker.
     * @param string $overrideUrl Prepared public stylesheet URL, or empty when absent.
     * @param bool $anonymousPreview Whether the public renderer uses anonymous preview assets.
     * @param string $headExtras Prepared trusted head markup used by the cascade fixture.
     * @param string $adminLoginReturn Prepared same-origin destination after Admin login.
     * @return string Captured HTML emitted by the production header view.
     */
    function cascade_header_html(
        string $bodyClass,
        ?array $user,
        bool $testRunsEnabled = false,
        bool $testRunActive = false,
        bool $updatePending = false,
        string $overrideUrl = '',
        bool $anonymousPreview = false,
        string $headExtras = '',
        string $adminLoginReturn = '',
    ): string {
        $stylesheetUrls = \Gallery\Controllers\shared_layout_stylesheet_urls(
            $bodyClass,
            $user,
            $anonymousPreview,
            false,
            [],
            '/public/assets/custom.css',
            17,
        );
        $languageSelector = $bodyClass === 'public-page'
            ? [
                'enabled' => true,
                'show_codes' => true,
                'items' => [[
                    'code' => 'en',
                    'name' => 'English',
                    'url' => '/language/en',
                    'active' => true,
                ]],
            ]
            : [];
        ob_start();
        try {
            \Gallery\Views\view_render_header(
                'Header fixture',
                [
                    'body_class' => $bodyClass,
                    'user' => $user,
                    'anonymous_preview' => $anonymousPreview,
                    'public_preview_url' => $anonymousPreview
                        ? '/gallery/photos?lang=en'
                        : '/gallery/photos?lang=en&view_as=anonymous',
                    'admin_test_runs_enabled' => $testRunsEnabled,
                    'admin_test_run_active' => $testRunActive,
                    'update_pending' => $updatePending,
                    'update_label' => 'Updates',
                    'language_selector' => $languageSelector,
                    'custom_css_url' => '/public/assets/custom.css',
                    'custom_css_version' => 17,
                    'custom_css_overrides_url' => $overrideUrl,
                    'stylesheet_urls' => $stylesheetUrls,
                    'head_extras' => $headExtras,
                    'admin_login_return' => $adminLoginReturn,
                    'admin_chrome' => [],
                ],
                '/gallery/photos?sort=latest&lang=en',
                $bodyClass === 'admin-page' ? 'admin' : 'gallery',
            );
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Capture actual shared header styles for one disposable visitor context.
     * @param string $bodyClass Canonical public or Admin presentation context.
     * @param array{id:int,username:string}|null $user Disposable authenticated identity or anonymous visitor.
     * @param bool $anonymousPreview Whether the public renderer uses anonymous preview assets.
     * @param string $overrideUrl Prepared content-versioned URL, or empty when no override text exists.
     * @return list<string> Stylesheet URLs in their actual document order.
     */
    function cascade_styles(
        string $bodyClass,
        ?array $user,
        bool $anonymousPreview,
        string $overrideUrl,
    ): array {
        $html = cascade_header_html(
            $bodyClass,
            $user,
            overrideUrl: $overrideUrl,
            anonymousPreview: $anonymousPreview,
            headExtras: '<link rel="stylesheet" href="/fixture-extra.css">',
        );
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $urls = [];
        foreach ((new DOMXPath($document))->query(
            '//head/link[@rel="stylesheet"]',
        ) as $link)
            $urls[] = $link->getAttribute('href');
        return $urls;
    }

    $authenticatedUser = ['id' => 1, 'username' => 'fixture'];
    $publicHeaderHtml = cascade_header_html(
        'public-page',
        $authenticatedUser,
        testRunsEnabled: true,
        updatePending: true,
    );
    $publicHeader = new DOMDocument();
    @$publicHeader->loadHTML($publicHeaderHtml);
    $publicHeaderXPath = new DOMXPath($publicHeader);
    $actionLabels = [];
    foreach ($publicHeaderXPath->query(
        '//*[contains(concat(" ", normalize-space(@class), " "), " public-header-action ")]'
            . '/span[contains(concat(" ", normalize-space(@class), " "), " public-header-action-label ")]'
    ) as $label) {
        $actionLabels[] = trim($label->textContent);
        cascade_require(
            str_contains(' ' . $label->getAttribute('class') . ' ', ' visually-hidden ')
                && $label->parentNode?->getAttribute('title')
                    === trim($label->textContent),
            'each header icon retains its accessible name and localized hover tooltip',
        );
    }
    cascade_require(
        $actionLabels === ['View as anonymous', 'Admin', 'Test run', 'Updates', 'Logout'],
        'the authenticated public header must include preview with the compact actions',
    );
    cascade_require(
        $publicHeaderXPath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " public-header-action ")]'
                . '/span[contains(concat(" ", normalize-space(@class), " "), " public-header-action-icon ")]/svg[@class="admin-menu-icon"]',
        )->length === count($actionLabels),
        'each public header action must use the existing decorative Admin SVG helper',
    );
    $adminLoginReturn = '/gallery/photos?sort=latest&lang=en';
    foreach ([
        [null, false, 'anonymous'],
        [$authenticatedUser, true, 'anonymous preview'],
    ] as [$loginUser, $anonymousPreview, $audience]) {
        $loginHeader = new DOMDocument();
        @$loginHeader->loadHTML(cascade_header_html(
            'public-page',
            $loginUser,
            anonymousPreview: $anonymousPreview,
            adminLoginReturn: $adminLoginReturn,
        ));
        $loginXPath = new DOMXPath($loginHeader);
        $previewAction = $loginXPath->query('//a[@data-public-anonymous-preview]');
        cascade_require(
            $previewAction->length === ($loginUser !== null ? 1 : 0),
            'only an actual signed-in administrator receives the preview action',
        );
        if ($anonymousPreview) {
            cascade_require(
                $previewAction->item(0)?->getAttribute('href')
                    === '/gallery/photos?lang=en'
                    && trim($previewAction->item(0)?->textContent ?? '')
                        === 'Exit preview'
                    && $previewAction->item(0)?->getAttribute('title')
                        === 'Exit preview',
                'active preview keeps a native header exit with the prepared locale',
            );
        }
        $loginAction = $loginXPath->query(
            '//a[@title="Admin login" and contains(concat(" ", normalize-space(@class), " "), " public-header-action ")'
                . ']',
        );
        $loginHref = $loginAction->item(0)?->getAttribute('href') ?? '';
        $loginQuery = [];
        parse_str((string) parse_url($loginHref, PHP_URL_QUERY), $loginQuery);
        cascade_require(
            $loginAction->length === 1
                && ($loginQuery['page'] ?? '') === 'admin_login'
                && ($loginQuery['return'] ?? '') === $adminLoginReturn
                && $loginXPath->query(
                    '//a[@title="Admin login" and contains(concat(" ", normalize-space(@class), " "), " public-header-action ")]'
                        . '/span[contains(concat(" ", normalize-space(@class), " "), " public-header-action-label ")'
                        . ' and contains(concat(" ", normalize-space(@class), " "), " visually-hidden ")'
                        . ' and text()="Admin login"]',
                )->length === 1
                && $loginXPath->query(
                    '//a[@title="Admin login" and contains(concat(" ", normalize-space(@class), " "), " public-header-action ")]'
                        . '/span[contains(concat(" ", normalize-space(@class), " "), " public-header-action-icon ")]'
                        . '/svg[@class="admin-menu-icon"]',
                )->length === 1,
            $audience
                . ' public header must render the compact Admin login action and preserve its return destination',
        );
    }
    $updatesAction = $publicHeaderXPath->query(
        '//a[contains(concat(" ", normalize-space(@class), " "), " public-header-action ")'
            . ' and contains(concat(" ", normalize-space(@class), " "), " is-update-pending ")'
            . ' and @href="/index.php?page=admin_update"]',
    );
    cascade_require(
        $updatesAction->length === 1
            && $updatesAction->item(0)?->getAttribute('title') === 'Updates',
        'Updates keeps its pending class, title and existing route',
    );
    $testRunForm = $publicHeaderXPath->query(
        '//form[@method="post" and @action="/index.php?page=admin_test_run_start"]',
    );
    cascade_require(
        $testRunForm->length === 1
            && $publicHeaderXPath->query(
                '//form[@action="/index.php?page=admin_test_run_start"]'
                    . '/input[@name="csrf_token" and @value="cascade-fixture-token"]',
            )->length === 1
            && $publicHeaderXPath->query(
                '//form[@action="/index.php?page=admin_test_run_start"]'
                    . '/input[@name="target" and @value="/gallery/photos?sort=latest&lang=en"]',
            )->length === 1
            && $publicHeaderXPath->query(
                '//form[@action="/index.php?page=admin_test_run_start"]'
                    . '/input[@name="target_page" and @value="gallery"]',
            )->length === 1,
        'Test run keeps its POST route, CSRF token, return URI and target page fields',
    );
    $languageControl = $publicHeaderXPath->query(
        '//div[@class="public-language-switcher"]'
            . '/a[@class="public-language-button is-active" and @href="/language/en"'
            . ' and @hreflang="en" and @lang="en" and @aria-current="true"]'
            . '/span[@class="public-language-code" and text()="EN"]',
    );
    cascade_require(
        $languageControl->length === 1,
        'adding header actions preserves the existing language selector markup and destination',
    );
    $runningHeader = new DOMDocument();
    @$runningHeader->loadHTML(cascade_header_html(
        'public-page',
        $authenticatedUser,
        testRunsEnabled: true,
        testRunActive: true,
    ));
    $runningXPath = new DOMXPath($runningHeader);
    cascade_require(
        $runningXPath->query(
            '//span[contains(concat(" ", normalize-space(@class), " "), " public-header-action ")'
                . ' and contains(concat(" ", normalize-space(@class), " "), " is-disabled ")'
                . ' and @aria-disabled="true" and @title="Test run running"]'
                . '/span[contains(concat(" ", normalize-space(@class), " "), " public-header-action-label ")'
                . ' and contains(concat(" ", normalize-space(@class), " "), " visually-hidden ")'
                . ' and text()="Test run running"]',
        )->length === 1
            && $runningXPath->query(
                '//span[contains(concat(" ", normalize-space(@class), " "), " public-header-action ")]'
                    . '/span[contains(concat(" ", normalize-space(@class), " "), " public-header-action-icon ")]'
                    . '/svg[@class="admin-menu-icon"]',
            )->length === 1
            && $runningXPath->query('//form[@action="/index.php?page=admin_test_run_start"]')->length === 0,
        'an active Test run retains its disabled icon, hidden label and tooltip without a form',
    );
    $adminHeader = new DOMDocument();
    @$adminHeader->loadHTML(cascade_header_html(
        'admin-page',
        $authenticatedUser,
    ));
    $adminXPath = new DOMXPath($adminHeader);
    cascade_require(
        $adminXPath->query(
            '//header//nav/a[@href="/index.php?page=admin_logout" and text()="Logout"'
                . ' and not(@class) and not(.//svg)]',
        )->length === 1
            && $adminXPath->query(
                '//header//nav//*[contains(concat(" ", normalize-space(@class), " "), " public-header-action ")]',
            )->length === 0,
        'Admin page Logout keeps its existing plain markup outside the public action styling',
    );
    $anonymousAdminHeader = new DOMDocument();
    @$anonymousAdminHeader->loadHTML(cascade_header_html(
        'admin-page',
        null,
        adminLoginReturn: $adminLoginReturn,
    ));
    $anonymousAdminXPath = new DOMXPath($anonymousAdminHeader);
    $anonymousAdminLogin = $anonymousAdminXPath->query(
        '//header//nav/a[text()="Admin login" and not(@title)'
            . ' and not(@class) and not(.//svg)]',
    );
    $anonymousAdminHref = $anonymousAdminLogin->item(0)?->getAttribute('href') ?? '';
    $anonymousAdminQuery = [];
    parse_str(
        (string) parse_url($anonymousAdminHref, PHP_URL_QUERY),
        $anonymousAdminQuery,
    );
    cascade_require(
        $anonymousAdminLogin->length === 1
            && ($anonymousAdminQuery['page'] ?? '') === 'admin_login'
            && ($anonymousAdminQuery['return'] ?? '') === $adminLoginReturn,
        'anonymous Admin-page login keeps its prior plain markup and return destination',
    );

    $overrideUrl =
        '/public/assets/custom-overrides.css?v='
        . hash('sha256', '.manual { color:red; }');
    foreach ([
        [null, false],
        [['id' => 1, 'username' => 'fixture'], false],
        [['id' => 1, 'username' => 'fixture'], true],
    ] as [$user, $preview]) {
        $urls = cascade_styles('public-page', $user, $preview, $overrideUrl);
        cascade_require(
            end($urls) === $overrideUrl
                && count(array_keys($urls, $overrideUrl, true)) === 1,
            'every public context must load exactly one digest-versioned override as its final stylesheet',
        );
        $installed = array_search(
            '/public/assets/custom.css?v=17',
            $urls,
            true,
        );
        $generated = $mobile = false;
        foreach ($urls as $index => $url) {
            if (str_contains($url, 'page=theme_css'))
                $generated = $index;
            if (str_contains($url, 'assets/styles/mobile-gallery.css'))
                $mobile = $index;
        }
        cascade_require(
            is_int($installed)
                && is_int($generated)
                && is_int($mobile)
                && $installed < $generated
                && $generated < $mobile
                && $mobile < array_search('/fixture-extra.css', $urls, true),
            'installed CSS, generated Theme, mobile and trusted extras must precede manual overrides',
        );
    }
    $_SERVER['SCRIPT_NAME'] = '/sub/index.php';
    $_SERVER['HTTP_HOST'] = 'gallery.example';
    $previewStyles = \Gallery\Controllers\shared_layout_stylesheet_urls(
        'public-page',
        ['id' => 1, 'username' => 'fixture'],
        false,
        true,
        [],
        '/configured/assets/custom.css',
        17,
    );
    $anonymousPreviewStyles = \Gallery\Controllers\shared_layout_stylesheet_urls(
        'public-page',
        ['id' => 1, 'username' => 'fixture'],
        true,
        true,
        [],
        '/configured/assets/custom.css',
        17,
    );
    $ordinaryStyles = \Gallery\Controllers\shared_layout_stylesheet_urls(
        'public-page',
        ['id' => 1, 'username' => 'fixture'],
        false,
        false,
        [],
        '/configured/assets/custom.css',
        17,
    );
    cascade_require(
        $previewStyles !== []
            && !array_filter(
                $previewStyles,
                static fn(string $url): bool => (
                    !str_contains($url, 'preview=visual')
                    || str_contains($url, 'view_as=anonymous')
                ),
            ),
        'signed-in preview CSS URLs must carry only the visual marker',
    );
    cascade_require(
        $anonymousPreviewStyles !== []
            && !array_filter(
                $anonymousPreviewStyles,
                static fn(string $url): bool => (
                    !str_contains($url, 'preview=visual')
                    || !str_contains($url, 'view_as=anonymous')
                ),
            ),
        'anonymous preview CSS URLs must carry both protected context markers',
    );
    cascade_require(
        !array_filter(
            $ordinaryStyles,
            static fn(string $url): bool => (
                str_contains($url, 'preview=visual')
                || str_contains($url, 'view_as=anonymous')
            ),
        ),
        'ordinary public CSS URLs must remain unmarked',
    );
    cascade_require(
        !in_array(
            $overrideUrl,
            cascade_styles('admin-page', null, false, $overrideUrl),
            true,
        ),
        'Admin head must refuse the override layer even when a model supplies its URL',
    );
    cascade_require(
        !array_filter(
            cascade_styles('public-page', null, false, ''),
            static fn(string $url): bool => str_contains(
                $url,
                'custom-overrides.css',
            ),
        ),
        'empty overrides must omit their public link',
    );
    echo "PASS public manual CSS cascade and Admin isolation\n";
}
