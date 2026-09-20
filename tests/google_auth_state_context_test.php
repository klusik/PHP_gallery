<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/google_auth_state_context_test.php
 * Module Type: Regression Test
 * Purpose: Prove OAuth challenges retain one-time semantics outside session transport.
 * Responsibilities:
 *   - Check caller isolation, expiry, challenge entropy and credential exclusion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply inert OAuth configuration without reading the installed site.
     * @return array{google_login:array{enabled:bool,client_id:string,client_secret:string,redirect_uri:string,prompt:string}} Fixture settings, never sent to Google.
     */
    function cms_config(): array
    {
        return ['google_login' => [
            'enabled' => true,
            'client_id' => 'fixture-client',
            'client_secret' => 'fixture-secret-not-for-the-authorization-url',
            'redirect_uri' => 'https://example.invalid/google-callback',
            'prompt' => 'select_account',
        ]];
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/google_auth.php';
    use const Gallery\Core\CMS_GOOGLE_AUTH_ENDPOINT;
    use const Gallery\Core\GOOGLE_AUTH_STATE_RANDOM_BYTES;
    use const Gallery\Core\GOOGLE_AUTH_STATE_TTL_SECONDS;
    use function Gallery\Services\google_auth_authorization_url;
    use function Gallery\Services\google_auth_consume_state;

    /**
     * Require an OAuth state invariant without starting a session or network request.
     * @param bool $condition Expected one-time challenge or isolation property.
     * @param string $message Non-secret assertion label.
     * @return void
     */
    function oauth_context_assert(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    $_SESSION = ['sentinel' => 'controller-owned'];
    $states = [
        'expired' => ['created_at' => time() - GOOGLE_AUTH_STATE_TTL_SECONDS - 2],
        'invalid' => false,
        'fresh' => ['created_at' => time()],
    ];
    $otherCaller = [];
    $url = google_auth_authorization_url('unknown-mode', '/admin', $states, null);
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $state = (string) ($query['state'] ?? '');
    oauth_context_assert(str_starts_with($url, CMS_GOOGLE_AUTH_ENDPOINT . '?'), 'Unexpected provider endpoint.');
    oauth_context_assert(strlen($state) === GOOGLE_AUTH_STATE_RANDOM_BYTES * 2 && ctype_xdigit($state), 'Challenge entropy/encoding changed.');
    oauth_context_assert(!str_contains($url, 'fixture-secret') && !isset($query['client_secret']), 'Authorization URL exposed the client secret.');
    oauth_context_assert(!isset($states['expired']) && !isset($states['invalid']) && isset($states['fresh']), 'Challenge pruning changed.');
    oauth_context_assert(google_auth_consume_state($state, $otherCaller) === null && isset($states[$state]), 'Challenge leaked between callers.');
    $entry = google_auth_consume_state($state, $states);
    oauth_context_assert($entry !== null && $entry['mode'] === 'login' && $entry['user_id'] === null && $entry['return'] === '/admin', 'Prepared login intent changed.');
    oauth_context_assert(google_auth_consume_state($state, $states) === null, 'Consumed challenge could be replayed.');

    $linkUrl = google_auth_authorization_url('link', '/admin/account', $states, 7);
    parse_str((string) parse_url($linkUrl, PHP_URL_QUERY), $query);
    $linkState = (string) $query['state'];
    oauth_context_assert($linkState !== $state && $states[$linkState]['user_id'] === 7 && $states[$linkState]['mode'] === 'link', 'Prepared link identity or new challenge changed.');
    $states[$linkState]['created_at'] = time() - GOOGLE_AUTH_STATE_TTL_SECONDS - 2;
    oauth_context_assert(google_auth_consume_state($linkState, $states) === null && !isset($states[$linkState]), 'Expired challenge was not consumed.');
    oauth_context_assert(google_auth_consume_state('', $states) === null, 'Empty challenge was accepted.');
    oauth_context_assert($_SESSION === ['sentinel' => 'controller-owned'], 'OAuth policy touched session transport.');
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/services/google_auth.php');
    oauth_context_assert(!str_contains($source, '$_SESSION'), 'OAuth domain module still extracts session transport.');
    echo "Google OAuth caller-context checks passed.\n";
}
