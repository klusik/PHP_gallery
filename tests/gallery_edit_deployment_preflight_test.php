<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_edit_deployment_preflight_test.php
 * Module Type: Regression Test
 * Purpose: Refuse updater activation before files change when revision storage is unavailable.
 * Responsibilities:
 *   - Exercise missing, unknown and available revision-column observations without live DDL
 *   - Prove available storage needs no trigger metadata, grant inspection or database adapter
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Observe the fixture's revision-column state without reading configuration.
     *
     * @param string $table Expected gallery table.
     * @param string $column Expected edit revision column.
     * @return array{state:string} Explicit fixture state.
     */
    function schema_inspection_column(string $table, string $column): array
    {
        if ($table !== 'galleries' || $column !== 'edit_revision') {
            throw new \RuntimeException('Unexpected schema probe.');
        }
        return ['state' => $GLOBALS['edit_preflight_state']];
    }

    /**
     * Accept only a verified column.
     *
     * @param array{state:string} $status Fixture observation.
     * @return bool Whether the column is available.
     */
    function schema_inspection_is_available(array $status): bool
    {
        return $status['state'] === 'available';
    }

    /**
     * Preserve confirmed absence separately from uncertainty.
     *
     * @param array{state:string} $status Fixture observation.
     * @return bool Whether the column is missing.
     */
    function schema_inspection_is_missing(array $status): bool
    {
        return $status['state'] === 'missing';
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/updates_install.php';

    $root = dirname(__DIR__);
    foreach (['missing', 'unknown', 'available'] as $state) {
        $GLOBALS['edit_preflight_state'] = $state;
        $refused = false;
        try {
            Gallery\Services\application_update_assert_gallery_edit_enforcement($root);
        } catch (RuntimeException $error) {
            $refused = true;
            if (preg_match('/\b(?:trigger|SUPER|privilege|grant|DBA)\b/i', $error->getMessage()) === 1) {
                throw new RuntimeException('Updater preflight exposed an obsolete privileged-database requirement.');
            }
        }
        if ($refused !== ($state !== 'available')) {
            throw new RuntimeException('Activation preflight classified revision storage incorrectly.');
        }
    }

    $GLOBALS['edit_preflight_state'] = 'unknown';
    Gallery\Services\application_update_assert_gallery_edit_enforcement($root . '/tests/fixtures');
    foreach (['app/services/updates_jobs/plan.php', 'app/services/updates_jobs/activation.php', 'app/services/updates_filesystem.php'] as $path) {
        if (!str_contains((string) file_get_contents($root . '/' . $path), 'application_update_assert_gallery_edit_enforcement(')) {
            throw new RuntimeException('An activation entry point omits the revision-storage preflight.');
        }
    }
    echo "Gallery edit deployment revision-storage preflight checks passed.\n";
}
