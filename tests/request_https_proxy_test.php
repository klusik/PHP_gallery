<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/request_https_proxy_test.php
 * Module Type: Regression Test
 * Purpose: Verify shared HTTPS trust for Admin cookies, public URLs and Viewer transport.
 * Responsibilities: Cover direct TLS, explicit proxy trust, ambiguous headers and cookie policy.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return mutable isolated configuration without reading installation state.
     *
     * @return array<string,mixed> Fixture configuration.
     */
    function cms_config(): array
    {
        return $GLOBALS['request_https_proxy_config'];
    }
}

namespace Gallery\Services {
    /**
     * Keep transport fixtures on the supported query-routing entry point.
     * @return bool False because these fixtures do not enable URL rewriting.
     */
    function url_rewrite_should_emit_clean_urls(): bool
    {
        return false;
    }
}

namespace {
    use function Gallery\Core\cms_start_session;
    use function Gallery\Core\public_base_url;
    use function Gallery\Core\request_aware_base_url;
    use function Gallery\Core\request_is_https;
    use function Gallery\Services\viewer_request_is_https;

    require_once dirname(__DIR__) . '/app/helpers_request.php';
    require_once dirname(__DIR__) . '/app/services/client_ip.php';
    require_once dirname(__DIR__) . '/app/bootstrap/session.php';

    /**
     * Assert an observable transport or consumer behavior.
     *
     * @param bool $condition Whether the required behavior occurred.
     * @param string $message Explanation of the protected behavior.
     * @return void Throw on failure.
     */
    function request_https_proxy_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $GLOBALS['request_https_proxy_config'] = ['base_url' => '', 'security' => []];
    $httpRequest = [
        'REMOTE_ADDR' => '198.51.100.25',
        'SERVER_PORT' => '80',
        'HTTP_HOST' => 'gallery.example.test',
        'SCRIPT_NAME' => '/galerie/index.php',
    ];
    $_SERVER = $httpRequest + ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_SSL' => 'on'];
    request_https_proxy_assert(!request_is_https(), 'An untrusted client must not forge HTTPS for Admin cookies or URLs.');
    request_https_proxy_assert(!viewer_request_is_https(), 'Viewer transport must agree with the shared untrusted-peer policy.');
    request_https_proxy_assert(
        request_aware_base_url('http://gallery.example.test/galerie') === 'http://gallery.example.test/galerie'
            && public_base_url() === 'http://gallery.example.test/galerie',
        'Spoofed forwarded headers must not upgrade configured or inferred public URLs.'
    );

    $cases = [
        [[], $httpRequest, false, 'Direct HTTP'],
        [[], $httpRequest + ['HTTPS' => 'on'], true, 'Direct TLS'],
        [[], $httpRequest + ['HTTPS' => '0'], false, 'Disabled TLS indicator'],
        [[], array_replace($httpRequest, ['SERVER_PORT' => '443']), true, 'Direct TLS port'],
        [['trusted_proxies' => ['198.51.100.0/24']], $_SERVER, false, 'Proxy without enabled protocol header'],
        [['trusted_proxy_protocol_headers' => ['x-forwarded-proto']], $_SERVER, false, 'Header without trusted proxy'],
        [['trusted_proxies' => ['not-a-cidr'], 'trusted_proxy_protocol_headers' => ['x-forwarded-proto']], $_SERVER, false, 'Invalid proxy config'],
    ];
    $trusted = [
        'trusted_proxies' => ['203.0.113.0/24', '2001:db8::/64'],
        'trusted_proxy_protocol_headers' => ['x-forwarded-proto', 'x-forwarded-ssl'],
    ];
    $proxyRequest = array_replace($httpRequest, ['REMOTE_ADDR' => '203.0.113.9']);
    foreach ([
        [[], false, 'No forwarded protocol'],
        [['HTTP_X_FORWARDED_PROTO' => ' HTTPS '], true, 'Trusted forwarded HTTPS'],
        [['HTTP_X_FORWARDED_SSL' => 'ON'], true, 'Trusted forwarded SSL'],
        [['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_SSL' => 'on'], true, 'Consistent enabled headers'],
        [['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_SSL' => 'off'], false, 'Conflicting enabled headers'],
        [['HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_SSL' => 'on'], false, 'Opposite conflicting headers'],
        [['HTTP_X_FORWARDED_PROTO' => 'https,http'], false, 'Multiple protocol values'],
        [['HTTP_X_FORWARDED_SSL' => 'on,off'], false, 'Multiple SSL values'],
        [['HTTP_X_FORWARDED_PROTO' => 'invalid', 'HTTP_X_FORWARDED_SSL' => 'on'], false, 'Malformed enabled header'],
    ] as [$headers, $expected, $label]) {
        $cases[] = [$trusted, $proxyRequest + $headers, $expected, $label];
    }
    $cases[] = [$trusted, array_replace($proxyRequest, ['REMOTE_ADDR' => '2001:db8::42', 'HTTP_X_FORWARDED_PROTO' => 'https']), true, 'Trusted IPv6 CIDR'];
    $cases[] = [$trusted, array_replace($proxyRequest, ['REMOTE_ADDR' => '2001:db9::42', 'HTTP_X_FORWARDED_PROTO' => 'https']), false, 'Untrusted IPv6'];
    $cases[] = [$trusted, array_replace($proxyRequest, ['REMOTE_ADDR' => 'invalid', 'HTTP_X_FORWARDED_PROTO' => 'https']), false, 'Invalid direct peer'];
    $cases[] = [$trusted, ['HTTP_X_FORWARDED_PROTO' => 'https'], false, 'Missing direct peer'];
    $protoOnly = array_replace($trusted, ['trusted_proxy_protocol_headers' => ['x-forwarded-proto']]);
    $cases[] = [$protoOnly, $proxyRequest + ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_SSL' => 'off'], true, 'Disabled SSL header is ignored'];
    $exactProxy = array_replace($protoOnly, ['trusted_proxies' => ['203.0.113.9']]);
    $cases[] = [$exactProxy, $proxyRequest + ['HTTP_X_FORWARDED_PROTO' => 'https'], true, 'Trusted exact proxy IP'];
    $cases[] = [$exactProxy, array_replace($proxyRequest, ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_PROTO' => 'https']), false, 'Exact trust does not include adjacent peer'];
    $cases[] = [$trusted, $httpRequest + ['HTTPS' => 'on', 'HTTP_X_FORWARDED_PROTO' => 'invalid'], true, 'Direct TLS is independent of untrusted headers'];

    foreach ($cases as [$security, $server, $expected, $label]) {
        $GLOBALS['request_https_proxy_config']['security'] = $security;
        $_SERVER = $server;
        request_https_proxy_assert(request_is_https() === $expected, $label . ': shared HTTPS resolution failed.');
        request_https_proxy_assert(viewer_request_is_https() === $expected, $label . ': Viewer HTTPS resolution diverged.');
    }

    $sessionDirectory = sys_get_temp_dir() . '/gallery-https-' . bin2hex(random_bytes(8));
    request_https_proxy_assert(mkdir($sessionDirectory, 0700), 'Isolated session directory must be created.');
    $previousSavePath = session_save_path();
    session_save_path($sessionDirectory);
    try {
        foreach ([
            [[], $httpRequest + ['HTTP_X_FORWARDED_PROTO' => 'https'], false],
            [[], $httpRequest + ['HTTPS' => 'on'], true],
            [$trusted, $proxyRequest + ['HTTP_X_FORWARDED_PROTO' => 'https'], true],
        ] as [$security, $server, $expected]) {
            $GLOBALS['request_https_proxy_config']['security'] = $security;
            $_SERVER = $server;
            cms_start_session(['admin_session_name' => 'gallery_https_proxy_test']);
            $cookie = session_get_cookie_params();
            request_https_proxy_assert($cookie['secure'] === $expected, 'Admin session Secure policy must use the shared transport decision.');
            request_https_proxy_assert($cookie['httponly'] && $cookie['samesite'] === 'Lax', 'Other Admin cookie protections must remain intact.');
            session_destroy();
            session_id('');
        }
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_save_path($previousSavePath);
        foreach (glob($sessionDirectory . '/sess_*') ?: [] as $sessionFile) {
            unlink($sessionFile);
        }
        rmdir($sessionDirectory);
    }

    $_SERVER = $proxyRequest + ['HTTP_X_FORWARDED_PROTO' => 'https'];
    $GLOBALS['request_https_proxy_config']['security'] = $trusted;
    request_https_proxy_assert(
        request_aware_base_url('http://gallery.example.test/galerie') === 'https://gallery.example.test/galerie'
            && public_base_url() === 'https://gallery.example.test/galerie',
        'Configured TLS termination must continue upgrading same-installation and inferred public URLs.'
    );
    request_https_proxy_assert(
        request_aware_base_url('http://other.example.test/galerie') === 'http://other.example.test/galerie',
        'Request transport must not rewrite an unrelated configured host.'
    );

    foreach ([[], $trusted] as $proxyPolicy) {
        $GLOBALS['request_https_proxy_config']['security'] = $proxyPolicy;
        request_https_proxy_assert(\Gallery\Core\public_visual_preview_home_url() === '/galerie/index.php?page=home&preview=visual',
            'Protected Home remains on the browser origin and actual mount with either trusted or untrusted TLS forwarding.');
    }

    echo "Shared HTTPS proxy and Admin cookie policy passed.\n";
}
