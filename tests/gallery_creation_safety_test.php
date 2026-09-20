<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Exercise filesystem observation refusals without live media or a database.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_creation_safety_test.php
 * Module Type: Regression Test
 * Purpose: Exercise catalog-preserving creation admission without a database or live media.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models {
    /**
     * Return controlled catalog ownership without executing SQL.
     *
     * @param string $folderPath Requested fixture path.
     * @return int Fixture catalog identifier or zero.
     */
    function gallery_model_catalog_path_owner(string $folderPath): int
    {
        return (int) ($GLOBALS['creationSafetyOwner'] ?? 0);
    }
}

namespace Gallery\Services {
    /**
     * Resolve the test-owned root, never the site's configured gallery storage.
     *
     * @return string Private temporary fixture directory.
     */
    function galleries_root(): string
    {
        return $GLOBALS['creationSafetyRoot'];
    }

    /**
     * Limit creation targets to the named fixture children.
     *
     * @param string $relativePath Single fixture child name.
     * @return string Absolute temporary child path.
     */
    function gallery_target_abs_path(string $relativePath): string
    {
        if (!in_array($relativePath, ['missing', 'present', 'not-directory'], true)) {
            throw new \RuntimeException('Invalid fixture target.');
        }
        return galleries_root() . DIRECTORY_SEPARATOR . $relativePath;
    }

    /**
     * Simulate path identity refusal independently of readable directory contents.
     *
     * @param string $path Temporary path being observed.
     * @return bool Whether the fixture allows the trusted-root identity.
     */
    function gallery_filesystem_path_inside_root(string $path): bool
    {
        return empty($GLOBALS['creationSafetyUnknown']);
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/gallery_creation_safety.php';

    use Gallery\Services\GalleryCatalogConflict;
    use function Gallery\Services\gallery_creation_assert_catalog_preserved;
    use function Gallery\Services\gallery_creation_target_observation;
    use const Gallery\Core\FILESYSTEM_OBSERVATION_AVAILABLE;
    use const Gallery\Core\FILESYSTEM_OBSERVATION_MISSING;
    use const Gallery\Core\FILESYSTEM_OBSERVATION_UNKNOWN;

    /**
     * Fail this isolated regression with a safe assertion message.
     *
     * @param bool $condition Required test postcondition.
     * @param string $message Bounded failure description without paths or credentials.
     * @return void
     */
    function creationSafetyCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $GLOBALS['creationSafetyRoot'] = sys_get_temp_dir() . '/gallery-creation-safety-' . bin2hex(random_bytes(12));
    $root = $GLOBALS['creationSafetyRoot'];
    creationSafetyCheck(mkdir($root, 0700), 'Could not create private test fixture.');
    try {
        creationSafetyCheck(mkdir($root . '/present', 0700), 'Could not create fixture child.');
        creationSafetyCheck(file_put_contents($root . '/not-directory', 'fixture') !== false, 'Could not create fixture file.');
        creationSafetyCheck(gallery_creation_target_observation('missing') === FILESYSTEM_OBSERVATION_MISSING, 'Readable absent entry not recognized.');
        creationSafetyCheck(gallery_creation_target_observation('present') === FILESYSTEM_OBSERVATION_AVAILABLE, 'Readable present directory not recognized.');
        creationSafetyCheck(gallery_creation_target_observation('not-directory') === FILESYSTEM_OBSERVATION_UNKNOWN, 'A file must not qualify as a reusable directory.');
        $GLOBALS['creationSafetyOwner'] = 17;
        try {
            gallery_creation_assert_catalog_preserved('missing');
            throw new RuntimeException('Missing catalog path was allowed.');
        } catch (GalleryCatalogConflict $conflict) {
            creationSafetyCheck($conflict->galleryId === 17, 'Conflicting catalog identity lost.');
        }
        gallery_creation_assert_catalog_preserved('present');
        $GLOBALS['creationSafetyUnknown'] = true;
        creationSafetyCheck(gallery_creation_target_observation('missing') === FILESYSTEM_OBSERVATION_UNKNOWN, 'Failed root observation became absence.');
        $GLOBALS['creationSafetyOwner'] = 0;
        $refused = false;
        try {
            gallery_creation_assert_catalog_preserved('missing');
        } catch (RuntimeException) {
            $refused = true;
        }
        creationSafetyCheck($refused, 'Unknown storage without a catalog owner was accepted.');
        $GLOBALS['creationSafetyUnknown'] = false;
        gallery_creation_assert_catalog_preserved('missing');
        $source = file_get_contents(dirname(__DIR__) . '/app/services/gallery_sidecars.php');
        creationSafetyCheck(!str_contains($source, 'delete_missing_gallery_database_subtree_by_folder_path('), 'Create workflow regained implicit catalog deletion.');
        echo "PASS gallery creation available/missing/unknown preserves catalog ownership\n";
    } finally {
        // Remove only explicitly created leaves inside this randomly named owned fixture.
        if (is_file($root . '/not-directory')) {
            unlink($root . '/not-directory');
        }
        if (is_dir($root . '/present')) {
            rmdir($root . '/present');
        }
        rmdir($root);
    }
}
