<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_visit_render_fixture.php
 * Module Type: Browser Fixture Support
 * Purpose: Render the real public bell without installation data or sessions.
 * Responsibilities: Supply escaped strings to the production presentation owner.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape trusted fixture labels through PHP's normal HTML encoder.
     * @param string $value Prepared fixture text or attribute.
     * @return string HTML-safe UTF-8 text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
namespace Gallery\Services {
    /**
     * Use production fallback labels without reading an installation catalog.
     * @param string $key Translation key requested by the real renderer.
     * @param string|null $fallback Prepared English presentation fallback.
     * @param array<string,int|float|string> $parameters Unused replacement values.
     * @return string Explicit fallback or stable key when no fallback exists.
     */
    function t(string $key, ?string $fallback = null, array $parameters = []): string
    {
        return $fallback ?? $key;
    }
}
namespace {
    require_once dirname(__DIR__, 2) . '/app/views/layout.php';
    \Gallery\Views\view_render_gallery_visit_notifications();
}
