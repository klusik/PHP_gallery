<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_settings_entry_runtime_test.php
 * Module Type: Regression Test
 * Purpose: Execute real Settings ownership and PDF entry construction under strict diagnostics.
 * Responsibilities: Catch undefined variables, invalid owner metadata and DPI validation regressions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Build an isolated specialized Settings link without installation state.
     * @param string $route Registered destination route.
     * @param array<string,string> $params Destination query fields.
     * @return string Relative fixture URL.
     */
    function url_for(string $route, array $params = []): string
    {
        return '/index.php?' . http_build_query(['page' => $route] + $params);
    }
}

namespace Gallery\Services {
    /**
     * Return defaults without reading or writing the installation database.
     * @param string $key Requested persisted setting key.
     * @param string|null $default Value for absent fixture storage.
     * @return string|null Supplied default because the fixture has no persisted settings.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $default;
    }

    /**
     * Supply the domain validator's fallback text without loading translation state.
     * @param string $key Translation identifier.
     * @param string $fallback English validation message.
     * @return string Supplied English validation message.
     */
    function t(string $key, string $fallback): string
    {
        return $fallback;
    }
}

namespace {
    require_once __DIR__ . '/../app/services/admin_settings_registry.php';
    require_once __DIR__ . '/../app/services/gallery_pdf_settings.php';

    // Warnings must fail even when CI disables display_errors; restore the caller's handler.
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }, E_ALL);
    try {
        $source = (string) file_get_contents(__DIR__ . '/../app/services/admin_settings_registry.php');
        preg_match_all("/admin_settings_entry\('([^']+)'/", $source, $matches);
        if (count($matches[1]) < 30) {
            throw new \RuntimeException('Registry owner coverage unexpectedly lost its declared entries.');
        }
        foreach (array_unique($matches[1]) as $id) {
            $owner = \Gallery\Services\admin_settings_owner_for_id($id);
            if ($owner === '' || is_numeric($owner)) {
                throw new \RuntimeException('Invalid Settings owner for ' . $id);
            }
        }
        $entry = \Gallery\Services\admin_settings_entry('gallery_pdf_render_dpi', 'media', 'PDF DPI', 'PDF page resolution', 'gallery_pdf_render_dpi', 'select', '150', '150', 'admin_upload_settings', [], '', true);
        if ($entry['owner'] !== 'gallery_pdf_settings' || ($entry['current_resolver'])() !== '150' || ($entry['default_resolver'])() !== '150') {
            throw new \RuntimeException('PDF Settings entry lost its domain owner or resolvers.');
        }
        foreach (['96', '150', '200', '300', '600', '1200', '2400'] as $dpi) {
            if (\Gallery\Services\admin_settings_normalize_editable_value($entry, $dpi) !== $dpi) {
                throw new \RuntimeException('Supported PDF DPI failed normalization: ' . $dpi);
            }
        }
        foreach (['', '151', '0', '2401', 'invalid'] as $dpi) {
            try {
                \Gallery\Services\admin_settings_normalize_editable_value($entry, $dpi);
            } catch (\InvalidArgumentException) {
                continue;
            }
            throw new \RuntimeException('Invalid PDF DPI accepted before persistence: ' . $dpi);
        }
    } finally {
        restore_error_handler();
    }
    echo "Real Settings owners and PDF entry runtime tests passed.\n";
}
