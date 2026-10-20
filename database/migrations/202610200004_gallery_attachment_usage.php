<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610200004_gallery_attachment_usage.php
 * Module Type: Database Migration
 * Purpose: Store bounded derived attachment counts for public gallery cards.
 * Responsibilities: Track dirty snapshots and digest-unique PDF/music sources.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    'statements' => [
        "CREATE TABLE IF NOT EXISTS gallery_attachment_usage_state (
        gallery_id BIGINT UNSIGNED NOT NULL,
        kind ENUM('pdf','music') NOT NULL,
        state ENUM('dirty','ready') NOT NULL DEFAULT 'dirty',
        revision CHAR(32) NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (gallery_id, kind),
        CONSTRAINT gallery_attachment_usage_state_gallery_fk FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS gallery_attachment_usage_items (
        gallery_id BIGINT UNSIGNED NOT NULL,
        kind ENUM('pdf','music') NOT NULL,
        source_id VARCHAR(80) NOT NULL COMMENT 'Bounded prefixed opaque source identity',
        sha256 CHAR(64) NULL,
        asset_id CHAR(32) NULL,
        album_id BIGINT UNSIGNED NULL,
        hidden TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (gallery_id, kind, source_id),
        KEY gallery_attachment_usage_digest_index (gallery_id, kind, sha256),
        KEY gallery_attachment_usage_album_index (album_id),
        CONSTRAINT gallery_attachment_usage_items_gallery_fk FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];
