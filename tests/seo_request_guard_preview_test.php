<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/seo_request_guard_preview_test.php
 * Module Type: Regression Test
 * Purpose: Preserve visual-preview context through public SEO canonicalization.
 * Responsibilities:
 *   - Verify the protected preview marker is accepted on public routes.
 *   - Verify the exact anonymous fallback notice survives a canonical homepage redirect only with valid preview markers.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return the subdirectory application URL used to prove canonical path preservation.
     * @return string Absolute fixture base URL ending at the application directory.
     */
    function public_base_url(): string
    {
        return 'https://example.test/gallery';
    }
}

namespace Gallery\Services {
    /** Enable request policy while preserving each caller's documented default.
     * @param string $key Requested fixture setting.
     * @param string $default Policy fallback supplied by production code.
     * @return string The supplied policy fallback value.
     */
    function app_setting(string $key, string $default = ''): string
    {
        return $default;
    }
}

namespace {
    require dirname(__DIR__) . '/app/services/seo_request_guard.php';

    $query = ['page' => 'home', 'preview' => 'visual', 'view_as' => 'anonymous', 'visual_notice' => 'anonymous_fallback'];
    $unexpected = \Gallery\Services\seo_request_guard_unexpected_query_parameters('home', $query);
    if ($unexpected !== []) {
        throw new RuntimeException('The exact anonymous fallback notice must be accepted only with both preview markers.');
    }
    $markedDecision = \Gallery\Services\seo_request_guard_enforcement_decision('home', 'GET', $query, true);
    if (($markedDecision['action'] ?? '') !== 'allow') {
        throw new RuntimeException('A valid visual fallback notice must not be canonicalized away.');
    }

    $unsafeQuery = $query + ['unrelated' => 'discard'];
    $unsafeDecision = \Gallery\Services\seo_request_guard_enforcement_decision('home', 'GET', $unsafeQuery, true);
    if (($unsafeDecision['action'] ?? '') !== 'redirect' || !is_string($unsafeDecision['location'] ?? null)) {
        throw new RuntimeException('Unrelated homepage parameters must retain the existing canonical redirect behavior.');
    }
    $redirect = $unsafeDecision['location'];
    $parts = parse_url($redirect);
    $redirectQuery = [];
    parse_str((string) ($parts['query'] ?? ''), $redirectQuery);
    if (($parts['path'] ?? '') !== '/gallery/' || ($redirectQuery['preview'] ?? '') !== 'visual'
        || ($redirectQuery['view_as'] ?? '') !== 'anonymous'
        || ($redirectQuery['visual_notice'] ?? '') !== 'anonymous_fallback' || isset($redirectQuery['unrelated'])) {
        throw new RuntimeException('A canonical homepage redirect must retain the exact fallback notice and preview context without unrelated keys.');
    }

    $invalidNoticeQueries = [
        array_replace($query, ['unrelated' => 'discard', 'visual_notice' => 'other']),
        array_replace($query, ['visual_notice' => 'other']),
        array_replace($query, ['view_as' => 'administrator']),
        array_replace($query, ['preview' => 'other']),
        array_replace($query, ['visual_notice' => ['anonymous_fallback']]),
    ];
    foreach ($invalidNoticeQueries as $invalidNoticeQuery) {
        $unexpected = \Gallery\Services\seo_request_guard_unexpected_query_parameters('home', $invalidNoticeQuery);
        if (!in_array('visual_notice', $unexpected, true)) {
            throw new RuntimeException('Unknown, nonscalar, or mismatched fallback notice values must remain unexpected Home parameters.');
        }
    }
    $invalidNoticeDecision = \Gallery\Services\seo_request_guard_enforcement_decision(
        'home', 'GET', array_replace($query, ['visual_notice' => 'other']), true
    );
    if (($invalidNoticeDecision['action'] ?? '') !== 'redirect' || !is_string($invalidNoticeDecision['location'] ?? null)) {
        throw new RuntimeException('An invalid fallback notice must use the existing canonical cleanup redirect.');
    }
    $invalidNoticeParts = parse_url($invalidNoticeDecision['location']);
    $invalidNoticeRedirectQuery = [];
    parse_str((string) (is_array($invalidNoticeParts) ? ($invalidNoticeParts['query'] ?? '') : ''), $invalidNoticeRedirectQuery);
    if (isset($invalidNoticeRedirectQuery['visual_notice'])) {
        throw new RuntimeException('Canonical Home cleanup must strip an invalid fallback notice.');
    }

    echo "SEO preview redirect context: accepted markers and subdirectory target passed.\n";
}
