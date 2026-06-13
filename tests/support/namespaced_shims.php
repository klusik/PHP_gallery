<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/support/namespaced_shims.php
 * Module Type: Test Support
 *
 * Purpose:
 *   Provides deterministic namespaced helper shims for standalone model tests.
 *
 * Responsibilities:
 *   - Keep focused tests independent from the full application bootstrap
 *   - Mirror only helper behavior required by the standalone assertions
 *   - Avoid touching the real database, session, or configuration
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-06-13
 */

declare(strict_types=1);

namespace Gallery\Services {
    if (!function_exists(__NAMESPACE__ . '\\t')) {
        /**
         * Return a deterministic translation fallback for standalone model tests.
         *
         * @param string $key Lookup key.
         * @param string|array|null $fallback Fallback value.
         * @param array $parameters Replacement values keyed by placeholder name.
         * @return string Text result for the caller.
         */
        function t(string $key, string|array|null $fallback = null, array $parameters = []): string
        {
            if (is_array($fallback)) {
                $parameters = $fallback;
                $fallback = null;
            }
            $text = is_string($fallback) ? $fallback : $key;
            foreach ($parameters as $name => $value) {
                $text = str_replace('{' . $name . '}', (string) $value, $text);
            }
            return $text;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\db_column_exists')) {
        /**
         * Return whether a test schema column is available.
         *
         * @param string $table Table name.
         * @param string $column Column name.
         * @return bool True when the test schema exposes the column.
         */
        function db_column_exists(string $table, string $column): bool
        {
            return $table === 'galleries' && in_array($column, ['gallery_date', 'gallery_date_end'], true);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\gallery_is_public_listed')) {
        /**
         * Return whether a gallery row is visible to anonymous navigation.
         *
         * @param array $gallery Gallery row or gallery data.
         * @return bool True when the gallery is public and listed.
         */
        function gallery_is_public_listed(array $gallery): bool
        {
            return (string) ($gallery['visibility'] ?? 'public') === 'public'
                && (string) ($gallery['access_listing'] ?? 'listed') === 'listed';
        }
    }
}

namespace Gallery\Core {
    if (!function_exists(__NAMESPACE__ . '\\e')) {
        /**
         * Escape text for safe HTML output.
         *
         * @param ?string $value Value to process.
         * @return string Escaped text.
         */
        function e(?string $value): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\url_for')) {
        /**
         * Build a deterministic route URL for standalone navigation tests.
         *
         * @param string $route Route name.
         * @param array $params Query parameters.
         * @return string URL result for the caller.
         */
        function url_for(string $route, array $params = []): string
        {
            if ($route === 'home') {
                return '/';
            }
            return '/?page=' . rawurlencode($route);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\gallery_public_url')) {
        /**
         * Build a deterministic gallery URL for standalone navigation tests.
         *
         * @param array $gallery Gallery row or gallery data.
         * @return string URL result for the caller.
         */
        function gallery_public_url(array $gallery): string
        {
            return '/gallery/' . rawurlencode(trim((string) ($gallery['url_path'] ?? $gallery['folder_path'] ?? $gallery['slug'] ?? 'gallery'), '/')) . '/';
        }
    }
}
