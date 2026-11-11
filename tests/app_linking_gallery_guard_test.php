<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_gallery_guard_test.php
 * Module Type: Regression Test
 * Purpose: Preserve strict SEO admission for anonymous bearer gallery reads.
 * Responsibilities:
 *   - Permit only each route's declared query parameter names.
 *   - Retain rejection of unexpected names at the default enabled guard.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Provide the enabled production default without persistent fixture logs.
     *
     * @param string $key Requested setting name.
     * @param string $default Existing default value.
     * @return string Enabled guard or disabled fixture-only rejection logging.
     */
    function app_setting(string $key, string $default = ''): string
    {
        return $key === 'seo_request_guard_logging_enabled' ? '0' : $default;
    }
}

namespace {
    require_once __DIR__ . '/../app/services/seo_request_guard.php';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fixture-only';
    foreach ([
        ['app_linking_galleries', ['page' => 'app_linking_galleries', 'after' => '12']],
        // The bearer-authorized complete tree is an actual versioned GET route.
        // Its controller still requires the exact canonical tree/revision shape.
        ['app_linking_galleries', ['page' => 'app_linking_galleries', 'tree' => '1']],
        ['app_linking_galleries', [
            'page' => 'app_linking_galleries', 'tree' => '1',
            'revision' => str_repeat('a', 64),
        ]],
        ['app_linking_gallery_detail', [
            'page' => 'app_linking_gallery_detail', 'gallery_id' => '12',
        ]],
    ] as [$route, $query]) {
        $allowed = \Gallery\Services\seo_request_guard_enforcement_decision(
            $route, 'GET', $query, false,
        );
        if (($allowed['action'] ?? null) !== 'allow') {
            throw new \RuntimeException('Bearer gallery query was rejected by SEO guard.');
        }
        $query['unexpected'] = 'value';
        $rejected = \Gallery\Services\seo_request_guard_enforcement_decision(
            $route, 'GET', $query, false,
        );
        if (($rejected['action'] ?? null) !== 'reject'
            || ($rejected['status'] ?? null) !== 404) {
            throw new \RuntimeException('Unexpected bearer query was not rejected.');
        }
    }
    if (\Gallery\Services\seo_request_guard_unexpected_query_parameters(
        'app_linking_galleries', ['gallery_id' => '12'],
    ) !== ['gallery_id'] || \Gallery\Services\seo_request_guard_unexpected_query_parameters(
        'app_linking_gallery_detail', ['after' => '12'],
    ) !== ['after']) {
        throw new \RuntimeException('Gallery route query names were widened.');
    }
    echo "app linking gallery guard tests passed\n";
}
