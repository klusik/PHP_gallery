<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/gallery_description_layout_migration.php
 * Module Type: Model
 * Purpose: Persist the one-time gallery-card orientation compatibility migration.
 * Responsibilities:
 *   - Read existing installation evidence and all persisted orientation documents.
 *   - Swap legacy scalar values and commit the durable replay checkpoint atomically.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;

/**
 * Read the complete migration inputs before any filesystem mutation.
 * @param PDO $pdo Canonical migration connection with preceding migrations applied.
 * @return array{settings:array<string,string>,upgrade:bool,smart:array<int,array<string,mixed>>,trash:array<int,array<string,mixed>>} Existing preferences and documents.
 */
function gallery_layout_migration_model_state(PDO $pdo): array
{
    $settings = [];
    foreach ($pdo->query("SELECT setting_key, setting_value, updated_at FROM app_settings WHERE setting_key IN ('theme_gallery_description_layout', 'tag_page_gallery_description_layout', 'gallery_description_layout_semantics_version')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
    }
    $smart = $pdo->query('SELECT id, presentation_json, updated_at FROM smart_galleries')->fetchAll(PDO::FETCH_ASSOC);
    $trash = $pdo->query('SELECT id, snapshot_json FROM gallery_trash_entries')->fetchAll(PDO::FETCH_ASSOC);
    // Reading the revision column also verifies the full mutation schema before files change.
    $galleries = $pdo->query('SELECT id, description_layout, edit_revision FROM galleries')->fetchAll(PDO::FETCH_ASSOC);
    $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    return ['settings' => $settings, 'upgrade' => $users > 0 || $galleries !== [] || $smart !== [] || $trash !== []
        || array_key_exists('theme_gallery_description_layout', $settings) || array_key_exists('tag_page_gallery_description_layout', $settings),
        'smart' => $smart, 'trash' => $trash];
}

/**
 * Begin the atomic database portion of the compatibility conversion.
 * @param PDO $pdo Canonical migration connection.
 * @return void Starts a transaction that must be committed or rolled back by the use case.
 */
function gallery_layout_migration_model_begin(PDO $pdo): void
{
    $pdo->beginTransaction();
}

/**
 * Persist prepared orientation values without changing inherited gallery overrides.
 * @param PDO $pdo Canonical migration connection inside its transaction.
 * @param array<string,string> $settings Prepared global settings and completion marker.
 * @param array<int,array{id:int,json:string}> $smart Changed Smart Gallery documents.
 * @param array<int,array{id:int,json:string}> $trash Changed restore snapshots.
 * @param bool $swapGalleries Whether existing explicit gallery orientations require conversion.
 * @return void Applies replay-protected scalar and document updates.
 */
function gallery_layout_migration_model_apply(PDO $pdo, array $settings, array $smart, array $trash, bool $swapGalleries): void
{
    if ($swapGalleries) {
        $pdo->exec("UPDATE galleries SET description_layout = CASE description_layout WHEN 'vertical' THEN 'horizontal' WHEN 'horizontal' THEN 'vertical' END, edit_revision = edit_revision + 1 WHERE description_layout IN ('vertical', 'horizontal')");
    }
    $statement = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
    foreach ($settings as $key => $value) $statement->execute([$key, $value]);
    $smartStatement = $pdo->prepare('UPDATE smart_galleries SET presentation_json = ?, updated_at = NOW() WHERE id = ?');
    foreach ($smart as $row) $smartStatement->execute([$row['json'], $row['id']]);
    $trashStatement = $pdo->prepare('UPDATE gallery_trash_entries SET snapshot_json = ? WHERE id = ?');
    foreach ($trash as $row) $trashStatement->execute([$row['json'], $row['id']]);
}

/**
 * Finish or undo the complete database portion.
 * @param PDO $pdo Canonical migration connection.
 * @param bool $commit Whether every filesystem and database operation succeeded.
 * @return void Commits the completion marker or restores all database preferences.
 */
function gallery_layout_migration_model_finish(PDO $pdo, bool $commit): void
{
    if ($pdo->inTransaction()) {
        if ($commit) $pdo->commit();
        else $pdo->rollBack();
    }
}
