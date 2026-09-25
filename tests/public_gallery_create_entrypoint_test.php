<?php

/**
 * Project: PHP Gallery
 * File: tests/public_gallery_create_entrypoint_test.php
 * Module Type: Regression Test
 * Purpose: Verify the public add-gallery control opens the focused create panel.
 * Responsibilities:
 *   - Render real hero and card add-gallery links with isolated dependencies.
 * Repository: https://github.com/klusik/PHP_gallery
 * Author: Rudolf Klusal
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return an authenticated admin fixture.
     *
     * @return array<string,mixed> Authenticated admin fixture.
     */
    function current_user(): array
    {
        return ['id' => 1, 'role' => 'admin'];
    }

    /**
     * Keep anonymous preview disabled for the fixture.
     *
     * @return bool The fixture never uses anonymous preview.
     */
    function admin_anonymous_preview_active(): bool
    {
        return false;
    }

    /**
     * Build a deterministic route URL for assertions.
     *
     * @param string $route Route name to encode.
     * @param array<string,mixed> $params Query parameters to encode.
     * @return string Deterministic fixture URL.
     */
    function url_for(string $route, array $params = []): string
    {
        return '/?' . http_build_query(['route' => $route] + $params);
    }

    /**
     * Escape rendered link attributes.
     *
     * @param string $value Raw HTML value.
     * @return string Escaped fixture HTML value.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace Gallery\Services {
    /**
     * Enable only the inline administration capability.
     *
     * @param string $capability Capability being checked.
     * @return bool Whether inline administration is enabled in this fixture.
     */
    function feature_capability_effective_enabled(string $capability): bool
    {
        return $capability === 'inline_administration';
    }

    /**
     * Resolve fixture labels from their English fallbacks.
     *
     * @param string $key Translation key requested by the view.
     * @param string $fallback English fallback string.
     * @param array<string,mixed> $params Replacements for the fallback.
     * @return string Translated fixture label.
     */
    function t(string $key, string $fallback = '', array $params = []): string
    {
        return strtr($fallback, ['{title}' => (string) ($params['title'] ?? '')]);
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/views/public_gallery_cards.php';
    require_once dirname(__DIR__) . '/app/controllers/public_gallery_cards.php';

    foreach (['hero', 'card'] as $placement) {
        ob_start();
        \Gallery\Controllers\render_public_gallery_admin_add_child_link(
            ['id' => 42, 'title' => 'Parent'],
            $placement
        );
        $html = (string) ob_get_clean();

        foreach ([
            'href="/?route=admin_new_gallery&amp;parent_id=42"',
            'data-gallery-side-panel-url="/?route=admin_new_gallery&amp;parent_id=42&amp;panel=1"',
            'data-admin-side-panel-workflow="create"',
        ] as $expected) {
            if (!str_contains($html, $expected)) {
                throw new \RuntimeException($placement . ' add-gallery link is missing ' . $expected);
            }
        }
    }

    fwrite(STDOUT, "Public gallery create entrypoint checks passed.\n");
}
