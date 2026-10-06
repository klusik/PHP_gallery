<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/updater_inventory_compatibility_test.php
 * Module Type: Updater Compatibility Regression Test
 * Purpose: Validate incoming release membership using the installed pre-WinApp archive reader.
 * Responsibilities: Reproduce the old rejection, prove additive companion compatibility and preserve updater scope.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/release_file_policy.php';
require_once __DIR__ . '/fixtures/updater_inventory_before_winapp.php';

use function Gallery\Core\release_file_policy_read;
use function Gallery\Core\release_file_policy_archive_updater_paths;
use function Gallery\Tests\LegacyUpdatePolicy\release_file_policy_archive_updater_paths as installed_archive_paths;
use function Gallery\Tests\LegacyUpdatePolicy\release_file_policy_paths as installed_policy_paths;
use function Gallery\Tests\LegacyUpdatePolicy\release_file_policy_read as installed_policy_read;

/**
 * Require a compatibility postcondition without depending on PHP assertion settings.
 *
 * @param bool $condition Compatibility condition that must hold.
 * @param string $message Explanation of the failed condition.
 * @return void Throws when the condition does not hold.
 */
function updater_inventory_compatibility_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Remove an owned temporary fixture without following directory links.
 *
 * @param string $path Absolute test-owned directory or file path.
 * @return void Removes the fixture after the test completes.
 */
function updater_inventory_compatibility_cleanup(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                updater_inventory_compatibility_cleanup($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
    }
}

$root = dirname(__DIR__);
$inventory = release_file_policy_read($root);
updater_inventory_compatibility_assert($inventory['companion_files'] !== [], 'The release lost its reviewed companion membership.');
updater_inventory_compatibility_assert(installed_policy_read($root)['updater_files'] === $inventory['updater_files'],
    'The installed reader refused the real incoming release inventory or changed its activation set.');
updater_inventory_compatibility_assert(installed_policy_paths($root, 'updater') === $inventory['updater_files'],
    'The incoming release is missing a path required by the installed updater.');

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-gallery-old-updater-' . bin2hex(random_bytes(5));
try {
    $production = ['app/production-files.json', 'app/runtime.php', 'winapp/.htaccess'];
    $companion = ['winapp/uploader/media.py'];
    $payload = [
        'schema_version' => 1,
        'production_files' => $production,
        'companion_files' => $companion,
        'updater_files' => $production,
        'source_review_files' => [],
    ];
    foreach (array_merge($production, $companion) as $path) {
        $absolute = $fixture . '/' . $path;
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0777, true)) {
            throw new RuntimeException('Could not create an extracted-source compatibility fixture.');
        }
        file_put_contents($absolute, "fixture\n");
    }
    $sidecar = $fixture . '/app/production-files.json';
    // Preserve the exact failed shape as a control, before testing the compatible shape.
    $broken = $payload;
    $broken['production_files'] = array_merge($production, $companion);
    sort($broken['production_files'], SORT_STRING);
    unset($broken['companion_files']);
    file_put_contents($sidecar, json_encode($broken, JSON_THROW_ON_ERROR));
    $reference = null;
    try {
        installed_archive_paths($fixture);
    } catch (RuntimeException $exception) {
        $reference = strtoupper(substr(hash('sha256', $exception->getMessage()), 0, 12));
    }
    updater_inventory_compatibility_assert($reference === '385BCD5058D6',
        'The frozen installed validator did not reproduce the reported package_validate failure.');

    file_put_contents($sidecar, json_encode($payload, JSON_THROW_ON_ERROR));
    $expected = ['files' => $production, 'legacy' => false];
    updater_inventory_compatibility_assert(installed_archive_paths($fixture) === $expected,
        'The installed updater refused the additive inventory or activated companion sources.');
    updater_inventory_compatibility_assert(release_file_policy_archive_updater_paths($fixture) === $expected,
        'Current and installed updater activation scopes disagree.');

    file_put_contents($fixture . '/app/unlisted.php', "unlisted\n");
    $refused = false;
    try {
        installed_archive_paths($fixture);
    } catch (RuntimeException $exception) {
        $refused = $exception->getMessage() === 'Update archive contains unexpected updater-owned files.';
    }
    updater_inventory_compatibility_assert($refused, 'Compatibility weakened the installed updater archive allowlist.');

    echo "Installed updater inventory compatibility checks passed.\n";
} finally {
    updater_inventory_compatibility_cleanup($fixture);
}
