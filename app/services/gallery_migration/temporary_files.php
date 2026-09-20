<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_migration/temporary_files.php
 * Module Type: Service Part
 * Purpose: Own temporary transfer-file lifetime for migration controllers.
 * Responsibilities:
 *   - Allocate fixed-kind transfer files and release only paths allocated by this request.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Loaded only through gallery_migration.php; immutable Core policy is loaded by its dependencies.
 */
declare(strict_types=1);
namespace Gallery\Services;

use const Gallery\Core\GALLERY_MIGRATION_TEMPORARY_PREFIXES;

/**
 * Return the private per-request set of allocated transfer paths.
 * @return array<string,true> Absolute paths owned by this request, by reference; never serialized or accepted as request input.
 */
function &gallery_migration_temporary_files(): array
{
    static $paths = [];
    return $paths;
}

/**
 * Allocate an exclusive empty file for one known migration transfer kind.
 * @param string $kind Asset, received package or outgoing source_package role.
 * @return string Private absolute path; the caller must eventually release it through this module.
 * @throws \RuntimeException When the role is unknown or temporary storage cannot allocate a file.
 */
function gallery_migration_allocate_temporary_file(string $kind): string
{
    $prefix = GALLERY_MIGRATION_TEMPORARY_PREFIXES[$kind] ?? null;
    if ($prefix === null) {
        throw new \RuntimeException('Unknown migration transfer-file role.');
    }
    $path = @tempnam(sys_get_temp_dir(), $prefix);
    if ($path === false) {
        throw new \RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
    }
    $paths = &gallery_migration_temporary_files();
    $paths[$path] = true;
    return $path;
}

/**
 * Release only a temporary transfer allocated by this request.
 * @param string $path Exact allocation result; unknown, already released and moved paths are harmless no-ops.
 * @return void Preserves historical best-effort cleanup without accepting arbitrary deletion targets.
 */
function gallery_migration_release_temporary_file(string $path): void
{
    $paths = &gallery_migration_temporary_files();
    if (!isset($paths[$path])) {
        return;
    }
    if (!is_file($path) && !is_link($path)) {
        unset($paths[$path]);
        return;
    }
    if (@unlink($path)) {
        unset($paths[$path]);
    }
}
