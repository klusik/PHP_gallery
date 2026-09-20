<?php
/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Add replay-safe gallery revision storage for application-owned edit protection.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202609200002_gallery_edit_revision.php
 * Module Type: Database Migration
 * Purpose: Store gallery revisions advanced explicitly by application model writers.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Uses ordinary table-alter privileges only; no trigger, routine, global-variable,
 *   SUPER, or database-server administration privilege is required. A prior interrupted
 *   attempt that already added the column is safely replayed by the migration runner.
 */
declare(strict_types=1);

return [
    'ALTER TABLE galleries ADD COLUMN edit_revision BIGINT UNSIGNED NOT NULL DEFAULT 1',
];
