<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/cache_registry_fixture.php
 * Module Type: Test Support
 * Purpose: Provision a disposable SQLite representation of the actual cache registry migration.
 * Responsibilities: Share real persistent model storage between isolated CLI and HTTP workers; never access installation data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/**
 * Open an owned fixture database and optionally apply the migration's portable table definition.
 * @param string $root Existing random fixture root with no live application data.
 * @param bool $initialize Whether this parent owns table creation before workers start.
 * @return PDO Independent SQLite connection; missing required PDO support or invalid migration DDL fails the fixture.
 */
function cache_registry_fixture_database(
    string $root,
    bool $initialize = true,
): PDO {
    $database = new PDO('sqlite:' . $root . '/registry.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    if ($initialize) {
        $definitions = require
            dirname(__DIR__, 2)
                . '/database/migrations/202610150001_cache_lifecycle_regions.php';
        $sql = $definitions[0];
        $sql = str_replace(' CHARACTER SET ascii COLLATE ascii_bin', '', $sql);
        $sql = preg_replace(
            '/,\\s*KEY cache_lifecycle_regions_due \\([^)]*\\)/',
            '',
            $sql,
        );
        $sql = preg_replace('/\\) ENGINE=InnoDB.*$/s', ')', $sql);
        if (!is_string($sql))
            throw new RuntimeException('Fixture migration conversion failed.');
        $database->exec($sql);
    }
    return $database;
}
