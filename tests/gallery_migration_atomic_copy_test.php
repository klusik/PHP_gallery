<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_migration_atomic_copy_test.php
 * Module Type: Regression Test
 * Purpose: Verify atomic migration asset installation and safe resumable retries.
 * Responsibilities: Cover success, idempotency, conflicts, missing source and symlink destination.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/gallery_migration.php';

/**
 * Reject a failed migration-asset copy invariant.
 *
 * @param bool $condition Required postcondition.
 * @param string $label Regression scenario.
 * @return void No result when satisfied.
 */
function migration_copy_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Require an unsafe migration copy to fail without modifying its destination.
 *
 * @param string $source Source asset path.
 * @param string $target Target asset path.
 * @param string $checksum Manifest checksum.
 * @param string $label Expected refusal class.
 * @return void No result after expected refusal.
 */
function migration_copy_refused(string $source, string $target, string $checksum, string $label): void
{
    try {
        Gallery\Services\gallery_migration_copy_if_same_or_missing($source, $target, $checksum);
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($label . ': rejected copy was accepted.');
}

$root = sys_get_temp_dir() . '/gallery-migration-copy-' . bin2hex(random_bytes(9));
$album = $root . '/album';
if (!mkdir($album, 0700, true)) {
    throw new RuntimeException('Cannot create disposable migration copy fixture.');
}
$source = $root . '/incoming.jpg';
$target = $album . '/photo.jpg';
$conflicting = $album . '/existing.jpg';
$unsafe = $album . '/symlink.jpg';
$outside = $root . '/outside.jpg';
$failed = $album . '/failed.jpg';
try {
    file_put_contents($source, 'complete verified source asset');
    file_put_contents($outside, 'outside asset remains untouched');
    $hash = hash_file('sha256', $source);
    migration_copy_assert(is_string($hash), 'Fixture checksum is not available.');

    Gallery\Services\gallery_migration_copy_if_same_or_missing($source, $target, $hash);
    migration_copy_assert(is_file($target) && file_get_contents($target) === 'complete verified source asset',
        'New target must be completely staged and installed.');
    migration_copy_assert((glob($album . '/.gallery-migration-*') ?: []) === [],
        'Successful migration must leave no temporary staging files.');

    Gallery\Services\gallery_migration_copy_if_same_or_missing($source, $target, $hash);
    migration_copy_assert(file_get_contents($target) === 'complete verified source asset',
        'Matching existing target must remain idempotent.');

    file_put_contents($conflicting, 'different previously stored data');
    migration_copy_refused($source, $conflicting, $hash, 'Conflicting existing target');
    migration_copy_assert(file_get_contents($conflicting) === 'different previously stored data',
        'Refusal must preserve the conflicting existing file.');

    migration_copy_refused($root . '/missing.jpg', $failed, $hash, 'Missing incoming source');
    migration_copy_assert(!file_exists($failed) && (glob($album . '/.gallery-migration-*') ?: []) === [],
        'Failed migration must not leave partial final or staging files.');

    migration_copy_refused($source, $failed, str_repeat('0', 64), 'Wrong manifest checksum');
    migration_copy_assert(!file_exists($failed) && (glob($album . '/.gallery-migration-*') ?: []) === [],
        'Checksum refusal must leave no partial migration target.');

    if (function_exists('symlink') && @symlink($outside, $unsafe)) {
        migration_copy_refused($source, $unsafe, hash_file('sha256', $outside), 'Existing symlink target');
        migration_copy_assert(file_get_contents($outside) === 'outside asset remains untouched',
            'Symlink refusal must not rewrite a file outside gallery storage.');
    }

    echo "Atomic migration asset copy tests passed.\n";
} finally {
    foreach ([$unsafe, $target, $conflicting, $failed, $source, $outside] as $path) {
        @unlink($path);
    }
    foreach (glob($album . '/.gallery-migration-*') ?: [] as $stage) {
        @unlink($stage);
    }
    @rmdir($album);
    @rmdir($root);
}
