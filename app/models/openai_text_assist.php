<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/openai_text_assist.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence used by optional user-scoped OpenAI text assistance.
 *
 * Responsibilities:
 *   - Read and persist per-user OpenAI text settings
 *   - Persist generated image descriptions
 *   - Reload updated image rows after mutations
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - API transport, encryption, prompt construction, and feature policy remain in services.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Return one user's persisted OpenAI text-assistance settings.
 * @param int $userId Account owner identifier.
 * @return ?array<string,int|string|null> Persisted settings row for the account, or null when none exists.
 */
function openai_text_assist_model_user_settings(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM user_openai_text_settings WHERE user_id = ? LIMIT 1',
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Persist one user's OpenAI text-assistance settings.
 * @param int $userId Account owner identifier.
 * @param array{enabled?:bool|int|string,api_key_cipher?:?string,api_key_hint?:?string,model?:string,allow_image_input?:bool|int|string} $settings Normalized setting values to persist.
 * @param string $now SQL timestamp used for row creation and updates.
 * @param bool $withImageInput Whether the installed schema supports the image-input setting.
 * @return void Insert or update the user settings row.
 */
function openai_text_assist_model_save_settings(
    int $userId,
    array $settings,
    string $now,
    bool $withImageInput,
): void {
    if ($withImageInput) {
        $stmt = db()->prepare(
            'INSERT INTO user_openai_text_settings (user_id, enabled, api_key_cipher, api_key_hint, model, allow_image_input, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), api_key_cipher = VALUES(api_key_cipher), api_key_hint = VALUES(api_key_hint), model = VALUES(model), allow_image_input = VALUES(allow_image_input), updated_at = VALUES(updated_at)',
        );
        $stmt->execute([
            $userId,
            !empty($settings['enabled']) ? 1 : 0,
            $settings['api_key_cipher'] ?? null,
            $settings['api_key_hint'] ?? null,
            (string) ($settings['model'] ?? ''),
            !empty($settings['allow_image_input']) ? 1 : 0,
            $now,
            $now,
        ]);
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO user_openai_text_settings (user_id, enabled, api_key_cipher, api_key_hint, model, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), api_key_cipher = VALUES(api_key_cipher), api_key_hint = VALUES(api_key_hint), model = VALUES(model), updated_at = VALUES(updated_at)',
    );
    $stmt->execute([
        $userId,
        !empty($settings['enabled']) ? 1 : 0,
        $settings['api_key_cipher'] ?? null,
        $settings['api_key_hint'] ?? null,
        (string) ($settings['model'] ?? ''),
        $now,
        $now,
    ]);
}

/**
 * Update one image description and return the refreshed image row.
 * @param int $imageId Image identifier to update.
 * @param string $description Replacement image description.
 * @param string $now SQL timestamp recorded for the update.
 * @return ?array<string,int|string|null> Refreshed image row after the update, or null when the row is absent.
 */
function openai_text_assist_model_save_image_description(
    int $imageId,
    string $description,
    string $now,
): ?array {
    $stmt = db()->prepare(
        'UPDATE images SET description = ?, updated_at = ? WHERE id = ?',
    );
    $stmt->execute([$description, $now, $imageId]);

    $stmt = db()->prepare('SELECT * FROM images WHERE id = ? LIMIT 1');
    $stmt->execute([$imageId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}
