<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202609070001_gallery_trash_bin.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds the gallery trash bin so deleted gallery subtrees become recoverable.
 *
 * Responsibilities:
 *   - Store one durable entry per trashed gallery root
 *   - Keep a restore snapshot independent from live gallery rows
 *   - Record lifecycle state used by crash-safe trash/restore/purge operations
 *   - Record retention so expired entries can be purged by scheduled maintenance
 *   - Remain additive and safe to replay on partially upgraded installations
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Trash rows intentionally have no foreign key to galleries because they must
 *     outlive the gallery rows they describe.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-07
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS gallery_trash_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        trash_token CHAR(32) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'preparing',
        original_folder_path VARCHAR(1024) NOT NULL,
        original_parent_folder_path VARCHAR(1024) NULL,
        title VARCHAR(255) NOT NULL,
        subtree_gallery_count INT UNSIGNED NOT NULL DEFAULT 0,
        image_count INT UNSIGNED NOT NULL DEFAULT 0,
        byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        snapshot_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        snapshot_json LONGTEXT NULL,
        trash_relative_path VARCHAR(1024) NOT NULL,
        deleted_by_user_id BIGINT UNSIGNED NULL,
        deleted_from VARCHAR(32) NOT NULL DEFAULT 'admin',
        deleted_at DATETIME NOT NULL,
        purge_after DATETIME NOT NULL,
        operation_started_at DATETIME NULL,
        restored_at DATETIME NULL,
        purged_at DATETIME NULL,
        last_error_code VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY gallery_trash_entries_token_unique (trash_token),
        KEY gallery_trash_entries_status_purge_index (status, purge_after),
        KEY gallery_trash_entries_status_deleted_index (status, deleted_at),
        KEY gallery_trash_entries_deleted_by_index (deleted_by_user_id),
        KEY gallery_trash_entries_original_path_index (original_folder_path(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "ALTER TABLE gallery_trash_entries
        ADD CONSTRAINT gallery_trash_entries_user_foreign
        FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL",
];
