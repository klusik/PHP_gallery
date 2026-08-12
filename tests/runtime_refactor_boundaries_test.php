<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/runtime_refactor_boundaries_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the thin compatibility coordinators and focused runtime module wiring
 *   introduced by the large-file and bootstrap responsibility refactor.
 *
 * Responsibilities:
 *   - Keep legacy include entrypoints small and explicit
 *   - Verify extracted controller, helper, updater, and bootstrap modules are wired
 *   - Protect request lifecycle ordering inside the bootstrap orchestrator
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * Read one repository file or fail the test with a useful message.
 *
 * @param string $relativePath Repository-relative path.
 * @return string File contents.
 */
function refactor_test_read(string $relativePath): string
{
    global $root;
    $contents = file_get_contents($root . '/' . $relativePath);
    if ($contents === false) {
        throw new RuntimeException('Unable to read refactor test target: ' . $relativePath);
    }
    return $contents;
}

/**
 * Assert that one text fragment occurs in a repository file.
 *
 * @param string $relativePath Repository-relative path.
 * @param string $needle Required fragment.
 */
function refactor_test_contains(string $relativePath, string $needle): void
{
    if (!str_contains(refactor_test_read($relativePath), $needle)) {
        throw new RuntimeException($relativePath . ' is missing expected wiring: ' . $needle);
    }
}

$thinCoordinators = [
    'app/controllers/admin_galleries_edit.php' => 100,
    'app/controllers/public_gallery.php' => 100,
    'app/controllers/admin_theme.php' => 120,
    'app/services/updates.php' => 100,
    'app/helpers.php' => 100,
    'app/bootstrap.php' => 120,
];

foreach ($thinCoordinators as $relativePath => $maxLines) {
    $lineCount = substr_count(refactor_test_read($relativePath), "\n") + 1;
    if ($lineCount > $maxLines) {
        throw new RuntimeException($relativePath . ' grew beyond its coordinator boundary: ' . $lineCount . ' lines.');
    }
}

foreach ([
    'app/controllers/admin_galleries_edit.php' => [
        'admin_galleries_edit_actions.php',
        'admin_galleries_edit_metadata.php',
        'admin_galleries_edit_page.php',
        'admin_galleries_edit_views.php',
    ],
    'app/controllers/public_gallery.php' => [
        'public_gallery_home.php',
        'public_gallery_page.php',
        'public_gallery_controls.php',
        'public_gallery_cards.php',
        'public_gallery_lightbox.php',
    ],
    'app/controllers/admin_theme.php' => [
        'admin_theme_actions.php',
        'admin_theme_appearance.php',
        'admin_theme_media.php',
        'admin_theme_layout.php',
        'admin_theme_language.php',
        'admin_theme_custom_css.php',
        'admin_theme_page.php',
    ],
    'app/services/updates.php' => [
        'updates_status.php',
        'updates_patch_notes.php',
        'updates_install.php',
        'updates_remote.php',
        'updates_filesystem.php',
    ],
    'app/helpers.php' => [
        'helpers_request.php',
        'helpers_public_urls.php',
        'helpers_runtime.php',
        'helpers_admin_rendering.php',
        'helpers_page_rendering.php',
        'helpers_files.php',
    ],
] as $coordinator => $modules) {
    foreach ($modules as $module) {
        refactor_test_contains($coordinator, "require_once __DIR__ . '/" . $module . "';");
    }
}

foreach ([
    'app/bootstrap/configuration.php',
    'app/bootstrap/routing.php',
    'app/bootstrap/session.php',
    'app/bootstrap/request.php',
    'app/bootstrap/maintenance.php',
    'app/bootstrap/dispatch.php',
] as $bootstrapModule) {
    refactor_test_contains('app/bootstrap.php', "require __DIR__ . '/" . substr($bootstrapModule, 4) . "';");
}

$deploymentExcludedBasenames = [
    '.gitignore',
    'config.php',
    '.env',
];
foreach ([
    'app/bootstrap/configuration.php',
    'app/bootstrap/routing.php',
    'app/bootstrap/session.php',
    'app/bootstrap/request.php',
    'app/bootstrap/maintenance.php',
    'app/bootstrap/dispatch.php',
] as $bootstrapModule) {
    if (in_array(basename($bootstrapModule), $deploymentExcludedBasenames, true)) {
        throw new RuntimeException('Required bootstrap module uses a deployment-excluded basename: ' . $bootstrapModule);
    }
}

refactor_test_contains('scripts/generate_manifest.php', "'#^app/bootstrap/config\\.php$#'");

$bootstrap = refactor_test_read('app/bootstrap.php');
$orderedCalls = [
    'cms_start_session($config);',
    '$page = cms_initialize_request();',
    'cms_run_request_maintenance($page);',
    'cms_dispatch_page($page);',
];
$lastPosition = -1;
foreach ($orderedCalls as $call) {
    $position = strpos($bootstrap, $call);
    if ($position === false || $position <= $lastPosition) {
        throw new RuntimeException('Bootstrap request lifecycle order changed around: ' . $call);
    }
    $lastPosition = $position;
}

require_once $root . '/app/helpers.php';
require_once $root . '/app/services/updates.php';
require_once $root . '/app/controllers/public_gallery.php';
require_once $root . '/app/controllers/admin_galleries_edit.php';

foreach ([
    'Gallery\\Core\\gallery_public_url',
    'Gallery\\Core\\render_header',
    'Gallery\\Services\\check_application_update',
    'Gallery\\Services\\application_patch_notes_viewer_data',
    'Gallery\\Services\\install_application_update',
    'Gallery\\Services\\application_update_copy_files',
    'Gallery\\Controllers\\cms_home',
    'Gallery\\Controllers\\cms_gallery',
    'Gallery\\Controllers\\render_gallery_card',
    'Gallery\\Controllers\\render_lightbox',
    'Gallery\\Controllers\\cms_admin_edit_gallery',
    'Gallery\\Controllers\\admin_save_gallery_from_input',
] as $functionName) {
    if (!function_exists($functionName)) {
        throw new RuntimeException('Extracted runtime function is not available through its legacy include contract: ' . $functionName);
    }
}

echo "Runtime refactor boundary tests passed.\n";