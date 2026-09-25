<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/gallery_creation_preferences.php
 * Module Type: Model
 *
 * Purpose:
 *   Persist gallery creation defaults for one administrator.
 *
 * Responsibilities:
 *   - Read and write user-owned preference rows
 *   - Keep preference SQL outside controllers and views
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Last Updated:
 *   2026-09-25
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Return saved defaults for the given administrator.
 *
 * @param int $userId Authenticated administrator ID.
 * @return array<string,mixed>|null Saved preference row or null.
 */
function gallery_creation_preferences_model_find(int $userId): ?array
{
    $stmt = db()->prepare('SELECT simbrief_pilot_id, simbrief_pilot_name, content_language FROM user_gallery_creation_preferences WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Persist all normalized defaults in one row.
 *
 * @param int $userId Authenticated administrator ID.
 * @param array<string,string> $values Validated preference values.
 * @param string $now SQL timestamp for this mutation.
 * @return void Persist the selected values.
 */
function gallery_creation_preferences_model_save(int $userId, array $values, string $now): void
{
    $stmt = db()->prepare('INSERT INTO user_gallery_creation_preferences (user_id, simbrief_pilot_id, simbrief_pilot_name, content_language, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE simbrief_pilot_id = VALUES(simbrief_pilot_id), simbrief_pilot_name = VALUES(simbrief_pilot_name), content_language = VALUES(content_language), updated_at = VALUES(updated_at)');
    $stmt->execute([$userId, $values['simbrief_pilot_id'], $values['simbrief_pilot_name'], $values['content_language'], $now, $now]);
}
