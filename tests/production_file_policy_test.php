<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/production_file_policy_test.php
 * Module Type: Policy Regression Test
 * Purpose: Verify the checked-in production inventory and exact package tree checks fail closed.
 * Responsibilities: Exercise safe path validation, integrity membership, and missing/unexpected package detection.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/release_file_policy.php';

use function Gallery\Core\release_file_policy_is_integrity_path;
use function Gallery\Core\release_file_policy_is_safe_relative_path;
use function Gallery\Core\release_file_policy_paths;
use function Gallery\Core\release_file_policy_validate_path_list;
use function Gallery\Core\release_file_policy_verify_tree;
use function Gallery\Core\release_file_policy_archive_updater_paths;
use function Gallery\Core\release_file_policy_prior_updater_paths;
use function Gallery\Core\release_file_policy_read;
use function Gallery\Core\release_file_policy_rollback_snapshot_paths;

/**
 * Assert a policy condition and throw a readable failure on mismatch.
 *
 * @param bool $condition Condition that must be true.
 * @param string $message Failure message for the test runner.
 * @return void No value is returned when the assertion succeeds.
 */
function production_file_policy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Require a malformed or unsafe fixture operation to fail before activation.
 *
 * @param callable():void $operation Fixture operation expected to throw.
 * @param string $message Explanation if the operation incorrectly succeeds.
 * @return void Throws when the unsafe operation was accepted.
 */
function production_file_policy_expect_refusal(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

/**
 * Create a directory and its parents when absent.
 *
 * @param string $path Absolute directory path.
 * @return void No value is returned when the directory exists.
 */
function production_file_policy_mkdir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create policy fixture directory.');
    }
}

/**
 * Remove a temporary fixture tree without following links.
 *
 * @param string $path Absolute fixture path.
 * @return void No value is returned after cleanup.
 */
function production_file_policy_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            production_file_policy_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    rmdir($path);
}

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-gallery-production-policy-' . bin2hex(random_bytes(5));
$source = $fixture . DIRECTORY_SEPARATOR . 'source';
$stage = $fixture . DIRECTORY_SEPARATOR . 'stage';
try {
    $production = [
        'app/.htaccess',
        'app/example.php',
        'app/production-files.json',
        'docs/manual.pdf',
        'public/assets/styles.css',
        'tests/.htaccess',
        'winapp/SimConnect.dll',
        'winapp/assets/tray-icon.ico',
        'winapp/gallery_watch_upload.pyw',
        'winapp/uploader/media.py',
    ];
    $updater = ['app/.htaccess', 'app/example.php', 'app/production-files.json', 'public/assets/styles.css', 'tests/.htaccess'];
    $sourceReview = ['tests/policy_fixture.php'];
    foreach (array_merge($production, $sourceReview) as $relative) {
        production_file_policy_mkdir(dirname($source . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)));
        file_put_contents($source . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), "fixture\n");
    }
    production_file_policy_mkdir($source . DIRECTORY_SEPARATOR . 'app');
    file_put_contents($source . DIRECTORY_SEPARATOR . 'app/production-files.json', json_encode([
        'schema_version' => 1,
        'production_files' => $production,
        'updater_files' => $updater,
        'source_review_files' => $sourceReview,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    production_file_policy_assert(release_file_policy_paths($source) === $production, 'Production profile did not return the canonical inventory.');
    production_file_policy_assert(release_file_policy_is_integrity_path('app/example.json'), 'Integrity selector rejected an application JSON file.');
    production_file_policy_assert(!release_file_policy_is_integrity_path('docs/manual.pdf'), 'Integrity selector accepted a distribution PDF.');
    production_file_policy_assert(!release_file_policy_is_integrity_path('custom_css/preset.css'), 'Integrity selector accepted a user CSS preset.');
    production_file_policy_assert(!\Gallery\Core\release_file_policy_is_updater_path('winapp/uploader/media.py'), 'CMS updater claimed independently owned WinApp sources.');
    production_file_policy_assert(!release_file_policy_is_safe_relative_path('app/CON.txt'), 'Windows device path was accepted.');
    production_file_policy_assert(!release_file_policy_is_safe_relative_path('app/unsafe. '), 'Trailing-dot/space alias was accepted.');

    $unicodeCollisionRejected = false;
    try {
        release_file_policy_validate_path_list(['app/Ä.php', 'app/ä.php']);
    } catch (RuntimeException) {
        $unicodeCollisionRejected = true;
    }
    production_file_policy_assert($unicodeCollisionRejected, 'Unicode Windows case aliases were accepted.');

    // A source archive may contain development files, but its activation set is exact.
    production_file_policy_mkdir($source . '/.agent-local');
    file_put_contents($source . '/.agent-local/private.txt', "private fixture\n");
    file_put_contents($source . '/TEMP_local-plan.md', "local fixture\n");
    file_put_contents($source . '/tests/source-only.php', "source fixture\n");
    $archive = release_file_policy_archive_updater_paths($source);
    production_file_policy_assert($archive === ['files' => $updater, 'legacy' => false],
        'Source-only archive entries changed the exact activation set.');
    file_put_contents($source . '/app/unlisted.php', "unexpected fixture\n");
    production_file_policy_expect_refusal(static function () use ($source): void {
        release_file_policy_archive_updater_paths($source);
    }, 'An unlisted application file was admitted from a current archive.');
    production_file_policy_assert(!in_array('app/unlisted.php', release_file_policy_prior_updater_paths($source), true),
        'An unknown installation file became obsolete-deletion ownership.');
    unlink($source . '/app/unlisted.php');

    $inventoryPath = $source . '/app/production-files.json';
    $inventoryBytes = file_get_contents($inventoryPath);
    production_file_policy_assert(is_string($inventoryBytes), 'Fixture inventory bytes are unavailable.');
    foreach (['config.php', 'public/assets/custom.css', 'cache/private.txt', 'data/private.txt', 'app/_for_codex/private.txt',
        'winapp/dist/0.3.2/Setup.exe', 'winapp/build/generated.py', 'winapp/settings.json',
        'winapp/tests/local_test.py', 'winapp/http_monitor_logs/private.txt'] as $protected) {
        $tampered = json_decode($inventoryBytes, true, 512, JSON_THROW_ON_ERROR);
        $tampered['production_files'][] = $protected;
        $tampered['updater_files'][] = $protected;
        sort($tampered['production_files'], SORT_STRING);
        sort($tampered['updater_files'], SORT_STRING);
        file_put_contents($inventoryPath, json_encode($tampered, JSON_THROW_ON_ERROR));
        production_file_policy_expect_refusal(static function () use ($source): void {
            release_file_policy_read($source);
        }, 'A supplied inventory could claim protected installation state: ' . $protected);
    }
    file_put_contents($inventoryPath, '{malformed');
    file_put_contents($source . '/app/core-manifest.json', '{"files":{}}');
    production_file_policy_expect_refusal(static function () use ($source): void {
        release_file_policy_archive_updater_paths($source);
    }, 'A malformed present sidecar silently fell back to legacy membership.');
    production_file_policy_assert(release_file_policy_prior_updater_paths($source) === [],
        'An invalid prior sidecar invented installation ownership.');
    file_put_contents($inventoryPath, $inventoryBytes);
    unlink($source . '/app/core-manifest.json');

    // Old stable archives still carry a positive core-manifest, including its own file.
    $legacy = $fixture . '/legacy';
    production_file_policy_mkdir($legacy . '/app');
    file_put_contents($legacy . '/app/old.php', "legacy fixture\n");
    file_put_contents($legacy . '/app/not-owned.php', "unowned fixture\n");
    file_put_contents($legacy . '/app/core-manifest.json', json_encode([
        'files' => ['app/old.php' => hash('sha256', "legacy fixture\n")],
    ], JSON_THROW_ON_ERROR));
    production_file_policy_assert(release_file_policy_archive_updater_paths($legacy) === [
        'files' => ['app/core-manifest.json', 'app/old.php'], 'legacy' => true,
    ], 'Legacy archive activation did not stay within positive manifest ownership.');
    production_file_policy_assert(!in_array('app/not-owned.php', release_file_policy_prior_updater_paths($legacy), true),
        'Legacy obsolete ownership admitted an unknown application file.');

    // A server-written clean-reinstall backup may own an otherwise unowned local file.
    $snapshot = $fixture . '/rollback';
    production_file_policy_mkdir($snapshot . '/app');
    file_put_contents($snapshot . '/app/current.php', "previous application\n");
    file_put_contents($snapshot . '/notes.txt', "previous local file\n");
    $rollbackIndex = ['app/current.php', 'notes.txt'];
    production_file_policy_assert(release_file_policy_rollback_snapshot_paths($snapshot, $rollbackIndex) === $rollbackIndex,
        'A trusted partial clean-reinstall backup could not restore its removed local file.');
    file_put_contents($snapshot . '/rogue.txt', "unindexed\n");
    production_file_policy_expect_refusal(static function () use ($snapshot, $rollbackIndex): void {
        release_file_policy_rollback_snapshot_paths($snapshot, $rollbackIndex);
    }, 'Rollback admitted a file absent from the trusted server-written backup index.');
    unlink($snapshot . '/rogue.txt');
    production_file_policy_mkdir($snapshot . '/removed-directory/nested');
    file_put_contents($snapshot . '/removed-directory/nested/notes.txt', "indexed directory backup\n");
    $rollbackIndex[] = 'removed-directory';
    production_file_policy_assert(release_file_policy_rollback_snapshot_paths($snapshot, $rollbackIndex) ===
        ['app/current.php', 'notes.txt', 'removed-directory/nested/notes.txt'],
        'A trusted removed-directory checkpoint could not restore its backed-up descendants.');
    foreach (['config.php', 'CONFIG.php', 'app/bootstrap/config.php', 'APP/BOOTSTRAP/CONFIG.php',
        'data/private.json', 'DATA/private.json', 'cache/private.txt', 'galleries/photo.jpg',
        'logs/private.log', 'tmp/private.txt', 'public/assets/custom.css'] as $protected) {
        production_file_policy_expect_refusal(static function () use ($snapshot, $rollbackIndex, $protected): void {
            release_file_policy_rollback_snapshot_paths($snapshot, array_merge($rollbackIndex, [$protected]));
        }, 'Rollback metadata could claim protected installation state: ' . $protected);
    }

    foreach ($production as $relative) {
        $destination = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        production_file_policy_mkdir(dirname($destination));
        copy($source . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $destination);
    }
    $valid = release_file_policy_verify_tree($stage, $source);
    production_file_policy_assert($valid['unexpected'] === [] && $valid['missing'] === [], 'Exact staged package was rejected.');

    file_put_contents($stage . DIRECTORY_SEPARATOR . 'unexpected.txt', "unexpected\n");
    $extra = release_file_policy_verify_tree($stage, $source);
    production_file_policy_assert($extra['unexpected'] === ['unexpected.txt'], 'Unexpected staged package file was not detected.');
    unlink($stage . DIRECTORY_SEPARATOR . 'unexpected.txt');
    unlink($stage . DIRECTORY_SEPARATOR . 'docs/manual.pdf');
    $missing = release_file_policy_verify_tree($stage, $source);
    production_file_policy_assert($missing['missing'] === ['docs/manual.pdf'], 'Missing staged production file was not detected.');

    echo "Production file policy checks passed.\n";
} finally {
    production_file_policy_remove_tree($fixture);
}
