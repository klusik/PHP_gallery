<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Create the journal storage needed to reconcile interrupted image moves.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202609200001_gallery_image_move_journal.php
 * Module Type: Database Migration
 * Purpose: Preserve image-move intent and an ownership-transaction commit marker across worker exit.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS gallery_image_move_journal (
        operation_id CHAR(32) NOT NULL PRIMARY KEY,
        source_gallery_id BIGINT UNSIGNED NOT NULL,
        destination_gallery_id BIGINT UNSIGNED NOT NULL,
        state VARCHAR(32) NOT NULL,
        database_committed TINYINT(1) NOT NULL DEFAULT 0,
        manifest_json LONGTEXT NOT NULL,
        last_error_code VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY gallery_image_move_journal_pending (state, updated_at),
        KEY gallery_image_move_journal_source (source_gallery_id),
        KEY gallery_image_move_journal_destination (destination_gallery_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
