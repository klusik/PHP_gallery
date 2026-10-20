<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610200003_music_repository.php
 * Module Type: Database Migration
 * Purpose: Add central immutable audio metadata and live gallery assignments.
 * Responsibilities: Relate repository assets and album links without storing audio bytes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    'statements' => [
        "CREATE TABLE IF NOT EXISTS music_repository_assets (
        id CHAR(32) NOT NULL PRIMARY KEY,
        song_id BIGINT UNSIGNED NOT NULL,
        filename VARCHAR(240) NOT NULL,
        indexed_title VARCHAR(240) NOT NULL,
        mime VARCHAR(120) NOT NULL,
        byte_size BIGINT UNSIGNED NOT NULL,
        sha256 CHAR(64) NOT NULL,
        origin_gallery_id BIGINT UNSIGNED NULL,
        origin_track_id CHAR(32) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY music_repository_origin_index (origin_gallery_id, origin_track_id),
        KEY music_repository_song_index (song_id, created_at, id),
        CONSTRAINT music_repository_song_fk FOREIGN KEY (song_id) REFERENCES music_songs(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS music_gallery_album_links (
        gallery_id BIGINT UNSIGNED NOT NULL,
        assignment_id CHAR(32) NOT NULL,
        album_id BIGINT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (gallery_id, assignment_id),
        KEY music_gallery_album_links_album_index (album_id, gallery_id),
        CONSTRAINT music_gallery_album_links_gallery_fk FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
        CONSTRAINT music_gallery_album_links_album_fk FOREIGN KEY (album_id) REFERENCES music_albums(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    'after' => static function (\PDO $pdo): void {
        $column = $pdo->prepare(
            "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME = 'music_track_instances' "
                . "AND COLUMN_NAME = 'asset_id'",
        );
        $column->execute();
        $columnRow = $column->fetch(\PDO::FETCH_ASSOC);
        if ($columnRow === false) {
            $pdo->exec(
                'ALTER TABLE music_track_instances '
                    . 'ADD COLUMN asset_id CHAR(32) NULL AFTER song_id',
            );
        } elseif (
            strtolower((string) $columnRow['COLUMN_TYPE']) !== 'char(32)'
            || (string) $columnRow['IS_NULLABLE'] !== 'YES'
        ) {
            throw new \RuntimeException(
                'Music repository migration found an incompatible track asset column.',
            );
        }

        $index = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS "
                . "WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME = 'music_track_instances' "
                . "AND INDEX_NAME = 'music_track_instances_asset_index'",
        );
        $index->execute();
        $indexColumn = $index->fetchColumn();
        if ($indexColumn === false) {
            $pdo->exec(
                'ALTER TABLE music_track_instances '
                    . 'ADD KEY music_track_instances_asset_index (asset_id)',
            );
        } elseif ((string) $indexColumn !== 'asset_id') {
            throw new \RuntimeException(
                'Music repository migration found an incompatible track asset index.',
            );
        }

        $constraint = $pdo->prepare(
            "SELECT REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME "
                . "FROM information_schema.KEY_COLUMN_USAGE "
                . "WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME = 'music_track_instances' "
                . "AND CONSTRAINT_NAME = 'music_track_instances_asset_fk'",
        );
        $constraint->execute();
        $constraintRow = $constraint->fetch(\PDO::FETCH_ASSOC);
        if ($constraintRow === false) {
            $pdo->exec(
                'ALTER TABLE music_track_instances '
                    . 'ADD CONSTRAINT music_track_instances_asset_fk '
                    . 'FOREIGN KEY (asset_id) '
                    . 'REFERENCES music_repository_assets(id) ON DELETE RESTRICT',
            );
        } elseif (
            (string) $constraintRow['REFERENCED_TABLE_NAME']
                !== 'music_repository_assets'
            || (string) $constraintRow['REFERENCED_COLUMN_NAME'] !== 'id'
        ) {
            throw new \RuntimeException(
                'Music repository migration found an incompatible asset foreign key.',
            );
        }
    },
];
