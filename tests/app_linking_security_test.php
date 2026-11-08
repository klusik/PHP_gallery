<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_security_test.php
 * Module Type: Security Regression Test
 * Purpose: Check isolated native-link policy and legacy upload compatibility.
 * Responsibilities:
 *   - Exercise PKCE, issuer and loopback callback rejection boundaries.
 *   - Check credential separation and fail-closed configuration.
 *   - Preserve the existing gallery upload key parsing and hash contract.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply only the explicit configuration owned by this fixture.
     *
     * @return array{base_url?:string,app_linking?:array{issuer:string},app_linking_secret?:string}
     *     Website address and legacy overrides, when configured.
     */
    function cms_config(): array
    {
        return $GLOBALS['app_link_test_config'];
    }

    /**
     * Supply the current account identity without an application database.
     *
     * @return array{id:int,role:string}|null Fixture account, or null when signed out.
     */
    function current_user(): ?array
    {
        return $GLOBALS['app_link_test_user'] ?? null;
    }

    /**
     * Supply this fixture's trusted transport state.
     *
     * @return bool True when the test request is HTTPS.
     */
    function request_is_https(): bool
    {
        return $GLOBALS['app_link_test_https'];
    }

}

namespace {
    use function Gallery\Controllers\app_linking_configured_issuer;
    use function Gallery\Controllers\app_linking_media_query;
    use function Gallery\Services\app_link_push_code_generate;
    use function Gallery\Services\app_link_push_key;
    use function Gallery\Services\app_linking_access_token_generate;
    use function Gallery\Services\app_linking_access_token_hash;
    use function Gallery\Services\app_linking_code_generate;
    use function Gallery\Services\app_linking_code_hash;
    use function Gallery\Services\app_linking_issuer_valid;
    use function Gallery\Services\app_linking_pkce_s256_verify;
    use function Gallery\Services\app_linking_redirect_uri_valid;
    use function Gallery\Services\app_linking_scope_allowed;
    use function Gallery\Services\app_linking_scope_allows_admin_media;
    use function Gallery\Services\app_linking_scope_allows_gallery_listing;
    use function Gallery\Services\app_linking_scope_allows_gallery_search;
    use function Gallery\Services\app_linking_scope_allows_presentation;
    use function Gallery\Services\app_linking_scope_requests_rolling;
    use function Gallery\Services\app_linking_visibility_scope_allowed;
    use function Gallery\Services\upload_automation_generate_token_value;
    use function Gallery\Services\upload_automation_request_token;
    use function Gallery\Services\upload_automation_token_hash;

    require_once __DIR__ . '/../app/services/schema_inspection.php';
    require_once __DIR__ . '/../app/services/app_linking_schema.php';
    require_once __DIR__ . '/../app/services/app_linking_refresh.php';
    require_once __DIR__ . '/../app/services/app_linking_recovery.php';
    require_once __DIR__ . '/../app/helpers_files.php';
    require_once __DIR__ . '/../app/services/security_tokens.php';
    require_once __DIR__ . '/../app/services/app_linking_policy.php';
    require_once __DIR__ . '/../app/services/app_linking.php';
    require_once __DIR__ . '/../app/services/app_linking_push.php';
    require_once __DIR__ . '/../app/services/seo_request_guard.php';
    require_once __DIR__ . '/../app/services/upload_automation.php';
    require_once __DIR__ . '/../app/controllers/app_linking.php';

    /**
     * Stop on the first failed security expectation.
     *
     * @param bool $condition Whether the invariant holds.
     * @param string $message Safe explanation of the invariant.
     * @return void Returns only when the invariant holds.
     */
    function app_link_test_check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    app_link_test_check(
        app_linking_scope_allows_gallery_listing('link:identity galleries:list')
            && app_linking_scope_allows_gallery_listing('link:identity galleries:list galleries:search')
            && app_linking_scope_allows_gallery_listing('link:identity galleries:list offline_access')
            && app_linking_scope_allows_gallery_listing('link:identity galleries:list media:read')
            && !app_linking_scope_allows_gallery_listing('link:identity')
            && !app_linking_scope_allows_gallery_listing('link:identity offline_access')
            && !app_linking_scope_allows_gallery_listing('galleries:list'),
        'Public photo admission must use the existing exact listing bundles.',
    );

    app_link_test_check(
        app_linking_media_query('page=app_linking_media') === null
            && app_linking_media_query('page=app_linking_media&gallery_id=4&kind=photos')
                === ['gallery_id' => 4, 'kind' => 'photos', 'cursor' => 0, 'image_id' => 0, 'track_id' => '']
            && app_linking_media_query('page=app_linking_media&gallery_id=4&kind=photo_visibility')
                === null,
        'The fixed write query and visibility selector must never qualify as media reads.',
    );
    app_link_test_check(
        app_linking_media_query(
            'page=app_linking_media&gallery_id=4&kind=gallery_info',
        ) === ['gallery_id' => 4, 'kind' => 'gallery_info', 'cursor' => 0,
            'image_id' => 0, 'track_id' => '']
            && app_linking_media_query(
                'page=app_linking_media&gallery_id=4&kind=photo_info&image_id=12',
            ) === ['gallery_id' => 4, 'kind' => 'photo_info', 'cursor' => 0,
                'image_id' => 12, 'track_id' => '']
            && app_linking_media_query(
                'page=app_linking_media&gallery_id=4&kind=photo_info',
            ) === null
            && app_linking_media_query(
                'page=app_linking_media&gallery_id=4&kind=photo_info&image_id=12&after=3',
            ) === null,
        'Detailed Admin metadata selectors must require one exact canonical target.',
    );

    app_link_test_check(
        app_linking_media_query(
            'page=app_linking_media&gallery_id=4&kind=revision',
        ) === ['gallery_id' => 4, 'kind' => 'revision', 'cursor' => 0,
            'image_id' => 0, 'track_id' => '']
            && app_linking_media_query(
                'page=app_linking_media&gallery_id=4&kind=revision&after=3',
            ) === null,
        'Revision reads require one exact gallery without a cursor.',
    );

    $adminRead = 'link:identity galleries:list media:admin';
    $adminEdit = 'link:identity galleries:list gallery:read gallery:visibility '
        . 'galleries:search media:admin presentation:read rolling_access offline_access';
    app_link_test_check(
        app_linking_scope_allows_admin_media($adminRead)
            && app_linking_scope_allows_admin_media($adminEdit)
            && app_linking_scope_allows_gallery_search($adminEdit)
            && app_linking_scope_allows_presentation($adminEdit)
            && app_linking_scope_requests_rolling($adminEdit)
            && app_linking_visibility_scope_allowed($adminEdit)
            && !app_linking_visibility_scope_allowed($adminRead)
            && !app_linking_scope_allows_admin_media('link:identity galleries:list')
            && !app_linking_scope_allows_admin_media('link:identity galleries:list media:read')
            && !app_linking_scope_allows_admin_media('link:identity media:admin')
            && !app_linking_scope_allows_admin_media($adminRead . ' offline_access')
            && !app_linking_scope_allows_admin_media($adminRead . ' media:admin'),
        'Restricted media must require exact separate consent and retain each granted operation.',
    );

    // RFC 7636 appendix B gives a fixed verifier/challenge pair.
    $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    app_link_test_check(
        app_linking_pkce_s256_verify($challenge, $verifier),
        'The standard S256 proof must succeed.',
    );
    app_link_test_check(
        !app_linking_pkce_s256_verify($challenge, $verifier . 'x')
            && !app_linking_pkce_s256_verify($challenge, 'short')
            && !app_linking_pkce_s256_verify($challenge, $verifier . '='),
        'Wrong, short and illegal verifier values must be refused.',
    );

    foreach ([
        'http://gallery.example.test/Galerie',
        'http://localhost.example.test/Galerie',
        'http://127.0.0.1/Galerie',
        'http://localhost:80/Galerie',
        'https://gallery.example.test:443/Galerie',
        'https://gallery.example.test/Galerie/',
        'https://gallery.example.test/Galerie/../other',
        'https://gallery.example.test/Galerie?state=secret',
        'https://user@gallery.example.test/Galerie',
        'https://[0:0:0:0:0:0:0:1]/Galerie',
        // Browser URL parsing would reinterpret these as IPv4 or reject them.
        'https://127.1/Galerie',
        'https://0177.0.0.1/Galerie',
        'https://0x7f000001/Galerie',
        'https://2130706433/Galerie',
        'https://gallery.123/Galerie',
        'https://gallery.0xa/Galerie',
        'https://gallery.0x/Galerie',
        'https://256.0.0.1/Galerie',
    ] as $issuer) {
        app_link_test_check(!app_linking_issuer_valid($issuer), 'Unsafe issuer accepted.');
    }
    app_link_test_check(
        app_linking_issuer_valid('https://gallery.example.test/Galerie')
            && app_linking_issuer_valid('https://[::1]:8443/Galerie')
            && app_linking_issuer_valid('https://127.0.0.1:8443/Galerie')
            && app_linking_issuer_valid('https://gallery.0xg/Galerie')
            && app_linking_issuer_valid('http://localhost:8888/Galerie'),
        'Canonical HTTPS and literal localhost HTTP issuers must be accepted.',
    );
    $client = 'php-gallery-winapp';
    app_link_test_check(
        app_linking_scope_allowed($client, 'link:identity')
            && app_linking_scope_allowed($client, 'link:identity galleries:list')
            && app_linking_scope_allowed($client, 'link:identity galleries:list gallery:upload')
            && !app_linking_scope_allowed($client, 'galleries:list')
            && !app_linking_scope_allowed($client, 'link:identity gallery:upload')
            && !app_linking_scope_allowed('unknown', 'link:identity galleries:list'),
        'Only explicitly registered client scope bundles may be accepted.',
    );
    app_link_test_check(
        app_linking_redirect_uri_valid($client, 'http://127.0.0.1:1234/oauth2redirect/php-gallery')
            && app_linking_redirect_uri_valid($client, 'http://[::1]:1234/oauth2redirect/php-gallery'),
        'Both registered loopback families must be accepted.',
    );
    foreach ([
        'http://localhost:1234/oauth2redirect/php-gallery',
        'http://127.0.0.1:0/oauth2redirect/php-gallery',
        'http://127.0.0.1:1234/oauth2redirect/php-gallery?x=1',
        'http://127.0.0.1:1234/other',
        'https://127.0.0.1:1234/oauth2redirect/php-gallery',
    ] as $redirect) {
        app_link_test_check(
            !app_linking_redirect_uri_valid($client, $redirect),
            'Unregistered callback accepted.',
        );
    }
    app_link_test_check(
        !app_linking_redirect_uri_valid('another-client', 'http://127.0.0.1:1234/oauth2redirect/php-gallery'),
        'An unregistered client must not use the callback.',
    );

    $code = app_linking_code_generate();
    $token = app_linking_access_token_generate();
    app_link_test_check(
        app_linking_code_hash($code) === hash('sha256', $code)
            && app_linking_access_token_hash($token) === hash('sha256', $token)
            && app_linking_code_hash($token) === null
            && app_linking_access_token_hash($code) === null,
        'Authorization codes and bearer tokens must have distinct formats and hashes.',
    );
    $claim = app_link_push_code_generate();
    app_link_test_check(
        preg_match('/\A[A-Z2-7]{25}[AEIMQUY4]\z/D', $claim) === 1,
        'The browser correlation handle must be canonical 128-bit Base32.',
    );
    app_link_test_check(
        \Gallery\Controllers\app_linking_start_request_handle(
            'page=app_linking_start&request_handle=' . $claim,
        ) === $claim
            && \Gallery\Controllers\app_linking_start_request_handle(
                'page=app_linking_start',
            ) === '',
        'Only the fixed browser start route and canonical handle may enter the session.',
    );
    $browserQuery = ['page' => 'app_linking_start', 'request_handle' => $claim];
    app_link_test_check(
        \Gallery\Services\seo_request_guard_unexpected_query_parameters(
            'app_linking_start', $browserQuery,
        ) === []
            && \Gallery\Services\seo_request_guard_unexpected_query_parameters(
                'app_linking_start', $browserQuery + ['code' => $code],
            ) === ['code'],
        'Anonymous dispatch must admit the handle without exempting credential queries.',
    );
    foreach ([
        'page=app_linking_start&request_handle=' . $claim . '&state=hidden',
        'page=app_linking_start&request_handle=' . $claim . '&request_handle=' . $claim,
        'page=app_linking_start&request_handle=%41' . substr($claim, 1),
        'page=app_linking_start&request_handle=' . strtolower($claim),
        'page=app_linking_start&code=' . $code,
        'page=app_linking_start&access_token=' . $token,
        'request_handle=' . $claim . '&page=app_linking_start',
    ] as $query) {
        app_link_test_check(
            \Gallery\Controllers\app_linking_start_request_handle($query) === null,
            'Ambiguous or credential-bearing browser start queries must be refused.',
        );
    }

    $GLOBALS['app_link_test_config'] = [];
    $GLOBALS['app_link_test_https'] = true;
    app_link_test_check(
        \Gallery\Controllers\app_linking_discovery_model() === ['available' => false]
            && \Gallery\Services\app_linking_discovery_capabilities() === [],
        'Missing optional linking configuration must hide linking without touching legacy auth.',
    );
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['SCRIPT_NAME'] = '/private/rewritten/public/index.php';
    $_SERVER['REQUEST_URI'] = '/Galerie/index.php?page=app_linking_discovery';
    app_link_test_check(
        app_linking_configured_issuer() === null,
        'An absent issuer must refuse authorization.',
    );
    $GLOBALS['app_link_test_config']['base_url'] = 'https://Gallery.Example.Test:443/Galerie/';
    app_link_test_check(
        app_linking_configured_issuer() === 'https://gallery.example.test/Galerie',
        'The saved website address must yield the canonical issuer and exact mount.',
    );
    foreach ([
        'http://gallery.example.test/Galerie',
        'https://gallery.example.test/Galerie?redirect=1',
        'https://gallery.example.test/Galerie#fragment',
        'https://gallery.example.test/galerie',
    ] as $unsafeAddress) {
        $GLOBALS['app_link_test_config']['base_url'] = $unsafeAddress;
        app_link_test_check(app_linking_configured_issuer() === null, 'Unsafe website address accepted.');
    }
    $GLOBALS['app_link_test_config']['base_url'] = 'https://gallery.example.test/Galerie/';
    $GLOBALS['app_link_test_config']['app_linking'] = ['issuer' => 'invalid'];
    app_link_test_check(
        app_linking_configured_issuer() === null,
        'A malformed explicit issuer must fail closed instead of using the website address.',
    );
    unset($GLOBALS['app_link_test_config']['app_linking']);
    // Public request paths confirm the configured mount independently of the
    // hosting server's internal SCRIPT_NAME. The URI never supplies authority.
    foreach ([
        null,
        '/other/index.php',
        '/galerie/index.php',
        '/Galerie-other/index.php',
        '/private/rewritten/public/index.php',
        '//gallery.example.test/Galerie/index.php',
        'https://gallery.example.test/Galerie/index.php',
        '/Galerie/index.php/extra',
        '/Galerie/%69ndex.php',
        '/Gal%65rie/index.php',
        '/Galerie/../Galerie/index.php',
        '/Galerie//index.php',
        '/Galerie\\index.php',
        '/Galerie/index.php#fragment',
        "/Galerie/index.php\0",
    ] as $unsafeRequestUri) {
        $_SERVER['REQUEST_URI'] = $unsafeRequestUri;
        app_link_test_check(
            app_linking_configured_issuer() === null,
            'An absent, ambiguous or different public mount must be refused.',
        );
    }
    foreach ([
        '/Galerie/index.php?page=app_linking_discovery',
        '/Galerie/index.php?page=app_linking_push',
        '/Galerie/public/index.php?page=app_linking_start',
    ] as $publicRequestUri) {
        $_SERVER['REQUEST_URI'] = $publicRequestUri;
        app_link_test_check(
            app_linking_configured_issuer()
                === 'https://gallery.example.test/Galerie',
            'Rewritten and direct public entrypoints preserve the exact issuer.',
        );
    }
    $GLOBALS['app_link_test_config']['base_url'] = 'https://gallery.example.test';
    $_SERVER['REQUEST_URI'] = '/index.php?page=app_linking_discovery';
    app_link_test_check(
        app_linking_configured_issuer() === 'https://gallery.example.test',
        'A public root installation must ignore a private script mount.',
    );
    app_link_test_check(
        \Gallery\Controllers\app_linking_public_route_path(
            'app_linking_start', app_linking_configured_issuer(),
        ) === '/index.php?page=app_linking_start',
        'Login returns and approval forms must use the configured public mount.',
    );
    app_link_test_check(
        \Gallery\Controllers\app_linking_public_route_path(
            'app_linking_devices', null,
        ) === '/index.php?page=app_linking_devices',
        'Owner inspection uses the public path without issuance configuration.',
    );
    $GLOBALS['app_link_test_config']['base_url'] = 'https://gallery.example.test/Galerie/';
    $_SERVER['REQUEST_URI'] = '/Galerie/index.php?page=app_linking_discovery';
    $refused = false;
    try {
        app_link_push_key('state');
    } catch (\RuntimeException) {
        $refused = true;
    }
    app_link_test_check($refused, 'An absent linking key must fail closed.');
    $GLOBALS['app_link_test_config']['app_linking_secret'] = 'invalid';
    $refused = false;
    try {
        app_link_push_key('state');
    } catch (\RuntimeException) {
        $refused = true;
    }
    app_link_test_check($refused, 'A malformed linking key must fail closed.');
    if (function_exists('openssl_encrypt') && function_exists('openssl_decrypt')) {
        // A deterministic 32-byte value is used only as fixture key material.
        $GLOBALS['app_link_test_config']['app_linking_secret'] = str_repeat('a1', 32);
        app_link_test_check(
            app_link_push_key('state') !== app_link_push_key('session')
                && app_link_push_key('state') !== app_link_push_key('rate'),
            'Pending state, session and rate-limit keys must have separate domains.',
        );
        $binding = \Gallery\Services\app_link_push_session_hash(
            'https://gallery.example.test/Galerie', 7, 'session-a',
        );
        app_link_test_check(
            $binding !== \Gallery\Services\app_link_push_session_hash(
                'https://gallery.example.test/Galerie', 7, 'session-b',
            ) && $binding !== \Gallery\Services\app_link_push_session_hash(
                'https://gallery.example.test/Galerie', 8, 'session-a',
            ) && $binding !== \Gallery\Services\app_link_push_session_hash(
                'https://other.example.test/Galerie', 7, 'session-a',
            ),
            'The persisted binding must distinguish issuer, account and session.',
        );
    }
    $GLOBALS['app_link_test_user'] = ['id' => 7, 'role' => 'admin'];
    app_link_test_check(
        \Gallery\Controllers\app_link_push_session_user() === null,
        'A closed browser session cannot authorize a claim.',
    );
    session_id(bin2hex(random_bytes(16)));
    app_link_test_check(session_start([
        'use_cookies' => false,
        'cache_limiter' => '',
        'save_path' => sys_get_temp_dir(),
    ]), 'Could not start the isolated session fixture.');
    try {
        $_SESSION = ['user_id' => 7, 'csrf_token' => 'fixture-token',
            'app_link_push_request' => 91];
        app_link_test_check(
            \Gallery\Controllers\app_link_push_session_user() === 7
                && \Gallery\Controllers\app_link_push_csrf_valid('fixture-token')
                && !\Gallery\Controllers\app_link_push_csrf_valid('wrong'),
            'Only the matching active Admin and session token may proceed.',
        );
        app_link_test_check(
            !\Gallery\Controllers\app_link_push_claim(
                'invalid', 'https://gallery.example.test/Galerie', 'wrong',
            ) && ($_SESSION['app_link_push_request'] ?? null) === 91,
            'A CSRF failure must neither claim nor replace a prior request.',
        );
        app_link_test_check(
            \Gallery\Controllers\app_link_push_consume(
                'https://gallery.example.test/Galerie', 'wrong',
            ) === null && ($_SESSION['app_link_push_request'] ?? null) === 91,
            'A CSRF failure must not consume the pending request.',
        );
        app_link_test_check(
            !\Gallery\Controllers\app_link_push_claim(
                'invalid', 'https://gallery.example.test/Galerie', 'fixture-token',
            ) && !isset($_SESSION['app_link_push_request']),
            'A refused replacement must invalidate the previous session claim.',
        );
        $_SESSION['user_id'] = 8;
        app_link_test_check(
            \Gallery\Controllers\app_link_push_session_user() === null,
            'An account switch must not retain the prior session identity.',
        );
        $_SESSION['user_id'] = 7;
        $_SESSION['admin_support'] = null;
        app_link_test_check(
            \Gallery\Controllers\app_link_push_session_user() === null,
            'Delegated support sessions must be rejected even with an empty marker.',
        );
    } finally {
        session_destroy();
        unset($GLOBALS['app_link_test_user']);
    }
    $GLOBALS['app_link_test_config']['app_linking'] = [
        'issuer' => 'https://gallery.example.test/Galerie',
    ];
    $GLOBALS['app_link_test_config']['base_url'] = 'https://different.example.test/Galerie';
    app_link_test_check(
        app_linking_configured_issuer() === 'https://gallery.example.test/Galerie',
        'Configured authority and mount must match the request.',
    );
    $_SERVER['HTTP_HOST'] = 'other.example.test';
    app_link_test_check(app_linking_configured_issuer() === null, 'Wrong Host must be refused.');
    $_SERVER['HTTP_HOST'] = 'gallery.example.test';
    $_SERVER['REQUEST_URI'] = '/other/index.php?page=app_linking_discovery';
    app_link_test_check(app_linking_configured_issuer() === null, 'Wrong mount must be refused.');
    $_SERVER['REQUEST_URI'] = '/Galerie/index.php?page=app_linking_discovery';
    $GLOBALS['app_link_test_https'] = false;
    app_link_test_check(app_linking_configured_issuer() === null, 'A TLS issuer cannot use HTTP.');

    $previousServer = $_SERVER;
    $previousConfig = $GLOBALS['app_link_test_config'];
    unset($GLOBALS['app_link_test_config']['app_linking']);
    $GLOBALS['app_link_test_config']['base_url'] = 'http://LOCALHOST:8888/Galerie/';
    $localIssuer = 'http://localhost:8888/Galerie';
    // The port models the prepared MAMP mount. Either socket family can
    // serve an installation on the same computer.
    foreach (['127.0.0.1', '::1', '::ffff:127.0.0.1'] as $loopback) {
        $_SERVER = [
            'REMOTE_ADDR' => $loopback,
            'SERVER_ADDR' => $loopback,
            'SERVER_PORT' => '8888',
            'HTTP_HOST' => 'localhost:8888',
            'SCRIPT_NAME' => '/private/rewritten/public/index.php',
            'REQUEST_URI' => '/Galerie/index.php?page=app_linking_discovery',
        ];
        app_link_test_check(app_linking_configured_issuer() === $localIssuer,
            'Direct localhost HTTP must preserve the exact port and mount.');
    }
    $localServer = $_SERVER;
    foreach ([
        ['REMOTE_ADDR' => '192.0.2.1'],
        ['SERVER_ADDR' => '192.0.2.1'],
        ['SERVER_PORT' => '80'],
        ['HTTP_HOST' => 'localhost'],
        ['HTTP_HOST' => '127.0.0.1:8888'],
        ['HTTP_X_FORWARDED_FOR' => '192.0.2.1'],
        ['HTTP_FORWARDED' => 'for=192.0.2.1'],
        ['HTTP_X_FORWARDED' => 'for=192.0.2.1'],
        ['HTTP_VIA' => '1.1 proxy'],
        ['HTTP_X_REAL_IP' => '192.0.2.1'],
    ] as $otherTransport) {
        $_SERVER = array_replace($localServer, $otherTransport);
        app_link_test_check(app_linking_configured_issuer() === null,
            'A nonlocal, forwarded or mismatched HTTP request must be refused.');
    }
    $_SERVER = $localServer;
    $_SERVER['HTTP_ORIGIN'] = 'http://localhost:8888';
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
    app_link_test_check(
        \Gallery\Controllers\app_linking_browser_origin_valid($localIssuer),
        'The exact local browser origin must be admitted.',
    );
    foreach (['null', 'https://localhost:8888', 'http://localhost'] as $otherOrigin) {
        $_SERVER['HTTP_ORIGIN'] = $otherOrigin;
        app_link_test_check(
            !\Gallery\Controllers\app_linking_browser_origin_valid($localIssuer),
            'An opaque origin or an origin with a different scheme or port must fail.',
        );
    }
    $_SERVER['REQUEST_URI'] = '/galerie/index.php?page=app_linking_discovery';
    app_link_test_check(app_linking_configured_issuer() === null,
        'Local HTTP cannot change the configured mount.');
    app_link_test_check(
        \Gallery\Services\app_linking_scope_transport_allowed(
            $localIssuer, \Gallery\Services\APP_LINKING_GALLERIES_SCOPE,
        )
        && !\Gallery\Services\app_linking_scope_transport_allowed(
            $localIssuer, \Gallery\Services\APP_LINKING_IDENTITY_OFFLINE_SCOPE,
        ),
        'Local HTTP preserves temporary access and refuses legacy offline access.',
    );
    // All four canonical combinations share renewal but keep upload and
    // search authority distinct. The issuer stays bound to the direct route.
    $rollingBase = 'link:identity galleries:list';
    foreach ([
        '' => false,
        ' gallery:upload' => false,
        ' galleries:search' => true,
        ' gallery:upload galleries:search' => true,
    ] as $extras => $allowsSearch) {
        $rolling = $rollingBase . $extras . ' rolling_access offline_access';
        app_link_test_check(
            app_linking_scope_allowed($client, $rolling)
                && !app_linking_scope_allowed('other-client', $rolling)
                && app_linking_scope_allows_gallery_listing($rolling)
                && \Gallery\Services\app_linking_scope_allows_gallery_search($rolling)
                    === $allowsSearch
                && \Gallery\Services\app_linking_scope_requests_refresh($rolling)
                && \Gallery\Services\app_linking_scope_transport_allowed(
                    $localIssuer, $rolling,
                )
                && !\Gallery\Services\app_linking_scope_transport_allowed(
                    'http://127.0.0.1:8888/Galerie', $rolling,
                ),
            'Rolling scopes must preserve the exact client and gallery permissions.',
        );
    }
    $registered = \Gallery\Services\app_linking_public_client($client)['scopes'];
    foreach ([
        '' => false,
        ' gallery:upload' => false,
        ' galleries:search' => true,
        ' gallery:upload galleries:search' => true,
    ] as $extras => $allowsSearch) {
        $base = $rollingBase . $extras;
        $basic = $base . ' rolling_access offline_access';
        $presentation = $base . ' media:read presentation:read rolling_access offline_access';
        app_link_test_check(
            in_array($presentation, $registered, true)
                && \Gallery\Services\app_linking_scope_base($presentation)
                    === \Gallery\Services\app_linking_scope_base($basic)
                && \Gallery\Services\app_linking_scope_allows_gallery_search($presentation)
                    === $allowsSearch
                && \Gallery\Services\app_linking_scope_allows_presentation($presentation)
                && !\Gallery\Services\app_linking_scope_allows_presentation($basic)
                && \Gallery\Services\app_linking_scope_requests_refresh($presentation)
                && \Gallery\Services\app_linking_scope_transport_allowed(
                    $localIssuer, $presentation,
                )
                && !\Gallery\Services\app_linking_scope_transport_allowed(
                    'http://127.0.0.1:8888/Galerie', $presentation,
                ),
            'Rolling presentation must add only the expressly approved presentation permission.',
        );
    }
    foreach ([
        'link:identity rolling_access offline_access',
        $rollingBase . ' offline_access rolling_access',
        $rollingBase . ' rolling_access',
        $rollingBase . ' galleries:search offline_access',
        $rollingBase . ' gallery:read rolling_access offline_access',
        $rollingBase . ' presentation:read rolling_access offline_access',
        $rollingBase . ' media:read rolling_access offline_access',
        $rollingBase . ' rolling_access offline_access media:read presentation:read',
    ] as $unregistered) {
        app_link_test_check(
            !app_linking_scope_allowed($client, $unregistered)
                && !\Gallery\Services\app_linking_scope_requests_rolling($unregistered)
                && !\Gallery\Services\app_linking_scope_allows_presentation($unregistered),
            'Unregistered renewal bundles must fail closed.',
        );
    }
    $_SERVER = $previousServer;
    $GLOBALS['app_link_test_config'] = $previousConfig;
    $GLOBALS['app_link_test_mount'] = '/Galerie';

    // Inspect absent, partial and unknown migration states without a database.
    \Gallery\Services\schema_inspection_set_query_executor_for_tests(
        static fn(string $type, string $table, string $object): bool =>
            $table !== 'app_link_refresh_families',
    );
    app_link_test_check(
        \Gallery\Services\app_link_refresh_reclaim_state() === 'absent'
            && \Gallery\Services\schema_inspection_is_available(
                \Gallery\Services\app_linking_issuance_schema_status(),
            ),
        'Confirmed absent refresh storage must preserve legacy issuance.',
    );
    \Gallery\Services\schema_inspection_set_query_executor_for_tests(
        static function (string $type, string $table, string $object): bool {
            if ($table === 'app_link_refresh_families') {
                throw new \RuntimeException('Fixture inspection failure.');
            }
            return true;
        },
    );
    app_link_test_check(
        \Gallery\Services\app_link_refresh_reclaim_state() === 'unknown',
        'An inspection failure must never authorize reclamation.',
    );
    \Gallery\Services\schema_inspection_set_query_executor_for_tests(
        static fn(string $type, string $table, string $object): bool =>
            !($table === 'app_link_refresh_families'
                && $object === 'idle_expires_at'),
    );
    app_link_test_check(
        \Gallery\Services\app_link_refresh_reclaim_state() === 'unknown'
            && \Gallery\Services\schema_inspection_is_available(
                \Gallery\Services\app_link_refresh_revocation_schema_status(),
            )
            && !\Gallery\Services\schema_inspection_is_available(
                \Gallery\Services\app_link_refresh_schema_status(),
            ),
        'Partial issuance schema must refuse reclaim but preserve narrow revoke.',
    );
    // Every combination of old and widened columns retains short-scope
    // readiness; only the fully widened combination admits future capacity.
    $scopeOwners = [
        'app_link_requests', 'app_link_codes', 'app_link_grants',
        'app_link_refresh_families',
    ];
    // Four binary schema choices produce exactly sixteen structural vectors.
    for ($mask = 0; $mask < 16; ++$mask) {
        \Gallery\Services\schema_inspection_set_query_executor_for_tests(
            static function (
                string $type,
                string $table,
                string $object,
                array|string|null $definition = null,
            ) use ($scopeOwners, $mask): bool {
                $index = array_search($table, $scopeOwners, true);
                if ($type === 'column_exact' && $object === 'scope' && $index !== false) {
                    $expected = ($mask & (1 << $index)) !== 0
                        ? 'varbinary(255)' : 'varbinary(64)';
                    return is_array($definition) && ($definition[0] ?? null) === $expected;
                }
                return true;
            },
        );
        app_link_test_check(
            \Gallery\Services\schema_inspection_is_available(
                \Gallery\Services\app_linking_issuance_schema_status(),
            ) && \Gallery\Services\schema_inspection_is_available(
                \Gallery\Services\app_link_refresh_schema_status(),
            ),
            'Each supported scope-width combination must preserve existing grants.',
        );
        app_link_test_check(
            \Gallery\Services\schema_inspection_is_available(
                \Gallery\Services\app_linking_wide_scope_schema_status(),
            ) === ($mask === 15),
            'Future wide scope requires all four widened columns.',
        );
    }
    \Gallery\Services\schema_inspection_set_query_executor_for_tests(
        static function (
            string $type,
            string $table,
            string $object,
            array|string|null $definition = null,
        ): bool {
            if ($type === 'column_exact' && $table === 'app_link_grants'
                && $object === 'scope' && is_array($definition)
                && ($definition[0] ?? null) === 'varbinary(255)') {
                throw new \RuntimeException('Fixture metadata failure.');
            }
            return true;
        },
    );
    app_link_test_check(
        \Gallery\Services\schema_inspection_is_unknown(
            \Gallery\Services\app_linking_issuance_schema_status(),
        ) && \Gallery\Services\schema_inspection_is_unknown(
            \Gallery\Services\app_linking_wide_scope_schema_status(),
        ),
        'Unknown width metadata must not authorize a supported or future scope.',
    );
    \Gallery\Services\schema_inspection_set_query_executor_for_tests(
        static function (
            string $type,
            string $table,
            string $object,
            array|string|null $definition = null,
        ): bool {
            return !($type === 'column_exact' && $table === 'app_link_grants'
                && $object === 'scope');
        },
    );
    app_link_test_check(
        \Gallery\Services\schema_inspection_is_missing(
            \Gallery\Services\app_linking_issuance_schema_status(),
        ) && !\Gallery\Services\schema_inspection_is_available(
            \Gallery\Services\app_linking_wide_scope_schema_status(),
        ),
        'An unexpected scope definition must refuse issuance and wide scope.',
    );
    // Simulate generated-column metadata: the legacy exact type probe alone
    // would match, but scope readiness must require the plain-column option.
    foreach ($scopeOwners as $generatedOwner) {
        \Gallery\Services\schema_inspection_set_query_executor_for_tests(
            static function (
                string $type,
                string $table,
                string $object,
                array|string|null $definition = null,
            ) use ($generatedOwner): bool {
                if ($type === 'column_exact' && $table === $generatedOwner
                    && $object === 'scope' && is_array($definition)) {
                    return ($definition[4] ?? false) !== true;
                }
                return true;
            },
        );
        app_link_test_check(
            \Gallery\Services\schema_inspection_is_missing(
                \Gallery\Services\app_linking_scope_column_status($generatedOwner),
            ) && \Gallery\Services\schema_inspection_is_missing(
                \Gallery\Services\app_linking_wide_scope_schema_status(),
            ),
            'Generated scope metadata must refuse either width and future wide scope.',
        );
        if ($generatedOwner === 'app_link_refresh_families') {
            app_link_test_check(
                \Gallery\Services\schema_inspection_is_missing(
                    \Gallery\Services\app_link_refresh_schema_status(),
                ),
                'Generated refresh scope must refuse refresh issuance and rotation.',
            );
        }
    }
    \Gallery\Services\schema_inspection_set_query_executor_for_tests(null);

    // A positive disk probe never turns an inconclusive scan into absence.
    $recoveryFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'gallery-link-recovery-' . bin2hex(random_bytes(12));
    app_link_test_check(mkdir($recoveryFixture, 0700), 'Recovery fixture creation failed.');
    $recoveryDirectory = realpath($recoveryFixture);
    app_link_test_check(is_string($recoveryDirectory), 'Recovery fixture is unavailable.');
    $recoveryImage = $recoveryDirectory . DIRECTORY_SEPARATOR . 'source.jpg';
    $recoveryLink = $recoveryDirectory . DIRECTORY_SEPARATOR . 'linked.jpg';
    $recoveryOutside = $recoveryDirectory . '-outside.jpg';
    $recoveryBytes = 'fixture-only recovery image bytes';
    try {
        app_link_test_check(
            file_put_contents($recoveryImage, $recoveryBytes) === strlen($recoveryBytes),
            'Recovery fixture write failed.',
        );
        $recoveryHash = hash('sha256', $recoveryBytes);
        $indexedMatch = [
            'remote_relative_path' => 'source.jpg',
            'size' => strlen($recoveryBytes),
            'sha256' => $recoveryHash,
        ];
        app_link_test_check(
            \Gallery\Services\app_linking_inventory_verify_files($recoveryDirectory, [$indexedMatch]),
            'A current indexed original must produce positive inventory evidence.',
        );
        $wrongHash = $indexedMatch;
        $wrongHash['sha256'] = hash('sha256', 'different');
        $wrongSize = $indexedMatch;
        $wrongSize['size']++;
        $outsidePath = $indexedMatch;
        $outsidePath['remote_relative_path'] = '../source.jpg';
        app_link_test_check(
            !\Gallery\Services\app_linking_inventory_verify_files($recoveryDirectory, [$wrongHash])
                && !\Gallery\Services\app_linking_inventory_verify_files($recoveryDirectory, [$wrongSize])
                && !\Gallery\Services\app_linking_inventory_verify_files($recoveryDirectory, [$outsidePath])
                && !\Gallery\Services\app_linking_inventory_verify_files(
                    $recoveryDirectory,
                    array_fill(0, \Gallery\Services\APP_LINKING_INVENTORY_VERIFY_MAX_FILES + 1, $indexedMatch),
                ),
            'Changed bytes, wrong size, traversal and an exhausted batch must stay unknown.',
        );
        app_link_test_check(file_put_contents($recoveryImage, str_repeat('x', strlen($recoveryBytes)))
            === strlen($recoveryBytes), 'Changed-original fixture setup failed.');
        app_link_test_check(
            !\Gallery\Services\app_linking_inventory_verify_files($recoveryDirectory, [$indexedMatch]),
            'A replaced same-size original must not confirm a stale index hash.',
        );
        app_link_test_check(file_put_contents($recoveryImage, $recoveryBytes) === strlen($recoveryBytes),
            'Changed-original fixture restoration failed.');
        if (function_exists('link') && @link($recoveryImage, $recoveryLink)) {
            try {
                app_link_test_check(
                    !\Gallery\Services\app_linking_inventory_verify_files($recoveryDirectory, [$indexedMatch]),
                    'A multiply linked indexed original must remain unknown.',
                );
            } finally {
                app_link_test_check(unlink($recoveryLink), 'Fixture hardlink cleanup failed.');
            }
        }
        app_link_test_check(
            \Gallery\Services\app_linking_recovery_scan(
                $recoveryDirectory, $recoveryHash, strlen($recoveryBytes),
            ),
            'Matching regular image bytes must provide positive evidence.',
        );
        app_link_test_check(
            !\Gallery\Services\app_linking_recovery_scan(
                $recoveryDirectory, hash('sha256', 'different'), strlen($recoveryBytes),
            ) && !\Gallery\Services\app_linking_recovery_scan(
                $recoveryDirectory, $recoveryHash,
                \Gallery\Services\APP_LINKING_RECOVERY_MAX_FILE_BYTES + 1,
            ),
            'A mismatch or oversized candidate must remain inconclusive.',
        );
        app_link_test_check(rename($recoveryImage, $recoveryOutside),
            'Recovery outside fixture setup failed.');
        app_link_test_check(
            !\Gallery\Services\app_linking_inventory_verify_files($recoveryDirectory, [$indexedMatch]),
            'A missing indexed original must not confirm inventory.',
        );
        if (function_exists('symlink') && @symlink($recoveryOutside, $recoveryLink)) {
            $linkedMatch = $indexedMatch;
            $linkedMatch['remote_relative_path'] = 'linked.jpg';
            app_link_test_check(
                !\Gallery\Services\app_linking_recovery_scan(
                    $recoveryDirectory, $recoveryHash, strlen($recoveryBytes),
                ) && !\Gallery\Services\app_linking_inventory_verify_files($recoveryDirectory, [$linkedMatch]),
                'A symbolic-link candidate must not be followed or confirmed.',
            );
        }
    } finally {
        @unlink($recoveryLink);
        @unlink($recoveryImage);
        @unlink($recoveryOutside);
        @rmdir($recoveryDirectory);
    }
    app_link_test_check(
        app_linking_scope_allowed($client, 'link:identity offline_access')
            && !app_linking_scope_allowed($client, 'offline_access')
            && !app_linking_scope_allowed($client, 'offline_access link:identity'),
        'Offline access requires its own explicitly registered exact bundle.',
    );

    // Decimal string comparisons preserve the 64-bit boundary on 32-bit PHP.
    unset($_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_CONTENT_LENGTH'],
        $_SERVER['HTTP_TRANSFER_ENCODING']);
    app_link_test_check(
        \Gallery\Controllers\app_linking_upload_content_length_error()
            === 'length_required',
        'Multipart must require a declared length.',
    );
    $_SERVER['CONTENT_LENGTH'] = '35433480192';
    app_link_test_check(
        \Gallery\Controllers\app_linking_upload_content_length_error() === null,
        'The exact delegated request boundary must be accepted.',
    );
    $_SERVER['CONTENT_LENGTH'] = '35433480193';
    app_link_test_check(
        \Gallery\Controllers\app_linking_upload_content_length_error()
            === 'request_too_large',
        'A declared size above the delegated boundary must be refused.',
    );
    foreach (['01', '-1', '1e3', '0'] as $badLength) {
        $_SERVER['CONTENT_LENGTH'] = $badLength;
        app_link_test_check(
            \Gallery\Controllers\app_linking_upload_content_length_error()
                === 'invalid_request',
            'Noncanonical multipart length must be refused.',
        );
    }
    $_SERVER['CONTENT_LENGTH'] = '100';
    $_SERVER['HTTP_CONTENT_LENGTH'] = '99';
    app_link_test_check(
        \Gallery\Controllers\app_linking_upload_content_length_error()
            === 'invalid_request',
        'Conflicting length metadata must be refused.',
    );
    unset($_SERVER['HTTP_CONTENT_LENGTH']);
    $_SERVER['HTTP_TRANSFER_ENCODING'] = 'chunked';
    app_link_test_check(
        \Gallery\Controllers\app_linking_upload_content_length_error()
            === 'invalid_request',
        'Transfer encoding combined with a declared length must be refused.',
    );
    unset($_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_TRANSFER_ENCODING']);

    // The legacy API key remains an independent authentication format.
    $legacy = upload_automation_generate_token_value();
    app_link_test_check(
        str_starts_with($legacy, 'pgu_')
            && upload_automation_token_hash($legacy) === hash('sha256', $legacy)
            && upload_automation_request_token($legacy, '', '') === $legacy
            && upload_automation_request_token('', 'Bearer ' . $legacy, '') === $legacy
            && upload_automation_request_token('', '', $legacy) === $legacy
            && app_linking_access_token_hash($legacy) === null,
        'Existing gallery API-key extraction and hashing must remain independent.',
    );
    echo "app linking security policy tests passed\n";
}
