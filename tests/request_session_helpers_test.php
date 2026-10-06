<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/request_session_helpers_test.php
 * Module Type: Regression Test
 * Purpose: Preserve request-aware helper and one-time flash behavior through Core adapters.
 * Responsibilities: Cover JSON detection, URL routing, page preparation, and flash consumption.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return a stable fixture URL for asset-routing assertions.
     *
     * @param string $path Asset path selected by the helper.
     * @return string Fixture URL containing the selected path.
     */
    function base_url(string $path = ''): string
    {
        $GLOBALS['request_session_helper_base_url_calls'][] = $path;
        return 'https://gallery.test/' . ltrim($path, '/');
    }
}

namespace Gallery\Controllers {
    /**
     * Capture normalized header inputs and return a fixture view model.
     *
     * @param ?array<string,mixed> $currentGallery Optional current gallery.
     * @param bool $publicOnly Whether public-safe assets are requested.
     * @param array<string,mixed> $requestQuery Normalized query input bag.
     * @param string $requestUri Current request URI.
     * @param string $scriptName Current script path.
     * @param string $page Current route identifier.
     * @param string $headExtras Buffered trusted head markup.
     * @return array<string,mixed> Fixture model passed to the view.
     */
    function shared_layout_header_model(
        ?array $currentGallery,
        bool $publicOnly,
        array $requestQuery,
        string $requestUri,
        string $scriptName,
        string $page,
        string $headExtras = ''
    ): array {
        $GLOBALS['request_session_helper_header_model_args'] = func_get_args();
        return ['fixture' => 'header'];
    }

    /**
     * Return a fixture browser localization asset URL for one page family.
     *
     * @param bool $isAdminPage Whether the selected page is an Admin page.
     * @return string Fixture localization URL.
     */
    function shared_layout_browser_i18n_asset_url(bool $isAdminPage): string
    {
        $GLOBALS['request_session_helper_i18n_admin'] = $isAdminPage;
        return $isAdminPage ? '/admin-i18n.js' : '/public-i18n.js';
    }

    /**
     * Return a fixture footer model for one page.
     *
     * @param string $page Current route identifier.
     * @return array<string,mixed> Fixture footer model.
     */
    function shared_layout_footer_model(string $page): array
    {
        $GLOBALS['request_session_helper_footer_page'] = $page;
        return ['page' => $page];
    }
}

namespace Gallery\Views {
    /**
     * Capture shared header arguments supplied by Core page preparation.
     *
     * @param string $title Prepared page title.
     * @param array<string,mixed> $model Prepared header view model.
     * @param string $requestUri Current request URI.
     * @param string $page Current route identifier.
     * @return void Record the arguments for the fixture assertions.
     */
    function view_render_header(string $title, array $model, string $requestUri, string $page): void
    {
        $GLOBALS['request_session_helper_header_view_args'] = func_get_args();
    }

    /**
     * Capture the selected browser localization script URL.
     *
     * @param string $assetUrl Localization module URL.
     * @return void Record the URL for the fixture assertion.
     */
    function view_render_browser_i18n_script(string $assetUrl): void
    {
        $GLOBALS['request_session_helper_i18n_view_url'] = $assetUrl;
    }

    /**
     * Capture the selected page and footer view model.
     *
     * @param string $page Current route identifier.
     * @param array<string,mixed> $model Prepared footer view model.
     * @return void Record the arguments for the fixture assertions.
     */
    function view_render_footer(string $page, array $model): void
    {
        $GLOBALS['request_session_helper_footer_view_args'] = func_get_args();
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_runtime.php';
    require_once dirname(__DIR__) . '/app/helpers_mutation.php';
    require_once dirname(__DIR__) . '/app/helpers_page_rendering.php';

    use function Gallery\Core\admin_wants_json;
    use function Gallery\Core\asset_url;
    use function Gallery\Core\flash_message;
    use function Gallery\Core\render_browser_i18n_script;
    use function Gallery\Core\render_footer;
    use function Gallery\Core\render_header;
    use function Gallery\Core\request_method;
    use function Gallery\Core\session_context_get;

    /**
     * Fail one helper semantic assertion with its behavior contract.
     *
     * @param bool $condition Whether the expected behavior occurred.
     * @param string $message Human-readable failure explanation.
     * @return void Throw when the condition is false.
     */
    function request_session_helpers_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $_GET = [];
    $_POST = [];
    $_SERVER = [];
    request_session_helpers_assert(request_method() === 'GET', 'Missing request method must default to GET.');
    $_SERVER['REQUEST_METHOD'] = 'pOsT';
    request_session_helpers_assert(request_method() === 'POST', 'Request method must remain case-normalized.');

    $_GET = [];
    $_POST = [];
    $_SERVER = [];
    request_session_helpers_assert(!admin_wants_json(), 'Plain page requests must remain non-JSON.');
    $_POST['ajax'] = '1';
    request_session_helpers_assert(admin_wants_json(), 'POST ajax marker must request JSON.');
    $_POST = [];
    $_GET['panel'] = '1';
    request_session_helpers_assert(admin_wants_json(), 'Query panel marker must request JSON.');
    $_GET = [];
    $_GET['ajax'] = '1';
    request_session_helpers_assert(admin_wants_json(), 'Query ajax marker must request JSON.');
    $_GET = [];
    $_POST['panel'] = '1';
    request_session_helpers_assert(admin_wants_json(), 'POST panel marker must request JSON.');
    $_POST = [];
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    request_session_helpers_assert(admin_wants_json(), 'XMLHttpRequest detection must remain case-insensitive.');
    $_SERVER = ['HTTP_ACCEPT' => 'text/html, application/json;q=0.9'];
    request_session_helpers_assert(admin_wants_json(), 'JSON Accept headers must request JSON.');

    $GLOBALS['request_session_helper_base_url_calls'] = [];
    $_SERVER = ['SCRIPT_NAME' => '/public/index.php'];
    request_session_helpers_assert(
        asset_url('/assets/site.css') === 'https://gallery.test/public/assets/site.css',
        'A public/index.php script path must select public-prefixed asset URLs.'
    );
    $_SERVER = ['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => 'C:\\gallery\\public\\index.php'];
    request_session_helpers_assert(
        asset_url('assets/site.css') === 'https://gallery.test/assets/site.css',
        'A public/index.php script filename must select public-root asset URLs.'
    );
    $_SERVER = ['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => 'C:\\gallery\\index.php'];
    request_session_helpers_assert(
        asset_url('assets/site.css') === 'https://gallery.test/public/assets/site.css',
        'Repository-root requests must retain the public-prefixed asset fallback.'
    );

    $_GET = ['page' => 'admin_galleries', 'filter' => 'recent'];
    $_SERVER = ['REQUEST_URI' => '/admin.php?page=admin_galleries', 'SCRIPT_NAME' => '/admin.php'];
    render_header('Gallery administration');
    $headerInputs = $GLOBALS['request_session_helper_header_model_args'] ?? [];
    request_session_helpers_assert(
        ($headerInputs[2] ?? null) === $_GET
            && ($headerInputs[3] ?? null) === $_SERVER['REQUEST_URI']
            && ($headerInputs[4] ?? null) === $_SERVER['SCRIPT_NAME']
            && ($headerInputs[5] ?? null) === 'admin_galleries',
        'Header preparation must pass the normalized query, URI, script path, and active navigation page.'
    );
    $headerViewInputs = $GLOBALS['request_session_helper_header_view_args'] ?? [];
    request_session_helpers_assert(($headerViewInputs[2] ?? null) === $_SERVER['REQUEST_URI'], 'Header view must receive the original request URI.');
    render_browser_i18n_script();
    request_session_helpers_assert(($GLOBALS['request_session_helper_i18n_admin'] ?? false) === true, 'Admin page must select the Admin localization asset.');
    request_session_helpers_assert(($GLOBALS['request_session_helper_i18n_view_url'] ?? '') === '/admin-i18n.js', 'Selected localization asset must reach the view.');
    render_footer();
    request_session_helpers_assert(($GLOBALS['request_session_helper_footer_page'] ?? '') === 'admin_galleries', 'Footer preparation must retain active navigation page.');
    request_session_helpers_assert(($GLOBALS['request_session_helper_footer_view_args'][0] ?? '') === 'admin_galleries', 'Footer view must receive the active page identifier.');
    $_GET = [];
    render_header('Gallery home');
    request_session_helpers_assert(($GLOBALS['request_session_helper_header_model_args'][5] ?? '') === 'home', 'Missing page query must retain home navigation default.');
    render_browser_i18n_script();
    request_session_helpers_assert(($GLOBALS['request_session_helper_i18n_admin'] ?? true) === false, 'Public pages must select the public localization asset.');
    render_footer();
    request_session_helpers_assert(($GLOBALS['request_session_helper_footer_view_args'][0] ?? '') === 'home', 'Footer navigation must return to the home page context.');

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $_SESSION = ['existing' => 'preserved'];
    request_session_helpers_assert(flash_message('notice', 'inactive') === 'inactive', 'Inactive flash storage must return the supplied message unchanged.');
    request_session_helpers_assert(!array_key_exists('flash_messages', $_SESSION), 'Inactive flash storage must not persist message state.');
    request_session_helpers_assert(flash_message('notice') === null, 'Inactive flash reads must remain empty.');

    $originalSessionName = session_name();
    $originalSessionSavePath = (string) session_save_path();
    $originalSessionUseCookies = (string) ini_get('session.use_cookies');
    $originalSessionCacheLimiter = (string) ini_get('session.cache_limiter');
    try {
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        session_save_path(sys_get_temp_dir());
        session_name('PGHelperSemantics');
        session_id('helper-' . bin2hex(random_bytes(8)));
        request_session_helpers_assert(session_start(), 'Fixture PHP session must start for active flash semantics.');
        $_SESSION = ['existing' => 'preserved'];
        request_session_helpers_assert(flash_message('first', 'alpha') === null, 'Active flash writes must return null.');
        request_session_helpers_assert(flash_message('second', 'beta') === null, 'Active sibling flash writes must return null.');
        request_session_helpers_assert(flash_message('first') === 'alpha', 'Active flash read must return the requested message.');
        $remainingMessages = session_context_get('flash_messages');
        request_session_helpers_assert($remainingMessages === ['second' => 'beta'], 'Consuming one flash must preserve sibling messages.');
        request_session_helpers_assert(flash_message('first') === null, 'Consumed flash messages must not be returned twice.');
        request_session_helpers_assert(flash_message('second') === 'beta', 'Sibling flash must remain consumable.');
        request_session_helpers_assert(session_context_get('flash_messages') === [], 'The flash bag remains as an empty array after its final message is consumed.');
        request_session_helpers_assert(session_context_get('existing') === 'preserved', 'Flash updates must preserve unrelated session values.');
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        session_id('');
        session_name($originalSessionName);
        ini_set('session.use_cookies', $originalSessionUseCookies);
        ini_set('session.cache_limiter', $originalSessionCacheLimiter);
        if ($originalSessionSavePath !== '') {
            session_save_path($originalSessionSavePath);
        }
    }

    echo "Request/session helper semantics passed.\n";
}
