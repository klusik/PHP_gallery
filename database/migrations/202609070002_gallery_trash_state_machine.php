<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202609070002_gallery_trash_state_machine.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Upgrades early trash-bin installations to the crash-safe state-machine schema.
 *
 * Responsibilities:
 *   - Replace the early ENUM lifecycle with extensible VARCHAR states
 *   - Expand restore snapshots to LONGTEXT and allow clearing finalized snapshots
 *   - Add operation/reconciliation metadata required by atomic trash mutations
 *   - Remain replay-safe on fresh installs where the preceding migration already
 *     created the final column definitions
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
 *   - This migration intentionally remains separate from the create-table migration
 *     so a development database that already ran 202609070001 is upgraded safely.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-07
 */

declare(strict_types=1);

return [
    "ALTER TABLE gallery_trash_entries MODIFY COLUMN status VARCHAR(24) NOT NULL DEFAULT 'preparing'",
    "ALTER TABLE gallery_trash_entries MODIFY COLUMN snapshot_json LONGTEXT NULL",
    "ALTER TABLE gallery_trash_entries ADD COLUMN snapshot_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER byte_size",
    "ALTER TABLE gallery_trash_entries ADD COLUMN operation_started_at DATETIME NULL AFTER purge_after",
    "ALTER TABLE gallery_trash_entries ADD COLUMN last_error_code VARCHAR(64) NULL AFTER purged_at",
    "ALTER TABLE gallery_trash_entries ADD KEY gallery_trash_entries_original_path_index (original_folder_path(191))",
];
