<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/updater_safety_model_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Verifies that application updates validate complete snapshots and never classify
 *   legitimate app modules as misplaced project copies.
 *
 * Responsibilities:
 *   - Protect app/views.php and other current top-level app entries from cleanup
 *   - Require critical runtime files before an update can touch the installation
 *   - Preserve narrowly targeted cleanup for an actual project copy nested in app
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

require_once __DIR__ . '/../app/services/updates.php';

$updatesController = (string) file_get_contents(__DIR__ . '/../app/controllers/updates.php');
if (!str_contains($updatesController, 'value="cleanup_malformed_root_files"')) {
    throw new RuntimeException('Advanced updater tools no longer expose malformed root-file cleanup.');
}

use function Gallery\Services\application_update_assert_source_root;
use function Gallery\Services\application_update_misplaced_project_paths;
use function Gallery\Services\application_update_remove_malformed_root_files;

$root = dirname(__DIR__);
application_update_assert_source_root($root);

$misplaced = application_update_misplaced_project_paths($root);
foreach ([
    'app/views.php',
    'app/views',
    'app/lang',
    'app/migration_definitions.php',
    'app/migration_repairs.php',
] as $validPath) {
    if (in_array($validPath, $misplaced, true)) {
        throw new RuntimeException('Updater classified a valid application path as misplaced: ' . $validPath);
    }
}

foreach (['app/app', 'app/public', 'app/index.php'] as $knownWrongPath) {
    if (!in_array($knownWrongPath, $misplaced, true)) {
        throw new RuntimeException('Updater no longer recognizes a known nested project artifact: ' . $knownWrongPath);
    }
}

$tempRoot = sys_get_temp_dir() . '/php-gallery-incomplete-update-' . bin2hex(random_bytes(6));
mkdir($tempRoot, 0775, true);
try {
    foreach ([
        'index.php',
        'public/index.php',
        'app/bootstrap.php',
        'app/bootstrap/configuration.php',
        'app/bootstrap/dispatch.php',
        'app/bootstrap/maintenance.php',
        'app/bootstrap/request.php',
        'app/bootstrap/routing.php',
        'app/bootstrap/session.php',
        'app/controllers.php',
        'app/controllers/admin_galleries_edit.php',
        'app/controllers/admin_galleries_edit_actions.php',
        'app/controllers/admin_galleries_edit_metadata.php',
        'app/controllers/admin_galleries_edit_page.php',
        'app/controllers/admin_galleries_edit_views.php',
        'app/controllers/admin_theme.php',
        'app/controllers/admin_theme_actions.php',
        'app/controllers/admin_theme_appearance.php',
        'app/controllers/admin_theme_custom_css.php',
        'app/controllers/admin_theme_language.php',
        'app/controllers/admin_theme_layout.php',
        'app/controllers/admin_theme_media.php',
        'app/controllers/admin_theme_page.php',
        'app/controllers/public_gallery.php',
        'app/controllers/public_gallery_cards.php',
        'app/controllers/public_gallery_controls.php',
        'app/controllers/public_gallery_home.php',
        'app/controllers/public_gallery_lightbox.php',
        'app/controllers/public_gallery_page.php',
        'app/database.php',
        'app/helpers.php',
        'app/helpers_admin_rendering.php',
        'app/helpers_files.php',
        'app/helpers_page_rendering.php',
        'app/helpers_public_urls.php',
        'app/helpers_request.php',
        'app/helpers_runtime.php',
        'app/integrity.php',
        'app/migrations.php',
        'app/security.php',
        'app/services.php',
        'app/services/updates.php',
        'app/services/updates_filesystem.php',
        'app/services/updates_install.php',
        'app/services/updates_patch_notes.php',
        'app/services/updates_remote.php',
        'app/services/updates_status.php',
        'app/views/layout.php',
        'app/lang/en.php',
        'public/assets/styles.css',
    ] as $path) {
        $absolute = $tempRoot . '/' . $path;
        $directory = dirname($absolute);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($absolute, "<?php\n");
    }

    $failedAsExpected = false;
    try {
        application_update_assert_source_root($tempRoot);
    } catch (RuntimeException $exception) {
        $failedAsExpected = str_contains($exception->getMessage(), 'app/views.php');
    }
    if (!$failedAsExpected) {
        throw new RuntimeException('Incomplete update snapshot was not rejected for missing app/views.php.');
    }
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($tempRoot);
}

if (class_exists(ZipArchive::class) && DIRECTORY_SEPARATOR !== '\\') {
    $cleanupRoot = sys_get_temp_dir() . '/php-gallery-malformed-root-cleanup-' . bin2hex(random_bytes(6));
    mkdir($cleanupRoot . '/app/services', 0775, true);
    mkdir($cleanupRoot . '/public/assets', 0775, true);
    mkdir($cleanupRoot . '/cache', 0775, true);
    foreach (['index.php', 'app/bootstrap.php', 'app/services/updates.php', 'public/assets/styles.css'] as $requiredPath) {
        file_put_contents($cleanupRoot . '/' . $requiredPath, "<?php\n");
    }
    $malformedName = 'app\\controllers\\admin_auth.php';
    file_put_contents($cleanupRoot . '/' . $malformedName, "<?php\n");
    file_put_contents($cleanupRoot . '/bootstrap.php', "<?php\n");
    file_put_contents($cleanupRoot . '/google-site-verification.html', "verification\n");
    file_put_contents($cleanupRoot . '/custom-tracker.php', "<?php\n");
    mkdir($cleanupRoot . '/nested', 0775, true);
    file_put_contents($cleanupRoot . '/nested/keep\\file.php', "<?php\n");
    mkdir($cleanupRoot . '/keep\\directory', 0775, true);

    $backupPath = $cleanupRoot . '/cache/malformed-cleanup.zip';
    $backup = new ZipArchive();
    if ($backup->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create malformed-root cleanup test archive.');
    }
    try {
        $removed = application_update_remove_malformed_root_files($cleanupRoot, $backup);
    } finally {
        $backup->close();
    }

    sort($removed);
    $expectedRemoved = [$malformedName, 'bootstrap.php'];
    sort($expectedRemoved);
    if ($removed !== $expectedRemoved || file_exists($cleanupRoot . '/' . $malformedName) || file_exists($cleanupRoot . '/bootstrap.php')) {
        throw new RuntimeException('Updater did not remove the malformed root file safely.');
    }
    if (!is_file($cleanupRoot . '/nested/keep\\file.php') || !is_dir($cleanupRoot . '/keep\\directory') || !is_file($cleanupRoot . '/google-site-verification.html') || !is_file($cleanupRoot . '/custom-tracker.php')) {
        throw new RuntimeException('Malformed-root cleanup escaped its direct regular-file scope.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cleanupRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($cleanupRoot);
}

echo "Updater safety model tests passed.\n";
