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

use function Gallery\Services\application_update_assert_source_root;
use function Gallery\Services\application_update_misplaced_project_paths;

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
        'app/controllers.php',
        'app/database.php',
        'app/helpers.php',
        'app/integrity.php',
        'app/migrations.php',
        'app/security.php',
        'app/services.php',
        'app/services/updates.php',
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

echo "Updater safety model tests passed.\n";
