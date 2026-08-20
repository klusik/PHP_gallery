<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_admin_mode_toggle_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the administrator-controlled viewer account availability toggle.
 *
 * Responsibilities:
 *   - Verify the database-backed Admin override safely supersedes config.php
 *   - Verify the historical config.php values remain the fallback when no override exists
 *   - Verify the Admin control exposes exactly disabled, invite_only, and open modes
 *   - Verify the invitation controller uses Admin authentication and CSRF for the toggle
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Return mutable test configuration. */
    function cms_config(): array
    {
        return $GLOBALS['viewer_toggle_test_config'] ?? [];
    }

    /** Database stub required only for symbol binding in the included service. */
    function db(): object
    {
        throw new \RuntimeException('Database stub must not be called by this test.');
    }

    /** SQL timestamp stub required only for symbol binding in the included service. */
    function now_sql(): string
    {
        return '2026-08-18 00:00:00';
    }
}

namespace Gallery\Services {
    /** Return one mutable in-memory app setting for the focused service test. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $GLOBALS['viewer_toggle_test_settings'] ?? [])
            ? (string) $GLOBALS['viewer_toggle_test_settings'][$key]
            : $default;
    }

    /** Persist one mutable in-memory app setting for the focused service test. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['viewer_toggle_test_settings'][$key] = $value;
    }
}

namespace {
    /** Throw when one viewer Admin toggle expectation fails. */
    function viewer_toggle_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/services/viewer_accounts.php';

    $GLOBALS['viewer_toggle_test_settings'] = [];
    $GLOBALS['viewer_toggle_test_config'] = [
        'viewer_accounts' => ['enabled' => false, 'registration_mode' => 'disabled'],
    ];
    viewer_toggle_assert(!\Gallery\Services\viewer_accounts_enabled(), 'Disabled config fallback must remain disabled without an Admin override.');
    viewer_toggle_assert(\Gallery\Services\viewer_registration_mode() === 'disabled', 'Disabled config fallback must report disabled registration mode.');

    $GLOBALS['viewer_toggle_test_config'] = [
        'viewer_accounts' => ['enabled' => true, 'registration_mode' => 'invite_only'],
    ];
    viewer_toggle_assert(\Gallery\Services\viewer_accounts_enabled(), 'Historical config.php enablement must remain a valid fallback.');
    viewer_toggle_assert(\Gallery\Services\viewer_registration_mode() === 'invite_only', 'Historical invite-only config mode must remain a valid fallback.');

    \Gallery\Services\viewer_accounts_set_admin_enabled(false);
    viewer_toggle_assert(!\Gallery\Services\viewer_accounts_enabled(), 'Admin disable override must supersede enabled config.php.');
    viewer_toggle_assert(\Gallery\Services\viewer_registration_mode() === 'disabled', 'Admin disable override must force disabled mode.');
    viewer_toggle_assert(($GLOBALS['viewer_toggle_test_settings'][\Gallery\Services\VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY] ?? null) === 'disabled', 'Admin disable must persist the single bounded mode setting.');

    \Gallery\Services\viewer_accounts_set_admin_enabled(true);
    viewer_toggle_assert(\Gallery\Services\viewer_accounts_enabled(), 'Admin enable override must enable viewer accounts.');
    viewer_toggle_assert(\Gallery\Services\viewer_registration_mode() === 'invite_only', 'Admin enable override must select invite-only mode.');
    viewer_toggle_assert(($GLOBALS['viewer_toggle_test_settings'][\Gallery\Services\VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY] ?? null) === 'invite_only', 'Historical compatibility enable must continue to persist invite_only.');
    viewer_toggle_assert(\Gallery\Services\viewer_registration_mode_normalize('open') === 'open', 'The bounded backend mode normalization must retain open for the Phase 4.1 selector.');

    $GLOBALS['viewer_toggle_test_settings'][\Gallery\Services\VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY] = 'unexpected';
    $GLOBALS['viewer_toggle_test_config'] = [
        'viewer_accounts' => ['enabled' => false, 'registration_mode' => 'disabled'],
    ];
    viewer_toggle_assert(!\Gallery\Services\viewer_accounts_enabled(), 'Malformed Admin override must fail back to the safe historical configuration.');

    $controller = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
    viewer_toggle_assert(str_contains($controller, "if (\$action === 'set_mode')"), 'Viewer invitation Admin page must process the dedicated mode action.');
    viewer_toggle_assert(str_contains($controller, 'verify_csrf();'), 'Viewer mode mutation must remain protected by Admin CSRF.');
    viewer_toggle_assert(str_contains($controller, 'require_admin();'), 'Viewer mode mutation must remain on an administrator-only page.');
    viewer_toggle_assert(str_contains($controller, "in_array(\$requestedMode, ['disabled', 'invite_only', 'open'], true)"), 'Controller must explicitly allow only the three supported registration modes.');
    viewer_toggle_assert(str_contains($controller, 'viewer_accounts_set_admin_registration_mode($requestedMode);'), 'Controller must delegate persistence to the lifecycle-aware registration-mode service.');
    foreach (['disabled', 'invite_only', 'open'] as $mode) {
        viewer_toggle_assert(str_contains($controller, '<option value="' . $mode . '"'), 'Admin selector missing mode: ' . $mode);
    }
    viewer_toggle_assert(!str_contains($controller, 'file_put_contents(') && !str_contains($controller, 'config.php\''), 'Admin viewer toggle must not rewrite config.php.');
    viewer_toggle_assert(!str_contains($controller, 'session_destroy()'), 'Viewer availability toggle must never destroy the shared Admin session.');

    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true);
        viewer_toggle_assert(is_array($catalog), 'Translation catalog failed to decode: ' . $language);
        foreach ([
            'viewer.admin.invites.mode_title',
            'viewer.admin.invites.mode_selector_label',
            'viewer.admin.invites.mode_disabled_label',
            'viewer.admin.invites.mode_invite_only_label',
            'viewer.admin.invites.mode_open_label',
            'viewer.admin.invites.mode_save_button',
            'viewer.admin.invites.mode_scope_help',
        ] as $key) {
            viewer_toggle_assert(isset($catalog[$key]) && is_string($catalog[$key]) && $catalog[$key] !== '', 'Missing viewer Admin mode translation ' . $key . ' in ' . $language . '.');
        }
    }

    echo "Viewer Admin mode toggle tests passed.\n";
}
