<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/music_catalog_title_policy_test.php
 * Module Type: Regression Test
 * Purpose: Verify normalized music catalog public title selection.
 * Responsibilities: Exercise real title, setting, and audio format owners using isolated SQLite storage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return this fixture's isolated in-memory settings database.
     * @return \PDO In-memory SQLite connection owned only by this test.
     */
    function db(): \PDO
    {
        return $GLOBALS['music_catalog_title_fixture_db'];
    }
}

namespace {
    /**
     * Assert one title returned by the production policy function.
     * @param string $expected Expected public title.
     * @param string $actual Actual public title from the catalog service.
     * @param string $scenario Scenario description without persisted data.
     * @return void Throw when the production result differs.
     */
    function music_catalog_title_assert(
        string $expected,
        string $actual,
        string $scenario,
    ): void {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $scenario . ': expected ' . var_export($expected, true)
                    . ', received ' . var_export($actual, true),
            );
        }
    }

    /**
     * Persist one fixture preference and clear the real service cache.
     * @param \PDO $connection Isolated fixture SQLite connection.
     * @param string $value Extension preference stored by the application.
     * @return void Make the next production setting read observe this row.
     */
    function music_catalog_title_set_extension_preference(
        \PDO $connection,
        string $value,
    ): void {
        $statement = $connection->prepare(
            'INSERT INTO app_settings '
                . '(setting_key, setting_value, updated_at) '
                . 'VALUES (?, ?, ?) '
                . 'ON CONFLICT(setting_key) DO UPDATE SET '
                . 'setting_value = excluded.setting_value, '
                . 'updated_at = excluded.updated_at',
        );
        $statement->execute(['music_show_file_extensions', $value, 'fixture']);
        \Gallery\Services\app_settings_reset_request_cache();
    }

    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        echo "SKIP: music catalog title policy requires PDO SQLite\n";
        exit(0);
    }

    $GLOBALS['music_catalog_title_fixture_db'] = new \PDO('sqlite::memory:');
    $GLOBALS['music_catalog_title_fixture_db']->setAttribute(
        \PDO::ATTR_ERRMODE,
        \PDO::ERRMODE_EXCEPTION,
    );
    $GLOBALS['music_catalog_title_fixture_db']->exec(
        'CREATE TABLE app_settings ('
            . 'setting_key TEXT PRIMARY KEY, '
            . 'setting_value TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL)',
    );

    require_once dirname(__DIR__) . '/app/models/app_settings.php';
    require_once dirname(__DIR__) . '/app/services/gallery_music.php';

    music_catalog_title_assert(
        'Song',
        \Gallery\Services\music_catalog_display_title(
            'Song.mp3',
            'Song.mp3',
        ),
        'Default-off preference removes a recognized lowercase extension',
    );
    music_catalog_title_assert(
        'Mix',
        \Gallery\Services\music_catalog_display_title(
            'Mix.MP3',
            'Mix.MP3',
        ),
        'Default-off preference recognizes uppercase extensions',
    );

    music_catalog_title_set_extension_preference(
        $GLOBALS['music_catalog_title_fixture_db'],
        '1',
    );
    music_catalog_title_assert(
        'Song.mp3',
        \Gallery\Services\music_catalog_display_title(
            'Song.mp3',
            'Song.mp3',
        ),
        'Enabled preference restores the original extension',
    );

    music_catalog_title_set_extension_preference(
        $GLOBALS['music_catalog_title_fixture_db'],
        '0',
    );
    music_catalog_title_assert(
        'A meaningful indexed title',
        \Gallery\Services\music_catalog_display_title(
            'Song.mp3',
            'A meaningful indexed title',
        ),
        'Meaningful existing indexed title takes precedence',
    );
    music_catalog_title_assert(
        'Deliberate 演奏.mp3',
        \Gallery\Services\music_catalog_display_title(
            'Song.mp3',
            'Song.mp3',
            'Deliberate 演奏.mp3',
        ),
        'Custom Unicode title is returned exactly, including extension text',
    );
    music_catalog_title_assert(
        'Archive.xyz',
        \Gallery\Services\music_catalog_display_title(
            'folder/Archive.xyz',
            'Archive.xyz',
        ),
        'Unknown extension remains in the basename fallback',
    );

    echo "PASS: music catalog public title policy\n";
}
