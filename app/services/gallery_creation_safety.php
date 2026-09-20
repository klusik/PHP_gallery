<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Classify storage observations and refuse creation that would discard an existing catalog.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_creation_safety.php
 * Module Type: Service
 * Purpose: Preserve catalog ownership when storage observation cannot justify new creation.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Models\gallery_model_catalog_path_owner;
use const Gallery\Core\FILESYSTEM_OBSERVATION_AVAILABLE;
use const Gallery\Core\FILESYSTEM_OBSERVATION_MISSING;
use const Gallery\Core\FILESYSTEM_OBSERVATION_UNKNOWN;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * An existing catalog object requires explicit storage reconciliation.
 *
 * The identifier is safe response metadata, not authorization to delete or repair.
 */
final class GalleryCatalogConflict extends RuntimeException
{
    /**
     * Identity of the first catalog object occupying the requested subtree.
     * @var int Positive catalog identifier, not authorization to alter the object.
     */
    public readonly int $galleryId;

    /**
     * Preserve the conflicting object's identity without exposing an absolute path.
     *
     * @param int $galleryId Existing catalog identifier.
     * @return void
     */
    public function __construct(int $galleryId)
    {
        $this->galleryId = $galleryId;
        parent::__construct('An existing gallery catalog occupies this folder path. Restore its folder or reconcile it through discovery before creating here.');
    }
}

/**
 * Observe a creation target without equating a failed stat with confirmed absence.
 *
 * Missing requires successful enumeration of a readable, stable parent. Root and
 * parent identity are checked on both sides of enumeration. This is not a storage
 * health guarantee or a filesystem lock; callers still use exclusive mkdir.
 *
 * @param string $relativePath Normalized requested child path under the gallery root.
 * @return string One of the central FILESYSTEM_OBSERVATION_* states.
 */
function gallery_creation_target_observation(string $relativePath): string
{
    $handle = null;
    $handlingWarnings = false;
    try {
        $target = gallery_target_abs_path($relativePath);
        $parent = dirname($target);
        clearstatcache(true);
        $rootBefore = @stat(galleries_root());
        $parentBefore = @stat($parent);
        if ($rootBefore === false || $parentBefore === false || !is_readable($parent)) {
            return FILESYSTEM_OBSERVATION_UNKNOWN;
        }
        $handle = @opendir($parent);
        if ($handle === false) {
            return FILESYSTEM_OBSERVATION_UNKNOWN;
        }
        $found = false;
        $name = basename($target);
        // A failed directory read is unknown, not a successful end-of-directory.
        set_error_handler(
            /**
             * Convert a failed observation to unknown without exposing filesystem diagnostics.
             *
             * @param int $severity Native PHP warning severity.
             * @param string $message Native diagnostic, deliberately not returned or logged.
             * @return never
             */
            static function (int $severity, string $message): never {
                throw new RuntimeException('Directory observation failed.');
            }
        );
        $handlingWarnings = true;
        while (($entry = readdir($handle)) !== false) {
            if ($entry === $name || (PHP_OS_FAMILY === 'Windows' && strcasecmp($entry, $name) === 0)) {
                $found = true;
                break;
            }
        }
        clearstatcache(true);
        $rootAfter = @stat(galleries_root());
        $parentAfter = @stat($parent);
        if ($rootAfter === false || $parentAfter === false
            || $rootBefore['dev'] !== $rootAfter['dev'] || $rootBefore['ino'] !== $rootAfter['ino']
            || $parentBefore['dev'] !== $parentAfter['dev'] || $parentBefore['ino'] !== $parentAfter['ino']
            || !gallery_filesystem_path_inside_root($parent)) {
            return FILESYSTEM_OBSERVATION_UNKNOWN;
        }
        if (!$found) {
            return FILESYSTEM_OBSERVATION_MISSING;
        }
        return is_dir($target) && is_readable($target) && gallery_filesystem_path_inside_root($target)
            ? FILESYSTEM_OBSERVATION_AVAILABLE : FILESYSTEM_OBSERVATION_UNKNOWN;
    } catch (Throwable) {
        return FILESYSTEM_OBSERVATION_UNKNOWN;
    } finally {
        if ($handlingWarnings) {
            restore_error_handler();
        }
        if (is_resource($handle)) {
            closedir($handle);
        }
    }
}

/**
 * Refuse missing/ambiguous catalog reuse; never perform cleanup as a create side effect.
 *
 * Existing healthy galleries may still cause the normal intentional duplicate
 * suffix selection. A missing descendant-only catalog is protected as well.
 *
 * @param string $relativePath Requested gallery folder path.
 * @return void
 * @throws GalleryCatalogConflict When existing catalog ownership needs reconciliation.
 * @throws RuntimeException When storage cannot be observed safely.
 */
function gallery_creation_assert_catalog_preserved(string $relativePath): void
{
    $state = gallery_creation_target_observation($relativePath);
    try {
        $owner = gallery_model_catalog_path_owner($relativePath);
    } catch (Throwable $exception) {
        throw new RuntimeException('Gallery catalog could not be verified. No existing catalog was changed.', 0, $exception);
    }
    if ($owner > 0 && $state !== FILESYSTEM_OBSERVATION_AVAILABLE) {
        throw new GalleryCatalogConflict($owner);
    }
    if ($state === FILESYSTEM_OBSERVATION_UNKNOWN) {
        throw new RuntimeException('Gallery storage could not be verified. No existing catalog was changed; check storage availability before retrying.');
    }
}
