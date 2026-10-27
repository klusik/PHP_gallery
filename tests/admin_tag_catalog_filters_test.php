<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_tag_catalog_filters_test.php
 * Module Type: Disposable Database Regression Test
 * Purpose: Verify assignment-scoped Admin filters over shared tag identities.
 * Responsibilities: Exercise real model/service queries, scoped counts/order,
 *   reserved PDF refusal and legacy/unknown schema behavior in memory only.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply the explicitly owned in-memory fixture connection.
     * @return \PDO Disposable SQLite connection; never the installation database.
     */
    function db(): \PDO
    {
        $GLOBALS['tag_filter_db_reads']++;
        return $GLOBALS['tag_filter_db'];
    }
}

namespace Gallery\Services {
    /**
     * Return fixture-injected typed-tag schema evidence without a real probe.
     * @param string $table Requested schema table, unused by this bounded seam.
     * @param string $column Requested column, unused by this bounded seam.
     * @return array{state:string} Available, missing or unknown fixture state.
     */
    function schema_inspection_column(string $table, string $column): array
    {
        return ['state' => $GLOBALS['tag_filter_schema']];
    }

    /**
     * Describe only the fixture's available song-assignment table.
     * @param string $table Requested table identifier.
     * @return array{state:string} Available only for the typed music fixture.
     */
    function schema_inspection_table(string $table): array
    {
        return ['state' => $GLOBALS['tag_filter_schema'] === 'available'
            && $table === 'music_song_tags' ? 'available' : 'missing'];
    }

    /**
     * Interpret explicit fixture evidence without guessing availability.
     * @param array{state:string} $status Prepared schema observation.
     * @return bool True only for confirmed available evidence.
     */
    function schema_inspection_is_available(array $status): bool
    {
        return $status['state'] === 'available';
    }

    /**
     * Use deterministic source labels without loading live locale settings.
     * @param string $key Translation identity, unused by this fallback seam.
     * @param string $fallback Authored English label.
     * @return string Plain fallback text.
     */
    function t(string $key, string $fallback = ''): string
    {
        return $fallback;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/models/tags.php';
    require_once dirname(__DIR__) . '/app/services/tag_metadata.php';

    /**
     * Fail on an observed filter regression using fixed fixture diagnostics.
     * @param bool $condition Actual model/service result.
     * @param string $message Safe assertion explanation.
     * @return void Throw on failure or continue the disposable test.
     */
    function tag_filter_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    // Identities and assignment multiplicities below are structural vectors:
    // gallery-only, image-only, shared, music, reserved PDF and unused visual.
    $fixture = static function (bool $typed): PDO {
        $connection = new PDO('sqlite::memory:');
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->exec('CREATE TABLE tags (
            id INTEGER PRIMARY KEY, name TEXT, slug TEXT, description TEXT,
            created_at TEXT, updated_at TEXT'
            . ($typed ? ', media_type TEXT' : '') . ')');
        foreach ([
            'gallery_tags' => 'gallery_id',
            'image_tags' => 'image_id',
            'music_song_tags' => 'song_id',
        ] as $table => $column) {
            $connection->exec('CREATE TABLE ' . $table . ' ('
                . $column . ' INTEGER, tag_id INTEGER)');
        }
        foreach ([
            [1, 'gallery-only', 'image'],
            [2, 'image-only', 'image'],
            [3, 'shared', 'image'],
            [4, 'song', 'music'],
            [5, 'reserved-document', 'pdf'],
            [6, 'unassigned', 'image'],
        ] as [$id, $name, $type]) {
            if (!$typed && $type !== 'image') {
                continue;
            }
            $columns = 'id,name,slug,description,created_at,updated_at';
            $values = [$id, $name, $name, '', 'fixture', 'fixture'];
            if ($typed) {
                $columns .= ',media_type';
                $values[] = $type;
            }
            $connection->prepare('INSERT INTO tags (' . $columns . ') VALUES ('
                . implode(',', array_fill(0, count($values), '?')) . ')')
                ->execute($values);
        }
        $connection->exec('INSERT INTO gallery_tags VALUES (10,1),(11,1),(12,3)');
        $connection->exec('INSERT INTO image_tags VALUES (20,2),(21,2),(22,2),(23,3),(24,3)');
        $connection->exec('INSERT INTO music_song_tags VALUES (30,4)');
        return $connection;
    };
    $GLOBALS['tag_filter_db_reads'] = 0;
    $GLOBALS['tag_filter_schema'] = 'available';
    $GLOBALS['tag_filter_db'] = $fixture(true);
    $definitions = Gallery\Services\admin_tag_catalog_filters();
    tag_filter_assert(
        $definitions['gallery']['media_type'] === 'image'
            && $definitions['gallery']['assignment_scope'] === 'gallery'
            && $definitions['image']['assignment_scope'] === 'image'
            && $definitions['documents']['media_type'] === 'pdf'
            && $definitions['documents']['available'] === false,
        'Usage filters must preserve shared visual identities and reserve PDF UI.',
    );
    $galleries = Gallery\Services\admin_tag_rows('usage', 'desc', 'image', 'gallery');
    $images = Gallery\Services\admin_tag_rows('usage', 'desc', 'image', 'image');
    tag_filter_assert(
        array_map('intval', array_column($galleries, 'id')) === [1, 3]
            && array_map('intval', array_column($images, 'id')) === [2, 3],
        'Gallery and image scopes use direct assignments and scoped usage order.',
    );
    tag_filter_assert(
        (int) $galleries[1]['usage_count'] === 1
            && (int) $images[1]['usage_count'] === 2
            && (int) $galleries[1]['image_count'] === 2
            && (int) $images[1]['gallery_count'] === 1,
        'A shared tag keeps one identity and both aggregate fields in either scope.',
    );
    $all = Gallery\Services\admin_tag_rows('name', 'asc', 'all');
    $ids = array_map('intval', array_column($all, 'id'));
    sort($ids);
    tag_filter_assert(
        $ids === [1, 2, 3, 4, 6],
        'All includes unused tags and music while excluding reserved PDF records.',
    );
    $existingCaller = Gallery\Services\admin_tag_rows('name', 'asc');
    tag_filter_assert(
        in_array(6, array_map('intval', array_column($existingCaller, 'id')), true),
        'Existing non-Admin callers retain the entire visual vocabulary by default.',
    );
    $before = $GLOBALS['tag_filter_db_reads'];
    foreach ([['pdf', 'all'], ['image', 'invalid'], ['music', 'gallery']] as [$type, $scope]) {
        tag_filter_assert(
            Gallery\Models\tag_model_admin_rows(true, 'usage', 'desc',
                $type, true, true, $scope,
            ) === [],
            'Unsupported namespace/scope pairs must not reach a query.',
        );
    }
    tag_filter_assert(
        $GLOBALS['tag_filter_db_reads'] === $before,
        'Reserved PDF and malformed scopes never open the model database seam.',
    );
    $GLOBALS['tag_filter_schema'] = 'unknown';
    tag_filter_assert(
        Gallery\Services\admin_tag_rows('usage', 'desc', 'image', 'gallery') === []
            && $GLOBALS['tag_filter_db_reads'] === $before,
        'Unknown schema never becomes an assumed legacy assignment query.',
    );
    $GLOBALS['tag_filter_schema'] = 'missing';
    $GLOBALS['tag_filter_db'] = $fixture(false);
    tag_filter_assert(
        array_map('intval', array_column(
            Gallery\Services\admin_tag_rows('usage', 'desc', 'image', 'gallery'),
            'id',
        )) === [1, 3],
        'Confirmed legacy visual storage retains gallery filtering without media_type SQL.',
    );
    echo "Admin tag assignment filters: PASS\n";
}
