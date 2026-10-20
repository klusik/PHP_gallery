<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610200002_music_catalog.php
 * Module Type: Database Migration
 * Purpose: Add normalized identities for music tracks and nested albums.
 * Responsibilities: Persist song identity, physical track references and album membership.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS music_songs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(240) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY music_songs_title_index (title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS music_track_instances (
        gallery_id BIGINT UNSIGNED NOT NULL,
        track_id CHAR(32) NOT NULL,
        song_id BIGINT UNSIGNED NOT NULL,
        filename VARCHAR(240) NOT NULL,
        indexed_title VARCHAR(240) NOT NULL,
        file_sha256 CHAR(64) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (gallery_id, track_id),
        KEY music_track_instances_song_index (song_id),
        CONSTRAINT music_track_instances_gallery_fk FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
        CONSTRAINT music_track_instances_song_fk FOREIGN KEY (song_id) REFERENCES music_songs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS music_albums (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        parent_id BIGINT UNSIGNED NULL,
        title VARCHAR(240) NOT NULL,
        description TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY music_albums_parent_order_index (parent_id, sort_order, id),
        CONSTRAINT music_albums_parent_fk FOREIGN KEY (parent_id) REFERENCES music_albums(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS music_album_songs (
        album_id BIGINT UNSIGNED NOT NULL,
        song_id BIGINT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (album_id, song_id),
        KEY music_album_songs_song_index (song_id),
        CONSTRAINT music_album_songs_album_fk FOREIGN KEY (album_id) REFERENCES music_albums(id) ON DELETE CASCADE,
        CONSTRAINT music_album_songs_song_fk FOREIGN KEY (song_id) REFERENCES music_songs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS music_song_tags (
        song_id BIGINT UNSIGNED NOT NULL,
        tag_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (song_id, tag_id),
        KEY music_song_tags_tag_song_index (tag_id, song_id),
        CONSTRAINT music_song_tags_song_fk FOREIGN KEY (song_id) REFERENCES music_songs(id) ON DELETE CASCADE,
        CONSTRAINT music_song_tags_tag_fk FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS music_catalog_state (
        state_key VARCHAR(40) NOT NULL PRIMARY KEY,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS music_catalog_dirty_galleries (
        gallery_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        changed_at DATETIME NOT NULL,
        CONSTRAINT music_catalog_dirty_gallery_fk FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT IGNORE INTO music_catalog_state (state_key, revision, updated_at) VALUES ('catalog', 1, NOW())",
];
