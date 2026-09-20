<?php
/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Supply deterministic edit state without application configuration.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_edit_runtime.php
 * Module Type: Test Support
 * Purpose: Load concurrency code with a fixture-only database adapter, never application config.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return only a separately validated disposable connection assigned by this test.
     * @return \PDO Fixture connection; throws before SQL when no fixture was supplied.
     */
    function db(): \PDO
    {
        return $GLOBALS['gallery_edit_fixture_connection'] ?? throw new \RuntimeException('Fixture database unavailable.');
    }

    /**
     * Escape fixture view output with the production HTML escaping semantics.
     * @param string|int|float|bool|null $value Scalar fixture field rendered as text; no application configuration is read.
     * @return string Escaped UTF-8 markup text.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Return a valid fixture timestamp for migration-ledger recovery checks.
     *
     * @return string Current UTC time in the application's SQL datetime format.
     */
    function now_sql(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

namespace Gallery\Services {
    /**
     * Return the explicit English fallback without reading translation settings.
     * @param string $key Translation identity.
     * @param string|null $default Explicit English fallback; null falls back to the translation key.
     * @param array<string,mixed> $parameters Unused placeholder values.
     * @return string Stable bounded fixture message.
     */
    function t(string $key, mixed $default = null, array $parameters = []): string
    {
        return is_string($default) ? $default : $key;
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/app/models/schema_inspection.php';
    require_once dirname(__DIR__, 2) . '/app/services/schema_inspection.php';
    require_once dirname(__DIR__, 2) . '/app/services/gallery_edit_concurrency.php';
}
