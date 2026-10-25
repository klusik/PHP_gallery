<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610250001_gallery_visit_content.php
 * Module Type: Database Migration
 * Purpose: Retain addition-only content membership watermarks, never visitors.
 * Responsibilities: Store current membership identities and a dirty generation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return ['statements' => [
    'CREATE TABLE IF NOT EXISTS gallery_visit_content_state (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        dirty TINYINT UNSIGNED NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'INSERT IGNORE INTO gallery_visit_content_state (id) VALUES (1)',
    'CREATE TABLE IF NOT EXISTS gallery_visit_content_pending (
        scope_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        PRIMARY KEY (scope_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS gallery_visit_content_members (
        node_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        item_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        lane_key VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        revision BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (node_key, item_key, lane_key),
        KEY gallery_visit_content_lanes (node_key, lane_key, revision)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
], 'after' => static function (\PDO $pdo): void {
    // A newly activated runtime model may already be included from old code.
    // Load the dedicated migration owner by its independent, stable file path.
    require_once dirname(__DIR__, 2)
        . '/app/models/gallery_visit_content_migration.php';
    require_once dirname(__DIR__, 2) . '/app/models/smart_galleries.php';
    try {
        \Gallery\Models\gallery_visit_migration_model_seed($pdo);
    } catch (\Gallery\Models\GalleryVisitMigrationNotReady) {
        // Bounded optional data readiness must not block a core upgrade. Keep
        // the index dirty and public New explicitly unavailable; SQL failures
        // still propagate through the normal retryable migration lifecycle.
        error_log('Initial gallery content evidence remains unavailable.');
    }
}];
