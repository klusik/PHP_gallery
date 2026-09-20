<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_migration_temporary_files_test.php
 * Module Type: Regression Test
 * Purpose: Prove migration cleanup cannot delete an unallocated transfer path.
 * Responsibilities:
 *   - Check request ownership, repeat release and ZIP use of an exclusively reserved filename.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/gallery_migration.php';
use function Gallery\Services\gallery_migration_allocate_temporary_file;
use function Gallery\Services\gallery_migration_release_temporary_file;
use function Gallery\Services\gallery_migration_temporary_files;

/**
 * Require a transfer-file invariant without network, gallery or database work.
 * @param bool $condition Expected ownership or ZIP property.
 * @param string $message Safe fixture assertion label.
 * @return void
 */
function migration_temp_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$allocated = [];
$unrelated = tempnam(sys_get_temp_dir(), 'gallery-unrelated-fixture-');
migration_temp_assert(is_string($unrelated), 'Unrelated fixture could not be allocated.');
try {
    file_put_contents($unrelated, 'retain unrelated fixture');
    gallery_migration_release_temporary_file($unrelated);
    migration_temp_assert(file_get_contents($unrelated) === 'retain unrelated fixture', 'Unowned temporary file was deleted.');
    foreach (['asset', 'package', 'source_package'] as $kind) {
        $path = gallery_migration_allocate_temporary_file($kind);
        $allocated[] = $path;
        migration_temp_assert(is_file($path) && isset(gallery_migration_temporary_files()[$path]), 'Transfer file was not exclusively reserved and owned.');
        if ($kind === 'source_package') {
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException('ZIP extension required for outgoing transfer allocation coverage.');
            }
            $zip = new ZipArchive();
            migration_temp_assert($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Reserved temporary filename was not accepted as a ZIP.');
            migration_temp_assert($zip->addFromString('fixture.txt', 'bounded fixture') && $zip->close(), 'Owned ZIP could not be finalized.');
            $zip = new ZipArchive();
            migration_temp_assert($zip->open($path) === true && $zip->getFromName('fixture.txt') === 'bounded fixture', 'ZIP payload changed because the private filename has no suffix.');
            $zip->close();
        }
        gallery_migration_release_temporary_file($path);
        migration_temp_assert(!file_exists($path) && !isset(gallery_migration_temporary_files()[$path]), 'Owned transfer survived release.');
        gallery_migration_release_temporary_file($path);
    }
    $refused = false;
    try { gallery_migration_allocate_temporary_file('../untrusted'); } catch (RuntimeException) { $refused = true; }
    migration_temp_assert($refused && gallery_migration_temporary_files() === [], 'Unknown transfer role allocated storage.');
    echo "Gallery migration temporary-file checks passed.\n";
} finally {
    foreach ($allocated as $path) { gallery_migration_release_temporary_file($path); }
    if (is_file($unrelated)) { unlink($unrelated); }
}
